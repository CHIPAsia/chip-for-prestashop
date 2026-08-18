<?php
/**
 * CHIP for PrestaShop - Callback controller.
 *
 * Receives the CHIP webhook (success_callback) with the X-Signature header, and
 * is also the success/failure/cancel redirect target. Handles order validation
 * idempotently (no double validateOrder) and redirects the customer to the
 * order-confirmation page (or back to the order page on failure).
 *
 * @author CHIPAsia
 * @license http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class ChipCallbackModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    /**
     * Verify the webhook signature (X-Signature) against the raw body.
     * Falls back to GET /purchases/{id}/ when the header is missing or invalid.
     *
     * @return array|false payment data, or false
     */
    protected function getPaymentData()
    {
        $chip = $this->module->getApi();

        $payment_id = (string) $this->context->cookie->chip_payment_id;
        if ($payment_id === '') {
            $payment_id = (string) Tools::getValue('payment_id', '');
        }
        if ($payment_id === '') {
            $payment_id = (string) Tools::getValue('id', '');
        }

        $content = file_get_contents('php://input');
        if ($content === false) {
            $content = '';
        }
        $signature = isset($_SERVER['HTTP_X_SIGNATURE']) ? (string) $_SERVER['HTTP_X_SIGNATURE'] : '';

        if ($signature !== '' && $chip->verifySignature($content, $signature)) {
            $payment = Tools::jsonDecode($content, true);
            if (is_array($payment) && !empty($payment['id'])) {
                return $payment;
            }
        } elseif ($signature !== '') {
            PrestaShopLogger::addLog(
                'CHIP: callback signature verification failed for purchase ' . $payment_id . ' - falling back to API',
                2,
                null,
                'ChipCallback',
                null,
                true
            );
        }

        // Signature missing or failed -> fallback: fetch the actual status from the API.
        if ($payment_id !== '') {
            $payment = $chip->getPurchase($payment_id);
            if (is_array($payment) && !empty($payment['id'])) {
                return $payment;
            }
        }

        return false;
    }

    /**
     * Validate the order for a paid purchase (idempotent - never double validate).
     *
     * @param int $id_cart
     * @param float $total_paid
     * @param string $purchase_id
     * @return bool true when validated (or already validated)
     */
    protected function validatePaidOrder($id_cart, $total_paid, $purchase_id)
    {
        $id_cart = (int) $id_cart;
        if ($id_cart <= 0) {
            return false;
        }

        // Idempotency: an order for this cart already exists -> skip validateOrder.
        $existing_id = (int) Order::getIdByCartId($id_cart);
        if ($existing_id > 0) {
            PrestaShopLogger::addLog(
                'CHIP: order ' . $existing_id . ' already exists for cart ' . $id_cart . ' - skip validateOrder',
                1,
                null,
                'Cart',
                $id_cart,
                true
            );

            return true;
        }

        $cart = new Cart($id_cart);
        if (!Validate::isLoadedObject($cart) || !(int) $cart->id_customer) {
            PrestaShopLogger::addLog('CHIP: cannot load cart ' . $id_cart . ' for validation', 3, null, 'Cart', $id_cart, true);

            return false;
        }

        $customer = new Customer((int) $cart->id_customer);
        $secure_key = Validate::isLoadedObject($customer) ? $customer->secure_key : false;

        try {
            $this->module->validateOrder(
                $id_cart,
                (int) Configuration::get('PS_OS_PAYMENT'),
                (float) $total_paid,
                $this->module->displayName,
                null,
                array('transaction_id' => (string) $purchase_id),
                null,
                false,
                $secure_key
            );
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'CHIP: validateOrder failed for cart ' . $id_cart . ': ' . $e->getMessage(),
                3,
                null,
                'Cart',
                $id_cart,
                true
            );

            return false;
        }

        PrestaShopLogger::addLog(
            'CHIP: order validated for cart ' . $id_cart . ' (purchase ' . $purchase_id . ')',
            1,
            null,
            'Cart',
            $id_cart,
            true
        );

        return true;
    }

    /**
     * Extract the purchase reference so the callback can be bound to the cart.
     *
     * @param array $payment
     * @return string
     */
    protected function getPaymentReference($payment)
    {
        if (isset($payment['reference']) && $payment['reference'] !== '') {
            return (string) $payment['reference'];
        }
        if (isset($payment['purchase']['reference'])) {
            return (string) $payment['purchase']['reference'];
        }

        return '';
    }

    public function postProcess()
    {
        $id_cart = (int) Tools::getValue('id_cart', 0);
        if (!$id_cart) {
            $id_cart = (int) $this->context->cookie->chip_purchase_cart;
        }

        $payment = $this->getPaymentData();

        if ($payment === false) {
            PrestaShopLogger::addLog('CHIP: callback with no valid payment data (cart ' . $id_cart . ')', 3, null, 'Cart', $id_cart, true);
            $this->context->cookie->chip_payment_error = $this->module->l('Payment could not be verified. Please contact support.');
            $this->context->cookie->write();
            Tools::redirect($this->context->link->getPageLink('order', true));

            return;
        }

        $purchase_id = (string) $payment['id'];
        $status = isset($payment['status']) ? (string) $payment['status'] : '';

        // Security: the purchase must reference the cart being validated.
        $payment_reference = $this->getPaymentReference($payment);
        if ($id_cart > 0 && $payment_reference !== '' && $payment_reference !== (string) $id_cart) {
            PrestaShopLogger::addLog(
                'CHIP: reference mismatch - payment ' . $purchase_id . ' is for reference ' . $payment_reference . ', cart ' . $id_cart,
                3,
                null,
                'Cart',
                $id_cart,
                true
            );
            $this->context->cookie->chip_payment_error = $this->module->l('Payment could not be verified. Please contact support.');
            $this->context->cookie->write();
            Tools::redirect($this->context->link->getPageLink('order', true));

            return;
        }

        if ($status === 'paid') {
            // CHIP returns amounts in minor units (sen) nested under payment.amount.
            $total_paid = 0.0;
            if (isset($payment['payment']['amount'])) {
                $total_paid = (float) $payment['payment']['amount'] / 100;
            }
            if ($total_paid <= 0 && $id_cart > 0) {
                $cart = new Cart($id_cart);
                if (Validate::isLoadedObject($cart)) {
                    $total_paid = (float) $cart->getOrderTotal(true, Cart::BOTH);
                }
            }

            if (!$this->validatePaidOrder($id_cart, $total_paid, $purchase_id)) {
                $this->context->cookie->chip_payment_error = $this->module->l('Payment received but the order could not be confirmed. Please contact support.');
                $this->context->cookie->write();
                Tools::redirect($this->context->link->getPageLink('order', true));

                return;
            }

            $id_order = (int) Order::getIdByCartId($id_cart);
            $secure_key = '';
            if ($id_order > 0) {
                $order = new Order($id_order);
                if (Validate::isLoadedObject($order)) {
                    $secure_key = $order->secure_key;
                }
            }

            // Clear session purchase markers.
            unset($this->context->cookie->chip_payment_id);
            unset($this->context->cookie->chip_purchase_cart);
            unset($this->context->cookie->chip_purchase_total);
            $this->context->cookie->write();

            Tools::redirect(
                $this->context->link->getPageLink(
                    'order-confirmation',
                    true,
                    null,
                    'id_cart=' . (int) $id_cart . '&id_module=' . (int) $this->module->id . '&key=' . $secure_key
                )
            );

            return;
        }

        PrestaShopLogger::addLog(
            'CHIP: callback status ' . $status . ' for cart ' . $id_cart . ' (purchase ' . $purchase_id . ')',
            1,
            null,
            'Cart',
            $id_cart,
            true
        );

        // Not paid -> send the customer back to the payment step with an error.
        $this->context->cookie->chip_payment_error = $this->module->l('The payment was not completed. Please try again.');
        $this->context->cookie->write();
        Tools::redirect($this->context->link->getPageLink('order', true));
    }

    public function initContent()
    {
        parent::initContent();
    }
}

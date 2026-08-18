<?php
/**
 * CHIP for PrestaShop - Payment front controller.
 *
 * Creates a CHIP purchase and redirects the customer to the CHIP checkout page.
 * Maps to the module URL .../module/chip/payment (see hookPaymentOptions in chip.php).
 *
 * @author CHIPAsia
 * @license http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class ChipPaymentModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    /** @var bool */
    public $display_column_left = false;

    /** @var bool */
    public $display_column_right = false;

    /**
     * Validate that the requested cart belongs to the current customer.
     *
     * @return Cart|false
     */
    protected function getValidCart()
    {
        $id_cart = (int) Tools::getValue('id_cart', 0);
        if (!$id_cart) {
            return false;
        }

        $cart = new Cart((int) $id_cart);
        if (!Validate::isLoadedObject($cart)) {
            PrestaShopLogger::addLog('CHIP: payment controller - invalid cart ' . $id_cart, 3, null, 'Cart', $id_cart, true);

            return false;
        }

        // The cart must belong to the logged-in customer and match the session cart.
        if ((int) $this->context->cookie->id_cart !== (int) $cart->id
            || (int) $cart->id_customer !== (int) $this->context->customer->id) {
            PrestaShopLogger::addLog('CHIP: payment controller - cart/customer mismatch for cart ' . $id_cart, 3, null, 'Cart', $id_cart, true);

            return false;
        }

        if (!(int) $cart->id_customer || !(int) $cart->id_address_delivery || !(int) $cart->id_address_invoice) {
            PrestaShopLogger::addLog('CHIP: payment controller - cart not ready (no customer/address) for cart ' . $id_cart, 3, null, 'Cart', $id_cart, true);

            return false;
        }

        return $cart;
    }

    /**
     * Build the CHIP purchase params following CHIP-API-SPEC.md
     * (mirrors the WooCommerce gateway params).
     *
     * @param Cart $cart
     * @return array
     */
    protected function buildPurchaseParams(Cart $cart)
    {
        $currency = new Currency((int) $cart->id_currency);
        $iso_code = Validate::isLoadedObject($currency) ? strtolower($currency->iso_code) : 'myr';

        $language_code = 'en';
        if (isset($this->context->language) && Validate::isLoadedObject($this->context->language)) {
            $language_code = strtolower(substr((string) $this->context->language->iso_code, 0, 2));
        }

        $customer = new Customer((int) $cart->id_customer);
        $address_invoice = new Address((int) $cart->id_address_invoice);

        $full_name = trim($address_invoice->firstname . ' ' . $address_invoice->lastname);
        if (empty($full_name)) {
            $full_name = trim($customer->firstname . ' ' . $customer->lastname);
        }

        $client = array(
            'email' => $customer->email,
            'full_name' => substr($full_name, 0, 128),
            'street_address' => substr(trim($address_invoice->address1 . ' ' . $address_invoice->address2), 0, 128),
            'city' => substr($address_invoice->city, 0, 128),
            'zip_code' => substr($address_invoice->postcode, 0, 32),
        );

        if ($address_invoice->phone_mobile) {
            $client['phone'] = substr($address_invoice->phone_mobile, 0, 32);
        } elseif ($address_invoice->phone) {
            $client['phone'] = substr($address_invoice->phone, 0, 32);
        }

        $country_iso = Country::getIsoById((int) $address_invoice->id_country);
        if ($country_iso) {
            $client['country'] = strtoupper(substr($country_iso, 0, 2));
        }

        if ((int) $address_invoice->id_state) {
            $state_name = State::getNameById((int) $address_invoice->id_state);
            if ($state_name) {
                $client['state'] = substr($state_name, 0, 128);
            }
        }

        // Shipping address (only when a different delivery address is used)
        $address_delivery = new Address((int) $cart->id_address_delivery);
        if (Validate::isLoadedObject($address_delivery) && (int) $address_delivery->id !== (int) $address_invoice->id) {
            $shipping_street = trim($address_delivery->address1 . ' ' . $address_delivery->address2);
            if ($shipping_street) {
                $client['shipping_street_address'] = substr($shipping_street, 0, 128);
            }
            $client['shipping_city'] = substr($address_delivery->city, 0, 128);
            $client['shipping_zip_code'] = substr($address_delivery->postcode, 0, 32);

            $shipping_country = Country::getIsoById((int) $address_delivery->id_country);
            if ($shipping_country) {
                $client['shipping_country'] = strtoupper(substr($shipping_country, 0, 2));
            }

            if ((int) $address_delivery->id_state) {
                $state_name = State::getNameById((int) $address_delivery->id_state);
                if ($state_name) {
                    $client['shipping_state'] = substr($state_name, 0, 128);
                }
            }
        }

        // Drop empty client fields (mirrors WooCommerce behavior)
        foreach ($client as $key => $value) {
            if ($value === '' || $value === null) {
                unset($client[$key]);
            }
        }

        $total = (float) $cart->getOrderTotal(true, Cart::BOTH);
        $total_override = (int) round($total * 100);

        // Products (sen prices)
        $products = array();
        $use_total_override = false;
        foreach ($cart->getProducts() as $product) {
            $qty = (int) $product['quantity'];
            if ($qty < 1) {
                $qty = 1;
            }

            $line_total = isset($product['total_wt']) ? (float) $product['total_wt'] : 0.0;
            $unit_price = (int) round($line_total * 100 / $qty);
            if ($unit_price < 0) {
                $unit_price = 0;
            }

            if ($unit_price === 0 && $total_override > 0) {
                $use_total_override = true;
                break;
            }

            $products[] = array(
                'name' => substr((string) $product['name'], 0, 256),
                'price' => $unit_price,
                'quantity' => $qty,
            );
        }

        if ($use_total_override || count($products) === 0) {
            $products = array(
                array(
                    'name' => 'Order ' . (int) $cart->id,
                    'price' => $total_override,
                    'quantity' => 1,
                ),
            );
        }

        // All four URLs point to the CHIP callback controller, which redirects
        // the customer to order-confirmation (or back to the order page).
        $callback_url = $this->context->link->getModuleLink(
            'chip',
            'callback',
            array('id_cart' => (int) $cart->id),
            true
        );

        $params = array(
            'success_callback' => $callback_url,
            'success_redirect' => $callback_url,
            'failure_redirect' => $callback_url,
            'cancel_redirect' => $callback_url,
            'creator_agent' => 'PrestaShop: 1.0.0',
            'reference' => (string) $cart->id,
            'platform' => 'prestashop',
            'purchase' => array(
                'total_override' => $total_override,
                'due_strict' => (bool) Configuration::get('CHIP_DUE_STRICT'),
                'timezone' => (string) Configuration::get('CHIP_PURCHASE_TIME_ZONE'),
                'currency' => $iso_code,
                'language' => $language_code,
                'products' => $products,
            ),
            'brand_id' => (string) Configuration::get('CHIP_BRAND_ID'),
            'client' => $client,
        );

        // Optional payment method whitelist from config
        $whitelist = $this->module->getConfiguredWhitelist();
        if (count($whitelist) > 0) {
            $params['payment_method_whitelist'] = $whitelist;
        }

        return $params;
    }

    /**
     * Create the CHIP purchase and redirect to its checkout URL.
     */
    public function postProcess()
    {
        $id_cart = (int) Tools::getValue('id_cart', 0);
        $cart = $this->getValidCart();
        if ($cart === false) {
            PrestaShopLogger::addLog('CHIP: payment controller - no valid cart, redirect to order', 1, null, 'Cart', $id_cart, true);
            $this->context->cookie->chip_payment_error = $this->module->l('Unable to start the payment. Please try again.');
            $this->context->cookie->write();
            Tools::redirect($this->context->link->getPageLink('order', true));

            return;
        }

        // Never create a purchase for a cart that already has an order.
        $existing_id = (int) Order::getIdByCartId((int) $cart->id);
        if ($existing_id > 0) {
            $order = new Order($existing_id);
            $key = Validate::isLoadedObject($order) ? $order->secure_key : '';
            Tools::redirect(
                $this->context->link->getPageLink(
                    'order-confirmation',
                    true,
                    null,
                    'id_cart=' . (int) $cart->id . '&id_module=' . (int) $this->module->id . '&key=' . $key
                )
            );

            return;
        }

        $params = $this->buildPurchaseParams($cart);
        $chip = $this->module->getApi();
        $purchase = $chip->createPurchase($params);

        if (!is_array($purchase) || empty($purchase['id']) || empty($purchase['checkout_url'])) {
            PrestaShopLogger::addLog('CHIP: createPurchase failed for cart ' . (int) $cart->id, 3, null, 'Cart', (int) $cart->id, true);
            $this->context->cookie->chip_payment_error = $this->module->l('Unable to initialize the payment with CHIP. Please try again.');
            $this->context->cookie->write();
            Tools::redirect($this->context->link->getPageLink('order', true));

            return;
        }

        // Store purchase data in the session cookie (scalar only - PrestaShop
        // cookies cannot hold arrays).
        $this->context->cookie->chip_payment_id = (string) $purchase['id'];
        $this->context->cookie->chip_purchase_cart = (int) $cart->id;
        $this->context->cookie->chip_purchase_total = $params['purchase']['total_override'];
        $this->context->cookie->write();

        PrestaShopLogger::addLog(
            'CHIP: purchase created ' . $purchase['id'] . ' for cart ' . (int) $cart->id,
            1,
            null,
            'Cart',
            (int) $cart->id,
            true
        );

        Tools::redirect((string) $purchase['checkout_url']);
    }

    public function initContent()
    {
        parent::initContent();
    }
}

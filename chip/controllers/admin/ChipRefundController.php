<?php
/**
 * CHIP for PrestaShop - Admin refund controller.
 *
 * Handles the AJAX refund action (action=refund&ajax=1) posted from the order
 * page refund button (displayAdminOrderSide / displayAdminOrderMain /
 * displayAdminOrderContentOrder), and the "Test API" action used from the
 * module configuration page (action=testapi&ajax=1).
 *
 * Compatible with PrestaShop 9.x (legacy AdminController AJAX flow:
 * `ajax=1&action=xxx` maps to ajaxProcessXxx via Tools::toCamelCase).
 *
 * @author CHIPAsia
 * @license http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

class ChipRefundController extends ModuleAdminController
{
    /**
     * Process a refund request: POST /purchases/{id}/refund/ with amount in sen.
     */
    public function ajaxProcessRefund(): void
    {
        header('Content-Type: application/json');

        $id_order = (int) Tools::getValue('id_order', 0);
        $purchase_id = (string) Tools::getValue('purchase_id', '');

        $order = new Order($id_order);
        if (!Validate::isLoadedObject($order) || $order->module !== $this->module->name) {
            $this->ajaxDieJson(['success' => false, 'message' => $this->module->l('Invalid order.')]);
        }

        // The purchase id must match the one recorded on the order.
        if ($purchase_id === '' || $purchase_id !== $this->module->getOrderPurchaseId($order)) {
            $this->ajaxDieJson(['success' => false, 'message' => $this->module->l('No valid CHIP purchase found for this order.')]);
        }

        // Refund the full paid amount (sen).
        $amount_sen = (int) round((float) $order->total_paid * 100);
        if ($amount_sen <= 0) {
            $this->ajaxDieJson(['success' => false, 'message' => $this->module->l('Nothing to refund.')]);
        }

        $chip = $this->module->getApi();
        $result = $chip->refundPurchase($purchase_id, $amount_sen);

        if (!is_array($result) || (isset($result['status']) && $result['status'] === 'error')) {
            PrestaShopLogger::addLog(
                'CHIP: refund failed for order ' . $id_order . ' purchase ' . $purchase_id,
                3,
                null,
                'Order',
                $id_order,
                true
            );
            $this->ajaxDieJson([
                'success' => false,
                'message' => $this->module->l('Refund failed. Check the CHIP dashboard for details.'),
            ]);
        }

        // Update the PrestaShop order status to Refunded (creates an order
        // history entry via OrderHistory).
        try {
            $order->setCurrentState(Configuration::get('PS_OS_REFUND'));
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'CHIP: failed to update order ' . $id_order . ' status after refund: ' . $e->getMessage(),
                3,
                null,
                'Order',
                $id_order,
                true
            );
        }

        PrestaShopLogger::addLog(
            'CHIP: refund processed for order ' . $id_order . ' purchase ' . $purchase_id . ' amount ' . $amount_sen,
            1,
            null,
            'Order',
            $id_order,
            true
        );

        $this->ajaxDieJson([
            'success' => true,
            'message' => $this->module->l('Refund request sent to CHIP. Order marked as refunded.'),
        ]);
    }

    /**
     * Test API connectivity: GET /payment_methods/ with amount=1000 (safe
     * default so all methods are returned, see CHIP-API-SPEC.md).
     */
    public function ajaxProcessTestapi(): void
    {
        header('Content-Type: application/json');

        $chip = $this->module->getApi();
        // currency is mandatory for /payment_methods/; default to MYR when the
        // shop has no currency context (e.g. direct AJAX call from config page).
        $currency = 'MYR';
        if (isset($this->context->currency) && Validate::isLoadedObject($this->context->currency)) {
            $currency = strtoupper((string) $this->context->currency->iso_code);
        }
        $methods = $chip->getPaymentMethods(1000, $currency, '');

        if (!is_array($methods)) {
            $this->ajaxDieJson([
                'success' => false,
                'message' => $this->module->l('API connection failed. Check your Secret Key and Brand ID.'),
            ]);
        }

        $available = is_array($methods['available_payment_methods'] ?? null)
            ? $methods['available_payment_methods']
            : [];

        $this->ajaxDieJson([
            'success' => true,
            'message' => sprintf($this->module->l('API connection OK (%d payment methods available).'), count($available)),
        ]);
    }

    /**
     * Echo a JSON payload and stop processing (avoids the admin layout).
     *
     * @param array $data
     */
    protected function ajaxDieJson(array $data): void
    {
        echo json_encode($data);
        die();
    }
}

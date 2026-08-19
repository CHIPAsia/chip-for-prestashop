<?php
/**
 * CHIP for PrestaShop - CHIP payment gateway module.
 *
 * Compatible with PrestaShop 9.0.0 - 9.x (single module).
 * PHP 8.1+ optimized.
 *
 * @author CHIPAsia
 * @license http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

class Chip extends PaymentModule
{
    public $name = 'chip';

    public $tab = 'payments_gateways';

    public $version = '1.0.1';

    public $author = 'CHIP';

    public $need_instance = 1;

    /** @var array{min: string, max: string} */
    public $ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];

    public $bootstrap = true;

    public $displayName;

    public $description;

    public $confirmUninstall;

    /** @var string Module key provided by PrestaShop Addons (assigned at product registration) */
    public $module_key = 'YOUR_MODULE_KEY_FROM_PRESTASHOP_ADDONS';

    /** @var string Module version used in the creator_agent header */
    public const CREATOR_AGENT_VERSION = '1.0.1';

    public function __construct()
    {
        $this->displayName = $this->l('CHIP');
        $this->description = $this->l('Accept payments via CHIP (FPX, DuitNow QR, cards and more).');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall the CHIP payment module?');

        parent::__construct();
    }

    public function install(): bool
    {
        if (!parent::install()) {
            return false;
        }

        $this->registerHook('paymentOptions');
        $this->registerHook('displayPaymentReturn');
        $this->registerHook('displayAdminOrderSide');
        $this->registerHook('displayAdminOrderMain');
        $this->registerHook('displayAdminOrderContentOrder');

        // Hidden admin tab that routes the refund + test-api AJAX actions
        // to controllers/admin/ChipRefundController.php (the 9.x Symfony
        // legacy router resolves module tabs from the tab table).
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'ChipRefund';
        $tab->name = [];
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'CHIP Refund';
        }
        $tab->id_parent = -1; // hidden from the Back Office menu
        $tab->module = $this->name;
        if (!$tab->add()) {
            return false;
        }

        Configuration::updateValue('CHIP_SECRET_KEY', '');
        Configuration::updateValue('CHIP_BRAND_ID', '');
        Configuration::updateValue('CHIP_PAYMENT_METHOD_WHITELIST', '');
        Configuration::updateValue('CHIP_DUE_STRICT', 0);
        Configuration::updateValue('CHIP_PURCHASE_TIME_ZONE', 'Asia/Kuala_Lumpur');
        Configuration::updateValue('CHIP_CHECKOUT_TEXT', '');
        Configuration::updateValue('CHIP_PUBLIC_KEY', '');

        return true;
    }

    public function uninstall(): bool
    {
        $id_tab = (int) Tab::getIdFromClassName('ChipRefund');
        if ($id_tab > 0) {
            $tab = new Tab($id_tab);
            if (Validate::isLoadedObject($tab) && $tab->module === $this->name) {
                $tab->delete();
            }
        }

        Configuration::deleteByName('CHIP_SECRET_KEY');
        Configuration::deleteByName('CHIP_BRAND_ID');
        Configuration::deleteByName('CHIP_PAYMENT_METHOD_WHITELIST');
        Configuration::deleteByName('CHIP_DUE_STRICT');
        Configuration::deleteByName('CHIP_PURCHASE_TIME_ZONE');
        Configuration::deleteByName('CHIP_CHECKOUT_TEXT');
        Configuration::deleteByName('CHIP_PUBLIC_KEY');

        return parent::uninstall();
    }

    /**
     * Hook paymentOptions (PrestaShop 9.x).
     *
     * @param array $params
     *
     * @return array of PaymentOption
     */
    public function hookPaymentOptions(array $params): array
    {
        if (!$this->active) {
            return [];
        }

        $cart = $this->context->cart ?? null;
        if (!Validate::isLoadedObject($cart) || !$cart->id) {
            return [];
        }
        if (!(int) $cart->id_customer) {
            return [];
        }

        // A cart that already produced an order is handled by the payment flow (retry),
        // but we must not offer a second CHIP purchase for a paid cart.
        if (Order::getIdByCartId((int) $cart->id) > 0) {
            return [];
        }

        $payment_option = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $payment_option->setCallToActionText($this->l('Pay with CHIP'));
        $payment_option->setModuleName($this->name);
        $payment_option->setAction(
            $this->context->link->getModuleLink(
                $this->name,
                'payment',
                ['id_cart' => (int) $cart->id],
                true
            )
        );

        // Additional information text shown under "Pay with CHIP" on the
        // checkout page. Merchants can customise it via the module config
        // (CHIP_CHECKOUT_TEXT); when empty, the configured payment methods
        // are listed automatically.
        $checkout_text = trim((string) Configuration::get('CHIP_CHECKOUT_TEXT'));
        if ($checkout_text !== '') {
            $payment_option->setAdditionalInformation($checkout_text);
        } else {
            $methods = $this->getFormattedMethodLabels();
            if (count($methods) > 0) {
                $payment_option->setAdditionalInformation(
                    $this->l('Pay securely with:') . ' ' . implode(', ', $methods)
                );
            } else {
                $payment_option->setAdditionalInformation(
                    $this->l('Pay securely with FPX, DuitNow QR, cards and more.')
                );
            }
        }

        // Show any payment error left in the session (redirected back from the callback)
        if (isset($this->context->cookie->chip_payment_error) && $this->context->cookie->chip_payment_error !== '') {
            $payment_option->setAdditionalInformation((string) $this->context->cookie->chip_payment_error);
            unset($this->context->cookie->chip_payment_error);
            $this->context->cookie->write();
        }

        return [$payment_option];
    }

    /**
     * Hook displayPaymentReturn: shown on the order confirmation page after a CHIP payment.
     *
     * @param array $params
     *
     * @return string HTML
     */
    public function hookDisplayPaymentReturn(array $params): string
    {
        if (!$this->active) {
            return '';
        }

        $order = $params['order'] ?? null;
        if (!Validate::isLoadedObject($order)) {
            return '';
        }

        $this->context->smarty->assign([
            'chip_order_reference' => $order->reference,
            'chip_total_paid' => $order->total_paid,
            'chip_payment_method' => $order->payment,
            'chip_module_name' => $this->displayName,
        ]);

        return $this->display(__FILE__, 'payment_return.tpl');
    }

    /**
     * Hook displayAdminOrderSide (9.x) - refund button.
     *
     * @param array $params
     *
     * @return string HTML
     */
    public function hookDisplayAdminOrderSide(array $params): string
    {
        return $this->renderAdminRefund($params);
    }

    /**
     * Hook displayAdminOrderMain (9.x) - refund button (fallback location).
     *
     * @param array $params
     *
     * @return string HTML
     */
    public function hookDisplayAdminOrderMain(array $params): string
    {
        return $this->renderAdminRefund($params);
    }

    /**
     * Hook displayAdminOrderContentOrder - refund button (legacy location).
     *
     * @param array $params
     *
     * @return string HTML
     */
    public function hookDisplayAdminOrderContentOrder(array $params): string
    {
        return $this->renderAdminRefund($params);
    }

    /**
     * Build the admin refund block (single implementation shared by the three hooks).
     *
     * @param array $params
     *
     * @return string HTML
     */
    protected function renderAdminRefund(array $params): string
    {
        if (!$this->active) {
            return '';
        }

        // displayAdminOrderSide / displayAdminOrderMain pass id_order;
        // displayAdminOrderContentOrder passes an Order object.
        $id_order = (int) ($params['id_order'] ?? 0);
        if ($id_order <= 0 && isset($params['order']) && Validate::isLoadedObject($params['order'])) {
            $id_order = (int) $params['order']->id;
        }
        if ($id_order <= 0) {
            return '';
        }

        $order = new Order($id_order);
        if (!Validate::isLoadedObject($order)) {
            return '';
        }

        if ($order->module !== $this->name) {
            return '';
        }

        // Only show when there is at least one recorded CHIP payment (transaction id = purchase id).
        $purchase_id = $this->getOrderPurchaseId($order);
        if ($purchase_id === '') {
            return '';
        }

        $this->context->smarty->assign([
            'chip_refund_url' => $this->context->link->getAdminLink(
                'ChipRefund',
                true,
                [],
                [
                    'ajax' => 1,
                    'action' => 'refund',
                    'id_order' => $id_order,
                    'purchase_id' => $purchase_id,
                ]
            ),
            'chip_purchase_id' => $purchase_id,
            'chip_id_order' => $id_order,
            'chip_total_paid' => $order->total_paid,
        ]);

        return $this->display(__FILE__, 'admin_refund.tpl');
    }

    /**
     * Admin configuration page.
     *
     * @return string HTML
     */
    public function getContent(): string
    {
        $output = '';

        if (Tools::isSubmit('submitChipConfig')) {
            $secret_key = trim((string) Tools::getValue('CHIP_SECRET_KEY'));
            $brand_id = trim((string) Tools::getValue('CHIP_BRAND_ID'));

            $errors = [];
            if ($secret_key === '') {
                $errors[] = $this->l('Secret key is required.');
            }
            if ($brand_id === '') {
                $errors[] = $this->l('Brand ID is required.');
            }

            if ($errors === []) {
                Configuration::updateValue('CHIP_SECRET_KEY', $secret_key);
                Configuration::updateValue('CHIP_BRAND_ID', $brand_id);

                // Clear cached public key when credentials change
                Configuration::deleteByName('CHIP_PUBLIC_KEY');

                $whitelist = Tools::getValue('CHIP_PAYMENT_METHOD_WHITELIST[]');
                if (is_array($whitelist)) {
                    $whitelist = array_values(array_filter($whitelist));
                    Configuration::updateValue('CHIP_PAYMENT_METHOD_WHITELIST', json_encode($whitelist));
                } else {
                    Configuration::updateValue('CHIP_PAYMENT_METHOD_WHITELIST', '');
                }

                Configuration::updateValue('CHIP_DUE_STRICT', (int) Tools::getValue('CHIP_DUE_STRICT', 0));
                Configuration::updateValue(
                    'CHIP_PURCHASE_TIME_ZONE',
                    (string) Tools::getValue('CHIP_PURCHASE_TIME_ZONE', 'Asia/Kuala_Lumpur')
                );
                Configuration::updateValue(
                    'CHIP_CHECKOUT_TEXT',
                    (string) Tools::getValue('CHIP_CHECKOUT_TEXT', '')
                );

                $output .= $this->displayConfirmation($this->l('Settings updated'));
            } else {
                $output .= $this->displayError(implode('<br />', $errors));
            }
        }

        $output .= $this->renderForm();
        $output .= $this->renderTestApiButton();

        return $output;
    }

    /**
     * Render the configuration form (plain HTML template - avoids HelperForm
     * template resolution issues on PrestaShop 9.x).
     *
     * @return string HTML
     */
    public function renderForm(): string
    {
        $whitelist = $this->getConfiguredWhitelist();

        $this->context->smarty->assign([
            'chip_config_saved' => Tools::isSubmit('submitChipConfig') && count($this->getConfigErrors()) === 0,
            'chip_config_error' => implode('<br />', $this->getConfigErrors()),
            'chip_config_action' => $_SERVER['REQUEST_URI'],
            'chip_secret_key' => (string) Configuration::get('CHIP_SECRET_KEY'),
            'chip_brand_id' => (string) Configuration::get('CHIP_BRAND_ID'),
            'chip_method_options' => $this->getFormattedLabels(),
            'chip_method_selected' => $whitelist,
            'chip_due_strict' => (int) Configuration::get('CHIP_DUE_STRICT'),
            'chip_timezone' => (string) Configuration::get('CHIP_PURCHASE_TIME_ZONE'),
            'chip_checkout_text' => (string) Configuration::get('CHIP_CHECKOUT_TEXT'),
        ]);

        return $this->display(__FILE__, 'config_form.tpl');
    }

    /**
     * Collect configuration validation errors (used by renderForm).
     *
     * @return array
     */
    protected function getConfigErrors(): array
    {
        $errors = [];
        if (Tools::isSubmit('submitChipConfig')) {
            if (trim((string) Tools::getValue('CHIP_SECRET_KEY')) === '') {
                $errors[] = $this->l('Secret key is required.');
            }
            if (trim((string) Tools::getValue('CHIP_BRAND_ID')) === '') {
                $errors[] = $this->l('Brand ID is required.');
            }
        }

        return $errors;
    }

    /**
     * "Test API" button + AJAX result container (POSTs to payment_methods with amount=1000).
     *
     * @return string HTML
     */
    protected function renderTestApiButton(): string
    {
        $test_url = $this->context->link->getAdminLink(
            'ChipRefund',
            true,
            [],
            ['ajax' => 1, 'action' => 'testapi']
        );

        $this->context->smarty->assign([
            'chip_test_url' => $test_url,
        ]);

        return $this->display(__FILE__, 'test_api.tpl');
    }

    /**
     * Resolve the configured payment method whitelist (JSON string in Configuration)
     * into an array of identifier codes, or an empty array when unset.
     *
     * @return array
     */
    public function getConfiguredWhitelist(): array
    {
        $whitelist = Configuration::get('CHIP_PAYMENT_METHOD_WHITELIST');
        if (empty($whitelist)) {
            return [];
        }

        $whitelist = json_decode($whitelist, true);
        if (!is_array($whitelist)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $whitelist)));
    }

    /**
     * Identifier -> user-facing display name map (exact spellings, see CHIP-API-SPEC.md).
     *
     * @return array
     */
    protected function getFormattedLabels(): array
    {
        return [
            'fpx' => 'FPX',
            'fpx_b2b1' => 'FPX B2B1',
            'card' => 'Card (Visa, Mastercard, Maestro)',
            'duitnow_qr' => 'DuitNow QR',
            'dnqr' => 'DuitNow QR',
            'razer_atome' => 'Atome',
            'razer_grabpay' => 'GrabPay',
            'razer_maybankqr' => 'Maybank QRPay',
            'razer_shopeepay' => 'ShopeePay',
            'razer_tng' => "Touch 'n Go eWallet",
            'crypto_coin' => 'Crypto Coin',
        ];
    }

    /**
     * Display names for the configured whitelist (used on the checkout PaymentOption).
     *
     * @return array list of display names
     */
    public function getFormattedMethodLabels(): array
    {
        $codes = $this->getConfiguredWhitelist();
        if ($codes === []) {
            return [];
        }

        $labels = $this->getFormattedLabels();
        $output = [];
        foreach ($codes as $code) {
            $code = (string) $code;
            $output[] = $labels[$code] ?? $code;
        }

        return $output;
    }

    /**
     * Read the CHIP purchase id stored on the order (transaction id of the order payment).
     *
     * @return string purchase id, or '' when none
     */
    public function getOrderPurchaseId(Order $order): string
    {
        foreach ($order->getOrderPaymentCollection() as $payment) {
            $transaction_id = (string) ($payment->transaction_id ?? '');
            if ($transaction_id !== '') {
                return $transaction_id;
            }
        }

        return '';
    }

    /**
     * Instantiate the CHIP API client with the configured credentials.
     */
    public function getApi(): ChipApi
    {
        // Explicit require: the PrestaShop class index may not have been
        // rebuilt after the module was copied into place (e.g. manual install
        // or module update), which would otherwise raise ClassNotFoundError.
        if (!class_exists('ChipApi', false)) {
            require_once _PS_MODULE_DIR_ . $this->name . '/classes/ChipApi.php';
        }

        return ChipApi::getInstance(
            (string) Configuration::get('CHIP_SECRET_KEY'),
            (string) Configuration::get('CHIP_BRAND_ID')
        );
    }
}

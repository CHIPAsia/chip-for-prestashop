<?php
/**
 * CHIP for PrestaShop - CHIP payment gateway module.
 *
 * Compatible with PrestaShop 1.7.0 - 9.1.4 (single module).
 * PHP 7.2+ compatible.
 *
 * @author CHIPAsia
 * @license http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Chip extends PaymentModule
{
    /** @var string */
    public $name = 'chip';

    /** @var string */
    public $tab = 'payments_gateways';

    /** @var string */
    public $version = '1.0.1';

    /** @var string */
    public $author = 'CHIPAsia';

    /** @var bool */
    public $need_instance = 1;

    /** @var array */
    public $ps_versions_compliancy = array('min' => '1.7', 'max' => '9.9.99');

    /** @var bool */
    public $bootstrap = true;

    /** @var string */
    public $displayName;

    /** @var string */
    public $description;

    /** @var string */
    public $confirmUninstall;

    /** @var string Module key provided by PrestaShop Addons (assigned at product registration) */
    public $module_key = 'YOUR_MODULE_KEY_FROM_PRESTASHOP_ADDONS';

    /** @var string Module version used in the creator_agent header */
    const CREATOR_AGENT = 'PrestaShop: 1.0.1';

    public function __construct()
    {
        $this->displayName = $this->l('CHIP');
        $this->description = $this->l('Accept payments via CHIP (FPX, DuitNow QR, cards and more).');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall the CHIP payment module?');

        parent::__construct();
    }

    public function install()
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
        // to controllers/admin/ChipRefundController.php (1.7 legacy dispatcher
        // and 9.x Symfony legacy router both resolve module tabs from the tab table).
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'ChipRefund';
        $tab->name = array();
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
        Configuration::updateValue('CHIP_PUBLIC_KEY', '');

        return true;
    }

    public function uninstall()
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
        Configuration::deleteByName('CHIP_PUBLIC_KEY');

        return parent::uninstall();
    }

    /**
     * Hook paymentOptions (PrestaShop 1.7+ / 8.x / 9.x).
     *
     * @param array $params
     * @return array of PaymentOption
     */
    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return array();
        }

        $cart = isset($this->context->cart) ? $this->context->cart : null;
        if (!Validate::isLoadedObject($cart) || !$cart->id) {
            return array();
        }
        if (!(int) $cart->id_customer) {
            return array();
        }

        // A cart that already produced an order is handled by the payment flow (retry),
        // but we must not offer a second CHIP purchase for a paid cart.
        $existing = Order::getIdByCartId((int) $cart->id);
        if ($existing > 0) {
            return array();
        }

        $payment_option = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $payment_option->setCallToActionText($this->l('Pay with CHIP'));
        $payment_option->setModuleName($this->name);
        $payment_option->setLogo($this->_path . 'logo.png');
        $payment_option->setAction(
            $this->context->link->getModuleLink(
                $this->name,
                'payment',
                array('id_cart' => (int) $cart->id),
                true
            )
        );

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

        // Show any payment error left in the session (redirected back from the callback)
        if (isset($this->context->cookie->chip_payment_error) && $this->context->cookie->chip_payment_error !== '') {
            $error_text = (string) $this->context->cookie->chip_payment_error;
            if (count($methods) > 0) {
                $payment_option->setAdditionalInformation(
                    $this->l('Pay securely with:') . ' ' . implode(', ', $methods) . ' — ' . $error_text
                );
            } else {
                $payment_option->setAdditionalInformation($error_text);
            }
            unset($this->context->cookie->chip_payment_error);
            $this->context->cookie->write();
        }

        return array($payment_option);
    }

    /**
     * Hook displayPaymentReturn: shown on the order confirmation page after a CHIP payment.
     * Hook signature is identical in 1.7.8.11 and 9.1.4: params['order'] is an Order object.
     *
     * @param array $params
     * @return string HTML
     */
    public function hookDisplayPaymentReturn($params)
    {
        if (!$this->active) {
            return '';
        }

        $order = isset($params['order']) ? $params['order'] : null;
        if (!Validate::isLoadedObject($order)) {
            return '';
        }

        $this->context->smarty->assign(array(
            'chip_order_reference' => $order->reference,
            'chip_total_paid' => $order->total_paid,
            'chip_payment_method' => $order->payment,
            'chip_module_name' => $this->displayName,
        ));

        return $this->display(__FILE__, 'payment_return.tpl');
    }

    /**
     * Hook displayAdminOrderSide (1.7.7+ / 8.x / 9.x) - refund button.
     *
     * @param array $params
     * @return string HTML
     */
    public function hookDisplayAdminOrderSide($params)
    {
        return $this->renderAdminRefund($params);
    }

    /**
     * Hook displayAdminOrderMain (1.7.7+ / 8.x / 9.x) - refund button (fallback location).
     *
     * @param array $params
     * @return string HTML
     */
    public function hookDisplayAdminOrderMain($params)
    {
        return $this->renderAdminRefund($params);
    }

    /**
     * Hook displayAdminOrderContentOrder (since 1.7.7) - refund button for
     * PrestaShop 1.7.0-1.7.6 which do not have displayAdminOrderSide yet.
     *
     * @param array $params
     * @return string HTML
     */
    public function hookDisplayAdminOrderContentOrder($params)
    {
        return $this->renderAdminRefund($params);
    }

    /**
     * Build the admin refund block (single implementation shared by the three hooks).
     *
     * @param array $params
     * @return string HTML
     */
    protected function renderAdminRefund($params)
    {
        if (!$this->active) {
            return '';
        }

        // displayAdminOrderSide / displayAdminOrderMain pass id_order;
        // displayAdminOrderContentOrder (1.7.0-1.7.6) passes an Order object.
        $id_order = isset($params['id_order']) ? (int) $params['id_order'] : 0;
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

        $this->context->smarty->assign(array(
            'chip_refund_url' => $this->context->link->getAdminLink(
                'ChipRefund',
                true,
                array(),
                array(
                    'ajax' => 1,
                    'action' => 'refund',
                    'id_order' => $id_order,
                    'purchase_id' => $purchase_id,
                )
            ),
            'chip_purchase_id' => $purchase_id,
            'chip_id_order' => $id_order,
            'chip_total_paid' => $order->total_paid,
        ));

        return $this->display(__FILE__, 'admin_refund.tpl');
    }

    /**
     * Admin configuration page (HelperForm).
     *
     * @return string HTML
     */
    public function getContent()
    {
        $output = '';
        $errors = array();

        if (Tools::isSubmit('submitChipConfig')) {
            $secret_key = trim((string) Tools::getValue('CHIP_SECRET_KEY'));
            $brand_id = trim((string) Tools::getValue('CHIP_BRAND_ID'));

            if ($secret_key === '') {
                $errors[] = $this->l('Secret key is required.');
            }
            if ($brand_id === '') {
                $errors[] = $this->l('Brand ID is required.');
            }

            if (count($errors) === 0) {
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
     * Build the HelperForm configuration form.
     *
     * @return string HTML
     */
    protected function buildForm()
    {
        $default_lang = (int) Configuration::get('PS_LANG_DEFAULT');

        $fields_form = array(
            array(
                'type' => 'legend',
                'title' => $this->l('CHIP Settings'),
                'icon' => 'icon-credit-card',
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Secret Key'),
                'name' => 'CHIP_SECRET_KEY',
                'required' => true,
                'desc' => $this->l('Your CHIP secret key (per brand). Never share this key.'),
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Brand ID'),
                'name' => 'CHIP_BRAND_ID',
                'required' => true,
                'desc' => $this->l('Your CHIP brand ID.'),
            ),
            array(
                'type' => 'select',
                'label' => $this->l('Payment Methods'),
                'name' => 'CHIP_PAYMENT_METHOD_WHITELIST[]',
                'multiple' => true,
                'options' => array(
                    'query' => array(
                        array('id' => 'fpx', 'name' => 'FPX'),
                        array('id' => 'fpx_b2b1', 'name' => 'FPX B2B1'),
                        array('id' => 'card', 'name' => 'Card (Visa, Mastercard, Maestro)'),
                        array('id' => 'duitnow_qr', 'name' => 'DuitNow QR'),
                        array('id' => 'razer_atome', 'name' => 'Atome'),
                        array('id' => 'razer_grabpay', 'name' => 'GrabPay'),
                        array('id' => 'razer_maybankqr', 'name' => 'Maybank QRPay'),
                        array('id' => 'razer_shopeepay', 'name' => 'ShopeePay'),
                        array('id' => 'razer_tng', 'name' => "Touch 'n Go eWallet"),
                        array('id' => 'crypto_coin', 'name' => 'Crypto Coin'),
                    ),
                    'id' => 'id',
                    'name' => 'name',
                ),
                'desc' => $this->l('Leave empty to allow all payment methods. Select only the methods you want to offer.'),
            ),
            array(
                'type' => 'switch',
                'label' => $this->l('Due Strict'),
                'name' => 'CHIP_DUE_STRICT',
                'is_bool' => true,
                'values' => array(
                    array('id' => 'active_on', 'value' => 1, 'label' => $this->l('Enabled')),
                    array('id' => 'active_off', 'value' => 0, 'label' => $this->l('Disabled')),
                ),
                'desc' => $this->l('When enabled, payment must be completed before the due time.'),
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Purchase Timezone'),
                'name' => 'CHIP_PURCHASE_TIME_ZONE',
                'required' => true,
                'desc' => $this->l('Timezone used for the purchase (e.g. Asia/Kuala_Lumpur).'),
            ),
        );

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->title = $this->displayName;
        $helper->submit_action = 'submitChipConfig';
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;

        $helper->fields_value = array(
            'CHIP_SECRET_KEY' => Configuration::get('CHIP_SECRET_KEY'),
            'CHIP_BRAND_ID' => Configuration::get('CHIP_BRAND_ID'),
            'CHIP_PAYMENT_METHOD_WHITELIST[]' => json_decode(Configuration::get('CHIP_PAYMENT_METHOD_WHITELIST'), true),
            'CHIP_DUE_STRICT' => (int) Configuration::get('CHIP_DUE_STRICT'),
            'CHIP_PURCHASE_TIME_ZONE' => Configuration::get('CHIP_PURCHASE_TIME_ZONE'),
        );

        return $helper->generateForm(array(array('form' => $fields_form)));
    }

    /**
     * Render the configuration form.
     *
     * @return string HTML
     */
    public function renderForm()
    {
        return $this->buildForm();
    }

    /**
     * "Test API" button + AJAX result container (POSTs to payment_methods with amount=1000).
     *
     * @return string HTML
     */
    protected function renderTestApiButton()
    {
        $test_url = $this->context->link->getAdminLink(
            'ChipRefund',
            true,
            array(),
            array('ajax' => 1, 'action' => 'testapi')
        );

        $this->context->smarty->assign(array(
            'chip_test_url' => $test_url,
        ));

        return $this->display(__FILE__, 'test_api.tpl');
    }

    /**
     * Resolve the configured payment method whitelist (JSON string in Configuration)
     * into an array of identifier codes, or an empty array when unset.
     *
     * @return array
     */
    public function getConfiguredWhitelist()
    {
        $whitelist = Configuration::get('CHIP_PAYMENT_METHOD_WHITELIST');
        if (empty($whitelist)) {
            return array();
        }

        $whitelist = json_decode($whitelist, true);
        if (!is_array($whitelist)) {
            return array();
        }

        return array_values(array_filter(array_map('strval', $whitelist)));
    }

    /**
     * Identifier -> user-facing display name map (exact spellings, see CHIP-API-SPEC.md).
     *
     * @return array
     */
    protected function getFormattedLabels()
    {
        return array(
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
        );
    }

    /**
     * Display names for the configured whitelist (used on the checkout PaymentOption).
     *
     * @return array list of display names
     */
    public function getFormattedMethodLabels()
    {
        $codes = $this->getConfiguredWhitelist();
        if (count($codes) === 0) {
            return array();
        }

        $labels = $this->getFormattedLabels();
        $output = array();
        foreach ($codes as $code) {
            $code = (string) $code;
            if (isset($labels[$code])) {
                $output[] = $labels[$code];
            } else {
                $output[] = $code;
            }
        }

        return $output;
    }

    /**
     * Read the CHIP purchase id stored on the order (transaction id of the order payment).
     *
     * @param Order $order
     * @return string purchase id, or '' when none
     */
    public function getOrderPurchaseId(Order $order)
    {
        $payments = $order->getOrderPaymentCollection();
        foreach ($payments as $payment) {
            $transaction_id = isset($payment->transaction_id) ? (string) $payment->transaction_id : '';
            if ($transaction_id !== '') {
                return $transaction_id;
            }
        }

        return '';
    }

    /**
     * Instantiate the CHIP API client with the configured credentials.
     *
     * @return ChipApi
     */
    public function getApi()
    {
        return ChipApi::getInstance(
            (string) Configuration::get('CHIP_SECRET_KEY'),
            (string) Configuration::get('CHIP_BRAND_ID')
        );
    }
}

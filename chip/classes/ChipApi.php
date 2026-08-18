<?php
/**
 * CHIP for PrestaShop - CHIP Collect API client.
 *
 * PHP 7.2+ compatible. Singleton keyed by md5(secret_key . brand_id) so that
 * different credentials get different instances (see CHIP-API-SPEC.md).
 *
 * @author CHIPAsia
 * @license http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class ChipApi
{
    const API_BASE = 'https://gate.chip-in.asia/api/v1';

    /** @var string */
    protected $secret_key;

    /** @var string */
    protected $brand_id;

    /** @var int */
    protected $timeout = 15;

    /** @var array of ChipApi instances keyed by md5(secret_key . brand_id) */
    protected static $instances = array();

    protected function __construct($secret_key, $brand_id)
    {
        $this->secret_key = $secret_key;
        $this->brand_id = $brand_id;
    }

    /**
     * Singleton: credentials berbeza => instance berbeza.
     *
     * @param string $secret_key
     * @param string $brand_id
     * @return ChipApi
     */
    public static function getInstance($secret_key, $brand_id)
    {
        $key = md5($secret_key . '|' . $brand_id);
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new self($secret_key, $brand_id);
        }

        return self::$instances[$key];
    }

    /**
     * Build the full API URL. Cache-bust `time` is always added on GET
     * requests (see CHIP-API-SPEC.md).
     *
     * @param string $path e.g. /purchases/
     * @param array $params
     * @return string
     */
    protected function buildUrl($path, $params = array())
    {
        $url = self::API_BASE . '/' . ltrim($path, '/');
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        return $url;
    }

    /**
     * Perform an HTTP request (GET/POST) with a JSON body.
     * Prefers cURL, falls back to a stream context.
     *
     * @param string $method GET or POST
     * @param string $path API path
     * @param array $body JSON body
     * @param array $query query params (time cache-bust is added automatically)
     * @return array|false decoded JSON response, or false on failure
     */
    protected function request($method, $path, $body = array(), $query = array())
    {
        if ($method === 'GET') {
            $query['time'] = time();
        }

        $url = $this->buildUrl($path, $query);
        $json = json_encode($body);
        $headers = array(
            'Authorization: Bearer ' . $this->secret_key,
            'Content-Type: application/json',
            'Accept: application/json',
        );

        $response = false;
        $status = 0;

        if (function_exists('curl_init')) {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $this->timeout);
            curl_setopt($curl, CURLOPT_TIMEOUT, $this->timeout);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            if ($method === 'POST') {
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
            }
            $response = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            if (PHP_VERSION_ID < 80000) {
                curl_close($curl);
            }
        } else {
            $context = stream_context_create(array(
                'http' => array(
                    'method' => $method,
                    'header' => implode("\r\n", $headers),
                    'content' => $json,
                    'timeout' => $this->timeout,
                    'ignore_errors' => true,
                ),
                'ssl' => array(
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ),
            ));
            $response = @file_get_contents($url, false, $context);
            $response_headers = function_exists('http_get_last_response_headers')
                ? http_get_last_response_headers()
                : (isset($http_response_header) ? $http_response_header : array());
            if (is_array($response_headers)) {
                foreach ($response_headers as $header_line) {
                    if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header_line, $matches)) {
                        $status = (int) $matches[1];
                        break;
                    }
                }
            }
        }

        if ($response === false || $response === '') {
            PrestaShopLogger::addLog('CHIP: API request failed for ' . $path . ' (status ' . $status . ')', 3, null, 'ChipApi', null, true);

            return false;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            PrestaShopLogger::addLog('CHIP: API returned invalid JSON for ' . $path . ' (status ' . $status . ')', 3, null, 'ChipApi', null, true);

            return false;
        }

        return $decoded;
    }

    /**
     * Create a purchase (payment).
     *
     * @param array $params payment params (see CHIP-API-SPEC.md)
     * @return array|false purchase data, or false on error
     */
    public function createPurchase($params)
    {
        return $this->request('POST', '/purchases/', $params);
    }

    /**
     * Fetch a purchase by ID.
     *
     * @param string $purchase_id
     * @return array|false purchase data, or false on error
     */
    public function getPurchase($purchase_id)
    {
        if (empty($purchase_id)) {
            return false;
        }

        return $this->request('GET', '/purchases/' . rawurlencode($purchase_id) . '/');
    }

    /**
     * Refund a purchase. Amount is in sen (minor units).
     *
     * @param string $purchase_id
     * @param int $amount_sen
     * @return array|false refund data, or false on error
     */
    public function refundPurchase($purchase_id, $amount_sen)
    {
        if (empty($purchase_id) || (int) $amount_sen <= 0) {
            return false;
        }

        return $this->request('POST', '/purchases/' . rawurlencode($purchase_id) . '/refund/', array(
            'amount' => (int) $amount_sen,
        ));
    }

    /**
     * List available payment methods for the brand.
     * `amount` is mandatory (in sen); use 1000 (RM 10) as the safe default for
     * availability checks so that all methods are returned (see CHIP-API-SPEC.md).
     *
     * @param int $amount_sen
     * @param string $currency optional ISO 4217 code
     * @param string $language optional 2-letter ISO code
     * @return array|false list of payment methods, or false on error
     */
    public function getPaymentMethods($amount_sen = 1000, $currency = '', $language = '')
    {
        $query = array(
            'brand_id' => $this->brand_id,
            'amount' => (int) $amount_sen,
        );
        if ($currency !== '') {
            $query['currency'] = $currency;
        }
        if ($language !== '') {
            $query['language'] = $language;
        }

        return $this->request('GET', '/payment_methods/', array(), $query);
    }

    /**
     * Resolve the configured payment method whitelist against the merchant's
     * actual /payment_methods/ response, with preferred-method priority for
     * the DuitNow QR and ShopeePay groups (mirrors chip-for-fluent-cart):
     *
     *   - DuitNow QR group: dnqr wins when both {duitnow_qr, dnqr} are present.
     *   - Shopee Pay group: shopee_pay wins when both {razer_shopeepay, shopee_pay}
     *     are present; razer_shopeepay is the fallback.
     *
     * @param array  $whitelist Configured payment_method_whitelist.
     * @param string $currency  Order currency code (e.g. 'MYR').
     * @param int    $amount    Order total in sen (e.g. 12345 = RM 123.45).
     * @return array Final whitelist to send to CHIP.
     */
    public function resolvePaymentMethodGroups($whitelist, $currency, $amount)
    {
        $duitnow_group = array('duitnow_qr', 'dnqr');
        $shopee_group = array('razer_shopeepay', 'shopee_pay');

        $has_dnqr = count(array_intersect($whitelist, $duitnow_group)) > 0;
        $has_shopee = count(array_intersect($whitelist, $shopee_group)) > 0;

        // Short-circuit: no group member configured -> return untouched.
        if (!$has_dnqr && !$has_shopee) {
            return $whitelist;
        }

        $expanded = $whitelist;
        if ($has_dnqr) {
            $expanded = array_values(array_unique(array_merge($expanded, $duitnow_group)));
        }
        if ($has_shopee) {
            $expanded = array_values(array_unique(array_merge($expanded, $shopee_group)));
        }

        $response = $this->getPaymentMethods($amount, $currency, '');
        if (!is_array($response) || !isset($response['available_payment_methods'])) {
            // API failed -> fallback to expanded whitelist unchanged.
            return $expanded;
        }
        $available = $response['available_payment_methods'];

        $resolved_dnqr = $has_dnqr ? array_values(array_intersect($duitnow_group, $available)) : array();
        $resolved_shopee = $has_shopee ? array_values(array_intersect($shopee_group, $available)) : array();

        // Priority: dnqr wins over duitnow_qr; shopee_pay wins over razer_shopeepay.
        if (in_array('dnqr', $resolved_dnqr, true)) {
            $resolved_dnqr = array_values(array_diff($resolved_dnqr, array('duitnow_qr')));
        }
        if (in_array('shopee_pay', $resolved_shopee, true)) {
            $resolved_shopee = array_values(array_diff($resolved_shopee, array('razer_shopeepay')));
        }

        $all_groups = array_merge($duitnow_group, $shopee_group);
        $final = array_values(array_diff($expanded, $all_groups));
        $final = array_merge($final, $resolved_dnqr, $resolved_shopee);

        return $final;
    }

    /**
     * Fetch the public key used to verify webhook signatures.
     * Result is cached in Configuration (CHIP_PUBLIC_KEY).
     *
     * @return string|false PEM public key
     */
    public function getPublicKey()
    {
        $cached = Configuration::get('CHIP_PUBLIC_KEY');
        if (!empty($cached)) {
            return (string) $cached;
        }

        $response = $this->request('GET', '/public_key/');
        if (!is_array($response) || empty($response['key'])) {
            PrestaShopLogger::addLog('CHIP: Unable to fetch public key', 3, null, 'ChipApi', null, true);

            return false;
        }

        $key = (string) $response['key'];
        // Normalize escaped newlines (mirrors the WooCommerce get_public_key behavior)
        $key = str_replace(array('\r\n', '\n'), array("\r\n", "\n"), $key);
        Configuration::updateValue('CHIP_PUBLIC_KEY', $key);

        return $key;
    }

    /**
     * Verify the X-Signature header against the raw callback body.
     *
     * @param string $content raw request body
     * @param string $signature base64 encoded signature from X-Signature header
     * @return bool
     */
    public function verifySignature($content, $signature)
    {
        $public_key = $this->getPublicKey();
        if ($public_key === false) {
            return false;
        }

        $decoded = base64_decode($signature);
        if ($decoded === false || $decoded === '') {
            return false;
        }

        return openssl_verify($content, $decoded, $public_key, 'sha256WithRSAEncryption') === 1;
    }
}

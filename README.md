# CHIP for PrestaShop

CHIP payment gateway module for PrestaShop **1.7.0 – 9.1.4** (single module compatible across all versions, PHP 7.2+).

> **Using PrestaShop 1.6?** This module is NOT compatible with 1.6 (1.6 uses a different `displayPayment` hook). Use **[chip-for-prestashop-1.6](https://github.com/CHIPAsia/chip-for-prestashop-1.6)** for PrestaShop 1.6.x.

Accept payments via CHIP Collect: FPX, FPX B2B1, DuitNow QR, Card, Atome, GrabPay, Maybank QRPay, ShopeePay, Touch 'n Go eWallet, Crypto Coin.

## Requirements

- PrestaShop 1.7.0, 1.7.x, 8.x, or 9.1.x
- PHP 7.2+ (PHP 8.x supported; no PHP 8-only syntax is used)
- cURL extension (falls back to stream context when unavailable)
- OpenSSL extension (webhook signature verification)
- A CHIP brand with a **Secret Key** and **Brand ID** (see [docs.chip-in.asia](https://docs.chip-in.asia))

## Installation

1. Copy the `chip` folder into `<prestashop-root>/modules/` so the module lives at `modules/chip/chip.php`.
2. In the Back Office go to **Modules → Module Manager**, find **CHIP**, and click **Install**.
3. After installation, click **Configure** and fill in:
   - **Secret Key** — your CHIP brand secret key (never share it)
   - **Brand ID** — your CHIP brand ID
   - **Payment Methods** (optional) — restrict the methods offered at checkout; leave empty to allow all
   - **Due Strict** — when enabled, payment must be completed before the due time
   - **Purchase Timezone** — default `Asia/Kuala_Lumpur`
4. Click **Save**, then **Test API** to verify connectivity (calls `GET /payment_methods/` with `amount=1000`).

The module registers these hooks automatically:

| Hook | Purpose |
|------|---------|
| `paymentOptions` | Payment option at checkout (1.7+) |
| `displayPaymentReturn` | Payment summary on the order confirmation page |
| `displayAdminOrderSide` | Admin refund button (1.7.7+ / 8.x / 9.x) |
| `displayAdminOrderMain` | Admin refund button (fallback location) |
| `displayAdminOrderContentOrder` | Admin refund button for PrestaShop 1.7.0–1.7.6 |

## Payment Flow

1. The customer checks out and selects **Pay with CHIP**.
2. `controllers/front/payment.php` validates the cart (must belong to the logged-in customer and match the session cart), builds the purchase parameters, and POSTs to `https://gate.chip-in.asia/api/v1/purchases/`.
3. The purchase id is stored in the session cookie (`chip_payment_id`) and the customer is redirected to the CHIP checkout URL.
4. CHIP sends the webhook (`success_callback`) to `controllers/front/callback.php` and redirects the customer there via `success_redirect` / `failure_redirect` / `cancel_redirect`.
5. `callback.php` verifies the `X-Signature` header with the cached public key (`GET /public_key/`), falling back to `GET /purchases/{id}/` when the header is missing or verification fails.
6. When the status is `paid`, the order is created via `validateOrder` (idempotent — `Order::getIdByCartId` is checked first, so a cart is never double-validated) and the purchase id is stored as the order payment `transaction_id`.
7. The customer is redirected to `order-confirmation` with `id_cart`, `id_module`, and the order `secure_key`.

### Purchase parameters (create payment)

Built in `ChipPaymentModuleFrontController::buildPurchaseParams()` following `CHIP-API-SPEC.md`:

```
success_callback / success_redirect / failure_redirect / cancel_redirect → module callback URL (id_cart)
creator_agent: 'PrestaShop: 1.0.0'
reference: (string) id_cart
platform: 'prestashop'
purchase: {
  total_override: int sen (round(cart total × 100)),
  due_strict: from config,
  timezone: from config (default Asia/Kuala_Lumpur),
  currency: cart currency ISO code (lowercase),
  language: 2-letter shop language ISO code,
  products: [{ name, price (sen), quantity }]
}
brand_id: from config
client: { email, phone (max 32), full_name, street_address (max 128), country (ISO 2),
          city (max 128), zip_code (max 32), state (max 128), shipping_* } — from invoice
          address + customer; empty fields are dropped
payment_method_whitelist: from config (optional): fpx, fpx_b2b1, card, razer_atome,
          razer_grabpay, razer_maybankqr, razer_shopeepay, razer_tng, duitnow_qr, crypto_coin
```

Amounts are always integer **sen**. When a product's computed unit price is 0 (or the cart has no products), a single "Order N" line with `total_override` is sent instead.

## Callback

`controllers/front/callback.php`:

- **Signature verification**: `openssl_verify` (RSA PKCS#1 v1.5, SHA-256) against the raw request body using the `X-Signature` header and the public key from `GET /public_key/` (cached in `CHIP_PUBLIC_KEY`, cleared when credentials change). If the signature is missing or invalid, the module falls back to `GET /purchases/{id}/` and uses the actual purchase status.
- **Status `paid`**: checks `Order::getIdByCartId($id_cart)` first (idempotency — no double `validateOrder`), then calls:
  ```php
  $this->module->validateOrder($id_cart, Configuration::get('PS_OS_PAYMENT'), $total_paid,
      $this->module->displayName, null, array(), null, false, $secure_key);
  ```
  with the customer's `secure_key` (9 positional args — compatible with both the 1.7.8.11 and 9.1.4 signatures). The purchase id is passed via `$extra_vars['transaction_id']`, which `validateOrder` stores on the `order_payment` record (`$order->addOrderPayment(..., $transaction_id)` is called internally by `validateOrder` itself).
- **Reference check**: the purchase `reference` must equal the `id_cart` being validated; a mismatch is rejected and logged.
- **Success**: redirect to `order-confirmation?id_cart=…&id_module=…&key=<secure_key>`.
- **Failed/cancel**: redirect back to the order page with a session error message (`chip_payment_error`).
- All events are logged with `PrestaShopLogger::addLog('CHIP: …')`.

## Admin Refund

On the order page (Back Office → Orders), a **Refund via CHIP** button is shown for CHIP orders via `displayAdminOrderSide` / `displayAdminOrderMain` (1.7.7+ / 8.x / 9.x) and `displayAdminOrderContentOrder` (1.7.0–1.7.6).

The button POSTs to `controllers/admin/ChipRefundController.php` (`ajax=1&action=refund`) which:

1. Loads the order and verifies it belongs to this module.
2. Verifies the purchase id passed in matches the one recorded on the order.
3. Calls `POST /purchases/{id}/refund/` with `{ "amount": <full paid amount in sen> }`.
4. Returns a JSON result shown inline on the order page.

The **Test API** button on the module configuration page uses the same controller (`action=testapi`) and calls `GET /payment_methods/?brand_id=…&amount=1000`.

## Configuration

All settings are stored with the `CHIP_` prefix:

| Setting | Key | Description |
|---------|-----|-------------|
| Secret Key | `CHIP_SECRET_KEY` | CHIP brand secret key (Bearer token) |
| Brand ID | `CHIP_BRAND_ID` | CHIP brand id |
| Payment Method Whitelist | `CHIP_PAYMENT_METHOD_WHITELIST` | JSON array of method identifiers; empty = all |
| Due Strict | `CHIP_DUE_STRICT` | 0/1 |
| Purchase Timezone | `CHIP_PURCHASE_TIME_ZONE` | default `Asia/Kuala_Lumpur` |
| Public Key (cache) | `CHIP_PUBLIC_KEY` | cached webhook verification key |

## Compatibility Matrix

| PrestaShop | Payment Option | displayPaymentReturn | Admin Refund Hooks | validateOrder | Notes |
|------------|----------------|----------------------|--------------------|---------------|-------|
| 1.7.0 – 1.7.6 | `paymentOptions` | `params['order']` | `displayAdminOrderContentOrder` | 9 args OK | |
| 1.7.7 – 1.7.8.x | `paymentOptions` | `params['order']` | `displayAdminOrderSide` / `displayAdminOrderMain` | 9 args OK | |
| 8.x | `paymentOptions` | `params['order']` | `displayAdminOrderSide` / `displayAdminOrderMain` | 9 args OK | |
| 9.0 – 9.1.4 | `paymentOptions` | `params['order']` | `displayAdminOrderSide` / `displayAdminOrderMain` | 9 args OK (10th/11th optional) | |

Verified against the 1.7.8.11 and 9.1.4 code bases:

- `PaymentOption` (`src/Core/Payment/PaymentOption.php`) has identical setters (`setCallToActionText`, `setModuleName`, `setLogo`, `setAction`, `setAdditionalInformation`) in both.
- `validateOrder` in 9.1.4 appends `?Shop $shop = null, ?string $order_reference = null` — both optional, so the 9-arg 1.7 call is compatible.
- `Order::getByCartId` exists in both; `Order::getIdByCartId` (used by this module) exists in both.
- `displayPaymentReturn` receives `['order' => Order]` in both 1.7.8.11 and 9.1.4.
- `Tools::redirect` exists in both (`Tools::redirectLink` was removed in 9.x — not used).
- Legacy admin AJAX (`ajax=1&action=refund` → `ajaxProcessRefund`) is dispatched by the legacy AdminController flow in 1.7 and by `LegacyController` in 9.x.
- No PHP 8-only syntax (`?->`, `match`, constructor promotion, typed properties) — PHP 7.2+ safe.

## Limitations

- The module implements one-off payments only (no recurring/subscription support).
- No partial refunds from the admin — the button refunds the full paid amount. Partial refunds can be done in the CHIP dashboard.
- Guest checkout: the customer must be logged in when the payment is initiated; cart ownership is enforced against the session customer.
- Refund state is not synced back from CHIP to the PrestaShop order status automatically; the refund appears in the CHIP dashboard.
- `displayPaymentReturn` shows the payment summary; no additional order-state changes are made by the hook.

## Development

```bash
# Lint all PHP files (from the repo root)
find chip -name '*.php' -exec php -l {} \;
```

Do not commit secrets — use placeholders for Secret Key / Brand ID in any config example.

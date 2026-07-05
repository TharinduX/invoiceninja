# PayHere Payment Gateway — Design

Date: 2026-07-05
Status: Approved
Scope: Add PayHere (Sri Lankan hosted-redirect gateway) to the Invoice Ninja Laravel
backend, supporting **one-time invoice payments only**.

## Background

PayHere is a Sri Lankan payment gateway using a **hosted-redirect** model, structurally
almost identical to the existing PayFast driver in this codebase. The integration flow:

1. Invoice Ninja renders an auto-submitting HTML form that POSTs order details plus an
   MD5 `hash` to PayHere's checkout page.
2. The customer completes payment on PayHere's hosted page.
3. PayHere confirms the payment via a **server-to-server IPN callback** to our `notify_url`.
   This callback is the authoritative source of truth. The browser `return_url` carries no
   verified payment status, so it is used only to bring the customer back to their invoice.

Supported currencies: LKR, USD, GBP, EUR, AUD.

## Driver resolution (existing generic machinery — no change needed)

`CompanyGateway::driver()` resolves the driver class by convention:
`App\PaymentDrivers\{provider}PaymentDriver`. With `provider = 'PayHere'`, this resolves to
`App\PaymentDrivers\PayHerePaymentDriver`. The IPN webhook route
(`payment_notification_webhook/{company_key}/{company_gateway_id}/{client}`) and the admin
config UI (renders the gateway `fields` JSON dynamically) already work generically.

## Approach: webhook-authoritative (mirrors PayFast)

The `notify_url` IPN callback creates the payment via a queued `PaymentCompletedWebhook`
job, after md5sig verification, idempotent on PayHere's `payment_id`. The `return_url`
redirects the client back to their invoice/payment page (payment shows as completed once the
IPN has been processed).

Rejected alternatives:
- **Return-url-authoritative:** not viable — PayHere sends no verified payment status to
  `return_url`.
- **Hybrid (return + Retrieval API status query):** requires additional App ID/Secret config
  and more complexity; out of scope for the one-time MVP.

## Components / files

1. **`database/migrations/2026_07_05_000000_add_payhere_gateway.php`**
   Insert `Gateway` row (guarded by `Gateway::find(68)`):
   - `id = 68`, `name = 'PayHere'`, `provider = 'PayHere'`
   - unique 32-char hex `key` (deterministic, e.g. `md5('payhere_invoiceninja_gateway')`)
   - `is_offsite = true`, `visible = true`, `sort_order = 30`
   - `site_url = 'https://www.payhere.lk'`
   - `default_gateway_type_id = 1` (GatewayType::CREDIT_CARD)
   - `fields = {"merchantId":"","merchantSecret":"","testMode":false}`
   - `down()` is a no-op, consistent with the payware/lawpay migrations.

2. **`database/seeders/PaymentLibrariesSeeder.php`**
   Add the same row (`id => 68`, `sort_order => 30`, `provider => 'PayHere'`, matching
   `key` and `fields`) so fresh installs seed the gateway.

3. **`app/Models/SystemLog.php`**
   - Add `public const TYPE_PAYHERE = 330;` (next value after `TYPE_PAYWARE = 329`).
   - Add `case self::TYPE_PAYHERE: return 'PayHere';` in `getEventType()`.

4. **`app/PaymentDrivers/PayHerePaymentDriver.php`** (extends `BaseDriver`)
   - `$refundable = false`, `$token_billing = false`, `$can_authorise_credit_card = false`.
   - `$methods = [GatewayType::CREDIT_CARD => CreditCard::class]`.
   - `const SYSTEM_LOG_TYPE = SystemLog::TYPE_PAYHERE`.
   - `gatewayTypes()`: return `[GatewayType::CREDIT_CARD]` only when the client currency is one
     of LKR/USD/GBP/EUR/AUD; otherwise empty.
   - `endpointUrl()`: `https://sandbox.payhere.lk/pay/checkout` when `testMode`, else
     `https://www.payhere.lk/pay/checkout`.
   - `init()`, `setPaymentMethod()`, `processPaymentView()`, `processPaymentResponse()`
     following the PayFast delegation pattern.
   - `generateStartHash($order_id, $amount, $currency)`:
     `strtoupper(md5($merchant_id . $order_id . number_format($amount,2,'.','') . $currency . strtoupper(md5($merchant_secret))))`.
   - `processWebhookRequest()`: verify `md5sig`, then dispatch `PaymentCompletedWebhook`.
   - `refund()` and `tokenBilling()`: return `false` (out of scope).

5. **`app/PaymentDrivers/PayHere/CreditCard.php`** (implements `LivewireMethodInterface`)
   - `paymentData($data)` / `paymentView($data)`: build checkout fields — `merchant_id`,
     `return_url`, `cancel_url`, `notify_url` (`genericWebhookUrl()`), `order_id`
     (the payment hash), `items`, `currency`, `amount` (2dp), `first_name`, `last_name`,
     `email`, `phone`, `address`, `city`, `country`, and `hash` — then render the pay view.
     `order_id` uses the payment hash so the IPN can be correlated back to the `PaymentHash`.
   - `paymentResponse(Request $request)`: redirect the client to
     `client.payments` / invoice show (payment completion happens via the IPN job).
   - `livewirePaymentView()`: return the livewire blade name.

6. **`app/PaymentDrivers/PayHere/PaymentCompletedWebhook.php`** (queued job)
   - Constructor: `array $data`, `string $company_key`, `int $company_gateway_id`.
   - `handle()`: set DB by company key; idempotency guard on
     `transaction_reference == payment_id`; look up `PaymentHash` by `order_id`; verify amount
     matches; re-verify `md5sig`; on `status_code == 2` create a completed payment
     (`PaymentType::CREDIT_CARD_OTHER`, `GatewayType::CREDIT_CARD`,
     `transaction_reference = payment_id`, `idempotency_key = payment_id . hash`); otherwise
     log failure and send the failure mail.

7. **`resources/views/portal/ninja2020/gateways/payhere/pay.blade.php`** and
   **`pay_livewire.blade.php`**
   Auto-submitting form POSTing all checkout fields + `hash` to `$payment_endpoint_url`,
   following the PayFast blade structure.

## Hash formulas (PayHere spec)

- **Start (checkout):**
  `strtoupper(md5(merchant_id + order_id + amount_2dp + currency + strtoupper(md5(merchant_secret))))`
- **Notify (IPN verify):**
  `strtoupper(md5(merchant_id + order_id + payhere_amount + payhere_currency + status_code + strtoupper(md5(merchant_secret))))`

`status_code`: `2` = success, `0` = pending, `-1` = canceled, `-2` = failed, `-3` = chargedback.

## Config fields

- `merchantId` — PayHere Merchant ID.
- `merchantSecret` — PayHere Merchant Secret (used for both hashes; never sent in the
  checkout form, only the derived hash is).
- `testMode` — boolean toggle for sandbox vs live endpoint.

## Out of scope

- Token billing / saved cards / recurring (PayHere Preapproval + Charging API).
- Refunds (PayHere Retrieval/Refund API).

## Testing notes

- Unit-verifiable without external calls: start-hash generation and checkout request field
  building; IPN md5sig verification.
- End-to-end sandbox testing requires the IPN `notify_url` to be publicly reachable, so a
  tunnel (e.g. ngrok) pointing at the local instance is needed for a real redirect + callback
  flow. Sandbox credentials are available.

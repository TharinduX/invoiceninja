# PayHere Payment Gateway Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the PayHere (Sri Lankan hosted-redirect) payment gateway to the Invoice Ninja Laravel backend for one-time invoice payments.

**Architecture:** PayHere is a hosted-redirect gateway structurally identical to the existing PayFast driver. Invoice Ninja renders an auto-submitting form that POSTs order details + an MD5 `hash` to PayHere's checkout page; PayHere confirms payment via a server-to-server IPN callback to our `notify_url`, which (after md5sig verification) dispatches a queued job that creates the payment. Driver resolution is by convention (`App\PaymentDrivers\PayHerePaymentDriver` from `provider = 'PayHere'`), and the IPN route + admin config UI are already generic.

**Tech Stack:** PHP 8 / Laravel, PHPUnit, Blade. Design spec: `docs/superpowers/specs/2026-07-05-payhere-gateway-design.md`.

## Global Constraints

- Scope is **one-time payments only** — no token billing, recurring, or refunds. `refund()` and `tokenBilling()` return `false`; `$refundable = false`, `$token_billing = false`, `$can_authorise_credit_card = false`.
- Gateway identity is fixed: `id = 68`, `provider = 'PayHere'`, `key = '8e75ceb21ac6153ef90da3ff7f5cffce'`, `sort_order = 30`, `default_gateway_type_id = GatewayType::CREDIT_CARD` (1), `is_offsite = true`, `site_url = 'https://www.payhere.lk'`.
- Config fields: `merchantId`, `merchantSecret`, `testMode` (boolean). `merchantSecret` is never sent to PayHere — only derived MD5 hashes are.
- Supported currencies: `LKR`, `USD`, `GBP`, `EUR`, `AUD`.
- SystemLog type: `TYPE_PAYHERE = 330`.
- Start hash: `strtoupper(md5(merchant_id . order_id . amount_2dp . currency . strtoupper(md5(merchant_secret))))`, where `amount_2dp = number_format($amount, 2, '.', '')`.
- IPN verify hash: `strtoupper(md5(merchant_id . order_id . payhere_amount . payhere_currency . status_code . strtoupper(md5(merchant_secret))))`.
- IPN `status_code`: `2` = success, `0` = pending, `-1` = canceled, `-2` = failed, `-3` = chargedback. Only `2` creates a payment.
- `order_id` sent to PayHere is the Invoice Ninja PaymentHash `hash`, so the IPN correlates back to the pending payment.
- File license header: every new PHP class file starts with the standard Invoice Ninja header block (copy verbatim from `app/PaymentDrivers/PayFastPaymentDriver.php` lines 1-11).
- Run tests with: `./vendor/bin/phpunit --filter <TestClass>` (or the project's configured test runner).

---

### Task 1: Register the PayHere gateway (migration + seeder + SystemLog)

**Files:**
- Create: `database/migrations/2026_07_05_000000_add_payhere_gateway.php`
- Modify: `database/seeders/PaymentLibrariesSeeder.php` (add gateway row + add `68` to the visible whitelist)
- Modify: `app/Models/SystemLog.php` (add `TYPE_PAYHERE` constant + `getEventType()` case)
- Test: `tests/Feature/PaymentDrivers/PayHere/PayHereGatewayRegistrationTest.php`

**Interfaces:**
- Produces: `Gateway` row id `68` with `provider = 'PayHere'`; `SystemLog::TYPE_PAYHERE = 330`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PaymentDrivers/PayHere/PayHereGatewayRegistrationTest.php`:

```php
<?php

namespace Tests\Feature\PaymentDrivers\PayHere;

use App\Models\Gateway;
use App\Models\GatewayType;
use App\Models\SystemLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PayHereGatewayRegistrationTest extends TestCase
{
    use DatabaseTransactions;

    public function testPayHereGatewayIsRegistered(): void
    {
        $gateway = Gateway::find(68);

        $this->assertNotNull($gateway);
        $this->assertEquals('PayHere', $gateway->provider);
        $this->assertEquals('8e75ceb21ac6153ef90da3ff7f5cffce', $gateway->key);
        $this->assertTrue((bool) $gateway->is_offsite);
        $this->assertEquals(GatewayType::CREDIT_CARD, $gateway->default_gateway_type_id);

        $fields = json_decode($gateway->fields, true);
        $this->assertArrayHasKey('merchantId', $fields);
        $this->assertArrayHasKey('merchantSecret', $fields);
        $this->assertArrayHasKey('testMode', $fields);
    }

    public function testSystemLogTypeExists(): void
    {
        $this->assertEquals(330, SystemLog::TYPE_PAYHERE);
        $this->assertEquals('PayHere', (new SystemLog())->getEventType(SystemLog::TYPE_PAYHERE));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter PayHereGatewayRegistrationTest`
Expected: FAIL — `Gateway::find(68)` returns null and `SystemLog::TYPE_PAYHERE` is undefined.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_07_05_000000_add_payhere_gateway.php`:

```php
<?php

use App\Models\Gateway;
use App\Models\GatewayType;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {

    public function up(): void
    {
        \Illuminate\Database\Eloquent\Model::unguard();

        if (! Gateway::find(68)) {
            $fields = new \stdClass();
            $fields->merchantId = '';
            $fields->merchantSecret = '';
            $fields->testMode = false;

            $gateway = new Gateway();
            $gateway->id = 68;
            $gateway->name = 'PayHere';
            $gateway->key = '8e75ceb21ac6153ef90da3ff7f5cffce';
            $gateway->provider = 'PayHere';
            $gateway->is_offsite = true;
            $gateway->fields = \json_encode($fields);
            $gateway->visible = true;
            $gateway->sort_order = 30;
            $gateway->site_url = 'https://www.payhere.lk';
            $gateway->default_gateway_type_id = GatewayType::CREDIT_CARD;
            $gateway->save();
        }

        \Illuminate\Database\Eloquent\Model::reguard();
    }

    public function down(): void
    {
        //
    }
};
```

- [ ] **Step 4: Add the SystemLog constant + case**

In `app/Models/SystemLog.php`, add the constant next to `TYPE_PAYWARE = 329`:

```php
    public const TYPE_PAYHERE = 330;
```

In the `getEventType()` `switch`, add a case (immediately after the `TYPE_FORTE` case near the end):

```php
            case self::TYPE_PAYHERE:
                return 'PayHere';
```

- [ ] **Step 5: Add the seeder row + visible whitelist entry**

In `database/seeders/PaymentLibrariesSeeder.php`, add this line to the `$gateways` array immediately after the `id => 67` (payware) entry:

```php
            ['id' => 68, 'name' => 'PayHere', 'is_offsite' => true, 'sort_order' => 30, 'provider' => 'PayHere', 'key' => '8e75ceb21ac6153ef90da3ff7f5cffce', 'fields' => '{"merchantId":"","merchantSecret":"","testMode":false}', 'default_gateway_type_id' => GatewayType::CREDIT_CARD],
```

In the same file, add `68` to the visible whitelist so seeding leaves PayHere visible. Change:

```php
        Gateway::whereIn('id', [1, 3, 7, 11, 15, 20, 39, 46, 55, 50, 57, 52, 58, 59, 60, 62, 63, 67])->update(['visible' => 1]);
```

to:

```php
        Gateway::whereIn('id', [1, 3, 7, 11, 15, 20, 39, 46, 55, 50, 57, 52, 58, 59, 60, 62, 63, 67, 68])->update(['visible' => 1]);
```

- [ ] **Step 6: Run the migration and test**

Run: `php artisan migrate` then `./vendor/bin/phpunit --filter PayHereGatewayRegistrationTest`
Expected: PASS (both test methods).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_05_000000_add_payhere_gateway.php database/seeders/PaymentLibrariesSeeder.php app/Models/SystemLog.php tests/Feature/PaymentDrivers/PayHere/PayHereGatewayRegistrationTest.php
git commit -m "feat(payhere): register PayHere gateway (migration, seeder, system log type)"
```

---

### Task 2: PayHere driver core (endpoint, currency gating, hashes)

**Files:**
- Create: `app/PaymentDrivers/PayHerePaymentDriver.php`
- Test: `tests/Feature/PaymentDrivers/PayHere/PayHereDriverTest.php`

**Interfaces:**
- Consumes: `Gateway` id 68 (Task 1), `GatewayType::CREDIT_CARD`, `SystemLog::TYPE_PAYHERE`.
- Produces:
  - `PayHerePaymentDriver extends BaseDriver`
  - `public static $methods = [GatewayType::CREDIT_CARD => CreditCard::class]` (CreditCard added in Task 3)
  - `endpointUrl(): string`
  - `gatewayTypes(): array`
  - `generateStartHash(string $order_id, float $amount, string $currency): string`
  - `verifyIpnSignature(array $data): bool`
  - `init(): self`, `setPaymentMethod($id): self`, `processPaymentView(array $data)`, `processPaymentResponse($request)`
  - `refund(...): false`, `tokenBilling(...): false`

Note: this task references `CreditCard::class` (created in Task 3). Add the `use App\PaymentDrivers\PayHere\CreditCard;` import now; PHP only resolves the class at call time, and Task 2's tests do not instantiate it, so the file loads fine.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PaymentDrivers/PayHere/PayHereDriverTest.php`:

```php
<?php

namespace Tests\Feature\PaymentDrivers\PayHere;

use App\Models\Client;
use App\Models\CompanyGateway;
use App\Models\Gateway;
use App\Models\GatewayType;
use App\PaymentDrivers\PayHerePaymentDriver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\MockAccountData;
use Tests\TestCase;

class PayHereDriverTest extends TestCase
{
    use MockAccountData;
    use DatabaseTransactions;

    private const GATEWAY_KEY = '8e75ceb21ac6153ef90da3ff7f5cffce';

    private CompanyGateway $company_gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeTestData();

        $this->company_gateway = new CompanyGateway();
        $this->company_gateway->company_id = $this->company->id;
        $this->company_gateway->user_id = $this->user->id;
        $this->company_gateway->gateway_key = self::GATEWAY_KEY;
        $this->company_gateway->config = encrypt(json_encode([
            'merchantId' => '1211149',
            'merchantSecret' => 'test-secret',
            'testMode' => true,
        ]));
        $this->company_gateway->fees_and_limits = '';
        $this->company_gateway->save();
    }

    private function driver(): PayHerePaymentDriver
    {
        return $this->company_gateway->driver($this->client)->init();
    }

    public function testEndpointUrlTestMode(): void
    {
        $this->assertEquals('https://sandbox.payhere.lk/pay/checkout', $this->driver()->endpointUrl());
    }

    public function testStartHashMatchesPayHereFormula(): void
    {
        $driver = $this->driver();

        $order_id = 'ORDER123';
        $amount = 1000.0;
        $currency = 'LKR';

        $expected = strtoupper(md5(
            '1211149' . $order_id . number_format($amount, 2, '.', '') . $currency . strtoupper(md5('test-secret'))
        ));

        $this->assertEquals($expected, $driver->generateStartHash($order_id, $amount, $currency));
    }

    public function testVerifyIpnSignatureAcceptsValidAndRejectsTampered(): void
    {
        $driver = $this->driver();

        $data = [
            'merchant_id' => '1211149',
            'order_id' => 'ORDER123',
            'payhere_amount' => '1000.00',
            'payhere_currency' => 'LKR',
            'status_code' => '2',
        ];

        $data['md5sig'] = strtoupper(md5(
            $data['merchant_id'] . $data['order_id'] . $data['payhere_amount'] .
            $data['payhere_currency'] . $data['status_code'] . strtoupper(md5('test-secret'))
        ));

        $this->assertTrue($driver->verifyIpnSignature($data));

        $data['md5sig'] = strtoupper(md5('tampered'));
        $this->assertFalse($driver->verifyIpnSignature($data));
    }

    public function testGatewayTypesGatedByCurrency(): void
    {
        $this->client->settings->currency_id = '110'; // LKR
        $this->client->save();
        $this->assertEquals([GatewayType::CREDIT_CARD], $this->driver()->gatewayTypes());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter PayHereDriverTest`
Expected: FAIL — `App\PaymentDrivers\PayHerePaymentDriver` class not found.

- [ ] **Step 3: Create the driver**

Create `app/PaymentDrivers/PayHerePaymentDriver.php`:

```php
<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\PaymentDrivers;

use App\Models\Payment;
use App\Models\SystemLog;
use App\Models\GatewayType;
use App\Models\PaymentHash;
use App\Models\ClientGatewayToken;
use App\PaymentDrivers\PayHere\CreditCard;
use App\PaymentDrivers\PayHere\PaymentCompletedWebhook;
use App\Http\Requests\Payments\PaymentNotificationWebhookRequest;

class PayHerePaymentDriver extends BaseDriver
{
    public $refundable = false;

    public $token_billing = false;

    public $can_authorise_credit_card = false;

    public $payment_method;

    public static $methods = [
        GatewayType::CREDIT_CARD => CreditCard::class,
    ];

    public const SYSTEM_LOG_TYPE = SystemLog::TYPE_PAYHERE;

    // PayHere supports these currencies on the checkout page.
    private const SUPPORTED_CURRENCIES = ['LKR', 'USD', 'GBP', 'EUR', 'AUD'];

    public function init(): self
    {
        return $this;
    }

    public function gatewayTypes(): array
    {
        $types = [];

        if (in_array($this->client->currency()->code, self::SUPPORTED_CURRENCIES, true)) {
            $types[] = GatewayType::CREDIT_CARD;
        }

        return $types;
    }

    public function endpointUrl(): string
    {
        if ($this->company_gateway->getConfigField('testMode')) {
            return 'https://sandbox.payhere.lk/pay/checkout';
        }

        return 'https://www.payhere.lk/pay/checkout';
    }

    public function setPaymentMethod($payment_method_id): self
    {
        $class = self::$methods[$payment_method_id];
        $this->payment_method = new $class($this);

        return $this;
    }

    public function processPaymentView(array $data)
    {
        return $this->payment_method->paymentView($data);
    }

    public function processPaymentResponse($request)
    {
        return $this->payment_method->paymentResponse($request);
    }

    /**
     * PayHere checkout "start" hash.
     * @see https://support.payhere.lk/api-&-mobile-sdk/checkout-api
     */
    public function generateStartHash(string $order_id, float $amount, string $currency): string
    {
        return strtoupper(md5(
            $this->company_gateway->getConfigField('merchantId') .
            $order_id .
            number_format($amount, 2, '.', '') .
            $currency .
            strtoupper(md5($this->company_gateway->getConfigField('merchantSecret')))
        ));
    }

    /**
     * Verify the md5sig on a PayHere IPN notification.
     */
    public function verifyIpnSignature(array $data): bool
    {
        $local = strtoupper(md5(
            ($data['merchant_id'] ?? '') .
            ($data['order_id'] ?? '') .
            ($data['payhere_amount'] ?? '') .
            ($data['payhere_currency'] ?? '') .
            ($data['status_code'] ?? '') .
            strtoupper(md5($this->company_gateway->getConfigField('merchantSecret')))
        ));

        return hash_equals($local, $data['md5sig'] ?? '');
    }

    public function processWebhookRequest(PaymentNotificationWebhookRequest $request, ?Payment $payment = null)
    {
        $data = $request->all();

        if (! $this->verifyIpnSignature($data)) {
            return response()->json(['error' => 'Invalid Webhook Signature'], 400);
        }

        PaymentCompletedWebhook::dispatch($data, $request->company_key, $this->company_gateway->id);

        return response()->json([], 200);
    }

    public function refund(Payment $payment, $amount, $return_client_response = false)
    {
        return false;
    }

    public function tokenBilling(ClientGatewayToken $cgt, PaymentHash $payment_hash)
    {
        return false;
    }
}
```

- [ ] **Step 4: Run the test**

Run: `./vendor/bin/phpunit --filter PayHereDriverTest`
Expected: `testEndpointUrlTestMode`, `testStartHashMatchesPayHereFormula`, `testVerifyIpnSignatureAcceptsValidAndRejectsTampered`, `testGatewayTypesGatedByCurrency` all PASS.

Note: if `testGatewayTypesGatedByCurrency` fails on the currency id, adjust the `currency_id` in the test to the LKR id in your seeded currencies (`app/Models/Currency.php` / currencies seeder). The driver logic keys off `->currency()->code === 'LKR'`, which is the invariant under test.

- [ ] **Step 5: Commit**

```bash
git add app/PaymentDrivers/PayHerePaymentDriver.php tests/Feature/PaymentDrivers/PayHere/PayHereDriverTest.php
git commit -m "feat(payhere): add PayHere driver core (endpoint, currency gating, hashes)"
```

---

### Task 3: CreditCard payment method + Blade checkout views

**Files:**
- Create: `app/PaymentDrivers/PayHere/CreditCard.php`
- Create: `resources/views/portal/ninja2020/gateways/payhere/pay.blade.php`
- Create: `resources/views/portal/ninja2020/gateways/payhere/pay_livewire.blade.php`
- Test: `tests/Feature/PaymentDrivers/PayHere/PayHereCheckoutDataTest.php`

**Interfaces:**
- Consumes: `PayHerePaymentDriver` (Task 2), `LivewireMethodInterface`.
- Produces:
  - `CreditCard implements LivewireMethodInterface`
  - `paymentData(array $data): array` — returns the checkout fields incl. `hash`, `payment_endpoint_url`, `gateway`
  - `paymentView(array $data)` — renders `gateways.payhere.pay`
  - `paymentResponse(\Illuminate\Http\Request $request)` — redirects to the client invoice
  - `livewirePaymentView(array $data): string` — returns `'gateways.payhere.pay_livewire'`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PaymentDrivers/PayHere/PayHereCheckoutDataTest.php`:

```php
<?php

namespace Tests\Feature\PaymentDrivers\PayHere;

use App\Models\CompanyGateway;
use App\Models\Invoice;
use App\Models\PaymentHash;
use App\PaymentDrivers\PayHere\CreditCard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\MockAccountData;
use Tests\TestCase;

class PayHereCheckoutDataTest extends TestCase
{
    use MockAccountData;
    use DatabaseTransactions;

    private const GATEWAY_KEY = '8e75ceb21ac6153ef90da3ff7f5cffce';

    private CompanyGateway $company_gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeTestData();

        $this->company_gateway = new CompanyGateway();
        $this->company_gateway->company_id = $this->company->id;
        $this->company_gateway->user_id = $this->user->id;
        $this->company_gateway->gateway_key = self::GATEWAY_KEY;
        $this->company_gateway->config = encrypt(json_encode([
            'merchantId' => '1211149',
            'merchantSecret' => 'test-secret',
            'testMode' => true,
        ]));
        $this->company_gateway->fees_and_limits = '';
        $this->company_gateway->save();
    }

    public function testPaymentDataBuildsCheckoutFieldsWithHash(): void
    {
        $invoice = Invoice::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'number' => 'INV-PH-1',
        ]);

        $hash = str_repeat('a', 32);

        $payment_hash = new PaymentHash();
        $payment_hash->hash = $hash;
        $payment_hash->fee_invoice_id = $invoice->id;
        $payment_hash->fee_total = 0;
        $payment_hash->data = [
            'amount_with_fee' => 1000.0,
            'invoices' => [['invoice_id' => $invoice->hashed_id, 'invoice_number' => 'INV-PH-1', 'amount' => 1000.0]],
        ];
        $payment_hash->save();

        $driver = $this->company_gateway->driver($this->client)->init();
        $driver->setPaymentHash($payment_hash);
        $driver->setPaymentMethod(\App\Models\GatewayType::CREDIT_CARD);

        $data = [
            'payment_hash' => $hash,
            'amount_with_fee' => 1000.0,
            'invoices' => [(object) ['invoice_number' => 'INV-PH-1']],
        ];

        $result = (new CreditCard($driver))->paymentData($data);

        $this->assertEquals('1211149', $result['merchant_id']);
        $this->assertEquals($hash, $result['order_id']);
        $this->assertEquals('1000.00', $result['amount']);
        $this->assertEquals('https://sandbox.payhere.lk/pay/checkout', $result['payment_endpoint_url']);

        $expected_hash = strtoupper(md5(
            '1211149' . $hash . '1000.00' . $result['currency'] . strtoupper(md5('test-secret'))
        ));
        $this->assertEquals($expected_hash, $result['hash']);
        $this->assertArrayNotHasKey('merchantSecret', $result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter PayHereCheckoutDataTest`
Expected: FAIL — `App\PaymentDrivers\PayHere\CreditCard` class not found.

- [ ] **Step 3: Create the CreditCard payment method**

Create `app/PaymentDrivers/PayHere/CreditCard.php`:

```php
<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\PaymentDrivers\PayHere;

use Illuminate\Http\Request;
use App\PaymentDrivers\Common\LivewireMethodInterface;
use App\PaymentDrivers\PayHerePaymentDriver;

class CreditCard implements LivewireMethodInterface
{
    public $payhere;

    public function __construct(PayHerePaymentDriver $payhere)
    {
        $this->payhere = $payhere;
    }

    public function paymentView($data)
    {
        $data = $this->paymentData($data);

        return render('gateways.payhere.pay', $data);
    }

    /**
     * PayHere confirms payment via the server-to-server IPN (notify_url), not the
     * browser return. Bring the client back to the invoice; the queued
     * PaymentCompletedWebhook marks it paid.
     */
    public function paymentResponse(Request $request)
    {
        return redirect()->route('client.invoice.show', [
            'invoice' => $this->payhere->payment_hash->fee_invoice->hashed_id,
        ]);
    }

    public function paymentData(array $data): array
    {
        $client = $this->payhere->client;
        $invoice = $this->payhere->payment_hash->fee_invoice;
        $contact = $client->primary_contact()->first() ?? $client->contacts->first();
        $currency = $client->currency()->code;
        $amount = round((float) $data['amount_with_fee'], 2);
        $order_id = $data['payment_hash'];

        $payhere_data = [
            'merchant_id' => $this->payhere->company_gateway->getConfigField('merchantId'),
            'return_url' => route('client.invoice.show', ['invoice' => $invoice->hashed_id]),
            'cancel_url' => route('client.invoice.show', ['invoice' => $invoice->hashed_id]),
            'notify_url' => $this->payhere->genericWebhookUrl(),
            'order_id' => $order_id,
            'items' => ctrans('texts.invoices') . ': ' . collect($data['invoices'])->pluck('invoice_number')->implode(', '),
            'currency' => $currency,
            'amount' => number_format($amount, 2, '.', ''),
            'first_name' => $contact->first_name ?? $client->name,
            'last_name' => $contact->last_name ?? '',
            'email' => $contact->email ?? '',
            'phone' => $client->phone ?? '',
            'address' => $client->address1 ?? '',
            'city' => $client->city ?? '',
            'country' => $client->country ? $client->country->name : '',
            'hash' => $this->payhere->generateStartHash($order_id, $amount, $currency),
        ];

        $payhere_data['gateway'] = $this->payhere;
        $payhere_data['payment_endpoint_url'] = $this->payhere->endpointUrl();

        return array_merge($data, $payhere_data);
    }

    /**
     * @inheritDoc
     */
    public function livewirePaymentView(array $data): string
    {
        return 'gateways.payhere.pay_livewire';
    }
}
```

- [ ] **Step 4: Create the pay.blade.php view**

Create `resources/views/portal/ninja2020/gateways/payhere/pay.blade.php`:

```blade
@extends('portal.ninja2020.layout.payments', ['gateway_title' => ctrans('texts.credit_card'), 'card_title' => ctrans('texts.credit_card')])

@section('gateway_head')
    <meta name="contact-email" content="{{ $contact->email }}">
    <meta name="client-postal-code" content="{{ $contact->client->postal_code }}">
    <meta name="instant-payment" content="yes" />
@endsection

@section('gateway_content')
    <form action="{{ $payment_endpoint_url }}" method="post" id="server_response">
        @csrf
        <input type="hidden" name="merchant_id" value="{{ $merchant_id }}">
        <input type="hidden" name="return_url" value="{{ $return_url }}">
        <input type="hidden" name="cancel_url" value="{{ $cancel_url }}">
        <input type="hidden" name="notify_url" value="{{ $notify_url }}">
        <input type="hidden" name="order_id" value="{{ $order_id }}">
        <input type="hidden" name="items" value="{{ $items }}">
        <input type="hidden" name="currency" value="{{ $currency }}">
        <input type="hidden" name="amount" value="{{ $amount }}">
        <input type="hidden" name="first_name" value="{{ $first_name }}">
        <input type="hidden" name="last_name" value="{{ $last_name }}">
        <input type="hidden" name="email" value="{{ $email }}">
        <input type="hidden" name="phone" value="{{ $phone }}">
        <input type="hidden" name="address" value="{{ $address }}">
        <input type="hidden" name="city" value="{{ $city }}">
        <input type="hidden" name="country" value="{{ $country }}">
        <input type="hidden" name="hash" value="{{ $hash }}">

        @component('portal.ninja2020.components.general.card-element', ['title' => ctrans('texts.method')])
            {{ ctrans('texts.credit_card') }}
        @endcomponent

        @include('portal.ninja2020.gateways.includes.payment_details')

        @include('portal.ninja2020.gateways.includes.pay_now')
    </form>
@endsection
```

- [ ] **Step 5: Create the pay_livewire.blade.php view**

Create `resources/views/portal/ninja2020/gateways/payhere/pay_livewire.blade.php`:

```blade
<div class="rounded-lg border bg-card text-card-foreground shadow-sm overflow-hidden py-5 bg-white sm:gap-4"
    id="payhere-payment">
    <meta name="contact-email" content="{{ $contact->email }}">
    <meta name="client-postal-code" content="{{ $contact->client->postal_code }}">

    <form action="{{ $payment_endpoint_url }}" method="post" id="server_response">
        @csrf
        <input type="hidden" name="merchant_id" value="{{ $merchant_id }}">
        <input type="hidden" name="return_url" value="{{ $return_url }}">
        <input type="hidden" name="cancel_url" value="{{ $cancel_url }}">
        <input type="hidden" name="notify_url" value="{{ $notify_url }}">
        <input type="hidden" name="order_id" value="{{ $order_id }}">
        <input type="hidden" name="items" value="{{ $items }}">
        <input type="hidden" name="currency" value="{{ $currency }}">
        <input type="hidden" name="amount" value="{{ $amount }}">
        <input type="hidden" name="first_name" value="{{ $first_name }}">
        <input type="hidden" name="last_name" value="{{ $last_name }}">
        <input type="hidden" name="email" value="{{ $email }}">
        <input type="hidden" name="phone" value="{{ $phone }}">
        <input type="hidden" name="address" value="{{ $address }}">
        <input type="hidden" name="city" value="{{ $city }}">
        <input type="hidden" name="country" value="{{ $country }}">
        <input type="hidden" name="hash" value="{{ $hash }}">

        @component('portal.ninja2020.components.general.card-element', ['title' => ctrans('texts.method')])
            {{ ctrans('texts.credit_card') }}
        @endcomponent

        @include('portal.ninja2020.gateways.includes.payment_details')

        @include('portal.ninja2020.gateways.includes.pay_now')
    </form>
</div>

@script
<script defer>
    const payNowButton = document.getElementById('pay-now');
    if (payNowButton) {
        payNowButton.addEventListener('click', function (e) {
            e.preventDefault();
            payNowButton.disabled = true;
            payNowButton.querySelector('#pay-now svg')?.classList.remove('hidden');
            payNowButton.querySelector('#pay-now span')?.classList.add('hidden');
            document.getElementById('server_response').submit();
        });
    }
</script>
@endscript
```

- [ ] **Step 6: Run the test**

Run: `./vendor/bin/phpunit --filter PayHereCheckoutDataTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/PaymentDrivers/PayHere/CreditCard.php resources/views/portal/ninja2020/gateways/payhere/ tests/Feature/PaymentDrivers/PayHere/PayHereCheckoutDataTest.php
git commit -m "feat(payhere): add checkout payment method and blade views"
```

---

### Task 4: PaymentCompletedWebhook job (IPN → payment creation)

**Files:**
- Create: `app/PaymentDrivers/PayHere/PaymentCompletedWebhook.php`
- Test: `tests/Feature/PaymentDrivers/PayHere/PayHereWebhookJobTest.php`

**Interfaces:**
- Consumes: `PayHerePaymentDriver` (Task 2), `CompanyGateway::driver()`, `BaseDriver::createPayment()`, `BaseDriver::logSuccessfulGatewayResponse()`, `BaseDriver::sendFailureMail()`.
- Produces: `PaymentCompletedWebhook` job with constructor `(array $data, string $company_key, int $company_gateway_id)` and `handle(): void`.

IPN field reference (subset used):
`merchant_id`, `order_id` (= PaymentHash hash), `payment_id`, `payhere_amount`, `payhere_currency`, `status_code`, `md5sig`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PaymentDrivers/PayHere/PayHereWebhookJobTest.php`:

```php
<?php

namespace Tests\Feature\PaymentDrivers\PayHere;

use App\Jobs\Util\SystemLogger;
use App\Models\CompanyGateway;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentHash;
use App\PaymentDrivers\PayHere\PaymentCompletedWebhook;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Tests\MockAccountData;
use Tests\TestCase;

class PayHereWebhookJobTest extends TestCase
{
    use MockAccountData;
    use DatabaseTransactions;

    private const GATEWAY_KEY = '8e75ceb21ac6153ef90da3ff7f5cffce';
    private const SECRET = 'test-secret';

    private CompanyGateway $company_gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeTestData();

        $this->company_gateway = new CompanyGateway();
        $this->company_gateway->company_id = $this->company->id;
        $this->company_gateway->user_id = $this->user->id;
        $this->company_gateway->gateway_key = self::GATEWAY_KEY;
        $this->company_gateway->config = encrypt(json_encode([
            'merchantId' => '1211149',
            'merchantSecret' => self::SECRET,
            'testMode' => true,
        ]));
        $this->company_gateway->fees_and_limits = '';
        $this->company_gateway->save();
    }

    private function makePaymentHash(string $hash, float $amount = 1000.00): PaymentHash
    {
        $invoice = Invoice::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
        ]);

        $payment_hash = new PaymentHash();
        $payment_hash->hash = $hash;
        $payment_hash->fee_invoice_id = $invoice->id;
        $payment_hash->fee_total = 0;
        $payment_hash->data = [
            'amount_with_fee' => $amount,
            'invoices' => [['invoice_id' => $invoice->hashed_id, 'amount' => $amount]],
        ];
        $payment_hash->save();

        return $payment_hash;
    }

    private function ipn(string $order_id, string $payment_id, string $status = '2', string $amount = '1000.00'): array
    {
        $data = [
            'merchant_id' => '1211149',
            'order_id' => $order_id,
            'payment_id' => $payment_id,
            'payhere_amount' => $amount,
            'payhere_currency' => 'LKR',
            'status_code' => $status,
        ];

        $data['md5sig'] = strtoupper(md5(
            $data['merchant_id'] . $data['order_id'] . $data['payhere_amount'] .
            $data['payhere_currency'] . $data['status_code'] . strtoupper(md5(self::SECRET))
        ));

        return $data;
    }

    public function testJobCreatesPaymentAndIsIdempotent(): void
    {
        $hash = str_repeat('a', 32);
        $payment_hash = $this->makePaymentHash($hash);

        $data = $this->ipn($hash, '320001');

        (new PaymentCompletedWebhook($data, $this->company->company_key, $this->company_gateway->id))->handle();

        $payment = Payment::where('transaction_reference', '320001')->first();
        $this->assertNotNull($payment);
        $this->assertEquals(1000.00, (float) $payment->amount);
        $this->assertEquals('320001' . $payment_hash->hash, $payment->idempotency_key);

        (new PaymentCompletedWebhook($data, $this->company->company_key, $this->company_gateway->id))->handle();
        $this->assertEquals(1, Payment::where('transaction_reference', '320001')->count());
    }

    public function testAmountMismatchSkipsPaymentCreation(): void
    {
        $hash = str_repeat('b', 32);
        $this->makePaymentHash($hash, 1000.00);

        $data = $this->ipn($hash, '320002', '2', '500.00');

        (new PaymentCompletedWebhook($data, $this->company->company_key, $this->company_gateway->id))->handle();

        $this->assertNull(Payment::where('transaction_reference', '320002')->first());
    }

    public function testNonSuccessStatusLogsFailureAndCreatesNoPayment(): void
    {
        Bus::fake([SystemLogger::class]);

        $hash = str_repeat('c', 32);
        $this->makePaymentHash($hash);

        $data = $this->ipn($hash, '320003', '-2');

        (new PaymentCompletedWebhook($data, $this->company->company_key, $this->company_gateway->id))->handle();

        $this->assertNull(Payment::where('transaction_reference', '320003')->first());
        Bus::assertDispatched(SystemLogger::class);
    }

    public function testTamperedSignatureCreatesNoPayment(): void
    {
        $hash = str_repeat('d', 32);
        $this->makePaymentHash($hash);

        $data = $this->ipn($hash, '320004');
        $data['md5sig'] = strtoupper(md5('tampered'));

        (new PaymentCompletedWebhook($data, $this->company->company_key, $this->company_gateway->id))->handle();

        $this->assertNull(Payment::where('transaction_reference', '320004')->first());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter PayHereWebhookJobTest`
Expected: FAIL — `App\PaymentDrivers\PayHere\PaymentCompletedWebhook` class not found.

- [ ] **Step 3: Create the job**

Create `app/PaymentDrivers/PayHere/PaymentCompletedWebhook.php`:

```php
<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\PaymentDrivers\PayHere;

use App\Jobs\Util\SystemLogger;
use App\Libraries\MultiDB;
use App\Models\Company;
use App\Models\CompanyGateway;
use App\Models\GatewayType;
use App\Models\Payment;
use App\Models\PaymentHash;
use App\Models\PaymentType;
use App\Models\SystemLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PaymentCompletedWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public array $data, public string $company_key, public int $company_gateway_id) {}

    public function handle(): void
    {
        MultiDB::findAndSetDbByCompanyKey($this->company_key);

        $company = Company::query()->where('company_key', $this->company_key)->first();

        $payment_id = $this->data['payment_id'] ?? null;

        $existing = Payment::query()
            ->where('company_id', $company->id)
            ->where('transaction_reference', $payment_id)
            ->first();

        if ($existing) {
            return;
        }

        $payment_hash = PaymentHash::where('hash', $this->data['order_id'] ?? '')->first();

        if (! $payment_hash) {
            nlog('PayHere:: payment_hash not found for order_id ' . ($this->data['order_id'] ?? ''));
            return;
        }

        $company_gateway = CompanyGateway::query()
            ->where('company_id', $company->id)
            ->where('id', $this->company_gateway_id)
            ->first();

        $driver = $company_gateway->driver($payment_hash->fee_invoice->client)->init();
        $driver->setPaymentHash($payment_hash);

        // Defense in depth: re-verify the signature at job time.
        if (! $driver->verifyIpnSignature($this->data)) {
            nlog('PayHere:: invalid md5sig at job time for order_id ' . $this->data['order_id']);
            return;
        }

        $expected = (float) $payment_hash->data->amount_with_fee;
        $received = (float) ($this->data['payhere_amount'] ?? 0);

        if (abs($received - $expected) > 0.02) {
            nlog('PayHere:: amount mismatch');
            return;
        }

        $status = $this->data['status_code'] ?? null;

        if ((string) $status !== '2') {
            $this->processFailure($driver, $status);
            return;
        }

        $payment_hash->data = array_merge((array) $payment_hash->data, [
            'server_response' => $this->data,
            'payment_hash' => $this->data['order_id'],
        ]);
        $payment_hash->save();

        $payment_record = [
            'amount' => $received,
            'payment_type' => PaymentType::CREDIT_CARD_OTHER,
            'gateway_type_id' => GatewayType::CREDIT_CARD,
            'transaction_reference' => $payment_id,
            'idempotency_key' => $payment_id . $payment_hash->hash,
        ];

        $driver->logSuccessfulGatewayResponse(
            ['response' => $this->data, 'data' => $payment_hash->data],
            SystemLog::TYPE_PAYHERE,
        );

        $driver->createPayment($payment_record, Payment::STATUS_COMPLETED);
    }

    private function processFailure($driver, ?string $status): void
    {
        $reason = 'PayHere payment status_code: ' . ($status ?? 'unknown');

        $driver->sendFailureMail($reason);

        SystemLogger::dispatch(
            ['server_response' => $this->data, 'data' => $driver->payment_hash->data],
            SystemLog::CATEGORY_GATEWAY_RESPONSE,
            SystemLog::EVENT_GATEWAY_FAILURE,
            SystemLog::TYPE_PAYHERE,
            $driver->client,
            $driver->client->company,
        );
    }
}
```

- [ ] **Step 4: Run the test**

Run: `./vendor/bin/phpunit --filter PayHereWebhookJobTest`
Expected: all four methods PASS.

- [ ] **Step 5: Commit**

```bash
git add app/PaymentDrivers/PayHere/PaymentCompletedWebhook.php tests/Feature/PaymentDrivers/PayHere/PayHereWebhookJobTest.php
git commit -m "feat(payhere): add IPN webhook job for payment completion"
```

---

### Task 5: IPN endpoint verification (driver processWebhookRequest via HTTP)

**Files:**
- Test: `tests/Feature/PaymentDrivers/PayHere/PayHereWebhookEndpointTest.php`

(No production code changes — `processWebhookRequest()` was implemented in Task 2. This task adds the HTTP-level regression coverage that the generic `payment_notification_webhook` route dispatches/rejects correctly, mirroring `PayFastWebhookTest`.)

**Interfaces:**
- Consumes: route `payment_notification_webhook`, `PayHerePaymentDriver::processWebhookRequest()` (Task 2), `PaymentCompletedWebhook` (Task 4).

- [ ] **Step 1: Write the test**

Create `tests/Feature/PaymentDrivers/PayHere/PayHereWebhookEndpointTest.php`:

```php
<?php

namespace Tests\Feature\PaymentDrivers\PayHere;

use App\Models\CompanyGateway;
use App\PaymentDrivers\PayHere\PaymentCompletedWebhook;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\MockAccountData;
use Tests\TestCase;

class PayHereWebhookEndpointTest extends TestCase
{
    use MockAccountData;
    use DatabaseTransactions;

    private const GATEWAY_KEY = '8e75ceb21ac6153ef90da3ff7f5cffce';
    private const SECRET = 'test-secret';

    private CompanyGateway $company_gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeTestData();

        $this->company_gateway = new CompanyGateway();
        $this->company_gateway->company_id = $this->company->id;
        $this->company_gateway->user_id = $this->user->id;
        $this->company_gateway->gateway_key = self::GATEWAY_KEY;
        $this->company_gateway->config = encrypt(json_encode([
            'merchantId' => '1211149',
            'merchantSecret' => self::SECRET,
            'testMode' => true,
        ]));
        $this->company_gateway->fees_and_limits = '';
        $this->company_gateway->save();
    }

    private function webhookUrl(): string
    {
        return route('payment_notification_webhook', [
            'company_key' => $this->company->company_key,
            'company_gateway_id' => $this->encodePrimaryKey($this->company_gateway->id),
            'client' => $this->encodePrimaryKey($this->client->id),
        ]);
    }

    private function ipn(bool $valid = true): array
    {
        $data = [
            'merchant_id' => '1211149',
            'order_id' => str_repeat('a', 32),
            'payment_id' => '320100',
            'payhere_amount' => '1000.00',
            'payhere_currency' => 'LKR',
            'status_code' => '2',
        ];

        $data['md5sig'] = $valid
            ? strtoupper(md5(
                $data['merchant_id'] . $data['order_id'] . $data['payhere_amount'] .
                $data['payhere_currency'] . $data['status_code'] . strtoupper(md5(self::SECRET))
            ))
            : strtoupper(md5('tampered'));

        return $data;
    }

    public function testValidSignatureDispatchesJob(): void
    {
        Queue::fake();

        $response = $this->post($this->webhookUrl(), $this->ipn(true));

        $response->assertStatus(200);
        Queue::assertPushed(PaymentCompletedWebhook::class);
    }

    public function testTamperedSignatureIsRejected(): void
    {
        Queue::fake();

        $response = $this->post($this->webhookUrl(), $this->ipn(false));

        $response->assertStatus(400);
        Queue::assertNotPushed(PaymentCompletedWebhook::class);
    }
}
```

- [ ] **Step 2: Run the test**

Run: `./vendor/bin/phpunit --filter PayHereWebhookEndpointTest`
Expected: both methods PASS.

If `testValidSignatureDispatchesJob` returns 302 instead of 200, the `PaymentNotificationWebhookRequest` authorize/validation rejected the payload — inspect `app/Http/Requests/Payments/PaymentNotificationWebhookRequest.php` and confirm it authorizes (it should return `true`), then re-run.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/PaymentDrivers/PayHere/PayHereWebhookEndpointTest.php
git commit -m "test(payhere): cover IPN endpoint signature verification"
```

---

### Task 6: Full-suite regression + static analysis

**Files:** none (verification only).

- [ ] **Step 1: Run the full PayHere test group**

Run: `./vendor/bin/phpunit --filter PayHere`
Expected: all PayHere tests green.

- [ ] **Step 2: Run static analysis on the new files (if the project uses PHPStan)**

Run: `./vendor/bin/phpstan analyse app/PaymentDrivers/PayHerePaymentDriver.php app/PaymentDrivers/PayHere --level=$(grep -m1 level phpstan.neon | grep -o '[0-9]*')`
Expected: no errors. Fix any reported type issues inline (e.g. add missing `?` nullable hints).

- [ ] **Step 3: Commit any analysis fixes**

```bash
git add -A
git commit -m "chore(payhere): satisfy static analysis"
```

---

## Manual end-to-end verification (post-implementation, requires sandbox creds)

Not a coded task — perform once the above is merged/deployed to a tunnel-reachable environment:

1. In the admin UI, add the PayHere gateway and enter sandbox `merchantId` + `merchantSecret`, enable Test Mode.
2. Expose the app via a tunnel (e.g. `ngrok http`) so PayHere's IPN can reach `notify_url`.
3. From the client portal, pay an LKR invoice → confirm redirect to `sandbox.payhere.lk`.
4. Complete the sandbox payment → confirm the IPN marks the invoice paid and a Payment record with the PayHere `payment_id` as `transaction_reference` appears.
5. Confirm a `SystemLog` entry of type PayHere is recorded.

---

## Self-Review

- **Spec coverage:** migration+seeder+SystemLog (Task 1) ✔; driver core/endpoint/currency-gating/start-hash/IPN-verify (Task 2) ✔; CreditCard method + blade views (Task 3) ✔; PaymentCompletedWebhook job (Task 4) ✔; IPN endpoint verification (Task 5) ✔; refund/tokenBilling stubbed to `false` (Task 2) ✔; config fields merchantId/merchantSecret/testMode (Tasks 1-2) ✔; currencies LKR/USD/GBP/EUR/AUD (Task 2) ✔; hashes match spec formulas (Tasks 2 & 4) ✔.
- **Type consistency:** `generateStartHash(string,float,string)`, `verifyIpnSignature(array): bool`, `paymentData(array): array`, and job constructor `(array,string,int)` are used identically across tasks and tests.
- **Placeholders:** none — every step includes runnable code or an exact command.

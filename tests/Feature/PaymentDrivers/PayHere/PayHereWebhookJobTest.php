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

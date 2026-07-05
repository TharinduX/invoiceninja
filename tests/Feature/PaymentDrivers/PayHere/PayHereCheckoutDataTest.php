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

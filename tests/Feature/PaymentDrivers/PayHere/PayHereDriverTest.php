<?php

namespace Tests\Feature\PaymentDrivers\PayHere;

use App\Models\CompanyGateway;
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
        $settings = $this->client->settings;
        $settings->currency_id = '50'; // LKR
        $this->client->settings = $settings;
        $this->client->save();

        $this->assertEquals([GatewayType::CREDIT_CARD], $this->driver()->gatewayTypes());
    }
}

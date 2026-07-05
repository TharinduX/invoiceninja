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

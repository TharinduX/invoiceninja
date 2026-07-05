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

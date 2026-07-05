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

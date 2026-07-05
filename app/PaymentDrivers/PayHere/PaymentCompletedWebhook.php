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
            nlog('PayHere:: invalid md5sig at job time for order_id ' . ($this->data['order_id'] ?? ''));
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

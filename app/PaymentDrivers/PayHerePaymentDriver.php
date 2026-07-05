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
     *
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
     * Verify the md5sig on a PayHere IPN (notify_url) notification.
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

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

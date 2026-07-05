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

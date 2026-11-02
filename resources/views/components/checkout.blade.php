@php($targetId = $attributes->get('id', 'mib-global-pay-checkout'))
<div {{ $attributes->merge(['id' => $targetId]) }}>
    {{ $slot->isEmpty() ? ($embedded ? __('Loading the payment form…') : __('Redirecting to the payment page…')) : $slot }}
</div>
<script src="{{ $scriptUrl }}" @foreach ($callbacks as $callback => $function) data-{{ $callback }}="{{ $function }}" @endforeach></script>
<script>
    function mibGlobalPayError(error) {
        console.error('MIB Checkout error', error);
        document.getElementById(@js($targetId)).textContent = @js(__('The payment page could not be loaded. Please try again.'));
    }
    if (typeof Checkout === 'undefined') {
        mibGlobalPayError('checkout.min.js did not load');
    } else {
        Checkout.configure({ session: { id: @js($session) } });
@if ($embedded)
        Checkout.showEmbeddedPage('#' + CSS.escape(@js($targetId)));
@else
        Checkout.showPaymentPage();
@endif
    }
</script>

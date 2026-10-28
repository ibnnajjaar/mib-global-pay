<div {{ $attributes->merge(['id' => 'mib-global-pay-checkout']) }}>
    {{ $slot->isEmpty() ? __('Redirecting to the payment page…') : $slot }}
</div>
<script src="{{ $scriptUrl }}" data-error="mibGlobalPayError" data-cancel="mibGlobalPayCancel"></script>
<script>
    function mibGlobalPayError(error) { console.error('MIB Checkout error', error); }
    function mibGlobalPayCancel() {}
    Checkout.configure({ session: { id: @js($session) } });
    Checkout.showPaymentPage();
</script>

@php
    $targetId = $attributes->get('id', 'mib-global-pay-checkout');
    $isPayment = $display === \IbnNajjaar\MIBGlobalPay\Core\Checkout\CheckoutDisplay::Payment;
    $isModal = $display === \IbnNajjaar\MIBGlobalPay\Core\Checkout\CheckoutDisplay::Modal;
@endphp
@if ($isModal)
<button type="button" id="{{ $targetId }}-open">{{ __('Pay now') }}</button>
<dialog id="{{ $targetId }}-dialog">
@endif
<div {{ $attributes->merge(['id' => $targetId]) }}>
    {{ $slot->isEmpty() ? ($isPayment ? __('Redirecting to the payment page…') : __('Loading the payment form…')) : $slot }}
</div>
@if ($isModal)
</dialog>
@endif
{!! $checkoutJs($targetId)->scripts() !!}

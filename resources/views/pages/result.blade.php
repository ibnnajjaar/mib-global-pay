<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Payment') }}</title>
</head>
<body>
    <p>
        @switch($status)
            @case(\IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationStatus::Captured)
                {{ __('Thank you. Your payment was successful.') }}
                @break
            @case(\IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationStatus::Authorized)
            @case(\IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationStatus::PartiallyCaptured)
                {{ __('Thank you. Your payment has been approved.') }}
                @break
            @case(\IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationStatus::Verified)
                {{ __('Thank you. Your card has been verified.') }}
                @break
            @case(\IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationStatus::Pending)
                {{ __('We are still confirming your payment. Please check back shortly.') }}
                @break
            @default
                {{ __('Your payment was not completed.') }}
        @endswitch
    </p>
</body>
</html>

<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Payment') }}</title>
</head>
<body>
    <x-mib-global-pay::checkout :session="$sessionId" :mode="$mode" />
</body>
</html>

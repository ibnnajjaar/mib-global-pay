<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;

/**
 * Where the gateway sends a payer whose payment link cannot be used. Only known codes are shown.
 */
final class PaymentLinkErrorController
{
    public function __invoke(Request $request): View
    {
        $message = match ($request->query('errorCode')) {
            'EXPIRED' => __('This payment link has expired. Please ask for a new one.'),
            'PAID' => __('This order has already been paid.'),
            'USAGE_COUNT_EXCEEDED' => __('This payment link has been tried too many times. Please ask for a new one.'),
            'DOES_NOT_EXIST', 'DELETED' => __('This payment link is no longer valid.'),
            default => __('This payment link could not be opened. Please try again later.'),
        };

        /** @var view-string $name registered by the service provider (loadViewsFrom) */
        $name = 'mib-global-pay::pages.link-error';

        return view($name, ['message' => $message]);
    }
}

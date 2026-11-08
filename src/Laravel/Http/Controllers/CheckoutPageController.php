<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use IbnNajjaar\MIBGlobalPay\Laravel\Support\Settings;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\AttemptState;
use IbnNajjaar\MIBGlobalPay\Laravel\Models\CheckoutAttempt;
use IbnNajjaar\MIBGlobalPay\Laravel\Checkout\CheckoutStarter;

/**
 * The signed page that starts Checkout.js for an attempt. A host links to it with
 * $attempt->paymentUrl() and writes no JavaScript.
 */
final readonly class CheckoutPageController
{
    public function __construct(
        private Settings $settings,
    ) {
    }

    public function __invoke(Request $request, string $order): View|RedirectResponse
    {
        $attempt = CheckoutAttempt::query()->where('gateway_order_id', $order)->firstOrFail();

        if ($attempt->session_id === null || $attempt->state !== AttemptState::Live) {
            return new RedirectResponse(route(CheckoutStarter::RETURN_ROUTE, ['order' => $order]));
        }

        /** @var view-string $name registered by the service provider (loadViewsFrom) */
        $name = 'mib-global-pay::pages.checkout';

        return view($name, ['sessionId' => $attempt->session_id, 'mode' => $this->settings->checkoutMode()]);
    }
}

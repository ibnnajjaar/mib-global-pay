<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\View\Components;

use Illuminate\View\Component;
use Illuminate\Contracts\View\View;
use IbnNajjaar\MIBGlobalPay\Laravel\Support\Settings;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;

/**
 * <x-mib-global-pay::checkout :session="$sessionId" />
 *
 * Renders the Checkout.js redirect to the Hosted Payment Page. From API v67, configure() takes
 * only the session; everything else is set in Initiate Checkout.
 */
final class Checkout extends Component
{
    public readonly string $scriptUrl;

    public function __construct(
        public readonly string $session,
        Settings $settings,
        GatewayConfig $config,
        ?string $scriptUrl = null,
    ) {
        $this->scriptUrl = $scriptUrl
            ?? $settings->checkoutJsUrl()
            ?? 'https://' . $config->host . '/static/checkout/checkout.min.js';
    }

    public function render(): View
    {
        /** @var view-string $name registered by the service provider (loadViewsFrom) */
        $name = 'mib-global-pay::components.checkout';

        return view($name);
    }
}

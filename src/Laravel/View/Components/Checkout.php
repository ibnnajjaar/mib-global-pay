<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\View\Components;

use Illuminate\View\Component;
use Illuminate\Contracts\View\View;
use IbnNajjaar\MIBGlobalPay\Core\Checkout\CheckoutJs;
use IbnNajjaar\MIBGlobalPay\Laravel\Support\Settings;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Checkout\CheckoutDisplay;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;

/**
 * <x-mib-global-pay::checkout :session="$sessionId" />
 *
 * Renders Checkout.js and starts the payment. mode is "payment" (redirect to the Payment Page, the
 * default), "embedded" (inside this element) or "modal" (in a dialog). error, cancel, complete,
 * timeout, before-redirect and after-redirect each name a global JavaScript function for that
 * callback. cancel works with the Payment Page only, so other modes refuse it.
 */
final class Checkout extends Component
{
    public readonly string $scriptUrl;

    public readonly CheckoutDisplay $display;

    /** @var array<string, string|null> */
    private readonly array $callbacks;

    /**
     * @throws InvalidRequest
     */
    public function __construct(
        public readonly string $session,
        Settings $settings,
        GatewayConfig $config,
        ?string $scriptUrl = null,
        string $mode = 'payment',
        ?string $error = null,
        ?string $cancel = null,
        ?string $complete = null,
        ?string $timeout = null,
        ?string $beforeRedirect = null,
        ?string $afterRedirect = null,
    ) {
        $this->display = CheckoutDisplay::tryFrom($mode)
            ?? throw new InvalidRequest('Checkout mode must be "payment", "embedded" or "modal"');
        $this->scriptUrl = $scriptUrl
            ?? $settings->checkoutJsUrl()
            ?? CheckoutJs::defaultScriptUrl($config);
        $this->callbacks = ['error' => $error, 'cancel' => $cancel, 'complete' => $complete, 'timeout' => $timeout, 'beforeRedirect' => $beforeRedirect, 'afterRedirect' => $afterRedirect];

        $this->checkoutJs('mib-global-pay-checkout'); // refuse a bad setup as soon as the component is built
    }

    /**
     * @throws InvalidRequest
     */
    public function checkoutJs(string $targetId): CheckoutJs
    {
        $message = __('The payment page could not be loaded. Please try again.');

        return new CheckoutJs($this->session, $this->scriptUrl, $this->display, $targetId, $this->callbacks, is_string($message) ? $message : '');
    }

    public function render(): View
    {
        /** @var view-string $name registered by the service provider (loadViewsFrom) */
        $name = 'mib-global-pay::components.checkout';

        return view($name);
    }
}

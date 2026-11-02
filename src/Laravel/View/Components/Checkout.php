<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\View\Components;

use Illuminate\View\Component;
use Illuminate\Contracts\View\View;
use IbnNajjaar\MIBGlobalPay\Laravel\Support\Settings;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;

/**
 * <x-mib-global-pay::checkout :session="$sessionId" />
 *
 * Renders Checkout.js and starts the payment. From API v67, configure() takes only the session;
 * everything else is set in Initiate Checkout.
 *
 * mode is "payment" (redirect to the Payment Page, the default) or "embedded" (show the Embedded
 * Page inside this element). error, cancel, complete, timeout, before-redirect and after-redirect
 * each name a global JavaScript function for that Checkout.js callback. cancel works with the
 * Payment Page only, so it is refused in embedded mode.
 */
final class Checkout extends Component
{
    private const string CALLBACK_NAME = '/^[A-Za-z_$][\w$]*(\.[A-Za-z_$][\w$]*)*$/';

    public readonly string $scriptUrl;

    public readonly bool $embedded;

    /** @var array<string, string> Checkout.js callback name => global function name */
    public readonly array $callbacks;

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
        if (! in_array($mode, ['payment', 'embedded'], true)) {
            throw new InvalidRequest('Checkout mode must be "payment" or "embedded"');
        }

        if ($mode === 'embedded' && $cancel !== null) {
            throw new InvalidRequest('The cancel callback works with the Payment Page only, not the Embedded Page');
        }

        $this->scriptUrl = $scriptUrl
            ?? $settings->checkoutJsUrl()
            ?? 'https://' . $config->host . '/static/checkout/checkout.min.js';
        $this->embedded = $mode === 'embedded';
        $this->callbacks = self::validated([
            'error' => $error ?? 'mibGlobalPayError',
            'cancel' => $cancel,
            'complete' => $complete,
            'timeout' => $timeout,
            'beforeRedirect' => $beforeRedirect,
            'afterRedirect' => $afterRedirect,
        ]);
    }

    public function render(): View
    {
        /** @var view-string $name registered by the service provider (loadViewsFrom) */
        $name = 'mib-global-pay::components.checkout';

        return view($name);
    }

    /**
     * @param array<string, string|null> $callbacks
     *
     * @return array<string, string>
     *
     * @throws InvalidRequest
     */
    private static function validated(array $callbacks): array
    {
        $names = array_filter($callbacks, fn (?string $name): bool => $name !== null);

        foreach ($names as $callback => $name) {
            if (preg_match(self::CALLBACK_NAME, $name) !== 1) {
                throw new InvalidRequest("The {$callback} callback must name a global JavaScript function");
            }
        }

        return $names;
    }
}

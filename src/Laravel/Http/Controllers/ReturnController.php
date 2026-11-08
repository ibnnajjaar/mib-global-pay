<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use IbnNajjaar\MIBGlobalPay\Laravel\PaymentOutcome;
use IbnNajjaar\MIBGlobalPay\Laravel\Support\Settings;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\GatewayException;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\TransportException;
use IbnNajjaar\MIBGlobalPay\Core\Verification\VerificationStatus;

/**
 * Where the gateway sends the payer back. Asks the gateway what happened, which dispatches the
 * usual events, then sends the payer to your completed URL or shows a simple result page.
 */
final readonly class ReturnController
{
    public function __construct(
        private PaymentOutcome $outcome,
        private Settings $settings,
    ) {
    }

    public function __invoke(Request $request, string $order): View|RedirectResponse
    {
        /** @var array<array-key, mixed> $query */
        $query = $request->query();

        try {
            $result = $this->outcome->resolveFromReturn($order, $query);
        } catch (GatewayException|TransportException $e) {
            report($e);
            $result = ['attempt' => null, 'outcome' => null, 'unreachable' => true];
        }

        if ($result['attempt'] === null && ! isset($result['unreachable'])) {
            abort(404);
        }

        // The webhook or mib:reconcile finishes the job if the gateway could not be asked just now.
        $status = $result['outcome']->verification->status ?? VerificationStatus::Pending;
        $completed = $this->settings->checkoutCompletedUrl();

        if ($completed !== null) {
            return new RedirectResponse($completed . (str_contains($completed, '?') ? '&' : '?') . http_build_query(['order' => $order, 'status' => $status->value]));
        }

        /** @var view-string $name registered by the service provider (loadViewsFrom) */
        $name = 'mib-global-pay::pages.result';

        return view($name, ['status' => $status]);
    }
}

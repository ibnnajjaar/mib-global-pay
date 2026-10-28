<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Support;

use Illuminate\Routing\Route;

/**
 * Remembers the Route created by Route::mibGlobalPayWebhook(), so the package can build the
 * webhook URL even when a host group renames it (e.g. ->name('student.') gives
 * "student.mib-global-pay.webhook") or puts it on a domain.
 */
final class WebhookRoute
{
    private ?Route $route = null;

    public function remember(Route $route): void
    {
        $this->route = $route;
    }

    public function route(): ?Route
    {
        return $this->route;
    }
}

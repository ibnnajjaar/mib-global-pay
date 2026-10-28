<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Laravel\Events;

/**
 * What triggered the outcome pipeline.
 */
enum Source: string
{
    case Webhook = 'webhook';
    case ReturnUrl = 'return_url';
    case Reconcile = 'reconcile';
    case Manual = 'manual';

    /** AttemptGuard found money captured on an earlier attempt while starting a new one. */
    case AttemptGuard = 'attempt_guard';
}

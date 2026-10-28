<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Attempts;

use Closure;
use IbnNajjaar\MIBGlobalPay\Core\Money\Money;

/**
 * The host's payable (ISIMS: InvoicePayment). Implemented by the host; the host owns the model.
 * While Frozen, its invoice set must stay immutable.
 */
interface Payable
{
    /** Stable host reference (ISIMS: receipt_no). Sent as order.reference. Max 40 characters. */
    public function payableReference(): string;

    /** Frozen amount; identical for every attempt. Never a recomputed invoice total. */
    public function expectedAmount(): Money;

    public function payableState(): PayableState;

    public function settledBy(): ?Settlement;

    /** @return iterable<Attempt> newest first */
    public function attempts(): iterable;

    /**
     * Run $callback holding the host's row lock on the payable (e.g. DB::transaction +
     * lockForUpdate). Implementations must re-read state inside the lock.
     *
     * @template T
     *
     * @param Closure(): T $callback
     *
     * @return T
     */
    public function lock(Closure $callback): mixed;
}

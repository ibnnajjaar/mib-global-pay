<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Attempts\Payable;
use IbnNajjaar\MIBGlobalPay\Laravel\Concerns\HasMibPayments;

/**
 * What a host writes: a model, the interface, the trait and two methods.
 *
 * @property string $reference
 * @property string $total
 */
final class PayableInvoice extends Model implements Payable
{
    use HasMibPayments;

    protected $table = 'invoices';

    protected $guarded = [];

    public function payableReference(): string
    {
        return $this->reference;
    }

    public function expectedAmount(): Money
    {
        return Money::of($this->total, Currency::Mvr);
    }
}

<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Requests;

use IbnNajjaar\MIBGlobalPay\Core\Money\Money;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;

/**
 * agreement.*: the payer's agreement to later payments on a stored card.
 */
final readonly class Agreement
{
    /**
     * @throws InvalidRequest
     */
    public function __construct(
        public string $id,
        public AgreementType $type = AgreementType::Recurring,
        public ?AmountVariability $amountVariability = null,
        public ?PaymentFrequency $paymentFrequency = null,
        public ?\DateTimeInterface $startDate = null,
        public ?\DateTimeInterface $expiryDate = null,
        public ?int $minimumDaysBetweenPayments = null,
        public ?int $numberOfPayments = null,
        public ?Money $minimumAmountPerPayment = null,
        public ?Money $maximumAmountPerPayment = null,
    ) {
        Validate::identifier($id, 'Agreement ID');

        if ($type === AgreementType::Recurring && $amountVariability === null) {
            throw new InvalidRequest('A recurring agreement needs an amount variability');
        }

        if ($minimumDaysBetweenPayments !== null && ($minimumDaysBetweenPayments < 1 || $minimumDaysBetweenPayments > 9999)) {
            throw new InvalidRequest('Minimum days between payments must be between 1 and 9999');
        }

        if ($numberOfPayments !== null && ($numberOfPayments < 1 || $numberOfPayments > 999)) {
            throw new InvalidRequest('Number of payments must be between 1 and 999');
        }
    }

    /** @return array<string, string|int|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'amountVariability' => $this->amountVariability?->value,
            'paymentFrequency' => $this->paymentFrequency?->value,
            'startDate' => $this->startDate?->format('Y-m-d'),
            'expiryDate' => $this->expiryDate?->format('Y-m-d'),
            'minimumDaysBetweenPayments' => $this->minimumDaysBetweenPayments,
            'numberOfPayments' => $this->numberOfPayments,
            'minimumAmountPerPayment' => $this->minimumAmountPerPayment?->toDecimal(),
            'maximumAmountPerPayment' => $this->maximumAmountPerPayment?->toDecimal(),
        ];
    }
}

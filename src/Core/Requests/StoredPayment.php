<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Requests;

use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;

/**
 * How a server-side PAY, AUTHORIZE or VERIFY pays: with a gateway token saved during an earlier
 * payer-present checkout. The package never takes card numbers.
 */
final readonly class StoredPayment
{
    /**
     * @throws InvalidRequest
     */
    private function __construct(
        public string $token,
        public ?string $agreementId,
        public ?TransactionSource $source,
    ) {
        if (preg_match('/^[0-9A-Za-z]{1,40}$/', $token) !== 1) {
            throw new InvalidRequest('Token must be 1-40 characters from 0-9, a-z and A-Z');
        }

        if ($agreementId !== null) {
            Validate::identifier($agreementId, 'Agreement ID');
        }
    }

    /**
     * The payer is taking part, for example choosing a saved card.
     *
     * @throws InvalidRequest
     */
    public static function payerInitiated(string $token, ?string $agreementId = null): self
    {
        return new self($token, $agreementId, null);
    }

    /**
     * You are charging under an agreement, without the payer present (transaction.source MERCHANT).
     * The first payment on the card must have been payer-initiated.
     *
     * @throws InvalidRequest
     */
    public static function merchantInitiated(string $token, string $agreementId): self
    {
        return new self($token, $agreementId, TransactionSource::Merchant);
    }

    /** @return array<string, mixed> */
    public function toBody(): array
    {
        return [
            'sourceOfFunds' => [
                'token' => $this->token,
                'provided' => ['card' => ['storedOnFile' => StoredOnFile::Stored->value]],
            ],
            'agreement' => ['id' => $this->agreementId],
            'transaction' => ['source' => $this->source?->value],
        ];
    }
}

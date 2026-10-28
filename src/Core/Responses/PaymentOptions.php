<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Responses;

use IbnNajjaar\MIBGlobalPay\Core\Money\Currency;
use IbnNajjaar\MIBGlobalPay\Core\Support\Payload;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedPayload;

/**
 * Payment Options Inquiry response: what the merchant profile can accept.
 * Only the commonly needed parts are typed; everything else is in $payload.
 */
final readonly class PaymentOptions
{
    /**
     * @param list<string> $paymentTypes e.g. ["card", "paypal"]
     * @param list<string> $cardTypes    e.g. ["CREDIT", "DEBIT"]
     * @param list<string> $cardCurrencies raw ISO codes, including ones Currency does not model
     */
    private function __construct(
        public array $paymentTypes,
        public array $cardTypes,
        public array $cardCurrencies,
        public Payload $payload,
    ) {
    }

    /**
     * @throws MalformedPayload
     */
    public static function fromPayload(Payload $p): self
    {
        $types = $p->payload('paymentTypes');

        return new self(
            paymentTypes: $types === null ? [] : array_map('strval', array_keys($types->raw())),
            cardTypes: self::strings($p->list('paymentTypes.card.cardTypes'), 'cardType'),
            cardCurrencies: self::strings($p->list('paymentTypes.card.currencies'), 'currency'),
            payload: $p,
        );
    }

    public function acceptsCards(): bool
    {
        return in_array('card', $this->paymentTypes, true);
    }

    public function cardsAcceptCurrency(Currency $currency): bool
    {
        return in_array($currency->value, $this->cardCurrencies, true);
    }

    /**
     * @param list<Payload> $items
     *
     * @return list<string>
     *
     * @throws MalformedPayload
     */
    private static function strings(array $items, string $key): array
    {
        $values = [];
        foreach ($items as $item) {
            $value = $item->string($key);
            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }
}

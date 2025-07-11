<?php

namespace IbnNajjaar\MIBGlobalPay\DataObjects;

use IbnNajjaar\MIBGlobalPay\Support\IsResponseData;

class CheckoutSessionData implements IsResponseData
{
    /**
     * @var string|null
     */
    private $sessionId;

    /**
     * @var string|null
     */
    private $successIndicator;

    /**
     * @var array|null
     */
    private $rawResponse;

    /**
     * CheckoutSessionData constructor.
     *
     * @param ?string $sessionId
     * @param string|null $successIndicator
     * @param array|null $rawResponse
     */
    public function __construct(?string $sessionId, ?string $successIndicator, ?array $rawResponse)
    {
        $this->sessionId = $sessionId;
        $this->successIndicator = $successIndicator;
        $this->rawResponse = $rawResponse;
    }

    /**
     * @param array $response
     * @return self
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $response): IsResponseData
    {
        return new self(
            $response['session']['id'] ?? null,
            $response['successIndicator'] ?? null,
            $response
        );
    }

    /**
     * @return string
     */
    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * @return string|null
     */
    public function getSuccessIndicator(): ?string
    {
        return $this->successIndicator;
    }

    /**
     * @return array|null
     */
    public function getRawResponse(): ?array
    {
        return $this->rawResponse;
    }
}

<?php

namespace IbnNajjaar\MIBGlobalPay\Responses\DataObjects;

use IbnNajjaar\MIBGlobalPay\Contracts\IsResponseData;

class CheckoutSessionResponseData implements IsResponseData
{
    /**
     * @var string|null
     */
    private $session_id;

    /**
     * @var string|null
     */
    private $success_indicator;

    /**
     * @var array|null
     */
    private $raw_response;

    /**
     * CheckoutSessionData constructor.
     *
     * @param ?string $sessionId
     * @param string|null $successIndicator
     * @param array|null $rawResponse
     */
    public function __construct(?string $session_id, ?string $success_indicator, ?array $raw_response)
    {
        $this->session_id = $session_id;
        $this->success_indicator = $success_indicator;
        $this->raw_response = $raw_response;
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
        return $this->session_id;
    }

    /**
     * @return string|null
     */
    public function getSuccessIndicator(): ?string
    {
        return $this->success_indicator;
    }

    /**
     * @return array|null
     */
    public function getRawResponse(): ?array
    {
        return $this->raw_response;
    }
}

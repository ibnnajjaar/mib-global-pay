<?php

namespace IbnNajjaar\MIBGlobalPay\Responses\DataObjects;

use IbnNajjaar\MIBGlobalPay\Support\Arr;
use IbnNajjaar\MIBGlobalPay\Contracts\IsResponseData;

class HostedCheckoutReturnData implements IsResponseData
{
    /**
     * @var string|null
     */
    private $result_indicator;


    /**
     * @var array|null
     */
    private $raw_response;

    /**
     * CheckoutSessionData constructor.
     *
     * @param ?string $result_indicator
     * @param array|null $raw_response
     */
    public function __construct(?string $result_indicator, ?array $raw_response)
    {
        $this->result_indicator = $result_indicator;
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
            $response['resultIndicator'] ?? null,
            $response
        );
    }

    /**
     * @return string
     */
    public function getResultIndicator(): ?string
    {
        return $this->result_indicator;
    }

    /**
     * @return array|null
     */
    public function getRawResponse(): ?array
    {
        return $this->raw_response;
    }

    public function getSessionVersion(): ?string
    {
        return Arr::string(Arr::get($this->raw_response ?? [], 'sessionVersion'));
    }

    /**
     * Only present if you added an "order" parameter to your return URL.
     */
    public function getOrderId(): ?string
    {
        return Arr::string(Arr::get($this->raw_response ?? [], 'order'));
    }

    /**
     * Compare the returned resultIndicator with the successIndicator you stored
     * when creating the checkout session (timing-safe). A match means the payment succeeded.
     */
    public function matchesSuccessIndicator(?string $success_indicator): bool
    {
        return is_string($this->result_indicator)
            && is_string($success_indicator)
            && $success_indicator !== ''
            && hash_equals($success_indicator, $this->result_indicator);
    }
}

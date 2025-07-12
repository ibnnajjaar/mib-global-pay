<?php

namespace IbnNajjaar\MIBGlobalPay\Responses\DataObjects;

use IbnNajjaar\MIBGlobalPay\Contracts\IsResponseData;

class TransactionResponseData implements IsResponseData
{

    private $type;
    private $result;
    private $gateway_code;
    private $raw_response;

    public function __construct(?string $result, ?string $gateway_code, ?string $type, ?array $raw_response)
    {
        $this->type = $type;
        $this->result = $result;
        $this->gateway_code = $gateway_code;
        $this->raw_response = $raw_response;
    }

    public static function fromArray(array $response): IsResponseData
    {
        return new self(
            $response['result'] ? strtolower($response['result']) : null,
            $response['response']['gatewayCode'] ? strtolower($response['response']['gatewayCode']) : null,
                $response['transaction']['type'] ? strtolower($response['transaction']['type']) : null,
            $response
        );
    }

    public function getType(): ?string
    {
        return $this->type ? strtolower($this->type) : null;
    }

    public function getResult(): ?string
    {
        return $this->result ? strtolower($this->result) : null;
    }

    public function getGatewayCode(): ?string
    {
        return $this->gateway_code ? strtolower($this->gateway_code) : null;
    }


    public function getRawResponse(): ?array
    {
        return $this->raw_response;
    }
}

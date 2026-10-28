<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Contracts;

use IbnNajjaar\MIBGlobalPay\Core\Clients\OrderClient;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;
use IbnNajjaar\MIBGlobalPay\Core\Clients\GatewayClient;
use IbnNajjaar\MIBGlobalPay\Core\Clients\SessionClient;
use IbnNajjaar\MIBGlobalPay\Core\Clients\CheckoutClient;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\InvalidRequest;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\GatewayException;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\MalformedPayload;
use IbnNajjaar\MIBGlobalPay\Core\Exceptions\TransportException;

/**
 * The gateway client contract. MibGateway implements it; the testing fake (stage 7) will too,
 * so host code should type-hint this interface.
 */
interface Gateway
{
    public function config(): GatewayConfig;

    /**
     * Send any operation, including ones without a convenience method yet.
     *
     * @template T of object
     *
     * @param Operation<T> $operation
     *
     * @return T
     *
     * @throws InvalidRequest    before anything is sent
     * @throws GatewayException  HTTP 4xx/5xx
     * @throws TransportException no response (connection error, timeout)
     * @throws MalformedPayload  the response could not be parsed
     */
    public function send(Operation $operation): object;

    public function checkout(): CheckoutClient;

    public function sessions(): SessionClient;

    public function orders(): OrderClient;

    public function gateway(): GatewayClient;
}

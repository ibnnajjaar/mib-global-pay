<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Handler\MockHandler;
use Psr\Http\Message\RequestInterface;
use IbnNajjaar\MIBGlobalPay\Core\MibGateway;
use IbnNajjaar\MIBGlobalPay\Core\Http\RetryPolicy;
use IbnNajjaar\MIBGlobalPay\Core\Config\GatewayConfig;

/**
 * Builds a MibGateway over a Guzzle MockHandler. No real HTTP is ever sent.
 */
final class GatewayHarness
{
    /** @var list<array{request: RequestInterface}> */
    public array $history = [];

    /** @var list<int> */
    public array $sleeps = [];

    public readonly ArrayLogger $logger;

    public readonly MibGateway $gateway;

    /**
     * @param list<mixed> $responses responses or exceptions, in order
     * @param array<string, mixed> $config overrides for GatewayConfig::fromArray()
     */
    public function __construct(array $responses, array $config = [], ?RetryPolicy $retry = null)
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        $this->logger = new ArrayLogger();
        $this->gateway = new MibGateway(
            config: GatewayConfig::fromArray($config + [
                'merchant_id' => 'TEST123',
                'api_password' => 'super-secret-password',
                'order_prefix' => 'ISIMS',
            ]),
            httpClient: new Client(['handler' => $stack, 'http_errors' => false]),
            logger: $this->logger,
            retryPolicy: $retry,
            sleep: function (int $ms): void {
                $this->sleeps[] = $ms;
            },
        );
    }

    public static function json(int $status, array|string $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], is_string($body) ? $body : (string) json_encode($body));
    }

    public static function fixture(int $status, string $name): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) file_get_contents(__DIR__ . '/../Fixtures/v2/' . $name));
    }

    public function request(int $index = 0): RequestInterface
    {
        return $this->history[$index]['request'];
    }

    /** @return array<string, mixed>|null */
    public function requestBody(int $index = 0): ?array
    {
        $body = (string) $this->request($index)->getBody();

        return $body === '' ? null : json_decode($body, true);
    }
}

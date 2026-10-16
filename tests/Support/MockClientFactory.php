<?php

namespace IbnNajjaar\MIBGlobalPay\Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Handler\MockHandler;

final class MockClientFactory
{
    /**
     * Build a Guzzle client that replays the given responses (or exceptions)
     * and records every outgoing request into $history.
     *
     * @param array $responses
     * @param array|null $history Filled with ['request' => RequestInterface, ...] entries
     */
    public static function make(array $responses, &$history = null): Client
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new Client(['handler' => $stack]);
    }

    public static function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    public static function fixture(string $name): array
    {
        return json_decode(file_get_contents(__DIR__ . '/../Fixtures/' . $name), true);
    }
}

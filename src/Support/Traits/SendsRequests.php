<?php

namespace IbnNajjaar\MIBGlobalPay\Support\Traits;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException;
use IbnNajjaar\MIBGlobalPay\Support\Request;
use IbnNajjaar\MIBGlobalPay\Support\Response;
use IbnNajjaar\MIBGlobalPay\Exceptions\MIBGlobalPayException;

trait SendsRequests
{
    /**
     * @throws MIBGlobalPayException
     */
    public function send(Request $request): Response
    {
        try {
            $client = $this->getHttpClient();
            $options = $this->buildRequestOptions($request);

            $response = $this->makeHttpRequest($client, $request, $options);

            return $this->createResponse($response, $request->getResponseDataClass());
        } catch (RequestException $exception) {
            throw new MIBGlobalPayException(
                'HTTP request failed: ' . $exception->getMessage(),
                $exception->getCode(),
                $exception
            );
        } catch (\Exception $exception) {
            throw new MIBGlobalPayException(
                'Request failed: ' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    protected function buildRequestOptions(Request $request): array
    {
        $options = [];

        $options = $this->addQueryParameters($options, $request);
        $options = $this->addHeaders($options, $request);
        $options = $this->addBodyData($options, $request);

        return $options;
    }

    protected function addQueryParameters(array $options, Request $request): array
    {
        $query = $request->getQuery();
        if (!empty($query)) {
            $options['query'] = $query;
        }

        return $options;
    }

    protected function addBodyData(array $options, Request $request): array
    {
        $method = strtolower($request->getMethod());

        if ($this->methodSupportsBody($method)) {
            $data = $request->getData();
            if (!empty($data)) {
                $options['json'] = $data;
            }
        }

        return $options;
    }

    protected function methodSupportsBody(string $method): bool
    {
        return in_array($method, ['post', 'put']);
    }

    protected function addHeaders(array $options, Request $request): array
    {
        $headers = $request->getHeaders();
        if (!empty($headers)) {
            $options['headers'] = array_merge($this->defaultHeaders(), $headers);
        }

        return $options;
    }

    protected function makeHttpRequest($client, Request $request, array $options): ResponseInterface
    {
        $method = strtolower($request->getMethod());
        $endpoint = $request->resolveEndpoint();

        return $client->$method($endpoint, $options);
    }

    /**
     * @throws MIBGlobalPayException
     */
    protected function createResponse($httpResponse, string $response_data_class): Response
    {
        return new Response($httpResponse, $response_data_class);
    }
}

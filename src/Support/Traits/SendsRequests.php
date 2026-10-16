<?php

namespace IbnNajjaar\MIBGlobalPay\Support\Traits;

use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use IbnNajjaar\MIBGlobalPay\Support\Request;
use IbnNajjaar\MIBGlobalPay\Support\Response;
use IbnNajjaar\MIBGlobalPay\Exceptions\GatewayException;
use IbnNajjaar\MIBGlobalPay\Exceptions\MIBGlobalPayException;

trait SendsRequests
{
    /**
     * @throws MIBGlobalPayException
     * @throws GatewayException when the gateway responds with HTTP 4xx/5xx
     */
    public function send(Request $request): Response
    {
        try {
            $client = $this->getHttpClient();
            $options = $this->buildRequestOptions($request);

            $response = $this->makeHttpRequest($client, $request, $options);

            return $this->createResponse($response, $request->getResponseDataClass());
        } catch (RequestException $exception) {
            if ($exception->hasResponse()) {
                throw GatewayException::fromResponse($exception->getResponse(), null, $exception);
            }

            throw new MIBGlobalPayException(
                'HTTP request failed: ' . $exception->getMessage(),
                0,
                $exception
            );
        } catch (TransferException $exception) {
            // Connection errors and timeouts (Guzzle 7's ConnectException is not a RequestException)
            throw new MIBGlobalPayException(
                'HTTP request failed: ' . $exception->getMessage(),
                0,
                $exception
            );
        } catch (MIBGlobalPayException $exception) {
            throw $exception;
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
        $options = [
            'auth' => $this->defaultAuth(),
            'timeout' => $this->timeout,
        ];

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
        $options['headers'] = array_merge($this->defaultHeaders(), $request->getHeaders());

        return $options;
    }

    protected function makeHttpRequest($client, Request $request, array $options): ResponseInterface
    {
        $url = $this->resolveBaseUrl() . $request->resolveEndpoint();

        return $client->request(strtoupper($request->getMethod()), $url, $options);
    }

    /**
     * @throws MIBGlobalPayException
     */
    protected function createResponse($httpResponse, ?string $response_data_class): Response
    {
        return new Response($httpResponse, $response_data_class);
    }
}

<?php

namespace IbnNajjaar\MIBGlobalPay\Support\Traits;

use IbnNajjaar\MIBGlobalPay\Support\Request;
use IbnNajjaar\MIBGlobalPay\Support\Response;
use GuzzleHttp\Exception\RequestException;
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
            $method = strtolower($request->getMethod());
            $endpoint = $request->resolveEndpoint();

            $options = [];

            // Add query parameters
            $query = $request->getQuery();
            if (!empty($query)) {
                $options['query'] = $query;
            }

            // Add headers
            $headers = $request->getHeaders();
            if (!empty($headers)) {
                $options['headers'] = array_merge($this->defaultHeaders(), $headers);
            }

            // Add body data for POST/PUT/PATCH requests
            if (in_array($method, ['post', 'put', 'patch'])) {
                $data = $request->getData();
                if (!empty($data)) {
                    $options['json'] = $data;
                }
            }

            $response = $client->$method($endpoint, $options);

            return new Response($response);

        } catch (RequestException $e) {
            throw new MIBGlobalPayException(
                'HTTP request failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } catch (\Exception $e) {
            throw new MIBGlobalPayException(
                'Request failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}

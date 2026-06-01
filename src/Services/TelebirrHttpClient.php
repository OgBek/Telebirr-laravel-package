<?php

declare(strict_types=1);

namespace Bekambeyene\Telebirr\Services;

use Bekambeyene\Telebirr\Exceptions\NetworkException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class TelebirrHttpClient
{
    protected string $baseUrl;
    protected bool $verifySsl;
    protected int $timeout;
    protected Client $client;

    /**
     * @param string $baseUrl
     * @param bool $verifySsl Whether to verify SSL (should be true in production)
     * @param int $timeout Request timeout in seconds
     */
    public function __construct(string $baseUrl, bool $verifySsl = true, int $timeout = 30)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->verifySsl = $verifySsl;
        $this->timeout = $timeout;
        $this->client = new Client();
    }

    /**
     * Perform a POST request to the Telebirr API.
     *
     * @param string $endpoint
     * @param array|string $payload Array for JSON, String for raw body
     * @param array $headers
     * @return array
     * @throws NetworkException
     */
    public function post(string $endpoint, $payload, array $headers = []): array
    {
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

        $options = [
            'headers' => array_merge([
                'Content-Type' => 'application/json',
            ], $headers),
            'timeout' => $this->timeout,
            'verify' => $this->verifySsl,
            'http_errors' => false,
        ];

        if (is_string($payload)) {
            $options['body'] = $payload;
        } else {
            $options['json'] = $payload;
        }

        try {
            $response = $this->client->request('POST', $url, $options);
            return $this->handleResponse($response);
        } catch (GuzzleException $e) {
            throw new NetworkException("Telebirr API communication failure: " . $e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            throw new NetworkException("Telebirr API request error: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Handle the HTTP response.
     *
     * @param \Psr\Http\Message\ResponseInterface $response
     * @return array
     * @throws NetworkException
     */
    protected function handleResponse(\Psr\Http\Message\ResponseInterface $response): array
    {
        $statusCode = $response->getStatusCode();
        $body = (string)$response->getBody();

        if ($statusCode >= 200 && $statusCode < 300) {
            $result = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $result;
            }
        }

        throw new NetworkException("Telebirr API error (Status: {$statusCode}): {$body}");
    }
}

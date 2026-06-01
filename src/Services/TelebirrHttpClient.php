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
    protected ?Client $guzzleClient = null;
    protected bool $useLaravelHttp = false;

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
    }

    /**
     * Set whether to use Laravel's HTTP client.
     * This allows Laravel's testing utilities like Http::fake() to work.
     * 
     * @param bool $use
     * @return $this
     */
    public function setUseLaravelHttp(bool $use): self
    {
        $this->useLaravelHttp = $use;
        return $this;
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

        if ($this->useLaravelHttp && class_exists(\Illuminate\Support\Facades\Http::class)) {
            return $this->postWithLaravel($url, $payload, $headers);
        }

        return $this->postWithGuzzle($url, $payload, $headers);
    }

    /**
     * Execute the request using Laravel's Http client.
     */
    protected function postWithLaravel(string $url, $payload, array $headers): array
    {
        try {
            $request = \Illuminate\Support\Facades\Http::withHeaders(array_merge([
                'Content-Type' => 'application/json',
            ], $headers))
            ->timeout($this->timeout)
            ->withOptions([
                'verify' => $this->verifySsl,
            ]);

            if (is_string($payload)) {
                $response = $request->send('POST', $url, [
                    'body' => $payload
                ]);
            } else {
                $response = $request->post($url, $payload);
            }

            $statusCode = $response->status();
            $body = $response->body();

            if ($response->successful()) {
                $result = json_decode($body, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $result;
                }
            }

            throw new NetworkException("Telebirr API error (Status: {$statusCode}): {$body}");
        } catch (\Throwable $e) {
            if ($e instanceof NetworkException) {
                throw $e;
            }
            throw new NetworkException("Telebirr API communication failure: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Execute the request using GuzzleHttp\Client directly.
     */
    protected function postWithGuzzle(string $url, $payload, array $headers): array
    {
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
            if (!$this->guzzleClient) {
                $this->guzzleClient = new Client();
            }

            $response = $this->guzzleClient->request('POST', $url, $options);
            return $this->handleGuzzleResponse($response);
        } catch (GuzzleException $e) {
            throw new NetworkException("Telebirr API communication failure: " . $e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            throw new NetworkException("Telebirr API request error: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Handle the Guzzle HTTP response.
     */
    protected function handleGuzzleResponse(\Psr\Http\Message\ResponseInterface $response): array
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

<?php

declare(strict_types=1);

namespace Bekambeyene\Telebirr\Services;

use Bekambeyene\Telebirr\Exceptions\NetworkException;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;

class TelebirrHttpClient
{
    protected string $baseUrl;
    protected bool $verifySsl;
    protected int $timeout;

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

        try {
            $request = Http::withHeaders(array_merge([
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

            return $this->handleResponse($response);
        } catch (\Throwable $e) {
            throw new NetworkException("Telebirr API communication failure: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Handle the HTTP response.
     *
     * @param Response $response
     * @return array
     * @throws NetworkException
     */
    protected function handleResponse(Response $response): array
    {
        $statusCode = $response->status();
        $body = $response->body();

        if ($response->successful()) {
            $result = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $result;
            }
        }

        throw new NetworkException("Telebirr API error (Status: {$statusCode}): {$body}");
    }
}

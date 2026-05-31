<?php

declare(strict_types=1);

namespace Bekambeyene\Telebirr\Services;

use Bekambeyene\Telebirr\Exceptions\AuthenticationException;

class TokenManager
{
    protected TelebirrHttpClient $client;
    protected string $fabricAppId;
    protected string $appSecret;

    public function __construct(TelebirrHttpClient $client, string $fabricAppId, string $appSecret)
    {
        $this->client = $client;
        $this->fabricAppId = $fabricAppId;
        $this->appSecret = $appSecret;
    }

    /**
     * Retrieve the Fabric token from Telebirr.
     *
     * @return string
     * @throws AuthenticationException
     */
    public function getFabricToken(): string
    {
        try {
            $response = $this->client->post('/payment/v1/token', [
                'appSecret' => $this->appSecret,
            ], [
                'X-APP-Key' => $this->fabricAppId,
            ]);

            if (isset($response['token'])) {
                return $response['token'];
            }

            throw new AuthenticationException("Token missing from Telebirr response.");
        } catch (\Throwable $e) {
            throw new AuthenticationException("Failed to get Telebirr token: " . $e->getMessage(), 0, $e);
        }
    }
}

<?php

declare(strict_types=1);

namespace Bekambeyene\Telebirr\Services;

use Bekambeyene\Telebirr\Exceptions\AuthenticationException;

class TokenManager
{
    protected TelebirrHttpClient $client;
    protected string $fabricAppId;
    protected string $appSecret;

    protected static ?string $memoryToken = null;
    protected static ?int $memoryTokenExpiry = null;

    public function __construct(TelebirrHttpClient $client, string $fabricAppId, string $appSecret)
    {
        $this->client = $client;
        $this->fabricAppId = $fabricAppId;
        $this->appSecret = $appSecret;
    }

    /**
     * Retrieve the Fabric token from Telebirr.
     * Caches the token to avoid exhausting rate limits.
     *
     * @return string
     * @throws AuthenticationException
     */
    public function getFabricToken(): string
    {
        $cacheKey = 'telebirr_fabric_token_' . md5($this->fabricAppId);
        
        $useLaravelCache = false;
        try {
            if (class_exists(\Illuminate\Support\Facades\Cache::class)) {
                \Illuminate\Support\Facades\Cache::getFacadeRoot();
                $useLaravelCache = true;
            }
        } catch (\Throwable $e) {
            $useLaravelCache = false;
        }

        if ($useLaravelCache) {
            $cachedToken = \Illuminate\Support\Facades\Cache::get($cacheKey);
            if ($cachedToken) {
                return $cachedToken;
            }
        } else {
            if (self::$memoryToken && self::$memoryTokenExpiry > time()) {
                return self::$memoryToken;
            }
        }

        try {
            $response = $this->client->post('/payment/v1/token', [
                'appSecret' => $this->appSecret,
            ], [
                'X-APP-Key' => $this->fabricAppId,
            ]);

            if (isset($response['token'])) {
                $token = $response['token'];
                // Telebirr tokens are typically valid for 1 hour. Cache for 55 minutes.
                $ttlSeconds = 55 * 60;

                if ($useLaravelCache) {
                    \Illuminate\Support\Facades\Cache::put($cacheKey, $token, $ttlSeconds);
                } else {
                    self::$memoryToken = $token;
                    self::$memoryTokenExpiry = time() + $ttlSeconds;
                }

                return $token;
            }

            throw new AuthenticationException("Token missing from Telebirr response.");
        } catch (\Throwable $e) {
            if ($e instanceof AuthenticationException) {
                throw $e;
            }
            throw new AuthenticationException("Failed to get Telebirr token: " . $e->getMessage(), 0, $e);
        }
    }
}

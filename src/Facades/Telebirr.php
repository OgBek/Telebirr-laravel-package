<?php

declare(strict_types=1);

namespace Bekambeyene\Telebirr\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string getFabricToken()
 * @method static string createOrder(string $title, float $amount, ?string $merchOrderId = null, array $params = [])
 * @method static array verifyPayment(string $merchantOrderId)
 * @method static array initiatePayment(string $orderId, float $amount, string $subject, array $customerInfo = [])
 * @method static bool verifyCallbackSignature(array $payload, string $signature)
 * @method static bool verifyCallbackTimestamp(array $payload, int $maxAgeSeconds = 300)
 * @method static bool verifyNonce(array $payload, int $cacheTtlSeconds = 300)
 *
 * @see \Bekambeyene\Telebirr\Contracts\TelebirrClientInterface
 * @see \Bekambeyene\Telebirr\TelebirrClient
 */
class Telebirr extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'telebirr';
    }
}

<?php

namespace Bekambeyene\Telebirr\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string getFabricToken()
 * @method static string createOrder(string $title, $amount, string|null $merchOrderId = null)
 * @method static array verifyPayment(string $merchantOrderId)
 * @method static array initiatePayment(string $orderId, $amount, string $subject, array $customerInfo = [])
 * 
 * @see \Bekambeyene\Telebirr\TelebirrClient
 */
class Telebirr extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'telebirr';
    }
}

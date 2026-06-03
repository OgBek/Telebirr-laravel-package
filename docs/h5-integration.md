# Telebirr H5 SuperApp Integration Guide

## Overview

The Telebirr H5 payment flow allows your Laravel application to seamlessly redirect users to the Telebirr SuperApp checkout environment. 

## Process Flow

1. Your application calls `Telebirr::createOrder()`
2. The SDK makes a secure, RSA-PSS signed API request to Telebirr's Preorder endpoint
3. Telebirr returns a `prepay_id`
4. The SDK generates a deterministic checkout URL containing exactly 5 signed fields
5. Your application redirects the user to this checkout URL

## Implementation

```php
use Bekambeyene\Telebirr\Facades\Telebirr;
use Bekambeyene\Telebirr\Exceptions\TelebirrServerException;

try {
    $paymentUrl = Telebirr::createOrder(
        subject: 'Premium Subscription', 
        amount: 250.00,
        outTradeNo: 'ORDER-12345' // Optional, auto-generated if omitted
    );
    
    return redirect()->away($paymentUrl);
} catch (TelebirrServerException $e) {
    // Handle Telebirr downtime gracefully
}
```

## Security Guarantees

The SDK automatically ensures that your H5 checkout URLs are perfectly signed according to Telebirr's strictest requirements, meaning you never have to worry about the dreaded `60200099 Verify the sign field failed` error when redirecting users.

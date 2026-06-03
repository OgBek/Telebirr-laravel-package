<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Telebirr API Configuration
    |--------------------------------------------------------------------------
    |
    | Here you can configure your Telebirr API credentials and endpoints.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | Set this to 'production' or 'sandbox' to automatically use the correct
    | default Telebirr API endpoints.
    |
    */
    'environment' => env('TELEBIRR_ENV', 'sandbox'),

    'base_url' => env('TELEBIRR_BASE_URL', env('TELEBIRR_ENV', 'sandbox') === 'production' 
        ? 'https://app.ethiotelebirr.et:38443/apiaccess/payment/gateway' 
        : 'https://developerportal.ethiotelebirr.et:38443/apiaccess/payment/gateway'),

    'web_url' => env('TELEBIRR_WEB_URL', env('TELEBIRR_ENV', 'sandbox') === 'production' 
        ? 'https://app.ethiotelebirr.et:38443/payment/web/paygate' 
        : 'https://developerportal.ethiotelebirr.et:38443/payment/web/paygate'),
    'ssl_verify' => env('TELEBIRR_SSL_VERIFY', true),
    'fabric_app_id' => env('TELEBIRR_FABRIC_APP_ID'),
    'app_secret' => env('TELEBIRR_APP_SECRET'),
    'merchant_app_id' => env('TELEBIRR_MERCHANT_APP_ID'),
    'merchant_code' => env('TELEBIRR_MERCHANT_CODE'),
    'notify_url' => env('TELEBIRR_NOTIFY_URL'),
    
    // Telebirr documentation uses both redirect_url and return_url; the SDK normalizes both.
    'return_url' => env('TELEBIRR_RETURN_URL', env('TELEBIRR_REDIRECT_URL')),
    'redirect_url' => env('TELEBIRR_REDIRECT_URL', env('TELEBIRR_RETURN_URL')),
    
    'public_key' => env('TELEBIRR_PUBLIC_KEY'),
    'private_key' => env('TELEBIRR_PRIVATE_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Signature Padding Mode
    |--------------------------------------------------------------------------
    |
    | Default is 'pss' (RSA-PSS) as expected by newer Telebirr environments.
    | Set to 'pkcs1' for legacy PKCS#1 v1.5 padding if required.
    |
    */
    'padding' => env('TELEBIRR_SIGNATURE_PADDING', 'pss'),

    /*
    |--------------------------------------------------------------------------
    | Webhook Clock Tolerance
    |--------------------------------------------------------------------------
    |
    | Maximum allowed age (in seconds) for webhook callback requests to 
    | protect against replay attacks. Default is 300 seconds (5 minutes).
    |
    */
    'webhook_tolerance_seconds' => (int) env('TELEBIRR_WEBHOOK_TOLERANCE_SECONDS', 300),
];

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
    'return_url' => env('TELEBIRR_RETURN_URL'),
    'public_key' => env('TELEBIRR_PUBLIC_KEY'),
    'private_key' => env('TELEBIRR_PRIVATE_KEY'),
];

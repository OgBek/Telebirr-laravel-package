<?php
// examples/raw-php-integration.php

require_once __DIR__ . '/vendor/autoload.php';

use Bekambeyene\Telebirr\Services\SignatureService;
use Bekambeyene\Telebirr\Services\TelebirrHttpClient;

/**
 * Example of using the Telebirr SDK outside of Laravel (Raw PHP).
 */

$privateKey = '-----BEGIN PRIVATE KEY-----...';
$publicKey = '-----BEGIN PUBLIC KEY-----...';

// Instantiate the Signature Service
$signatureService = new SignatureService();

$payload = [
    'appid' => 'your_app_id',
    'merch_code' => 'your_merch_code',
    'nonce_str' => uniqid(),
    'prepay_id' => '1234567890',
    'timestamp' => (string) time(),
];

// Generate an RSA-PSS Signature manually
$signature = $signatureService->signPSS($payload, $privateKey);

// Verify an RSA-PSS Signature manually
$isValid = $signatureService->verifyPSS($payload, $signature, $publicKey);

if ($isValid) {
    echo "Signature is valid!";
} else {
    echo "Invalid signature!";
}

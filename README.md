<div align="center">
  <img src="https://github.com/user-attachments/assets/187d06f5-9aaf-4edb-91aa-c31b1ebca7e6" alt="Telebirr PHP SDK" width="800">
  
  # Telebirr PHP & Laravel SDK
  
  *A fully-featured, secure, and modern PHP SDK for integrating Ethio Telecom's Telebirr SuperApp Payment Gateway.*

  [![Latest Stable Version](https://img.shields.io/packagist/v/bekambeyene/telebirr?style=for-the-badge&color=blue)](https://packagist.org/packages/bekambeyene/telebirr)
  [![Total Downloads](https://img.shields.io/packagist/dt/bekambeyene/telebirr?style=for-the-badge&color=success)](https://packagist.org/packages/bekambeyene/telebirr)
  [![License](https://img.shields.io/badge/license-MIT-green?style=for-the-badge)](https://packagist.org/packages/bekambeyene/telebirr)
  [![PHP Version Compatibility](https://img.shields.io/packagist/php-v/bekambeyene/telebirr?style=for-the-badge)](https://packagist.org/packages/bekambeyene/telebirr)
</div>

<br>

This package has been thoroughly designed to prioritize **security** and **developer experience**, providing first-class integrations, facades, and configuration builders for **Laravel 10, 11, 12, and 13**, alongside robust support for Vanilla PHP environments.

---

## ⚡ Key Features

✨ **Enterprise-Grade Security**  
- **Strict Webhook Verification:** Cryptographically validates incoming Telebirr webhooks to prevent spoofing and Man-in-the-Middle (MitM) attacks.
- **Replay Attack Protection:** Built-in Nonce and Timestamp validation caching strictly rejects duplicated callback payloads.
- **Smart Key Parsing:** Automatically detects and formats raw base64 PEM keys directly from your `.env` to prevent `phpseclib3` crashes.

🚀 **Decentralized Architecture**  
Built with single-responsibility services (`SignatureService`, `TokenManager`, `TelebirrHttpClient`) for clean integration and easy testing. Fallback multi-merchant memory isolation guarantees safe concurrent requests in Octane/Swoole.

🛠️ **Flexible Flow Support**  
- **H5 / Web Checkout Flow:** Generate payment URLs seamlessly to redirect web clients.
- **In-App Payment Flow:** Obtain raw request strings directly for integration inside mobile apps or WebViews.

---

## 📦 Compatibility & Requirements

| Requirement | Supported Versions |
| ----------- | ------------------ |
| **PHP** | `^8.2` (Fully supports PHP 8.2, 8.3, and 8.4) |
| **Laravel** | `^10.0`, `^11.0`, `^12.0`, `^13.0` |
| **Extensions** | `openssl`, `curl`, `json` |

---

## 🚀 Installation

Install the package into your project using Composer:

```bash
composer require bekambeyene/telebirr
```

---

## 🔑 Generating RSA Keys

Telebirr requires asymmetric RSA keys to securely sign requests and verify payloads. If you don't have your keys yet, follow these steps to generate them using Ethio Telecom's official tools:

1. **Download the Sign Tool:** [Download Here](https://developer.ethiotelecom.et/developer_tools/static/download/SignTool.zip)
2. **Generate the Key Pair:** Extract the `.zip` file, open the tool, and generate your RSA key pair.
3. **Save your Keys:** You will receive a **Public Key** and a **Private Key**. Provide your Public Key to Telebirr via the Developer Portal, and keep the Private Key secure within your application.

---

## 🛠️ Laravel Integration

Thanks to Laravel's Package Auto-Discovery, the Service Provider and `Telebirr` Facade are registered automatically.

### 1. Publish Configuration

```bash
php artisan vendor:publish --tag="telebirr-config"
```

### 2. Environment Configuration

Add the following credentials to your `.env` file:

```env
TELEBIRR_ENV=sandbox

TELEBIRR_FABRIC_APP_ID=your_fabric_app_id
TELEBIRR_APP_SECRET=your_app_secret
TELEBIRR_MERCHANT_APP_ID=your_merchant_app_id
TELEBIRR_MERCHANT_CODE=your_merchant_code
TELEBIRR_NOTIFY_URL=https://yourdomain.com/payment/notify
TELEBIRR_RETURN_URL=https://yourdomain.com/payment/success
```

> [!TIP]
> **Smart Key Storage Flexibility**
> To avoid multiline `.env` string issues, the SDK supports three ways to load keys:
> 1. **Raw Base64:** Paste just the raw string! The SDK automatically calculates chunking and injects `-----BEGIN PRIVATE KEY-----` boundaries for you.
> 2. **File Path:** `TELEBIRR_PRIVATE_KEY="file:///var/www/keys/private_key.pem"`
> 3. **Base64 Strict:** `TELEBIRR_PRIVATE_KEY="base64:LS0tLS1CRUdJ..."`

### 3. Usage inside Laravel Controllers

> [!CAUTION]
> **CSRF Middleware Exception Required!**
> Telebirr sends webhooks directly from its servers via a `POST` request. It does not carry a Laravel CSRF token. If you place your webhook route in `routes/web.php` without an exception, Laravel will instantly block it with a **419 Page Expired** error.
> 
> **For Laravel 11+:** Open `bootstrap/app.php` and configure the exception:
> ```php
> ->withMiddleware(function (Middleware $middleware) {
>     $middleware->validateCsrfTokens(except: [
>         'payment/notification', // Replace with your exact route URI
>     ]);
> })
> ```
> **For Laravel 10 and below:** Open `app/Http/Middleware/VerifyCsrfToken.php` and add your URI to the `$except` array.

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Bekambeyene\Telebirr\Facades\Telebirr;
use Bekambeyene\Telebirr\Exceptions\TelebirrException;
use Bekambeyene\Telebirr\Exceptions\TelebirrServerException;
use Illuminate\Support\Facades\Log;

class TelebirrPaymentController extends Controller
{
    /**
     * Start payment checkout flow
     */
    public function initiateCheckout(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'title' => 'required|string|max:100',
        ]);

        try {
            // Generate the payment redirect URL for H5 Checkout
            $paymentUrl = Telebirr::createOrder(
                $request->input('title'),
                $request->input('amount')
            );

            return redirect()->away($paymentUrl);
        } catch (TelebirrServerException $e) {
            // Handled safely: e.g. Telebirr is under maintenance (code 60200087)
            Log::error('Telebirr Servers are busy: ' . $e->getMessage());
            return back()->with('error', 'Telebirr payment gateway is currently busy. Please try again later.');
        } catch (TelebirrException $e) {
            Log::error('Telebirr Payment Config Error: ' . $e->getMessage());
            return back()->with('error', 'Unable to initiate payment.');
        }
    }

    /**
     * Handle payment notification callback (Webhook) from Telebirr
     */
    public function handleNotification(Request $request)
    {
        try {
            $payload = $request->except('sign');
            $signature = $request->input('sign');

            // 1. Securely verify the RSA-PSS webhook signature
            if (!Telebirr::verifyCallbackSignature($payload, $signature)) {
                return response('invalid signature', 403);
            }

            // 2. Protect against replay attacks by validating the timestamp
            if (!Telebirr::verifyCallbackTimestamp($payload)) {
                return response('request expired', 403);
            }

            // 3. Deduplicate the request using the nonce
            if (!Telebirr::verifyNonce($payload, 300, strict: true)) {
                return response('request already processed', 403);
            }

            $tradeStatus = $request->input('trade_status');
            
            if ($tradeStatus === 'PAY_SUCCESS') {
                // Securely process order!
            }

            return response('success');

        } catch (\Exception $e) {
            Log::error('Telebirr Webhook Parsing Error: ' . $e->getMessage());
            return response('error', 500);
        }
    }
}
```

---

## 🐘 Vanilla PHP Integration

You can easily use the SDK in native PHP applications without Laravel.

```php
<?php

require 'vendor/autoload.php';

use Bekambeyene\Telebirr\TelebirrClient;
use Bekambeyene\Telebirr\Services\TokenManager;
use Bekambeyene\Telebirr\Services\SignatureService;
use Bekambeyene\Telebirr\Services\TelebirrHttpClient;

$config = [
    'environment' => 'sandbox',
    'fabric_app_id' => 'your_fabric_app_id',
    'app_secret' => 'your_app_secret',
    'merchant_app_id' => 'your_merchant_app_id',
    'merchant_code' => 'your_merchant_code',
    'notify_url' => 'https://yourdomain.com/payment/notify',
    'return_url' => 'https://yourdomain.com/payment/success',
    'public_key' => '...',
    'private_key' => '...'
];

$httpClient = new TelebirrHttpClient($config['base_url'], true);
$tokenManager = new TokenManager($httpClient, $config['fabric_app_id'], $config['app_secret']);
$signatureService = new SignatureService();

$telebirr = new TelebirrClient($config, $tokenManager, $signatureService, $httpClient);

$paymentUrl = $telebirr->createOrder('Standard Plan Subscription', 250.00);
header('Location: ' . $paymentUrl);
exit;
```

---

## 🛡️ Security Best Practices

When integrating payments, please follow these critical guidelines:

1. **Replay Attack Mitigation (Nonces & Timestamps):**
   Telebirr webhooks can theoretically be captured and replayed. Always use both `$telebirr->verifyCallbackTimestamp($payload)` (ensures the request isn't stale) and `$telebirr->verifyNonce($payload, strict: true)` (uses Laravel Cache to guarantee the unique `nonce_str` hasn't been seen recently) before processing a webhook.
   
2. **Handle Telebirr Service Outages Gracefully:**
   During Ethio Telecom sandbox maintenance, you might encounter `Organization does not exist` errors (Code `60200087`). Catch `TelebirrServerException` explicitly to show a friendly "Try again later" message to your users instead of crashing.

---

## 🔗 Links

- [Telebirr Developer Portal](https://developer.ethiotelecom.et/)
- [Telebirr H5 C2B Integration Guide](https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/requestCreateOrder)

## 📄 License

This SDK is open-sourced software licensed under the [MIT License](LICENSE).

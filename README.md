<div align="center">
  <img src="https://github.com/user-attachments/assets/187d06f5-9aaf-4edb-91aa-c31b1ebca7e6" alt="Telebirr PHP SDK" width="800">
  
  # Telebirr PHP & Laravel SDK
  
  *A deterministic, secure, and production-hardened PHP SDK for integrating Ethio Telecom's Telebirr SuperApp Payment Gateway.*

  [![Latest Stable Version](https://img.shields.io/packagist/v/bekambeyene/telebirr?style=for-the-badge&color=blue)](https://packagist.org/packages/bekambeyene/telebirr)
  [![Total Downloads](https://img.shields.io/packagist/dt/bekambeyene/telebirr?style=for-the-badge&color=success)](https://packagist.org/packages/bekambeyene/telebirr)
  [![License](https://img.shields.io/badge/license-MIT-green?style=for-the-badge)](https://packagist.org/packages/bekambeyene/telebirr)
  [![PHP Version Compatibility](https://img.shields.io/packagist/php-v/bekambeyene/telebirr?style=for-the-badge)](https://packagist.org/packages/bekambeyene/telebirr)
</div>

<br>

This package prioritizes **exact interoperability, determinism, and maintainability** to guarantee seamless operation in both Telebirr sandbox and production environments. It supports **Laravel 10, 11, 12, and 13**, alongside Vanilla PHP environments.

---

## ⚡ Key Features

✨ **Production-Grade Webhook Handling**  
- **Unified Webhook Verification:** Parse and verify incoming webhook requests automatically via `Telebirr::handleWebhook(Request $request)`.
- **Clock Drift & Replay Protection:** Enforces strict validation of request age and tracks nonces via Laravel's cache (using the configured tolerance TTL) to shut down replay attacks.

🚀 **Configurable Cryptography & Padding**  
- **RSA-PSS Default Padding:** Preorder requests, H5 URLs, and webhook signatures default to modern RSA-PSS.
- **Legacy PKCS#1 v1.5 Support:** Optionally switch padding modes via environment configuration for legacy request string flows or compatibility.

🛠 **Robust Canonicalization & Smart Keys**  
- **Stable Sorting:** Recursively and deterministically sorts parameter structures, preventing PHP hash-order discrepancies.
- **Robust Key Loading:** Automatically processes raw, base64-encoded, or file path PEM keys without crashing.

---

## 📦 Compatibility & Requirements

| Requirement | Supported Versions |
| ----------- | ------------------ |
| **PHP** | `^8.2` (PHP 8.2, 8.3, and 8.4) |
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

Telebirr requires asymmetric RSA keys to sign requests and verify payloads.
1. **Download the Sign Tool:** [Download Here](https://developer.ethiotelecom.et/developer_tools/static/download/SignTool.zip)
2. **Generate Key Pair:** Generate RSA keys. Provide your Public Key to Telebirr via the Developer Portal, and keep your Private Key private.
3. **Smart Key Loading:** Paste the raw key base64 string directly in `.env`. The SDK automatically chunk-splits and formats it with standard PEM headers.

---

## 🛠 Laravel Configuration & Integration

Publish the configuration file:

```bash
php artisan vendor:publish --tag="telebirr-config"
```

Add your credentials to your `.env` file:

```env
TELEBIRR_ENV=sandbox

TELEBIRR_FABRIC_APP_ID=your_fabric_app_id
TELEBIRR_APP_SECRET=your_app_secret
TELEBIRR_MERCHANT_APP_ID=your_merchant_app_id
TELEBIRR_MERCHANT_CODE=your_merchant_code
TELEBIRR_NOTIFY_URL=https://yourdomain.com/payment/notify

# Supports both redirect_url and return_url configurations (SDK normalizes both)
TELEBIRR_RETURN_URL=https://yourdomain.com/payment/success

# Cryptography Settings (pss or pkcs1)
TELEBIRR_SIGNATURE_PADDING=pss

# Webhook clock tolerance in seconds
TELEBIRR_WEBHOOK_TOLERANCE_SECONDS=300
```

---

## ⚖ Correct Signing & Interoperability Rules

Different Telebirr endpoints use different signature generation rules:

### 1. Preorder Request Signing
Preorder requests (`payment.preorder`) compile top-level properties and recursively sort all parameters (including `biz_content` keys which are flattened and promoted to the root during canonicalization). Signatures use the configured padding (`pss` by default).

### 2. H5 Web Checkout Checkout URL Signing
When launching H5 web checkout redirects, Telebirr expects the URL signature to be calculated on **EXACTLY** 5 fields:
- `appid`
- `merch_code`
- `nonce_str`
- `prepay_id`
- `timestamp`

> [!WARNING]
> Do **NOT** sign `version`, `trade_type`, `sign_type`, or `redirect_url`. These optional query parameters must be appended to the redirect URL *after* generating the signature. Adding them to the signed payload will cause intermittent signature failures (error `60200099`).

### 3. URL Encoding Mechanics
- **Canonical String:** The sorted key-value payload constructed for signature signing/verification is **never** URL-encoded.
- **Redirection URL:** Query parameters appended to the H5 redirection URL must be URL-encoded (using `urlencode`) before the client is redirected.

---

## 🛠 Usage in Laravel

### 1. CSRF Exception Required
Telebirr sends webhooks directly via POST request. You must bypass CSRF verification for this endpoint.

**Laravel 11+ (bootstrap/app.php):**
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: [
        'payment/notification', // Adjust to your route path
    ]);
})
```

### 2. Controller Implementation

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Bekambeyene\Telebirr\Facades\Telebirr;
use Bekambeyene\Telebirr\Exceptions\TelebirrException;
use Bekambeyene\Telebirr\Exceptions\TelebirrServerException;
use Bekambeyene\Telebirr\Exceptions\InvalidSignatureException;
use Bekambeyene\Telebirr\Exceptions\TimestampExpiredException;
use Bekambeyene\Telebirr\Exceptions\ReplayAttackException;
use Illuminate\Support\Facades\Log;

class TelebirrPaymentController extends Controller
{
    /**
     * Redirect to Telebirr Checkout
     */
    public function checkout(Request $request)
    {
        try {
            $paymentUrl = Telebirr::createOrder('Premium Subscription', 250.00);
            return redirect()->away($paymentUrl);
        } catch (TelebirrServerException $e) {
            // Gracefully catch Telebirr busy/sync status issues
            Log::warning('Telebirr server status exception: ' . $e->getMessage());
            return back()->with('error', 'Telebirr payment services are currently busy. Please try again in a few moments.');
        } catch (TelebirrException $e) {
            Log::error('Telebirr config error: ' . $e->getMessage());
            return back()->with('error', 'Failed to initiate payment.');
        }
    }

    /**
     * Handle incoming Telebirr Webhook
     */
    public function handleWebhook(Request $request)
    {
        try {
            // Automatically validates signature, timestamp fresh window, and checks duplicate nonces
            $payload = Telebirr::handleWebhook($request);

            // Process order securely (e.g. mark paid)
            $orderId = $payload['merch_order_id'];
            $status = $payload['trade_status'];

            if ($status === 'PAY_SUCCESS') {
                // Activate service...
            }

            return response('success');

        } catch (InvalidSignatureException $e) {
            Log::error('Webhook Invalid Signature: ' . $e->getMessage());
            return response('invalid signature', 403);
        } catch (TimestampExpiredException $e) {
            Log::error('Webhook Request Expired (stale timestamp): ' . $e->getMessage());
            return response('request expired', 403);
        } catch (ReplayAttackException $e) {
            Log::error('Replay Attack detected: ' . $e->getMessage());
            return response('request already processed', 409);
        } catch (\Exception $e) {
            Log::error('Webhook handling failed: ' . $e->getMessage());
            return response('error', 500);
        }
    }
}
```

---

## 🔍 Troubleshooting Common Errors

### `60200099 Verify the sign field failed`
This error means the public key on Telebirr's server cannot verify the signature generated by your private key.
- **Signed Field List:** Check that H5 signatures only include the 5 required fields. The SDK enforces this automatically.
- **Padding Mode mismatch:** Telebirr production requires `pss` (RSA-PSS) padding. Ensure `TELEBIRR_SIGNATURE_PADDING` matches your gateway settings.
- **Accidental double encoding:** Ensure you do not URL-encode the parameters twice when formatting query strings.
- **Raw key format:** Ensure your private key matches the public key uploaded to the Telebirr developer portal.

### `60200087 Organization does not exist`
- This status indicates the Telebirr gateway/merchant sync services are busy, down, or undergoing synchronization. Always catch `TelebirrServerException` and prompt users to retry.

---

## 🛡 Production Best Practices

- **Clock Synchronization (NTP):** Ensure clock synchronization is enabled on your production servers. Webhook timestamp verification relies on synchronized times.
- **Idempotency & Database Locks:** Acquire database locks on transactions during callback handling to prevent duplicate order allocation if a webhook triggers concurrently.
- **Sandbox vs Production Differences:** Sandboxes are often more permissive than production systems. Always test your signature configurations in both systems.

---

## 📄 License

This SDK is open-sourced software licensed under the [MIT License](LICENSE).

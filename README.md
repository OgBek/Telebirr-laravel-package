<div align="center">
  <img src="https://github.com/user-attachments/assets/187d06f5-9aaf-4edb-91aa-c31b1ebca7e6" alt="Telebirr PHP SDK" width="800">
  
  # Telebirr PHP & Laravel SDK
  
  *A Laravel and PHP SDK for Telebirr H5/SuperApp integration with webhook verification, RSA signing, and production-ready interoperability support.*

  [![Latest Stable Version](https://img.shields.io/packagist/v/bekambeyene/telebirr?style=for-the-badge&color=blue)](https://packagist.org/packages/bekambeyene/telebirr)
  [![Total Downloads](https://img.shields.io/packagist/dt/bekambeyene/telebirr?style=for-the-badge&color=success)](https://packagist.org/packages/bekambeyene/telebirr)
  [![License](https://img.shields.io/badge/license-MIT-green?style=for-the-badge)](https://packagist.org/packages/bekambeyene/telebirr)
  [![PHP Version Compatibility](https://img.shields.io/packagist/php-v/bekambeyene/telebirr?style=for-the-badge)](https://packagist.org/packages/bekambeyene/telebirr)
</div>

<br>

This package solves the complex implementation details of Ethiopia's payment gateway. It prioritizes exact interoperability, determinism, and maintainability to guarantee seamless operation in both Telebirr sandbox and production environments. It supports Laravel 12 and 13, alongside Vanilla PHP environments.

---

## 🎨 Features

✨ **Production-Grade Webhook Handling**  
- **Unified Webhook Verification:** Parse and verify incoming webhook requests automatically via `Telebirr::handleWebhook(Request $request)`.
- **Clock Drift & Replay Protection:** Enforces strict validation of request age and tracks nonces via Laravel's cache.

🚀 **Configurable Cryptography & Padding**  
- **RSA-PSS Default Padding:** Preorder requests, H5 URLs, and webhook signatures default to modern RSA-PSS.
- **Legacy PKCS#1 v1.5 Support:** Optionally switch padding modes.

🛠 **Robust Canonicalization & Smart Keys**  
- **Stable Sorting:** Recursively and deterministically sorts parameter structures, preventing PHP hash-order discrepancies.
- **Smart Key Storage Flexibility:** Automatically processes raw strings, base64-encoded, or file path PEM keys (`file:///path/to/key.pem`) without crashing.

---

## 📦 Installation

Install the package into your project using Composer:

```bash
composer require bekambeyene/telebirr
```

Publish the configuration file (Laravel):

```bash
php artisan vendor:publish --tag="telebirr-config"
```

---

## ⚡ Quick Start

Add your credentials to your `.env` file:

```env
TELEBIRR_ENV=sandbox
TELEBIRR_SSL_VERIFY=false

TELEBIRR_FABRIC_APP_ID=your_fabric_app_id
TELEBIRR_APP_SECRET=your_app_secret
TELEBIRR_MERCHANT_APP_ID=your_merchant_app_id
TELEBIRR_MERCHANT_CODE=your_merchant_code
TELEBIRR_NOTIFY_URL=https://yourdomain.com/payment/notify
TELEBIRR_RETURN_URL=https://yourdomain.com/payment/success

# Cryptography Settings (pss or pkcs1)
TELEBIRR_SIGNATURE_PADDING=pss
```

> [!TIP]
> 💡 **Smart Key Storage Flexibility**
> To avoid multiline `.env` string issues, the SDK supports three ways to load keys:
> - **Raw Base64**: Paste just the raw string! The SDK automatically calculates chunking and injects `-----BEGIN PRIVATE KEY-----` boundaries for you.
> - **File Path**: `TELEBIRR_PRIVATE_KEY="file:///var/www/keys/private_key.pem"`
> - **Base64 Strict**: `TELEBIRR_PRIVATE_KEY="base64:LS0tLS1CRUdJ..."`

> [!TIP]
> 💡 **Sandbox SSL Issue**
> Adding `TELEBIRR_SSL_VERIFY=false` to your `.env` file resolves the "unable to get local issuer certificate" error when connecting to the Telebirr sandbox API.

---

##  H5 Payment Controller Example

Below is the recommended controller code you should use when integrating our package.

```php
<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Bekambeyene\Telebirr\Facades\Telebirr;
use Bekambeyene\Telebirr\Exceptions\TelebirrException;
use Bekambeyene\Telebirr\Exceptions\TelebirrServerException;
use Bekambeyene\Telebirr\Exceptions\InvalidSignatureException;
use Bekambeyene\Telebirr\Exceptions\TimestampExpiredException;
use Bekambeyene\Telebirr\Exceptions\ReplayAttackException;

class PaymentController extends Controller
{
    /**
     * Show the checkout form (title + amount).
     */
    public function showForm()
    {
        return view('payment.form');
    }

    /**
     * Initiate an H5 payment and redirect the user.
     */
    public function checkout(Request $request)
    {
        $validated = $request->validate([
            'title'  => ['required', 'string', 'min:1', 'max:64'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $title  = trim($validated['title']);
        $amount = (float) $validated['amount'];

        try {
            // Telebirr requires merch_order_id to match ^[A-Za-z0-9]+$ — no hyphens or underscores.
            $merchOrderId = 'ORDER' . strtoupper(uniqid());

            $paymentUrl = Telebirr::createOrder($title, $amount, $merchOrderId);

            Log::info('Redirecting to Telebirr H5', [
                'merch_order_id' => $merchOrderId,
                'title'          => $title,
                'amount'         => $amount,
                'url_host'       => parse_url($paymentUrl, PHP_URL_HOST),
            ]);

            return redirect()->away($paymentUrl);

        } catch (TelebirrServerException $e) {
            Log::warning('Telebirr server status exception: ' . $e->getMessage());
            return response()->view('payment.error', [
                'title'   => 'Telebirr is busy',
                'message' => 'Telebirr payment services are currently busy. Please try again in a few moments.',
                'detail'  => $e->getMessage(),
            ], 503);
        } catch (TelebirrException $e) {
            Log::error('Telebirr config error: ' . $e->getMessage());
            return response()->view('payment.error', [
                'title'   => 'Payment failed',
                'message' => 'Failed to initiate payment.',
                'detail'  => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle asynchronous webhook (notify_url) from Telebirr.
     * Telebirr POSTs here after a payment attempt.
     */
    public function notification(Request $request)
    {
        try {
            $payload = Telebirr::handleWebhook($request);

            $merchOrderId = $payload['merch_order_id'] ?? null;
            $tradeStatus  = $payload['trade_status'] ?? $payload['result'] ?? null;

            Log::info('Telebirr webhook received', [
                'merch_order_id' => $merchOrderId,
                'trade_status'   => $tradeStatus,
            ]);

            return response('success', 200);

        } catch (InvalidSignatureException $e) {
            Log::error('Telebirr webhook signature invalid: ' . $e->getMessage());
            return response('invalid signature', 400);
        } catch (TimestampExpiredException $e) {
            Log::error('Telebirr webhook timestamp expired: ' . $e->getMessage());
            return response('expired', 400);
        } catch (ReplayAttackException $e) {
            Log::error('Telebirr webhook replay detected: ' . $e->getMessage());
            return response('replay', 400);
        } catch (TelebirrException $e) {
            Log::error('Telebirr webhook error: ' . $e->getMessage());
            return response('error', 400);
        }
    }

    /**
     * Browser redirect target after the user completes (or cancels) payment.
     */
    public function success(Request $request)
    {
        return response()->view('payment.success', [
            'merch_order_id' => $request->query('merch_order_id'),
        ]);
    }
}
```

---
## routes/web.php  example
```php
Route::get('/',  [PaymentController::class, 'showForm'])->name('payment.form');
Route::post('/payment/checkout',     [PaymentController::class, 'checkout'])->name('payment.checkout');
Route::post('/payment/notification', [PaymentController::class, 'notification'])->name('payment.notification');
Route::get('/payment/success',       [PaymentController::class, 'success'])->name('payment.success');

]);
```
---
## 🛡️ Webhook Verification

> [!CAUTION]
> 🚨 **<span style="color:red">CSRF Middleware Exception Required!</span>**
> Telebirr sends webhooks directly from its servers via a POST request. It does not carry a Laravel CSRF token. If you place your webhook route in `routes/web.php` without an exception, Laravel will instantly block it with a 419 Page Expired error.
> 
> **For Laravel 12+:** In `bootstrap/app.php`:
> `$middleware->validateCsrfTokens(except: ['payment/notification']);`
>
> **For older projects (Laravel 11 and lower):** In `app/Http/Middleware/VerifyCsrfToken.php`:
> `protected $except = ['payment/notification'];`

```php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Bekambeyene\Telebirr\Facades\Telebirr;
use Bekambeyene\Telebirr\Exceptions\InvalidSignatureException;
use Bekambeyene\Telebirr\Exceptions\TimestampExpiredException;
use Bekambeyene\Telebirr\Exceptions\ReplayAttackException;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handle(Request $request)
    {
        try {
            // Automatically validates signature, timestamp fresh window, and checks duplicate nonces
            $payload = Telebirr::handleWebhook($request);

            if ($payload['trade_status'] === 'PAY_SUCCESS') {
                // Process order securely...
            }

            return response('success');
            
        } catch (InvalidSignatureException $e) {
            Log::error('Webhook Invalid Signature: ' . $e->getMessage());
            return response('invalid signature', 403);
        } catch (TimestampExpiredException $e) {
            Log::error('Webhook Request Expired: ' . $e->getMessage());
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

## 🔍 Signature Troubleshooting

Different Telebirr endpoints use different signature generation rules:

### 1. Preorder Request Signing
Preorder requests (`payment.preorder`) compile top-level properties and recursively sort all parameters. Signatures use the configured padding (`pss` by default).

### 2. H5 Web Checkout Checkout URL Signing
When launching H5 web checkout redirects, Telebirr expects the URL signature to be calculated on **EXACTLY** 5 fields:
- `appid`, `merch_code`, `nonce_str`, `prepay_id`, `timestamp`.

> [!WARNING]
> ⚠️ **Do NOT sign `version`, `trade_type`, `sign_type`, or `redirect_url`.** These optional query parameters must be appended to the redirect URL *after* generating the signature. Adding them to the signed payload will cause intermittent signature failures (error `60200099`).

---

## ❌ Common Errors

### `60200099 Verify the sign field failed`
This error means the public key on Telebirr's server cannot verify the signature generated by your private key.
- **Signed Field List:** Check that H5 signatures only include the 5 required fields.
- **Padding Mode mismatch:** Telebirr production requires `pss` (RSA-PSS) padding. Ensure `TELEBIRR_SIGNATURE_PADDING` matches your gateway settings.
- **Accidental double encoding:** Ensure you do not URL-encode the parameters twice.

### `60200087 Organization does not exist`
This status indicates the Telebirr gateway/merchant sync services are busy, down, or undergoing synchronization. Always catch `TelebirrServerException` and prompt users to retry.

---

## ✅ Production Best Practices

- **Clock Synchronization (NTP):** Ensure clock synchronization is enabled on your production servers.
- **Idempotency & Database Locks:** Acquire database locks on transactions during callback handling.
- **Sandbox vs Production Differences:** Sandboxes are often more permissive than production systems.

---

## 🧪 Testing

Run the tests with:
```bash
composer test
```
---
> [!CAUTION]
> ### 🛡️ Production Use Requires Care
> This SDK is actively maintained, but it has **not yet undergone extensive real-world production validation**.
>
> While it may work correctly in development and staging environments, you should **not assume production-grade reliability by default**.
>
> Before deploying to production, it is strongly recommended to:
>
> - Audit the codebase and dependencies
> - Test against real Telebirr workflows and edge cases
> - Validate callback, retry, and failure-handling behavior
> - Monitor transactions and logs closely
> - Keep fallback and recovery mechanisms in place
>
> Production deployment should always include your own security review, operational safeguards, and risk assessment.
---

# ❓ FAQ

> [!QUESTION]
>
> ### 🔐 Does Telebirr use RSA-PSS or PKCS1?
>
> By default, newer Telebirr integrations use **RSA-PSS** signatures.
>
> If your merchant configuration still requires PKCS1 v1.5, you can switch modes using:
>
> ```env
> TELEBIRR_SIGNATURE_PADDING=pkcs1
> ```

---

> [!WARNING]
>
> ### 🚨 Why am I getting `60200099`?
>
> This error usually indicates a **signature verification failure**.
>
> Common causes include:
>
> * Wrong fields included in the signed payload
> * Incorrect field ordering
> * RSA padding mismatch (`PSS` vs `PKCS1`)
> * Invalid merchant/public/private keys
> * Modified callback payloads
>
> See the **Common Errors** section for detailed troubleshooting steps.

---

> [!TIP]
>
> ### 🪝 How do I verify webhooks?
>
> Use the built-in webhook handler:
>
> ```php
> Telebirr::handleWebhook($request);
> ```
>
> It automatically performs:
>
> * Deterministic payload canonicalization
> * RSA signature verification
> * Nonce replay protection
> * Timestamp validation

---

> [!TIP]
>
> ### 🌐 How do I use Telebirr H5 in Laravel?
>
> Generate the checkout URL:
>
> ```php
> $url = Telebirr::createOrder('Order Title', $amount);
> ```
>
> Then redirect the user:
>
> ```php
> return redirect()->away($url);
> ```

---

> [!NOTE]
>
> ### ⚙️ Can I use this package without Laravel?
>
> Yes.
>
> Core services like:
>
> * `SignatureService`
> * `TelebirrHttpClient`
>
> are framework-agnostic and can be used independently in any PHP application.

---

# 📄 License

> [!IMPORTANT]
>
> ### MIT License
>
> This SDK is released under the **MIT License**.
>
> You are free to use, modify, distribute, and integrate it into both personal and commercial projects.

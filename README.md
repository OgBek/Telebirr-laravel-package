# Telebirr PHP & Laravel SDK (`bekambeyene/telebirr`)

[![Latest Stable Version](https://img.shields.io/packagist/v/bekambeyene/telebirr.svg?maxAge=0&v=4)](https://packagist.org/packages/bekambeyene/telebirr)
[![Total Downloads](https://img.shields.io/packagist/dt/bekambeyene/telebirr.svg?maxAge=0&v=4)](https://packagist.org/packages/bekambeyene/telebirr)
[![License](https://img.shields.io/badge/license-MIT-green.svg?maxAge=0&v=4)](https://packagist.org/packages/bekambeyene/telebirr)
[![PHP Version Compatibility](https://img.shields.io/packagist/php-v/bekambeyene/telebirr.svg?maxAge=0&v=4)](https://packagist.org/packages/bekambeyene/telebirr)
<img width="1280" height="320" alt="telebanner" src="https://github.com/user-attachments/assets/187d06f5-9aaf-4edb-91aa-c31b1ebca7e6" />

A fully-featured, secure, and modern PHP SDK for integrating Ethio Telecom's **Telebirr SuperApp Payment Gateway**. 

This package has been thoroughly redesigned to prioritize security, providing first-class integrations, facades, and configuration builders for **Laravel 10, 11, 12, and 13**, alongside robust support for Vanilla PHP environments.

---

## ⚡ Key Features

- **Decentralized Architecture:** Built with single-responsibility services (`SignatureService`, `TokenManager`, `TelebirrHttpClient`) for clean integration and easy testing.
- **Strict Security & SSL Enforcement:** Ensures enterprise-grade security by enforcing transport-layer SSL verification.
- **Advanced Cryptography:** 
  - **Request Signing:** Handles RSA signature generation using **SHA256 with RSA-PSS padding** (with SHA256 MGF1 hash configuration) as strictly required by Telebirr.
  - **Webhook Verification:** Cryptographically validates incoming Telebirr webhooks to prevent spoofing and Man-in-the-Middle (MitM) attacks.
  - **H5 Redirect URL:** Generates secure `PKCS#1` signatures for the web gateway payload.
- **Flexible Flow Support:**
  - **H5 / Web Checkout Flow:** Generate payment URLs seamlessly to redirect web clients.
  - **In-App Payment Flow:** Obtain raw request strings directly for integration inside mobile apps or WebViews.
- **Zero-Dependency Laravel Bridge:** Includes a built-in Service Provider and Facade that auto-discovers on installation.

---

## 📦 Compatibility & Requirements

- **PHP:** `^8.2` (Fully supports PHP 8.2, 8.3, and 8.4)
- **Laravel Framework:** `^10.0 || ^11.0 || ^12.0 || ^13.0`
- **Extensions Needed:** `openssl`, `curl`, `json`

---

## 🚀 Installation

Install the package into your project using Composer:

```bash
composer require bekambeyene/telebirr
```

---

## 🔑 Generating RSA Keys

Telebirr requires asymmetric RSA keys to securely sign requests and verify payloads. If you don't have your keys yet, follow these steps to generate them using Ethio Telecom's official tools:

1. **Download the Sign Tool:** 
   Download the official Telebirr Key Generation tool here: 
   [https://developer.ethiotelecom.et/developer_tools/static/download/SignTool.zip](https://developer.ethiotelecom.et/developer_tools/static/download/SignTool.zip)
2. **Generate the Key Pair:**
   Extract the `.zip` file, open the tool, and generate your RSA key pair.
3. **Save your Keys:** 
   You will receive a **Public Key** and a **Private Key**. You must provide your Public Key to Telebirr via the Developer Portal, and keep the Private Key secure within your application environment.

---

## 🛠️ Laravel Integration

Thanks to Laravel's Package Auto-Discovery, the Service Provider and `Telebirr` Facade are registered automatically.

### 1. Publish Configuration

Publish the configuration file using Artisan:

```bash
php artisan vendor:publish --tag="telebirr-config"
```

This creates a default config file at `config/telebirr.php`.

### 2. Environment Configuration

Add the following credentials provided by Ethio Telecom to your `.env` file:

```env
TELEBIRR_ENV=sandbox

TELEBIRR_FABRIC_APP_ID=your_fabric_app_id
TELEBIRR_APP_SECRET=your_app_secret
TELEBIRR_MERCHANT_APP_ID=your_merchant_app_id
TELEBIRR_MERCHANT_CODE=your_merchant_code
TELEBIRR_NOTIFY_URL=https://yourdomain.com/payment/notify
TELEBIRR_RETURN_URL=https://yourdomain.com/payment/success
TELEBIRR_PUBLIC_KEY="-----BEGIN PUBLIC KEY-----
YOUR_TELEBIRR_PUBLIC_KEY_HERE
-----END PUBLIC KEY-----"
TELEBIRR_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----
YOUR_MERCHANT_PRIVATE_KEY_HERE
-----END PRIVATE KEY-----"
```

### 3. Usage inside Laravel Controllers

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Bekambeyene\Telebirr\Facades\Telebirr;
use Bekambeyene\Telebirr\Exceptions\TelebirrException;
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
            // 1. Generate the payment redirect URL for H5 Checkout
            $paymentUrl = Telebirr::createOrder(
                $request->input('title'),
                $request->input('amount')
            );

            // 2. Redirect the customer to Telebirr Gateway
            return redirect()->away($paymentUrl);
        } catch (TelebirrException $e) {
            Log::error('Telebirr Payment Error: ' . $e->getMessage());
            return back()->with('error', 'Unable to initiate payment. Please try again.');
        }
    }

    /**
     * Handle payment notification callback (Webhook) from Telebirr
     */
    public function handleNotification(Request $request)
    {
        Log::info('Telebirr Webhook Payload Received: ', $request->all());

        try {
            $payload = $request->except('sign');
            $signature = $request->input('sign');

            // Securely verify the RSA-PSS webhook signature
            $isValid = Telebirr::verifyCallbackSignature($payload, $signature);

            if (!$isValid) {
                Log::warning('Telebirr Webhook Signature Verification Failed!');
                return response('invalid signature', 403);
            }

            // Protect against replay attacks by validating the timestamp
            if (!Telebirr::verifyCallbackTimestamp($payload)) {
                Log::warning('Telebirr Webhook Timestamp Expired or Missing!');
                return response('request expired', 403);
            }

            $tradeStatus = $request->input('trade_status');
            $merchantOrderId = $request->input('merch_order_id');

            if ($tradeStatus === 'PAY_SUCCESS') {
                // Update order status in your database securely
                // e.g. Order::where('order_id', $merchantOrderId)->update(['status' => 'paid']);
            }

            return response('success'); // Telebirr expects 'success' response

        } catch (\Exception $e) {
            Log::error('Telebirr Webhook Parsing Error: ' . $e->getMessage());
            return response('error', 500);
        }
    }

    /**
     * Verify payment status manually (e.g. on return/success page redirect)
     */
    public function verifyStatus(Request $request)
    {
        $merchantOrderId = $request->query('track_number');

        if (!$merchantOrderId) {
            return redirect('/')->with('error', 'Missing order reference.');
        }

        $result = Telebirr::verifyPayment($merchantOrderId);

        if ($result['success']) {
            $status = $result['status']; // e.g. "pay_success", "fail", etc.
            return view('payment.success', ['status' => $status, 'details' => $result['raw_response']]);
        }

        return redirect('/')->with('error', 'Verification failed: ' . $result['message']);
    }
}
```

---

## 🐘 Vanilla PHP Integration (Framework-Agnostic)

You can easily use the SDK in native PHP applications. Because the new architecture relies on specialized services, you simply instantiate and inject the dependencies into the main `TelebirrClient`.

### 1. Initialize the Client

```php
<?php

require 'vendor/autoload.php';

use Bekambeyene\Telebirr\TelebirrClient;
use Bekambeyene\Telebirr\Services\TokenManager;
use Bekambeyene\Telebirr\Services\SignatureService;
use Bekambeyene\Telebirr\Services\TelebirrHttpClient;

$config = [
    'environment' => 'sandbox', // or 'production'
    'fabric_app_id' => 'your_fabric_app_id',
    'app_secret' => 'your_app_secret',
    'merchant_app_id' => 'your_merchant_app_id',
    'merchant_code' => 'your_merchant_code',
    'notify_url' => 'https://yourdomain.com/payment/notify',
    'return_url' => 'https://yourdomain.com/payment/success',
    'public_key' => '-----BEGIN PUBLIC KEY-----
YOUR_TELEBIRR_PUBLIC_KEY
-----END PUBLIC KEY-----',
    'private_key' => '-----BEGIN PRIVATE KEY-----
YOUR_PRIVATE_KEY
-----END PRIVATE KEY-----'
];

// Optional: Provide custom logging callback
$logger = function (string $message, string $level = 'info', array $context = []) {
    error_log("[Telebirr SDK][$level] $message " . json_encode($context));
};

// 1. Initialize the HTTP Client (Base URL, Verify SSL = true)
$httpClient = new TelebirrHttpClient($config['base_url'], true);

// 2. Initialize the Token Manager
$tokenManager = new TokenManager($httpClient, $config['fabric_app_id'], $config['app_secret']);

// 3. Initialize the Signature Service
$signatureService = new SignatureService();

// 4. Boot the Telebirr Orchestrator Client
$telebirr = new TelebirrClient($config, $tokenManager, $signatureService, $httpClient, $logger);
```

### 2. Standard H5 / Web Checkout Flow

```php
try {
    $paymentUrl = $telebirr->createOrder('Standard Plan Subscription', 250.00);
    
    // Redirect customer to the secure Telebirr gateway
    header('Location: ' . $paymentUrl);
    exit;
} catch (Exception $e) {
    echo "Payment failed to initialize: " . $e->getMessage();
}
```

### 3. Mobile App InApp / WebView Flow

If you are requesting the payload for a Mobile App SDK (InApp) and want the raw query string instead of the Web Checkout redirect URL:

```php
try {
    // Generate raw request string for Mobile Client SDK evaluation
    $rawRequestString = $telebirr->createOrder('InApp Purchase', 120.00, null, [
        'trade_type' => 'InApp',
        'raw_request' => true
    ]);
    
    header('Content-Type: application/json');
    echo json_encode(['raw_request_string' => $rawRequestString]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
```

### 4. Direct Status Verification

```php
$merchantOrderId = '1780080424282';
$result = $telebirr->verifyPayment($merchantOrderId);

if ($result['success']) {
    echo "Status: " . $result['status']; // e.g. "pay_success"
    print_r($result['raw_response']);
} else {
    echo "Verification failure: " . $result['message'];
}
```

---

## 🔒 Security, Signing and Padding Details

The Telebirr Gateway utilizes a complex dual signing architecture. Our `SignatureService` handles this securely under the hood:
1. **Pre-order API Request Signature:** Signed with **RSA-PSS Padding** with SHA256 hash & SHA256 Mask Generation Function (`withMGFHash('sha256')`).
2. **Webhook Verification:** Incoming callbacks are cryptographically verified using **RSA-PSS Padding** against the provided Public Key.
3. **Web Paygate Redirect URL Signature:** Uses **RSA-PKCS1 Padding** on the web payload variables (`appid`, `merch_code`, `nonce_str`, `prepay_id`, `timestamp`, `sign_type`), while leaving structural parameters like `version` and `trade_type` appended to the URL securely.

This SDK dynamically abstracts all these complexities via `phpseclib3`.

---
## Default endpoints used by the library:

By setting `TELEBIRR_ENV=sandbox` or `TELEBIRR_ENV=production` in your `.env` (or config array in Vanilla PHP), the SDK automatically routes to the correct Telebirr endpoints:

**Sandbox (Test) Endpoints:**
- API: https://developerportal.ethiotelebirr.et:38443/apiaccess/payment/gateway
- Web Checkout Redirect: https://developerportal.ethiotelebirr.et:38443/payment/web/paygate?

**Production Endpoints:**
- API: https://app.ethiotelebirr.et:38443/apiaccess/payment/gateway
- Web Checkout Redirect: https://app.ethiotelebirr.et:38443/payment/web/paygate?
  
## 🔗 Links

- [Telebirr Developer Portal](https://developer.ethiotelecom.et/)
- [Telebirr H5 C2B Integration Guide](https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/requestCreateOrder)

## 📞 Support & Contacts

- 📧 **General Info / Security Vulnerabilities:** [bbekam60@gmail.com](mailto:bbekam60@gmail.com)
- 💬 **Developer Support (Telegram):** [@eth_dev_support](https://t.me/eth_dev_support)

---

## 📄 License

This SDK is open-sourced software licensed under the [MIT License](LICENSE).

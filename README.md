# Telebirr PHP & Laravel SDK (`bekambeyene/telebirr`)

[![Latest Stable Version](https://img.shields.io/packagist/v/bekambeyene/telebirr.svg?maxAge=0)](https://packagist.org/packages/bekambeyene/telebirr)
[![Total Downloads](https://img.shields.io/packagist/dt/bekambeyene/telebirr.svg?maxAge=0)](https://packagist.org/packages/bekambeyene/telebirr)
[![License](https://img.shields.io/packagist/l/bekambeyene/telebirr.svg?maxAge=0)](https://packagist.org/packages/bekambeyene/telebirr)
[![PHP Version Compatibility](https://img.shields.io/packagist/php-v/bekambeyene/telebirr.svg?maxAge=0)](https://packagist.org/packages/bekambeyene/telebirr)
<img width="1280" height="320" alt="telebanner" src="https://github.com/user-attachments/assets/187d06f5-9aaf-4edb-91aa-c31b1ebca7e6" />

A fully-featured, framework-agnostic PHP SDK for integrating Ethio Telecom's **Telebirr SuperApp Payment Gateway**. Includes first-class integrations, facades, and configuration builders for **Laravel 11, 12, and 13**, as well as clean vanilla PHP environments.

---

## ⚡ Key Features

- **Secure & Standardized Padding:** Automatically handles RSA signature generation using **SHA256 with RSA-PSS padding** (with SHA256 MGF1 hash configuration) as required by Telebirr.
- **Double Signature Handling:** Handles both the main request pre-order signing and the Web Gateway redirect query parameter signing (`PKCS#1` format for H5).
- **Flexible Flow Support:**
  - **H5 / Web Checkout Flow:** Generate payment URLs seamlessly to redirect web clients.
  - **In-App Payment Flow:** Obtain raw request strings directly for integration inside mobile apps or WebViews.
- **Robust Client Query:** Verify order/trade status with Telebirr API directly.
- **Zero-Dependency Laravel Bridge:** Includes a built-in Service Provider and Facade that auto-discovers on installation.

---

## 📦 Compatibility & Requirements

- **PHP:** `^8.2` (Fully supports PHP 8.2, 8.3, and 8.4)
- **Laravel Framework:** `^11.0 || ^12.0 || ^13.0`
- **Extensions Needed:** `openssl`, `curl`, `json`

---

## 🚀 Installation

Install the package into your project using Composer:

```bash
composer require bekambeyene/telebirr
```

---

## 🛠️ Laravel Integration (Laravel 11, 12, 13)

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
TELEBIRR_BASE_URL=https://developerportal.ethiotelebirr.et:38443/apiaccess/payment/gateway
TELEBIRR_WEB_URL=https://developerportal.ethiotelebirr.et:38443/payment/web/paygate
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
use Bekambeyene\Telebirr\Laravel\Facades\Telebirr;
use Illuminate\Support\Facades\Log;
use Exception;

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
        } catch (Exception $e) {
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

        // Validate the request payload details
        $tradeStatus = $request->input('trade_status');
        $merchantOrderId = $request->input('merch_order_id');

        if ($tradeStatus === 'PAY_SUCCESS') {
            // Update order status in your database
            // e.g. Order::where('order_id', $merchantOrderId)->update(['status' => 'paid']);
        }

        return response('success'); // Telebirr expects 'success' response
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

You can use the SDK in native PHP applications easily.

### 1. Initialize the Client

```php
<?php

require 'vendor/autoload.php';

use Bekambeyene\Telebirr\TelebirrClient;

$config = [
    'base_url' => 'https://developerportal.ethiotelebirr.et:38443/apiaccess/payment/gateway',
    'web_url' => 'https://developerportal.ethiotelebirr.et:38443/payment/web/paygate',
    'fabric_app_id' => 'your_fabric_app_id',
    'app_secret' => 'your_app_secret',
    'merchant_app_id' => 'your_merchant_app_id',
    'merchant_code' => 'your_merchant_code',
    'notify_url' => 'https://yourdomain.com/payment/notify',
    'return_url' => 'https://yourdomain.com/payment/success',
    'private_key' => '-----BEGIN PRIVATE KEY-----
YOUR_PRIVATE_KEY
-----END PRIVATE KEY-----'
];

// Optional: Provide custom logging callback
$logger = function (string $message, string $level = 'info', array $context = []) {
    error_log("[Telebirr SDK][$level] $message " . json_encode($context));
};

$telebirr = new TelebirrClient($config, $logger);
```

### 2. Standard H5 / Web Checkout Flow

```php
try {
    $paymentUrl = $telebirr->createOrder('Standard Plan Subscription', 250.00);
    
    // Redirect customer
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

The Telebirr Gateway uses a dual signing structure:
1. **Pre-order API Request Signature:** Signed with **RSA-PSS Padding** with SHA256 hash & SHA256 Mask Generation Function (`withMGFHash('sha256')`).
2. **Web Paygate Redirect URL Signature:** Uses **RSA-PKCS1 Padding** on the web payload variables (`appid`, `merch_code`, `nonce_str`, `prepay_id`, `timestamp`, `sign_type`), while leaving parameters like `version` and `trade_type` appended to the URL unsigned.

This SDK abstracts all of these complexities automatically via `phpseclib3`.

---
## Default endpoints used by the library:

- Test API: https://developerportal.ethiotelebirr.et:38443/apiaccess/payment/gateway
- Production API: https://superapp.ethiomobilemoney.et:38443/apiaccess/payment/gateway
- Test Web Checkout Redirect: https://developerportal.ethiotelebirr.et:38443/payment/web/paygate?
- Production Web Checkout Redirect: https://superapp.ethiomobilemoney.et:38443/payment/web/paygate?
  
## 🔗 Links

- [Telebirr Developer Portal](https://developer.ethiotelecom.et/)
- [Telebirr H5 C2B Integration Guide](https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/requestCreateOrder)

## 📞 Support & Contacts

- 📧 **General Info / Security Vulnerabilities:** [bbekam60@gmail.com](mailto:bbekam60@gmail.com)
- 💬 **Developer Support (Telegram):** [@eth_dev_support](https://t.me/eth_dev_support)

---

## 📄 License

This SDK is open-sourced software licensed under the [MIT License](LICENSE).


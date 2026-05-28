# Telebirr PHP & Laravel Package (`bekambeyene/telebirr`)

A framework-agnostic PHP package for integrating Telebirr payment gateway, with full first-class support for Laravel 11, 12, and 13.

## Installation

Install the package via Composer:

```bash
composer require bekambeyene/telebirr
```

---

## Laravel Integration (Laravel 11, 12, 13)

This package supports Laravel's package auto-discovery, so it registers the Service Provider and Facade automatically.

### 1. Publish Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag="telebirr-config"
```

This will create a `config/telebirr.php` file in your application.

### 2. Environment Variables

Add the following variables to your `.env` file:

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
...
-----END PUBLIC KEY-----"
TELEBIRR_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----
...
-----END PRIVATE KEY-----"
```

### 3. Usage Example (Controller)

Here is how you can use the Telebirr facade in your controllers:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Bekambeyene\Telebirr\Laravel\Facades\Telebirr;
use Illuminate\Support\Facades\Log;
use Exception;

class PaymentController extends Controller
{
    /**
     * Redirect user to Telebirr payment page
     */
    public function checkout(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric',
            'title' => 'required|string',
        ]);

        try {
            // Generates the H5/Web payment URL
            $paymentUrl = Telebirr::createOrder(
                $request->input('title'),
                $request->input('amount')
            );

            return redirect()->away($paymentUrl);
        } catch (Exception $e) {
            Log::error('Telebirr Checkout Error: ' . $e->getMessage());
            return back()->with('error', 'Payment initialization failed: ' . $e->getMessage());
        }
    }

    /**
     * Handle webhook notifications from Telebirr
     */
    public function handleNotification(Request $request)
    {
        Log::info('Telebirr Notification payload: ', $request->all());

        // Process notification, verify order details
        return response('success');
    }

    /**
     * Manually verify the payment status
     */
    public function verify(string $merchantOrderId)
    {
        $result = Telebirr::verifyPayment($merchantOrderId);

        if ($result['success']) {
            $status = $result['status']; // e.g. "success", "fail", etc.
            return view('payment.status', ['status' => $status, 'details' => $result['raw_response']]);
        }

        return redirect()->route('home')->with('error', 'Verification failed: ' . $result['message']);
    }
}
```

---

## Vanilla PHP Usage (No Laravel)

If you are using this package in a raw PHP project (without Laravel):

### 1. Instantiate the Client

Pass your config array directly to the constructor of `TelebirrClient`:

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
    'public_key' => '-----BEGIN PUBLIC KEY-----...',
    'private_key' => '-----BEGIN PRIVATE KEY-----...'
];

// Optional: Provide a logging callback function
$logger = function (string $message, string $level = 'info', array $context = []) {
    error_log("[Telebirr SDK][$level] $message - " . json_encode($context));
};

$telebirr = new TelebirrClient($config, $logger);
```

### 2. Initiate Payment / Create Order

```php
try {
    $paymentUrl = $telebirr->createOrder('Order Title', 150.50);
    
    // Redirect to Telebirr payment URL
    header('Location: ' . $paymentUrl);
    exit;
} catch (Exception $e) {
    echo "Error initializing payment: " . $e->getMessage();
}
```

### 3. Verify Payment Status

```php
$merchantOrderId = '1716912345678';
$response = $telebirr->verifyPayment($merchantOrderId);

if ($response['success']) {
    echo "Payment Status: " . $response['status'];
} else {
    echo "Payment Verification failed: " . $response['message'];
}
```

## Security & Signatures
Signatures are generated using RSA-SHA256 with PSS padding (SHA256 Hash and SHA256 MGF Hash) automatically using standard security libraries (`phpseclib`).

## License
MIT

<?php
// examples/laravel-h5-payment-example.php

use Bekambeyene\Telebirr\Facades\Telebirr;
use Bekambeyene\Telebirr\Exceptions\TelebirrException;
use Bekambeyene\Telebirr\Exceptions\TelebirrServerException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    /**
     * Initiate an H5 payment and redirect the user.
     */
    public function checkout(Request $request)
    {
        try {
            // Provide a clear subject, amount, and optionally a custom order ID
            $paymentUrl = Telebirr::createOrder('Premium Subscription', 250.00, 'ORDER-' . uniqid());
            
            // Redirect the user to the generated H5 Telebirr Checkout URL
            return redirect()->away($paymentUrl);
            
        } catch (TelebirrServerException $e) {
            // 60200087: The Telebirr gateway is busy or syncing
            Log::warning('Telebirr server status exception: ' . $e->getMessage());
            return back()->with('error', 'Telebirr payment services are currently busy. Please try again in a few moments.');
            
        } catch (TelebirrException $e) {
            // Configuration or generic SDK error
            Log::error('Telebirr config error: ' . $e->getMessage());
            return back()->with('error', 'Failed to initiate payment.');
        }
    }
}

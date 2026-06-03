<?php
// examples/webhook-verification-example.php

use Bekambeyene\Telebirr\Facades\Telebirr;
use Bekambeyene\Telebirr\Exceptions\InvalidSignatureException;
use Bekambeyene\Telebirr\Exceptions\TimestampExpiredException;
use Bekambeyene\Telebirr\Exceptions\ReplayAttackException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * Handle incoming Telebirr Webhooks securely.
     * 
     * IMPORTANT: Remember to add this route to your CSRF exceptions!
     */
    public function handle(Request $request)
    {
        try {
            // handleWebhook automatically validates:
            // 1. RSA-PSS signature
            // 2. Timestamp drift
            // 3. Nonce replay attacks
            $payload = Telebirr::handleWebhook($request);

            $orderId = $payload['out_trade_no'];
            $status = $payload['trade_status'];

            if ($status === 'PAY_SUCCESS') {
                // Grant access, mark order paid, etc.
                // It is highly recommended to use database locks here to prevent race conditions
            }

            // Always return plain text 'success' to acknowledge receipt
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

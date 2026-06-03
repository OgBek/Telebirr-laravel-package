<?php

declare(strict_types=1);

namespace Bekambeyene\Telebirr;

use Bekambeyene\Telebirr\Contracts\TelebirrClientInterface;
use Bekambeyene\Telebirr\Exceptions\TelebirrException;
use Bekambeyene\Telebirr\Exceptions\TelebirrServerException;
use Bekambeyene\Telebirr\Services\SignatureService;
use Bekambeyene\Telebirr\Services\TelebirrHttpClient;
use Bekambeyene\Telebirr\Services\TokenManager;

class TelebirrClient implements TelebirrClientInterface
{
    protected TokenManager $tokenManager;
    protected SignatureService $signatureService;
    protected TelebirrHttpClient $httpClient;

    protected string $webUrl;
    protected string $fabricAppId;
    protected string $merchantAppId;
    protected string $merchantCode;
    protected string $privateKey;
    protected string $publicKey;
    protected string $notifyUrl;
    protected string $returnUrl;
    protected string $padding;
    protected int $webhookToleranceSeconds;
    /** @var callable|null */
    protected $logger;

    public function __construct(
        array $config,
        TokenManager $tokenManager,
        SignatureService $signatureService,
        TelebirrHttpClient $httpClient,
        ?callable $logger = null
    ) {
        $this->tokenManager = $tokenManager;
        $this->signatureService = $signatureService;
        $this->httpClient = $httpClient;

        $this->webUrl = $config['web_url'] ?? '';
        $this->fabricAppId = $config['fabric_app_id'] ?? '';
        $this->merchantAppId = $config['merchant_app_id'] ?? '';
        $this->merchantCode = $config['merchant_code'] ?? '';
        $this->privateKey = $this->resolveKey($config['private_key'] ?? '', 'PRIVATE');
        $this->publicKey = $this->resolveKey($config['public_key'] ?? '', 'PUBLIC');
        $this->notifyUrl = $config['notify_url'] ?? '';
        
        // Normalize return_url and redirect_url, supporting both keys
        $this->returnUrl = $config['return_url'] ?? $config['redirect_url'] ?? '';
        
        $this->padding = strtolower($config['padding'] ?? 'pss');
        $this->webhookToleranceSeconds = (int)($config['webhook_tolerance_seconds'] ?? 300);
        $this->logger = $logger;
    }

    protected function log(string $message, string $level = 'info', array $context = []): void
    {
        if ($this->logger) {
            call_user_func($this->logger, $message, $level, $context);
        }
    }

    /**
     * Resolves a cryptographic key from a file path, base64 string, or raw string.
     * 
     * @param string $key
     * @param string $type
     * @return string
     */
    protected function resolveKey(string $key, string $type = 'PRIVATE'): string
    {
        $key = trim($key);

        if (empty($key)) {
            return '';
        }

        // Check if the key is a file path (starts with file://, or absolute path / or C:\)
        if (str_starts_with($key, 'file://')) {
            $path = substr($key, 7);
            if (file_exists($path)) {
                return trim(file_get_contents($path));
            }
        } elseif ((str_starts_with($key, '/') || preg_match('/^[A-Za-z]:\\\\/', $key)) && file_exists($key)) {
            return trim(file_get_contents($key));
        }

        // Check if the key is base64 encoded
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        // If it's a raw base64 string without headers/footers, format it properly
        if (!str_contains($key, '-----BEGIN')) {
            $formattedKey = "-----BEGIN {$type} KEY-----\n";
            $formattedKey .= chunk_split($key, 64, "\n");
            $formattedKey .= "-----END {$type} KEY-----";
            
            return $formattedKey;
        }

        return $key;
    }

    public function getFabricToken(): string
    {
        return $this->tokenManager->getFabricToken();
    }

    /**
     * Create an order and return the payment URL or raw request string.
     * 
     * @param string $title
     * @param float $amount
     * @param string|null $merchOrderId
     * @param array{trade_type?: string, timeout_express?: string, business_type?: string, callback_info?: string, raw_request?: bool} $params
     * @return string
     * @throws TelebirrException
     */
    public function createOrder(string $title, float $amount, ?string $merchOrderId = null, array $params = []): string
    {
        if ($amount <= 0) {
            throw new TelebirrException("Order amount must be greater than zero.");
        }

        if (empty(trim($title))) {
            throw new TelebirrException("Order title cannot be empty.");
        }

        if ($merchOrderId !== null && empty(trim($merchOrderId))) {
            throw new TelebirrException("Merchant order ID cannot be empty if provided.");
        }

        $token = $this->getFabricToken();
        $nonce = $this->createNonceStr();
        $timestamp = $this->createTimeStamp();
        $merchantOrderId = $merchOrderId ?? $this->createMerchantOrderId();

        $this->log("Telebirr: Using Merchant Order ID: {$merchantOrderId}");

        $returnUrl = $this->returnUrl;
        
        // Removed ?track_number= parameter pollution to prevent Telebirr redirect corruption.
        // It is recommended to use merch_order_id from the callback or store it in your session.

        $bizContent = [
            'notify_url' => $this->notifyUrl,
            'appid' => $this->merchantAppId,
            'merch_code' => $this->merchantCode,
            'merch_order_id' => $merchantOrderId,
            'trade_type' => $params['trade_type'] ?? 'Checkout',
            'title' => $title,
            'total_amount' => number_format($amount, 2, '.', ''),
            'trans_currency' => 'ETB',
            'timeout_express' => $params['timeout_express'] ?? '120m',
            'business_type' => $params['business_type'] ?? 'BuyGoods',
            'callback_info' => $params['callback_info'] ?? 'From web',
            'redirect_url' => $returnUrl
        ];

        $bizContent = array_merge($bizContent, $params);
        $rawRequestFlag = $bizContent['raw_request'] ?? false;
        unset($bizContent['raw_request']);

        $requestParams = [
            'nonce_str' => $nonce,
            'method' => 'payment.preorder',
            'timestamp' => $timestamp,
            'version' => '1.0',
            'biz_content' => $bizContent,
            'sign_type' => 'SHA256WithRSA',
        ];

        $requestParams['sign'] = $this->padding === 'pkcs1'
            ? $this->signatureService->signPKCS1($requestParams, $this->privateKey)
            : $this->signatureService->signPSS($requestParams, $this->privateKey);

        $this->log("Telebirr: Creating order");

        $response = $this->httpClient->post(
            '/payment/v1/merchant/preOrder',
            json_encode($requestParams, JSON_UNESCAPED_SLASHES),
            [
                'Authorization' => $token,
                'X-APP-Key' => $this->fabricAppId,
            ]
        );

        $code = $response['code'] ?? null;
        $msg = $response['msg'] ?? '';

        if ($code !== null && $code !== '0' && $code !== '200') {
            if ($code === '60200087') {
                throw new TelebirrServerException("Telebirr servers are currently busy or syncing. Please try your payment a bit later.", $code, $msg);
            }
            throw new TelebirrServerException("Telebirr API Error: " . $msg, $code, $msg);
        }

        $biz = $response['biz_content'] ?? [];
        $prepayId = $biz['prepay_id'] ?? $biz['prePayId'] ?? null;

        if ($prepayId) {
            if ($rawRequestFlag === true) {
                return $this->getRawRequestString($prepayId, $params['trade_type'] ?? 'Checkout');
            }
            return $this->createRawRequestUrl($prepayId, $params['trade_type'] ?? 'Checkout', $merchantOrderId);
        }

        throw new TelebirrException('Failed to extract prepay_id from Telebirr response.');
    }

    /**
     * Build common parameters for H5 request generation.
     */
    protected function buildBaseRequestParams(string $prepayId, string $tradeType): array
    {
        $map = [
            'appid' => $this->merchantAppId,
            'merch_code' => $this->merchantCode,
            'nonce_str' => $this->createNonceStr(),
            'prepay_id' => $prepayId,
            'timestamp' => $this->createTimeStamp(),
            'sign_type' => 'SHA256WithRSA'
        ];

        if ($tradeType === 'Checkout') {
            $map['version'] = '1.0';
            $map['trade_type'] = $tradeType;
        }

        return $map;
    }

    /**
     * Get Raw Request String.
     * Note: This method signs the request using PKCS1 padding, whereas standard checkout uses PSS.
     * Check Telebirr documentation to ensure this aligns with your integration requirements.
     */
    public function getRawRequestString(string $prepayId, string $tradeType = 'Checkout', ?string $returnUrl = null): string
    {
        $maps = $this->buildBaseRequestParams($prepayId, $tradeType);
        
        if (!empty($returnUrl)) {
            $maps["returnUrl"] = $returnUrl;
        }
        
        $rawRequest = "";
        foreach ($maps as $map => $m) {
            $rawRequest .= $map . '=' . $m . "&";
        }
        $sign = $this->signatureService->signPKCS1($maps, $this->privateKey);
        
        $rawRequest .= 'sign=' . $sign;
        return $rawRequest;
    }

    /**
     * Generate the Telebirr H5 Web Checkout Payment URL.
     *
     * WARNING:
     * Telebirr production interoperability depends on signing EXACTLY the 5 fields below.
     * Do NOT include version, trade_type, sign_type, redirect_url, or any optional parameters
     * in the signed payload. Doing so will trigger a signature verification failure (error 60200099)
     * in the production environment.
     *
     * URL Encoding Clarification:
     * - The canonical string passed to the signature service is NOT URL-encoded.
     * - The final query parameters appended to the URL must be URL-encoded (urlencode) to be safe for transport.
     */
    protected function createRawRequestUrl(string $prepayId, string $tradeType = 'Checkout', ?string $merchantOrderId = null): string
    {
        $signMap = [
            'appid' => $this->merchantAppId,
            'merch_code' => $this->merchantCode,
            'nonce_str' => $this->createNonceStr(),
            'prepay_id' => $prepayId,
            'timestamp' => $this->createTimeStamp()
        ];

        // Sign the payload using the configured padding mode (PSS is the default and strongly recommended)
        $sign = $this->padding === 'pkcs1'
            ? $this->signatureService->signPKCS1($signMap, $this->privateKey)
            : $this->signatureService->signPSS($signMap, $this->privateKey);

        $urlMap = $signMap;
        $urlMap['sign_type'] = 'SHA256WithRSA';

        if ($tradeType === 'Checkout') {
            $urlMap['version'] = '1.0';
            $urlMap['trade_type'] = $tradeType;
        }

        if ($merchantOrderId !== null) {
            $urlMap['merch_order_id'] = $merchantOrderId;
        }

        $urlMap['sign'] = $sign;

        $rawRequestArray = [];
        foreach ($urlMap as $key => $value) {
            $rawRequestArray[] = $key . "=" . urlencode((string)$value);
        }
        
        $rawRequest = implode("&", $rawRequestArray);

        $webBaseUrl = rtrim($this->webUrl, '?') . '?';
        $paymentUrl = $webBaseUrl . $rawRequest;
        
        $this->log("Telebirr H5 URL Generated for prepay_id: " . $prepayId);
        
        return $paymentUrl;
    }

    public function verifyPayment(string $merchantOrderId): array
    {
        $token = $this->getFabricToken();
        $nonce = $this->createNonceStr();
        $timestamp = $this->createTimeStamp();

        $bizContent = [
            'appid' => $this->merchantAppId,
            'merch_code' => $this->merchantCode,
            'merch_order_id' => $merchantOrderId,
        ];

        $requestParams = [
            'nonce_str' => $nonce,
            'method' => 'payment.queryorder',
            'timestamp' => $timestamp,
            'version' => '1.0',
            'biz_content' => $bizContent,
            'sign_type' => 'SHA256WithRSA',
        ];

        $requestParams['sign'] = $this->padding === 'pkcs1'
            ? $this->signatureService->signPKCS1($requestParams, $this->privateKey)
            : $this->signatureService->signPSS($requestParams, $this->privateKey);
        
        $response = $this->httpClient->post(
            '/payment/v1/merchant/queryOrder',
            json_encode($requestParams, JSON_UNESCAPED_SLASHES),
            [
                'Authorization' => $token,
                'X-APP-Key' => $this->fabricAppId,
            ]
        );

        if (isset($response['sign'])) {
            if (!$this->verifyCallbackSignature($response, $response['sign'])) {
                throw new TelebirrException("Telebirr response signature verification failed.");
            }
        }

        if (isset($response['biz_content'])) {
            $biz = $response['biz_content'];
            $orderStatus = $biz['trade_status'] ?? $biz['order_status'] ?? $biz['result'] ?? 'unknown';
            
            return [
                'success' => true,
                'status' => strtolower($orderStatus),
                'raw_response' => $response
            ];
        }

        throw new TelebirrServerException('Telebirr query failed, biz_content missing.', $response['code'] ?? '', $response['msg'] ?? '');
    }

    public function initiatePayment(string $orderId, float $amount, string $subject, array $customerInfo = []): array
    {
        try {
            $paymentUrl = $this->createOrder($subject, $amount, $orderId);
            
            return [
                'success' => true,
                'payment_url' => $paymentUrl,
                'reference' => $orderId 
            ];
        } catch (\Throwable $e) {
            $this->log('Telebirr Initiate Payment Error: ' . $e->getMessage(), 'error');
            return [
                'success' => false, 
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Handle incoming webhook callback from Telebirr.
     * Automatically verifies signature, timestamp freshness, and checks for replay attacks.
     *
     * @param \Illuminate\Http\Request $request
     * @return array Verified and cleaned payload array
     * @throws \Bekambeyene\Telebirr\Exceptions\InvalidSignatureException
     * @throws \Bekambeyene\Telebirr\Exceptions\TimestampExpiredException
     * @throws \Bekambeyene\Telebirr\Exceptions\ReplayAttackException
     */
    public function handleWebhook(\Illuminate\Http\Request $request): array
    {
        $payload = $request->all();

        // Extract signature from payload or headers
        $signature = $payload['sign'] ?? $payload['signature'] ?? $request->header('X-Sign') ?? $request->header('Signature') ?? '';

        if (empty($signature)) {
            $this->log("Webhook handling failed: Signature is missing.", "error", ['payload' => $payload]);
            throw new Exceptions\InvalidSignatureException("Webhook signature is missing.");
        }

        $this->log("Webhook handling: Verifying signature.", "info", ['nonce' => $payload['nonce_str'] ?? null]);
        if (!$this->verifyCallbackSignature($payload, $signature)) {
            $this->log("Webhook handling failed: Signature verification failed.", "error");
            throw new Exceptions\InvalidSignatureException("Webhook signature verification failed.");
        }

        $tolerance = $this->webhookToleranceSeconds;
        if (!$this->verifyCallbackTimestamp($payload, $tolerance)) {
            $this->log("Webhook handling failed: Timestamp expired.", "error", [
                'timestamp' => $payload['timestamp'] ?? null,
                'tolerance' => $tolerance
            ]);
            throw new Exceptions\TimestampExpiredException("Webhook request timestamp is expired or outside tolerance window.");
        }

        if (!$this->verifyNonce($payload, $tolerance, true)) {
            $this->log("Webhook handling failed: Replay attack detected.", "error", ['nonce' => $payload['nonce_str'] ?? null]);
            throw new Exceptions\ReplayAttackException("Duplicate request detected (nonce replay attack).");
        }

        // Clean signature parameters from payload
        unset($payload['sign'], $payload['sign_type']);

        $this->log("Webhook handling succeeded.", "info", ['merch_order_id' => $payload['merch_order_id'] ?? null]);

        return $payload;
    }

    /**
     * Verify callback signature.
     * 
     * Security Note: To prevent replay attacks, you MUST validate that the 'timestamp' 
     * is recent (e.g., within 5 minutes) and verify that the 'nonce_str' hasn't been used before.
     */
    public function verifyCallbackSignature(array $payload, string $signature): bool
    {
        if (empty($this->publicKey)) {
            throw new TelebirrException("Public key is required to verify webhook signatures.");
        }

        if ($this->padding === 'pkcs1') {
            return $this->signatureService->verifyPKCS1($payload, $signature, $this->publicKey);
        }
        return $this->signatureService->verifyPSS($payload, $signature, $this->publicKey);
    }

    /**
     * Verify that the callback timestamp is within an acceptable window to prevent replay attacks.
     *
     * @param array $payload
     * @param int $maxAgeSeconds Default 300 seconds (5 minutes)
     * @return bool
     */
    public function verifyCallbackTimestamp(array $payload, int $maxAgeSeconds = 300): bool
    {
        if (!isset($payload['timestamp'])) {
            return false;
        }

        $requestTime = (int)$payload['timestamp'];
        
        // Handle cases where timestamp might be in milliseconds instead of seconds
        if (strlen((string)$requestTime) >= 13) {
            $requestTime = (int)($requestTime / 1000);
        }

        $currentTime = time();
        return abs($currentTime - $requestTime) <= $maxAgeSeconds;
    }

    /**
     * Verify that the callback nonce has not been processed recently to prevent replay attacks.
     * Requires Laravel Cache. If Cache is not available, it safely returns true (bypass) or throws if strict.
     *
     * @param array $payload
     * @param int $cacheTtlSeconds Default 300 seconds (5 minutes) to match timestamp max age
     * @param bool $strict If true, throws an exception if Cache is unavailable.
     * @return bool True if valid (not a replay), False if nonce already exists in cache.
     */
    public function verifyNonce(array $payload, int $cacheTtlSeconds = 300, bool $strict = true): bool
    {
        if (!isset($payload['nonce_str'])) {
            return false;
        }

        $nonce = (string)$payload['nonce_str'];

        if (class_exists(\Illuminate\Support\Facades\Cache::class)) {
            try {
                // Returns true if the item was actually added (meaning it didn't exist)
                return \Illuminate\Support\Facades\Cache::add('telebirr_nonce_' . $nonce, true, $cacheTtlSeconds);
            } catch (\Throwable $e) {
                if ($strict) {
                    throw new TelebirrException("Telebirr Cache unavailable for strict nonce verification.", 0, $e);
                }
                // If Cache facade is failing (e.g. redis down), allow by default
                // or fallback to log depending on strictness
                $this->log("Telebirr Cache unavailable for nonce verification: " . $e->getMessage(), 'warning');
            }
        } elseif ($strict) {
            throw new TelebirrException("Laravel Cache facade not found for strict nonce verification.");
        }

        // Vanilla PHP fallback without cache configured
        return true;
    }

    public function refundOrder(string $outTradeNo, float $refundAmount, string $outRequestNo, array $params = []): array
    {
        if ($refundAmount <= 0) {
            throw new TelebirrException("Refund amount must be greater than zero.");
        }

        if (empty(trim($outTradeNo))) {
            throw new TelebirrException("Original merchant order ID cannot be empty.");
        }

        if (empty(trim($outRequestNo))) {
            throw new TelebirrException("Refund request ID cannot be empty.");
        }

        $token = $this->getFabricToken();
        $nonce = $this->createNonceStr();
        $timestamp = $this->createTimeStamp();

        $bizContent = [
            'appid' => $this->merchantAppId,
            'merch_code' => $this->merchantCode,
            'merch_order_id' => $outTradeNo,
            'refund_amount' => number_format($refundAmount, 2, '.', ''),
            'out_request_no' => $outRequestNo,
            'refund_reason' => $params['refund_reason'] ?? 'Requested by customer'
        ];

        $requestParams = [
            'nonce_str' => $nonce,
            'method' => 'payment.refund',
            'timestamp' => $timestamp,
            'version' => '1.0',
            'biz_content' => $bizContent,
            'sign_type' => 'SHA256WithRSA',
        ];

        $requestParams['sign'] = $this->padding === 'pkcs1'
            ? $this->signatureService->signPKCS1($requestParams, $this->privateKey)
            : $this->signatureService->signPSS($requestParams, $this->privateKey);

        $this->log("Telebirr: Processing refund for {$outTradeNo}");

        $response = $this->httpClient->post(
            '/payment/v1/merchant/refund',
            json_encode($requestParams, JSON_UNESCAPED_SLASHES),
            [
                'Authorization' => $token,
                'X-APP-Key' => $this->fabricAppId,
            ]
        );

        if (isset($response['sign'])) {
            if (!$this->verifyCallbackSignature($response, $response['sign'])) {
                throw new TelebirrException("Telebirr response signature verification failed.");
            }
        }

        if (isset($response['biz_content'])) {
            $biz = $response['biz_content'];
            return [
                'success' => true,
                'raw_response' => $response
            ];
        }

        throw new TelebirrServerException('Telebirr refund failed, biz_content missing.', $response['code'] ?? '', $response['msg'] ?? '');
    }

    protected function createNonceStr(int $length = 32): string
    {
        return bin2hex(random_bytes(max(1, (int)($length / 2))));
    }

    protected function createTimeStamp(): string
    {
        return (string)time();
    }

    protected function createMerchantOrderId(): string
    {
        return 'ORDER_' . date('YmdHis') . strtoupper(bin2hex(random_bytes(4)));
    }
}

<?php

declare(strict_types=1);

namespace Bekambeyene\Telebirr;

use Bekambeyene\Telebirr\Contracts\TelebirrClientInterface;
use Bekambeyene\Telebirr\Exceptions\TelebirrException;
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

        $this->webUrl = $config['web_url'] ?? 'https://developerportal.ethiotelebirr.et:38443/payment/web/paygate';
        $this->fabricAppId = $config['fabric_app_id'] ?? '';
        $this->merchantAppId = $config['merchant_app_id'] ?? '';
        $this->merchantCode = $config['merchant_code'] ?? '';
        $this->privateKey = $config['private_key'] ?? '';
        $this->publicKey = $config['public_key'] ?? '';
        $this->notifyUrl = $config['notify_url'] ?? '';
        $this->returnUrl = $config['return_url'] ?? '';
        $this->logger = $logger;
    }

    protected function log(string $message, string $level = 'info', array $context = []): void
    {
        if ($this->logger) {
            call_user_func($this->logger, $message, $level, $context);
        }
    }

    public function getFabricToken(): string
    {
        return $this->tokenManager->getFabricToken();
    }

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
        $separator = (parse_url($returnUrl, PHP_URL_QUERY) == null) ? '?' : '&';
        $returnUrl .= $separator . 'track_number=' . $merchantOrderId;

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

        $requestParams['sign'] = $this->signatureService->signPSS($requestParams, $this->privateKey);

        $this->log("Telebirr: Creating order");

        $response = $this->httpClient->post(
            '/payment/v1/merchant/preOrder',
            json_encode($requestParams, JSON_UNESCAPED_SLASHES),
            [
                'Authorization' => $token,
                'X-APP-Key' => $this->fabricAppId,
            ]
        );

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

    public function getRawRequestString(string $prepayId, string $tradeType = 'Checkout', ?string $returnUrl = null): string
    {
        $maps = [
            "appid" => $this->merchantAppId,
            "merch_code" => $this->merchantCode,
            "nonce_str" => $this->createNonceStr(),
            "prepay_id" => $prepayId,
            "timestamp" => $this->createTimeStamp(),
            "sign_type" => "SHA256WithRSA"
        ];
        
        if ($tradeType === 'Checkout') {
            $maps['version'] = '1.0';
            $maps['trade_type'] = $tradeType;
        }
        
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

    protected function createRawRequestUrl(string $prepayId, string $tradeType = 'Checkout', ?string $merchantOrderId = null): string
    {
        $map = [
            'appid' => $this->merchantAppId,
            'merch_code' => $this->merchantCode,
            'nonce_str' => $this->createNonceStr(),
            'prepay_id' => $prepayId,
            'timestamp' => $this->createTimeStamp(),
            'sign_type' => 'SHA256WithRSA'
        ];

        if ($merchantOrderId !== null) {
            $map['merch_order_id'] = $merchantOrderId;
        }

        $sign = $this->signatureService->signPSS($map, $this->privateKey);

        $rawRequestArray = [
            "appid=" . urlencode($map['appid']),
            "merch_code=" . urlencode($map['merch_code']),
            "nonce_str=" . urlencode($map['nonce_str']),
            "prepay_id=" . urlencode($map['prepay_id']),
            "timestamp=" . urlencode($map['timestamp']),
            "sign_type=" . urlencode($map['sign_type']),
            "sign=" . urlencode($sign),
            "version=1.0",
            "trade_type=" . $tradeType
        ];

        if ($merchantOrderId !== null) {
            $rawRequestArray[] = "merch_order_id=" . urlencode($merchantOrderId);
        }
        
        $rawRequest = implode("&", $rawRequestArray);

        $webBaseUrl = rtrim($this->webUrl, '?') . '?';
        $paymentUrl = $webBaseUrl . $rawRequest;
        
        $this->log("Telebirr H5 URL Generated: " . $paymentUrl);
        
        return $paymentUrl;
    }

    public function verifyPayment(string $merchantOrderId): array
    {
        try {
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

            $requestParams['sign'] = $this->signatureService->signPSS($requestParams, $this->privateKey);
            
            $response = $this->httpClient->post(
                '/payment/v1/merchant/queryOrder',
                json_encode($requestParams, JSON_UNESCAPED_SLASHES),
                [
                    'Authorization' => $token,
                    'X-APP-Key' => $this->fabricAppId,
                ]
            );

            if (isset($response['biz_content'])) {
                $biz = $response['biz_content'];
                $orderStatus = $biz['trade_status'] ?? $biz['order_status'] ?? $biz['result'] ?? 'unknown';
                
                return [
                    'success' => true,
                    'status' => strtolower($orderStatus),
                    'raw_response' => $response
                ];
            }

            return [
                'success' => false,
                'message' => 'Telebirr query failed, biz_content missing.'
            ];

        } catch (\Throwable $e) {
            $this->log('Telebirr Query Order Exception: ' . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
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

    public function verifyCallbackSignature(array $payload, string $signature): bool
    {
        if (empty($this->publicKey)) {
            throw new TelebirrException("Public key is required to verify webhook signatures.");
        }

        return $this->signatureService->verifyPSS($payload, $signature, $this->publicKey);
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

        try {
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

            $requestParams['sign'] = $this->signatureService->signPSS($requestParams, $this->privateKey);

            $this->log("Telebirr: Processing refund for {$outTradeNo}");

            $response = $this->httpClient->post(
                '/payment/v1/merchant/refund',
                json_encode($requestParams, JSON_UNESCAPED_SLASHES),
                [
                    'Authorization' => $token,
                    'X-APP-Key' => $this->fabricAppId,
                ]
            );

            if (isset($response['biz_content'])) {
                $biz = $response['biz_content'];
                $status = $biz['trade_status'] ?? $biz['result'] ?? 'unknown';

                return [
                    'success' => in_array($status, ['SUCCESS', 'REFUND_SUCCESS']),
                    'status' => strtolower($status),
                    'raw_response' => $response
                ];
            }

            return [
                'success' => false,
                'message' => 'Telebirr refund failed, biz_content missing.'
            ];

        } catch (\Throwable $e) {
            $this->log('Telebirr Refund Order Exception: ' . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    protected function createNonceStr(int $length = 32): string
    {
        try {
            return bin2hex(random_bytes(max(1, (int)($length / 2))));
        } catch (\Exception $e) {
            // Fallback if random_bytes is unavailable for some reason
            return substr(str_shuffle(str_repeat($x='0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ', (int)ceil($length/strlen($x)) )),1,$length);
        }
    }

    protected function createTimeStamp(): string
    {
        return (string)time();
    }

    protected function createMerchantOrderId(): string
    {
        return (string)floor(microtime(true) * 1000);
    }
}

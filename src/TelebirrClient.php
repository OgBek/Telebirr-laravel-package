<?php

namespace Bekambeyene\Telebirr;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Exception;

class TelebirrClient
{
    protected $baseUrl;
    protected $webUrl;
    protected $fabricAppId;
    protected $appSecret;
    protected $merchantAppId;
    protected $merchantCode;
    protected $privateKey;
    protected $notifyUrl;
    protected $returnUrl;
    protected $logger;

    /**
     * TelebirrClient constructor.
     * 
     * @param array $config Configuration array
     * @param callable|null $logger Optional logging callback function(string $message, string $level = 'info')
     */
    public function __construct(array $config, ?callable $logger = null)
    {
        $this->baseUrl = $config['base_url'] ?? 'https://developerportal.ethiotelebirr.et:38443/apiaccess/payment/gateway';
        $this->webUrl = $config['web_url'] ?? 'https://developerportal.ethiotelebirr.et:38443/payment/web/paygate';
        $this->fabricAppId = $config['fabric_app_id'] ?? '';
        $this->appSecret = $config['app_secret'] ?? '';
        $this->merchantAppId = $config['merchant_app_id'] ?? '';
        $this->merchantCode = $config['merchant_code'] ?? '';
        $this->privateKey = $config['private_key'] ?? '';
        $this->notifyUrl = $config['notify_url'] ?? '';
        $this->returnUrl = $config['return_url'] ?? '';
        $this->logger = $logger;
    }

    /**
     * Internal logger helper
     */
    protected function log(string $message, string $level = 'info', array $context = []): void
    {
        if ($this->logger) {
            call_user_func($this->logger, $message, $level, $context);
        }
    }

    /**
     * Get the Fabric Token
     */
    public function getFabricToken(): string
    {
        try {
            $url = rtrim($this->baseUrl, '/') . '/payment/v1/token';
            $this->log("Telebirr: Requesting token from {$url}");

            $client = new Client(['verify' => false]);
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-APP-Key' => $this->fabricAppId,
                ],
                'timeout' => 5,
                'json' => [
                    'appSecret' => $this->appSecret,
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();

            if ($statusCode >= 200 && $statusCode < 300) {
                $result = json_decode($body, true);
                if (isset($result['token'])) {
                    return $result['token'];
                }
            }

            $this->log("Telebirr Token Error: Code={$statusCode}, Body={$body}", 'error');
            throw new Exception("Failed to get Telebirr token: Status code {$statusCode}");
        } catch (Exception $e) {
            $this->log("Telebirr Token Exception: " . $e->getMessage(), 'error');
            throw $e;
        }
    }

    /**
     * Create Order
     */
    public function createOrder(string $title, $amount, ?string $merchOrderId = null): string
    {
        $token = $this->getFabricToken();
        $nonce = $this->createNonceStr();
        $timestamp = $this->createTimeStamp();
        $merchantOrderId = $merchOrderId ?? $this->createMerchantOrderId();
        $this->log("Telebirr: Using Merchant Order ID: {$merchantOrderId}");
        
        $returnUrl = $this->returnUrl;
        $separator = (parse_url($returnUrl, PHP_URL_QUERY) === null) ? '?' : '&';
        $returnUrl .= $separator . 'track_number=' . $merchantOrderId;

        $bizContent = [
            'notify_url' => $this->notifyUrl,
            'appid' => $this->merchantAppId,
            'merch_code' => $this->merchantCode,
            'merch_order_id' => $merchantOrderId,
            'trade_type' => 'Checkout',
            'title' => $title,
            'total_amount' => number_format($amount, 2, '.', ''),
            'trans_currency' => 'ETB',
            'timeout_express' => '120m',
            'business_type' => 'BuyGoods',
            'payee_identifier' => $this->merchantCode,
            'payee_identifier_type' => '04',
            'payee_type' => '5000',
            'callback_info' => 'From web',
            'redirect_url' => $returnUrl
        ];

        $requestParams = [
            'nonce_str' => $nonce,
            'method' => 'payment.preorder',
            'timestamp' => $timestamp,
            'version' => '1.0',
            'biz_content' => $bizContent,
            'sign_type' => 'SHA256WithRSA',
        ];

        // Sign the request
        $requestParams['sign'] = $this->sign($requestParams);

        $url = rtrim($this->baseUrl, '/') . '/payment/v1/merchant/preOrder';
        $this->log("Telebirr: Creating order at {$url}");

        $client = new Client(['verify' => false]);
        $response = $client->post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-APP-Key' => $this->fabricAppId,
                'Authorization' => $token,
            ],
            'timeout' => 5,
            'body' => json_encode($requestParams, JSON_UNESCAPED_SLASHES),
        ]);

        $body = $response->getBody()->getContents();
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $result = json_decode($body, true);
            $biz = $result['biz_content'] ?? [];
            $prepayId = $biz['prepay_id'] ?? $biz['prePayId'] ?? null;
            
            if ($prepayId) {
                return $this->createRawRequest($prepayId, $returnUrl);
            }
        }

        $this->log("Telebirr Create Order Error: " . $body, 'error');
        throw new Exception("Failed to create Telebirr order: " . $body);
    }

    /**
     * Create Raw Request for App/H5
     */
    protected function createRawRequest(string $prepayId, ?string $returnUrl = null): string
    {
        $map = [
            'appid' => $this->merchantAppId,
            'merch_code' => $this->merchantCode,
            'nonce_str' => $this->createNonceStr(),
            'prepay_id' => $prepayId,
            'timestamp' => $this->createTimeStamp(),
            'sign_type' => 'SHA256WithRSA'
        ];

        $sign = $this->sign($map);

        $rawRequestArray = [
            "appid=" . urlencode($map['appid']),
            "merch_code=" . urlencode($map['merch_code']),
            "nonce_str=" . urlencode($map['nonce_str']),
            "prepay_id=" . urlencode($map['prepay_id']),
            "timestamp=" . urlencode($map['timestamp']),
            "sign_type=" . urlencode($map['sign_type']),
            "sign=" . urlencode($sign),
            "version=1.0",
            "trade_type=Checkout"
        ];
        
        $rawRequest = implode("&", $rawRequestArray);
        $webBaseUrl = rtrim($this->webUrl, '?') . '?';
        $paymentUrl = $webBaseUrl . $rawRequest;
        
        $this->log("Telebirr H5 URL Generated: " . $paymentUrl);
        
        return $paymentUrl;
    }

    /**
     * Sign the data using RSA-SHA256
     */
    protected function sign(array $request): string
    {
        $excludeFields = ['sign', 'sign_type', 'header', 'refund_info', 'openType', 'raw_request'];
        ksort($request);
        $stringApplet = '';

        foreach ($request as $key => $values) {
            if (in_array($key, $excludeFields, true) || is_null($values)) {
                continue;
            }

            if ($key === 'biz_content') {
                ksort($values);
                foreach ($values as $value => $singleValue) {
                    if ($stringApplet === '') {
                        $stringApplet = $value . '=' . $singleValue;
                    } else {
                        $stringApplet .= '&' . $value . '=' . $singleValue;
                    }
                }
            } else {
                if ($stringApplet === '') {
                    $stringApplet = $key . '=' . $values;
                } else {
                    $stringApplet .= '&' . $key . '=' . $values;
                }
            }
        }

        $sortedString = $this->sortedString($stringApplet);
        $this->log("Telebirr: Signature string: " . $sortedString);
        return $this->signString($sortedString);
    }

    protected function sortedString(string $stringApplet): string
    {
        $sortedArray = explode('&', $stringApplet);
        sort($sortedArray);
        return implode('&', $sortedArray);
    }

    /**
     * Verify payment status using Telebirr queryOrder API
     */
    public function verifyPayment($merchantOrderId): array
    {
        try {
            $token = $this->getFabricToken();
            $nonce = $this->createNonceStr();
            $timestamp = $this->createTimeStamp();

            $bizContent = [
                'appid' => $this->merchantAppId,
                'merch_code' => $this->merchantCode,
                'merch_order_id' => (string)$merchantOrderId,
            ];

            $requestParams = [
                'nonce_str' => $nonce,
                'method' => 'payment.queryorder',
                'timestamp' => $timestamp,
                'version' => '1.0',
                'biz_content' => $bizContent,
                'sign_type' => 'SHA256WithRSA',
            ];

            $requestParams['sign'] = $this->sign($requestParams);
            $url = rtrim($this->baseUrl, '/') . '/payment/v1/merchant/queryOrder';
            $this->log("Telebirr Query Request Params: " . json_encode($requestParams));

            $client = new Client(['verify' => false]);
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-APP-Key' => $this->fabricAppId,
                    'Authorization' => $token,
                ],
                'timeout' => 5,
                'body' => json_encode($requestParams, JSON_UNESCAPED_SLASHES),
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();

            $this->log("Telebirr Query HTTP Status: {$statusCode}");
            $this->log("Telebirr Query Raw Response: {$body}");

            if ($statusCode >= 200 && $statusCode < 300) {
                $result = json_decode($body, true);
                if (isset($result['biz_content'])) {
                    $biz = $result['biz_content'];
                    $orderStatus = $biz['trade_status'] ?? $biz['order_status'] ?? $biz['result'] ?? 'unknown';
                    
                    return [
                        'success' => true,
                        'status' => strtolower($orderStatus),
                        'raw_response' => $result
                    ];
                }
            }

            return [
                'success' => false,
                'message' => 'Telebirr query failed: Status ' . $statusCode
            ];
        } catch (Exception $e) {
            $this->log("Telebirr Query Order Exception: " . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function initiatePayment($orderId, $amount, $subject, array $customerInfo = []): array
    {
        try {
            $paymentUrl = $this->createOrder($subject, $amount, $orderId);
            return [
                'success' => true,
                'payment_url' => $paymentUrl,
                'reference' => $orderId
            ];
        } catch (Exception $e) {
            $this->log("Telebirr Initiate Payment Error: " . $e->getMessage(), 'error');
            return [
                'success' => false, 
                'message' => $e->getMessage()
            ];
        }
    }

    protected function formatQueryString(array $params): string
    {
        ksort($params);
        $query = [];
        foreach ($params as $key => $value) {
            $query[] = $key . '=' . $value;
        }
        return implode('&', $query);
    }

    protected function normalizePrivateKey(string $key): string
    {
        $cleanKey = str_replace([
            '-----BEGIN PRIVATE KEY-----',
            '-----END PRIVATE KEY-----',
            '-----BEGIN RSA PRIVATE KEY-----',
            '-----END RSA PRIVATE KEY-----',
            "\r", "\n", ' ', "\t"
        ], '', $key);

        $chunked = chunk_split($cleanKey, 64, "\n");

        return "-----BEGIN PRIVATE KEY-----\n" . rtrim($chunked) . "\n-----END PRIVATE KEY-----";
    }

    protected function signString(string $data): string
    {
        $key = $this->normalizePrivateKey($this->privateKey);
        $rsa = \phpseclib3\Crypt\RSA::load($key)
            ->withHash('sha256')
            ->withMGFHash('sha256'); 
        
        return base64_encode($rsa->sign($data));
    }

    protected function createNonceStr(int $length = 32): string
    {
        return substr(str_shuffle(str_repeat('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length / 36))), 1, $length);
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

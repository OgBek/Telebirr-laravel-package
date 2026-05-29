<?php

namespace Bekambeyene\Telebirr;

use GuzzleHttp\Client;
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
    public function getFabricToken()
    {
        try {
            // Ensure no double slashes if base url has trailing slash
            $url = rtrim($this->baseUrl, '/') . '/payment/v1/token';
            
            $this->log("Telebirr: Requesting token from {$url}");

            $client = new Client(['verify' => false]);
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-APP-Key' => $this->fabricAppId,
                ],
                'timeout' => 30,
                'json' => [
                    'appSecret' => $this->appSecret,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();

            if ($statusCode >= 200 && $statusCode < 300) {
                $result = json_decode($body, true);
                if (isset($result['token'])) {
                    return $result['token'];
                }
            }

            $this->log('Telebirr Token Error: ' . $body, 'error');
            throw new Exception('Failed to get Telebirr token: ' . $statusCode);
        } catch (Exception $e) {
            $this->log('Telebirr Token Exception: ' . $e->getMessage(), 'error');
            throw $e;
        }
    }

    /**
     * Create Order
     * 
     * @param string $title
     * @param string|float $amount
     * @param string|null $merchOrderId
     * @param array $params Extra preorder parameters (e.g. ['trade_type' => 'InApp', 'payee_identifier' => '...', 'raw_request' => true])
     */
    public function createOrder($title, $amount, $merchOrderId = null, array $params = [])
    {
        $token = $this->getFabricToken();
        $nonce = $this->createNonceStr();
        $timestamp = $this->createTimeStamp();
        $merchantOrderId = $merchOrderId ?? $this->createMerchantOrderId();
        $this->log("Telebirr: Using Merchant Order ID: {$merchantOrderId}");
        $returnUrl = $this->returnUrl;
        $separator = (parse_url($returnUrl, PHP_URL_QUERY) == NULL) ? '?' : '&';
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

        // Merge any dynamic params
        $bizContent = array_merge($bizContent, $params);
        // Exclude internal SDK flags from bizContent
        unset($bizContent['raw_request']);

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

        // Make the request with unescaped slashes and a slightly longer timeout
        $client = new Client(['verify' => false]);
        $response = $client->post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-APP-Key' => $this->fabricAppId,
                'Authorization' => $token,
            ],
            'timeout' => 30,
            'body' => json_encode($requestParams, JSON_UNESCAPED_SLASHES),
        ]);

        $body = $response->getBody()->getContents();
        $statusCode = $response->getStatusCode();

        if ($statusCode >= 200 && $statusCode < 300) {
            $result = json_decode($body, true);
            
            $biz = $result['biz_content'] ?? [];
            $prepayId = $biz['prepay_id'] ?? $biz['prePayId'] ?? null;
            
            if ($prepayId) {
                if (isset($params['raw_request']) && $params['raw_request'] === true) {
                    return $this->getRawRequestString($prepayId, $params['trade_type'] ?? 'Checkout');
                }
                return $this->createRawRequest($prepayId, $returnUrl, $params['trade_type'] ?? 'Checkout');
            }
        }

        $this->log('Telebirr Create Order Error: ' . $body, 'error');
        throw new Exception('Failed to create Telebirr order: ' . $body);
    }

    /**
     * Get Raw Request query string for App/H5 evaluation
     */
    public function getRawRequestString($prepayId, $tradeType = 'Checkout', $returnUrl = null)
    {
        $maps = array(
            "appid" => $this->merchantAppId,
            "merch_code" => $this->merchantCode,
            "nonce_str" => $this->createNonceStr(),
            "prepay_id" => $prepayId,
            "timestamp" => $this->createTimeStamp(),
            "sign_type" => "SHA256WithRSA"
        );
        
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
        $sign = $this->signPKCS1($maps);
        
        $rawRequest = $rawRequest . 'sign=' . $sign;
        return $rawRequest;
    }

    protected function createRawRequest($prepayId, $returnUrl = null, $tradeType = 'Checkout')
    {
        // 1. Create the map of parameters to sign (only these 6, matching working TelebirrService)
        $map = [
            'appid' => $this->merchantAppId,
            'merch_code' => $this->merchantCode,
            'nonce_str' => $this->createNonceStr(),
            'prepay_id' => $prepayId,
            'timestamp' => $this->createTimeStamp(),
            'sign_type' => 'SHA256WithRSA'
        ];

        // 2. Sign the map (PSS padding via sign() -> signString() -> withMGFHash)
        $sign = $this->sign($map);

        // 3. Construct the query string exactly as per working TelebirrService
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
        
        $rawRequest = implode("&", $rawRequestArray);

        // 4. Construct the full URL
        $webBaseUrl = rtrim($this->webUrl, '?') . '?';
        $paymentUrl = $webBaseUrl . $rawRequest;
        
        $this->log("Telebirr H5 URL Generated: " . $paymentUrl);
        
        return $paymentUrl;
    }

    /**
     * Generate signature matching reference code logic
     */
    protected function signPKCS1($request)
    {
        $excludeFields = ['sign', 'sign_type', 'header', 'refund_info', 'openType', 'raw_request'];
        ksort($request);
        $stringApplet = '';

        foreach ($request as $key => $values) {
            if (in_array($key, $excludeFields) || is_null($values)) {
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

        $rsa = \phpseclib3\Crypt\RSA::load($this->privateKey)
            ->withHash('sha256')
            ->withPadding(\phpseclib3\Crypt\RSA::SIGNATURE_PKCS1); 
        
        return base64_encode($rsa->sign($sortedString));
    }

    /**
     * Generate signature matching reference code logic
     */
    protected function sign($request)
    {
        $excludeFields = ['sign', 'sign_type', 'header', 'refund_info', 'openType', 'raw_request'];
        ksort($request);
        $stringApplet = '';

        foreach ($request as $key => $values) {
            if (in_array($key, $excludeFields) || is_null($values)) {
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
        $this->log('Telebirr: Signature string: ' . $sortedString);
        return $this->signString($sortedString);
    }

    protected function sortedString($stringApplet)
    {
        $sortedArray = explode('&', $stringApplet);
        sort($sortedArray);
        return implode('&', $sortedArray);
    }

    /**
     * Verify payment status using Telebirr queryOrder API
     */
    public function verifyPayment($merchantOrderId)
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

            // Make the request with unescaped slashes and a slightly longer timeout
            $client = new Client(['verify' => false]);
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-APP-Key' => $this->fabricAppId,
                    'Authorization' => $token,
                ],
                'timeout' => 30,
                'body' => json_encode($requestParams, JSON_UNESCAPED_SLASHES),
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();

            $this->log("Telebirr Query HTTP Status: " . $statusCode);
            $this->log("Telebirr Query Raw Response: " . $body);

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
                'message' => 'Telebirr query failed: ' . $statusCode
            ];

        } catch (Exception $e) {
            $this->log('Telebirr Query Order Exception: ' . $e->getMessage(), 'error');
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function initiatePayment($orderId, $amount, $subject, $customerInfo = [])
    {
        try {
            // Use subject as the title
            $paymentUrl = $this->createOrder($subject, $amount, $orderId);
            
            return [
                'success' => true,
                'payment_url' => $paymentUrl,
                'reference' => $orderId // Return the order ID as reference
            ];
        } catch (Exception $e) {
            $this->log('Telebirr Initiate Payment Error: ' . $e->getMessage(), 'error');
            return [
                'success' => false, 
                'message' => $e->getMessage()
            ];
        }
    }

    protected function formatQueryString($params)
    {
        ksort($params);
        $query = [];
        foreach ($params as $key => $value) {
            $query[] = $key . '=' . $value;
        }
        return implode('&', $query);
    }

    protected function signString($data)
    {
        // Reference code uses setMGFHash, which in phpseclib triggers PSS padding.
        // We use phpseclib3's equivalent setup.
        $rsa = \phpseclib3\Crypt\RSA::load($this->privateKey)
            ->withHash('sha256')
            ->withMGFHash('sha256'); 
        
        $signature = $rsa->sign($data);
        
        return base64_encode($signature);
    }

    protected function createNonceStr($length = 32)
    {
        return substr(str_shuffle(str_repeat($x='0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length/strlen($x)) )),1,$length);
    }

    protected function createTimeStamp()
    {
        return (string)time();
    }

    protected function createMerchantOrderId()
    {
        return (string)floor(microtime(true) * 1000);
    }
}

<?php

namespace Bekambeyene\Telebirr;

class CreateOrderService
{
    public $req;
    public $BASE_URL;
    public $fabricAppId;
    public $appSecret;
    public $merchantAppId;
    public $merchantCode;
    public $notify_path;
    protected $privateKey;

    public function __construct($baseUrl, $req, $fabricAppId, $appSecret, $merchantAppId, $merchantCode, $privateKey = '')
    {
        $this->BASE_URL = $baseUrl;
        $this->req = $req;
        $this->fabricAppId = $fabricAppId;
        $this->appSecret = $appSecret;
        $this->merchantAppId = $merchantAppId;
        $this->merchantCode = $merchantCode;
        
        $envNotify = $_ENV['TELEBIRR_NOTIFY_URL'] ?? '';
        if ($envNotify) {
            $this->notify_path = rtrim($envNotify, '/');
        } else {
            $this->notify_path = "https://" . ($_SERVER['SERVER_NAME'] ?? 'localhost');
        }
        $this->privateKey = $privateKey;
    }

    /**
     * @Purpose: Creating Order
     *
     * @Param: no parameters it takes from the constructor
     * @Return: rawRequest|String
     */
    public function createOrder()
    {
        $title = $this->req->title ?? '';
        $amount = $this->req->amount ?? '0.00';

        $applyFabricTokenResult = new ApplyFabricToken(
            $this->BASE_URL,
            $this->fabricAppId,
            $this->appSecret,
            $this->merchantAppId
        );

        $result = json_decode($applyFabricTokenResult->applyFabricToken());
        $fabricToken = $result->token ?? '';

        $createOrderResult = $this->requestCreateOrder($fabricToken, $title, $amount);
        $prepayId = json_decode($createOrderResult)->biz_content->prepay_id ?? '';

        $rawRequest = $this->createRawRequest($prepayId);

        // Echo the rawRequest string to follow documentation
        echo trim((string)$rawRequest);

        return $rawRequest;
    }

    /**
     * @Purpose: Requests CreateOrder
     *
     * @Param: fabricToken|String title|string amount|string
     * @Return: String | Boolean
     */
    public function requestCreateOrder($fabricToken, $title, $amount)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, rtrim($this->BASE_URL, '/') . '/payment/v1/merchant/preOrder');
        curl_setopt($ch, CURLOPT_POST, 1);

        // Header parameters
        $headers = array(
            "Content-Type: application/json",
            "X-APP-Key: " . $this->fabricAppId,
            "Authorization: " . $fabricToken
        );
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // Body parameters
        $payload = $this->createRequestObject($title, $amount);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE); // for dev environment only
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $server_output = curl_exec($ch);
        curl_close($ch);

        return $server_output;
    }

    /**
     * @Purpose: Creating a new merchantOrderId
     *
     * @Param: no parameters
     * @Return: returns a string format of time (UTC)
     */
    public function createMerchantOrderId_()
    {
        return (string)floor(microtime(true) * 1000);
    }

    /**
     * @Purpose: Creating Request Object
     *
     * @Param: title|String and amount|String
     * @Return: Json encoded string
     */
    public function createRequestObject($title, $amount)
    {
        $req = array(
            'nonce_str' => $this->createNonceStr(),
            'method' => 'payment.preorder',
            'timestamp' => $this->createTimeStamp(),
            'version' => '1.0',
            'biz_content' => [],
        );

        $biz = array(
            'notify_url' => $this->notify_path . '/api/payment.php', // set your notify end point
            'business_type' => 'BuyGoods',
            'trade_type' => 'InApp',
            'appid' => $this->merchantAppId,
            'merch_code' => $this->merchantCode,
            'merch_order_id' => $this->createMerchantOrderId_(),
            'title' => $title,
            'total_amount' => $amount,
            'trans_currency' => 'ETB',
            'timeout_express' => '120m',
            'payee_identifier' => '220311',
            'payee_identifier_type' => '04',
            'payee_type' => '5000',
        );

        $req['biz_content'] = $biz;
        $req['sign_type'] = 'SHA256WithRSA';
        $req['sign'] = $this->sign($req);

        return json_encode($req);
    }

    /**
     * @Purpose: Create a rawRequest string for H5 page to start pay
     *
     * @Param: prepayId returned from the createRequestObject
     * @Return: rawRequest|string
     */
    public function createRawRequest($prepayId)
    {
        $maps = array(
            "appid" => $this->merchantAppId,
            "merch_code" => $this->merchantCode,
            "nonce_str" => $this->createNonceStr(),
            "prepay_id" => $prepayId,
            "timestamp" => $this->createTimeStamp(),
            "sign_type" => "SHA256WithRSA"
        );
        
        $rawRequest = "";
        foreach ($maps as $map => $m) {
                $rawRequest .= $map . '=' . $m."&";
        }
        $sign = $this->signPKCS1($maps);
        // order by ascii in array
        $rawRequest = $rawRequest.'sign='. urlencode($sign);

        return $rawRequest;
    }

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
                    $stringApplet .= ($stringApplet === '' ? '' : '&') . $value . '=' . $singleValue;
                }
            } else {
                $stringApplet .= ($stringApplet === '' ? '' : '&') . $key . '=' . $values;
            }
        }

        $sortedArray = explode('&', $stringApplet);
        sort($sortedArray);
        $sortedString = implode('&', $sortedArray);

        $rsa = \phpseclib3\Crypt\RSA::load($this->privateKey)
            ->withHash('sha256')
            ->withPadding(\phpseclib3\Crypt\RSA::SIGNATURE_PKCS1); 
        
        return base64_encode($rsa->sign($sortedString));
    }

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
                    $stringApplet .= ($stringApplet === '' ? '' : '&') . $value . '=' . $singleValue;
                }
            } else {
                $stringApplet .= ($stringApplet === '' ? '' : '&') . $key . '=' . $values;
            }
        }

        $sortedArray = explode('&', $stringApplet);
        sort($sortedArray);
        $sortedString = implode('&', $sortedArray);

        $rsa = \phpseclib3\Crypt\RSA::load($this->privateKey)
            ->withHash('sha256')
            ->withMGFHash('sha256'); 
        
        return base64_encode($rsa->sign($sortedString));
    }

    protected function createNonceStr($length = 32)
    {
        return substr(str_shuffle(str_repeat($x='0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length/strlen($x)) )),1,$length);
    }

    protected function createTimeStamp()
    {
        return (string)time();
    }
}

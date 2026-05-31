<?php

namespace Bekambeyene\Telebirr\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Bekambeyene\Telebirr\Providers\TelebirrServiceProvider;
use Bekambeyene\Telebirr\Facades\Telebirr;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            TelebirrServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app)
    {
        return [
            'Telebirr' => Telebirr::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Set up test configuration
        $app['config']->set('telebirr.base_url', 'https://test.ethiotelebirr.et/apiaccess/payment/gateway');
        $app['config']->set('telebirr.web_url', 'https://test.ethiotelebirr.et/payment/web/paygate');
        $app['config']->set('telebirr.fabric_app_id', 'test_fabric_app_id');
        $app['config']->set('telebirr.app_secret', 'test_app_secret');
        $app['config']->set('telebirr.merchant_app_id', 'test_merchant_app_id');
        $app['config']->set('telebirr.merchant_code', 'test_merchant_code');
        $app['config']->set('telebirr.notify_url', 'https://example.com/notify');
        $app['config']->set('telebirr.return_url', 'https://example.com/return');
        $app['config']->set('telebirr.ssl_verify', false);
        
        // Generate temporary test keys
        $res = openssl_pkey_new([
            "private_key_bits" => 2048,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        ]);
        
        openssl_pkey_export($res, $privateKey);
        $publicKeyDetails = openssl_pkey_get_details($res);
        $publicKey = $publicKeyDetails['key'];

        $app['config']->set('telebirr.public_key', $publicKey);
        $app['config']->set('telebirr.private_key', $privateKey);
    }
}

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
        
        /**
         * @internal 
         * @warning DO NOT USE THIS IN PRODUCTION. 
         * 1024-bit RSA keys are generated here on-the-fly strictly to accelerate the test suite. 
         * Production Telebirr environments strictly require 2048-bit (or higher) RSA keys generated 
         * using the official Ethio Telecom tool.
         */
        static $privateKeyCache = null;
        static $publicKeyCache = null;

        if (!$privateKeyCache) {
            $key = \phpseclib3\Crypt\RSA::createKey(1024);
            $privateKeyCache = $key->toString('PKCS1');
            $publicKeyCache = $key->getPublicKey()->toString('PKCS8');
        }

        $privateKey = $privateKeyCache;
        $publicKey = $publicKeyCache;

        $app['config']->set('telebirr.public_key', $publicKey);
        $app['config']->set('telebirr.private_key', $privateKey);
    }
}

<?php

namespace Bekambeyene\Telebirr\Tests\Unit;

use Bekambeyene\Telebirr\Exceptions\InvalidSignatureException;
use Bekambeyene\Telebirr\Exceptions\ReplayAttackException;
use Bekambeyene\Telebirr\Exceptions\TimestampExpiredException;
use Bekambeyene\Telebirr\Facades\Telebirr;
use Bekambeyene\Telebirr\Services\SignatureService;
use Bekambeyene\Telebirr\Services\TelebirrHttpClient;
use Bekambeyene\Telebirr\Services\TokenManager;
use Bekambeyene\Telebirr\TelebirrClient;
use Bekambeyene\Telebirr\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Mockery;

class WebhookHelperTest extends TestCase
{
    protected SignatureService $signatureService;
    protected string $privateKey;
    protected string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signatureService = new SignatureService();
        $this->privateKey = config('telebirr.private_key');
        $this->publicKey = config('telebirr.public_key');
    }

    public function test_canonical_string_snapshot_verifies_exact_components_sorting()
    {
        $params = [
            'appid' => 'app123',
            'nonce_str' => 'nonce123',
            'timestamp' => '1620000000',
            'biz_content' => [
                'z_field' => 'zebra',
                'a_field' => 'alpha',
                'empty' => '',
                'nil' => null,
                'bool_true' => true,
                'bool_false' => false,
            ]
        ];

        $canonical = $this->signatureService->buildCanonicalString($params);

        // Verification details:
        // - biz_content fields flattened
        // - sorted alphabetically: a_field, appid, bool_false, bool_true, empty, nonce_str, timestamp, z_field
        // - bool_true is '1', bool_false is '0'
        // - nil (null) is excluded
        // - empty string is preserved
        $expected = 'a_field=alpha&appid=app123&bool_false=0&bool_true=1&empty=&nonce_str=nonce123&timestamp=1620000000&z_field=zebra';
        $this->assertSame($expected, $canonical);
    }

    public function test_handle_webhook_success_with_pss_padding()
    {
        config(['telebirr.padding' => 'pss']);
        config(['telebirr.webhook_tolerance_seconds' => 300]);

        $payload = [
            'appid' => config('telebirr.merchant_app_id'),
            'merch_code' => config('telebirr.merchant_code'),
            'nonce_str' => 'unique_nonce_123',
            'prepay_id' => 'prepay_999',
            'timestamp' => (string) time(),
        ];

        // Sign using PSS (the default)
        $signature = $this->signatureService->signPSS($payload, $this->privateKey);
        $payload['sign'] = $signature;

        $request = Request::create('/payment/notification', 'POST', $payload);

        $client = new TelebirrClient(
            config('telebirr'),
            Mockery::mock(TokenManager::class),
            $this->signatureService,
            Mockery::mock(TelebirrHttpClient::class)
        );

        $result = $client->handleWebhook($request);

        $this->assertArrayNotHasKey('sign', $result);
        $this->assertArrayNotHasKey('sign_type', $result);
        $this->assertEquals('prepay_999', $result['prepay_id']);
    }

    public function test_handle_webhook_success_with_pkcs1_padding()
    {
        config(['telebirr.padding' => 'pkcs1']);
        config(['telebirr.webhook_tolerance_seconds' => 300]);

        $payload = [
            'appid' => config('telebirr.merchant_app_id'),
            'merch_code' => config('telebirr.merchant_code'),
            'nonce_str' => 'unique_nonce_456',
            'prepay_id' => 'prepay_888',
            'timestamp' => (string) time(),
        ];

        // Sign using PKCS1
        $signature = $this->signatureService->signPKCS1($payload, $this->privateKey);
        $payload['sign'] = $signature;

        $request = Request::create('/payment/notification', 'POST', $payload);

        $client = new TelebirrClient(
            config('telebirr'),
            Mockery::mock(TokenManager::class),
            $this->signatureService,
            Mockery::mock(TelebirrHttpClient::class)
        );

        $result = $client->handleWebhook($request);

        $this->assertArrayNotHasKey('sign', $result);
        $this->assertEquals('prepay_888', $result['prepay_id']);
    }

    public function test_handle_webhook_throws_invalid_signature_exception_on_bad_signature()
    {
        $payload = [
            'appid' => config('telebirr.merchant_app_id'),
            'merch_code' => config('telebirr.merchant_code'),
            'nonce_str' => 'nonce_bad',
            'prepay_id' => 'prepay_000',
            'timestamp' => (string) time(),
            'sign' => 'invalid_signature_base64_data',
        ];

        $request = Request::create('/payment/notification', 'POST', $payload);

        $client = new TelebirrClient(
            config('telebirr'),
            Mockery::mock(TokenManager::class),
            $this->signatureService,
            Mockery::mock(TelebirrHttpClient::class)
        );

        $this->expectException(InvalidSignatureException::class);
        $client->handleWebhook($request);
    }

    public function test_handle_webhook_throws_timestamp_expired_exception_on_stale_request()
    {
        config(['telebirr.webhook_tolerance_seconds' => 300]);

        $payload = [
            'appid' => config('telebirr.merchant_app_id'),
            'merch_code' => config('telebirr.merchant_code'),
            'nonce_str' => 'nonce_expired',
            'prepay_id' => 'prepay_111',
            // Outside 300 seconds window (10 minutes ago)
            'timestamp' => (string) (time() - 600),
        ];

        $signature = $this->signatureService->signPSS($payload, $this->privateKey);
        $payload['sign'] = $signature;

        $request = Request::create('/payment/notification', 'POST', $payload);

        $client = new TelebirrClient(
            config('telebirr'),
            Mockery::mock(TokenManager::class),
            $this->signatureService,
            Mockery::mock(TelebirrHttpClient::class)
        );

        $this->expectException(TimestampExpiredException::class);
        $client->handleWebhook($request);
    }

    public function test_handle_webhook_throws_replay_attack_exception_on_duplicate_nonce()
    {
        config(['telebirr.webhook_tolerance_seconds' => 300]);

        $payload = [
            'appid' => config('telebirr.merchant_app_id'),
            'merch_code' => config('telebirr.merchant_code'),
            'nonce_str' => 'replay_nonce_789',
            'prepay_id' => 'prepay_222',
            'timestamp' => (string) time(),
        ];

        $signature = $this->signatureService->signPSS($payload, $this->privateKey);
        $payload['sign'] = $signature;

        $request = Request::create('/payment/notification', 'POST', $payload);

        $client = new TelebirrClient(
            config('telebirr'),
            Mockery::mock(TokenManager::class),
            $this->signatureService,
            Mockery::mock(TelebirrHttpClient::class)
        );

        // Put the nonce in Cache so it triggers replay prevention
        Cache::shouldReceive('add')
            ->once()
            ->with('telebirr_nonce_replay_nonce_789', true, 300)
            ->andReturn(false); // Simulated already exists

        $this->expectException(ReplayAttackException::class);
        $client->handleWebhook($request);
    }
}

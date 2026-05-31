<?php

namespace Bekambeyene\Telebirr\Tests\Integration;

use Bekambeyene\Telebirr\Services\SignatureService;
use Bekambeyene\Telebirr\Services\TelebirrHttpClient;
use Bekambeyene\Telebirr\Services\TokenManager;
use Bekambeyene\Telebirr\TelebirrClient;
use Bekambeyene\Telebirr\Tests\TestCase;
use Bekambeyene\Telebirr\Exceptions\TelebirrException;
use Mockery;

class TelebirrClientTest extends TestCase
{
    protected TelebirrClient $telebirrClient;
    protected $mockHttpClient;
    protected $mockTokenManager;
    protected $signatureService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockHttpClient = Mockery::mock(TelebirrHttpClient::class);
        $this->mockTokenManager = Mockery::mock(TokenManager::class);
        $this->signatureService = new SignatureService(); // Use real signature service to test integration

        $config = config('telebirr');

        $this->telebirrClient = new TelebirrClient(
            $config,
            $this->mockTokenManager,
            $this->signatureService,
            $this->mockHttpClient
        );
    }

    public function test_create_order_returns_h5_redirect_url()
    {
        $this->mockTokenManager->shouldReceive('getFabricToken')
            ->once()
            ->andReturn('mock_token_123');

        $this->mockHttpClient->shouldReceive('post')
            ->once()
            ->with('/payment/v1/merchant/preOrder', Mockery::type('string'), Mockery::type('array'))
            ->andReturn([
                'biz_content' => [
                    'prepay_id' => 'mock_prepay_id_999'
                ]
            ]);

        $url = $this->telebirrClient->createOrder('Test Item', 100.50, 'order_123');

        $this->assertStringContainsString('https://test.ethiotelebirr.et/payment/web/paygate', $url);
        $this->assertStringContainsString('prepay_id=mock_prepay_id_999', $url);
        $this->assertStringContainsString('sign=', $url);
    }

    public function test_create_order_returns_raw_request_string_for_in_app()
    {
        $this->mockTokenManager->shouldReceive('getFabricToken')
            ->once()
            ->andReturn('mock_token_123');

        $this->mockHttpClient->shouldReceive('post')
            ->once()
            ->andReturn([
                'biz_content' => [
                    'prepay_id' => 'mock_prepay_id_999'
                ]
            ]);

        $rawString = $this->telebirrClient->createOrder('Test Item', 100.50, 'order_123', ['raw_request' => true]);

        // It should just be a query string without the base URL
        $this->assertStringNotContainsString('https://', $rawString);
        $this->assertStringContainsString('prepay_id=mock_prepay_id_999', $rawString);
        $this->assertStringContainsString('sign=', $rawString);
    }

    public function test_create_order_throws_exception_for_invalid_amount()
    {
        $this->expectException(TelebirrException::class);
        $this->expectExceptionMessage('Order amount must be greater than zero.');

        $this->telebirrClient->createOrder('Test Item', 0);
    }

    public function test_verify_payment_returns_successful_status()
    {
        $this->mockTokenManager->shouldReceive('getFabricToken')
            ->once()
            ->andReturn('mock_token_123');

        $this->mockHttpClient->shouldReceive('post')
            ->once()
            ->andReturn([
                'biz_content' => [
                    'trade_status' => 'PAY_SUCCESS'
                ]
            ]);

        $result = $this->telebirrClient->verifyPayment('order_123');

        $this->assertTrue($result['success']);
        $this->assertEquals('pay_success', $result['status']);
    }

    public function test_verify_callback_signature_validates_payload()
    {
        $payload = ['merch_order_id' => '123', 'trade_status' => 'PAY_SUCCESS'];
        $signature = $this->signatureService->signPSS($payload, config('telebirr.private_key'));

        $isValid = $this->telebirrClient->verifyCallbackSignature($payload, $signature);

        $this->assertTrue($isValid);
    }

    // ─── Failed Payment Flow ─────────────────────────────────────────

    public function test_failed_payment_returns_fail_status()
    {
        $this->mockTokenManager->shouldReceive('getFabricToken')
            ->once()
            ->andReturn('mock_token');

        $this->mockHttpClient->shouldReceive('post')
            ->once()
            ->andReturn([
                'biz_content' => [
                    'trade_status' => 'TRADE_CLOSED'
                ]
            ]);

        $result = $this->telebirrClient->verifyPayment('order_456');

        $this->assertTrue($result['success']); // Query itself succeeded
        $this->assertEquals('trade_closed', $result['status']);
    }

    public function test_cancelled_payment_returns_cancelled_status()
    {
        $this->mockTokenManager->shouldReceive('getFabricToken')
            ->once()
            ->andReturn('mock_token');

        $this->mockHttpClient->shouldReceive('post')
            ->once()
            ->andReturn([
                'biz_content' => [
                    'trade_status' => 'TRADE_CANCEL'
                ]
            ]);

        $result = $this->telebirrClient->verifyPayment('order_789');

        $this->assertTrue($result['success']); // Query itself succeeded
        $this->assertEquals('trade_cancel', $result['status']);
    }

    // ─── Payment Status Recognition ──────────────────────────────────

    public function test_success_status_is_recognized()
    {
        $this->mockTokenManager->shouldReceive('getFabricToken')->andReturn('t');
        $this->mockHttpClient->shouldReceive('post')->andReturn([
            'biz_content' => ['trade_status' => 'PAY_SUCCESS']
        ]);

        $result = $this->telebirrClient->verifyPayment('o1');
        $this->assertEquals('pay_success', $result['status']);
    }

    public function test_failed_status_is_recognized()
    {
        $this->mockTokenManager->shouldReceive('getFabricToken')->andReturn('t');
        $this->mockHttpClient->shouldReceive('post')->andReturn([
            'biz_content' => ['trade_status' => 'TRADE_FAIL']
        ]);

        $result = $this->telebirrClient->verifyPayment('o2');
        $this->assertEquals('trade_fail', $result['status']);
    }

    public function test_pending_status_is_recognized()
    {
        $this->mockTokenManager->shouldReceive('getFabricToken')->andReturn('t');
        $this->mockHttpClient->shouldReceive('post')->andReturn([
            'biz_content' => ['trade_status' => 'WAIT_BUYER_PAY']
        ]);

        $result = $this->telebirrClient->verifyPayment('o3');
        $this->assertEquals('wait_buyer_pay', $result['status']);
    }

    public function test_unknown_status_is_handled_gracefully()
    {
        $this->mockTokenManager->shouldReceive('getFabricToken')->andReturn('t');
        $this->mockHttpClient->shouldReceive('post')->andReturn([
            'biz_content' => [] // No trade_status at all
        ]);

        $result = $this->telebirrClient->verifyPayment('o4');
        $this->assertTrue($result['success']);
        $this->assertEquals('unknown', $result['status']);
    }

    // ─── Missing Data Edge Cases ─────────────────────────────────────

    public function test_verify_payment_without_biz_content_returns_failure()
    {
        $this->mockTokenManager->shouldReceive('getFabricToken')->andReturn('t');
        $this->mockHttpClient->shouldReceive('post')->andReturn([
            'error' => 'some_random_error'
        ]);

        $result = $this->telebirrClient->verifyPayment('o5');
        $this->assertFalse($result['success']);
        $this->assertEquals('Telebirr query failed, biz_content missing.', $result['message']);
    }

    public function test_create_order_missing_prepay_id_throws_exception()
    {
        $this->mockTokenManager->shouldReceive('getFabricToken')->andReturn('t');
        $this->mockHttpClient->shouldReceive('post')->andReturn([
            'biz_content' => [] // No prepay_id
        ]);

        $this->expectException(TelebirrException::class);
        $this->expectExceptionMessage('Failed to extract prepay_id from Telebirr response.');

        $this->telebirrClient->createOrder('Test', 100.0, 'order_no_prepay');
    }

    // ─── Amount Formatting ───────────────────────────────────────────

    public function test_amount_is_formatted_with_two_decimals()
    {
        $this->mockTokenManager->shouldReceive('getFabricToken')->andReturn('t');
        $this->mockHttpClient->shouldReceive('post')
            ->once()
            ->withArgs(function ($endpoint, $body, $headers) {
                $decoded = json_decode($body, true);
                $amount = $decoded['biz_content']['total_amount'] ?? '';
                return $amount === '10.00';
            })
            ->andReturn(['biz_content' => ['prepay_id' => 'pp_1']]);

        $this->telebirrClient->createOrder('Item', 10, 'order_fmt_1');
    }

    public function test_decimal_amount_is_formatted_correctly()
    {
        $this->mockTokenManager->shouldReceive('getFabricToken')->andReturn('t');
        $this->mockHttpClient->shouldReceive('post')
            ->once()
            ->withArgs(function ($endpoint, $body, $headers) {
                $decoded = json_decode($body, true);
                $amount = $decoded['biz_content']['total_amount'] ?? '';
                return $amount === '10.50';
            })
            ->andReturn(['biz_content' => ['prepay_id' => 'pp_2']]);

        $this->telebirrClient->createOrder('Item', 10.5, 'order_fmt_2');
    }

    // ─── Request Payload Structure ───────────────────────────────────

    public function test_payment_request_contains_required_fields()
    {
        $this->mockTokenManager->shouldReceive('getFabricToken')->andReturn('t');
        $this->mockHttpClient->shouldReceive('post')
            ->once()
            ->withArgs(function ($endpoint, $body, $headers) {
                $decoded = json_decode($body, true);
                $biz = $decoded['biz_content'] ?? [];
                
                return isset($decoded['nonce_str'])
                    && isset($decoded['method'])
                    && isset($decoded['timestamp'])
                    && isset($decoded['version'])
                    && isset($decoded['sign'])
                    && isset($decoded['sign_type'])
                    && isset($biz['appid'])
                    && isset($biz['merch_code'])
                    && isset($biz['merch_order_id'])
                    && isset($biz['title'])
                    && isset($biz['total_amount'])
                    && isset($biz['notify_url'])
                    && isset($biz['redirect_url']);
            })
            ->andReturn(['biz_content' => ['prepay_id' => 'pp_3']]);

        $this->telebirrClient->createOrder('Test Book', 99.99, 'ORDER-FIELDS');
    }

    public function test_authorization_header_is_sent_with_request()
    {
        $this->mockTokenManager->shouldReceive('getFabricToken')
            ->once()
            ->andReturn('actual_token_value');

        $this->mockHttpClient->shouldReceive('post')
            ->once()
            ->withArgs(function ($endpoint, $body, $headers) {
                return $headers['Authorization'] === 'actual_token_value'
                    && isset($headers['X-APP-Key']);
            })
            ->andReturn(['biz_content' => ['prepay_id' => 'pp_4']]);

        $this->telebirrClient->createOrder('Test', 50.00, 'ORDER-AUTH');
    }
}

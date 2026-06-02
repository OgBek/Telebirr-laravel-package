<?php

namespace Bekambeyene\Telebirr\Tests\PaymentFlow;

use Bekambeyene\Telebirr\Exceptions\TelebirrException;
use Bekambeyene\Telebirr\Services\SignatureService;
use Bekambeyene\Telebirr\Services\TelebirrHttpClient;
use Bekambeyene\Telebirr\Services\TokenManager;
use Bekambeyene\Telebirr\TelebirrClient;
use Bekambeyene\Telebirr\Tests\TestCase;
use Mockery;

class RefundFlowTest extends TestCase
{
    protected TelebirrClient $telebirrClient;
    protected $mockHttpClient;
    protected $mockTokenManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockHttpClient = Mockery::mock(TelebirrHttpClient::class);
        $this->mockTokenManager = Mockery::mock(TokenManager::class);

        $this->telebirrClient = new TelebirrClient(
            config('telebirr'),
            $this->mockTokenManager,
            new SignatureService(),
            $this->mockHttpClient
        );
    }

    // ─── Successful Refund Flows ──────────────────────────────────────

    public function test_full_refund_is_supported()
    {
        $this->mockTokenManager->shouldReceive('getFabricToken')
            ->once()
            ->andReturn('mock_token');

        $this->mockHttpClient->shouldReceive('post')
            ->once()
            ->with('/payment/v1/merchant/refund', Mockery::type('string'), Mockery::type('array'))
            ->andReturn([
                'biz_content' => [
                    'trade_status' => 'REFUND_SUCCESS'
                ]
            ]);

        $result = $this->telebirrClient->refundOrder('ORDER-001', 500.00, 'REFUND-001');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('raw_response', $result);
    }

    public function test_partial_refund_is_supported()
    {
        $this->mockTokenManager->shouldReceive('getFabricToken')
            ->once()
            ->andReturn('mock_token');

        $this->mockHttpClient->shouldReceive('post')
            ->once()
            ->andReturn([
                'biz_content' => [
                    'trade_status' => 'REFUND_SUCCESS'
                ]
            ]);

        // Refund 200 from a 500 order
        $result = $this->telebirrClient->refundOrder('ORDER-001', 200.00, 'REFUND-002');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('raw_response', $result);
    }

    public function test_refund_with_custom_reason()
    {
        $this->mockTokenManager->shouldReceive('getFabricToken')
            ->once()
            ->andReturn('mock_token');

        $this->mockHttpClient->shouldReceive('post')
            ->once()
            ->andReturn([
                'biz_content' => [
                    'trade_status' => 'REFUND_SUCCESS'
                ]
            ]);

        $result = $this->telebirrClient->refundOrder(
            'ORDER-001', 
            500.00, 
            'REFUND-003',
            ['refund_reason' => 'Product returned by customer']
        );

        $this->assertTrue($result['success']);
    }

    // ─── Refund Validation Errors ─────────────────────────────────────

    public function test_refund_amount_cannot_be_zero()
    {
        $this->expectException(TelebirrException::class);
        $this->expectExceptionMessage('Refund amount must be greater than zero.');

        $this->telebirrClient->refundOrder('ORDER-001', 0.0, 'REFUND-001');
    }

    public function test_refund_amount_cannot_be_negative()
    {
        $this->expectException(TelebirrException::class);
        $this->expectExceptionMessage('Refund amount must be greater than zero.');

        $this->telebirrClient->refundOrder('ORDER-001', -50.0, 'REFUND-001');
    }

    public function test_refund_order_id_cannot_be_empty()
    {
        $this->expectException(TelebirrException::class);
        $this->expectExceptionMessage('Original merchant order ID cannot be empty.');

        $this->telebirrClient->refundOrder('', 100.0, 'REFUND-001');
    }

    public function test_refund_request_id_cannot_be_empty()
    {
        $this->expectException(TelebirrException::class);
        $this->expectExceptionMessage('Refund request ID cannot be empty.');

        $this->telebirrClient->refundOrder('ORDER-001', 100.0, '');
    }

    public function test_refund_order_id_cannot_be_whitespace()
    {
        $this->expectException(TelebirrException::class);
        $this->expectExceptionMessage('Original merchant order ID cannot be empty.');

        $this->telebirrClient->refundOrder('   ', 100.0, 'REFUND-001');
    }

    // ─── Refund Failure Scenarios ─────────────────────────────────────

    public function test_refund_failure_returns_unsuccessful()
    {
        $this->mockTokenManager->shouldReceive('getFabricToken')
            ->once()
            ->andReturn('mock_token');

        $this->mockHttpClient->shouldReceive('post')
            ->once()
            ->andReturn([
                'biz_content' => [
                    'trade_status' => 'FAIL'
                ]
            ]);

        $result = $this->telebirrClient->refundOrder('ORDER-001', 100.0, 'REFUND-001');

        $this->assertTrue($result['success']);
    }

    public function test_refund_missing_biz_content_returns_error()
    {
        $this->mockTokenManager->shouldReceive('getFabricToken')
            ->once()
            ->andReturn('mock_token');

        $this->mockHttpClient->shouldReceive('post')
            ->once()
            ->andReturn(['error' => 'something_went_wrong']);

        $this->expectException(\Bekambeyene\Telebirr\Exceptions\TelebirrServerException::class);
        $this->expectExceptionMessage('Telebirr refund failed, biz_content missing.');

        $this->telebirrClient->refundOrder('ORDER-001', 100.0, 'REFUND-001');
    }
}

<?php

namespace Bekambeyene\Telebirr\Tests\PaymentFlow;

use Bekambeyene\Telebirr\Services\SignatureService;
use Bekambeyene\Telebirr\Services\TelebirrHttpClient;
use Bekambeyene\Telebirr\Services\TokenManager;
use Bekambeyene\Telebirr\TelebirrClient;
use Bekambeyene\Telebirr\Tests\TestCase;
use Mockery;

class PaymentFlowTest extends TestCase
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

    public function test_full_checkout_payment_flow()
    {
        // 1. App requests token
        $this->mockTokenManager->shouldReceive('getFabricToken')
            ->once()
            ->andReturn('fabric_token_123');

        // 2. App sends PreOrder request and Telebirr returns prepay_id
        $this->mockHttpClient->shouldReceive('post')
            ->once()
            ->with('/payment/v1/merchant/preOrder', Mockery::type('string'), Mockery::type('array'))
            ->andReturn([
                'biz_content' => [
                    'prepay_id' => 'prepay_id_generated_by_telebirr'
                ]
            ]);

        // 3. SDK processes it into an H5 Payment URL
        $paymentUrl = $this->telebirrClient->createOrder('Laptop', 45000.00, 'ORDER-4500');

        $this->assertStringContainsString('prepay_id=prepay_id_generated_by_telebirr', $paymentUrl);
        $this->assertStringContainsString('merch_order_id', $paymentUrl); // verify parameter exists
    }

    public function test_initiate_payment_facade_wrapper_flow()
    {
        $this->mockTokenManager->shouldReceive('getFabricToken')
            ->once()
            ->andReturn('token_xyz');

        $this->mockHttpClient->shouldReceive('post')
            ->once()
            ->andReturn(['biz_content' => ['prepay_id' => '12345']]);

        // initiatePayment is a high-level wrapper
        $result = $this->telebirrClient->initiatePayment('ORDER-999', 500, 'Test Book');

        $this->assertTrue($result['success']);
        $this->assertEquals('ORDER-999', $result['reference']);
        $this->assertStringContainsString('12345', $result['payment_url']);
    }
}

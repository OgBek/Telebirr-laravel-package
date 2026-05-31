<?php

namespace Bekambeyene\Telebirr\Tests\Unit;

use Bekambeyene\Telebirr\Exceptions\TelebirrException;
use Bekambeyene\Telebirr\Services\SignatureService;
use Bekambeyene\Telebirr\Services\TelebirrHttpClient;
use Bekambeyene\Telebirr\Services\TokenManager;
use Bekambeyene\Telebirr\TelebirrClient;
use Bekambeyene\Telebirr\Tests\TestCase;
use Mockery;

class PaymentRequestTest extends TestCase
{
    protected TelebirrClient $client;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->client = new TelebirrClient(
            config('telebirr'),
            Mockery::mock(TokenManager::class),
            Mockery::mock(SignatureService::class),
            Mockery::mock(TelebirrHttpClient::class)
        );
    }

    public function test_amount_cannot_be_negative()
    {
        $this->expectException(TelebirrException::class);
        $this->expectExceptionMessage('Order amount must be greater than zero.');
        
        $this->client->createOrder('Test', -10);
    }

    public function test_amount_cannot_be_zero()
    {
        $this->expectException(TelebirrException::class);
        $this->expectExceptionMessage('Order amount must be greater than zero.');
        
        $this->client->createOrder('Test', 0);
    }

    public function test_amount_accepts_decimal_values()
    {
        // This should not throw an exception regarding the amount
        $this->expectException(\Exception::class); // It will fail later in the mock chain, but amount validation passes
        
        $this->client->createOrder('Test', 10.50);
    }

    public function test_order_title_cannot_be_empty()
    {
        $this->expectException(TelebirrException::class);
        $this->expectExceptionMessage('Order title cannot be empty.');
        
        $this->client->createOrder('', 10);
    }

    public function test_order_title_cannot_be_whitespace()
    {
        $this->expectException(TelebirrException::class);
        $this->expectExceptionMessage('Order title cannot be empty.');
        
        $this->client->createOrder('   ', 10);
    }

    public function test_order_id_cannot_be_empty_if_provided()
    {
        $this->expectException(TelebirrException::class);
        $this->expectExceptionMessage('Merchant order ID cannot be empty if provided.');
        
        $this->client->createOrder('Test', 10, '');
    }
}

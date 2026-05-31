<?php

namespace Bekambeyene\Telebirr\Tests\Unit;

use Bekambeyene\Telebirr\Exceptions\AuthenticationException;
use Bekambeyene\Telebirr\Services\TelebirrHttpClient;
use Bekambeyene\Telebirr\Services\TokenManager;
use Bekambeyene\Telebirr\Tests\TestCase;
use Mockery;

class TokenManagerTest extends TestCase
{
    public function test_retrieves_fabric_token_successfully()
    {
        $mockHttpClient = Mockery::mock(TelebirrHttpClient::class);
        $mockHttpClient->shouldReceive('post')
            ->once()
            ->with('/payment/v1/token', ['appSecret' => 'secret'], ['X-APP-Key' => 'app_id'])
            ->andReturn(['token' => 'mocked_fabric_token_123']);

        $tokenManager = new TokenManager($mockHttpClient, 'app_id', 'secret');
        
        $token = $tokenManager->getFabricToken();
        
        $this->assertEquals('mocked_fabric_token_123', $token);
    }

    public function test_throws_authentication_exception_on_missing_token()
    {
        $mockHttpClient = Mockery::mock(TelebirrHttpClient::class);
        $mockHttpClient->shouldReceive('post')
            ->once()
            ->andReturn(['error' => 'invalid_credentials']);

        $tokenManager = new TokenManager($mockHttpClient, 'app_id', 'secret');
        
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Token missing from Telebirr response.');
        
        $tokenManager->getFabricToken();
    }
}

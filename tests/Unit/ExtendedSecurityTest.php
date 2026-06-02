<?php

namespace Bekambeyene\Telebirr\Tests\Unit;

use Bekambeyene\Telebirr\Tests\TestCase;
use Bekambeyene\Telebirr\Facades\Telebirr;
use Bekambeyene\Telebirr\Exceptions\TelebirrException;
use Illuminate\Support\Facades\Cache;
use Bekambeyene\Telebirr\TelebirrClient;
use Bekambeyene\Telebirr\Services\TokenManager;
use Bekambeyene\Telebirr\Services\TelebirrHttpClient;
use Illuminate\Support\Facades\Log;

class ExtendedSecurityTest extends TestCase
{
    public function test_malformed_signature_throws_exception()
    {
        $payload = [
            'msisdn' => '251911223344',
            'outTradeNo' => '12345',
            'totalAmount' => '100.0',
            'tradeDate' => time(),
            'tradeStatus' => 2,
            'transactionNo' => 'transaction123'
        ];

        // Ensure fake signature doesn't pass verification
        $this->assertFalse(Telebirr::verifyCallbackSignature($payload, 'invalid_base64_signature_here'));
    }

    public function test_invalid_public_key_handling()
    {
        $this->app['config']->set('telebirr.public_key', '-----BEGIN PUBLIC KEY-----\nINVALID_KEY\n-----END PUBLIC KEY-----');
        
        $client = $this->app->make(\Bekambeyene\Telebirr\Contracts\TelebirrClientInterface::class);

        $payload = ['test' => 'data'];
        
        $this->expectException(TelebirrException::class);
        $client->verifyCallbackSignature($payload, 'some_signature');
    }

    public function test_corrupted_callback_payload_timestamp()
    {
        // Missing timestamp should fail
        $payload = ['some' => 'data'];
        $this->assertFalse(Telebirr::verifyCallbackTimestamp($payload));

        // Very old timestamp should fail
        $payload['timestamp'] = time() - 3600; 
        $this->assertFalse(Telebirr::verifyCallbackTimestamp($payload));

        // Valid timestamp should pass
        $payload['timestamp'] = time() - 100;
        $this->assertTrue(Telebirr::verifyCallbackTimestamp($payload));
    }

    public function test_nonce_replay_attack_protection()
    {
        Cache::shouldReceive('add')->with('telebirr_nonce_nonce123', true, 300)->once()->andReturn(true);
        Cache::shouldReceive('add')->with('telebirr_nonce_nonce123', true, 300)->once()->andReturn(false);

        $payload = ['nonce_str' => 'nonce123'];

        // First attempt should succeed (not a replay)
        $this->assertTrue(Telebirr::verifyNonce($payload));

        // Second attempt with same nonce should fail (replay attack detected)
        $this->assertFalse(Telebirr::verifyNonce($payload));
    }

    public function test_concurrent_token_cache_isolation_multi_merchant()
    {
        // To force memory cache usage in a Laravel test environment, we can temporarily mock
        // the Cache facade to throw an exception when getting the facade root, or just 
        // unset the application instance from the facade if possible. An easier way is to 
        // test the keys directly by simulating the fallback.
        
        $httpClient = $this->createMock(TelebirrHttpClient::class);
        $httpClient->method('post')->willReturn(['token' => 'mock_token_123']);

        $tokenManager1 = new TokenManager($httpClient, 'fabric1', 'secret', 'merchantA');
        $tokenManager2 = new TokenManager($httpClient, 'fabric1', 'secret', 'merchantB');

        // We can just invoke getFabricToken. Since Laravel Cache is available, it caches there.
        // We want to test memory isolation. We will directly manipulate the memoryTokens.
        $reflection = new \ReflectionClass(TokenManager::class);
        $memoryTokensProperty = $reflection->getProperty('memoryTokens');
        $memoryTokensProperty->setAccessible(true);
        $memoryTokenExpiriesProperty = $reflection->getProperty('memoryTokenExpiries');
        $memoryTokenExpiriesProperty->setAccessible(true);

        $key1 = 'telebirr_fabric_token_' . md5('fabric1|merchantA');
        $key2 = 'telebirr_fabric_token_' . md5('fabric1|merchantB');

        $tokens = [];
        $tokens[$key1] = 'mock_token_A';
        $tokens[$key2] = 'mock_token_B';
        
        $memoryTokensProperty->setValue(null, $tokens);
        $memoryTokenExpiriesProperty->setValue(null, [$key1 => time() + 3600, $key2 => time() + 3600]);

        // Now test if TokenManager retrieves the correct token from memory
        // We have to mock the Cache to throw an exception so it falls back to memory!
        Cache::shouldReceive('getFacadeRoot')->andThrow(new \Exception('Cache unavailable'));

        $this->assertEquals('mock_token_A', $tokenManager1->getFabricToken());
        $this->assertEquals('mock_token_B', $tokenManager2->getFabricToken());
    }
}

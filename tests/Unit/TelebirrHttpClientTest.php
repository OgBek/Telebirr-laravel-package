<?php

namespace Bekambeyene\Telebirr\Tests\Unit;

use Bekambeyene\Telebirr\Exceptions\NetworkException;
use Bekambeyene\Telebirr\Services\TelebirrHttpClient;
use Bekambeyene\Telebirr\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class TelebirrHttpClientTest extends TestCase
{
    public function test_makes_successful_post_request()
    {
        Http::fake([
            '*' => Http::response(['success' => true, 'data' => 'mocked'], 200)
        ]);

        $client = new TelebirrHttpClient('https://api.example.com');
        $response = $client->post('/test-endpoint', ['key' => 'value']);

        $this->assertEquals(['success' => true, 'data' => 'mocked'], $response);
        
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.example.com/test-endpoint' &&
                   $request['key'] === 'value';
        });
    }

    public function test_throws_network_exception_on_http_error()
    {
        Http::fake([
            '*' => Http::response(['error' => 'Server Error'], 500)
        ]);

        $client = new TelebirrHttpClient('https://api.example.com');
        
        $this->expectException(NetworkException::class);
        $this->expectExceptionMessage('Telebirr API error (Status: 500)');
        
        $client->post('/test-endpoint', ['key' => 'value']);
    }
}

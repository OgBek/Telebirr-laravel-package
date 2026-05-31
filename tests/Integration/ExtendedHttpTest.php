<?php

namespace Bekambeyene\Telebirr\Tests\Integration;

use Bekambeyene\Telebirr\Exceptions\NetworkException;
use Bekambeyene\Telebirr\Services\TelebirrHttpClient;
use Bekambeyene\Telebirr\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class ExtendedHttpTest extends TestCase
{
    // ─── Successful Response Handling ─────────────────────────────────

    public function test_successful_payment_creation_returns_array()
    {
        Http::fake([
            '*' => Http::response([
                'biz_content' => ['prepay_id' => 'PP_12345']
            ], 200)
        ]);

        $client = new TelebirrHttpClient('https://api.example.com', false);
        $result = $client->post('/payment/v1/merchant/preOrder', ['key' => 'value']);

        $this->assertIsArray($result);
        $this->assertEquals('PP_12345', $result['biz_content']['prepay_id']);
    }

    public function test_successful_token_response_returns_token()
    {
        Http::fake([
            '*' => Http::response(['token' => 'abc123'], 200)
        ]);

        $client = new TelebirrHttpClient('https://api.example.com', false);
        $result = $client->post('/payment/v1/token', ['appSecret' => 'secret']);

        $this->assertEquals('abc123', $result['token']);
    }

    // ─── HTTP Error Codes ────────────────────────────────────────────

    public function test_bad_request_400_throws_network_exception()
    {
        Http::fake([
            '*' => Http::response(['error' => 'Bad Request'], 400)
        ]);

        $client = new TelebirrHttpClient('https://api.example.com', false);

        $this->expectException(NetworkException::class);
        $this->expectExceptionMessage('Telebirr API error (Status: 400)');

        $client->post('/test', ['key' => 'value']);
    }

    public function test_unauthorized_401_throws_network_exception()
    {
        Http::fake([
            '*' => Http::response(['error' => 'Unauthorized'], 401)
        ]);

        $client = new TelebirrHttpClient('https://api.example.com', false);

        $this->expectException(NetworkException::class);
        $this->expectExceptionMessage('Telebirr API error (Status: 401)');

        $client->post('/test', ['key' => 'value']);
    }

    public function test_forbidden_403_throws_network_exception()
    {
        Http::fake([
            '*' => Http::response(['error' => 'Forbidden'], 403)
        ]);

        $client = new TelebirrHttpClient('https://api.example.com', false);

        $this->expectException(NetworkException::class);
        $this->expectExceptionMessage('Telebirr API error (Status: 403)');

        $client->post('/test', ['key' => 'value']);
    }

    public function test_not_found_404_throws_network_exception()
    {
        Http::fake([
            '*' => Http::response(['error' => 'Not Found'], 404)
        ]);

        $client = new TelebirrHttpClient('https://api.example.com', false);

        $this->expectException(NetworkException::class);
        $this->expectExceptionMessage('Telebirr API error (Status: 404)');

        $client->post('/test', ['key' => 'value']);
    }

    public function test_server_error_500_throws_network_exception()
    {
        Http::fake([
            '*' => Http::response(['error' => 'Internal Server Error'], 500)
        ]);

        $client = new TelebirrHttpClient('https://api.example.com', false);

        $this->expectException(NetworkException::class);
        $this->expectExceptionMessage('Telebirr API error (Status: 500)');

        $client->post('/test', ['key' => 'value']);
    }

    public function test_service_unavailable_503_throws_network_exception()
    {
        Http::fake([
            '*' => Http::response(['error' => 'Service Unavailable'], 503)
        ]);

        $client = new TelebirrHttpClient('https://api.example.com', false);

        $this->expectException(NetworkException::class);
        $this->expectExceptionMessage('Telebirr API error (Status: 503)');

        $client->post('/test', ['key' => 'value']);
    }

    // ─── Timeout & Network Failures ──────────────────────────────────

    public function test_timeout_throws_network_exception()
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
        });

        $client = new TelebirrHttpClient('https://api.example.com', false);

        $this->expectException(NetworkException::class);
        $this->expectExceptionMessage('Telebirr API communication failure');

        $client->post('/test', ['key' => 'value']);
    }

    public function test_network_failure_throws_network_exception()
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Could not resolve host');
        });

        $client = new TelebirrHttpClient('https://api.example.com', false);

        $this->expectException(NetworkException::class);
        $this->expectExceptionMessage('Telebirr API communication failure');

        $client->post('/test', ['key' => 'value']);
    }

    // ─── Payload & URL Formatting ────────────────────────────────────

    public function test_url_is_correctly_constructed()
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200)
        ]);

        $client = new TelebirrHttpClient('https://api.example.com', false);
        $client->post('/payment/v1/test', ['key' => 'value']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.example.com/payment/v1/test';
        });
    }

    public function test_content_type_header_is_json()
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200)
        ]);

        $client = new TelebirrHttpClient('https://api.example.com', false);
        $client->post('/test', ['key' => 'value']);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Content-Type', 'application/json');
        });
    }

    public function test_custom_headers_are_sent()
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200)
        ]);

        $client = new TelebirrHttpClient('https://api.example.com', false);
        $client->post('/test', ['key' => 'value'], [
            'Authorization' => 'Bearer token123',
            'X-APP-Key' => 'app_key_456'
        ]);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer token123') &&
                   $request->hasHeader('X-APP-Key', 'app_key_456');
        });
    }

    public function test_raw_string_body_is_sent_correctly()
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200)
        ]);

        $rawJson = '{"method":"payment.preorder","sign":"abc123"}';
        $client = new TelebirrHttpClient('https://api.example.com', false);
        $client->post('/test', $rawJson);

        Http::assertSent(function ($request) use ($rawJson) {
            return $request->body() === $rawJson;
        });
    }

    // ─── Malformed Response Handling ─────────────────────────────────

    public function test_non_json_successful_response_throws_exception()
    {
        Http::fake([
            '*' => Http::response('This is not JSON', 200)
        ]);

        $client = new TelebirrHttpClient('https://api.example.com', false);

        $this->expectException(NetworkException::class);
        $this->expectExceptionMessage('Telebirr API error (Status: 200)');

        $client->post('/test', ['key' => 'value']);
    }

    public function test_empty_body_successful_response_throws_exception()
    {
        Http::fake([
            '*' => Http::response('', 200)
        ]);

        $client = new TelebirrHttpClient('https://api.example.com', false);

        $this->expectException(NetworkException::class);

        $client->post('/test', ['key' => 'value']);
    }
}

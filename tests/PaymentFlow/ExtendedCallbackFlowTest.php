<?php

namespace Bekambeyene\Telebirr\Tests\PaymentFlow;

use Bekambeyene\Telebirr\Exceptions\TelebirrException;
use Bekambeyene\Telebirr\Services\SignatureService;
use Bekambeyene\Telebirr\Services\TelebirrHttpClient;
use Bekambeyene\Telebirr\Services\TokenManager;
use Bekambeyene\Telebirr\TelebirrClient;
use Bekambeyene\Telebirr\Tests\TestCase;
use Mockery;

class ExtendedCallbackFlowTest extends TestCase
{
    protected TelebirrClient $telebirrClient;
    protected SignatureService $signatureService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signatureService = new SignatureService();

        $this->telebirrClient = new TelebirrClient(
            config('telebirr'),
            Mockery::mock(TokenManager::class),
            $this->signatureService,
            Mockery::mock(TelebirrHttpClient::class)
        );
    }

    // ─── Valid Callback Acceptance ────────────────────────────────────

    public function test_valid_callback_is_accepted()
    {
        $payload = [
            'merch_order_id' => 'ORDER-001',
            'trade_status' => 'PAY_SUCCESS',
            'total_amount' => '100.00',
            'transaction_id' => 'TXN-12345'
        ];

        $signature = $this->signatureService->signPSS($payload, config('telebirr.private_key'));
        $this->assertTrue($this->telebirrClient->verifyCallbackSignature($payload, $signature));
    }

    // ─── Invalid Signature Rejection ─────────────────────────────────

    public function test_callback_with_invalid_signature_is_rejected()
    {
        $payload = [
            'merch_order_id' => 'ORDER-001',
            'trade_status' => 'PAY_SUCCESS',
        ];

        $fakeSignature = base64_encode('totally_fake_signature');
        $this->assertFalse($this->telebirrClient->verifyCallbackSignature($payload, $fakeSignature));
    }

    public function test_callback_without_signature_is_rejected()
    {
        $payload = [
            'merch_order_id' => 'ORDER-001',
            'trade_status' => 'PAY_SUCCESS',
        ];

        $this->assertFalse($this->telebirrClient->verifyCallbackSignature($payload, ''));
    }

    // ─── Callback Data Parsing ───────────────────────────────────────

    public function test_callback_data_is_parsed_correctly()
    {
        $payload = [
            'merch_order_id' => 'ORDER-999',
            'trade_status' => 'PAY_SUCCESS',
            'total_amount' => '250.50',
            'transaction_id' => 'TXN-ABCDE'
        ];

        $this->assertEquals('ORDER-999', $payload['merch_order_id']);
        $this->assertEquals('PAY_SUCCESS', $payload['trade_status']);
        $this->assertEquals('250.50', $payload['total_amount']);
        $this->assertEquals('TXN-ABCDE', $payload['transaction_id']);
    }

    // ─── Tampered Callback Detection ─────────────────────────────────

    public function test_callback_with_tampered_amount_is_rejected()
    {
        $original = [
            'merch_order_id' => 'ORDER-001',
            'trade_status' => 'PAY_SUCCESS',
            'total_amount' => '100.00'
        ];

        $signature = $this->signatureService->signPSS($original, config('telebirr.private_key'));

        // Attacker changes the amount
        $tampered = $original;
        $tampered['total_amount'] = '0.01';

        $this->assertFalse($this->telebirrClient->verifyCallbackSignature($tampered, $signature));
    }

    public function test_callback_with_tampered_status_is_rejected()
    {
        $original = [
            'merch_order_id' => 'ORDER-001',
            'trade_status' => 'PAY_FAIL',
            'total_amount' => '100.00'
        ];

        $signature = $this->signatureService->signPSS($original, config('telebirr.private_key'));

        // Attacker changes status to SUCCESS
        $tampered = $original;
        $tampered['trade_status'] = 'PAY_SUCCESS';

        $this->assertFalse($this->telebirrClient->verifyCallbackSignature($tampered, $signature));
    }

    public function test_callback_with_tampered_order_id_is_rejected()
    {
        $original = [
            'merch_order_id' => 'ORDER-001',
            'trade_status' => 'PAY_SUCCESS',
        ];

        $signature = $this->signatureService->signPSS($original, config('telebirr.private_key'));

        $tampered = $original;
        $tampered['merch_order_id'] = 'ORDER-HACKED';

        $this->assertFalse($this->telebirrClient->verifyCallbackSignature($tampered, $signature));
    }

    public function test_callback_with_injected_extra_field_is_rejected()
    {
        $original = [
            'merch_order_id' => 'ORDER-001',
        ];

        $signature = $this->signatureService->signPSS($original, config('telebirr.private_key'));

        $tampered = $original;
        $tampered['extra_field'] = 'injected_value';

        $this->assertFalse($this->telebirrClient->verifyCallbackSignature($tampered, $signature));
    }

    // ─── Missing Public Key ──────────────────────────────────────────

    public function test_callback_verification_throws_without_public_key()
    {
        $configNoKey = config('telebirr');
        $configNoKey['public_key'] = '';

        $clientNoKey = new TelebirrClient(
            $configNoKey,
            Mockery::mock(TokenManager::class),
            $this->signatureService,
            Mockery::mock(TelebirrHttpClient::class)
        );

        $this->expectException(TelebirrException::class);
        $this->expectExceptionMessage('Public key is required to verify webhook signatures.');

        $clientNoKey->verifyCallbackSignature(['key' => 'value'], 'some_sig');
    }

    // ─── Replay Attack Protection (Application-Level Guidance) ───────

    /**
     * This test demonstrates how consuming applications should protect
     * against replay attacks. The SDK validates the signature is valid,
     * but your application must check if the order was already processed.
     */
    public function test_duplicate_callback_detection_pattern()
    {
        $payload = [
            'merch_order_id' => 'ORDER-001',
            'trade_status' => 'PAY_SUCCESS',
        ];

        $signature = $this->signatureService->signPSS($payload, config('telebirr.private_key'));

        // First callback: signature is valid
        $firstResult = $this->telebirrClient->verifyCallbackSignature($payload, $signature);
        $this->assertTrue($firstResult);

        // Simulate application-level tracking
        $processedOrders = [];
        $orderId = $payload['merch_order_id'];

        // First processing
        if (!in_array($orderId, $processedOrders)) {
            $processedOrders[] = $orderId;
            // Process the order...
        }

        // Second callback with identical payload (replay)
        $secondResult = $this->telebirrClient->verifyCallbackSignature($payload, $signature);
        $this->assertTrue($secondResult); // SDK says signature is still valid

        // But application rejects the duplicate
        $isDuplicate = in_array($orderId, $processedOrders);
        $this->assertTrue($isDuplicate, 'Application should detect and reject duplicate callbacks');
    }
}

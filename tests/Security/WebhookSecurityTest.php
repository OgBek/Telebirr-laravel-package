<?php

namespace Bekambeyene\Telebirr\Tests\Security;

use Bekambeyene\Telebirr\Services\SignatureService;
use Bekambeyene\Telebirr\Tests\TestCase;

class WebhookSecurityTest extends TestCase
{
    protected SignatureService $signatureService;
    protected string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->signatureService = new SignatureService();
        $this->publicKey = config('telebirr.public_key');
    }

    public function test_rejects_empty_signature()
    {
        $payload = ['merch_order_id' => '123', 'trade_status' => 'PAY_SUCCESS'];
        $signature = '';

        $isValid = $this->signatureService->verifyPSS($payload, $signature, $this->publicKey);
        $this->assertFalse($isValid);
    }

    public function test_rejects_tampered_payload()
    {
        // 1. Sign a valid payload
        $originalPayload = ['merch_order_id' => '123', 'amount' => '100'];
        $signature = $this->signatureService->signPSS($originalPayload, config('telebirr.private_key'));

        // 2. Malicious actor modifies the payload amount to 0
        $tamperedPayload = ['merch_order_id' => '123', 'amount' => '0'];

        // 3. Verification should fail because the signature doesn't match the new canonical string
        $isValid = $this->signatureService->verifyPSS($tamperedPayload, $signature, $this->publicKey);
        $this->assertFalse($isValid);
    }

    public function test_rejects_injected_parameters()
    {
        $originalPayload = ['merch_order_id' => '123'];
        $signature = $this->signatureService->signPSS($originalPayload, config('telebirr.private_key'));

        // Malicious actor adds a trade_status=PAY_SUCCESS parameter
        $injectedPayload = ['merch_order_id' => '123', 'trade_status' => 'PAY_SUCCESS'];

        $isValid = $this->signatureService->verifyPSS($injectedPayload, $signature, $this->publicKey);
        $this->assertFalse($isValid);
    }

    // ─── Payload Edge Cases ──────────────────────────────────────────

    public function test_rejects_empty_payload()
    {
        $payload = [];
        $fakeSignature = base64_encode('fake_signature');
        
        $isValid = $this->signatureService->verifyPSS($payload, $fakeSignature, $this->publicKey);
        $this->assertFalse($isValid);
    }

    public function test_handles_large_payload()
    {
        // Generate a large payload — should not crash or hang
        $largePayload = [];
        for ($i = 0; $i < 100; $i++) {
            $largePayload["field_{$i}"] = str_repeat('x', 1000);
        }

        $signature = $this->signatureService->signPSS($largePayload, config('telebirr.private_key'));
        $this->assertNotEmpty($signature);

        $isValid = $this->signatureService->verifyPSS($largePayload, $signature, $this->publicKey);
        $this->assertTrue($isValid);
    }

    public function test_rejects_signature_from_different_key_pair()
    {
        // Generate a different key pair (attacker's keys)
        $attackerKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($attackerKey, $attackerPrivateKey);

        $payload = ['merch_order_id' => '123', 'trade_status' => 'PAY_SUCCESS'];

        // Attacker signs with their own private key
        $attackerSignature = $this->signatureService->signPSS($payload, $attackerPrivateKey);

        // Merchant verifies against the legitimate public key — must fail
        $isValid = $this->signatureService->verifyPSS($payload, $attackerSignature, $this->publicKey);
        $this->assertFalse($isValid);
    }

    public function test_rejects_base64_garbage_signature()
    {
        $payload = ['merch_order_id' => '123'];
        $garbageSignature = base64_encode(random_bytes(128));

        $isValid = $this->signatureService->verifyPSS($payload, $garbageSignature, $this->publicKey);
        $this->assertFalse($isValid);
    }

    public function test_rejects_reordered_payload_with_original_signature()
    {
        // Sign with keys in one order
        $original = ['a_field' => '1', 'b_field' => '2', 'c_field' => '3'];
        $signature = $this->signatureService->signPSS($original, config('telebirr.private_key'));

        // The canonical string sorts keys, so reordered keys should STILL verify
        $reordered = ['c_field' => '3', 'a_field' => '1', 'b_field' => '2'];
        $isValid = $this->signatureService->verifyPSS($reordered, $signature, $this->publicKey);
        $this->assertTrue($isValid, 'Canonical string sorting should normalize key order');
    }

    public function test_single_character_difference_in_value_fails()
    {
        $original = ['merch_order_id' => 'ORDER-001'];
        $signature = $this->signatureService->signPSS($original, config('telebirr.private_key'));

        // Change just one character
        $tampered = ['merch_order_id' => 'ORDER-002'];
        $isValid = $this->signatureService->verifyPSS($tampered, $signature, $this->publicKey);
        $this->assertFalse($isValid);
    }

    public function test_removed_field_fails_verification()
    {
        $original = ['merch_order_id' => 'ORDER-001', 'total_amount' => '100.00'];
        $signature = $this->signatureService->signPSS($original, config('telebirr.private_key'));

        // Attacker removes total_amount
        $stripped = ['merch_order_id' => 'ORDER-001'];
        $isValid = $this->signatureService->verifyPSS($stripped, $signature, $this->publicKey);
        $this->assertFalse($isValid);
    }

    // ─── Timestamp & Replay Patterns ─────────────────────────────────

    /**
     * This test demonstrates how consuming applications should detect
     * expired requests by checking the timestamp field in the payload.
     */
    public function test_expired_request_detection_pattern()
    {
        $payload = [
            'merch_order_id' => 'ORDER-001',
            'timestamp' => (string)(time() - 3600), // 1 hour ago
        ];

        $signature = $this->signatureService->signPSS($payload, config('telebirr.private_key'));

        // Signature is mathematically valid
        $isValid = $this->signatureService->verifyPSS($payload, $signature, $this->publicKey);
        $this->assertTrue($isValid);

        // But application should reject based on timestamp staleness
        $requestTimestamp = (int)$payload['timestamp'];
        $maxAgeSeconds = 600; // 10 minutes
        $isExpired = (time() - $requestTimestamp) > $maxAgeSeconds;
        $this->assertTrue($isExpired, 'Application should reject requests older than the allowed window');
    }

    /**
     * This test demonstrates how applications should detect replay attacks
     * by tracking previously processed transaction IDs.
     */
    public function test_replay_attack_detection_pattern()
    {
        $payload = [
            'merch_order_id' => 'ORDER-001',
            'trade_status' => 'PAY_SUCCESS',
        ];

        $signature = $this->signatureService->signPSS($payload, config('telebirr.private_key'));

        // Simulate a cache of processed order IDs
        $processedCache = [];

        // First processing
        $isValid = $this->signatureService->verifyPSS($payload, $signature, $this->publicKey);
        $this->assertTrue($isValid);
        $processedCache[] = $payload['merch_order_id'];

        // Second (replay) attempt
        $isReplay = in_array($payload['merch_order_id'], $processedCache);
        $this->assertTrue($isReplay, 'Application should detect replay of already-processed order');
    }
}

<?php

namespace Bekambeyene\Telebirr\Tests\Unit;

use Bekambeyene\Telebirr\Exceptions\SignatureException;
use Bekambeyene\Telebirr\Services\SignatureService;
use Bekambeyene\Telebirr\Tests\TestCase;
use phpseclib3\Crypt\RSA;

class ExtendedSignatureTest extends TestCase
{
    protected SignatureService $signatureService;
    protected string $privateKey;
    protected string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->signatureService = new SignatureService();
        $this->privateKey = config('telebirr.private_key');
        $this->publicKey = config('telebirr.public_key');
    }

    // ─── Canonical String Tests ───────────────────────────────────────

    public function test_canonical_string_excludes_sign_field()
    {
        $params = ['appid' => 'app123', 'sign' => 'should_be_excluded', 'nonce_str' => '12345'];
        $canonical = $this->signatureService->buildCanonicalString($params);
        
        $this->assertStringNotContainsString('sign=should_be_excluded', $canonical);
        $this->assertStringContainsString('appid=app123', $canonical);
    }

    public function test_canonical_string_excludes_sign_type_field()
    {
        $params = ['appid' => 'app123', 'sign_type' => 'SHA256WithRSA', 'nonce_str' => '12345'];
        $canonical = $this->signatureService->buildCanonicalString($params);
        
        $this->assertStringNotContainsString('sign_type=', $canonical);
    }

    public function test_canonical_string_excludes_empty_values()
    {
        $params = ['appid' => 'app123', 'empty_field' => '', 'nonce_str' => '12345'];
        $canonical = $this->signatureService->buildCanonicalString($params);
        
        $this->assertStringNotContainsString('empty_field=', $canonical);
    }

    public function test_canonical_string_excludes_null_values()
    {
        $params = ['appid' => 'app123', 'null_field' => null, 'nonce_str' => '12345'];
        $canonical = $this->signatureService->buildCanonicalString($params);
        
        $this->assertStringNotContainsString('null_field=', $canonical);
    }

    public function test_canonical_string_sorts_alphabetically()
    {
        $params = ['zebra' => 'z', 'alpha' => 'a', 'mid' => 'm'];
        $canonical = $this->signatureService->buildCanonicalString($params);
        
        $this->assertEquals('alpha=a&mid=m&zebra=z', $canonical);
    }

    public function test_canonical_string_flattens_biz_content()
    {
        $params = [
            'method' => 'payment.preorder',
            'biz_content' => [
                'appid' => 'app123',
                'total_amount' => '100.00'
            ]
        ];
        $canonical = $this->signatureService->buildCanonicalString($params);

        $this->assertStringContainsString('appid=app123', $canonical);
        $this->assertStringContainsString('total_amount=100.00', $canonical);
        $this->assertStringNotContainsString('biz_content=', $canonical);
    }

    public function test_canonical_string_with_single_param()
    {
        $params = ['appid' => 'app123'];
        $canonical = $this->signatureService->buildCanonicalString($params);
        
        $this->assertEquals('appid=app123', $canonical);
    }

    public function test_canonical_string_with_empty_params()
    {
        $params = [];
        $canonical = $this->signatureService->buildCanonicalString($params);
        
        $this->assertEquals('', $canonical);
    }

    // ─── Deterministic Signature Tests ────────────────────────────────

    public function test_same_payload_generates_same_pkcs1_signature()
    {
        $params = ['nonce_str' => 'fixed_nonce', 'appid' => 'app123'];
        
        $sig1 = $this->signatureService->signPKCS1($params, $this->privateKey);
        $sig2 = $this->signatureService->signPKCS1($params, $this->privateKey);
        
        // PKCS1 is deterministic — same input always produces same output
        $this->assertEquals($sig1, $sig2);
    }

    public function test_different_payload_generates_different_pkcs1_signature()
    {
        $params1 = ['nonce_str' => 'nonce_1', 'appid' => 'app123'];
        $params2 = ['nonce_str' => 'nonce_2', 'appid' => 'app456'];
        
        $sig1 = $this->signatureService->signPKCS1($params1, $this->privateKey);
        $sig2 = $this->signatureService->signPKCS1($params2, $this->privateKey);
        
        $this->assertNotEquals($sig1, $sig2);
    }

    public function test_pss_signature_is_non_empty_base64()
    {
        $params = ['nonce_str' => '12345', 'appid' => 'app123'];
        $signature = $this->signatureService->signPSS($params, $this->privateKey);
        
        $this->assertNotEmpty($signature);
        // Verify it's valid base64
        $this->assertNotFalse(base64_decode($signature, true));
    }

    public function test_pkcs1_signature_is_non_empty_base64()
    {
        $params = ['nonce_str' => '12345', 'appid' => 'app123'];
        $signature = $this->signatureService->signPKCS1($params, $this->privateKey);
        
        $this->assertNotEmpty($signature);
        $this->assertNotFalse(base64_decode($signature, true));
    }

    // ─── Verification Tests ──────────────────────────────────────────

    public function test_valid_pss_signature_passes_verification()
    {
        $params = ['merch_order_id' => 'ORDER-001', 'trade_status' => 'PAY_SUCCESS'];
        $signature = $this->signatureService->signPSS($params, $this->privateKey);
        
        $this->assertTrue($this->signatureService->verifyPSS($params, $signature, $this->publicKey));
    }

    public function test_modified_amount_fails_pss_verification()
    {
        $original = ['merch_order_id' => 'ORDER-001', 'total_amount' => '100.00'];
        $signature = $this->signatureService->signPSS($original, $this->privateKey);
        
        $tampered = ['merch_order_id' => 'ORDER-001', 'total_amount' => '0.01'];
        $this->assertFalse($this->signatureService->verifyPSS($tampered, $signature, $this->publicKey));
    }

    public function test_modified_order_id_fails_pss_verification()
    {
        $original = ['merch_order_id' => 'ORDER-001', 'total_amount' => '100.00'];
        $signature = $this->signatureService->signPSS($original, $this->privateKey);
        
        $tampered = ['merch_order_id' => 'ORDER-HACKED', 'total_amount' => '100.00'];
        $this->assertFalse($this->signatureService->verifyPSS($tampered, $signature, $this->publicKey));
    }

    public function test_modified_timestamp_fails_pss_verification()
    {
        $original = ['merch_order_id' => 'ORDER-001', 'timestamp' => '1700000000'];
        $signature = $this->signatureService->signPSS($original, $this->privateKey);
        
        $tampered = ['merch_order_id' => 'ORDER-001', 'timestamp' => '9999999999'];
        $this->assertFalse($this->signatureService->verifyPSS($tampered, $signature, $this->publicKey));
    }

    public function test_completely_random_signature_fails_verification()
    {
        $params = ['merch_order_id' => 'ORDER-001'];
        $randomSignature = base64_encode(random_bytes(256));
        
        $this->assertFalse($this->signatureService->verifyPSS($params, $randomSignature, $this->publicKey));
    }

    public function test_truncated_signature_fails_verification()
    {
        $params = ['merch_order_id' => 'ORDER-001'];
        $validSig = $this->signatureService->signPSS($params, $this->privateKey);
        $truncated = substr($validSig, 0, (int)(strlen($validSig) / 2));
        
        $this->assertFalse($this->signatureService->verifyPSS($params, $truncated, $this->publicKey));
    }

    // ─── Invalid Key Tests ───────────────────────────────────────────

    public function test_invalid_private_key_throws_signature_exception()
    {
        $params = ['appid' => 'app123'];
        
        $this->expectException(SignatureException::class);
        $this->signatureService->signPSS($params, 'not-a-valid-key');
    }

    public function test_invalid_public_key_throws_signature_exception()
    {
        $params = ['appid' => 'app123'];
        $signature = $this->signatureService->signPSS($params, $this->privateKey);
        
        $this->expectException(SignatureException::class);
        $this->signatureService->verifyPSS($params, $signature, 'not-a-valid-public-key');
    }

    public function test_mismatched_key_pair_fails_verification()
    {
        // Generate a completely different key pair
        $key = \phpseclib3\Crypt\RSA::createKey(1024);
        $differentPublicKey = $key->getPublicKey()->toString('PKCS8');

        $params = ['merch_order_id' => 'ORDER-001'];
        $signature = $this->signatureService->signPSS($params, $this->privateKey);
        
        // Verify against a DIFFERENT public key — must fail
        $this->assertFalse($this->signatureService->verifyPSS($params, $signature, $differentPublicKey));
    }

    public function test_pkcs1_invalid_private_key_throws_signature_exception()
    {
        $params = ['appid' => 'app123'];
        
        $this->expectException(SignatureException::class);
        $this->signatureService->signPKCS1($params, 'garbage-key-data');
    }
}

<?php

namespace Bekambeyene\Telebirr\Tests\Unit;

use Bekambeyene\Telebirr\Services\SignatureService;
use Bekambeyene\Telebirr\Tests\TestCase;
use phpseclib3\Crypt\RSA;

class SignatureServiceTest extends TestCase
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

    public function test_builds_correct_canonical_string()
    {
        $params = [
            'method' => 'payment.preorder',
            'nonce_str' => '12345',
            'sign' => 'should_be_ignored',
            'sign_type' => 'SHA256WithRSA',
            'biz_content' => [
                'appid' => 'app123',
                'title' => 'Test Item',
            ],
            'timestamp' => '1620000000'
        ];

        $canonical = $this->signatureService->buildCanonicalString($params);
        
        // biz_content arrays are flattened, empty values ignored, and 'sign', 'sign_type' are excluded
        // Wait, sign_type is excluded in buildCanonicalString.
        $this->assertEquals('appid=app123&method=payment.preorder&nonce_str=12345&timestamp=1620000000&title=Test Item', $canonical);
    }

    public function test_generates_valid_rsa_pss_signature()
    {
        $params = ['nonce_str' => '12345', 'appid' => 'app123'];
        
        $signature = $this->signatureService->signPSS($params, $this->privateKey);
        
        $this->assertNotEmpty($signature);
        
        // Verify it using verifyPSS
        $isValid = $this->signatureService->verifyPSS($params, $signature, $this->publicKey);
        $this->assertTrue($isValid);
    }

    public function test_generates_valid_rsa_pkcs1_signature()
    {
        $params = ['nonce_str' => '12345', 'appid' => 'app123'];
        
        $signature = $this->signatureService->signPKCS1($params, $this->privateKey);
        
        $this->assertNotEmpty($signature);
        
        $canonicalString = $this->signatureService->buildCanonicalString($params);
        $rsa = RSA::loadPublicKey($this->publicKey)
            ->withHash('sha256')
            ->withPadding(RSA::SIGNATURE_PKCS1);

        $this->assertTrue($rsa->verify($canonicalString, base64_decode($signature)));
    }

    public function test_rejects_invalid_webhook_signature()
    {
        $params = ['nonce_str' => '12345', 'appid' => 'app123'];
        
        $invalidSignature = base64_encode('invalid_signature_data');
        
        $isValid = $this->signatureService->verifyPSS($params, $invalidSignature, $this->publicKey);
        $this->assertFalse($isValid);
    }
}

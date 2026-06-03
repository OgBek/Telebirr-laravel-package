<?php

namespace Bekambeyene\Telebirr\Tests\Unit;

use Bekambeyene\Telebirr\Services\SignatureService;
use Bekambeyene\Telebirr\Tests\TestCase;
use Bekambeyene\Telebirr\Exceptions\TelebirrException;

class SignatureServiceKeyResolutionTest extends TestCase
{
    protected SignatureService $signatureService;
    protected string $privateKey;
    protected string $publicKey;
    protected string $tempPrivPath;
    protected string $tempPubPath;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->signatureService = new SignatureService();
        $this->privateKey = config('telebirr.private_key');
        $this->publicKey = config('telebirr.public_key');

        $this->tempPrivPath = sys_get_temp_dir() . '/test_priv.pem';
        $this->tempPubPath = sys_get_temp_dir() . '/test_pub.pem';

        file_put_contents($this->tempPrivPath, $this->privateKey);
        file_put_contents($this->tempPubPath, $this->publicKey);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempPrivPath)) {
            unlink($this->tempPrivPath);
        }
        if (file_exists($this->tempPubPath)) {
            unlink($this->tempPubPath);
        }
        
        parent::tearDown();
    }

    public function test_raw_key_string_works()
    {
        $params = ['nonce_str' => '12345'];
        $signature = $this->signatureService->signPSS($params, $this->privateKey);
        
        $this->assertNotEmpty($signature);
        $this->assertTrue($this->signatureService->verifyPSS($params, $signature, $this->publicKey));
    }

    public function test_file_based_private_key_works()
    {
        $params = ['nonce_str' => '12345'];
        
        $fileKey = 'file://' . $this->tempPrivPath;
        $signature = $this->signatureService->signPSS($params, $fileKey);
        
        $this->assertNotEmpty($signature);
        $this->assertTrue($this->signatureService->verifyPSS($params, $signature, $this->publicKey));
    }

    public function test_file_based_public_key_works()
    {
        $params = ['nonce_str' => '12345'];
        $signature = $this->signatureService->signPSS($params, $this->privateKey);
        
        $filePublicKey = 'file://' . $this->tempPubPath;
        $this->assertTrue($this->signatureService->verifyPSS($params, $signature, $filePublicKey));
    }

    public function test_nonexistent_file_throws_exception()
    {
        $this->expectException(TelebirrException::class);
        $this->expectExceptionMessage('Unable to load Telebirr key file');

        $params = ['nonce_str' => '12345'];
        $this->signatureService->signPSS($params, 'file:///path/to/nonexistent/file.pem');
    }

    public function test_escaped_newlines_are_normalized_for_raw_keys()
    {
        // Replace real newlines with \n literal
        $escapedPrivKey = str_replace(PHP_EOL, '\n', $this->privateKey);
        $escapedPubKey = str_replace(PHP_EOL, '\n', $this->publicKey);

        $params = ['nonce_str' => '12345'];
        
        $signature = $this->signatureService->signPSS($params, $escapedPrivKey);
        $this->assertNotEmpty($signature);
        $this->assertTrue($this->signatureService->verifyPSS($params, $signature, $escapedPubKey));
    }
}

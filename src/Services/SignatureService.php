<?php

declare(strict_types=1);

namespace Bekambeyene\Telebirr\Services;

use Bekambeyene\Telebirr\Exceptions\SignatureException;
use phpseclib3\Crypt\RSA;

class SignatureService
{
    /**
     * Build the canonical string for signing or verification.
     * The keys must be sorted alphabetically, and empty values or 'sign' are excluded.
     *
     * @param array $params
     * @return string
     */
    public function buildCanonicalString(array $params): string
    {
        $excludeFields = ['sign', 'sign_type', 'header', 'refund_info', 'openType', 'raw_request'];
        $components = [];

        foreach ($params as $key => $value) {
            if (in_array($key, $excludeFields) || $value === null || $value === '') {
                continue;
            }

            if ($key === 'biz_content' && is_array($value)) {
                foreach ($value as $k => $v) {
                    if ($v !== null && $v !== '') {
                        $components[] = $k . '=' . $v;
                    }
                }
            } else {
                $components[] = $key . '=' . $value;
            }
        }

        sort($components, SORT_STRING);
        return implode('&', $components);
    }

    /**
     * Sign the parameters using RSA-PSS.
     *
     * @param array $params
     * @param string $privateKey
     * @return string
     * @throws SignatureException
     */
    public function signPSS(array $params, string $privateKey): string
    {
        try {
            $canonicalString = $this->buildCanonicalString($params);

            $rsa = RSA::load($privateKey)
                ->withHash('sha256')
                ->withMGFHash('sha256')
                ->withSaltLength(32);

            $signature = $rsa->sign($canonicalString);

            return base64_encode($signature);
        } catch (\Throwable $e) {
            throw new SignatureException("Failed to generate RSA-PSS signature: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Sign the parameters using RSA-PKCS1.
     *
     * @param array $params
     * @param string $privateKey
     * @return string
     * @throws SignatureException
     */
    public function signPKCS1(array $params, string $privateKey): string
    {
        try {
            $canonicalString = $this->buildCanonicalString($params);

            $rsa = RSA::load($privateKey)
                ->withHash('sha256')
                ->withPadding(RSA::SIGNATURE_PKCS1);

            $signature = $rsa->sign($canonicalString);

            return base64_encode($signature);
        } catch (\Throwable $e) {
            throw new SignatureException("Failed to generate RSA-PKCS1 signature: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Verify the parameters using RSA-PSS.
     *
     * @param array $params
     * @param string $signature
     * @param string $publicKey
     * @return bool
     * @throws SignatureException
     */
    public function verifyPSS(array $params, string $signature, string $publicKey): bool
    {
        try {
            $canonicalString = $this->buildCanonicalString($params);

            $rsa = RSA::loadPublicKey($publicKey)
                ->withHash('sha256')
                ->withMGFHash('sha256')
                ->withSaltLength(32);

            return $rsa->verify($canonicalString, base64_decode($signature));
        } catch (\Throwable $e) {
            throw new SignatureException("Failed to verify RSA-PSS signature: " . $e->getMessage(), 0, $e);
        }
    }
}

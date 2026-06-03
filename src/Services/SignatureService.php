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
     * WARNING:
     * Telebirr production interoperability depends on this exact ordering and normalization.
     * Do not simplify or refactor this method without validating against production signatures.
     * Empty strings must be preserved, while NULL values must be omitted.
     * Booleans must be cast to '1' (true) or '0' (false).
     *
     * @param array $params
     * @return string
     */
    public function buildCanonicalString(array $params): string
    {
        $excludeFields = ['sign', 'sign_type', 'header', 'refund_info', 'openType', 'raw_request'];
        $components = [];

        // Helper to recursively normalize scalar values for stable transport matching
        $normalize = function ($val) {
            if ($val === null) {
                return null;
            }
            if (is_bool($val)) {
                return $val ? '1' : '0';
            }
            if (is_scalar($val)) {
                return (string) $val;
            }
            return $val;
        };

        // Helper to recursively sort array keys deterministically
        $recursiveSort = function (array $arr) use (&$recursiveSort) {
            ksort($arr, SORT_STRING);
            foreach ($arr as $key => $value) {
                if (is_array($value)) {
                    $arr[$key] = $recursiveSort($value);
                }
            }
            return $arr;
        };

        $sortedParams = $recursiveSort($params);

        foreach ($sortedParams as $key => $value) {
            if (in_array($key, $excludeFields, true) || $value === null) {
                continue;
            }

            if ($key === 'biz_content' && is_array($value)) {
                $sortedBiz = $recursiveSort($value);
                foreach ($sortedBiz as $k => $v) {
                    if ($v !== null) {
                        $normalizedVal = $normalize($v);
                        if ($normalizedVal !== null) {
                            $components[] = $k . '=' . $normalizedVal;
                        }
                    }
                }
            } else {
                $normalizedVal = $normalize($value);
                if ($normalizedVal !== null) {
                    $components[] = $key . '=' . $normalizedVal;
                }
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

    /**
     * Verify the parameters using RSA-PKCS1.
     *
     * @param array $params
     * @param string $signature
     * @param string $publicKey
     * @return bool
     * @throws SignatureException
     */
    public function verifyPKCS1(array $params, string $signature, string $publicKey): bool
    {
        try {
            $canonicalString = $this->buildCanonicalString($params);

            $rsa = RSA::loadPublicKey($publicKey)
                ->withHash('sha256')
                ->withPadding(RSA::SIGNATURE_PKCS1);

            return $rsa->verify($canonicalString, base64_decode($signature));
        } catch (\Throwable $e) {
            throw new SignatureException("Failed to verify RSA-PKCS1 signature: " . $e->getMessage(), 0, $e);
        }
    }
}

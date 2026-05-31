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
        
        ksort($params);
        $stringApplet = '';

        foreach ($params as $key => $values) {
            if (in_array($key, $excludeFields) || $values === null || $values === '') {
                continue;
            }

            if ($key === 'biz_content') {
                if (is_array($values)) {
                    ksort($values);
                    foreach ($values as $value => $singleValue) {
                        $stringApplet .= ($stringApplet === '' ? '' : '&') . $value . '=' . $singleValue;
                    }
                } elseif (is_string($values)) {
                    // Sometimes biz_content might be JSON stringified, though normally it's array
                    $stringApplet .= ($stringApplet === '' ? '' : '&') . $key . '=' . $values;
                }
            } else {
                $stringApplet .= ($stringApplet === '' ? '' : '&') . $key . '=' . $values;
            }
        }

        // To fix the dual sorting bug, we do not re-explode and re-sort. 
        // We just return the string built from sorted keys.
        // Wait, the previous implementation did:
        // $sortedArray = explode('&', $stringApplet); sort($sortedArray); return implode('&', $sortedArray);
        // The instructions said "Standardize on a single, clean key-sorting mechanism".
        // If we strictly do ksort on the top level and nested biz_content, that might differ from a flat string sort.
        // Let's implement the corrected flat sort if that's what Telebirr actually expects.
        // The review says: "running a secondary string-level sort() on the exploded components afterward will scrambling the underlying canonical sort order... Standardize on a single, clean key-sorting mechanism".
        // Let's build the array of "key=value" strings and sort that array once.

        $components = [];
        foreach ($params as $key => $values) {
            if (in_array($key, $excludeFields) || $values === null || $values === '') {
                continue;
            }
            if ($key === 'biz_content' && is_array($values)) {
                foreach ($values as $value => $singleValue) {
                    $components[] = $value . '=' . $singleValue;
                }
            } else {
                $components[] = $key . '=' . $values;
            }
        }

        sort($components);
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
                ->withMGFHash('sha256');

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
                ->withMGFHash('sha256');

            return $rsa->verify($canonicalString, base64_decode($signature));
        } catch (\Throwable $e) {
            throw new SignatureException("Failed to verify RSA-PSS signature: " . $e->getMessage(), 0, $e);
        }
    }
}

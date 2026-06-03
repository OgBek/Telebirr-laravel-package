<?php

declare(strict_types=1);

namespace Bekambeyene\Telebirr\Contracts;

interface TelebirrClientInterface
{
    /**
     * Get the Telebirr fabric token.
     *
     * @return string
     */
    public function getFabricToken(): string;

    /**
     * Create a pre-order request and return the URL or raw query string.
     *
     * @param string $title
     * @param float $amount
     * @param string|null $merchOrderId
     * @param array $params
     * @return string
     */
    public function createOrder(string $title, float $amount, ?string $merchOrderId = null, array $params = []): string;

    /**
     * Query the status of an existing order.
     *
     * @param string $merchantOrderId
     * @return array
     */
    public function verifyPayment(string $merchantOrderId): array;

    /**
     * Initiate payment using the pre-order API.
     *
     * @param string $orderId
     * @param float $amount
     * @param string $subject
     * @param array $customerInfo
     * @return array
     */
    public function initiatePayment(string $orderId, float $amount, string $subject, array $customerInfo = []): array;

    /**
     * Handle incoming webhook callback from Telebirr.
     * Automatically verifies signature, timestamp freshness, and checks for replay attacks.
     *
     * @param \Illuminate\Http\Request $request
     * @return array Verified and cleaned payload array
     */
    public function handleWebhook(\Illuminate\Http\Request $request): array;

    /**
     * Verify the signature of an incoming webhook payload.
     *
     * @param array $payload
     * @param string $signature
     * @return bool
     */
    public function verifyCallbackSignature(array $payload, string $signature): bool;

    /**
     * Verify that the callback timestamp is within an acceptable window to prevent replay attacks.
     *
     * @param array $payload
     * @param int $maxAgeSeconds Default 300 seconds (5 minutes)
     * @return bool
     */
    public function verifyCallbackTimestamp(array $payload, int $maxAgeSeconds = 300): bool;

    /**
     * Verify that the callback nonce has not been processed recently to prevent replay attacks.
     * Requires Laravel Cache. If Cache is not available, it safely returns true (bypass).
     *
     * @param array $payload
     * @param int $cacheTtlSeconds Default 300 seconds (5 minutes) to match timestamp max age
     * @param bool $strict If true, throws an exception if Cache is unavailable.
     * @return bool True if valid (not a replay), False if nonce already exists in cache.
     */
    public function verifyNonce(array $payload, int $cacheTtlSeconds = 300, bool $strict = true): bool;

    /**
     * Request a refund for an existing order.
     *
     * @param string $outTradeNo The original merchant order ID
     * @param float $refundAmount The amount to refund
     * @param string $outRequestNo A unique ID for this refund request
     * @param array $params Additional parameters (e.g. refund_reason)
     * @return array
     */
    public function refundOrder(string $outTradeNo, float $refundAmount, string $outRequestNo, array $params = []): array;
}

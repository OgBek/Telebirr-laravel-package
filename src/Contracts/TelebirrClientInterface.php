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
     * Verify the signature of an incoming webhook payload.
     *
     * @param array $payload
     * @param string $signature
     * @return bool
     */
    public function verifyCallbackSignature(array $payload, string $signature): bool;
}

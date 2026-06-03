# Webhook Security in Telebirr

## The Threat Model

Payment gateway webhooks are prime targets for:
- Replay attacks (re-sending a valid payload)
- Forgery (crafting fake successful payments)
- Timing attacks

## SDK Protections

The `Bekambeyene\Telebirr` SDK automatically protects against these threats.

### 1. Deterministic RSA-PSS Verification
All incoming webhooks are parsed, and their canonical string is rebuilt and verified against the Telebirr Public Key using RSA-PSS.

### 2. Timestamp Tolerance
The SDK enforces a strict clock-drift tolerance window (`TELEBIRR_WEBHOOK_TOLERANCE_SECONDS`, default 300s). If a webhook arrives with a stale timestamp, it is rejected, shutting down long-term replay attacks.

### 3. Nonce Tracking
To prevent short-term replay attacks within the tolerance window, the SDK caches incoming `nonce_str` values. If the same nonce is seen twice, it throws a `ReplayAttackException`.

## Required Actions

Always ensure your webhook route is excluded from Laravel's CSRF protection:

```php
// bootstrap/app.php
$middleware->validateCsrfTokens(except: [
    'payment/notification',
]);
```

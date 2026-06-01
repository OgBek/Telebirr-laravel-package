# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.0.0] - 2026-06-01

### Added
- **Webhook Security**: Added `verifyCallbackTimestamp()` to `TelebirrClient` to prevent replay attacks.
- **Environment Toggle**: Added an `environment` parameter to `config/telebirr.php` (can be `sandbox` or `production`) to automatically resolve the correct base API and web URLs.
- **HTTP Client Resiliency**: Added configurable exponential backoff retry logic (`max_retries`) to `TelebirrHttpClient` for both Laravel Http and Guzzle fallback.
- **Token Caching**: `TokenManager` now aggressively caches the Fabric token (using Laravel Cache if available, or in-memory fallback) to avoid fetching a new token on every request, reducing latency and rate limits.

### Changed
- **Testing**: `TestCase` now generates its own ephemeral 1024-bit RSA test keys using `phpseclib3` rather than depending on the local machine's `openssl` configuration.
- **Order ID Generation**: Enhanced `createMerchantOrderId()` to be less predictable, combining a timestamp with random hex bytes (`ORDER_{YmdHis}{Random}`).
- **HTTP Client Engine**: Completely refactored `TelebirrHttpClient` to detect and use Laravel's HTTP facade if booted (restoring `Http::fake()` compatibility), while seamlessly falling back to `GuzzleHttp\Client` in vanilla PHP environments.

### Fixed
- **Signature Security**: Explicitly enforced a 32-byte salt length for RSA-PSS signing and verification in `SignatureService` (fixing signature validation failures).
- **Code Duplication**: Cleaned up dead/duplicate canonical string building logic in `SignatureService`.
- **H5 URL Generation**: Consolidated `getRawRequestString` and `createRawRequestUrl` parameter building into a single `buildBaseRequestParams` helper.
- **Repository Hygiene**: Added comprehensive `.gitignore`.

### Removed
- Removed parameter pollution (`?track_number=`) from redirect URLs during order creation to prevent Telebirr redirect corruption.

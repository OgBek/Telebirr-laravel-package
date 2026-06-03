# Production Checklist

Before launching your Telebirr integration to production, verify the following:

## Environment Configuration
- [ ] `TELEBIRR_ENV=production` is set
- [ ] `TELEBIRR_SIGNATURE_PADDING=pss` is explicitly set
- [ ] `TELEBIRR_SSL_VERIFY=true` is set (do not disable SSL verification in production)

## Key Management
- [ ] Production RSA keys are stored securely using file paths (`TELEBIRR_PRIVATE_KEY=file:///etc/secrets/private.pem`) or strict base64 encoding
- [ ] Keys are never checked into version control
- [ ] The correct public key has been provided to Ethio Telecom

## Webhook Reliability
- [ ] CSRF verification is disabled for the webhook route
- [ ] Database locks or idempotency checks are implemented inside the webhook handler to prevent race conditions
- [ ] Server NTP (clock synchronization) is active to prevent timestamp validation failures

## Error Handling
- [ ] `TelebirrServerException` is caught to gracefully handle Telebirr system downtime (`60200087` errors)

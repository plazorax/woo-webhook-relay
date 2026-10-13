# Signatures

When an endpoint has a secret, every attempt carries:

```
X-Relay-Timestamp: 1767225600
X-Relay-Signature: sha256=<hex HMAC-SHA256 of "{timestamp}.{raw body}" with the secret>
```

The timestamp is part of the signed string, so a captured request cannot be
re-sent later with a fresh timestamp. Each retry is signed again with its own
timestamp.

## Verifying

1. Read the raw request body before any JSON parsing.
2. Compute `sha256=` + hex HMAC-SHA256 of `timestamp + "." + body`.
3. Compare with `X-Relay-Signature` in constant time.
4. Reject timestamps more than 5 minutes from your clock.

### PHP

```php
function relay_request_is_valid( string $secret ): bool {
    $body      = file_get_contents( 'php://input' );
    $timestamp = (int) ( $_SERVER['HTTP_X_RELAY_TIMESTAMP'] ?? 0 );
    $signature = (string) ( $_SERVER['HTTP_X_RELAY_SIGNATURE'] ?? '' );
    $expected  = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );

    return abs( time() - $timestamp ) <= 300 && hash_equals( $expected, $signature );
}
```

### Node.js

```js
import crypto from 'node:crypto';

// rawBody must be the exact bytes received (e.g. express.raw({ type: 'application/json' })).
export function relayRequestIsValid(secret, rawBody, headers) {
  const timestamp = Number(headers['x-relay-timestamp'] ?? 0);
  const received = Buffer.from(String(headers['x-relay-signature'] ?? ''));
  const expected = Buffer.from(
    'sha256=' + crypto.createHmac('sha256', secret).update(`${timestamp}.${rawBody}`).digest('hex')
  );

  return Math.abs(Date.now() / 1000 - timestamp) <= 300
    && received.length === expected.length
    && crypto.timingSafeEqual(received, expected);
}
```

The plugin's own `WooWebhookRelay\Delivery\Signer::verify()` implements the
same check and is covered by a test vector computed with
`openssl dgst -sha256 -hmac`.

## Duplicates and idempotency

Delivery is at least once. A receiver can see the same event more than once:
when it answered after the timeout, when a retry raced a slow response, or
when someone replays a delivery. The body's `id` (also sent as
`X-Relay-Event-Id`) identifies the event and stays the same across retries and
replays, so store it and skip events you have already processed. Use
`X-Relay-Delivery` and `X-Relay-Attempt` only for logging.

## Secrets

- Secrets are stored in the `woo_webhook_relay_endpoints` option, because the
  plugin needs the plain value to sign requests. Anyone with database access
  can read them.
- They are never printed back into the settings page, listed by WP-CLI, or
  written to the delivery log (request headers are not logged).
- To rotate a secret, enter the new value and update the receiver. Deliveries
  are signed at send time, so queued retries use the new secret.

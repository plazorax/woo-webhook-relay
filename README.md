# Woo Webhook Relay

![CI](https://github.com/plazorax/woo-webhook-relay/actions/workflows/ci.yml/badge.svg)

A WooCommerce plugin that sends signed webhooks for order and customer events to any URL (a CRM, Slack, n8n, Make, Zapier or an internal service) and retries failed deliveries with backoff.

## Why

- WooCommerce's built-in webhooks do not retry a failed delivery, and disable the webhook after more than five consecutive failures. Here failures are retried with exponential backoff through Action Scheduler ([comparison](docs/built-in-webhooks.md)).
- Every delivery is kept in a log table with status, response code and attempts, and can be retried or replayed from the admin or WP-CLI.
- One endpoint subscribes to several events and picks its payload: a full snapshot, a slim one without addresses, or a ready-made Slack message.
- The signature covers a timestamp, so receivers can reject stale or replayed requests.

## Install

Requires PHP 8.1+, WordPress 6.4+ and WooCommerce 8+. The plugin is not on WordPress.org and needs no Composer install at runtime:

```bash
cd wp-content/plugins
git clone https://github.com/plazorax/woo-webhook-relay.git
wp plugin activate woo-webhook-relay
```

## Usage

Add an endpoint under **WooCommerce > Webhook Relay** (URL, secret of 16+ characters, events, payload format), then send a sample event:

```bash
wp webhook-relay test ep_1a2b3c4d --event=order.created
```

Verify each request on the receiving side:

```php
$body      = file_get_contents( 'php://input' );
$timestamp = (int) ( $_SERVER['HTTP_X_RELAY_TIMESTAMP'] ?? 0 );
$expected  = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );

if ( abs( time() - $timestamp ) > 300 || ! hash_equals( $expected, $_SERVER['HTTP_X_RELAY_SIGNATURE'] ?? '' ) ) {
    http_response_code( 401 );
    exit;
}
```

More in [configuration](docs/configuration.md), [payloads](docs/payloads.md), [signatures](docs/signatures.md) and [WP-CLI](docs/wp-cli.md).

## Features

- Events: `order.created`, `order.status_changed`, `order.refunded`, `customer.created`.
- Retries for connection errors, timeouts, 408, 425, 429 and 5xx (about 1, 3, 9, 27 and 81 minutes apart by default, with jitter); other 4xx responses fail at once.
- Delivery log in a custom table with status filters, payload and response preview, Retry/Replay links and a retention cleanup job.
- WP-CLI commands: `endpoints`, `deliveries`, `replay`, `test`.
- HPOS compatible; requests use `wp_safe_remote_post()`; secrets never appear in the admin, CLI output or log.
- PHPCS (WordPress-Extra), PHPStan level 9 and PHPUnit with Brain Monkey, run in CI on PHP 8.1 and 8.3.

## Status

v0.1.0 — reference implementation. Unit-tested and smoke-tested by hand on WordPress 7.1 and WooCommerce 11.2 (legacy and HPOS order storage) in Docker; not yet run on a production store. See [limitations](docs/limitations.md) and [development](docs/development.md).

## License

[MIT](LICENSE) © 2026 Oleksii Shaposhnikov

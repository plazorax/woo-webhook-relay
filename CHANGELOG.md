# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [0.1.0] - 2026-10-13

### Added

- Events: `order.created`, `order.status_changed`, `order.refunded` and
  `customer.created`. Draft orders (`checkout-draft`, `auto-draft`) are
  reported as created when they leave the draft status.
- Endpoints managed under WooCommerce > Webhook Relay (Settings API): URL,
  secret, subscribed events, payload format (`full`, `slim`, `slack`), enabled.
- HMAC-SHA256 signature over `{timestamp}.{body}` in `X-Relay-Signature`, with
  the timestamp in `X-Relay-Timestamp`.
- Asynchronous delivery through Action Scheduler, with exponential backoff and
  jitter for timeouts, 408, 425, 429 and 5xx responses; max attempts is
  configurable (1-10, default 6).
- Delivery log in a custom table (created with `dbDelta()`, versioned), an
  admin list with status filters, payload and response details, and a
  nonce-protected Retry / Replay action.
- Daily cleanup of delivered and failed log rows after a configurable
  retention period.
- WP-CLI: `wp webhook-relay endpoints`, `deliveries`, `replay` and `test`.
- HPOS (custom order tables) compatibility declaration.
- Uninstall routine that removes the table, options and scheduled actions.

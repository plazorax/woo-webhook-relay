# Limitations

Known gaps in v0.1.0:

- **Not run on a production store.** Unit tests plus one manual smoke test
  (see [development](development.md)) are all the testing so far.
- **Four events only.** No product, coupon, subscription or order-deleted
  events, and no custom topics.
- **Snapshots, not live data.** The payload is built at the end of the request
  that triggered the event and never refreshed; a replay sends what was true
  then.
- **At-least-once delivery.** Receivers must de-duplicate by event `id`; see
  [signatures](signatures.md).
- **Ordering is not guaranteed.** Deliveries run in parallel queue batches and
  retries can overtake later events. Use `created_at` and the order status in
  the payload, not arrival order.
- **Admin-created orders** rely on WooCommerce moving them out of
  `auto-draft`; that path was not part of the smoke test.
- **`Retry-After` is ignored.** 429 and 503 responses use the normal backoff.
- **Transport errors are all retried**, including configuration problems such
  as a blocked internal host, until max attempts is reached.
- **An attempt killed mid-request stays `pending`.** If PHP dies during the
  HTTP call (fatal error, process timeout), the result is never recorded and
  no retry is scheduled; Action Scheduler marks the action as failed. Replay
  such rows by hand. The retention cleanup does not remove them.
- **SSRF protection is WordPress core's.** Anyone with `manage_woocommerce` can
  set an endpoint URL and read up to 2,000 bytes of the response in the log.
  Which address ranges `wp_safe_remote_post()` blocks depends on the
  WordPress version (see [configuration](configuration.md#internal-hosts-and-non-standard-ports)).
- **Secrets are stored in plain text** in the options table; the plugin needs
  the plain value to sign requests.
- **Payloads can contain personal data** (names, emails, addresses) in the
  `full` format and are kept in the log for the retention period. Use `slim`
  when the receiver does not need it.
- **Deactivation leaves queued deliveries in Action Scheduler.** They are
  processed without this plugin's handler, so their log rows stay
  `pending`/`retrying`; replay them after reactivating.
- **Multisite is untested.** Each site creates its own table on its first
  request with WooCommerce and the plugin active, but uninstall only removes
  the main site's table and options.
- **No bulk actions, search or endpoint filter** in the admin log (the
  `deliveries` WP-CLI command can filter by endpoint).
- **Not on WordPress.org**, and no automatic updates.

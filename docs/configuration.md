# Configuration

Everything lives under **WooCommerce > Webhook Relay**. The page needs the
`manage_woocommerce` capability (shop managers and administrators).

## Endpoints

Each endpoint has:

| Field | Notes |
| --- | --- |
| ID | Generated on save (`ep_` + 8 hex characters). Used by WP-CLI and in the log. |
| Name | Label for the admin. Defaults to the URL's host. |
| URL | Absolute `http://` or `https://` URL. |
| Secret | At least 16 printable ASCII characters; whitespace and any other characters are stripped. Required for the `full` and `slim` formats, optional for `slack`. The field is always shown empty; leave it blank to keep the saved secret. |
| Events | Any of `order.created`, `order.status_changed`, `order.refunded`, `customer.created`. |
| Payload format | `full`, `slim` or `slack`; see [payloads](payloads.md). |
| Enabled | Disabled endpoints get no new deliveries, and queued deliveries to them fail with "Endpoint is disabled." |

Ticking **Delete** removes the endpoint on save. Its log rows stay until the
retention cleanup removes them.

An invalid row (bad URL, missing or short secret) is not saved; an error is
shown and the previously stored version of that endpoint is kept.

The form saves through the Settings API (`options.php`) as two options:
`woo_webhook_relay_endpoints` and `woo_webhook_relay_settings`. The whole
endpoint list is replaced on every save, so endpoints that are missing from
the submitted form are removed.

## Delivery settings

| Setting | Default | Range | Meaning |
| --- | --- | --- | --- |
| Max attempts | 6 | 1-10 | Attempts per delivery, including the first. |
| Timeout | 10 s | 1-30 | HTTP timeout per attempt. |
| Keep log for | 30 days | 1-365 | Delivered and failed rows older than this are deleted by a daily job. Pending and retrying rows are kept. |

Backoff is fixed in code: 60 s base, factor 3, 6 h cap, up to 10 % jitter.
With 6 attempts, retries happen about 1, 3, 9, 27 and 81 minutes after the
previous attempt.

## Internal hosts and non-standard ports

Requests go through `wp_safe_remote_post()`, which refuses private and
loopback addresses and only allows ports 80, 443 and 8080. The exact list of
blocked ranges depends on the WordPress version; recent versions also block
link-local (`169.254.0.0/16`, used by cloud metadata services), CGNAT and other
reserved ranges. To send to an
internal service (for example a self-hosted n8n on `10.0.0.5:5678`), allow it
with the core filters in a small mu-plugin:

```php
add_filter( 'http_request_host_is_external', fn( $external, $host ) => $external || '10.0.0.5' === $host, 10, 2 );
add_filter( 'http_allowed_safe_ports', fn( $ports ) => array_merge( $ports, [ 5678 ] ) );
```

Without them the attempt fails with "A valid URL was not provided." and is
retried like any transport error.

Other request arguments can be changed with the core `http_request_args`
filter.

## Background processing

Deliveries run as Action Scheduler actions (hook `woo_webhook_relay_deliver`,
group `woo-webhook-relay`), visible under **WooCommerce > Status > Scheduled
Actions**. Action Scheduler runs on WP-Cron by default; on low-traffic sites
or with `DISABLE_WP_CRON`, run a real cron job or
`wp action-scheduler run`.

## Filters

- `woo_webhook_relay_payload` (`array $payload, Event $event, Endpoint $endpoint`):
  change the payload before it is encoded and stored. Runs once per delivery,
  not per attempt.

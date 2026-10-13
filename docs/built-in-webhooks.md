# Compared with WooCommerce webhooks

WooCommerce ships webhooks under **WooCommerce > Settings > Advanced >
Webhooks**. They cover more topics (products, coupons, deleted resources,
custom action hooks) and use the REST API payload, so for many stores they are
enough. This is what they do, from the WooCommerce documentation and source
(`includes/class-wc-webhook.php`, `includes/wc-webhook-functions.php`, read in
October 2026), next to this plugin:

| | WooCommerce webhooks | woo-webhook-relay |
| --- | --- | --- |
| Topics per webhook | One | Any subset of four events |
| Background delivery | Yes, queued on `shutdown` through `WC()->queue()` | Yes, Action Scheduler |
| Failed delivery | Not retried; a failure counter goes up | Retried with exponential backoff for timeouts, 408/425/429 and 5xx |
| Repeated failures | Webhook is disabled after more than 5 consecutive failures (filter `woocommerce_max_webhook_delivery_failures`) | Each delivery gives up after max attempts; the endpoint stays enabled |
| Success | 2xx, 301 or 302 | 2xx only; redirects are not followed |
| Log | WooCommerce logger, source `webhooks-delivery`; bodies only with `WP_DEBUG` | Own table: status, attempts, response code and body, stored payload, next attempt |
| Resend | None documented | Retry/Replay in the admin log, `wp webhook-relay replay` |
| Signature | `X-WC-Webhook-Signature`: base64 HMAC-SHA256 of the body | `X-Relay-Signature`: hex HMAC-SHA256 of `timestamp.body`, plus `X-Relay-Timestamp` |
| Payload | REST API representation of the resource | `full`, `slim` or Slack message |
| Test | Ping when first saved as active | `wp webhook-relay test` with sample data for any event |

Sources:

- https://woocommerce.com/document/webhooks/
- https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/includes/class-wc-webhook.php
- https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/includes/wc-webhook-functions.php

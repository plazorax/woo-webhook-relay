# Payloads

Every request is a `POST` with a JSON body and these headers:

| Header | Example | Notes |
| --- | --- | --- |
| `Content-Type` | `application/json; charset=utf-8` | |
| `User-Agent` | `WooWebhookRelay/0.1.0` | |
| `X-Relay-Event` | `order.created` | |
| `X-Relay-Event-Id` | `6f1c2b3a-...` | Same for retries and replays of the same event. |
| `X-Relay-Delivery` | `42` | Row ID in the delivery log. A replay gets a new one. |
| `X-Relay-Attempt` | `1` | Attempt number for this delivery. |
| `X-Relay-Timestamp` | `1767225600` | Unix time of this attempt. |
| `X-Relay-Signature` | `sha256=...` | Only when the endpoint has a secret. See [signatures](signatures.md). |

## When the snapshot is taken

Hook callbacks only record that something happened. On `shutdown`, at the end
of the request, the plugin loads the order or customer, takes a snapshot and
stores one rendered payload per subscribed endpoint. So:

- line items and totals saved later in the same request are included;
- `order.created` for a checkout that also took payment shows the final
  status (for example `processing`), and a separate `order.status_changed`
  is sent for the transition;
- retries and replays send the stored body unchanged; they do not re-read the
  order.

## Events

| Event | Fired on | `data` keys |
| --- | --- | --- |
| `order.created` | `woocommerce_new_order` for non-draft orders, or a status change away from `checkout-draft` / `auto-draft` / `draft` | `order` |
| `order.status_changed` | `woocommerce_order_status_changed` (not from or to a draft status) | `order`, `previous_status`, `new_status` |
| `order.refunded` | `woocommerce_order_refunded` (full and partial refunds) | `order`, `refund` |
| `customer.created` | `woocommerce_created_customer` (checkout, My Account) and `woocommerce_new_customer` (`WC_Customer::save()`, REST API) | `customer` |

`order.created` and `customer.created` are de-duplicated per request, so an
order or customer that triggers several of these hooks is reported once.

## `full`

```json
{
  "id": "6f1c2b3a-0000-4000-8000-000000000001",
  "event": "order.status_changed",
  "created_at": "2026-01-01T12:00:00+00:00",
  "test": false,
  "data": {
    "order": {
      "id": 1001, "number": "1001", "status": "processing", "currency": "EUR",
      "total": "59.00", "subtotal": "50.00", "total_tax": "4.00",
      "shipping_total": "5.00", "discount_total": "0.00",
      "payment_method": "bacs", "payment_method_title": "Direct bank transfer",
      "customer_id": 7, "customer_note": "",
      "created_at": "2026-01-01T12:00:00+00:00", "paid_at": null,
      "billing": { "first_name": "Jane", "last_name": "Doe", "email": "jane@example.com", "...": "..." },
      "shipping": { "first_name": "Jane", "last_name": "Doe", "...": "..." },
      "line_items": [
        { "id": 1, "name": "Sample product", "product_id": 10, "variation_id": 0,
          "sku": "SAMPLE-1", "quantity": 2, "subtotal": "50.00", "total": "50.00" }
      ],
      "edit_url": "https://shop.example/wp-admin/admin.php?page=wc-orders&action=edit&id=1001"
    },
    "previous_status": "pending",
    "new_status": "processing"
  }
}
```

Amounts are decimal strings with the store's price precision. Dates are ISO
8601 in UTC. `refund` has `id`, `amount`, `reason` and `created_at`.
`customer` has `id`, `email`, `first_name`, `last_name`, `username`,
`created_at`, `billing`, `shipping` and `edit_url`.

This is the plugin's own shape, not the WooCommerce REST API schema.

## `slim`

Same envelope; the order keeps `id`, `number`, `status`, `currency`, `total`,
`edit_url`, plus `customer_email` and `item_count`. Addresses, phone numbers
and line items are left out. `previous_status`, `new_status` and `refund` are
kept. A customer keeps `id`, `email`, `first_name` and `last_name`.

```json
{
  "id": "6f1c2b3a-0000-4000-8000-000000000001",
  "event": "order.created",
  "created_at": "2026-01-01T12:00:00+00:00",
  "test": false,
  "data": {
    "order": {
      "id": 1001, "number": "1001", "status": "pending", "currency": "EUR",
      "total": "59.00", "edit_url": "", "customer_email": "jane@example.com",
      "item_count": 2
    }
  }
}
```

## `slack`

A body that Slack incoming webhooks accept as is: `text` (also the
notification fallback) and two blocks.

```json
{
  "text": "New order <https://shop.example/wp-admin/...|#1001> from Jane Doe: 59.00 EUR (pending)",
  "blocks": [
    { "type": "section", "text": { "type": "mrkdwn", "text": "New order <...|#1001> from Jane Doe: 59.00 EUR (pending)" } },
    { "type": "context", "elements": [ { "type": "mrkdwn", "text": "Demo Store · `order.created`" } ] }
  ]
}
```

`&`, `<` and `>` in names, reasons and the store name are escaped, so
customer input cannot produce Slack mentions or links. Slack does not check
the signature headers; the URL itself is the secret, so the secret field is
optional for this format.

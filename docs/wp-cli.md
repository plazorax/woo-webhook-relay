# WP-CLI

All commands live under `wp webhook-relay`. Run `wp help webhook-relay <command>`
for the full synopsis.

## `endpoints`

Lists configured endpoints. Secrets are never printed; the `signed` column
shows whether one is set.

```console
$ wp webhook-relay endpoints
id           name  url                            events                        format  signed  enabled
ep_1a2b3c4d  CRM   https://crm.example.com/hooks  order.created,order.refunded  slim    yes     yes
```

Options: `--format=table|json|csv`.

## `deliveries`

Lists recent deliveries, newest first.

```console
$ wp webhook-relay deliveries --status=failed --limit=5
```

Options: `--status=pending|retrying|delivered|failed`, `--endpoint=<id>`,
`--limit=<n>` (default 20), `--format=table|json|csv`.

## `replay <id>`

Creates a new delivery with the same endpoint, event ID and stored payload as
delivery `<id>`, and queues it. Works for any status; the original row is not
changed and the new row shows "replay of #id" in the admin. The **Retry** and
**Replay** links in the admin log do the same thing.

```console
$ wp webhook-relay replay 42          # queue it
$ wp webhook-relay replay 42 --now    # send in this process and print the result
```

With `--now` the copy is not queued, so a queue runner cannot send it a second
time; a retryable failure is still retried through Action Scheduler.

## `test <endpoint>`

Sends a sample event (fixed fake data, `"test": true` in the envelope,
`resource_id` 0 in the log) to one endpoint right away and prints the result.
The endpoint does not need to subscribe to the event, but it must be enabled.
The delivery is logged, and retried on a retryable failure, like any other.

```console
$ wp webhook-relay test ep_1a2b3c4d --event=order.refunded
Success: Delivery #57: delivered, HTTP 200, attempt 1.
```

`--event` accepts `order.created` (default), `order.status_changed`,
`order.refunded` and `customer.created`.

# Development

```bash
composer install
composer lint      # PHPCS
composer analyse   # PHPStan
composer test      # PHPUnit
composer check     # all three
```

The plugin itself has no runtime Composer dependencies; `src/Autoloader.php`
loads its classes. Composer is only used for the tools below.

## Checks

| Tool | Configuration | Notes |
| --- | --- | --- |
| PHPCS 3 + WordPress Coding Standards 3 | `phpcs.xml.dist` | `WordPress-Extra`, minus two rules: `WordPress.Files.FileName` (PSR-4 file names) and `Universal.Arrays.DisallowShortArraySyntax` (short arrays). Text domain, global prefix and `minimum_wp_version` 6.4 are configured. Tests may use camelCase PHPUnit methods. `WordPress-Docs` is not enabled. |
| PHPStan 2 + `szepeviktor/phpstan-wordpress` | `phpstan.neon.dist` | Level 9 on `src/`, the main file and `uninstall.php`, with WordPress, WooCommerce and WP-CLI stubs. |
| PHPUnit 10 + Brain Monkey | `phpunit.xml.dist` | Unit tests only; WordPress functions are stubbed per test. |

CI (`.github/workflows/ci.yml`) runs all three on PHP 8.1 and 8.3.

## Tests

`tests/Unit` covers the parts that make decisions:

- `SignerTest`: an independent HMAC vector, tampering, wrong secret, clock skew.
- `RetryPolicyTest`: backoff sequence, cap, jitter bounds, which responses are retried.
- `PayloadFormattersTest`: full / slim / Slack output, Slack escaping.
- `EndpointTest`, `EndpointSanitizerTest`, `SettingsTest`: stored-data parsing,
  event filtering and form validation, including sanitizer idempotency.
- `DeliveryWorkerTest`: headers and signature, retry scheduling, give-up,
  disabled/deleted endpoints, response truncation.
- `DispatcherTest`: one delivery per subscribed endpoint, payload filter, replay.
- `EventListenerTest`: draft handling, de-duplication, flush on shutdown.

`tests/Support` has in-memory fakes for the delivery store, queue and HTTP
transport. Classes that only wire WordPress together (`Plugin`, `Admin\*`,
`Cli\Command`, `WpdbDeliveryStore`, `Snapshot`, `EventFactory`) have no unit
tests.

## Manual smoke test

Before tagging 0.1.0 the plugin was run by hand in Docker
(`wordpress:php8.2-apache`, MariaDB 11) on WordPress 7.1.3 and WooCommerce
11.2.1, with a local PHP receiver that verified signatures. Checked:

- activation creates the table; `wp webhook-relay endpoints` and `test` work;
- orders created with the CRUD API, on legacy post storage and with HPOS
  enabled (including one saved as `checkout-draft` first, as block checkout
  does), produce `order.created` once, then `order.status_changed`; a partial
  refund produces `order.refunded`; `WC_Customer::save()` produces
  `customer.created`. A real Store API checkout was not part of the test;
- a 503 response is retried about 60 s later by WP-Cron + Action Scheduler and
  delivered on attempt 2;
- the settings form saves through `options.php`, keeps a blank secret,
  deletes endpoints and shows validation errors; secrets are not in the HTML;
- as a shop manager, the admin Retry/Replay link queues a copy; a bad nonce
  returns 403;
- the cleanup job deletes old rows; deactivation unschedules it; uninstall
  drops the table and options.

This is not automated and was not repeated on other versions.

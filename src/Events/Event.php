<?php
/**
 * A store event with a snapshot of the resource it is about.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Events;

/**
 * Immutable event. `data` holds plain arrays only, so payload formatters
 * never touch WooCommerce objects.
 */
final class Event {

	/**
	 * Creates an event.
	 *
	 * @param string               $id          UUID; stays the same across retries and replays.
	 * @param string               $name        One of the EventType constants.
	 * @param int                  $resource_id Order or customer ID.
	 * @param int                  $occurred_at Unix timestamp.
	 * @param array<string, mixed> $data        Resource snapshot, e.g. [ 'order' => [...], 'previous_status' => 'pending' ].
	 * @param bool                 $is_test     True for events sent with `wp webhook-relay test`.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly int $resource_id,
		public readonly int $occurred_at,
		public readonly array $data,
		public readonly bool $is_test = false,
	) {}
}

<?php
/**
 * Full payload: the complete snapshot.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Payload;

use WooWebhookRelay\Events\Event;

/**
 * Sends everything the snapshot holds: addresses, line items, totals.
 */
final class FullFormatter implements Formatter {

	/**
	 * Builds the payload.
	 *
	 * @param Event $event Event.
	 * @return array<string, mixed>
	 */
	public function format( Event $event ): array {
		return Envelope::wrap( $event, $event->data );
	}
}

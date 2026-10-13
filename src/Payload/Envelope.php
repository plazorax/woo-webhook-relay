<?php
/**
 * The common wrapper of the full and slim formats.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Payload;

use WooWebhookRelay\Events\Event;

/**
 * Shape: { id, event, created_at, test, data }.
 */
final class Envelope {

	/**
	 * Wraps data in the envelope.
	 *
	 * @param Event                $event Event.
	 * @param array<string, mixed> $data  Payload data.
	 * @return array<string, mixed>
	 */
	public static function wrap( Event $event, array $data ): array {
		return [
			'id'         => $event->id,
			'event'      => $event->name,
			'created_at' => gmdate( 'c', $event->occurred_at ),
			'test'       => $event->is_test,
			'data'       => $data,
		];
	}
}

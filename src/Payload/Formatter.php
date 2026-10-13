<?php
/**
 * Turns an event into a JSON-serialisable payload.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Payload;

use WooWebhookRelay\Events\Event;

/**
 * Formatters are pure: no WordPress or WooCommerce calls.
 */
interface Formatter {

	/**
	 * Builds the payload.
	 *
	 * @param Event $event Event.
	 * @return array<string, mixed>
	 */
	public function format( Event $event ): array;
}

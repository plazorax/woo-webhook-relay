<?php
/**
 * Slim payload: identifiers, status and totals only.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Payload;

use WooWebhookRelay\Events\Event;

/**
 * For receivers that only need to know what changed and look the rest up
 * themselves. Leaves out addresses, phone numbers and line items.
 */
final class SlimFormatter implements Formatter {

	private const ORDER_FIELDS    = [ 'id', 'number', 'status', 'currency', 'total', 'edit_url' ];
	private const CUSTOMER_FIELDS = [ 'id', 'email', 'first_name', 'last_name' ];
	private const EXTRA_FIELDS    = [ 'previous_status', 'new_status', 'refund' ];

	/**
	 * Builds the payload.
	 *
	 * @param Event $event Event.
	 * @return array<string, mixed>
	 */
	public function format( Event $event ): array {
		$data = [];

		if ( isset( $event->data['order'] ) && is_array( $event->data['order'] ) ) {
			$order         = $event->data['order'];
			$billing       = is_array( $order['billing'] ?? null ) ? $order['billing'] : [];
			$data['order'] = self::pick( $order, self::ORDER_FIELDS ) + [
				'customer_email' => is_string( $billing['email'] ?? null ) ? $billing['email'] : '',
				'item_count'     => self::item_count( $order['line_items'] ?? [] ),
			];
		}

		if ( isset( $event->data['customer'] ) && is_array( $event->data['customer'] ) ) {
			$data['customer'] = self::pick( $event->data['customer'], self::CUSTOMER_FIELDS );
		}

		return Envelope::wrap( $event, $data + self::pick( $event->data, self::EXTRA_FIELDS ) );
	}

	/**
	 * Keeps only the listed keys, in the listed order.
	 *
	 * @param array<mixed>  $source Source array.
	 * @param array<string> $keys   Keys to keep.
	 * @return array<string, mixed>
	 */
	private static function pick( array $source, array $keys ): array {
		$picked = [];

		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $source ) ) {
				$picked[ $key ] = $source[ $key ];
			}
		}

		return $picked;
	}

	/**
	 * Sums line item quantities.
	 *
	 * @param mixed $line_items Line items from the snapshot.
	 */
	private static function item_count( mixed $line_items ): int {
		if ( ! is_array( $line_items ) ) {
			return 0;
		}

		$count = 0;

		foreach ( $line_items as $item ) {
			if ( is_array( $item ) && is_numeric( $item['quantity'] ?? null ) ) {
				$count += (int) $item['quantity'];
			}
		}

		return $count;
	}
}

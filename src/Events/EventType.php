<?php
/**
 * The store events an endpoint can subscribe to.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Events;

/**
 * Event names used in payloads, headers, settings and the delivery log.
 */
final class EventType {

	public const ORDER_CREATED        = 'order.created';
	public const ORDER_STATUS_CHANGED = 'order.status_changed';
	public const ORDER_REFUNDED       = 'order.refunded';
	public const CUSTOMER_CREATED     = 'customer.created';

	/**
	 * All supported event names.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return [
			self::ORDER_CREATED,
			self::ORDER_STATUS_CHANGED,
			self::ORDER_REFUNDED,
			self::CUSTOMER_CREATED,
		];
	}

	/**
	 * Whether the name is a supported event.
	 *
	 * @param string $name Event name.
	 */
	public static function is_valid( string $name ): bool {
		return in_array( $name, self::all(), true );
	}

	/**
	 * Human-readable labels keyed by event name.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return [
			self::ORDER_CREATED        => __( 'Order created', 'woo-webhook-relay' ),
			self::ORDER_STATUS_CHANGED => __( 'Order status changed', 'woo-webhook-relay' ),
			self::ORDER_REFUNDED       => __( 'Order refunded', 'woo-webhook-relay' ),
			self::CUSTOMER_CREATED     => __( 'Customer created', 'woo-webhook-relay' ),
		];
	}
}

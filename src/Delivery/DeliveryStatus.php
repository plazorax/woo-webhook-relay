<?php
/**
 * Delivery lifecycle states.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Delivery;

/**
 * Pending -> (Retrying ->)* Delivered | Failed.
 */
enum DeliveryStatus: string {

	case Pending   = 'pending';
	case Retrying  = 'retrying';
	case Delivered = 'delivered';
	case Failed    = 'failed';

	/**
	 * Whether the worker will not send this delivery again.
	 */
	public function is_final(): bool {
		return self::Delivered === $this || self::Failed === $this;
	}

	/**
	 * Human-readable label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Pending   => __( 'Pending', 'woo-webhook-relay' ),
			self::Retrying  => __( 'Retrying', 'woo-webhook-relay' ),
			self::Delivered => __( 'Delivered', 'woo-webhook-relay' ),
			self::Failed    => __( 'Failed', 'woo-webhook-relay' ),
		};
	}
}

<?php
/**
 * Queue backed by Action Scheduler, which ships with WooCommerce.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Delivery;

/**
 * Jobs appear under WooCommerce > Status > Scheduled Actions, group
 * "woo-webhook-relay".
 */
final class ActionSchedulerQueue implements Queue {

	public const HOOK  = 'woo_webhook_relay_deliver';
	public const GROUP = 'woo-webhook-relay';

	/**
	 * Sends a delivery as soon as the queue runner picks it up.
	 *
	 * @param int $delivery_id Delivery ID.
	 */
	public function enqueue( int $delivery_id ): void {
		as_enqueue_async_action( self::HOOK, [ $delivery_id ], self::GROUP );
	}

	/**
	 * Sends a delivery at a given time.
	 *
	 * @param int $delivery_id Delivery ID.
	 * @param int $timestamp   Unix timestamp.
	 */
	public function schedule( int $delivery_id, int $timestamp ): void {
		as_schedule_single_action( $timestamp, self::HOOK, [ $delivery_id ], self::GROUP );
	}
}

<?php
/**
 * Background job scheduling.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Delivery;

/**
 * Implemented by ActionSchedulerQueue; tests use a recording fake.
 */
interface Queue {

	/**
	 * Sends a delivery as soon as the queue runner picks it up.
	 *
	 * @param int $delivery_id Delivery ID.
	 */
	public function enqueue( int $delivery_id ): void;

	/**
	 * Sends a delivery at a given time.
	 *
	 * @param int $delivery_id Delivery ID.
	 * @param int $timestamp   Unix timestamp.
	 */
	public function schedule( int $delivery_id, int $timestamp ): void;
}

<?php
/**
 * Queue fake that records calls.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Tests\Support;

use WooWebhookRelay\Delivery\Queue;

/**
 * Records enqueued and scheduled delivery IDs.
 */
final class RecordingQueue implements Queue {

	/**
	 * Enqueued delivery IDs.
	 *
	 * @var list<int>
	 */
	public array $enqueued = [];

	/**
	 * Scheduled retries as [delivery ID, timestamp].
	 *
	 * @var list<array{int, int}>
	 */
	public array $scheduled = [];

	public function enqueue( int $delivery_id ): void {
		$this->enqueued[] = $delivery_id;
	}

	public function schedule( int $delivery_id, int $timestamp ): void {
		$this->scheduled[] = [ $delivery_id, $timestamp ];
	}
}

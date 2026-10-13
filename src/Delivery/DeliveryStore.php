<?php
/**
 * Persistence for the delivery log.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Delivery;

/**
 * Implemented by WpdbDeliveryStore; tests use an in-memory version.
 */
interface DeliveryStore {

	/**
	 * Inserts a pending delivery.
	 *
	 * @param array{endpoint_id: string, event: string, event_id: string, resource_id: int, payload: string, replay_of?: int|null} $data Delivery data.
	 * @return int New delivery ID, or 0 on failure.
	 */
	public function insert( array $data ): int;

	/**
	 * Finds a delivery.
	 *
	 * @param int $id Delivery ID.
	 */
	public function find( int $id ): ?Delivery;

	/**
	 * Updates columns of a delivery; `updated_at` is set automatically.
	 *
	 * @param int                                $id     Delivery ID.
	 * @param array<string, string|int|null>     $fields Column values.
	 */
	public function update( int $id, array $fields ): void;

	/**
	 * Lists deliveries, newest first.
	 *
	 * @param array{status?: string, endpoint_id?: string} $filters Exact-match filters.
	 * @param int                                          $limit   Page size.
	 * @param int                                          $offset  Offset.
	 * @return list<Delivery>
	 */
	public function query( array $filters, int $limit, int $offset = 0 ): array;

	/**
	 * Counts deliveries matching the filters.
	 *
	 * @param array{status?: string, endpoint_id?: string} $filters Exact-match filters.
	 */
	public function count( array $filters ): int;

	/**
	 * Deletes delivered and failed rows created before a date.
	 *
	 * @param string $before UTC "Y-m-d H:i:s".
	 * @return int Rows deleted.
	 */
	public function delete_finished_before( string $before ): int;
}

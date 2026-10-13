<?php
/**
 * Delivery log in a custom table.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Delivery;

use wpdb;
use WooWebhookRelay\Schema;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table; there is no WordPress API for it, and rows change too often to cache.

/**
 * Stores deliveries in {prefix}woo_webhook_relay_deliveries.
 */
final class WpdbDeliveryStore implements DeliveryStore {

	/**
	 * Table name with prefix.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Creates the store.
	 *
	 * @param wpdb $db WordPress database.
	 */
	public function __construct( private readonly wpdb $db ) {
		$this->table = Schema::table_name( $db );
	}

	/**
	 * Inserts a pending delivery.
	 *
	 * @param array{endpoint_id: string, event: string, event_id: string, resource_id: int, payload: string, replay_of?: int|null} $data Delivery data.
	 */
	public function insert( array $data ): int {
		$now = gmdate( 'Y-m-d H:i:s' );
		$row = [
			'endpoint_id' => $data['endpoint_id'],
			'event'       => $data['event'],
			'event_id'    => $data['event_id'],
			'resource_id' => $data['resource_id'],
			'status'      => DeliveryStatus::Pending->value,
			'attempts'    => 0,
			'payload'     => $data['payload'],
			'created_at'  => $now,
			'updated_at'  => $now,
		];

		if ( ! empty( $data['replay_of'] ) ) {
			$row['replay_of'] = $data['replay_of'];
		}

		$inserted = $this->db->insert( $this->table, $row );

		return false === $inserted ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * Finds a delivery.
	 *
	 * @param int $id Delivery ID.
	 */
	public function find( int $id ): ?Delivery {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, $id ),
			ARRAY_A
		);

		return is_array( $row ) ? Delivery::from_row( $row ) : null;
	}

	/**
	 * Updates columns of a delivery.
	 *
	 * @param int                            $id     Delivery ID.
	 * @param array<string, string|int|null> $fields Column values.
	 */
	public function update( int $id, array $fields ): void {
		$fields['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		$this->db->update( $this->table, $fields, [ 'id' => $id ] );
	}

	/**
	 * Lists deliveries, newest first.
	 *
	 * @param array{status?: string, endpoint_id?: string} $filters Filters.
	 * @param int                                          $limit   Page size.
	 * @param int                                          $offset  Offset.
	 * @return list<Delivery>
	 */
	public function query( array $filters, int $limit, int $offset = 0 ): array {
		$status   = $filters['status'] ?? '';
		$endpoint = $filters['endpoint_id'] ?? '';

		// An empty filter value matches every row, so the SQL stays static.
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM %i WHERE ( %s = '' OR status = %s ) AND ( %s = '' OR endpoint_id = %s ) ORDER BY id DESC LIMIT %d OFFSET %d",
				$this->table,
				$status,
				$status,
				$endpoint,
				$endpoint,
				max( 1, $limit ),
				max( 0, $offset )
			),
			ARRAY_A
		);

		return array_map( [ Delivery::class, 'from_row' ], is_array( $rows ) ? $rows : [] );
	}

	/**
	 * Counts deliveries matching the filters.
	 *
	 * @param array{status?: string, endpoint_id?: string} $filters Filters.
	 */
	public function count( array $filters ): int {
		$status   = $filters['status'] ?? '';
		$endpoint = $filters['endpoint_id'] ?? '';

		return (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM %i WHERE ( %s = '' OR status = %s ) AND ( %s = '' OR endpoint_id = %s )",
				$this->table,
				$status,
				$status,
				$endpoint,
				$endpoint
			)
		);
	}

	/**
	 * Deletes delivered and failed rows created before a date.
	 *
	 * @param string $before UTC "Y-m-d H:i:s".
	 */
	public function delete_finished_before( string $before ): int {
		$sql = $this->db->prepare(
			'DELETE FROM %i WHERE status IN ( %s, %s ) AND created_at < %s',
			$this->table,
			DeliveryStatus::Delivered->value,
			DeliveryStatus::Failed->value,
			$before
		);

		return null === $sql ? 0 : (int) $this->db->query( $sql );
	}
}

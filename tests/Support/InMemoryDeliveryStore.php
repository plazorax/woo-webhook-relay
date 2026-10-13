<?php
/**
 * Array-backed delivery store for tests.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Tests\Support;

use WooWebhookRelay\Delivery\Delivery;
use WooWebhookRelay\Delivery\DeliveryStatus;
use WooWebhookRelay\Delivery\DeliveryStore;

/**
 * Mirrors WpdbDeliveryStore's behaviour without a database.
 */
final class InMemoryDeliveryStore implements DeliveryStore {

	/**
	 * Rows keyed by ID, with the same columns as the table.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $rows = [];

	private int $next_id = 1;

	public function insert( array $data ): int {
		$id                = $this->next_id++;
		$this->rows[ $id ] = [
			'id'              => $id,
			'endpoint_id'     => $data['endpoint_id'],
			'event'           => $data['event'],
			'event_id'        => $data['event_id'],
			'resource_id'     => $data['resource_id'],
			'status'          => DeliveryStatus::Pending->value,
			'attempts'        => 0,
			'response_code'   => null,
			'response_body'   => '',
			'error'           => '',
			'payload'         => $data['payload'],
			'replay_of'       => $data['replay_of'] ?? null,
			'created_at'      => '2026-01-01 00:00:00',
			'updated_at'      => '2026-01-01 00:00:00',
			'next_attempt_at' => null,
		];

		return $id;
	}

	public function find( int $id ): ?Delivery {
		return isset( $this->rows[ $id ] ) ? Delivery::from_row( $this->rows[ $id ] ) : null;
	}

	public function update( int $id, array $fields ): void {
		$this->rows[ $id ] = array_merge( $this->rows[ $id ], $fields );
	}

	public function query( array $filters, int $limit, int $offset = 0 ): array {
		$rows = array_filter(
			array_reverse( $this->rows ),
			static fn( array $row ): bool => ( empty( $filters['status'] ) || $row['status'] === $filters['status'] )
				&& ( empty( $filters['endpoint_id'] ) || $row['endpoint_id'] === $filters['endpoint_id'] )
		);

		return array_values( array_map( [ Delivery::class, 'from_row' ], array_slice( $rows, $offset, $limit ) ) );
	}

	public function count( array $filters ): int {
		return count( $this->query( $filters, PHP_INT_MAX ) );
	}

	public function delete_finished_before( string $before ): int {
		return 0;
	}
}

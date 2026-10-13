<?php
/**
 * One row of the delivery log.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Delivery;

/**
 * Immutable view of a delivery. Dates are UTC "Y-m-d H:i:s" strings.
 */
final class Delivery {

	/**
	 * Creates a delivery.
	 *
	 * @param int            $id              Row ID.
	 * @param string         $endpoint_id     Endpoint ID.
	 * @param string         $event           Event name.
	 * @param string         $event_id        Event UUID.
	 * @param int            $resource_id     Order or customer ID; 0 for test events.
	 * @param DeliveryStatus $status          Status.
	 * @param int            $attempts        Attempts made so far.
	 * @param int|null       $response_code   Last HTTP status, if any.
	 * @param string         $response_body   Last response body, truncated.
	 * @param string         $error           Last transport or configuration error.
	 * @param string         $payload         JSON request body.
	 * @param int|null       $replay_of       Original delivery ID for replays.
	 * @param string         $created_at      Creation time.
	 * @param string         $updated_at      Last update time.
	 * @param string|null    $next_attempt_at When the next retry is scheduled.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $endpoint_id,
		public readonly string $event,
		public readonly string $event_id,
		public readonly int $resource_id,
		public readonly DeliveryStatus $status,
		public readonly int $attempts,
		public readonly ?int $response_code,
		public readonly string $response_body,
		public readonly string $error,
		public readonly string $payload,
		public readonly ?int $replay_of,
		public readonly string $created_at,
		public readonly string $updated_at,
		public readonly ?string $next_attempt_at,
	) {}

	/**
	 * Builds a delivery from a database row.
	 *
	 * @param array<string, mixed> $row Row from $wpdb (all values are strings or null).
	 */
	public static function from_row( array $row ): self {
		$string   = static fn( string $key ): string => is_scalar( $row[ $key ] ?? null ) ? (string) $row[ $key ] : '';
		$nullable = static fn( string $key ): ?int => is_numeric( $row[ $key ] ?? null ) ? (int) $row[ $key ] : null;

		return new self(
			(int) $string( 'id' ),
			$string( 'endpoint_id' ),
			$string( 'event' ),
			$string( 'event_id' ),
			(int) $string( 'resource_id' ),
			DeliveryStatus::tryFrom( $string( 'status' ) ) ?? DeliveryStatus::Pending,
			(int) $string( 'attempts' ),
			$nullable( 'response_code' ),
			$string( 'response_body' ),
			$string( 'error' ),
			$string( 'payload' ),
			$nullable( 'replay_of' ),
			$string( 'created_at' ),
			$string( 'updated_at' ),
			'' === $string( 'next_attempt_at' ) ? null : $string( 'next_attempt_at' ),
		);
	}
}

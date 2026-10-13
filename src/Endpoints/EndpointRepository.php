<?php
/**
 * Reads endpoints from the options table.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Endpoints;

/**
 * Endpoints are stored as one option (an array keyed by endpoint ID) and
 * saved through the Settings API; see Admin\AdminPage.
 */
class EndpointRepository {

	public const OPTION = 'woo_webhook_relay_endpoints';

	/**
	 * All endpoints, keyed by ID.
	 *
	 * @return array<string, Endpoint>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, [] );

		if ( ! is_array( $stored ) ) {
			return [];
		}

		$endpoints = [];

		foreach ( $stored as $row ) {
			$endpoint = is_array( $row ) ? Endpoint::from_array( $row ) : null;

			if ( null !== $endpoint ) {
				$endpoints[ $endpoint->id ] = $endpoint;
			}
		}

		return $endpoints;
	}

	/**
	 * One endpoint by ID.
	 *
	 * @param string $id Endpoint ID.
	 */
	public function find( string $id ): ?Endpoint {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * Enabled endpoints subscribed to an event.
	 *
	 * @param string $event Event name.
	 * @return list<Endpoint>
	 */
	public function subscribed_to( string $event ): array {
		return array_values(
			array_filter( $this->all(), static fn( Endpoint $endpoint ): bool => $endpoint->subscribes_to( $event ) )
		);
	}
}

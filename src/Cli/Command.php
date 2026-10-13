<?php
/**
 * WP-CLI commands.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Cli;

use WP_CLI;
use WooWebhookRelay\Delivery\Delivery;
use WooWebhookRelay\Delivery\DeliveryStatus;
use WooWebhookRelay\Delivery\DeliveryStore;
use WooWebhookRelay\Delivery\DeliveryWorker;
use WooWebhookRelay\Delivery\Dispatcher;
use WooWebhookRelay\Endpoints\Endpoint;
use WooWebhookRelay\Endpoints\EndpointRepository;
use WooWebhookRelay\Events\EventFactory;
use WooWebhookRelay\Events\EventType;

use function WP_CLI\Utils\format_items;
use function WP_CLI\Utils\get_flag_value;

/**
 * Inspect endpoints and deliveries, replay deliveries and send test events.
 */
final class Command {

	/**
	 * Creates the command.
	 *
	 * @param EndpointRepository $endpoints  Endpoint settings.
	 * @param DeliveryStore      $store      Delivery log.
	 * @param Dispatcher         $dispatcher Creates and replays deliveries.
	 * @param DeliveryWorker     $worker     Sends deliveries.
	 * @param EventFactory       $events     Builds sample events.
	 */
	public function __construct(
		private readonly EndpointRepository $endpoints,
		private readonly DeliveryStore $store,
		private readonly Dispatcher $dispatcher,
		private readonly DeliveryWorker $worker,
		private readonly EventFactory $events,
	) {}

	/**
	 * Lists configured endpoints. Secrets are never shown.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp webhook-relay endpoints
	 *
	 * @param list<string>          $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function endpoints( array $args, array $assoc_args ): void {
		$rows = array_map(
			static fn( Endpoint $endpoint ): array => [
				'id'      => $endpoint->id,
				'name'    => $endpoint->name,
				'url'     => $endpoint->url,
				'events'  => implode( ',', $endpoint->events ),
				'format'  => $endpoint->format->value,
				'signed'  => $endpoint->is_signed() ? 'yes' : 'no',
				'enabled' => $endpoint->enabled ? 'yes' : 'no',
			],
			array_values( $this->endpoints->all() )
		);

		format_items( $assoc_args['format'] ?? 'table', $rows, [ 'id', 'name', 'url', 'events', 'format', 'signed', 'enabled' ] );
	}

	/**
	 * Lists recent deliveries, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Only deliveries with this status.
	 * ---
	 * options:
	 *   - pending
	 *   - retrying
	 *   - delivered
	 *   - failed
	 * ---
	 *
	 * [--endpoint=<id>]
	 * : Only deliveries to this endpoint.
	 *
	 * [--limit=<number>]
	 * : How many to show.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp webhook-relay deliveries --status=failed
	 *     wp webhook-relay deliveries --endpoint=ep_1a2b3c4d --limit=50 --format=json
	 *
	 * @param list<string>          $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function deliveries( array $args, array $assoc_args ): void {
		$filters = [];

		if ( isset( $assoc_args['status'] ) ) {
			$filters['status'] = $assoc_args['status'];
		}

		if ( isset( $assoc_args['endpoint'] ) ) {
			$filters['endpoint_id'] = $assoc_args['endpoint'];
		}

		$limit = max( 1, (int) ( $assoc_args['limit'] ?? 20 ) );
		$rows  = array_map( [ self::class, 'row' ], $this->store->query( $filters, $limit ) );

		format_items(
			$assoc_args['format'] ?? 'table',
			$rows,
			[ 'id', 'created_at', 'event', 'resource_id', 'endpoint_id', 'status', 'response_code', 'attempts', 'error' ]
		);
	}

	/**
	 * Sends an earlier delivery again, as a new delivery with the same payload.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Delivery ID.
	 *
	 * [--now]
	 * : Send in this process instead of queueing it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp webhook-relay replay 42
	 *     wp webhook-relay replay 42 --now
	 *
	 * @param list<string>               $args       Positional arguments.
	 * @param array<string, string|bool> $assoc_args Named arguments.
	 */
	public function replay( array $args, array $assoc_args ): void {
		$now    = (bool) get_flag_value( $assoc_args, 'now', false );
		$new_id = $this->dispatcher->replay( (int) ( $args[0] ?? 0 ), ! $now );

		if ( null === $new_id ) {
			WP_CLI::error( sprintf( 'Delivery #%d not found.', (int) ( $args[0] ?? 0 ) ) );
			return;
		}

		if ( ! $now ) {
			WP_CLI::success( sprintf( 'Queued delivery #%d.', $new_id ) );
			return;
		}

		$this->worker->handle( $new_id );
		$this->report( $new_id );
	}

	/**
	 * Sends a sample event to one endpoint and prints the result.
	 *
	 * The endpoint does not have to subscribe to the event. The delivery is
	 * logged and retried like any other.
	 *
	 * ## OPTIONS
	 *
	 * <endpoint>
	 * : Endpoint ID (see `wp webhook-relay endpoints`).
	 *
	 * [--event=<event>]
	 * : Event to simulate.
	 * ---
	 * default: order.created
	 * options:
	 *   - order.created
	 *   - order.status_changed
	 *   - order.refunded
	 *   - customer.created
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp webhook-relay test ep_1a2b3c4d
	 *     wp webhook-relay test ep_1a2b3c4d --event=order.refunded
	 *
	 * @param list<string>          $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function test( array $args, array $assoc_args ): void {
		$endpoint = $this->endpoints->find( $args[0] ?? '' );

		if ( null === $endpoint ) {
			WP_CLI::error( sprintf( 'Endpoint "%s" not found.', $args[0] ?? '' ) );
			return;
		}

		if ( ! $endpoint->enabled ) {
			WP_CLI::error( sprintf( 'Endpoint "%s" is disabled.', $endpoint->id ) );
			return;
		}

		$event = $assoc_args['event'] ?? EventType::ORDER_CREATED;
		$id    = $this->dispatcher->create( $endpoint, $this->events->sample( EventType::is_valid( $event ) ? $event : EventType::ORDER_CREATED ) );

		if ( $id <= 0 ) {
			WP_CLI::error( 'Could not create the delivery.' );
			return;
		}

		$this->worker->handle( $id );
		$this->report( $id );
	}

	/**
	 * Prints the outcome of a delivery that was just attempted.
	 *
	 * @param int $id Delivery ID.
	 */
	private function report( int $id ): void {
		$delivery = $this->store->find( $id );

		if ( null === $delivery ) {
			WP_CLI::error( sprintf( 'Delivery #%d disappeared.', $id ) );
			return;
		}

		$summary = sprintf(
			'Delivery #%d: %s, HTTP %s, attempt %d.',
			$delivery->id,
			$delivery->status->value,
			null === $delivery->response_code ? '-' : (string) $delivery->response_code,
			$delivery->attempts
		);

		if ( DeliveryStatus::Delivered === $delivery->status ) {
			WP_CLI::success( $summary );
			return;
		}

		WP_CLI::warning( $summary . ( '' === $delivery->error ? '' : ' ' . $delivery->error ) );

		if ( null !== $delivery->next_attempt_at ) {
			WP_CLI::log( sprintf( 'Next attempt at %s UTC.', $delivery->next_attempt_at ) );
		}
	}

	/**
	 * Table row for a delivery.
	 *
	 * @param Delivery $delivery Delivery.
	 * @return array<string, string|int|null>
	 */
	private static function row( Delivery $delivery ): array {
		return [
			'id'            => $delivery->id,
			'created_at'    => $delivery->created_at,
			'event'         => $delivery->event,
			'resource_id'   => $delivery->resource_id,
			'endpoint_id'   => $delivery->endpoint_id,
			'status'        => $delivery->status->value,
			'response_code' => $delivery->response_code,
			'attempts'      => $delivery->attempts,
			'error'         => $delivery->error,
		];
	}
}

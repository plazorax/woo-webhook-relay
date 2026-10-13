<?php
/**
 * Creates deliveries for events and queues them.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Delivery;

use WooWebhookRelay\Endpoints\Endpoint;
use WooWebhookRelay\Endpoints\EndpointRepository;
use WooWebhookRelay\Events\Event;
use WooWebhookRelay\Payload\PayloadFactory;

/**
 * One event becomes one delivery per subscribed endpoint, each with the
 * payload already rendered in that endpoint's format.
 */
class Dispatcher {

	/**
	 * Creates the dispatcher.
	 *
	 * @param EndpointRepository $endpoints Endpoint settings.
	 * @param PayloadFactory     $payloads  Payload formatters.
	 * @param DeliveryStore      $store     Delivery log.
	 * @param Queue              $queue     Background queue.
	 */
	public function __construct(
		private readonly EndpointRepository $endpoints,
		private readonly PayloadFactory $payloads,
		private readonly DeliveryStore $store,
		private readonly Queue $queue,
	) {}

	/**
	 * Creates and queues deliveries for every subscribed endpoint.
	 *
	 * @param Event $event Event.
	 * @return list<int> Delivery IDs.
	 */
	public function dispatch( Event $event ): array {
		$ids = [];

		foreach ( $this->endpoints->subscribed_to( $event->name ) as $endpoint ) {
			$id = $this->create( $endpoint, $event );

			if ( $id > 0 ) {
				$this->queue->enqueue( $id );
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Creates a delivery for one endpoint regardless of its subscriptions,
	 * without queueing it. Used for test events.
	 *
	 * @param Endpoint $endpoint Endpoint.
	 * @param Event    $event    Event.
	 * @return int Delivery ID, or 0 on failure.
	 */
	public function create( Endpoint $endpoint, Event $event ): int {
		$payload = $this->payloads->build( $endpoint->format, $event );

		/**
		 * Filters the payload before it is encoded and stored.
		 *
		 * @param array<string, mixed> $payload  Payload.
		 * @param Event                $event    Event.
		 * @param Endpoint             $endpoint Endpoint.
		 */
		$payload = apply_filters( 'woo_webhook_relay_payload', $payload, $event, $endpoint );
		$body    = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $body ) {
			return 0;
		}

		return $this->store->insert(
			[
				'endpoint_id' => $endpoint->id,
				'event'       => $event->name,
				'event_id'    => $event->id,
				'resource_id' => $event->resource_id,
				'payload'     => $body,
			]
		);
	}

	/**
	 * Copies an earlier delivery with the same payload and event ID, and queues
	 * the copy. Works for any status; the original row is left unchanged.
	 *
	 * @param int  $delivery_id Original delivery ID.
	 * @param bool $enqueue     False when the caller sends the copy itself, so a
	 *                          queue runner cannot send it a second time.
	 * @return int|null New delivery ID, or null if the original does not exist.
	 */
	public function replay( int $delivery_id, bool $enqueue = true ): ?int {
		$original = $this->store->find( $delivery_id );

		if ( null === $original ) {
			return null;
		}

		$id = $this->store->insert(
			[
				'endpoint_id' => $original->endpoint_id,
				'event'       => $original->event,
				'event_id'    => $original->event_id,
				'resource_id' => $original->resource_id,
				'payload'     => $original->payload,
				'replay_of'   => $original->id,
			]
		);

		if ( $id <= 0 ) {
			return null;
		}

		if ( $enqueue ) {
			$this->queue->enqueue( $id );
		}

		return $id;
	}
}

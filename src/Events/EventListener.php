<?php
/**
 * Listens to WooCommerce hooks and turns them into events.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Events;

use Throwable;
use WooWebhookRelay\Delivery\Dispatcher;
use WooWebhookRelay\Endpoints\EndpointRepository;

/**
 * Hook callbacks only record what happened. Snapshots are taken on `shutdown`,
 * after WooCommerce has saved line items, totals and meta for the request.
 */
class EventListener {

	/**
	 * Statuses an order has before checkout completes or an admin first saves it.
	 * Leaving one of them counts as "order created".
	 */
	public const DRAFT_STATUSES = [ 'checkout-draft', 'auto-draft', 'draft' ];

	/**
	 * Recorded events, keyed for de-duplication within one request.
	 *
	 * @var array<string, array{name: string, resource_id: int, context: array<string, mixed>}>
	 */
	private array $pending = [];

	/**
	 * Creates the listener.
	 *
	 * @param EndpointRepository $endpoints  Endpoint settings.
	 * @param EventFactory       $factory    Builds events from IDs.
	 * @param Dispatcher         $dispatcher Creates and queues deliveries.
	 */
	public function __construct(
		private readonly EndpointRepository $endpoints,
		private readonly EventFactory $factory,
		private readonly Dispatcher $dispatcher,
	) {}

	/**
	 * Registers the WooCommerce hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_new_order', [ $this, 'on_new_order' ], 10, 2 );
		add_action( 'woocommerce_order_status_changed', [ $this, 'on_status_changed' ], 10, 3 );
		add_action( 'woocommerce_order_refunded', [ $this, 'on_order_refunded' ], 10, 2 );
		// Checkout and My Account registration fire the first; WC_Customer::save() (REST API) fires the second.
		add_action( 'woocommerce_created_customer', [ $this, 'on_customer_created' ], 10, 1 );
		add_action( 'woocommerce_new_customer', [ $this, 'on_customer_created' ], 10, 1 );
		add_action( 'shutdown', [ $this, 'flush' ] );
	}

	/**
	 * Handles `woocommerce_new_order`. Draft orders are skipped here and
	 * reported when they leave the draft status instead.
	 *
	 * @param int|string $order_id Order ID.
	 * @param mixed      $order    Order object (passed by WooCommerce 8+).
	 */
	public function on_new_order( $order_id, $order = null ): void {
		$status = is_object( $order ) && method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '';

		if ( in_array( $status, self::DRAFT_STATUSES, true ) ) {
			return;
		}

		$this->record( EventType::ORDER_CREATED, (int) $order_id );
	}

	/**
	 * Handles `woocommerce_order_status_changed`. Moving into a draft status is
	 * not reported: a new order saved as `checkout-draft` (block checkout)
	 * fires this hook with "pending" as the previous status.
	 *
	 * @param int|string $order_id Order ID.
	 * @param string     $from     Previous status, without the "wc-" prefix.
	 * @param string     $to       New status, without the "wc-" prefix.
	 */
	public function on_status_changed( $order_id, $from, $to ): void {
		$from = (string) $from;
		$to   = (string) $to;

		if ( in_array( $to, self::DRAFT_STATUSES, true ) ) {
			return;
		}

		if ( in_array( $from, self::DRAFT_STATUSES, true ) ) {
			$this->record( EventType::ORDER_CREATED, (int) $order_id );
			return;
		}

		$this->record(
			EventType::ORDER_STATUS_CHANGED,
			(int) $order_id,
			[
				'previous_status' => $from,
				'new_status'      => $to,
			]
		);
	}

	/**
	 * Handles `woocommerce_order_refunded` (full and partial refunds).
	 *
	 * @param int|string $order_id  Order ID.
	 * @param int|string $refund_id Refund ID.
	 */
	public function on_order_refunded( $order_id, $refund_id ): void {
		$this->record( EventType::ORDER_REFUNDED, (int) $order_id, [ 'refund_id' => (int) $refund_id ] );
	}

	/**
	 * Handles `woocommerce_created_customer` and `woocommerce_new_customer`.
	 *
	 * @param int|string $customer_id User ID.
	 */
	public function on_customer_created( $customer_id ): void {
		$this->record( EventType::CUSTOMER_CREATED, (int) $customer_id );
	}

	/**
	 * Events recorded so far in this request.
	 *
	 * @return list<array{name: string, resource_id: int, context: array<string, mixed>}>
	 */
	public function pending(): array {
		return array_values( $this->pending );
	}

	/**
	 * Builds the recorded events and hands them to the dispatcher.
	 */
	public function flush(): void {
		$pending       = $this->pending;
		$this->pending = [];

		foreach ( $pending as $item ) {
			try {
				$event = $this->build( $item['name'], $item['resource_id'], $item['context'] );

				if ( null !== $event ) {
					$this->dispatcher->dispatch( $event );
				}
			} catch ( Throwable $e ) {
				if ( function_exists( 'wc_get_logger' ) ) {
					wc_get_logger()->error(
						sprintf( 'Could not dispatch %s for #%d: %s', $item['name'], $item['resource_id'], $e->getMessage() ),
						[ 'source' => 'woo-webhook-relay' ]
					);
				}
			}
		}
	}

	/**
	 * Records an event if at least one enabled endpoint subscribes to it.
	 *
	 * @param string               $name        Event name.
	 * @param int                  $resource_id Order or customer ID.
	 * @param array<string, mixed> $context     Extra data.
	 */
	private function record( string $name, int $resource_id, array $context = [] ): void {
		if ( $resource_id <= 0 || [] === $this->endpoints->subscribed_to( $name ) ) {
			return;
		}

		// "Created" events are reported once per resource, however many hooks fire for it.
		$key = in_array( $name, [ EventType::ORDER_CREATED, EventType::CUSTOMER_CREATED ], true )
			? $name . ':' . $resource_id
			: $name . ':' . $resource_id . ':' . md5( (string) wp_json_encode( $context ) );

		$this->pending[ $key ] ??= [
			'name'        => $name,
			'resource_id' => $resource_id,
			'context'     => $context,
		];
	}

	/**
	 * Builds an event from a recorded item.
	 *
	 * @param string               $name        Event name.
	 * @param int                  $resource_id Resource ID.
	 * @param array<string, mixed> $context     Extra data.
	 */
	private function build( string $name, int $resource_id, array $context ): ?Event {
		return match ( $name ) {
			EventType::ORDER_REFUNDED   => $this->factory->refund( $resource_id, is_int( $context['refund_id'] ?? null ) ? $context['refund_id'] : 0 ),
			EventType::CUSTOMER_CREATED => $this->factory->customer( $resource_id ),
			default                     => $this->factory->order( $name, $resource_id, $context ),
		};
	}
}

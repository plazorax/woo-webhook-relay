<?php
/**
 * Builds events from WooCommerce IDs.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Events;

use Exception;
use WC_Customer;
use WC_Order;
use WC_Order_Refund;

/**
 * Loads the resource and wraps its snapshot in an Event. Returns null when the
 * resource no longer exists (for example, an order deleted in the same request).
 */
class EventFactory {

	/**
	 * Builds an order.created or order.status_changed event.
	 *
	 * @param string               $name     Event name.
	 * @param int                  $order_id Order ID.
	 * @param array<string, mixed> $context  Extra data, e.g. previous_status and new_status.
	 */
	public function order( string $name, int $order_id, array $context = [] ): ?Event {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		return $this->make( $name, $order_id, [ 'order' => Snapshot::order( $order ) ] + $context );
	}

	/**
	 * Builds an order.refunded event.
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 */
	public function refund( int $order_id, int $refund_id ): ?Event {
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );

		if ( ! $order instanceof WC_Order || ! $refund instanceof WC_Order_Refund ) {
			return null;
		}

		return $this->make(
			EventType::ORDER_REFUNDED,
			$order_id,
			[
				'order'  => Snapshot::order( $order ),
				'refund' => Snapshot::refund( $refund ),
			]
		);
	}

	/**
	 * Builds a customer.created event.
	 *
	 * @param int $customer_id User ID.
	 */
	public function customer( int $customer_id ): ?Event {
		try {
			$customer = new WC_Customer( $customer_id );
		} catch ( Exception $e ) {
			return null;
		}

		if ( 0 === $customer->get_id() ) {
			return null;
		}

		return $this->make( EventType::CUSTOMER_CREATED, $customer_id, [ 'customer' => Snapshot::customer( $customer ) ] );
	}

	/**
	 * Builds an event with sample data, for `wp webhook-relay test`.
	 *
	 * @param string $name Event name.
	 */
	public function sample( string $name ): Event {
		return new Event( wp_generate_uuid4(), $name, 0, time(), SampleData::for_event( $name ), true );
	}

	/**
	 * Wraps data in an Event with a fresh ID and timestamp.
	 *
	 * @param string               $name        Event name.
	 * @param int                  $resource_id Resource ID.
	 * @param array<string, mixed> $data        Snapshot.
	 */
	private function make( string $name, int $resource_id, array $data ): Event {
		return new Event( wp_generate_uuid4(), $name, $resource_id, time(), $data );
	}
}

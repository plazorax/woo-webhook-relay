<?php
/**
 * Fixed sample snapshots for test deliveries.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Events;

/**
 * Same shape as Snapshot output, with obviously fake values.
 */
final class SampleData {

	/**
	 * Sample data for an event.
	 *
	 * @param string $name Event name.
	 * @return array<string, mixed>
	 */
	public static function for_event( string $name ): array {
		return match ( $name ) {
			EventType::ORDER_STATUS_CHANGED => [
				'order'           => self::order( 'processing' ),
				'previous_status' => 'pending',
				'new_status'      => 'processing',
			],
			EventType::ORDER_REFUNDED       => [
				'order'  => self::order( 'refunded' ),
				'refund' => [
					'id'         => 1002,
					'amount'     => '59.00',
					'reason'     => 'Sample refund',
					'created_at' => '2026-01-01T12:30:00+00:00',
				],
			],
			EventType::CUSTOMER_CREATED     => [ 'customer' => self::customer() ],
			default                         => [ 'order' => self::order( 'pending' ) ],
		};
	}

	/**
	 * A sample order.
	 *
	 * @param string $status Order status.
	 * @return array<string, mixed>
	 */
	public static function order( string $status ): array {
		return [
			'id'                   => 1001,
			'number'               => '1001',
			'status'               => $status,
			'currency'             => 'EUR',
			'total'                => '59.00',
			'subtotal'             => '50.00',
			'total_tax'            => '4.00',
			'shipping_total'       => '5.00',
			'discount_total'       => '0.00',
			'payment_method'       => 'bacs',
			'payment_method_title' => 'Direct bank transfer',
			'customer_id'          => 7,
			'customer_note'        => '',
			'created_at'           => '2026-01-01T12:00:00+00:00',
			'paid_at'              => null,
			'billing'              => self::address() + [
				'email' => 'jane@example.com',
				'phone' => '+10000000000',
			],
			'shipping'             => self::address(),
			'line_items'           => [
				[
					'id'           => 1,
					'name'         => 'Sample product',
					'product_id'   => 10,
					'variation_id' => 0,
					'sku'          => 'SAMPLE-1',
					'quantity'     => 2,
					'subtotal'     => '50.00',
					'total'        => '50.00',
				],
			],
			'edit_url'             => '',
		];
	}

	/**
	 * A sample customer.
	 *
	 * @return array<string, mixed>
	 */
	public static function customer(): array {
		return [
			'id'         => 7,
			'email'      => 'jane@example.com',
			'first_name' => 'Jane',
			'last_name'  => 'Doe',
			'username'   => 'jane',
			'created_at' => '2026-01-01T12:00:00+00:00',
			'billing'    => self::address() + [
				'email' => 'jane@example.com',
				'phone' => '+10000000000',
			],
			'shipping'   => self::address(),
			'edit_url'   => '',
		];
	}

	/**
	 * A sample address.
	 *
	 * @return array<string, string>
	 */
	private static function address(): array {
		return [
			'first_name' => 'Jane',
			'last_name'  => 'Doe',
			'company'    => '',
			'address_1'  => '1 Example Street',
			'address_2'  => '',
			'city'       => 'Example City',
			'state'      => '',
			'postcode'   => '00000',
			'country'    => 'US',
		];
	}
}

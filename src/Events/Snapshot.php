<?php
/**
 * Converts WooCommerce objects into plain arrays.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Events;

use WC_Customer;
use WC_DateTime;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Refund;
use WC_Product;

/**
 * Snapshots are taken once, when the delivery is created, and stored with it.
 * Retries and replays resend the stored snapshot rather than rebuilding it.
 */
final class Snapshot {

	/**
	 * Order fields sent in the full payload.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, mixed>
	 */
	public static function order( WC_Order $order ): array {
		$line_items = [];

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product      = $item->get_product();
			$line_items[] = [
				'id'           => $item->get_id(),
				'name'         => $item->get_name(),
				'product_id'   => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'sku'          => $product instanceof WC_Product ? (string) $product->get_sku() : '',
				'quantity'     => (int) $item->get_quantity(),
				'subtotal'     => self::money( $item->get_subtotal() ),
				'total'        => self::money( $item->get_total() ),
			];
		}

		return [
			'id'                   => $order->get_id(),
			'number'               => (string) $order->get_order_number(),
			'status'               => $order->get_status(),
			'currency'             => $order->get_currency(),
			'total'                => self::money( $order->get_total() ),
			'subtotal'             => self::money( $order->get_subtotal() ),
			'total_tax'            => self::money( $order->get_total_tax() ),
			'shipping_total'       => self::money( $order->get_shipping_total() ),
			'discount_total'       => self::money( $order->get_discount_total() ),
			'payment_method'       => $order->get_payment_method(),
			'payment_method_title' => $order->get_payment_method_title(),
			'customer_id'          => $order->get_customer_id(),
			'customer_note'        => $order->get_customer_note(),
			'created_at'           => self::date( $order->get_date_created() ),
			'paid_at'              => self::date( $order->get_date_paid() ),
			'billing'              => $order->get_address( 'billing' ),
			'shipping'             => $order->get_address( 'shipping' ),
			'line_items'           => $line_items,
			'edit_url'             => $order->get_edit_order_url(),
		];
	}

	/**
	 * Refund fields.
	 *
	 * @param WC_Order_Refund $refund Refund.
	 * @return array<string, mixed>
	 */
	public static function refund( WC_Order_Refund $refund ): array {
		return [
			'id'         => $refund->get_id(),
			'amount'     => self::money( $refund->get_amount() ),
			'reason'     => (string) $refund->get_reason(),
			'created_at' => self::date( $refund->get_date_created() ),
		];
	}

	/**
	 * Customer fields sent in the full payload.
	 *
	 * @param WC_Customer $customer Customer.
	 * @return array<string, mixed>
	 */
	public static function customer( WC_Customer $customer ): array {
		return [
			'id'         => $customer->get_id(),
			'email'      => $customer->get_email(),
			'first_name' => $customer->get_first_name(),
			'last_name'  => $customer->get_last_name(),
			'username'   => $customer->get_username(),
			'created_at' => self::date( $customer->get_date_created() ),
			'billing'    => $customer->get_billing(),
			'shipping'   => $customer->get_shipping(),
			'edit_url'   => admin_url( 'user-edit.php?user_id=' . $customer->get_id() ),
		];
	}

	/**
	 * Formats an amount as a decimal string with the store's precision.
	 *
	 * @param mixed $amount Amount.
	 */
	private static function money( mixed $amount ): string {
		return (string) wc_format_decimal( is_numeric( $amount ) ? $amount : 0, wc_get_price_decimals() );
	}

	/**
	 * Formats a WooCommerce date as ISO 8601 in UTC.
	 *
	 * @param WC_DateTime|null $date Date.
	 */
	private static function date( ?WC_DateTime $date ): ?string {
		return $date ? gmdate( 'c', $date->getTimestamp() ) : null;
	}
}

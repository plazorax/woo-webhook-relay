<?php
/**
 * Slack incoming-webhook message.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Payload;

use WooWebhookRelay\Events\Event;
use WooWebhookRelay\Events\EventType;

/**
 * Builds `{ text, blocks }`, which Slack incoming webhooks accept as is.
 * `text` doubles as the notification fallback.
 */
final class SlackFormatter implements Formatter {

	/**
	 * Creates the formatter.
	 *
	 * @param string $site_name Shown in the message footer.
	 */
	public function __construct( private readonly string $site_name ) {}

	/**
	 * Builds the payload.
	 *
	 * @param Event $event Event.
	 * @return array<string, mixed>
	 */
	public function format( Event $event ): array {
		$text   = $this->headline( $event );
		$footer = self::escape( $this->site_name ) . ' · `' . $event->name . '`' . ( $event->is_test ? ' · test' : '' );

		return [
			'text'   => $text,
			'blocks' => [
				[
					'type' => 'section',
					'text' => [
						'type' => 'mrkdwn',
						'text' => $text,
					],
				],
				[
					'type'     => 'context',
					'elements' => [
						[
							'type' => 'mrkdwn',
							'text' => $footer,
						],
					],
				],
			],
		];
	}

	/**
	 * Escapes the three characters Slack treats as control characters.
	 *
	 * @param string $text Untrusted text.
	 */
	public static function escape( string $text ): string {
		return str_replace( [ '&', '<', '>' ], [ '&amp;', '&lt;', '&gt;' ], $text );
	}

	/**
	 * One-line summary of the event.
	 *
	 * @param Event $event Event.
	 */
	private function headline( Event $event ): string {
		$order    = self::section( $event->data, 'order' );
		$customer = self::section( $event->data, 'customer' );
		$refund   = self::section( $event->data, 'refund' );

		switch ( $event->name ) {
			case EventType::ORDER_CREATED:
				$total  = self::money( $order['total'] ?? '', $order['currency'] ?? '' );
				$status = self::str( $order['status'] ?? '' );

				return sprintf(
					'New order %s from %s%s%s',
					self::order_link( $order ),
					self::escape( self::person( self::section( $order, 'billing' ) ) ),
					'' === $total ? '' : ': ' . self::escape( $total ),
					'' === $status ? '' : ' (' . self::escape( $status ) . ')'
				);

			case EventType::ORDER_STATUS_CHANGED:
				return sprintf(
					'Order %s status changed from *%s* to *%s*',
					self::order_link( $order ),
					self::escape( self::str( $event->data['previous_status'] ?? '' ) ),
					self::escape( self::str( $event->data['new_status'] ?? $order['status'] ?? '' ) )
				);

			case EventType::ORDER_REFUNDED:
				$reason = self::str( $refund['reason'] ?? '' );

				return sprintf(
					'Refund of %s on order %s%s',
					self::escape( self::money( $refund['amount'] ?? '', $order['currency'] ?? '' ) ),
					self::order_link( $order ),
					'' === $reason ? '' : ': ' . self::escape( $reason )
				);

			case EventType::CUSTOMER_CREATED:
				return sprintf(
					'New customer: %s (%s)',
					self::escape( self::person( $customer ) ),
					self::escape( self::str( $customer['email'] ?? '' ) )
				);

			default:
				return 'Event `' . self::escape( $event->name ) . '`';
		}
	}

	/**
	 * `#1001`, linked to the order edit screen when the URL is known.
	 *
	 * @param array<mixed> $order Order snapshot.
	 */
	private static function order_link( array $order ): string {
		$label = '#' . self::escape( self::str( $order['number'] ?? $order['id'] ?? '' ) );
		$url   = self::str( $order['edit_url'] ?? '' );

		return '' === $url ? $label : '<' . self::escape( $url ) . '|' . $label . '>';
	}

	/**
	 * "First Last", or "a guest" when no name is known.
	 *
	 * @param array<mixed> $person Address or customer snapshot.
	 */
	private static function person( array $person ): string {
		$name = trim( self::str( $person['first_name'] ?? '' ) . ' ' . self::str( $person['last_name'] ?? '' ) );

		return '' === $name ? 'a guest' : $name;
	}

	/**
	 * "59.00 EUR".
	 *
	 * @param mixed $amount   Decimal amount.
	 * @param mixed $currency Currency code.
	 */
	private static function money( mixed $amount, mixed $currency ): string {
		return trim( self::str( $amount ) . ' ' . self::str( $currency ) );
	}

	/**
	 * Returns a nested array, or an empty one.
	 *
	 * @param array<mixed> $data Parent array.
	 * @param string       $key  Key.
	 * @return array<mixed>
	 */
	private static function section( array $data, string $key ): array {
		return isset( $data[ $key ] ) && is_array( $data[ $key ] ) ? $data[ $key ] : [];
	}

	/**
	 * Casts scalars to string; anything else becomes "".
	 *
	 * @param mixed $value Value.
	 */
	private static function str( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}

<?php
/**
 * Tests for the full, slim and Slack payload formats.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WooWebhookRelay\Events\Event;
use WooWebhookRelay\Events\EventType;
use WooWebhookRelay\Events\SampleData;
use WooWebhookRelay\Payload\FullFormatter;
use WooWebhookRelay\Payload\PayloadFactory;
use WooWebhookRelay\Payload\PayloadFormat;
use WooWebhookRelay\Payload\SlackFormatter;
use WooWebhookRelay\Payload\SlimFormatter;

final class PayloadFormattersTest extends TestCase {

	private const OCCURRED_AT = 1767268800; // 2026-01-01T12:00:00Z.

	public function test_full_format_wraps_the_whole_snapshot_in_the_envelope(): void {
		$event   = self::event( EventType::ORDER_CREATED );
		$payload = ( new FullFormatter() )->format( $event );

		$this->assertSame( [ 'id', 'event', 'created_at', 'test', 'data' ], array_keys( $payload ) );
		$this->assertSame( 'evt-1', $payload['id'] );
		$this->assertSame( 'order.created', $payload['event'] );
		$this->assertSame( '2026-01-01T12:00:00+00:00', $payload['created_at'] );
		$this->assertFalse( $payload['test'] );
		$this->assertSame( $event->data, $payload['data'] );
	}

	public function test_slim_format_keeps_identifiers_and_totals_only(): void {
		$payload = ( new SlimFormatter() )->format( self::event( EventType::ORDER_CREATED ) );

		$this->assertSame(
			[
				'order' => [
					'id'             => 1001,
					'number'         => '1001',
					'status'         => 'pending',
					'currency'       => 'EUR',
					'total'          => '59.00',
					'edit_url'       => '',
					'customer_email' => 'jane@example.com',
					'item_count'     => 2,
				],
			],
			$payload['data']
		);
	}

	public function test_slim_format_keeps_status_change_and_refund_details(): void {
		$changed  = ( new SlimFormatter() )->format( self::event( EventType::ORDER_STATUS_CHANGED ) );
		$refunded = ( new SlimFormatter() )->format( self::event( EventType::ORDER_REFUNDED ) );

		$this->assertSame( 'pending', $changed['data']['previous_status'] );
		$this->assertSame( 'processing', $changed['data']['new_status'] );
		$this->assertSame( '59.00', $refunded['data']['refund']['amount'] );
		$this->assertArrayNotHasKey( 'billing', $refunded['data']['order'] );
		$this->assertArrayNotHasKey( 'line_items', $refunded['data']['order'] );
	}

	public function test_slim_format_for_customers(): void {
		$payload = ( new SlimFormatter() )->format( self::event( EventType::CUSTOMER_CREATED ) );

		$this->assertSame(
			[
				'customer' => [
					'id'         => 7,
					'email'      => 'jane@example.com',
					'first_name' => 'Jane',
					'last_name'  => 'Doe',
				],
			],
			$payload['data']
		);
	}

	public function test_slack_format_builds_text_and_blocks(): void {
		$payload = ( new SlackFormatter( 'Demo Store' ) )->format( self::event( EventType::ORDER_CREATED ) );

		$this->assertSame( 'New order #1001 from Jane Doe: 59.00 EUR (pending)', $payload['text'] );
		$this->assertSame( 'section', $payload['blocks'][0]['type'] );
		$this->assertSame( $payload['text'], $payload['blocks'][0]['text']['text'] );
		$this->assertSame( 'Demo Store · `order.created`', $payload['blocks'][1]['elements'][0]['text'] );
	}

	public function test_slack_headlines_per_event(): void {
		$slack = new SlackFormatter( 'Demo Store' );

		$this->assertSame(
			'Order #1001 status changed from *pending* to *processing*',
			$slack->format( self::event( EventType::ORDER_STATUS_CHANGED ) )['text']
		);
		$this->assertSame(
			'Refund of 59.00 EUR on order #1001: Sample refund',
			$slack->format( self::event( EventType::ORDER_REFUNDED ) )['text']
		);
		$this->assertSame(
			'New customer: Jane Doe (jane@example.com)',
			$slack->format( self::event( EventType::CUSTOMER_CREATED ) )['text']
		);
	}

	public function test_slack_links_the_order_and_escapes_user_input(): void {
		$order                          = SampleData::order( 'processing' );
		$order['edit_url']              = 'https://shop.example/wp-admin/admin.php?page=wc-orders&action=edit&id=1001';
		$order['billing']['first_name'] = '<!channel> Bob & Co';
		$order['billing']['last_name']  = '';

		$event   = new Event( 'evt-1', EventType::ORDER_CREATED, 1001, self::OCCURRED_AT, [ 'order' => $order ], true );
		$payload = ( new SlackFormatter( 'R&D <Shop>' ) )->format( $event );

		$this->assertSame(
			'New order <https://shop.example/wp-admin/admin.php?page=wc-orders&amp;action=edit&amp;id=1001|#1001> from &lt;!channel&gt; Bob &amp; Co: 59.00 EUR (processing)',
			$payload['text']
		);
		$this->assertSame( 'R&amp;D &lt;Shop&gt; · `order.created` · test', $payload['blocks'][1]['elements'][0]['text'] );
	}

	public function test_slack_tolerates_missing_fields(): void {
		$event   = new Event( 'evt-1', EventType::ORDER_CREATED, 5, self::OCCURRED_AT, [ 'order' => [ 'id' => 5 ] ] );
		$payload = ( new SlackFormatter( 'Shop' ) )->format( $event );

		$this->assertSame( 'New order #5 from a guest', $payload['text'] );
	}

	public function test_factory_uses_the_requested_format(): void {
		$factory = PayloadFactory::create( 'Shop' );
		$event   = self::event( EventType::ORDER_CREATED );

		$this->assertArrayHasKey( 'line_items', $factory->build( PayloadFormat::Full, $event )['data']['order'] );
		$this->assertArrayHasKey( 'item_count', $factory->build( PayloadFormat::Slim, $event )['data']['order'] );
		$this->assertArrayHasKey( 'blocks', $factory->build( PayloadFormat::Slack, $event ) );
	}

	/**
	 * An event with sample data.
	 *
	 * @param string $name Event name.
	 */
	private static function event( string $name ): Event {
		return new Event( 'evt-1', $name, 1001, self::OCCURRED_AT, SampleData::for_event( $name ) );
	}
}

<?php
/**
 * Tests for creating, queueing and replaying deliveries.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Tests\Unit;

use Brain\Monkey\Filters;
use WooWebhookRelay\Delivery\Dispatcher;
use WooWebhookRelay\Endpoints\Endpoint;
use WooWebhookRelay\Endpoints\EndpointRepository;
use WooWebhookRelay\Events\Event;
use WooWebhookRelay\Events\EventType;
use WooWebhookRelay\Events\SampleData;
use WooWebhookRelay\Payload\PayloadFactory;
use WooWebhookRelay\Payload\PayloadFormat;
use WooWebhookRelay\Tests\Support\InMemoryDeliveryStore;
use WooWebhookRelay\Tests\Support\RecordingQueue;
use WooWebhookRelay\Tests\TestCase;

final class DispatcherTest extends TestCase {

	private InMemoryDeliveryStore $store;
	private RecordingQueue $queue;
	private Dispatcher $dispatcher;

	protected function setUp(): void {
		parent::setUp();

		$endpoints = [
			new Endpoint( 'crm', 'CRM', 'https://crm.example.com', 'crm-secret-0123456789', [ EventType::ORDER_CREATED ], PayloadFormat::Slim, true ),
			new Endpoint( 'slack', 'Slack', 'https://hooks.slack.com/services/X', '', [ EventType::ORDER_CREATED ], PayloadFormat::Slack, true ),
			new Endpoint( 'off', 'Off', 'https://off.example.com', 'off-secret-0123456789', [ EventType::ORDER_CREATED ], PayloadFormat::Full, false ),
			new Endpoint( 'refunds', 'Refunds', 'https://r.example.com', 'r-secret-0123456789ab', [ EventType::ORDER_REFUNDED ], PayloadFormat::Full, true ),
		];

		foreach ( $endpoints as $endpoint ) {
			$this->options[ EndpointRepository::OPTION ][ $endpoint->id ] = $endpoint->to_array();
		}

		$this->store      = new InMemoryDeliveryStore();
		$this->queue      = new RecordingQueue();
		$this->dispatcher = new Dispatcher( new EndpointRepository(), PayloadFactory::create( 'Shop' ), $this->store, $this->queue );
	}

	public function test_dispatch_creates_one_delivery_per_subscribed_endpoint_in_its_format(): void {
		$ids = $this->dispatcher->dispatch( self::event() );

		$this->assertSame( [ 1, 2 ], $ids );
		$this->assertSame( [ 1, 2 ], $this->queue->enqueued );

		$crm   = $this->store->rows[1];
		$slack = $this->store->rows[2];

		$this->assertSame( 'crm', $crm['endpoint_id'] );
		$this->assertSame( 'evt-1', $crm['event_id'] );
		$this->assertSame( 1001, $crm['resource_id'] );
		$this->assertSame( 2, json_decode( $crm['payload'], true )['data']['order']['item_count'] );

		$this->assertSame( 'slack', $slack['endpoint_id'] );
		$this->assertArrayHasKey( 'blocks', json_decode( $slack['payload'], true ) );
	}

	public function test_events_without_subscribers_create_nothing(): void {
		$event = new Event( 'evt-2', EventType::CUSTOMER_CREATED, 7, 0, SampleData::for_event( EventType::CUSTOMER_CREATED ) );

		$this->assertSame( [], $this->dispatcher->dispatch( $event ) );
		$this->assertSame( [], $this->store->rows );
	}

	public function test_payload_filter_can_change_the_body(): void {
		Filters\expectApplied( 'woo_webhook_relay_payload' )
			->twice()
			->andReturnUsing( static fn( array $payload ): array => $payload + [ 'source' => 'filter' ] );

		$this->dispatcher->dispatch( self::event() );

		$this->assertSame( 'filter', json_decode( $this->store->rows[1]['payload'], true )['source'] );
	}

	public function test_create_ignores_subscriptions_and_does_not_queue(): void {
		$refunds = ( new EndpointRepository() )->find( 'refunds' );
		$this->assertNotNull( $refunds );

		$id = $this->dispatcher->create( $refunds, self::event() );

		$this->assertSame( 'refunds', $this->store->rows[ $id ]['endpoint_id'] );
		$this->assertSame( [], $this->queue->enqueued );
	}

	public function test_replay_copies_payload_and_event_id_and_links_the_original(): void {
		$this->dispatcher->dispatch( self::event() );
		$this->store->update(
			1,
			[
				'status'   => 'failed',
				'attempts' => 6,
			]
		);

		$new_id = $this->dispatcher->replay( 1 );

		$this->assertSame( 3, $new_id );
		$this->assertSame( $this->store->rows[1]['payload'], $this->store->rows[3]['payload'] );
		$this->assertSame( 'evt-1', $this->store->rows[3]['event_id'] );
		$this->assertSame( 1, $this->store->rows[3]['replay_of'] );
		$this->assertSame( 'pending', $this->store->rows[3]['status'] );
		$this->assertSame( 0, $this->store->rows[3]['attempts'] );
		$this->assertSame( 'failed', $this->store->rows[1]['status'] );
		$this->assertSame( [ 1, 2, 3 ], $this->queue->enqueued );
	}

	public function test_replay_without_enqueue_leaves_sending_to_the_caller(): void {
		$this->dispatcher->dispatch( self::event() );

		$new_id = $this->dispatcher->replay( 1, false );

		$this->assertSame( 3, $new_id );
		$this->assertSame( 'pending', $this->store->rows[3]['status'] );
		$this->assertSame( [ 1, 2 ], $this->queue->enqueued );
	}

	public function test_replay_of_a_missing_delivery_returns_null(): void {
		$this->assertNull( $this->dispatcher->replay( 404 ) );
		$this->assertSame( [], $this->queue->enqueued );
	}

	/**
	 * An order.created event.
	 */
	private static function event(): Event {
		return new Event( 'evt-1', EventType::ORDER_CREATED, 1001, 1767268800, SampleData::for_event( EventType::ORDER_CREATED ) );
	}
}

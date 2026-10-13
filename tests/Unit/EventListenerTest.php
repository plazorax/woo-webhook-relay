<?php
/**
 * Tests for mapping WooCommerce hooks to events.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Tests\Unit;

use Mockery;
use WooWebhookRelay\Delivery\Dispatcher;
use WooWebhookRelay\Endpoints\Endpoint;
use WooWebhookRelay\Endpoints\EndpointRepository;
use WooWebhookRelay\Events\Event;
use WooWebhookRelay\Events\EventFactory;
use WooWebhookRelay\Events\EventListener;
use WooWebhookRelay\Events\EventType;
use WooWebhookRelay\Payload\PayloadFactory;
use WooWebhookRelay\Payload\PayloadFormat;
use WooWebhookRelay\Tests\Support\InMemoryDeliveryStore;
use WooWebhookRelay\Tests\Support\RecordingQueue;
use WooWebhookRelay\Tests\TestCase;

final class EventListenerTest extends TestCase {

	/**
	 * Mocked event factory.
	 *
	 * @var EventFactory&\Mockery\MockInterface
	 */
	private $factory;

	private InMemoryDeliveryStore $store;
	private EventListener $listener;

	protected function setUp(): void {
		parent::setUp();

		$endpoint                                    = new Endpoint( 'all', 'All', 'https://example.com', 'all-secret-0123456789', EventType::all(), PayloadFormat::Full, true );
		$this->options[ EndpointRepository::OPTION ] = [ 'all' => $endpoint->to_array() ];

		$this->factory  = Mockery::mock( EventFactory::class );
		$this->store    = new InMemoryDeliveryStore();
		$endpoints      = new EndpointRepository();
		$dispatcher     = new Dispatcher( $endpoints, PayloadFactory::create( 'Shop' ), $this->store, new RecordingQueue() );
		$this->listener = new EventListener( $endpoints, $this->factory, $dispatcher );
	}

	public function test_new_order_is_recorded_unless_it_is_a_draft(): void {
		$this->listener->on_new_order( 10, self::order( 'pending' ) );
		$this->listener->on_new_order( 11, self::order( 'checkout-draft' ) );
		$this->listener->on_new_order( 12, self::order( 'auto-draft' ) );
		$this->listener->on_new_order( '13' );

		$this->assertSame( [ 10, 13 ], array_column( $this->listener->pending(), 'resource_id' ) );
	}

	public function test_leaving_a_draft_status_counts_as_order_created(): void {
		$this->listener->on_status_changed( 20, 'checkout-draft', 'processing' );
		$this->listener->on_status_changed( 21, 'auto-draft', 'pending' );
		$this->listener->on_status_changed( 22, 'checkout-draft', 'auto-draft' );

		$pending = $this->listener->pending();

		$this->assertSame( [ EventType::ORDER_CREATED, EventType::ORDER_CREATED ], array_column( $pending, 'name' ) );
		$this->assertSame( [ 20, 21 ], array_column( $pending, 'resource_id' ) );
	}

	public function test_moving_into_a_draft_status_is_not_reported(): void {
		$this->listener->on_status_changed( 25, 'pending', 'checkout-draft' );
		$this->listener->on_status_changed( 26, 'processing', 'draft' );

		$this->assertSame( [], $this->listener->pending() );
	}

	public function test_status_changes_carry_both_statuses(): void {
		$this->listener->on_status_changed( 30, 'pending', 'processing' );
		$this->listener->on_status_changed( 30, 'processing', 'completed' );

		$pending = $this->listener->pending();

		$this->assertCount( 2, $pending );
		$this->assertSame(
			[
				'previous_status' => 'processing',
				'new_status'      => 'completed',
			],
			$pending[1]['context']
		);
	}

	public function test_created_events_are_deduplicated_per_request(): void {
		$this->listener->on_new_order( 40, self::order( 'pending' ) );
		$this->listener->on_status_changed( 40, 'auto-draft', 'pending' );
		$this->listener->on_customer_created( 7 );
		$this->listener->on_customer_created( '7' );

		$this->assertSame(
			[ EventType::ORDER_CREATED, EventType::CUSTOMER_CREATED ],
			array_column( $this->listener->pending(), 'name' )
		);
	}

	public function test_nothing_is_recorded_without_subscribers(): void {
		$this->options[ EndpointRepository::OPTION ] = [];

		$this->listener->on_new_order( 50, self::order( 'pending' ) );
		$this->listener->on_order_refunded( 50, 51 );

		$this->assertSame( [], $this->listener->pending() );
	}

	public function test_flush_builds_events_and_creates_deliveries(): void {
		$this->listener->on_new_order( 60, self::order( 'pending' ) );
		$this->listener->on_order_refunded( 60, 61 );
		$this->listener->on_customer_created( 0 );

		$this->factory->shouldReceive( 'order' )
			->once()
			->with( EventType::ORDER_CREATED, 60, [] )
			->andReturn( new Event( 'evt-a', EventType::ORDER_CREATED, 60, 0, [ 'order' => [ 'id' => 60 ] ] ) );
		$this->factory->shouldReceive( 'refund' )
			->once()
			->with( 60, 61 )
			->andReturnNull(); // E.g. the refund was deleted before shutdown.

		$this->listener->flush();

		$this->assertCount( 1, $this->store->rows );
		$this->assertSame( 'evt-a', $this->store->rows[1]['event_id'] );
		$this->assertSame( [], $this->listener->pending() );
	}

	/**
	 * A minimal order double.
	 *
	 * @param string $status Order status.
	 */
	private static function order( string $status ): object {
		return new class( $status ) {
			public function __construct( private string $status ) {}

			public function get_status(): string {
				return $this->status;
			}
		};
	}
}

<?php
/**
 * Tests for endpoint settings and event filtering.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Tests\Unit;

use WooWebhookRelay\Endpoints\Endpoint;
use WooWebhookRelay\Endpoints\EndpointRepository;
use WooWebhookRelay\Events\EventType;
use WooWebhookRelay\Payload\PayloadFormat;
use WooWebhookRelay\Tests\TestCase;

final class EndpointTest extends TestCase {

	public function test_from_array_drops_unknown_events_and_defaults_the_format(): void {
		$endpoint = Endpoint::from_array(
			[
				'id'      => 'ep_1',
				'url'     => 'https://example.com/hook',
				'events'  => [ 'order.created', 'order.deleted', 42 ],
				'format'  => 'xml',
				'enabled' => '1',
			]
		);

		$this->assertNotNull( $endpoint );
		$this->assertSame( [ 'order.created' ], $endpoint->events );
		$this->assertSame( PayloadFormat::Full, $endpoint->format );
		$this->assertSame( 'ep_1', $endpoint->name );
		$this->assertTrue( $endpoint->enabled );
		$this->assertFalse( $endpoint->is_signed() );
	}

	public function test_from_array_rejects_rows_without_id_or_url(): void {
		$this->assertNull( Endpoint::from_array( [ 'url' => 'https://example.com' ] ) );
		$this->assertNull( Endpoint::from_array( [ 'id' => 'ep_1' ] ) );
	}

	public function test_to_array_round_trips(): void {
		$endpoint = self::endpoint( 'ep_1', [ EventType::ORDER_REFUNDED ], true, PayloadFormat::Slack );

		$this->assertEquals( $endpoint, Endpoint::from_array( $endpoint->to_array() ) );
	}

	public function test_disabled_endpoints_subscribe_to_nothing(): void {
		$this->assertTrue( self::endpoint( 'a', [ EventType::ORDER_CREATED ], true )->subscribes_to( EventType::ORDER_CREATED ) );
		$this->assertFalse( self::endpoint( 'a', [ EventType::ORDER_CREATED ], true )->subscribes_to( EventType::ORDER_REFUNDED ) );
		$this->assertFalse( self::endpoint( 'a', [ EventType::ORDER_CREATED ], false )->subscribes_to( EventType::ORDER_CREATED ) );
	}

	public function test_repository_returns_enabled_subscribers_only(): void {
		$this->options[ EndpointRepository::OPTION ] = [
			'crm'     => self::endpoint( 'crm', [ EventType::ORDER_CREATED, EventType::CUSTOMER_CREATED ], true )->to_array(),
			'slack'   => self::endpoint( 'slack', [ EventType::ORDER_CREATED ], true, PayloadFormat::Slack )->to_array(),
			'paused'  => self::endpoint( 'paused', [ EventType::ORDER_CREATED ], false )->to_array(),
			'refunds' => self::endpoint( 'refunds', [ EventType::ORDER_REFUNDED ], true )->to_array(),
			'broken'  => 'not an array',
			'no-url'  => [ 'id' => 'no-url' ],
		];

		$repository = new EndpointRepository();
		$ids        = static fn( array $endpoints ): array => array_map( static fn( Endpoint $e ): string => $e->id, $endpoints );

		$this->assertSame( [ 'crm', 'slack', 'paused', 'refunds' ], array_keys( $repository->all() ) );
		$this->assertSame( [ 'crm', 'slack' ], $ids( $repository->subscribed_to( EventType::ORDER_CREATED ) ) );
		$this->assertSame( [ 'crm' ], $ids( $repository->subscribed_to( EventType::CUSTOMER_CREATED ) ) );
		$this->assertSame( [], $repository->subscribed_to( EventType::ORDER_STATUS_CHANGED ) );
		$this->assertSame( 'slack', $repository->find( 'slack' )?->id );
		$this->assertNull( $repository->find( 'missing' ) );
	}

	public function test_repository_tolerates_a_corrupt_option(): void {
		$this->options[ EndpointRepository::OPTION ] = 'garbage';

		$this->assertSame( [], ( new EndpointRepository() )->all() );
	}

	/**
	 * Builds an endpoint.
	 *
	 * @param string        $id      ID.
	 * @param list<string>  $events  Events.
	 * @param bool          $enabled Enabled.
	 * @param PayloadFormat $format  Format.
	 */
	private static function endpoint( string $id, array $events, bool $enabled, PayloadFormat $format = PayloadFormat::Full ): Endpoint {
		return new Endpoint( $id, ucfirst( $id ), 'https://example.com/' . $id, 'secret-' . $id . '-0123456789', $events, $format, $enabled );
	}
}

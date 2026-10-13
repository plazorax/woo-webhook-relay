<?php
/**
 * Tests for sending deliveries and scheduling retries.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Tests\Unit;

use WooWebhookRelay\Delivery\DeliveryStatus;
use WooWebhookRelay\Delivery\DeliveryWorker;
use WooWebhookRelay\Delivery\RetryPolicy;
use WooWebhookRelay\Delivery\Signer;
use WooWebhookRelay\Delivery\TransportResult;
use WooWebhookRelay\Endpoints\Endpoint;
use WooWebhookRelay\Endpoints\EndpointRepository;
use WooWebhookRelay\Events\EventType;
use WooWebhookRelay\Payload\PayloadFormat;
use WooWebhookRelay\Tests\Support\FakeTransport;
use WooWebhookRelay\Tests\Support\InMemoryDeliveryStore;
use WooWebhookRelay\Tests\Support\RecordingQueue;
use WooWebhookRelay\Tests\TestCase;

final class DeliveryWorkerTest extends TestCase {

	private const NOW    = 1767225600;
	private const SECRET = 'worker-secret-0123456789';
	private const BODY   = '{"id":"evt-1","event":"order.created"}';

	private InMemoryDeliveryStore $store;
	private RecordingQueue $queue;

	protected function setUp(): void {
		parent::setUp();

		$this->store = new InMemoryDeliveryStore();
		$this->queue = new RecordingQueue();
		$this->save_endpoint( self::SECRET, true );
	}

	public function test_successful_delivery_is_signed_and_marked_delivered(): void {
		$id        = $this->delivery();
		$transport = new FakeTransport( new TransportResult( 202, 'accepted' ) );

		$this->worker( $transport )->handle( $id );

		$request = $transport->requests[0];
		$row     = $this->store->rows[ $id ];

		$this->assertSame( 'https://receiver.example.com/hook', $request['url'] );
		$this->assertSame( self::BODY, $request['body'] );
		$this->assertSame( 7, $request['timeout'] );
		$this->assertSame( 'order.created', $request['headers']['X-Relay-Event'] );
		$this->assertSame( 'evt-1', $request['headers']['X-Relay-Event-Id'] );
		$this->assertSame( (string) $id, $request['headers']['X-Relay-Delivery'] );
		$this->assertSame( '1', $request['headers']['X-Relay-Attempt'] );
		$this->assertSame( (string) self::NOW, $request['headers'][ Signer::TIMESTAMP_HEADER ] );
		$this->assertTrue( Signer::verify( self::SECRET, $request['headers'][ Signer::SIGNATURE_HEADER ], self::NOW, self::BODY, self::NOW ) );

		$this->assertSame( DeliveryStatus::Delivered->value, $row['status'] );
		$this->assertSame( 1, $row['attempts'] );
		$this->assertSame( 202, $row['response_code'] );
		$this->assertSame( 'accepted', $row['response_body'] );
		$this->assertSame( [], $this->queue->scheduled );
	}

	public function test_retryable_failure_schedules_the_next_attempt_with_backoff(): void {
		$id = $this->delivery();

		$this->worker( new FakeTransport( new TransportResult( 503, 'down' ) ) )->handle( $id );
		$this->assertSame( DeliveryStatus::Retrying->value, $this->store->rows[ $id ]['status'] );
		$this->assertSame( [ [ $id, self::NOW + 60 ] ], $this->queue->scheduled );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW + 60 ), $this->store->rows[ $id ]['next_attempt_at'] );

		$this->worker( new FakeTransport( TransportResult::error( 'cURL error 28: timed out' ) ) )->handle( $id );
		$this->assertSame( [ $id, self::NOW + 180 ], $this->queue->scheduled[1] );
		$this->assertSame( 'cURL error 28: timed out', $this->store->rows[ $id ]['error'] );
		$this->assertNull( $this->store->rows[ $id ]['response_code'] );

		$transport = new FakeTransport( new TransportResult( 200 ) );
		$this->worker( $transport )->handle( $id );
		$this->assertSame( '3', $transport->requests[0]['headers']['X-Relay-Attempt'] );
		$this->assertSame( DeliveryStatus::Delivered->value, $this->store->rows[ $id ]['status'] );
		$this->assertNull( $this->store->rows[ $id ]['next_attempt_at'] );
		$this->assertSame( '', $this->store->rows[ $id ]['error'] );
	}

	public function test_gives_up_after_max_attempts(): void {
		$id = $this->delivery();

		for ( $i = 0; $i < 3; $i++ ) {
			$this->worker( new FakeTransport( new TransportResult( 500 ) ) )->handle( $id );
		}

		$this->assertSame( DeliveryStatus::Failed->value, $this->store->rows[ $id ]['status'] );
		$this->assertSame( 3, $this->store->rows[ $id ]['attempts'] );
		$this->assertCount( 2, $this->queue->scheduled );
	}

	public function test_non_retryable_response_fails_immediately(): void {
		$id = $this->delivery();

		$this->worker( new FakeTransport( new TransportResult( 401, 'bad signature' ) ) )->handle( $id );

		$this->assertSame( DeliveryStatus::Failed->value, $this->store->rows[ $id ]['status'] );
		$this->assertSame( [], $this->queue->scheduled );
	}

	public function test_final_deliveries_are_not_sent_again(): void {
		$id = $this->delivery();
		$this->store->update( $id, [ 'status' => DeliveryStatus::Delivered->value ] );
		$transport = new FakeTransport();

		$this->worker( $transport )->handle( $id );
		$this->worker( $transport )->handle( 999 );

		$this->assertSame( [], $transport->requests );
	}

	public function test_deleted_or_disabled_endpoint_fails_without_a_request(): void {
		$transport = new FakeTransport();

		$this->save_endpoint( self::SECRET, false );
		$disabled = $this->delivery();
		$this->worker( $transport )->handle( $disabled );

		$this->options[ EndpointRepository::OPTION ] = [];
		$deleted                                     = $this->delivery();
		$this->worker( $transport )->handle( $deleted );

		$this->assertSame( [], $transport->requests );
		$this->assertSame( 'Endpoint is disabled.', $this->store->rows[ $disabled ]['error'] );
		$this->assertSame( 'Endpoint was deleted.', $this->store->rows[ $deleted ]['error'] );
		$this->assertSame( DeliveryStatus::Failed->value, $this->store->rows[ $deleted ]['status'] );
	}

	public function test_unsigned_endpoint_sends_no_signature_header(): void {
		$this->save_endpoint( '', true );
		$transport = new FakeTransport();

		$this->worker( $transport )->handle( $this->delivery() );

		$this->assertArrayNotHasKey( Signer::SIGNATURE_HEADER, $transport->requests[0]['headers'] );
	}

	public function test_long_response_bodies_are_truncated_on_a_character_boundary(): void {
		$short = str_repeat( 'a', DeliveryWorker::MAX_RESPONSE_BYTES );
		$this->assertSame( $short, DeliveryWorker::truncate( $short ) );

		// 1999 ASCII bytes followed by a 2-byte character that straddles the limit.
		$body      = str_repeat( 'a', DeliveryWorker::MAX_RESPONSE_BYTES - 1 ) . 'étail';
		$truncated = DeliveryWorker::truncate( $body );

		$this->assertSame( str_repeat( 'a', DeliveryWorker::MAX_RESPONSE_BYTES - 1 ) . '…', $truncated );
		$this->assertTrue( mb_check_encoding( $truncated, 'UTF-8' ) );

		// A character that ends exactly at the limit is kept.
		$body = str_repeat( 'a', DeliveryWorker::MAX_RESPONSE_BYTES - 2 ) . 'étail';
		$this->assertStringEndsWith( 'é…', DeliveryWorker::truncate( $body ) );
	}

	/**
	 * Stores the receiver endpoint.
	 *
	 * @param string $secret  Secret.
	 * @param bool   $enabled Enabled.
	 */
	private function save_endpoint( string $secret, bool $enabled ): void {
		$endpoint = new Endpoint( 'ep_1', 'Receiver', 'https://receiver.example.com/hook', $secret, [ EventType::ORDER_CREATED ], PayloadFormat::Full, $enabled );

		$this->options[ EndpointRepository::OPTION ] = [ 'ep_1' => $endpoint->to_array() ];
	}

	/**
	 * Inserts a pending delivery to ep_1.
	 */
	private function delivery(): int {
		return $this->store->insert(
			[
				'endpoint_id' => 'ep_1',
				'event'       => EventType::ORDER_CREATED,
				'event_id'    => 'evt-1',
				'resource_id' => 1001,
				'payload'     => self::BODY,
			]
		);
	}

	/**
	 * Worker with a 3-attempt, jitter-free policy and a fixed clock.
	 *
	 * @param FakeTransport $transport Transport.
	 */
	private function worker( FakeTransport $transport ): DeliveryWorker {
		return new DeliveryWorker(
			$this->store,
			new EndpointRepository(),
			$transport,
			new RetryPolicy( max_attempts: 3, base_delay: 60, multiplier: 3, jitter: 0.0 ),
			$this->queue,
			7,
			'WooWebhookRelay/test',
			static fn(): int => self::NOW
		);
	}
}

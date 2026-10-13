<?php
/**
 * Sends one delivery attempt and records the result.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Delivery;

use Closure;
use WooWebhookRelay\Endpoints\EndpointRepository;

/**
 * Runs as the Action Scheduler callback. Each run is one attempt; on a
 * retryable failure it schedules the next run with exponential backoff.
 */
class DeliveryWorker {

	/**
	 * Bytes of the response body kept in the log.
	 */
	public const MAX_RESPONSE_BYTES = 2000;

	/**
	 * Returns the current Unix time.
	 *
	 * @var Closure(): int
	 */
	private Closure $clock;

	/**
	 * Creates the worker.
	 *
	 * @param DeliveryStore            $store     Delivery log.
	 * @param EndpointRepository       $endpoints Endpoint settings.
	 * @param Transport                $transport HTTP client.
	 * @param RetryPolicy              $policy    Backoff policy.
	 * @param Queue                    $queue     Queue for retries.
	 * @param int                      $timeout   HTTP timeout in seconds.
	 * @param string                   $user_agent User-Agent header.
	 * @param (Closure(): int)|null    $clock     Time source, for tests.
	 */
	public function __construct(
		private readonly DeliveryStore $store,
		private readonly EndpointRepository $endpoints,
		private readonly Transport $transport,
		private readonly RetryPolicy $policy,
		private readonly Queue $queue,
		private readonly int $timeout = 10,
		private readonly string $user_agent = 'WooWebhookRelay',
		?Closure $clock = null,
	) {
		$this->clock = $clock ?? static fn(): int => time();
	}

	/**
	 * Makes one attempt. Deliveries that are already final are ignored, so a
	 * duplicate queue entry cannot send a delivered payload twice.
	 *
	 * @param int|string $delivery_id Delivery ID (Action Scheduler passes stored args as given).
	 */
	public function handle( $delivery_id ): void {
		$delivery = $this->store->find( (int) $delivery_id );

		if ( null === $delivery || $delivery->status->is_final() ) {
			return;
		}

		$endpoint = $this->endpoints->find( $delivery->endpoint_id );

		if ( null === $endpoint || ! $endpoint->enabled ) {
			$this->store->update(
				$delivery->id,
				[
					'status'          => DeliveryStatus::Failed->value,
					'error'           => null === $endpoint ? 'Endpoint was deleted.' : 'Endpoint is disabled.',
					'next_attempt_at' => null,
				]
			);
			return;
		}

		$attempt = $delivery->attempts + 1;
		$now     = ( $this->clock )();
		$headers = [
			'Content-Type'           => 'application/json; charset=utf-8',
			'User-Agent'             => $this->user_agent,
			'X-Relay-Event'          => $delivery->event,
			'X-Relay-Event-Id'       => $delivery->event_id,
			'X-Relay-Delivery'       => (string) $delivery->id,
			'X-Relay-Attempt'        => (string) $attempt,
			Signer::TIMESTAMP_HEADER => (string) $now,
		];

		if ( $endpoint->is_signed() ) {
			$headers[ Signer::SIGNATURE_HEADER ] = Signer::sign( $endpoint->secret, $now, $delivery->payload );
		}

		$result = $this->transport->post( $endpoint->url, $delivery->payload, $headers, $this->timeout );
		$fields = [
			'attempts'        => $attempt,
			'response_code'   => $result->status_code,
			'response_body'   => self::truncate( $result->body ),
			'error'           => (string) $result->error,
			'next_attempt_at' => null,
		];

		if ( $result->is_success() ) {
			$fields['status'] = DeliveryStatus::Delivered->value;
		} elseif ( $this->policy->should_retry( $attempt, $result ) ) {
			$next                      = $now + $this->policy->delay_after( $attempt );
			$fields['status']          = DeliveryStatus::Retrying->value;
			$fields['next_attempt_at'] = gmdate( 'Y-m-d H:i:s', $next );
			$this->queue->schedule( $delivery->id, $next );
		} else {
			$fields['status'] = DeliveryStatus::Failed->value;
		}

		$this->store->update( $delivery->id, $fields );
	}

	/**
	 * Cuts a response body to MAX_RESPONSE_BYTES without splitting a UTF-8 character.
	 *
	 * @param string $body Response body.
	 */
	public static function truncate( string $body ): string {
		if ( strlen( $body ) <= self::MAX_RESPONSE_BYTES ) {
			return $body;
		}

		$cut = substr( $body, 0, self::MAX_RESPONSE_BYTES );

		// Walk back over continuation bytes to the last lead byte and drop the
		// character if the cut left it incomplete.
		for ( $back = 1; $back <= 4; $back++ ) {
			$byte = ord( $cut[ self::MAX_RESPONSE_BYTES - $back ] );

			if ( 0x80 === ( $byte & 0xC0 ) ) {
				continue;
			}

			$length = match ( true ) {
				$byte >= 0xF0 => 4,
				$byte >= 0xE0 => 3,
				$byte >= 0xC0 => 2,
				default       => 1,
			};

			if ( $length > $back ) {
				$cut = substr( $cut, 0, -$back );
			}
			break;
		}

		return $cut . '…';
	}
}

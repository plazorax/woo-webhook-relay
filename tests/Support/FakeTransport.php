<?php
/**
 * Transport fake with canned responses.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Tests\Support;

use WooWebhookRelay\Delivery\Transport;
use WooWebhookRelay\Delivery\TransportResult;

/**
 * Returns queued results and records each request.
 */
final class FakeTransport implements Transport {

	/**
	 * Requests made, oldest first.
	 *
	 * @var list<array{url: string, body: string, headers: array<string, string>, timeout: int}>
	 */
	public array $requests = [];

	/**
	 * Results to return, oldest first.
	 *
	 * @var list<TransportResult>
	 */
	private array $results;

	public function __construct( TransportResult ...$results ) {
		$this->results = array_values( $results );
	}

	public function post( string $url, string $body, array $headers, int $timeout ): TransportResult {
		$this->requests[] = compact( 'url', 'body', 'headers', 'timeout' );

		return array_shift( $this->results ) ?? new TransportResult( 200, 'ok' );
	}
}

<?php
/**
 * Sends one HTTP request.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Delivery;

/**
 * Implemented by WpHttpTransport; tests use a fake.
 */
interface Transport {

	/**
	 * POSTs a body.
	 *
	 * @param string                $url     Destination URL.
	 * @param string                $body    Request body.
	 * @param array<string, string> $headers Request headers.
	 * @param int                   $timeout Timeout in seconds.
	 */
	public function post( string $url, string $body, array $headers, int $timeout ): TransportResult;
}

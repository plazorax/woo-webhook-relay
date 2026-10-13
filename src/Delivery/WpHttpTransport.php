<?php
/**
 * Transport using the WordPress HTTP API.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Delivery;

/**
 * Uses wp_safe_remote_post(), which refuses private and loopback addresses.
 * Allow internal hosts with the core `http_request_host_is_external` filter;
 * change request arguments with the core `http_request_args` filter.
 */
final class WpHttpTransport implements Transport {

	/**
	 * POSTs a body.
	 *
	 * @param string                $url     Destination URL.
	 * @param string                $body    Request body.
	 * @param array<string, string> $headers Request headers.
	 * @param int                   $timeout Timeout in seconds.
	 */
	public function post( string $url, string $body, array $headers, int $timeout ): TransportResult {
		$response = wp_safe_remote_post(
			$url,
			[
				'method'      => 'POST',
				'body'        => $body,
				'headers'     => $headers,
				'timeout'     => $timeout,
				'redirection' => 0,
				'data_format' => 'body',
			]
		);

		if ( is_wp_error( $response ) ) {
			return TransportResult::error( $response->get_error_message() );
		}

		return new TransportResult(
			(int) wp_remote_retrieve_response_code( $response ),
			wp_remote_retrieve_body( $response )
		);
	}
}

<?php
/**
 * Outcome of one HTTP attempt.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Delivery;

/**
 * Either an HTTP response (status code + body) or a transport error.
 */
final class TransportResult {

	/**
	 * Status codes worth retrying besides 5xx.
	 */
	private const RETRYABLE_CODES = [ 408, 425, 429 ];

	/**
	 * Creates a result.
	 *
	 * @param int|null    $status_code HTTP status, or null when no response was received.
	 * @param string      $body        Response body.
	 * @param string|null $error       Transport error message (DNS, TLS, timeout, ...).
	 */
	public function __construct(
		public readonly ?int $status_code,
		public readonly string $body = '',
		public readonly ?string $error = null,
	) {}

	/**
	 * A result for a request that got no response.
	 *
	 * @param string $error Error message.
	 */
	public static function error( string $error ): self {
		return new self( null, '', $error );
	}

	/**
	 * 2xx responses count as delivered. Redirects are not followed.
	 */
	public function is_success(): bool {
		return null !== $this->status_code && $this->status_code >= 200 && $this->status_code < 300;
	}

	/**
	 * Transport errors, timeouts, rate limits and server errors are retried;
	 * other 4xx and 3xx responses are not, because resending will not help.
	 */
	public function is_retryable(): bool {
		if ( null === $this->status_code ) {
			return true;
		}

		return $this->status_code >= 500 || in_array( $this->status_code, self::RETRYABLE_CODES, true );
	}
}

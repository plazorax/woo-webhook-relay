<?php
/**
 * HMAC-SHA256 request signatures.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Delivery;

/**
 * The signed string is "{timestamp}.{body}", so a captured request cannot be
 * replayed later with a new timestamp. The header value is "sha256=<hex>".
 */
final class Signer {

	public const SIGNATURE_HEADER = 'X-Relay-Signature';
	public const TIMESTAMP_HEADER = 'X-Relay-Timestamp';
	public const TOLERANCE        = 300;

	/**
	 * Signs a request body.
	 *
	 * @param string $secret    Endpoint secret.
	 * @param int    $timestamp Unix timestamp sent in the timestamp header.
	 * @param string $body      Exact request body.
	 */
	public static function sign( string $secret, int $timestamp, string $body ): string {
		return 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	}

	/**
	 * Verifies a signature the way a receiver should: constant-time comparison
	 * and a bounded clock skew.
	 *
	 * @param string $secret    Endpoint secret.
	 * @param string $signature Value of the signature header.
	 * @param int    $timestamp Value of the timestamp header.
	 * @param string $body      Raw request body.
	 * @param int    $now       Current Unix time.
	 * @param int    $tolerance Accepted clock difference in seconds.
	 */
	public static function verify( string $secret, string $signature, int $timestamp, string $body, int $now, int $tolerance = self::TOLERANCE ): bool {
		if ( '' === $secret || abs( $now - $timestamp ) > $tolerance ) {
			return false;
		}

		return hash_equals( self::sign( $secret, $timestamp, $body ), $signature );
	}
}

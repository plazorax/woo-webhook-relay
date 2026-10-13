<?php
/**
 * Read-only access to query arguments.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Admin;

/**
 * Returns sanitized $_GET values. It does not verify nonces: callers that
 * change state must call check_admin_referer() themselves.
 */
final class Request {

	/**
	 * A query argument as a sanitized key (lowercase alphanumerics, "-" and "_").
	 *
	 * @param string $name Argument name.
	 */
	public static function key( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See class docblock.
		return isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ? sanitize_key( wp_unslash( $_GET[ $name ] ) ) : '';
	}

	/**
	 * A query argument as a non-negative integer.
	 *
	 * @param string $name Argument name.
	 */
	public static function int( string $name ): int {
		return absint( self::key( $name ) );
	}
}

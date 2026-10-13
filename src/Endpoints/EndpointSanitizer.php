<?php
/**
 * Validates the endpoints form.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Endpoints;

use Closure;
use WooWebhookRelay\Events\EventType;
use WooWebhookRelay\Payload\PayloadFormat;

/**
 * Input is the posted `woo_webhook_relay_endpoints` array: existing endpoints
 * keyed by ID plus an optional `new` row. Output is the array to store, keyed
 * by ID. The sanitizer is idempotent, because WordPress runs the
 * sanitize callback twice when an option is saved for the first time.
 */
final class EndpointSanitizer {

	public const NEW_ROW           = 'new';
	public const MIN_SECRET_LENGTH = 16;

	/**
	 * Generates IDs for new endpoints.
	 *
	 * @var Closure(): string
	 */
	private Closure $id_generator;

	/**
	 * Creates the sanitizer.
	 *
	 * @param (Closure(): string)|null $id_generator Overrides ID generation in tests.
	 */
	public function __construct( ?Closure $id_generator = null ) {
		$this->id_generator = $id_generator ?? static fn(): string => 'ep_' . bin2hex( random_bytes( 4 ) );
	}

	/**
	 * Sanitizes the posted endpoints.
	 *
	 * @param mixed                   $input    Posted value (already unslashed by options.php).
	 * @param array<string, Endpoint> $existing Currently stored endpoints.
	 * @return array{endpoints: array<string, array<string, mixed>>, errors: list<string>}
	 */
	public function sanitize( mixed $input, array $existing ): array {
		if ( ! is_array( $input ) ) {
			return [
				'endpoints' => array_map( static fn( Endpoint $endpoint ): array => $endpoint->to_array(), $existing ),
				'errors'    => [],
			];
		}

		$endpoints = [];
		$errors    = [];

		foreach ( $input as $key => $row ) {
			$key    = (string) $key;
			$is_new = self::NEW_ROW === $key;

			if ( ! is_array( $row ) || ( ! $is_new && 1 !== preg_match( '/^[a-z0-9_-]{1,40}$/', $key ) ) ) {
				continue;
			}

			if ( ! empty( $row['delete'] ) ) {
				continue;
			}

			$url = trim( self::text( $row['url'] ?? '' ) );

			if ( $is_new && '' === $url ) {
				continue; // The "add endpoint" row was left empty.
			}

			$previous = $is_new ? null : ( $existing[ $key ] ?? null );
			$result   = $this->sanitize_row( $is_new ? ( $this->id_generator )() : $key, $url, $row, $previous );

			if ( is_string( $result ) ) {
				$errors[] = $result;

				if ( null !== $previous ) {
					$endpoints[ $previous->id ] = $previous->to_array();
				}
				continue;
			}

			$endpoints[ $result->id ] = $result->to_array();
		}

		return [
			'endpoints' => $endpoints,
			'errors'    => $errors,
		];
	}

	/**
	 * Builds an endpoint from one row, or returns an error message.
	 *
	 * @param string        $id       Endpoint ID.
	 * @param string        $url      Trimmed URL as posted.
	 * @param array<mixed>  $row      Posted row.
	 * @param Endpoint|null $previous Stored version of this endpoint.
	 */
	private function sanitize_row( string $id, string $url, array $row, ?Endpoint $previous ): Endpoint|string {
		$name  = sanitize_text_field( self::text( $row['name'] ?? '' ) );
		$label = '' !== $name ? $name : $url;

		if ( ! self::is_valid_url( $url ) ) {
			/* translators: %s: endpoint name or URL. */
			return sprintf( __( 'Endpoint "%s" was not saved: enter a valid http:// or https:// URL.', 'woo-webhook-relay' ), $label );
		}

		$url    = esc_url_raw( $url, [ 'http', 'https' ] );
		$format = PayloadFormat::tryFrom( self::text( $row['format'] ?? '' ) ) ?? PayloadFormat::Full;
		$secret = (string) preg_replace( '/[^\x21-\x7E]/', '', self::text( $row['secret'] ?? '' ) );

		if ( '' === $secret && null !== $previous ) {
			$secret = $previous->secret; // A blank field keeps the stored secret.
		}

		if ( '' === $secret && PayloadFormat::Slack !== $format ) {
			/* translators: %s: endpoint name or URL. */
			return sprintf( __( 'Endpoint "%s" was not saved: a secret is required for the full and slim formats.', 'woo-webhook-relay' ), $label );
		}

		if ( '' !== $secret && strlen( $secret ) < self::MIN_SECRET_LENGTH ) {
			return sprintf(
				/* translators: 1: endpoint name or URL, 2: minimum length. */
				__( 'Endpoint "%1$s" was not saved: the secret must be at least %2$d characters.', 'woo-webhook-relay' ),
				$label,
				self::MIN_SECRET_LENGTH
			);
		}

		$events = is_array( $row['events'] ?? null ) ? $row['events'] : [];
		$events = array_values( array_unique( array_filter( array_map( [ self::class, 'text' ], $events ), [ EventType::class, 'is_valid' ] ) ) );

		if ( '' === $name ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			$name = is_string( $host ) ? $host : $id;
		}

		return new Endpoint( $id, $name, $url, $secret, $events, $format, ! empty( $row['enabled'] ) );
	}

	/**
	 * Whether the URL is absolute http(s) with a host.
	 *
	 * @param string $url URL.
	 */
	private static function is_valid_url( string $url ): bool {
		if ( false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );

		return in_array( is_string( $scheme ) ? strtolower( $scheme ) : '', [ 'http', 'https' ], true );
	}

	/**
	 * Casts scalars to string; anything else becomes "".
	 *
	 * @param mixed $value Value.
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}

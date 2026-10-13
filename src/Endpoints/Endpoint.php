<?php
/**
 * A configured webhook destination.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Endpoints;

use WooWebhookRelay\Events\EventType;
use WooWebhookRelay\Payload\PayloadFormat;

/**
 * Immutable endpoint settings.
 */
final class Endpoint {

	/**
	 * Creates an endpoint.
	 *
	 * @param string        $id      Stable ID, e.g. "ep_1a2b3c4d".
	 * @param string        $name    Label shown in the admin.
	 * @param string        $url     Destination URL.
	 * @param string        $secret  HMAC secret; empty means requests are not signed.
	 * @param list<string>  $events  Subscribed event names.
	 * @param PayloadFormat $format  Payload format.
	 * @param bool          $enabled Whether deliveries are created and sent.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $url,
		public readonly string $secret,
		public readonly array $events,
		public readonly PayloadFormat $format,
		public readonly bool $enabled,
	) {}

	/**
	 * Builds an endpoint from stored settings. Returns null for rows without
	 * an ID or URL; unknown events and formats are dropped or defaulted.
	 *
	 * @param array<mixed> $data Stored row.
	 */
	public static function from_array( array $data ): ?self {
		$id  = is_string( $data['id'] ?? null ) ? $data['id'] : '';
		$url = is_string( $data['url'] ?? null ) ? $data['url'] : '';

		if ( '' === $id || '' === $url ) {
			return null;
		}

		$events = is_array( $data['events'] ?? null ) ? $data['events'] : [];
		$events = array_values( array_filter( $events, static fn( $event ): bool => is_string( $event ) && EventType::is_valid( $event ) ) );
		$format = is_string( $data['format'] ?? null ) ? PayloadFormat::tryFrom( $data['format'] ) : null;

		return new self(
			$id,
			is_string( $data['name'] ?? null ) ? $data['name'] : $id,
			$url,
			is_string( $data['secret'] ?? null ) ? $data['secret'] : '',
			$events,
			$format ?? PayloadFormat::Full,
			! empty( $data['enabled'] )
		);
	}

	/**
	 * The stored representation.
	 *
	 * @return array{id: string, name: string, url: string, secret: string, events: list<string>, format: string, enabled: bool}
	 */
	public function to_array(): array {
		return [
			'id'      => $this->id,
			'name'    => $this->name,
			'url'     => $this->url,
			'secret'  => $this->secret,
			'events'  => $this->events,
			'format'  => $this->format->value,
			'enabled' => $this->enabled,
		];
	}

	/**
	 * Whether the endpoint should receive this event.
	 *
	 * @param string $event Event name.
	 */
	public function subscribes_to( string $event ): bool {
		return $this->enabled && in_array( $event, $this->events, true );
	}

	/**
	 * Whether requests to this endpoint carry a signature.
	 */
	public function is_signed(): bool {
		return '' !== $this->secret;
	}
}

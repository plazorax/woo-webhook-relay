<?php
/**
 * Picks the formatter for an endpoint's format.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Payload;

use WooWebhookRelay\Events\Event;

/**
 * Maps PayloadFormat cases to formatters.
 */
final class PayloadFactory {

	/**
	 * Creates the factory.
	 *
	 * @param FullFormatter  $full  Full formatter.
	 * @param SlimFormatter  $slim  Slim formatter.
	 * @param SlackFormatter $slack Slack formatter.
	 */
	public function __construct(
		private readonly FullFormatter $full,
		private readonly SlimFormatter $slim,
		private readonly SlackFormatter $slack,
	) {}

	/**
	 * Factory with the default formatters.
	 *
	 * @param string $site_name Shown in Slack messages.
	 */
	public static function create( string $site_name ): self {
		return new self( new FullFormatter(), new SlimFormatter(), new SlackFormatter( $site_name ) );
	}

	/**
	 * Builds the payload for a format.
	 *
	 * @param PayloadFormat $format Format.
	 * @param Event         $event  Event.
	 * @return array<string, mixed>
	 */
	public function build( PayloadFormat $format, Event $event ): array {
		$formatter = match ( $format ) {
			PayloadFormat::Full  => $this->full,
			PayloadFormat::Slim  => $this->slim,
			PayloadFormat::Slack => $this->slack,
		};

		return $formatter->format( $event );
	}
}

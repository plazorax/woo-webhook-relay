<?php
/**
 * Payload formats an endpoint can choose.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Payload;

/**
 * Stored in endpoint settings by its string value.
 */
enum PayloadFormat: string {

	case Full  = 'full';
	case Slim  = 'slim';
	case Slack = 'slack';

	/**
	 * Human-readable label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Full  => __( 'Full (complete order or customer snapshot)', 'woo-webhook-relay' ),
			self::Slim  => __( 'Slim (IDs, status, totals, email)', 'woo-webhook-relay' ),
			self::Slack => __( 'Slack message (incoming webhook)', 'woo-webhook-relay' ),
		};
	}
}

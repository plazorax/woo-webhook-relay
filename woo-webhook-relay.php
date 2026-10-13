<?php
/**
 * Plugin Name:          Webhook Relay for WooCommerce
 * Plugin URI:           https://github.com/plazorax/woo-webhook-relay
 * Description:          Signed webhooks for WooCommerce order and customer events, with retries, a delivery log and replay.
 * Version:              0.1.0
 * Requires at least:    6.4
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.0
 * Author:               Oleksii Shaposhnikov
 * Author URI:           https://github.com/plazorax
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Text Domain:          woo-webhook-relay
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/src/Autoloader.php';

Autoloader::register( __DIR__ . '/src' );

register_activation_hook( __FILE__, [ Schema::class, 'install' ] );
register_deactivation_hook( __FILE__, [ Plugin::class, 'deactivate' ] );

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', [ Plugin::class, 'boot' ] );

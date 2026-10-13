<?php
/**
 * Removes the plugin's table, options and scheduled actions.
 *
 * Runs when the plugin is deleted from the Plugins screen, not on deactivation.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( [ 'woo_webhook_relay_deliver', 'woo_webhook_relay_cleanup' ] as $woo_webhook_relay_hook ) {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( $woo_webhook_relay_hook );
	}
}

foreach ( [ 'woo_webhook_relay_endpoints', 'woo_webhook_relay_settings', 'woo_webhook_relay_db_version' ] as $woo_webhook_relay_option ) {
	delete_option( $woo_webhook_relay_option );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the plugin's own table.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'woo_webhook_relay_deliveries' ) );

<?php
/**
 * Delivery log table.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay;

use wpdb;

/**
 * Creates and upgrades the table with dbDelta(). Bump VERSION whenever the
 * CREATE TABLE statement changes; maybe_upgrade() then reruns it.
 */
final class Schema {

	public const VERSION        = '1';
	public const VERSION_OPTION = 'woo_webhook_relay_db_version';
	public const TABLE          = 'woo_webhook_relay_deliveries';

	/**
	 * Table name with the site prefix.
	 *
	 * @param wpdb $db WordPress database.
	 */
	public static function table_name( wpdb $db ): string {
		return $db->prefix . self::TABLE;
	}

	/**
	 * Creates or updates the table. Runs on activation.
	 */
	public static function install(): void {
		global $wpdb;

		$table   = self::table_name( $wpdb );
		$collate = $wpdb->get_charset_collate();

		// dbDelta() needs two spaces after PRIMARY KEY and one column per line.
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			endpoint_id varchar(40) NOT NULL,
			event varchar(64) NOT NULL,
			event_id char(36) NOT NULL,
			resource_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			response_code smallint(5) unsigned DEFAULT NULL,
			response_body text,
			error text,
			payload longtext NOT NULL,
			replay_of bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			next_attempt_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY endpoint_id (endpoint_id),
			KEY created_at (created_at)
		) {$collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Autoloaded: maybe_upgrade() reads it on every request.
		update_option( self::VERSION_OPTION, self::VERSION, true );
	}

	/**
	 * Reruns install() after an update that changed the schema.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::VERSION_OPTION ) !== self::VERSION ) {
			self::install();
		}
	}
}

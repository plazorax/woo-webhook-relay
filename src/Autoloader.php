<?php
/**
 * PSR-4 autoloader, so the plugin runs without `composer install`.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay;

/**
 * Maps WooWebhookRelay\Foo\Bar to src/Foo/Bar.php.
 */
final class Autoloader {

	/**
	 * Registers the autoloader for classes under this namespace.
	 *
	 * @param string $base_dir Absolute path of the src directory.
	 */
	public static function register( string $base_dir ): void {
		spl_autoload_register(
			static function ( string $class_name ) use ( $base_dir ): void {
				$prefix = __NAMESPACE__ . '\\';

				if ( ! str_starts_with( $class_name, $prefix ) ) {
					return;
				}

				$relative = substr( $class_name, strlen( $prefix ) );
				$file     = $base_dir . '/' . str_replace( '\\', '/', $relative ) . '.php';

				if ( is_readable( $file ) ) {
					require $file;
				}
			}
		);
	}
}

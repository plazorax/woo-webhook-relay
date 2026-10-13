<?php
/**
 * Global delivery settings.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Settings;

/**
 * Integer settings with defaults and bounds, stored as one option.
 */
class Settings {

	public const OPTION = 'woo_webhook_relay_settings';

	/**
	 * Default, minimum and maximum per setting.
	 */
	public const SCHEMA = [
		'max_attempts'   => [
			'default' => 6,
			'min'     => 1,
			'max'     => 10,
		],
		'timeout'        => [
			'default' => 10,
			'min'     => 1,
			'max'     => 30,
		],
		'retention_days' => [
			'default' => 30,
			'min'     => 1,
			'max'     => 365,
		],
	];

	/**
	 * Clamps posted values into range; missing or invalid values get the default.
	 *
	 * @param mixed $input Posted value.
	 * @return array<string, int>
	 */
	public static function sanitize( mixed $input ): array {
		$input = is_array( $input ) ? $input : [];
		$clean = [];

		foreach ( self::SCHEMA as $key => $rule ) {
			$value = $input[ $key ] ?? null;

			$clean[ $key ] = is_numeric( $value )
				? max( $rule['min'], min( $rule['max'], (int) $value ) )
				: $rule['default'];
		}

		return $clean;
	}

	/**
	 * All settings.
	 *
	 * @return array<string, int>
	 */
	public function all(): array {
		return self::sanitize( get_option( self::OPTION, [] ) );
	}

	/**
	 * Maximum delivery attempts, including the first one.
	 */
	public function max_attempts(): int {
		return $this->all()['max_attempts'];
	}

	/**
	 * HTTP timeout in seconds.
	 */
	public function timeout(): int {
		return $this->all()['timeout'];
	}

	/**
	 * Days to keep finished deliveries in the log.
	 */
	public function retention_days(): int {
		return $this->all()['retention_days'];
	}
}

<?php
/**
 * Tests for global settings sanitization.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Tests\Unit;

use WooWebhookRelay\Settings\Settings;
use WooWebhookRelay\Tests\TestCase;

final class SettingsTest extends TestCase {

	public function test_missing_input_gets_defaults(): void {
		$this->assertSame(
			[
				'max_attempts'   => 6,
				'timeout'        => 10,
				'retention_days' => 30,
			],
			Settings::sanitize( null )
		);
	}

	public function test_values_are_clamped_to_their_range(): void {
		$clean = Settings::sanitize(
			[
				'max_attempts'   => '99',
				'timeout'        => '0',
				'retention_days' => '-5',
			]
		);

		$this->assertSame( 10, $clean['max_attempts'] );
		$this->assertSame( 1, $clean['timeout'] );
		$this->assertSame( 1, $clean['retention_days'] );
	}

	public function test_non_numeric_values_fall_back_to_defaults_and_unknown_keys_are_dropped(): void {
		$clean = Settings::sanitize(
			[
				'max_attempts' => 'lots',
				'timeout'      => '15',
				'evil'         => '<script>',
			]
		);

		$this->assertSame(
			[
				'max_attempts'   => 6,
				'timeout'        => 15,
				'retention_days' => 30,
			],
			$clean
		);
	}

	public function test_reads_and_sanitizes_the_stored_option(): void {
		$this->options[ Settings::OPTION ] = [ 'max_attempts' => '3' ];

		$settings = new Settings();

		$this->assertSame( 3, $settings->max_attempts() );
		$this->assertSame( 10, $settings->timeout() );
		$this->assertSame( 30, $settings->retention_days() );
	}
}

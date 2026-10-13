<?php
/**
 * Base test case with Brain Monkey and common WordPress stubs.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Stubs the handful of WordPress functions the plugin's pure classes call.
 */
abstract class TestCase extends PHPUnitTestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Fake options table used by the get_option() stub.
	 *
	 * @var array<string, mixed>
	 */
	protected array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = [];

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		// These stubs stand in for the WordPress functions themselves, so they use the PHP originals.
		// phpcs:disable WordPress.WP.AlternativeFunctions
		Functions\stubs(
			[
				'wp_json_encode'      => static fn( $data, $flags = 0 ) => json_encode( $data, $flags ),
				'wp_parse_url'        => static fn( $url, $component = -1 ) => parse_url( $url, $component ),
				'sanitize_text_field' => static fn( $text ) => trim( strip_tags( (string) $text ) ),
				'esc_url_raw'         => static fn( $url ) => (string) $url,
				'wp_generate_uuid4'   => static fn() => '6f1c2b3a-0000-4000-8000-000000000001',
				'get_option'          => fn( $name, $fallback = false ) => $this->options[ $name ] ?? $fallback,
			]
		);
		// phpcs:enable WordPress.WP.AlternativeFunctions
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}

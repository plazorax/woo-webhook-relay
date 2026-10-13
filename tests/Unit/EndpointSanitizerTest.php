<?php
/**
 * Tests for the endpoints form sanitizer.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Tests\Unit;

use WooWebhookRelay\Endpoints\Endpoint;
use WooWebhookRelay\Endpoints\EndpointSanitizer;
use WooWebhookRelay\Events\EventType;
use WooWebhookRelay\Payload\PayloadFormat;
use WooWebhookRelay\Tests\TestCase;

final class EndpointSanitizerTest extends TestCase {

	private const SECRET = 'a-long-enough-secret-123';

	private EndpointSanitizer $sanitizer;

	protected function setUp(): void {
		parent::setUp();
		$this->sanitizer = new EndpointSanitizer( static fn(): string => 'ep_generated' );
	}

	public function test_new_row_becomes_an_endpoint_with_a_generated_id(): void {
		$result = $this->sanitizer->sanitize(
			[
				'new' => [
					'name'    => ' CRM <b>sync</b> ',
					'url'     => ' https://crm.example.com/hooks/woo ',
					'secret'  => self::SECRET,
					'events'  => [ EventType::ORDER_CREATED, 'order.deleted', EventType::ORDER_CREATED ],
					'format'  => 'slim',
					'enabled' => '1',
				],
			],
			[]
		);

		$this->assertSame( [], $result['errors'] );
		$this->assertSame(
			[
				'ep_generated' => [
					'id'      => 'ep_generated',
					'name'    => 'CRM sync',
					'url'     => 'https://crm.example.com/hooks/woo',
					'secret'  => self::SECRET,
					'events'  => [ EventType::ORDER_CREATED ],
					'format'  => 'slim',
					'enabled' => true,
				],
			],
			$result['endpoints']
		);
	}

	public function test_empty_new_row_is_ignored(): void {
		$result = $this->sanitizer->sanitize(
			[
				'new' => [
					'url'    => '  ',
					'format' => 'full',
				],
			],
			[]
		);

		$this->assertSame(
			[
				'endpoints' => [],
				'errors'    => [],
			],
			$result
		);
	}

	public function test_invalid_url_is_rejected(): void {
		foreach ( [ 'not a url', 'ftp://example.com/x', 'javascript:alert(1)', '//example.com' ] as $url ) {
			$result = $this->sanitizer->sanitize(
				[
					'new' => [
						'url'    => $url,
						'secret' => self::SECRET,
					],
				],
				[]
			);

			$this->assertSame( [], $result['endpoints'], $url );
			$this->assertCount( 1, $result['errors'], $url );
		}
	}

	public function test_invalid_edit_keeps_the_stored_endpoint(): void {
		$stored = self::stored();
		$result = $this->sanitizer->sanitize(
			[
				'ep_1' => [
					'url'    => 'nope',
					'events' => [],
				],
			],
			[ 'ep_1' => $stored ]
		);

		$this->assertSame( [ 'ep_1' => $stored->to_array() ], $result['endpoints'] );
		$this->assertStringContainsString( 'valid http', $result['errors'][0] );
	}

	public function test_secret_is_required_for_signed_formats_but_not_for_slack(): void {
		$full  = $this->sanitizer->sanitize(
			[
				'new' => [
					'url'    => 'https://example.com',
					'format' => 'full',
				],
			],
			[]
		);
		$slack = $this->sanitizer->sanitize(
			[
				'new' => [
					'url'    => 'https://hooks.slack.com/services/X',
					'format' => 'slack',
				],
			],
			[]
		);

		$this->assertSame( [], $full['endpoints'] );
		$this->assertStringContainsString( 'secret is required', $full['errors'][0] );
		$this->assertSame( '', $slack['endpoints']['ep_generated']['secret'] );
		$this->assertSame( [], $slack['errors'] );
	}

	public function test_short_secret_is_rejected(): void {
		$result = $this->sanitizer->sanitize(
			[
				'new' => [
					'url'    => 'https://example.com',
					'secret' => 'short',
				],
			],
			[]
		);

		$this->assertSame( [], $result['endpoints'] );
		$this->assertStringContainsString( 'at least 16', $result['errors'][0] );
	}

	public function test_blank_secret_keeps_the_stored_one_and_whitespace_is_stripped(): void {
		$stored = self::stored();

		$kept    = $this->sanitizer->sanitize(
			[
				'ep_1' => [
					'url'    => $stored->url,
					'secret' => '',
				],
			],
			[ 'ep_1' => $stored ]
		);
		$changed = $this->sanitizer->sanitize(
			[
				'ep_1' => [
					'url'    => $stored->url,
					'secret' => " new secret value 123 \n",
				],
			],
			[ 'ep_1' => $stored ]
		);

		$this->assertSame( $stored->secret, $kept['endpoints']['ep_1']['secret'] );
		$this->assertSame( 'newsecretvalue123', $changed['endpoints']['ep_1']['secret'] );
	}

	public function test_delete_flag_removes_the_endpoint(): void {
		$result = $this->sanitizer->sanitize(
			[
				'ep_1' => [
					'url'    => 'https://example.com',
					'delete' => '1',
				],
			],
			[ 'ep_1' => self::stored() ]
		);

		$this->assertSame( [], $result['endpoints'] );
	}

	public function test_unchecked_enabled_disables_and_name_falls_back_to_host(): void {
		$result = $this->sanitizer->sanitize(
			[
				'ep_1' => [
					'url'  => 'https://hooks.example.org/a',
					'name' => '',
				],
			],
			[ 'ep_1' => self::stored() ]
		);

		$this->assertFalse( $result['endpoints']['ep_1']['enabled'] );
		$this->assertSame( 'hooks.example.org', $result['endpoints']['ep_1']['name'] );
		$this->assertSame( 'full', $result['endpoints']['ep_1']['format'] );
	}

	public function test_rows_with_invalid_keys_or_shapes_are_skipped(): void {
		$result = $this->sanitizer->sanitize(
			[
				'../../etc' => [
					'url'    => 'https://example.com',
					'secret' => self::SECRET,
				],
				'ep_2'      => 'not-a-row',
			],
			[]
		);

		$this->assertSame( [], $result['endpoints'] );
	}

	public function test_non_array_input_keeps_the_stored_endpoints(): void {
		$stored = self::stored();

		$this->assertSame( [ 'ep_1' => $stored->to_array() ], $this->sanitizer->sanitize( 'oops', [ 'ep_1' => $stored ] )['endpoints'] );
	}

	public function test_sanitizing_the_output_again_changes_nothing(): void {
		// WordPress runs the sanitize callback twice when the option is first created.
		$first  = $this->sanitizer->sanitize(
			[
				'new' => [
					'url'     => 'https://example.com/hook',
					'secret'  => self::SECRET,
					'events'  => [ EventType::ORDER_REFUNDED ],
					'enabled' => '1',
				],
			],
			[]
		);
		$second = $this->sanitizer->sanitize( $first['endpoints'], [] );

		$this->assertSame( $first, $second );
	}

	/**
	 * A stored endpoint.
	 */
	private static function stored(): Endpoint {
		return new Endpoint( 'ep_1', 'Stored', 'https://stored.example.com', 'stored-secret-0123456789', [ EventType::ORDER_CREATED ], PayloadFormat::Full, true );
	}
}

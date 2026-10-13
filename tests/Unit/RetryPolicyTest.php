<?php
/**
 * Tests for the retry policy and the retryable-response rules.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WooWebhookRelay\Delivery\RetryPolicy;
use WooWebhookRelay\Delivery\TransportResult;

final class RetryPolicyTest extends TestCase {

	public function test_delay_grows_exponentially_from_the_base(): void {
		$policy = new RetryPolicy( max_attempts: 6, base_delay: 60, multiplier: 3, jitter: 0.0 );

		$delays = array_map( [ $policy, 'delay_after' ], [ 1, 2, 3, 4, 5 ] );

		$this->assertSame( [ 60, 180, 540, 1620, 4860 ], $delays );
	}

	public function test_delay_is_capped(): void {
		$policy = new RetryPolicy( base_delay: 60, multiplier: 3, max_delay: 3600, jitter: 0.0 );

		$this->assertSame( 3600, $policy->delay_after( 5 ) );
		$this->assertSame( 3600, $policy->delay_after( 500 ) );
	}

	public function test_jitter_adds_at_most_the_configured_fraction(): void {
		$calls  = [];
		$policy = new RetryPolicy(
			base_delay: 100,
			multiplier: 2,
			jitter: 0.1,
			random: static function ( int $min, int $max ) use ( &$calls ): int {
				$calls[] = [ $min, $max ];
				return $max;
			}
		);

		$this->assertSame( 220, $policy->delay_after( 2 ) );
		$this->assertSame( [ [ 0, 20 ] ], $calls );
	}

	public function test_retries_until_max_attempts_is_reached(): void {
		$policy = new RetryPolicy( max_attempts: 3 );
		$error  = new TransportResult( 503 );

		$this->assertTrue( $policy->should_retry( 1, $error ) );
		$this->assertTrue( $policy->should_retry( 2, $error ) );
		$this->assertFalse( $policy->should_retry( 3, $error ) );
	}

	public function test_a_single_attempt_policy_never_retries(): void {
		$this->assertFalse( ( new RetryPolicy( max_attempts: 1 ) )->should_retry( 1, TransportResult::error( 'timeout' ) ) );
		$this->assertSame( 1, ( new RetryPolicy( max_attempts: 0 ) )->max_attempts() );
	}

	/**
	 * Response classification.
	 *
	 * @return array<string, array{TransportResult, bool, bool}>
	 */
	public static function results(): array {
		return [
			'200 OK'           => [ new TransportResult( 200 ), true, false ],
			'204 No Content'   => [ new TransportResult( 204 ), true, false ],
			'301 redirect'     => [ new TransportResult( 301 ), false, false ],
			'400 Bad Request'  => [ new TransportResult( 400 ), false, false ],
			'401 Unauthorized' => [ new TransportResult( 401 ), false, false ],
			'410 Gone'         => [ new TransportResult( 410 ), false, false ],
			'408 Timeout'      => [ new TransportResult( 408 ), false, true ],
			'429 Too Many'     => [ new TransportResult( 429 ), false, true ],
			'500 Server Error' => [ new TransportResult( 500 ), false, true ],
			'503 Unavailable'  => [ new TransportResult( 503 ), false, true ],
			'transport error'  => [ TransportResult::error( 'cURL error 28' ), false, true ],
		];
	}

	#[DataProvider( 'results' )]
	public function test_classifies_responses( TransportResult $result, bool $success, bool $retryable ): void {
		$policy = new RetryPolicy( max_attempts: 5 );

		$this->assertSame( $success, $result->is_success() );
		$this->assertSame( $retryable, $policy->should_retry( 1, $result ) );
	}
}

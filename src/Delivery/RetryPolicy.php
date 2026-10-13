<?php
/**
 * Exponential backoff between attempts.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Delivery;

use Closure;

/**
 * With the defaults (6 attempts, 60 s base, factor 3) retries happen about
 * 1, 3, 9, 27 and 81 minutes after the previous attempt, plus up to 10 %
 * random jitter so that many failed deliveries do not retry in lockstep.
 */
final class RetryPolicy {

	/**
	 * Returns a random integer in [min, max].
	 *
	 * @var Closure(int, int): int
	 */
	private Closure $random;

	/**
	 * Creates the policy.
	 *
	 * @param int                          $max_attempts Total attempts, including the first.
	 * @param int                          $base_delay   Delay before the first retry, in seconds.
	 * @param int                          $multiplier   Growth factor per attempt.
	 * @param int                          $max_delay    Upper bound for one delay, in seconds.
	 * @param float                        $jitter       Fraction of the delay added at random (0 disables).
	 * @param (Closure(int, int): int)|null $random       Random source, for tests.
	 */
	public function __construct(
		private readonly int $max_attempts = 6,
		private readonly int $base_delay = 60,
		private readonly int $multiplier = 3,
		private readonly int $max_delay = 21600,
		private readonly float $jitter = 0.1,
		?Closure $random = null,
	) {
		$this->random = $random ?? static fn( int $min, int $max ): int => random_int( $min, $max );
	}

	/**
	 * Total attempts, including the first.
	 */
	public function max_attempts(): int {
		return max( 1, $this->max_attempts );
	}

	/**
	 * Whether to try again after the given attempt.
	 *
	 * @param int             $attempt Attempt that just finished (1-based).
	 * @param TransportResult $result  Its result.
	 */
	public function should_retry( int $attempt, TransportResult $result ): bool {
		return ! $result->is_success() && $result->is_retryable() && $attempt < $this->max_attempts();
	}

	/**
	 * Seconds to wait after the given attempt.
	 *
	 * @param int $attempt Attempt that just finished (1-based).
	 */
	public function delay_after( int $attempt ): int {
		$exponent = max( 0, $attempt - 1 );
		$delay    = (int) min( $this->max_delay, $this->base_delay * ( $this->multiplier ** $exponent ) );
		$spread   = (int) floor( $delay * max( 0.0, $this->jitter ) );

		return $spread > 0 ? $delay + ( $this->random )( 0, $spread ) : $delay;
	}
}

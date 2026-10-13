<?php
/**
 * Tests for the HMAC signer.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WooWebhookRelay\Delivery\Signer;

final class SignerTest extends TestCase {

	private const SECRET    = 'test-secret-0123456789';
	private const TIMESTAMP = 1767225600;
	private const BODY      = '{"id":"evt_1","event":"order.created"}';

	public function test_signature_matches_an_independently_computed_vector(): void {
		// printf '%s' '1767225600.{"id":"evt_1","event":"order.created"}' | openssl dgst -sha256 -hmac 'test-secret-0123456789'
		$this->assertSame(
			'sha256=6955f6a8ae2a56e16958f251fb21ae15268035a97dc1cc2c4345bea55cbb8f9b',
			Signer::sign( self::SECRET, self::TIMESTAMP, self::BODY )
		);
	}

	public function test_signature_covers_the_timestamp(): void {
		$this->assertNotSame(
			Signer::sign( self::SECRET, self::TIMESTAMP, self::BODY ),
			Signer::sign( self::SECRET, self::TIMESTAMP + 1, self::BODY )
		);
	}

	public function test_verify_accepts_a_valid_signature_within_tolerance(): void {
		$signature = Signer::sign( self::SECRET, self::TIMESTAMP, self::BODY );

		$this->assertTrue( Signer::verify( self::SECRET, $signature, self::TIMESTAMP, self::BODY, self::TIMESTAMP + 299 ) );
	}

	public function test_verify_rejects_a_tampered_body(): void {
		$signature = Signer::sign( self::SECRET, self::TIMESTAMP, self::BODY );

		$this->assertFalse( Signer::verify( self::SECRET, $signature, self::TIMESTAMP, self::BODY . ' ', self::TIMESTAMP ) );
	}

	public function test_verify_rejects_the_wrong_secret(): void {
		$signature = Signer::sign( self::SECRET, self::TIMESTAMP, self::BODY );

		$this->assertFalse( Signer::verify( 'another-secret-0123456789', $signature, self::TIMESTAMP, self::BODY, self::TIMESTAMP ) );
	}

	public function test_verify_rejects_old_and_future_timestamps(): void {
		$signature = Signer::sign( self::SECRET, self::TIMESTAMP, self::BODY );

		$this->assertFalse( Signer::verify( self::SECRET, $signature, self::TIMESTAMP, self::BODY, self::TIMESTAMP + 301 ) );
		$this->assertFalse( Signer::verify( self::SECRET, $signature, self::TIMESTAMP, self::BODY, self::TIMESTAMP - 301 ) );
	}

	public function test_verify_rejects_an_empty_secret(): void {
		$signature = Signer::sign( '', self::TIMESTAMP, self::BODY );

		$this->assertFalse( Signer::verify( '', $signature, self::TIMESTAMP, self::BODY, self::TIMESTAMP ) );
	}
}

<?php
/**
 * PHPUnit bootstrap: Composer autoloader only. WordPress functions are
 * stubbed per test with Brain Monkey.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

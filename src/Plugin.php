<?php
/**
 * Composition root.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay;

use WooWebhookRelay\Admin\AdminPage;
use WooWebhookRelay\Cli\Command;
use WooWebhookRelay\Delivery\ActionSchedulerQueue;
use WooWebhookRelay\Delivery\DeliveryStore;
use WooWebhookRelay\Delivery\DeliveryWorker;
use WooWebhookRelay\Delivery\Dispatcher;
use WooWebhookRelay\Delivery\RetryPolicy;
use WooWebhookRelay\Delivery\WpdbDeliveryStore;
use WooWebhookRelay\Delivery\WpHttpTransport;
use WooWebhookRelay\Endpoints\EndpointRepository;
use WooWebhookRelay\Events\EventFactory;
use WooWebhookRelay\Events\EventListener;
use WooWebhookRelay\Payload\PayloadFactory;
use WooWebhookRelay\Settings\Settings;

/**
 * Builds the object graph and registers hooks.
 */
final class Plugin {

	public const VERSION      = '0.1.0';
	public const CAPABILITY   = 'manage_woocommerce';
	public const CLEANUP_HOOK = 'woo_webhook_relay_cleanup';

	/**
	 * Global settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Endpoint settings.
	 *
	 * @var EndpointRepository
	 */
	private EndpointRepository $endpoints;

	/**
	 * Delivery log.
	 *
	 * @var DeliveryStore
	 */
	private DeliveryStore $store;

	/**
	 * Creates and replays deliveries.
	 *
	 * @var Dispatcher
	 */
	private Dispatcher $dispatcher;

	/**
	 * Sends deliveries.
	 *
	 * @var DeliveryWorker
	 */
	private DeliveryWorker $worker;

	/**
	 * Builds the object graph.
	 */
	private function __construct() {
		global $wpdb;

		$queue            = new ActionSchedulerQueue();
		$this->settings   = new Settings();
		$this->endpoints  = new EndpointRepository();
		$this->store      = new WpdbDeliveryStore( $wpdb );
		$this->dispatcher = new Dispatcher(
			$this->endpoints,
			PayloadFactory::create( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ),
			$this->store,
			$queue
		);
		$this->worker     = new DeliveryWorker(
			$this->store,
			$this->endpoints,
			new WpHttpTransport(),
			new RetryPolicy( $this->settings->max_attempts() ),
			$queue,
			$this->settings->timeout(),
			'WooWebhookRelay/' . self::VERSION
		);
	}

	/**
	 * Entry point, on `plugins_loaded`.
	 */
	public static function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'as_enqueue_async_action' ) ) {
			add_action( 'admin_notices', [ self::class, 'render_missing_woocommerce_notice' ] );
			return;
		}

		Schema::maybe_upgrade();
		( new self() )->register();
	}

	/**
	 * Deactivation hook: stops the cleanup job. Queued deliveries stay in
	 * Action Scheduler and their log rows stay as they are.
	 */
	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::CLEANUP_HOOK );
		}
	}

	/**
	 * Shown when WooCommerce is not active.
	 */
	public static function render_missing_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Webhook Relay for WooCommerce needs WooCommerce to be installed and active.', 'woo-webhook-relay' )
		);
	}

	/**
	 * Deletes finished deliveries older than the retention period.
	 */
	public function cleanup(): void {
		$before = gmdate( 'Y-m-d H:i:s', time() - $this->settings->retention_days() * DAY_IN_SECONDS );

		$this->store->delete_finished_before( $before );
	}

	/**
	 * Schedules the daily cleanup job if it is missing. Runs on admin
	 * requests only, to keep the check off the storefront.
	 */
	public function schedule_cleanup(): void {
		if ( ! as_has_scheduled_action( self::CLEANUP_HOOK ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::CLEANUP_HOOK, [], ActionSchedulerQueue::GROUP );
		}
	}

	/**
	 * Registers hooks.
	 */
	private function register(): void {
		( new EventListener( $this->endpoints, new EventFactory(), $this->dispatcher ) )->register();

		add_action( ActionSchedulerQueue::HOOK, [ $this->worker, 'handle' ] );
		add_action( self::CLEANUP_HOOK, [ $this, 'cleanup' ] );
		add_action( 'admin_init', [ $this, 'schedule_cleanup' ] );

		if ( is_admin() ) {
			( new AdminPage( $this->endpoints, $this->store, $this->dispatcher ) )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command(
				'webhook-relay',
				new Command( $this->endpoints, $this->store, $this->dispatcher, $this->worker, new EventFactory() )
			);
		}
	}
}

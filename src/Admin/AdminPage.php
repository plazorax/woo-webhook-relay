<?php
/**
 * WooCommerce > Webhook Relay admin screen.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Admin;

use WooWebhookRelay\Delivery\DeliveryStore;
use WooWebhookRelay\Delivery\Dispatcher;
use WooWebhookRelay\Endpoints\EndpointRepository;
use WooWebhookRelay\Endpoints\EndpointSanitizer;
use WooWebhookRelay\Plugin;
use WooWebhookRelay\Settings\Settings;

/**
 * Two tabs: "Endpoints" (a Settings API form) and "Deliveries" (the log).
 */
final class AdminPage {

	public const SLUG          = 'woo-webhook-relay';
	public const OPTION_GROUP  = 'woo_webhook_relay';
	public const REPLAY_ACTION = 'woo_webhook_relay_replay';

	/**
	 * Creates the page.
	 *
	 * @param EndpointRepository $endpoints  Endpoint settings.
	 * @param DeliveryStore      $store      Delivery log.
	 * @param Dispatcher         $dispatcher Replays deliveries.
	 */
	public function __construct(
		private readonly EndpointRepository $endpoints,
		private readonly DeliveryStore $store,
		private readonly Dispatcher $dispatcher,
	) {}

	/**
	 * Registers admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_post_' . self::REPLAY_ACTION, [ $this, 'handle_replay' ] );

		// options.php checks manage_options by default; shop managers should be able to save.
		add_filter( 'option_page_capability_' . self::OPTION_GROUP, static fn(): string => Plugin::CAPABILITY );
	}

	/**
	 * Adds the submenu under WooCommerce.
	 */
	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Webhook Relay', 'woo-webhook-relay' ),
			__( 'Webhook Relay', 'woo-webhook-relay' ),
			Plugin::CAPABILITY,
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	/**
	 * Registers both options with their sanitize callbacks.
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			EndpointRepository::OPTION,
			[
				'type'              => 'array',
				'default'           => [],
				'show_in_rest'      => false,
				'sanitize_callback' => [ $this, 'sanitize_endpoints' ],
			]
		);

		register_setting(
			self::OPTION_GROUP,
			Settings::OPTION,
			[
				'type'              => 'array',
				'default'           => [],
				'show_in_rest'      => false,
				'sanitize_callback' => [ Settings::class, 'sanitize' ],
			]
		);
	}

	/**
	 * Sanitize callback for the endpoints option.
	 *
	 * @param mixed $input Posted value.
	 * @return array<string, array<string, mixed>>
	 */
	public function sanitize_endpoints( mixed $input ): array {
		$result = ( new EndpointSanitizer() )->sanitize( $input, $this->endpoints->all() );

		foreach ( $result['errors'] as $index => $message ) {
			add_settings_error( EndpointRepository::OPTION, 'woo_webhook_relay_endpoint_' . $index, $message );
		}

		return $result['endpoints'];
	}

	/**
	 * Replays a delivery from the log (admin-post.php handler).
	 */
	public function handle_replay(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'woo-webhook-relay' ), 403 );
		}

		// The nonce action contains the delivery ID, so read the ID first.
		$delivery_id = Request::int( 'delivery' );
		check_admin_referer( self::REPLAY_ACTION . '_' . $delivery_id );

		$new_id = $this->dispatcher->replay( $delivery_id );

		wp_safe_redirect( self::url( 'deliveries', [ 'replayed' => $new_id ?? 0 ] ) );
		exit;
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'woo-webhook-relay' ), 403 );
		}

		$tab  = 'deliveries' === Request::key( 'tab' ) ? 'deliveries' : 'endpoints';
		$tabs = [
			'endpoints'  => __( 'Endpoints', 'woo-webhook-relay' ),
			'deliveries' => __( 'Deliveries', 'woo-webhook-relay' ),
		];

		echo '<div class="wrap"><h1>' . esc_html__( 'Webhook Relay', 'woo-webhook-relay' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( self::url( $key ) ),
				$key === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		if ( 'deliveries' === $tab ) {
			$this->render_deliveries();
		} else {
			// No filter: options.php adds "Settings saved." under the "general" key.
			settings_errors();
			( new EndpointsView( $this->endpoints->all(), ( new Settings() )->all() ) )->render( self::OPTION_GROUP );
		}

		echo '</div>';
	}

	/**
	 * URL of a tab.
	 *
	 * @param string               $tab   Tab key.
	 * @param array<string, mixed> $extra Extra query arguments.
	 */
	public static function url( string $tab, array $extra = [] ): string {
		return add_query_arg(
			[
				'page' => self::SLUG,
				'tab'  => $tab,
			] + $extra,
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Renders the delivery log tab.
	 */
	private function render_deliveries(): void {
		// Display-only flag set by handle_replay().
		if ( '' !== Request::key( 'replayed' ) ) {
			$replayed = Request::int( 'replayed' );

			if ( $replayed > 0 ) {
				/* translators: %d: new delivery ID. */
				$message = sprintf( __( 'Delivery #%d queued.', 'woo-webhook-relay' ), $replayed );
				printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
			} else {
				printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'That delivery no longer exists.', 'woo-webhook-relay' ) );
			}
		}

		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		$names = array_map( static fn( $endpoint ): string => $endpoint->name, $this->endpoints->all() );
		$table = new DeliveriesListTable( $this->store, $names );
		$table->prepare_items();

		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::SLUG ) );
		echo '<input type="hidden" name="tab" value="deliveries" />';
		$table->views();
		$table->display();
		echo '</form>';
	}
}

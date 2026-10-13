<?php
/**
 * Delivery log list table.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Admin;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WP_List_Table;
use WooWebhookRelay\Delivery\Delivery;
use WooWebhookRelay\Delivery\DeliveryStatus;
use WooWebhookRelay\Delivery\DeliveryStore;

/**
 * Lists deliveries newest first, filterable by status, with Retry / Replay
 * row actions protected by a per-row nonce.
 */
final class DeliveriesListTable extends WP_List_Table {

	private const PER_PAGE = 20;

	/**
	 * Creates the table.
	 *
	 * @param DeliveryStore         $store          Delivery log.
	 * @param array<string, string> $endpoint_names Endpoint names keyed by ID.
	 */
	public function __construct(
		private readonly DeliveryStore $store,
		private readonly array $endpoint_names,
	) {
		parent::__construct(
			[
				'singular' => 'delivery',
				'plural'   => 'deliveries',
				'ajax'     => false,
			]
		);
	}

	/**
	 * Column headers.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return [
			'id'         => __( 'Delivery', 'woo-webhook-relay' ),
			'created_at' => __( 'Created', 'woo-webhook-relay' ),
			'event'      => __( 'Event', 'woo-webhook-relay' ),
			'endpoint'   => __( 'Endpoint', 'woo-webhook-relay' ),
			'status'     => __( 'Status', 'woo-webhook-relay' ),
			'response'   => __( 'Response', 'woo-webhook-relay' ),
			'attempts'   => __( 'Attempts', 'woo-webhook-relay' ),
			'details'    => __( 'Details', 'woo-webhook-relay' ),
		];
	}

	/**
	 * Loads the current page of deliveries.
	 */
	public function prepare_items(): void {
		$filters = [];
		$status  = $this->current_status();

		if ( null !== $status ) {
			$filters['status'] = $status->value;
		}

		$total       = $this->store->count( $filters );
		$this->items = $this->store->query( $filters, self::PER_PAGE, ( $this->get_pagenum() - 1 ) * self::PER_PAGE );

		$this->_column_headers = [ $this->get_columns(), [], [], 'id' ];
		$this->set_pagination_args(
			[
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			]
		);
	}

	/**
	 * Message when the log is empty.
	 */
	public function no_items(): void {
		esc_html_e( 'No deliveries yet.', 'woo-webhook-relay' );
	}

	/**
	 * Status filter links.
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$current = $this->current_status();
		$views   = [
			'all' => sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( AdminPage::url( 'deliveries' ) ),
				null === $current ? ' class="current" aria-current="page"' : '',
				esc_html__( 'All', 'woo-webhook-relay' ),
				$this->store->count( [] )
			),
		];

		foreach ( DeliveryStatus::cases() as $status ) {
			$views[ $status->value ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( AdminPage::url( 'deliveries', [ 'status' => $status->value ] ) ),
				$current === $status ? ' class="current" aria-current="page"' : '',
				esc_html( $status->label() ),
				$this->store->count( [ 'status' => $status->value ] )
			);
		}

		return $views;
	}

	/**
	 * Delivery ID with row actions.
	 *
	 * @param Delivery $item Delivery.
	 */
	public function column_id( Delivery $item ): string {
		$output = '<strong>#' . esc_html( (string) $item->id ) . '</strong>';

		if ( null !== $item->replay_of ) {
			/* translators: %d: original delivery ID. */
			$output .= '<br /><small>' . esc_html( sprintf( __( 'replay of #%d', 'woo-webhook-relay' ), $item->replay_of ) ) . '</small>';
		}

		if ( $item->status->is_final() ) {
			$url   = wp_nonce_url(
				add_query_arg(
					[
						'action'   => AdminPage::REPLAY_ACTION,
						'delivery' => $item->id,
					],
					admin_url( 'admin-post.php' )
				),
				AdminPage::REPLAY_ACTION . '_' . $item->id
			);
			$label = DeliveryStatus::Failed === $item->status ? __( 'Retry', 'woo-webhook-relay' ) : __( 'Replay', 'woo-webhook-relay' );

			$output .= $this->row_actions( [ 'replay' => sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $label ) ) ] );
		}

		return $output;
	}

	/**
	 * Other columns.
	 *
	 * @param Delivery $item        Delivery.
	 * @param string   $column_name Column key.
	 */
	protected function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'created_at':
				return esc_html( get_date_from_gmt( $item->created_at, 'Y-m-d H:i:s' ) );

			case 'event':
				return '<code>' . esc_html( $item->event ) . '</code><br />' . $this->resource_link( $item );

			case 'endpoint':
				return esc_html( $this->endpoint_names[ $item->endpoint_id ] ?? $item->endpoint_id );

			case 'status':
				$status = esc_html( $item->status->label() );

				if ( null !== $item->next_attempt_at ) {
					/* translators: %s: date and time. */
					$status .= '<br /><small>' . esc_html( sprintf( __( 'next: %s', 'woo-webhook-relay' ), get_date_from_gmt( $item->next_attempt_at, 'Y-m-d H:i:s' ) ) ) . '</small>';
				}

				return $status;

			case 'response':
				return null === $item->response_code ? '&mdash;' : esc_html( (string) $item->response_code );

			case 'attempts':
				return esc_html( (string) $item->attempts );

			case 'details':
				return $this->details( $item );

			default:
				return '';
		}
	}

	/**
	 * Error, response body and payload in collapsible blocks.
	 *
	 * @param Delivery $item Delivery.
	 */
	private function details( Delivery $item ): string {
		$output = '' === $item->error ? '' : '<p>' . esc_html( $item->error ) . '</p>';

		if ( '' !== $item->response_body ) {
			$output .= sprintf(
				'<details><summary>%s</summary><pre style="white-space:pre-wrap;max-width:40em">%s</pre></details>',
				esc_html__( 'Response body', 'woo-webhook-relay' ),
				esc_html( $item->response_body )
			);
		}

		$decoded = json_decode( $item->payload, true );
		$pretty  = null === $decoded ? $item->payload : (string) wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return $output . sprintf(
			'<details><summary>%s</summary><pre style="white-space:pre-wrap;max-width:40em;max-height:30em;overflow:auto">%s</pre></details>',
			esc_html__( 'Payload', 'woo-webhook-relay' ),
			esc_html( $pretty )
		);
	}

	/**
	 * Link to the order or user the event is about.
	 *
	 * @param Delivery $item Delivery.
	 */
	private function resource_link( Delivery $item ): string {
		if ( $item->resource_id <= 0 ) {
			return '<small>' . esc_html__( 'test event', 'woo-webhook-relay' ) . '</small>';
		}

		$is_order = str_starts_with( $item->event, 'order.' );
		$url      = $is_order
			? OrderUtil::get_order_admin_edit_url( $item->resource_id )
			: admin_url( 'user-edit.php?user_id=' . $item->resource_id );
		$label    = ( $is_order ? __( 'Order', 'woo-webhook-relay' ) : __( 'Customer', 'woo-webhook-relay' ) ) . ' #' . $item->resource_id;

		return sprintf( '<small><a href="%s">%s</a></small>', esc_url( $url ), esc_html( $label ) );
	}

	/**
	 * Status selected in the views, if valid.
	 */
	private function current_status(): ?DeliveryStatus {
		return DeliveryStatus::tryFrom( Request::key( 'status' ) );
	}
}

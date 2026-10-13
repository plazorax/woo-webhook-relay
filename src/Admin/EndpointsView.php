<?php
/**
 * The endpoints and delivery settings form.
 *
 * @package WooWebhookRelay
 */

declare(strict_types=1);

namespace WooWebhookRelay\Admin;

use WooWebhookRelay\Endpoints\Endpoint;
use WooWebhookRelay\Endpoints\EndpointRepository;
use WooWebhookRelay\Endpoints\EndpointSanitizer;
use WooWebhookRelay\Events\EventType;
use WooWebhookRelay\Payload\PayloadFormat;
use WooWebhookRelay\Settings\Settings;

/**
 * Posts to options.php. Saved secrets are never printed back into the page.
 */
final class EndpointsView {

	/**
	 * Creates the view.
	 *
	 * @param array<string, Endpoint> $endpoints Stored endpoints.
	 * @param array<string, int>      $settings  Global settings.
	 */
	public function __construct(
		private readonly array $endpoints,
		private readonly array $settings,
	) {}

	/**
	 * Prints the form.
	 *
	 * @param string $option_group Settings API option group.
	 */
	public function render( string $option_group ): void {
		echo '<form method="post" action="options.php">';
		settings_fields( $option_group );

		foreach ( $this->endpoints as $endpoint ) {
			printf( '<h2>%s</h2>', esc_html( $endpoint->name ) );
			$this->render_endpoint( $endpoint->id, $endpoint );
		}

		echo '<h2>' . esc_html__( 'Add endpoint', 'woo-webhook-relay' ) . '</h2>';
		$this->render_endpoint( EndpointSanitizer::NEW_ROW, null );

		echo '<h2>' . esc_html__( 'Delivery', 'woo-webhook-relay' ) . '</h2>';
		$this->render_settings();

		submit_button();
		echo '</form>';
	}

	/**
	 * Fields for one endpoint.
	 *
	 * @param string        $key      Array key in the posted option (endpoint ID or "new").
	 * @param Endpoint|null $endpoint Stored endpoint, or null for the "add" row.
	 */
	private function render_endpoint( string $key, ?Endpoint $endpoint ): void {
		$name = static fn( string $field ): string => EndpointRepository::OPTION . '[' . $key . '][' . $field . ']';
		$id   = static fn( string $field ): string => 'wwr-' . $key . '-' . $field;

		echo '<table class="form-table" role="presentation"><tbody>';

		if ( null !== $endpoint ) {
			$this->row( __( 'ID', 'woo-webhook-relay' ), '', '<code>' . esc_html( $endpoint->id ) . '</code>' );
		}

		$this->row(
			__( 'Name', 'woo-webhook-relay' ),
			$id( 'name' ),
			sprintf(
				'<input type="text" class="regular-text" id="%s" name="%s" value="%s" />',
				esc_attr( $id( 'name' ) ),
				esc_attr( $name( 'name' ) ),
				esc_attr( $endpoint->name ?? '' )
			)
		);

		$this->row(
			__( 'URL', 'woo-webhook-relay' ),
			$id( 'url' ),
			sprintf(
				'<input type="url" class="large-text code" id="%s" name="%s" value="%s" placeholder="https://" />',
				esc_attr( $id( 'url' ) ),
				esc_attr( $name( 'url' ) ),
				esc_attr( $endpoint->url ?? '' )
			)
		);

		$secret_help = null !== $endpoint && $endpoint->is_signed()
			? __( 'A secret is saved. Leave blank to keep it.', 'woo-webhook-relay' )
			: sprintf(
				/* translators: %d: minimum secret length. */
				__( 'At least %d characters, e.g. the output of "openssl rand -hex 32". Required for the full and slim formats.', 'woo-webhook-relay' ),
				EndpointSanitizer::MIN_SECRET_LENGTH
			);

		$this->row(
			__( 'Secret', 'woo-webhook-relay' ),
			$id( 'secret' ),
			sprintf(
				'<input type="password" class="regular-text code" id="%s" name="%s" value="" autocomplete="new-password" /><p class="description">%s</p>',
				esc_attr( $id( 'secret' ) ),
				esc_attr( $name( 'secret' ) ),
				esc_html( $secret_help )
			)
		);

		$checkboxes = '';
		foreach ( EventType::labels() as $event => $label ) {
			$checkboxes .= sprintf(
				'<label><input type="checkbox" name="%s[]" value="%s"%s /> %s <code>%s</code></label><br />',
				esc_attr( $name( 'events' ) ),
				esc_attr( $event ),
				checked( null !== $endpoint && in_array( $event, $endpoint->events, true ), true, false ),
				esc_html( $label ),
				esc_html( $event )
			);
		}
		$this->row( __( 'Events', 'woo-webhook-relay' ), '', '<fieldset>' . $checkboxes . '</fieldset>' );

		$options = '';
		foreach ( PayloadFormat::cases() as $format ) {
			$options .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $format->value ),
				selected( ( $endpoint->format ?? PayloadFormat::Full )->value, $format->value, false ),
				esc_html( $format->label() )
			);
		}
		$this->row(
			__( 'Payload format', 'woo-webhook-relay' ),
			$id( 'format' ),
			sprintf( '<select id="%s" name="%s">%s</select>', esc_attr( $id( 'format' ) ), esc_attr( $name( 'format' ) ), $options )
		);

		$this->row(
			__( 'Status', 'woo-webhook-relay' ),
			'',
			sprintf(
				'<label><input type="checkbox" name="%s" value="1"%s /> %s</label>',
				esc_attr( $name( 'enabled' ) ),
				checked( $endpoint->enabled ?? true, true, false ),
				esc_html__( 'Enabled', 'woo-webhook-relay' )
			)
		);

		if ( null !== $endpoint ) {
			$this->row(
				__( 'Delete', 'woo-webhook-relay' ),
				'',
				sprintf(
					'<label><input type="checkbox" name="%s" value="1" /> %s</label>',
					esc_attr( $name( 'delete' ) ),
					esc_html__( 'Delete this endpoint when saving', 'woo-webhook-relay' )
				)
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Global delivery settings.
	 */
	private function render_settings(): void {
		$fields = [
			'max_attempts'   => __( 'Max attempts', 'woo-webhook-relay' ),
			'timeout'        => __( 'Timeout (seconds)', 'woo-webhook-relay' ),
			'retention_days' => __( 'Keep log for (days)', 'woo-webhook-relay' ),
		];

		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( $fields as $key => $label ) {
			$rule = Settings::SCHEMA[ $key ];
			$this->row(
				$label,
				'wwr-setting-' . $key,
				sprintf(
					'<input type="number" class="small-text" id="%s" name="%s" value="%d" min="%d" max="%d" />',
					esc_attr( 'wwr-setting-' . $key ),
					esc_attr( Settings::OPTION . '[' . $key . ']' ),
					(int) ( $this->settings[ $key ] ?? $rule['default'] ),
					(int) $rule['min'],
					(int) $rule['max']
				)
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * One form-table row. `$control` must already be escaped.
	 *
	 * @param string $label    Row label.
	 * @param string $field_id ID of the labelled control, or "" for none.
	 * @param string $control  Escaped HTML.
	 */
	private function row( string $label, string $field_id, string $control ): void {
		$label_html = '' === $field_id
			? esc_html( $label )
			: sprintf( '<label for="%s">%s</label>', esc_attr( $field_id ), esc_html( $label ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both parts are escaped above.
		printf( '<tr><th scope="row">%s</th><td>%s</td></tr>', $label_html, $control );
	}
}

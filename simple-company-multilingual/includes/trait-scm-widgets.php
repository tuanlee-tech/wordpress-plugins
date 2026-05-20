<?php
/**
 * Widget visibility module.
 *
 * @package Simple_Company_Multilingual
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait SCM_Widgets {
	/**
	 * Filter frontend widget display by current language.
	 *
	 * Returning false prevents WordPress from rendering the widget.
	 *
	 * @param array     $instance Widget instance settings.
	 * @param WP_Widget $widget   Widget object.
	 * @param array     $args     Display args.
	 * @return array|false
	 */
	public function filter_widget_display_by_language( $instance, $widget, $args ) {
		if ( is_admin() || ! $widget instanceof WP_Widget ) {
			return $instance;
		}

		$rules = $this->get_widget_language_visibility_rules( $widget->id );

		/* No rule means show for all languages. */
		if ( empty( $rules ) || in_array( 'all', $rules, true ) ) {
			return $instance;
		}

		$current_language = $this->get_current_language_context();

		if ( in_array( $current_language, $rules, true ) ) {
			return $instance;
		}

		return false;
	}

	/**
	 * Render language visibility controls inside widget admin form.
	 *
	 * @param WP_Widget $widget   Widget object.
	 * @param null      $return   Return value.
	 * @param array     $instance Widget instance.
	 * @return void
	 */
	public function render_widget_language_visibility_fields( $widget, $return, $instance ) {
		if ( ! $widget instanceof WP_Widget ) {
			return;
		}

		$active = $this->get_active_languages();
		$rules  = $this->get_widget_language_visibility_rules( $widget->id );

		if ( empty( $rules ) ) {
			$rules = array( 'all' );
		}
		?>

		<div class="scm-widget-visibility">
			<p><strong><?php esc_html_e( 'Company Multilingual Visibility', 'simple-company-multilingual' ); ?></strong></p>
			<p class="description">
				<?php esc_html_e( 'Choose which languages should display this widget. Leave as All languages for normal behavior.', 'simple-company-multilingual' ); ?>
			</p>

			<p>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $this->get_widget_visibility_field_name( $widget, 'all' ) ); ?>" value="1" <?php checked( in_array( 'all', $rules, true ) ); ?> />
					<?php esc_html_e( 'All languages', 'simple-company-multilingual' ); ?>
				</label>
			</p>

			<?php foreach ( $active as $locale => $language ) : ?>
				<p>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $this->get_widget_visibility_field_name( $widget, $locale ) ); ?>" value="1" <?php checked( in_array( $locale, $rules, true ) ); ?> />
						<?php echo esc_html( $this->format_language_label_text( $locale, $language ) ); ?>
					</label>
				</p>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Save language visibility fields from widget form.
	 *
	 * @param array     $instance     New widget instance.
	 * @param array     $new_instance Raw new instance.
	 * @param array     $old_instance Old instance.
	 * @param WP_Widget $widget       Widget object.
	 * @return array
	 */
	public function save_widget_language_visibility_fields( $instance, $new_instance, $old_instance, $widget ) {
		if ( ! $widget instanceof WP_Widget ) {
			return $instance;
		}

		$active = $this->get_active_languages();
		$rules  = array();

		if ( isset( $_POST[ $this->get_widget_visibility_field_key( $widget, 'all' ) ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$rules[] = 'all';
		}

		foreach ( $active as $locale => $language ) {
			$field_key = $this->get_widget_visibility_field_key( $widget, $locale );

			if ( isset( $_POST[ $field_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$rules[] = sanitize_key( $locale );
			}
		}

		/* Empty selection should behave as All languages to avoid accidentally hiding widgets. */
		if ( empty( $rules ) ) {
			$rules = array( 'all' );
		}

		$this->save_widget_language_visibility_rules( $widget->id, $rules );

		return $instance;
	}

	/**
	 * Get visibility rules for a widget id.
	 *
	 * @param string $widget_id Widget ID.
	 * @return string[]
	 */
	private function get_widget_language_visibility_rules( $widget_id ) {
		$widget_id = sanitize_key( $widget_id );
		$settings  = $this->get_settings();

		if ( '' === $widget_id || empty( $settings['widget_visibility'][ $widget_id ] ) || ! is_array( $settings['widget_visibility'][ $widget_id ] ) ) {
			return array();
		}

		$rules = array_map( 'sanitize_key', $settings['widget_visibility'][ $widget_id ] );
		$rules = array_values( array_unique( array_filter( $rules ) ) );

		return $rules;
	}

	/**
	 * Save visibility rules for a widget id.
	 *
	 * @param string   $widget_id Widget ID.
	 * @param string[] $rules     Rules.
	 * @return void
	 */
	private function save_widget_language_visibility_rules( $widget_id, $rules ) {
		$widget_id = sanitize_key( $widget_id );

		if ( '' === $widget_id ) {
			return;
		}

		$settings = $this->get_settings();
		$rules    = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $rules ) ) ) );

		if ( empty( $rules ) ) {
			$rules = array( 'all' );
		}

		$settings['widget_visibility'][ $widget_id ] = $rules;

		update_option( self::OPTION_KEY, $settings );
	}

	/**
	 * Get widget visibility field name.
	 *
	 * @param WP_Widget $widget Widget object.
	 * @param string    $locale Locale or all.
	 * @return string
	 */
	private function get_widget_visibility_field_name( $widget, $locale ) {
		return $this->get_widget_visibility_field_key( $widget, $locale );
	}

	/**
	 * Get widget visibility field key.
	 *
	 * @param WP_Widget $widget Widget object.
	 * @param string    $locale Locale or all.
	 * @return string
	 */
	private function get_widget_visibility_field_key( $widget, $locale ) {
		return 'scm_widget_visibility_' . sanitize_key( $widget->id ) . '_' . sanitize_key( $locale );
	}

	/**
	 * Filter frontend block widget rendering by language.
	 *
	 * @param string $block_content Rendered block content.
	 * @param array  $block         Parsed block data.
	 * @return string
	 */
	public function filter_block_widget_display_by_language( $block_content, $block ) {
		if ( is_admin() || empty( $block['attrs']['scmVisibilityLanguages'] ) || ! is_array( $block['attrs']['scmVisibilityLanguages'] ) ) {
			return $block_content;
		}

		$rules = array_values( array_unique( array_filter( array_map( 'sanitize_key', $block['attrs']['scmVisibilityLanguages'] ) ) ) );

		if ( empty( $rules ) || in_array( 'all', $rules, true ) ) {
			return $block_content;
		}

		$current_language = $this->get_current_language_context();

		if ( in_array( $current_language, $rules, true ) ) {
			return $block_content;
		}

		return '';
	}

	/**
	 * Enqueue block editor assets for widget/block visibility.
	 *
	 * @return void
	 */
	public function enqueue_block_widget_visibility_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! in_array( $screen->id, array( 'widgets', 'appearance_page_gutenberg-widgets' ), true ) ) {
			return;
		}

		$active = $this->get_active_languages();
		$languages = array();

		foreach ( $active as $locale => $language ) {
			$languages[] = array(
				'locale' => sanitize_key( $locale ),
				'label'  => $this->format_language_label_text( $locale, $language ),
			);
		}

		wp_enqueue_script(
			'scm-block-widget-visibility',
			SCM_PLUGIN_URL . 'assets/block-widget-visibility.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-compose', 'wp-hooks', 'wp-block-editor', 'wp-i18n' ),
			SCM_PLUGIN_VERSION,
			true
		);

		wp_localize_script(
			'scm-block-widget-visibility',
			'scmBlockWidgetVisibility',
			array(
				'languages' => $languages,
				'i18n'      => array(
					'title'        => __( 'Company Multilingual Visibility', 'simple-company-multilingual' ),
					'description'  => __( 'Choose which languages should display this block widget.', 'simple-company-multilingual' ),
					'allLanguages' => __( 'All languages', 'simple-company-multilingual' ),
				),
			)
		);
	}
}
<?php
/**
 * Theme element overrides module.
 *
 * @package Simple_Company_Multilingual
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait SCM_Theme_Element_Overrides {
	/**
	 * Enqueue frontend script for theme element overrides.
	 *
	 * @return void
	 */
	public function enqueue_theme_element_override_script() {
		if ( is_admin() ) {
			return;
		}

		$settings  = $this->get_settings();
		$overrides = isset( $settings['theme_element_overrides'] ) && is_array( $settings['theme_element_overrides'] ) ? $settings['theme_element_overrides'] : array();

		if ( empty( $overrides ) ) {
			return;
		}

		$current_language = $this->get_current_language_context();
		$payload          = array();

		foreach ( $overrides as $key => $override ) {
			$key = sanitize_key( $key );

			if ( '' === $key || empty( $override['selector'] ) || empty( $override['translations'][ $current_language ] ) ) {
				continue;
			}

			$selector = sanitize_text_field( $override['selector'] );
			$mode     = isset( $override['mode'] ) && 'html' === $override['mode'] ? 'html' : 'text';
			$value    = (string) $override['translations'][ $current_language ];

			$payload[] = array(
				'key'      => $key,
				'selector' => $selector,
				'mode'     => $mode,
				'value'    => $value,
			);
		}

		if ( empty( $payload ) ) {
			return;
		}

		wp_enqueue_script(
			'scm-theme-element-overrides',
			SCM_PLUGIN_URL . 'assets/theme-element-overrides.js',
			array(),
			SCM_PLUGIN_VERSION,
			true
		);

		wp_localize_script(
			'scm-theme-element-overrides',
			'scmThemeElementOverrides',
			array(
				'items' => $payload,
			)
		);
	}

	/**
	 * Render settings UI.
	 *
	 * @return void
	 */
	private function render_theme_element_overrides_settings() {
		$settings  = $this->get_settings();
		$active    = $this->get_active_languages();
		$overrides = isset( $settings['theme_element_overrides'] ) && is_array( $settings['theme_element_overrides'] ) ? $settings['theme_element_overrides'] : array();
		?>

		<p class="description">
			<?php esc_html_e( 'Override hardcoded theme or builder elements by CSS selector. Use this only when Page/Post translation, Menu by Language, Widget Visibility or String Overrides cannot handle the text.', 'simple-company-multilingual' ); ?>
		</p>

		<div class="scm-add-language-box">
			<h3><?php esc_html_e( 'Add Element Override', 'simple-company-multilingual' ); ?></h3>
			<div class="scm-add-language-row" style="grid-template-columns:1fr 2fr 120px auto;">
				<p>
					<label><?php esc_html_e( 'Key', 'simple-company-multilingual' ); ?></label>
					<input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[new_theme_element_override][key]" placeholder="header_cta" />
				</p>
				<p>
					<label><?php esc_html_e( 'CSS Selector', 'simple-company-multilingual' ); ?></label>
					<input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[new_theme_element_override][selector]" placeholder=".site-header .cta-button" />
				</p>
				<p>
					<label><?php esc_html_e( 'Mode', 'simple-company-multilingual' ); ?></label>
					<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[new_theme_element_override][mode]">
						<option value="text"><?php esc_html_e( 'Text', 'simple-company-multilingual' ); ?></option>
						<option value="html"><?php esc_html_e( 'HTML', 'simple-company-multilingual' ); ?></option>
					</select>
				</p>
				<p class="scm-add-language-action">
					<?php submit_button( __( 'Add Override', 'simple-company-multilingual' ), 'secondary', 'scm_add_theme_element_override', false ); ?>
				</p>
			</div>
			<p class="description">
				<?php esc_html_e( 'Example selector: .site-header .cta-button. Prefer unique selectors to avoid replacing multiple unrelated elements.', 'simple-company-multilingual' ); ?>
			</p>
		</div>

		<?php if ( empty( $overrides ) ) : ?>
			<p><?php esc_html_e( 'No theme element overrides yet.', 'simple-company-multilingual' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<div class="scm-table-scroll">
			<table class="widefat striped scm-language-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Key', 'simple-company-multilingual' ); ?></th>
						<th><?php esc_html_e( 'Selector', 'simple-company-multilingual' ); ?></th>
						<th><?php esc_html_e( 'Mode', 'simple-company-multilingual' ); ?></th>
						<?php foreach ( $active as $locale => $language ) : ?>
							<th><?php echo esc_html( $this->format_language_label_text( $locale, $language ) ); ?></th>
						<?php endforeach; ?>
						<th><?php esc_html_e( 'Actions', 'simple-company-multilingual' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $overrides as $key => $override ) : ?>
						<?php
						$key          = sanitize_key( $key );
						$selector     = isset( $override['selector'] ) ? sanitize_text_field( $override['selector'] ) : '';
						$mode         = isset( $override['mode'] ) && 'html' === $override['mode'] ? 'html' : 'text';
						$translations = isset( $override['translations'] ) && is_array( $override['translations'] ) ? $override['translations'] : array();
						?>
						<tr>
							<td><code><?php echo esc_html( $key ); ?></code></td>
							<td>
								<input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[theme_element_overrides][<?php echo esc_attr( $key ); ?>][selector]" value="<?php echo esc_attr( $selector ); ?>" />
							</td>
							<td>
								<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[theme_element_overrides][<?php echo esc_attr( $key ); ?>][mode]">
									<option value="text" <?php selected( $mode, 'text' ); ?>><?php esc_html_e( 'Text', 'simple-company-multilingual' ); ?></option>
									<option value="html" <?php selected( $mode, 'html' ); ?>><?php esc_html_e( 'HTML', 'simple-company-multilingual' ); ?></option>
								</select>
							</td>

							<?php foreach ( $active as $locale => $language ) : ?>
								<?php $value = isset( $translations[ $locale ] ) ? $translations[ $locale ] : ''; ?>
								<td>
									<textarea rows="2" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[theme_element_overrides][<?php echo esc_attr( $key ); ?>][translations][<?php echo esc_attr( $locale ); ?>]"><?php echo esc_textarea( $value ); ?></textarea>
								</td>
							<?php endforeach; ?>

							<td>
								<button type="submit" class="button button-small scm-button-delete" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[delete_theme_element_override]" value="<?php echo esc_attr( $key ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this element override?', 'simple-company-multilingual' ) ); ?>');">
									<?php esc_html_e( 'Delete', 'simple-company-multilingual' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
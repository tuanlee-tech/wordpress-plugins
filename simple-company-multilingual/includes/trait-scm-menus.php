<?php
/**
 * Menu translation module.
 *
 * @package Simple_Company_Multilingual
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait SCM_Menus {
	/**
	 * Replace theme location menu by current language mapping.
	 *
	 * @param array $args wp_nav_menu args.
	 * @return array
	 */
	public function filter_nav_menu_by_language( $args ) {
		if ( is_admin() || empty( $args['theme_location'] ) ) {
			return $args;
		}

		$settings       = $this->get_settings();
		$theme_location = sanitize_key( $args['theme_location'] );

		if ( empty( $settings['menu_mappings'][ $theme_location ] ) || ! is_array( $settings['menu_mappings'][ $theme_location ] ) ) {
			return $args;
		}

		$current_language = $this->get_current_language_context();
		$default_language = $this->get_default_language();
		$mappings         = $settings['menu_mappings'][ $theme_location ];
		$menu_id          = 0;

		if ( ! empty( $mappings[ $current_language ] ) ) {
			$menu_id = absint( $mappings[ $current_language ] );
		} elseif ( ! empty( $mappings[ $default_language ] ) ) {
			$menu_id = absint( $mappings[ $default_language ] );
		}

		if ( $menu_id <= 0 ) {
			return $args;
		}

		$menu = wp_get_nav_menu_object( $menu_id );

		if ( ! $menu ) {
			return $args;
		}

		$args['menu'] = $menu_id;

		return $args;
	}

	/**
	 * Render menu mapping settings.
	 *
	 * @return void
	 */
	private function render_menu_mapping_settings() {
		$settings  = $this->get_settings();
		$active    = $this->get_active_languages();
		$locations = get_registered_nav_menus();
		$menus     = wp_get_nav_menus( array( 'hide_empty' => false ) );

		if ( empty( $locations ) ) :
			?>
			<p class="description">
				<?php esc_html_e( 'No registered menu locations found for the current theme.', 'simple-company-multilingual' ); ?>
			</p>
			<?php
			return;
		endif;
		?>

		<p class="description">
			<?php esc_html_e( 'Map each theme menu location to a different WordPress menu per language. If a language has no menu selected, the default language menu is used as fallback.', 'simple-company-multilingual' ); ?>
		</p>

		<div class="scm-table-scroll">
			<table class="widefat striped scm-language-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Theme Location', 'simple-company-multilingual' ); ?></th>
						<?php foreach ( $active as $locale => $language ) : ?>
							<th><?php echo esc_html( $this->format_language_label_text( $locale, $language ) ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $locations as $location_key => $location_label ) : ?>
						<?php $location_key = sanitize_key( $location_key ); ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $location_label ); ?></strong><br />
								<code><?php echo esc_html( $location_key ); ?></code>
							</td>

							<?php foreach ( $active as $locale => $language ) : ?>
								<?php
								$selected_menu = isset( $settings['menu_mappings'][ $location_key ][ $locale ] ) ? absint( $settings['menu_mappings'][ $location_key ][ $locale ] ) : 0;
								?>
								<td>
									<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[menu_mappings][<?php echo esc_attr( $location_key ); ?>][<?php echo esc_attr( $locale ); ?>]">
										<option value="0"><?php esc_html_e( '— Theme default —', 'simple-company-multilingual' ); ?></option>
										<?php foreach ( $menus as $menu ) : ?>
											<option value="<?php echo esc_attr( $menu->term_id ); ?>" <?php selected( $selected_menu, $menu->term_id ); ?>>
												<?php echo esc_html( $menu->name ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
<?php
/**
 * SCM Core trait.
 *
 * @package Simple_Company_Multilingual
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait SCM_Core {
/**
		 * Load textdomain.
		 *
		 * @return void
		 */
		public function load_textdomain() {
			load_plugin_textdomain(
				'simple-company-multilingual',
				false,
				dirname( plugin_basename( SCM_PLUGIN_FILE ) ) . '/languages'
			);
		}

/**
		 * Add Settings link on Plugins screen.
		 *
		 * @param array $links Links.
		 * @return array
		 */
		public function add_plugin_action_links( $links ) {
			array_unshift(
				$links,
				sprintf(
					'<a href="%1$s">%2$s</a>',
					esc_url( admin_url( 'options-general.php?page=simple-company-multilingual' ) ),
					esc_html__( 'Settings', 'simple-company-multilingual' )
				)
			);

			return $links;
		}

/**
		 * Default settings.
		 *
		 * @return array
		 */
		private function get_default_settings() {
			return array(
				'default_language'              	=> '',
				'languages'                     	=> array(),
				'custom_languages'              	=> array(),
				'menu_mappings' 									=> array(),
				'widget_visibility'             	=> array(),
				'theme_element_overrides' 				=> array(),
				'auto_append_switcher'           	=> 'yes',
				'floating_switcher'              	=> 'no',
				'floating_position'              	=> 'bottom-right',
				'filter_frontend_lists'          	=> 'yes',
				'auto_create_translation_drafts' 	=> 'no',
				'delete_data_on_uninstall'       	=> 'no',
				'string_overrides'              	=> array(),
				'string_sources'                	=> array(),
			);
		}

/**
		 * Get settings.
		 *
		 * @return array
		 */
		public function get_settings() {
			$settings = get_option( self::OPTION_KEY, array() );

			if ( ! is_array( $settings ) ) {
				$settings = array();
			}

			return wp_parse_args( $settings, $this->get_default_settings() );
		}

/**
		 * Public helper: active languages.
		 *
		 * @return array
		 */
		public static function languages() {
			return self::instance()->get_active_languages();
		}

/**
		 * Public helper: translation ID.
		 *
		 * @param int    $post_id Post ID.
		 * @param string $locale Locale.
		 * @return int
		 */
		public static function get_translation_id( $post_id, $locale ) {
			$instance = self::instance();
			$group    = $instance->get_translation_group( $post_id );
			$locale   = sanitize_key( $locale );

			return isset( $group[ $locale ] ) ? absint( $group[ $locale ] ) : 0;
		}

/**
		 * Public helper: translation URL.
		 *
		 * @param int    $post_id Post ID.
		 * @param string $locale Locale.
		 * @return string
		 */
		public static function get_translation_url( $post_id, $locale ) {
			$target_id = self::get_translation_id( $post_id, $locale );

			if ( $target_id <= 0 ) {
				return '';
			}

			$url = get_permalink( $target_id );

			return is_string( $url ) ? $url : '';
		}
}

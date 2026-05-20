<?php
/**
 * Main plugin class.
 *
 * @package Simple_Company_Multilingual
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Simple_Company_Multilingual' ) ) {
	/**
	 * Main plugin class.
	 */

	final class Simple_Company_Multilingual {
		private const OPTION_KEY      = 'scm_settings';
		private const META_LANGUAGE   = '_scm_language';
		private const META_GROUP      = '_scm_translation_group';
		private const META_URL_SLUG   = '_scm_translation_url_slug';
		private const NONCE_META      = 'scm_meta_nonce';
		private const ACTION_CREATE   = 'scm_create_translation';
		private const SUPPORTED_TYPES = array( 'post', 'page' );
		private const FRONTEND_STYLE  = 'scm-frontend';
		private const ADMIN_STYLE     = 'scm-admin';
		private const ADMIN_SCRIPT    = 'scm-admin';
		

		/**
		 * Singleton instance.
		 *
		 * @var Simple_Company_Multilingual|null
		 */
		private static $instance = null;

		/**
		 * Cached language catalog.
		 *
		 * @var array|null
		 */
		private $language_catalog = null;

		/**
		 * Guard to prevent recursive draft generation.
		 *
		 * @var bool
		 */
		private $is_creating_translation = false;

		/**
		 * Guard against recursive locale filtering.
		 *
		 * @var bool
		 */
		private $is_filtering_locale = false;

		use SCM_Core;
		use SCM_Languages;
		use SCM_Locale;
		use SCM_String_Overrides;
		use SCM_Settings;
		use SCM_Post_Translations;
		use SCM_URL_Router;
		use SCM_Frontend;
		use SCM_SEO;
		use SCM_Menus;
		use SCM_Widgets;
		use SCM_Special_Pages;
		use SCM_Theme_Element_Overrides;

		/**
		 * Get singleton instance.
		 *
		 * @return Simple_Company_Multilingual
		 */
		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Constructor.
		 */
		private function __construct() {
			add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
			add_filter( 'locale', array( $this, 'filter_frontend_locale' ), 20 );
			add_filter( 'determine_locale', array( $this, 'filter_frontend_locale' ), 20 );
			add_action( 'wp', array( $this, 'switch_frontend_locale_by_query' ), 1 );
			add_filter( 'gettext', array( $this, 'filter_frontend_string_override' ), 20, 3 );
			add_filter( 'gettext_with_context', array( $this, 'filter_frontend_string_override_with_context' ), 20, 4 );
			add_filter( 'ngettext', array( $this, 'filter_frontend_plural_string_override' ), 20, 5 );
			add_action( 'init', array( $this, 'register_rewrite_rules' ) );

			add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_action( 'admin_init', array( $this, 'maybe_flush_rewrite_rules' ), 20 );
			add_action( 'admin_notices', array( $this, 'render_language_pack_notices' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

			add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
			add_action( 'save_post', array( $this, 'save_post_meta' ), 10, 2 );

			add_filter( 'page_row_actions', array( $this, 'add_row_actions' ), 10, 2 );
			add_filter( 'post_row_actions', array( $this, 'add_row_actions' ), 10, 2 );
			add_filter( 'manage_page_posts_columns', array( $this, 'add_language_column' ) );
			add_filter( 'manage_post_posts_columns', array( $this, 'add_language_column' ) );
			add_action( 'manage_page_posts_custom_column', array( $this, 'render_language_column' ), 10, 2 );
			add_action( 'manage_post_posts_custom_column', array( $this, 'render_language_column' ), 10, 2 );
			
			add_filter( 'post_class', array( $this, 'add_admin_translation_row_classes' ), 20, 3 );

			add_action( 'admin_post_' . self::ACTION_CREATE, array( $this, 'handle_create_translation' ) );

			add_shortcode( 'scm_language_switcher', array( $this, 'render_language_switcher_shortcode' ) );
			add_filter( 'the_content', array( $this, 'maybe_append_language_switcher_to_content' ) );
			add_action( 'wp_footer', array( $this, 'maybe_render_floating_language_switcher' ) );
			add_filter( 'wp_nav_menu_args', array( $this, 'filter_nav_menu_by_language' ), 20 );
			
			add_filter( 'render_block', array( $this, 'filter_block_widget_display_by_language' ), 20, 2 );
			add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_widget_visibility_assets' ) );
			add_filter( 'widget_display_callback', array( $this, 'filter_widget_display_by_language' ), 20, 3 );
			add_action( 'in_widget_form', array( $this, 'render_widget_language_visibility_fields' ), 20, 3 );
			add_filter( 'widget_update_callback', array( $this, 'save_widget_language_visibility_fields' ), 20, 4 );

			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_styles' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_theme_element_override_script' ), 30 );
			add_action( 'pre_get_posts', array( $this, 'filter_frontend_queries_by_language' ) );
			add_action( 'wp', array( $this, 'maybe_replace_core_canonical' ) );
			add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
			add_filter( 'request', array( $this, 'resolve_prefixed_translation_request' ) );
			add_filter( 'post_link', array( $this, 'filter_post_translation_permalink' ), 10, 2 );
			add_filter( 'page_link', array( $this, 'filter_page_translation_permalink' ), 10, 2 );

			add_filter(
				'plugin_action_links_' . plugin_basename( SCM_PLUGIN_FILE ),
				array( $this, 'add_plugin_action_links' )
			);

			$this->register_special_page_filters();
			
		}

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		/**
		 * Create all missing draft translations.
		 *
		 * @param int $source_id Source ID.
		 * @return array
		 */
		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		

		
	}

}

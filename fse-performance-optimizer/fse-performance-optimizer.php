<?php
/**
 * Plugin Name: FSE Performance Optimizer
 * Description: Bộ công cụ tổng hợp (All-in-One) cho WordPress FSE & WooCommerce (100% Share-Hosting Ready): Tối ưu ảnh WebP Q=100 giữ nguyên ICC Color Profile (Imagick & GD), Smart Delay Mini-Cart Drawer, Defer JS, Runtime Font WOFF2/Swap, LCP/CLS Guard, Static HTML Page Cache & Minify, Database Optimizer, Domain Migration (xử lý JSON escaped https:\/\/...) và Dashboard đối chiếu Trước/Sau.
 * Version: 1.2.0
 * Author: TuanLee
 * License: GPL2
 * Text Domain: fse-performance-optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-fse-opt-icc-webp.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-fse-opt-fonts.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-fse-opt-assets.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-fse-opt-cwv.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-fse-opt-cache.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-fse-opt-database.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-fse-opt-benchmark.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-fse-opt-migrator.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-fse-opt-admin.php';

class FSE_Performance_Optimizer {

	/**
	 * Option key for plugin settings.
	 */
	const OPTION_KEY = 'fse_performance_optimizer_settings';

	/**
	 * Current settings.
	 *
	 * @var array
	 */
	private $settings = array();

	/**
	 * Modules.
	 *
	 * @var FSE_Opt_ICC_WebP
	 */
	public $image_opt;

	/**
	 * @var FSE_Opt_Fonts
	 */
	public $fonts;

	/**
	 * @var FSE_Opt_Assets
	 */
	public $assets;

	/**
	 * @var FSE_Opt_CWV
	 */
	public $cwv;

	/**
	 * @var FSE_Opt_Cache
	 */
	public $cache;

	/**
	 * @var FSE_Opt_Database
	 */
	public $database;

	/**
	 * @var FSE_Opt_Benchmark
	 */
	public $benchmark;

	/**
	 * @var FSE_Opt_Migrator
	 */
	public $migrator;

	/**
	 * @var FSE_Opt_Admin
	 */
	public $admin;

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_default_settings() {
		return array(
			'enable_image_opt'      => true,
			'max_image_edge'        => 1920,
			'image_quality'         => 100,
			'enable_lossy_saving'   => false,
			'preserve_icc'          => true,
			'replace_original'      => true,
			'enable_js_defer'       => true,
			'enable_minicart_delay' => true,
			'enable_asset_unload'   => true,
			'enable_font_opt'       => true,
			'preload_fonts_count'   => 2,
			'enable_cwv_guard'      => true,
			'enable_page_cache'     => true,
			'enable_minify'         => true,
			'enable_htaccess'       => true,
			'cache_ttl'             => 36000,
			'max_revisions'         => 5,
		);
	}

	/**
	 * Initialize plugin modules and hooks.
	 */
	public function init() {
		$this->reload_settings();

		// Serve static HTML page cache as early as possible for anonymous GET requests.
		add_action( 'plugins_loaded', array( $this->cache, 'maybe_serve_cached_page' ), 1 );

		$this->image_opt->register_hooks();
		$this->assets->register_hooks();
		$this->cache->register_hooks();
		$this->database->register_hooks();

		if ( is_admin() ) {
			$this->admin->register_hooks();
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'filter_plugin_action_links' ) );
		} else {
			add_action( 'template_redirect', array( $this, 'start_frontend_output_buffer' ), 1 );
		}
	}

	/**
	 * Add quick links on the WordPress Plugins page row.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function filter_plugin_action_links( $links ) {
		$custom = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=fse-performance-optimizer&tab=dashboard' ) ) . '"><strong>📊 Dashboard</strong></a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=fse-performance-optimizer&tab=settings' ) ) . '">⚙️ Cấu hình</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=fse-performance-optimizer&tab=migration' ) ) . '">🔄 Migration</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=fse-performance-optimizer&tab=guide' ) ) . '">📖 Hướng dẫn</a>',
		);
		return array_merge( $custom, $links );
	}

	/**
	 * Reload settings and re-instantiate modules.
	 */
	public function reload_settings() {
		$saved          = get_option( self::OPTION_KEY, array() );
		$this->settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::get_default_settings() );

		$this->image_opt = new FSE_Opt_ICC_WebP( $this->settings );
		$this->fonts     = new FSE_Opt_Fonts( $this->settings );
		$this->assets    = new FSE_Opt_Assets( $this->settings );
		$this->cwv       = new FSE_Opt_CWV( $this->settings );
		$this->cache     = new FSE_Opt_Cache( $this->settings );
		$this->database  = new FSE_Opt_Database( $this->settings );
		$this->benchmark = new FSE_Opt_Benchmark();
		$this->migrator  = new FSE_Opt_Migrator();
		$this->admin     = new FSE_Opt_Admin( $this );
	}

	/**
	 * Get current settings array.
	 *
	 * @return array
	 */
	public function get_settings() {
		return $this->settings;
	}

	/**
	 * Start unified frontend HTML output buffer on normal HTML responses.
	 */
	public function start_frontend_output_buffer() {
		if ( is_admin() || is_feed() || is_robots() || is_trackback() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		// Allow raw unoptimized measurement if `?fse_raw=1` is requested for baseline comparison.
		if ( isset( $_GET['fse_raw'] ) && '1' === $_GET['fse_raw'] ) {
			return;
		}
		ob_start( array( $this, 'process_frontend_html_buffer' ) );
	}

	/**
	 * Unified frontend HTML optimization pipeline:
	 * 1. Runtime Font Optimizer (.woff2 upgrade, font-display:swap, preloads)
	 * 2. Core Web Vitals Guard (WebP src/srcset upgrade, missing width/height, LCP fetchpriority & lazy-load)
	 * 3. Smart JS Defer & Mini-Cart Drawer Delay
	 * 4. HTML / Inline CSS Minify & Static HTML Page Cache storage
	 *
	 * @param string $html Raw HTML output buffer.
	 * @return string Optimized HTML.
	 */
	public function process_frontend_html_buffer( $html ) {
		// Only process valid HTML documents.
		if ( '' === $html || false === stripos( $html, '<html' ) || false === stripos( $html, '</html>' ) ) {
			return $html;
		}

		$html = $this->fonts->optimize_fonts_in_html( $html );
		$html = $this->cwv->optimize_images_in_html( $html );
		$html = $this->assets->optimize_scripts_in_html( $html );
		$html = $this->cache->minify_and_store_html( $html );

		return $html;
	}

	/**
	 * Plugin activation hook.
	 */
	public static function activate() {
		$defaults = self::get_default_settings();
		if ( ! get_option( self::OPTION_KEY ) ) {
			update_option( self::OPTION_KEY, $defaults );
		}
		$cache = new FSE_Opt_Cache( $defaults );
		$cache->update_htaccess_rules( ! empty( $defaults['enable_htaccess'] ) );
	}

	/**
	 * Plugin deactivation hook.
	 */
	public static function deactivate() {
		$cache = new FSE_Opt_Cache( self::get_default_settings() );
		$cache->clear_all_cache();
		$cache->update_htaccess_rules( false );
	}
}

register_activation_hook( __FILE__, array( 'FSE_Performance_Optimizer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'FSE_Performance_Optimizer', 'deactivate' ) );

$GLOBALS['fse_performance_optimizer'] = new FSE_Performance_Optimizer();
$GLOBALS['fse_performance_optimizer']->init();

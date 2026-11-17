<?php
/**
 * Plugin Name: WC Instant Order Notifier (Telegram & SMTP)
 * Description: Đẩy thông báo WooCommerce Email có phân loại (#Hashtag) tức thì về Telegram Bot cho chủ shop + Tích hợp Gmail/Custom SMTP + Gửi ngầm tăng tốc Đặt hàng (Async Checkout) + Nhật ký gửi (Logs & Resend). Tái sử dụng cho mọi website WooCommerce.
 * Version: 1.0.0
 * Author: TuanLee
 * License: GPL2
 * Text Domain: wc-instant-order-notifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCION_VERSION', '1.0.0' );
define( 'WCION_PLUGIN_FILE', __FILE__ );
define( 'WCION_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCION_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WCION_PLUGIN_DIR . 'includes/class-wcion-logger.php';
require_once WCION_PLUGIN_DIR . 'includes/class-wcion-async.php';
require_once WCION_PLUGIN_DIR . 'includes/class-wcion-smtp.php';
require_once WCION_PLUGIN_DIR . 'includes/class-wcion-telegram.php';
require_once WCION_PLUGIN_DIR . 'includes/class-wcion-admin.php';

class WC_Instant_Order_Notifier {

	/**
	 * Option key for settings.
	 */
	const OPTION_KEY = 'wcion_settings';

	/**
	 * Current settings.
	 *
	 * @var array
	 */
	private $settings = array();

	/**
	 * @var WCION_Logger
	 */
	public $logger;

	/**
	 * @var WCION_Async
	 */
	public $async;

	/**
	 * @var WCION_SMTP
	 */
	public $smtp;

	/**
	 * @var WCION_Telegram
	 */
	public $telegram;

	/**
	 * @var WCION_Admin
	 */
	public $admin;

	/**
	 * Get default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_default_settings() {
		$blog_name   = wp_specialchars_decode( get_option( 'blogname', 'Shop' ), ENT_QUOTES );
		$admin_email = get_option( 'admin_email', '' );

		return array(
			// Telegram settings.
			'enable_telegram'          => true,
			'telegram_bot_token'       => '',
			'telegram_chat_id'         => '',
			'site_tag'                 => sanitize_key( preg_replace( '/[^a-zA-Z0-9]/', '', $blog_name ) ) ?: 'Shop',
			'include_zalo_link'        => true,
			'include_admin_link'       => true,

			// Categorized WooCommerce email/notification events.
			'notify_new_order'         => true,
			'notify_cancelled_order'   => true,
			'notify_failed_order'      => true,
			'notify_on_hold_order'     => true,
			'notify_processing_order'  => false, // Off by default if notify_new_order is on, to avoid duplicate on COD checkout.
			'notify_completed_order'   => true,
			'notify_refunded_order'    => true,
			'notify_customer_note'     => true,
			'notify_low_stock'         => true,
			'notify_no_stock'          => true,
			'notify_other_admin_email' => false,

			// Async checkout acceleration.
			'enable_async_dispatch'    => true,
			'defer_wc_emails'          => true,

			// SMTP settings (Gmail / Custom).
			'enable_smtp'              => false,
			'smtp_host'                => 'smtp.gmail.com',
			'smtp_port'                => 587,
			'smtp_encryption'          => 'tls',
			'smtp_username'            => '',
			'smtp_password'            => '',
			'smtp_from_name'           => $blog_name,
			'smtp_from_email'          => $admin_email,
			'admin_notify_email'       => $admin_email,
			'sync_wc_email_sender'     => true,

			// Logger settings.
			'max_logs'                 => 100,
		);
	}

	/**
	 * Initialize plugin modules and hooks.
	 */
	public function init() {
		$this->reload_settings();

		$this->async->register_hooks();
		$this->smtp->register_hooks();
		$this->telegram->register_hooks();

		if ( is_admin() ) {
			$this->admin->register_hooks();
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'filter_plugin_action_links' ) );
		}
	}

	/**
	 * Reload settings and re-instantiate modules.
	 */
	public function reload_settings() {
		$saved          = get_option( self::OPTION_KEY, array() );
		$this->settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::get_default_settings() );

		$this->logger   = new WCION_Logger( $this->settings );
		$this->async    = new WCION_Async( $this->settings, $this );
		$this->smtp     = new WCION_SMTP( $this->settings, $this->logger );
		$this->telegram = new WCION_Telegram( $this->settings, $this->logger, $this->async );
		$this->admin    = new WCION_Admin( $this );
	}

	/**
	 * Return current settings.
	 *
	 * @return array
	 */
	public function get_settings() {
		return $this->settings;
	}

	/**
	 * Add quick action links on the Plugins page.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function filter_plugin_action_links( $links ) {
		$custom = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=wc-instant-order-notifier&tab=telegram' ) ) . '"><strong>🤖 Telegram</strong></a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=wc-instant-order-notifier&tab=smtp' ) ) . '">📧 Gmail SMTP</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=wc-instant-order-notifier&tab=logs' ) ) . '">📋 Nhật ký</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=wc-instant-order-notifier&tab=guide' ) ) . '">📖 Hướng dẫn</a>',
		);
		return array_merge( $custom, $links );
	}

	/**
	 * Activation hook.
	 */
	public static function activate() {
		if ( ! get_option( self::OPTION_KEY ) ) {
			update_option( self::OPTION_KEY, self::get_default_settings() );
		}
	}
}

register_activation_hook( __FILE__, array( 'WC_Instant_Order_Notifier', 'activate' ) );

$GLOBALS['wc_instant_order_notifier'] = new WC_Instant_Order_Notifier();
add_action( 'plugins_loaded', array( $GLOBALS['wc_instant_order_notifier'], 'init' ), 20 );

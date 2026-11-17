<?php
/**
 * Async Checkout & Background Notification Dispatcher.
 *
 * Ensures customer checkout completes immediately (<0.3s) while Telegram and SMTP
 * notifications flush in the background right after the HTTP response is sent.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCION_Async {

	/**
	 * @var array
	 */
	private $settings;

	/**
	 * @var WC_Instant_Order_Notifier
	 */
	private $plugin;

	/**
	 * Queued callbacks to execute on PHP shutdown (after fastcgi_finish_request).
	 *
	 * @var callable[]
	 */
	private $shutdown_queue = array();

	/**
	 * Whether shutdown hook is already registered.
	 *
	 * @var bool
	 */
	private $shutdown_registered = false;

	/**
	 * @param array                     $settings Plugin settings.
	 * @param WC_Instant_Order_Notifier $plugin   Main plugin instance.
	 */
	public function __construct( $settings, $plugin ) {
		$this->settings = $settings;
		$this->plugin   = $plugin;
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		if ( ! empty( $this->settings['defer_wc_emails'] ) ) {
			add_filter( 'woocommerce_defer_transactional_emails', '__return_true', 99 );
		}
	}

	/**
	 * Enqueue a task (e.g. Telegram HTTP POST) to run after the HTTP response is flushed to the browser,
	 * or run immediately if async dispatch is disabled.
	 *
	 * @param callable $callback Callback to execute.
	 */
	public function dispatch( $callback ) {
		if ( empty( $this->settings['enable_async_dispatch'] ) || is_admin() ) {
			call_user_func( $callback );
			return;
		}

		$this->shutdown_queue[] = $callback;

		if ( ! $this->shutdown_registered ) {
			$this->shutdown_registered = true;
			add_action( 'shutdown', array( $this, 'flush_shutdown_queue' ), 999 );
		}
	}

	/**
	 * Flush all queued tasks on shutdown.
	 * On Nginx + PHP-FPM, `fastcgi_finish_request()` closes the client HTTP connection first
	 * so the shopper sees the Order Received page immediately without waiting for network I/O.
	 */
	public function flush_shutdown_queue() {
		if ( empty( $this->shutdown_queue ) ) {
			return;
		}

		if ( function_exists( 'fastcgi_finish_request' ) && ! headers_sent() === false ) {
			@fastcgi_finish_request();
		}

		$tasks                = $this->shutdown_queue;
		$this->shutdown_queue = array();

		foreach ( $tasks as $task ) {
			try {
				call_user_func( $task );
			} catch ( Exception $e ) {
				// Prevent uncaught exceptions during shutdown.
			}
		}
	}
}

<?php
/**
 * Special/system pages mapping module.
 *
 * @package Simple_Company_Multilingual
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait SCM_Special_Pages {
	/**
	 * Guard against recursive special page option filtering.
	 *
	 * @var bool
	 */
	private $is_filtering_special_page_id = false;

	/**
	 * Register special page filters.
	 *
	 * @return void
	 */
	public function register_special_page_filters() {
		/* WordPress Reading Settings: Posts page. */
		add_filter( 'option_page_for_posts', array( $this, 'filter_wordpress_posts_page_id' ), 20 );

		/* WooCommerce system pages. These filters exist through wc_get_page_id(). */
		add_filter( 'woocommerce_get_shop_page_id', array( $this, 'filter_woocommerce_special_page_id' ), 20 );
		add_filter( 'woocommerce_get_cart_page_id', array( $this, 'filter_woocommerce_special_page_id' ), 20 );
		add_filter( 'woocommerce_get_checkout_page_id', array( $this, 'filter_woocommerce_special_page_id' ), 20 );
		add_filter( 'woocommerce_get_myaccount_page_id', array( $this, 'filter_woocommerce_special_page_id' ), 20 );
	}

	/**
	 * Filter WordPress Posts Page ID by current language.
	 *
	 * @param mixed $page_id Page ID.
	 * @return mixed
	 */
	public function filter_wordpress_posts_page_id( $page_id ) {
		return $this->get_translated_special_page_id( $page_id );
	}

	/**
	 * Filter WooCommerce special page ID by current language.
	 *
	 * @param mixed $page_id Page ID.
	 * @return mixed
	 */
	public function filter_woocommerce_special_page_id( $page_id ) {
		return $this->get_translated_special_page_id( $page_id );
	}

	/**
	 * Resolve translated page ID for current frontend language.
	 *
	 * @param mixed $page_id Original special page ID.
	 * @return mixed
	 */
	private function get_translated_special_page_id( $page_id ) {
		$page_id = absint( $page_id );

		if ( $this->is_filtering_special_page_id ) {
			return $page_id;
		}

		if ( $page_id <= 0 || is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return $page_id;
		}

		$this->is_filtering_special_page_id = true;

		$post = get_post( $page_id );

		if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
			$this->is_filtering_special_page_id = false;
			return $page_id;
		}

		$current_language = $this->get_current_language_context_from_request( '' );
		$default_language = $this->get_default_language();

		if ( '' === $current_language || $current_language === $default_language ) {
			$this->is_filtering_special_page_id = false;
			return $page_id;
		}

		$translated_id = $this->get_translation_id_for_language( $page_id, $current_language );

		$this->is_filtering_special_page_id = false;

		if ( $translated_id > 0 ) {
			return $translated_id;
		}

		return $page_id;
	}

	/**
	 * Get sibling translation ID by language.
	 *
	 * @param int    $post_id  Source post/page ID.
	 * @param string $language Target language.
	 * @return int
	 */
	private function get_translation_id_for_language( $post_id, $language ) {
		$post_id  = absint( $post_id );
		$language = sanitize_key( $language );

		if ( $post_id <= 0 || '' === $language ) {
			return 0;
		}

		$group = $this->get_translation_group( $post_id );

		if ( isset( $group[ $language ] ) ) {
			$translated_id = absint( $group[ $language ] );
			$translated    = get_post( $translated_id );

			if ( $translated instanceof WP_Post && 'page' === $translated->post_type && 'trash' !== $translated->post_status ) {
				return $translated_id;
			}
		}

		return 0;
	}

	/**
	 * Get current special page source ID for language switcher.
	 *
	 * @return int
	 */
	public function get_current_special_page_source_id_for_switcher() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return 0;
		}

		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return absint( get_option( 'woocommerce_shop_page_id' ) );
		}

		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return absint( get_option( 'woocommerce_cart_page_id' ) );
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return absint( get_option( 'woocommerce_checkout_page_id' ) );
		}

		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return absint( get_option( 'woocommerce_myaccount_page_id' ) );
		}

		if ( is_home() && ! is_front_page() ) {
			return absint( get_option( 'page_for_posts' ) );
		}

		return 0;
	}
}

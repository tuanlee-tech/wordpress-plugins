<?php
/**
 * SCM Locale trait.
 *
 * @package Simple_Company_Multilingual
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait SCM_Locale {
/**
		 * Filter frontend WordPress locale by plugin language context.
		 *
		 * This makes WordPress core/theme/plugin strings follow the URL language,
		 * for example /vi/... uses Vietnamese strings while /ko/... uses Korean.
		 * Admin, AJAX, REST and WP-CLI are intentionally not affected.
		 *
		 * @param string $locale Current WordPress locale.
		 * @return string
		 */
		public function filter_frontend_locale( $locale ) {
			if ( $this->is_filtering_locale ) {
				return $locale;
			}

			if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				return $locale;
			}

			$this->is_filtering_locale = true;

			try {
				$language  = $this->get_current_language_context_from_request( $locale );
				$wp_locale = $this->get_wp_locale_for_language( $language, $locale );
			} catch ( Exception $e ) {
				$this->is_filtering_locale = false;
				return $locale;
			}

			$this->is_filtering_locale = false;

			return '' !== $wp_locale ? $wp_locale : $locale;
		}

/**
		 * Switch locale after the main query is resolved.
		 *
		 * This fixes static front page and builder-rendered front-page cases where
		 * request-prefix detection is not enough. At this point WordPress knows the
		 * queried page/post, so we can use its persisted _scm_language value.
		 *
		 * @return void
		 */
		public function switch_frontend_locale_by_query() {
			if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				return;
			}

			if ( ! function_exists( 'switch_to_locale' ) ) {
				return;
			}

			$language = $this->get_current_language_context();
			$wp_locale = $this->get_wp_locale_for_language( $language, '' );

			if ( '' === $wp_locale ) {
				return;
			}

			$current_locale = function_exists( 'determine_locale' ) ? determine_locale() : '';

			if ( $current_locale === $wp_locale ) {
				return;
			}

			switch_to_locale( $wp_locale );
		}

/**
		 * Get current plugin language context after query resolution.
		 *
		 * @return string
		 */
		public function get_current_language_context() {
			$queried_id = get_queried_object_id();

			if ( $queried_id > 0 ) {
				$post = get_post( $queried_id );

				if ( $post instanceof WP_Post && in_array( $post->post_type, self::SUPPORTED_TYPES, true ) ) {
					return $this->get_post_language( $queried_id );
				}
			}

			$request_language = $this->get_current_language_context_from_request( '' );

			if ( '' !== $request_language && $this->is_active_language( $request_language ) ) {
				return $request_language;
			}

			return $this->get_default_language();
		}

/**
		 * Get current plugin language from request URI without calling get_locale().
		 *
		 * This method is safe to call from the locale/determine_locale filters.
		 * It must not call get_default_language(), because that can call get_locale()
		 * and recurse into the locale filter.
		 *
		 * @param string $fallback_locale Current fallback WordPress locale.
		 * @return string Plugin language code.
		 */
		private function get_current_language_context_from_request( $fallback_locale = '' ) {
			$settings = $this->get_settings();
			$default  = isset( $settings['default_language'] ) && '' !== $settings['default_language'] ? sanitize_key( $settings['default_language'] ) : sanitize_key( $fallback_locale );

			if ( '' === $default ) {
				$default = $this->get_wordpress_default_language();
			}

			if ( '' === $default ) {
				$default = 'en_us';
			}

			$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$path = is_string( $path ) ? trim( $path, '/' ) : '';
			$first_segment = '';

			if ( '' !== $path ) {
				$parts = explode( '/', $path );
				$first_segment = isset( $parts[0] ) ? sanitize_title( $parts[0] ) : '';
			}

			if ( '' !== $first_segment && isset( $settings['languages'] ) && is_array( $settings['languages'] ) ) {
				foreach ( $settings['languages'] as $language => $config ) {
					$language = sanitize_key( $language );

					if ( ! is_array( $config ) || ! isset( $config['enabled'] ) || 'yes' !== $config['enabled'] ) {
						continue;
					}

					$prefix = isset( $config['prefix'] ) ? sanitize_title( $config['prefix'] ) : '';

					if ( '' === $prefix && $language !== $default ) {
						$prefix = $this->locale_to_prefix( $language );
					}

					if ( '' !== $prefix && $prefix === $first_segment ) {
						return $language;
					}
				}
			}

			return $default;
		}
}

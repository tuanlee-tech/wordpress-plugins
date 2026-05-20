<?php
/**
 * SCM Frontend trait.
 *
 * @package Simple_Company_Multilingual
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait SCM_Frontend {
/**
		 * Shortcode callback.
		 *
		 * @param array $atts Attributes.
		 * @return string
		 */
		public function render_language_switcher_shortcode( $atts ) {
			$atts = shortcode_atts(
				array(
					'post_id'      => 0,
					'show_current' => 'yes',
					'layout'       => 'inline',
				),
				$atts,
				'scm_language_switcher'
			);

			return $this->get_language_switcher_html(
				absint( $atts['post_id'] ),
				array(
					'show_current' => 'yes' === $atts['show_current'],
					'layout'       => sanitize_key( $atts['layout'] ),
				)
			);
		}

		/**
		 * Check whether current switcher source is the configured static front page.
		 *
		 * @param int $post_id Post ID.
		 * @return bool
		 */
		private function is_switcher_source_static_front_page( $post_id ) {
			$post_id       = absint( $post_id );
			$front_page_id = absint( get_option( 'page_on_front' ) );

			return $post_id > 0
				&& $front_page_id > 0
				&& 'page' === get_option( 'show_on_front' )
				&& $post_id === $front_page_id;
		}

		/**
		 * Build translated homepage URL using the translated page real slug.
		 *
		 * In this plugin model:
		 * - default front page uses /
		 * - translated front pages use /prefix/
		 *
		 * Example:
		 * - English → /
		 * - Vietnamese → /vi/home
		 * - Deutsch → /de/home
		 *
		 * @param int    $target_id Target translated page ID.
		 * @param string $locale    Target language.
		 * @return string
		 */
		private function get_translated_front_page_real_url( $target_id, $locale ) {
			$target_id = absint( $target_id );
			$locale    = sanitize_key( $locale );

			if ( $target_id <= 0 ) {
				return '';
			}

			$default = $this->get_default_language();

			if ( $locale === $default ) {
				return home_url( '/' );
			}

			$active = $this->get_active_languages();

			if ( empty( $active[ $locale ] ) ) {
				return '';
			}

			$prefix = isset( $active[ $locale ]['prefix'] ) ? sanitize_title( $active[ $locale ]['prefix'] ) : '';

			if ( '' === $prefix ) {
				$prefix = $this->locale_to_prefix( $locale );
			}

			if ( '' === $prefix ) {
				return '';
			}

			return home_url( user_trailingslashit( $prefix ) );
		}
		

/**
		 * Switcher HTML.
		 *
		 * @param int   $post_id Post ID.
		 * @param array $args Args.
		 * @return string
		 */
		public function get_language_switcher_html( $post_id = 0, $args = array() ) {
			$post_id = absint( $post_id );

			if ( $post_id <= 0 ) {
				$post_id = $this->get_language_switcher_source_id();
			}

			if ( $post_id <= 0 ) {
				return '';
			}

			$args = wp_parse_args(
				$args,
				array(
					'show_current' => true,
					'layout'       => 'inline',
				)
			);

			$active  = $this->get_active_languages();
			$current = $this->get_post_language( $post_id );
			$group   = $this->get_translation_group( $post_id );
			$links   = array();

			foreach ( $active as $locale => $language ) {
				$is_current = $locale === $current;

				if ( $is_current && ! $args['show_current'] ) {
					continue;
				}

				$target_id  = isset( $group[ $locale ] ) ? absint( $group[ $locale ] ) : 0;
				$label_html = $this->get_language_label_html( $locale, $language );

				if ( $target_id <= 0 ) {
					$links[] = sprintf( '<span class="scm-language-switcher__link scm-language-switcher__link--disabled" aria-disabled="true">%s</span>', $label_html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					continue;
				}

				$url        = '';
				$status     = get_post_status( $target_id );
				$is_public  = 'publish' === $status;
				$can_edit   = current_user_can( 'edit_post', $target_id );
				$draft_note = '';

				if ( $is_public ) {
					if ( $this->is_switcher_source_static_front_page( $post_id ) ) {
						$url = $this->get_translated_front_page_real_url( $target_id, $locale );
					} else {
						$url = get_permalink( $target_id );
					}
				} elseif ( $can_edit ) {
					$url        = get_edit_post_link( $target_id, 'raw' );
					$draft_note = ' <span class="scm-language-status">' . esc_html__( 'Draft', 'simple-company-multilingual' ) . '</span>';
				}

				if ( ! is_string( $url ) || '' === $url ) {
					$links[] = sprintf( '<span class="scm-language-switcher__link scm-language-switcher__link--disabled" aria-disabled="true">%s</span>', $label_html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					continue;
				}

				$classes = array( 'scm-language-switcher__link' );

				if ( $is_current ) {
					$classes[] = 'is-current';
				}

				$links[] = sprintf(
					'<a href="%1$s" class="%2$s" hreflang="%3$s" lang="%3$s" aria-current="%4$s">%5$s</a>',
					esc_url( $url ),
					esc_attr( implode( ' ', $classes ) ),
					esc_attr( str_replace( '_', '-', $locale ) ),
					$is_current ? 'page' : 'false',
					$label_html . $draft_note // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
			}

			if ( empty( $links ) ) {
				return '';
			}

			return sprintf(
				'<nav class="scm-language-switcher scm-language-switcher--%1$s" aria-label="%2$s">%3$s</nav>',
				esc_attr( sanitize_html_class( $args['layout'] ) ),
				esc_attr__( 'Language switcher', 'simple-company-multilingual' ),
				implode( "
", $links )
			);
		}

/**
		 * Get the source page/post ID for the language switcher.
		 *
		 * Special pages such as WooCommerce Shop/Cart/Checkout/My Account and the
		 * WordPress Posts Page are not always exposed as the queried object. In that
		 * case the switcher must use the original special page ID so it can read the
		 * correct translation group.
		 *
		 * @return int
		 */
		private function get_language_switcher_source_id() {
			$special_page_id = 0;

			if ( method_exists( $this, 'get_current_special_page_source_id_for_switcher' ) ) {
				$special_page_id = absint( $this->get_current_special_page_source_id_for_switcher() );
			}

			if ( $special_page_id > 0 ) {
				return $special_page_id;
			}

			$queried_id = absint( get_queried_object_id() );

			if ( $queried_id > 0 ) {
				return $queried_id;
			}

			return absint( get_the_ID() );
		}

/**
		 * Append switcher.
		 *
		 * @param string $content Content.
		 * @return string
		 */
		public function maybe_append_language_switcher_to_content( $content ) {
			if ( is_admin() || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
				return $content;
			}

			$settings = $this->get_settings();

			if ( 'yes' !== $settings['auto_append_switcher'] ) {
				return $content;
			}

			return $content . $this->get_language_switcher_html( get_the_ID(), array( 'layout' => 'after-content' ) );
		}

/**
		 * Floating switcher.
		 *
		 * @return void
		 */
		public function maybe_render_floating_language_switcher() {
			if ( is_admin() ) {
				return;
			}

			$source_id = $this->get_language_switcher_source_id();

			if ( $source_id <= 0 ) {
				return;
			}

			$settings = $this->get_settings();

			if ( 'yes' !== $settings['floating_switcher'] ) {
				return;
			}

			$switcher = $this->get_language_switcher_html( $source_id, array( 'layout' => 'floating' ) );

			if ( '' === $switcher ) {
				return;
			}

			printf(
				'<div class="scm-floating-language-switcher scm-floating-language-switcher--%1$s">%2$s</div>',
				esc_attr( sanitize_html_class( $settings['floating_position'] ) ),
				$switcher // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}


		/**
		 * Enqueue frontend styles.
		 *
		 * @return void
		 */
		public function enqueue_frontend_styles() {
			if ( is_admin() ) {
				return;
			}

			wp_enqueue_style(
				self::FRONTEND_STYLE,
				SCM_PLUGIN_URL . 'assets/frontend.css',
				array(),
				SCM_PLUGIN_VERSION
			);
		}

/**
		 * Filter frontend list queries.
		 *
		 * @param WP_Query $query Query.
		 * @return void
		 */
		public function filter_frontend_queries_by_language( $query ) {
			if ( is_admin() || ! $query instanceof WP_Query ) {
				return;
			}

			$settings = $this->get_settings();

			if ( 'yes' !== $settings['filter_frontend_lists'] || $query->get( 'scm_include_all_languages' ) ) {
				return;
			}

			if ( $query->is_main_query() && $query->is_singular() ) {
				return;
			}

			$post_type = $query->get( 'post_type' );

			if ( empty( $post_type ) ) {
				$post_type = 'post';
			}

			$post_types = is_array( $post_type ) ? $post_type : array( $post_type );

			if ( empty( array_intersect( $post_types, self::SUPPORTED_TYPES ) ) ) {
				return;
			}

			$language   = $this->get_context_language_for_query();
			$default    = $this->get_default_language();
			$meta_query = $query->get( 'meta_query' );

			if ( ! is_array( $meta_query ) ) {
				$meta_query = array();
			}

			if ( $language === $default ) {
				$meta_query[] = array(
					'relation' => 'OR',
					array(
						'key'     => self::META_LANGUAGE,
						'value'   => $language,
						'compare' => '=',
					),
					array(
						'key'     => self::META_LANGUAGE,
						'compare' => 'NOT EXISTS',
					),
				);
			} else {
				$meta_query[] = array(
					'key'     => self::META_LANGUAGE,
					'value'   => $language,
					'compare' => '=',
				);
			}

			$query->set( 'meta_query', $meta_query );
		}

/**
		 * Context language for frontend query.
		 *
		 * @return string
		 */
		private function get_context_language_for_query() {
			$queried_id = get_queried_object_id();

			if ( $queried_id <= 0 && method_exists( $this, 'get_current_special_page_source_id_for_switcher' ) ) {
				$queried_id = absint( $this->get_current_special_page_source_id_for_switcher() );
			}

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
}
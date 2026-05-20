<?php
/**
 * SCM SEO trait.
 *
 * @package Simple_Company_Multilingual
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait SCM_SEO {
/**
		 * Replace WordPress core canonical on supported singular content.
		 *
		 * WordPress core canonical does not know this plugin's prefixed post URLs.
		 * For multilingual SEO, each language version should usually have a
		 * self-referencing canonical URL, not a canonical pointing back to the root language.
		 *
		 * @return void
		 */
		public function maybe_replace_core_canonical() {
			if ( is_admin() || ! is_singular( self::SUPPORTED_TYPES ) ) {
				return;
			}

			$post_id = get_queried_object_id();

			if ( $post_id <= 0 ) {
				return;
			}

			/*
			 * Avoid duplicate canonical tags by removing WordPress core canonical
			 * only for post/page screens managed by this plugin.
			 */
			remove_action( 'wp_head', 'rel_canonical' );
			add_action( 'wp_head', array( $this, 'render_canonical_url' ), 1 );
			add_action( 'wp_head', array( $this, 'render_hreflang_links' ), 2 );
		}

/**
		 * Render canonical URL for current singular post/page.
		 *
		 * @return void
		 */
		public function render_canonical_url() {
			$post_id = get_queried_object_id();

			if ( $post_id <= 0 || 'publish' !== get_post_status( $post_id ) ) {
				return;
			}

			$canonical_url = get_permalink( $post_id );

			if ( ! is_string( $canonical_url ) || '' === $canonical_url ) {
				return;
			}

			printf( '<link rel="canonical" href="%s" />' . "
", esc_url( $canonical_url ) );
		}

/**
		 * Render hreflang alternates for published siblings.
		 *
		 * This is not a replacement for canonical. Canonical remains self-referencing.
		 * Hreflang tells search engines which language alternatives belong together.
		 *
		 * @return void
		 */
		public function render_hreflang_links() {
			$post_id = get_queried_object_id();

			if ( $post_id <= 0 || 'publish' !== get_post_status( $post_id ) ) {
				return;
			}

			$active = $this->get_active_languages();
			$group  = $this->get_translation_group( $post_id );

			foreach ( $active as $locale => $language ) {
				if ( empty( $group[ $locale ] ) ) {
					continue;
				}

				$target_id = absint( $group[ $locale ] );

				if ( $target_id <= 0 || 'publish' !== get_post_status( $target_id ) ) {
					continue;
				}

				$url = get_permalink( $target_id );

				if ( ! is_string( $url ) || '' === $url ) {
					continue;
				}

				printf(
					'<link rel="alternate" hreflang="%1$s" href="%2$s" />' . "
",
					esc_attr( strtolower( str_replace( '_', '-', $locale ) ) ),
					esc_url( $url )
				);
			}

			$default_locale = $this->get_default_language();

			if ( ! empty( $group[ $default_locale ] ) && 'publish' === get_post_status( $group[ $default_locale ] ) ) {
				$default_url = get_permalink( absint( $group[ $default_locale ] ) );

				if ( is_string( $default_url ) && '' !== $default_url ) {
					printf( '<link rel="alternate" hreflang="x-default" href="%s" />' . "
", esc_url( $default_url ) );
				}
			}
		}
}

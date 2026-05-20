<?php
/**
 * SCM String Overrides trait.
 *
 * @package Simple_Company_Multilingual
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait SCM_String_Overrides {
/**
		 * Get default string override sources.
		 *
		 * These are generated dynamically through WordPress translation APIs, so the
		 * admin sees source labels in the current Site Language when available.
		 * Theme-specific strings like "Written by" can still be added manually.
		 *
		 * @return string[]
		 */
		private function get_default_string_override_sources() {
			$sources = array(
				__( 'Comments' ),
				__( 'Leave a Reply' ),
				__( 'Edit your profile' ),
				__( 'Log out?' ),
				__( 'Required fields are marked *' ),
				__( 'Search' ),
				__( 'Read more' ),
				__( 'Previous' ),
				__( 'Next' ),
			);

			return array_values( array_unique( array_filter( array_map( 'trim', $sources ) ) ) );
		}

/**
		 * Get all string override sources.
		 *
		 * @return string[]
		 */
		private function get_string_override_sources() {
			$settings = $this->get_settings();
			$sources  = $this->get_default_string_override_sources();

			if ( ! empty( $settings['string_sources'] ) && is_array( $settings['string_sources'] ) ) {
				$sources = array_merge( $sources, $settings['string_sources'] );
			}

			if ( ! empty( $settings['string_overrides'] ) && is_array( $settings['string_overrides'] ) ) {
				$sources = array_merge( $sources, array_keys( $settings['string_overrides'] ) );
			}

			$sources = array_map( 'sanitize_text_field', $sources );
			$sources = array_values( array_unique( array_filter( array_map( 'trim', $sources ) ) ) );

			return $sources;
		}

/**
		 * Override selected frontend strings when the theme/core translation is not available.
		 *
		 * This is intentionally small and explicit. It is not a full string translation
		 * system. It only covers common theme/core labels that users often see in
		 * headers, post meta and comments.
		 *
		 * @param string $translation Existing translation.
		 * @param string $text        Original text.
		 * @param string $domain      Text domain.
		 * @return string
		 */
		public function filter_frontend_string_override( $translation, $text, $domain ) {
			return $this->get_frontend_string_override( $translation, $text );
		}

/**
		 * Override frontend gettext strings with context.
		 *
		 * @param string $translation Existing translation.
		 * @param string $text        Original text.
		 * @param string $context     Translation context.
		 * @param string $domain      Text domain.
		 * @return string
		 */
		public function filter_frontend_string_override_with_context( $translation, $text, $context, $domain ) {
			return $this->get_frontend_string_override( $translation, $text );
		}

/**
		 * Override frontend plural gettext strings.
		 *
		 * @param string $translation Existing translation.
		 * @param string $single      Singular source text.
		 * @param string $plural      Plural source text.
		 * @param int    $number      Number.
		 * @param string $domain      Text domain.
		 * @return string
		 */
		public function filter_frontend_plural_string_override( $translation, $single, $plural, $number, $domain ) {
			$override = $this->get_frontend_string_override( $translation, $single );

			if ( $override !== $translation ) {
				return $override;
			}

			return $this->get_frontend_string_override( $translation, $plural );
		}

/**
		 * Get frontend string override.
		 *
		 * @param string $translation Existing translation.
		 * @param string $source_text Source text.
		 * @return string
		 */
		private function get_frontend_string_override( $translation, $source_text ) {
			if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				return $translation;
			}

			$source_text = $this->normalize_override_source_text( $source_text );
			$translated  = $this->normalize_override_source_text( $translation );

			if ( '' === $source_text && '' === $translated ) {
				return $translation;
			}

			$settings = $this->get_settings();

			if ( empty( $settings['string_overrides'] ) || ! is_array( $settings['string_overrides'] ) ) {
				return $translation;
			}

			$language = did_action( 'wp' ) ? $this->get_current_language_context() : $this->get_current_language_context_from_request( '' );

			if ( '' === $language ) {
				return $translation;
			}

			foreach ( $settings['string_overrides'] as $registered_source => $translations ) {
				$registered_source = $this->normalize_override_source_text( $registered_source );

				if ( '' === $registered_source || ! is_array( $translations ) ) {
					continue;
				}

				if ( $registered_source !== $source_text && $registered_source !== $translated ) {
					continue;
				}

				if ( ! empty( $translations[ $language ] ) ) {
					return $translations[ $language ];
				}
			}

			return $translation;
		}

/**
		 * Normalize override source text for reliable matching.
		 *
		 * @param string $text Text.
		 * @return string
		 */
		private function normalize_override_source_text( $text ) {
			$text = wp_strip_all_tags( (string) $text );
			$text = html_entity_decode( $text, ENT_QUOTES, get_bloginfo( 'charset' ) );
			$text = preg_replace( '/\s+/', ' ', $text );

			return trim( (string) $text );
		}
}

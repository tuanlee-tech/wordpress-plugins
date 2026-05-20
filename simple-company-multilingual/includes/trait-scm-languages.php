<?php
/**
 * SCM Languages trait.
 *
 * @package Simple_Company_Multilingual
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait SCM_Languages {
/**
		 * Get WordPress default language.
		 *
		 * @return string
		 */
		private function get_wordpress_default_language() {
			$locale = get_locale();

			return $locale ? sanitize_key( $locale ) : 'en_US';
		}

/**
		 * Get effective default language.
		 *
		 * @return string
		 */
		public function get_default_language() {
			$settings = $this->get_settings();
			$language = isset( $settings['default_language'] ) ? sanitize_key( $settings['default_language'] ) : '';

			return '' !== $language ? $language : $this->get_wordpress_default_language();
		}

/**
		 * Convert locale to URL prefix.
		 *
		 * @param string $locale Locale.
		 * @return string
		 */
		private function locale_to_prefix( $locale ) {
			$locale = strtolower( str_replace( '_', '-', sanitize_key( $locale ) ) );
			$parts  = explode( '-', $locale );

			return isset( $parts[0] ) ? sanitize_title( $parts[0] ) : sanitize_title( $locale );
		}

/**
		 * Get selectable language catalog.
		 *
		 * @return array
		 */
		public function get_language_catalog() {
			if ( is_array( $this->language_catalog ) ) {
				return $this->language_catalog;
			}

			$catalog        = array();
			$default_locale = $this->get_wordpress_default_language();

			$catalog[ $default_locale ] = array(
				'label'     => $this->get_wp_locale_display_name( $default_locale ),
				'prefix'    => $this->locale_to_prefix( $default_locale ),
				'wp_locale' => $this->sanitize_wp_locale( $default_locale ),
			);

			foreach ( get_available_languages() as $locale ) {
				$locale = sanitize_key( $locale );

				$catalog[ $locale ] = array(
					'label'     => $this->get_wp_locale_display_name( $locale ),
					'prefix'    => $this->locale_to_prefix( $locale ),
					'wp_locale' => $this->sanitize_wp_locale( $locale ),
				);
			}

			if ( function_exists( 'wp_get_available_translations' ) ) {
				$translations = wp_get_available_translations();

				if ( is_array( $translations ) ) {
					foreach ( $translations as $locale => $translation ) {
						$locale = sanitize_key( $locale );
						$label  = $locale;

						if ( isset( $translation['native_name'] ) && '' !== $translation['native_name'] ) {
							$label = $translation['native_name'];
						} elseif ( isset( $translation['english_name'] ) && '' !== $translation['english_name'] ) {
							$label = $translation['english_name'];
						}

						$catalog[ $locale ] = array(
							'label'     => sanitize_text_field( $label ),
							'prefix'    => $this->locale_to_prefix( $locale ),
							'wp_locale' => $this->sanitize_wp_locale( $locale ),
						);
					}
				}
			}

			$settings = $this->get_settings();

			if ( isset( $settings['custom_languages'] ) && is_array( $settings['custom_languages'] ) ) {
				foreach ( $settings['custom_languages'] as $locale => $language ) {
					$locale = sanitize_key( $locale );

					if ( '' === $locale || ! is_array( $language ) ) {
						continue;
					}

					$label  = isset( $language['label'] ) ? sanitize_text_field( $language['label'] ) : $locale;
					$prefix = isset( $language['prefix'] ) ? sanitize_title( $language['prefix'] ) : $this->locale_to_prefix( $locale );

					$catalog[ $locale ] = array(
						'label'  => $label,
						'prefix' => $prefix,
					);
				}
			}

			ksort( $catalog );

			$this->language_catalog = apply_filters( 'scm_language_catalog', $catalog );

			return $this->language_catalog;
		}

/**
		 * Get active languages.
		 *
		 * @return array
		 */
		public function get_active_languages() {
			$settings       = $this->get_settings();
			$catalog        = $this->get_language_catalog();
			$active         = array();
			$default        = $this->get_default_language();
			$default_config = isset( $settings['languages'][ $default ] ) && is_array( $settings['languages'][ $default ] ) ? $settings['languages'][ $default ] : array();

			$active[ $default ] = array(
				'label'      => isset( $default_config['label'] ) && '' !== $default_config['label'] ? sanitize_text_field( $default_config['label'] ) : ( isset( $catalog[ $default ]['label'] ) ? $catalog[ $default ]['label'] : $default ),
				'prefix'     => '',
				'flag'       => isset( $default_config['flag'] ) ? sanitize_text_field( $default_config['flag'] ) : '',
				'flag_image' => isset( $default_config['flag_image'] ) ? esc_url_raw( $default_config['flag_image'] ) : '',
				'wp_locale'  => isset( $default_config['wp_locale'] ) && '' !== $default_config['wp_locale'] ? $this->sanitize_wp_locale( $default_config['wp_locale'] ) : $this->guess_wp_locale_from_language( $default ),
			);

			if ( isset( $settings['languages'] ) && is_array( $settings['languages'] ) ) {
				foreach ( $settings['languages'] as $locale => $config ) {
					$locale = sanitize_key( $locale );

					if ( ! is_array( $config ) || ! isset( $config['enabled'] ) || 'yes' !== $config['enabled'] ) {
						continue;
					}

					$catalog_item = isset( $catalog[ $locale ] ) ? $catalog[ $locale ] : array(
						'label'  => $locale,
						'prefix' => $this->locale_to_prefix( $locale ),
					);

					$prefix = isset( $config['prefix'] ) ? sanitize_title( $config['prefix'] ) : '';

					/*
					 * Important when changing default language:
					 * A language that used to be default may have an empty stored prefix.
					 * Once it becomes non-default, it must regain a usable prefix such as
					 * en_us -> en, otherwise /en/slug/ cannot resolve correctly.
					 */
					if ( $locale !== $default && '' === $prefix ) {
						$prefix = isset( $catalog_item['prefix'] ) && '' !== $catalog_item['prefix'] ? sanitize_title( $catalog_item['prefix'] ) : $this->locale_to_prefix( $locale );
					}

					$active[ $locale ] = array(
						'label'      => isset( $config['label'] ) && '' !== $config['label'] ? sanitize_text_field( $config['label'] ) : $catalog_item['label'],
						'prefix'     => $locale === $default ? '' : $prefix,
						'flag'       => isset( $config['flag'] ) ? sanitize_text_field( $config['flag'] ) : '',
						'flag_image' => isset( $config['flag_image'] ) ? esc_url_raw( $config['flag_image'] ) : '',
						'wp_locale'  => isset( $config['wp_locale'] ) && '' !== $config['wp_locale'] ? $this->sanitize_wp_locale( $config['wp_locale'] ) : $this->guess_wp_locale_from_language( $locale ),
					);
				}
			}

			return $active;
		}

/**
		 * Get full WordPress language choices similar to Settings > General > Site Language.
		 *
		 * @return array<string,array{label:string,prefix:string,wp_locale:string}>
		 */
		private function get_wordpress_language_choices() {
			$choices = array();

			if ( is_admin() && ! function_exists( 'wp_get_available_translations' ) ) {
				require_once ABSPATH . 'wp-admin/includes/translation-install.php';
			}

			/* English is WordPress core default and may not appear in language packs. */
			$choices['en_us'] = array(
				'label'     => __( 'English', 'simple-company-multilingual' ),
				'prefix'    => 'en',
				'wp_locale' => 'en_US',
			);

			foreach ( get_available_languages() as $locale ) {
				$locale = $this->sanitize_wp_locale( $locale );

				if ( '' === $locale ) {
					continue;
				}

				$key = sanitize_key( $locale );

				if ( ! isset( $choices[ $key ] ) ) {
					$choices[ $key ] = array(
						'label'     => $this->get_wp_locale_display_name( $locale ),
						'prefix'    => $this->locale_to_prefix( $locale ),
						'wp_locale' => $locale,
					);
				}
			}

			if ( function_exists( 'wp_get_available_translations' ) ) {
				$translations = wp_get_available_translations();

				if ( is_array( $translations ) ) {
					foreach ( $translations as $locale => $translation ) {
						$locale = $this->sanitize_wp_locale( $locale );

						if ( '' === $locale ) {
							continue;
						}

						$key   = sanitize_key( $locale );
						$label = '';

						if ( is_array( $translation ) ) {
							if ( ! empty( $translation['native_name'] ) ) {
								$label = sanitize_text_field( $translation['native_name'] );
							} elseif ( ! empty( $translation['english_name'] ) ) {
								$label = sanitize_text_field( $translation['english_name'] );
							}
						}

						if ( '' === $label ) {
							$label = $this->get_wp_locale_display_name( $locale );
						}

						$choices[ $key ] = array(
							'label'     => $label,
							'prefix'    => $this->locale_to_prefix( $locale ),
							'wp_locale' => $locale,
						);
					}
				}
			}

			uasort(
				$choices,
				static function ( $a, $b ) {
					return strcasecmp( $a['label'], $b['label'] );
				}
			);

			return $choices;
		}

/**
		 * Get display name for a language config.
		 *
		 * @param string $locale   Plugin locale.
		 * @param array  $language Language config.
		 * @return string
		 */
		private function get_language_display_name( $locale, $language ) {
			$label = isset( $language['label'] ) ? trim( (string) $language['label'] ) : '';

			if ( '' !== $label && sanitize_key( $label ) !== sanitize_key( $locale ) ) {
				return $label;
			}

			$wp_locale = isset( $language['wp_locale'] ) && '' !== $language['wp_locale'] ? $language['wp_locale'] : $this->guess_wp_locale_from_language( $locale );

			return $this->get_wp_locale_display_name( $wp_locale );
		}

/**
		 * Get human-readable locale display name.
		 *
		 * @param string $wp_locale WordPress locale.
		 * @return string
		 */
		private function get_wp_locale_display_name( $wp_locale ) {
			$wp_locale = $this->sanitize_wp_locale( $wp_locale );

			if ( '' === $wp_locale ) {
				return '';
			}

			if ( 'en_US' === $wp_locale || 'en_us' === strtolower( $wp_locale ) ) {
				return __( 'English', 'simple-company-multilingual' );
			}

			if ( function_exists( 'wp_get_available_translations' ) ) {
				$translations = wp_get_available_translations();

				if ( isset( $translations[ $wp_locale ] ) && is_array( $translations[ $wp_locale ] ) ) {
					if ( ! empty( $translations[ $wp_locale ]['native_name'] ) ) {
						return sanitize_text_field( $translations[ $wp_locale ]['native_name'] );
					}

					if ( ! empty( $translations[ $wp_locale ]['english_name'] ) ) {
						return sanitize_text_field( $translations[ $wp_locale ]['english_name'] );
					}
				}
			}

			return $wp_locale;
		}

/**
		 * Check whether a WordPress language choice already exists in plugin settings.
		 *
		 * @param string $choice_locale Choice key.
		 * @param array  $choice        Choice config.
		 * @param array  $settings      Plugin settings.
		 * @param array  $active        Active languages.
		 * @return bool
		 */
		private function is_language_choice_already_added( $choice_locale, $choice, $settings, $active ) {
			$choice_locale = sanitize_key( $choice_locale );
			$wp_locale     = isset( $choice['wp_locale'] ) ? $this->sanitize_wp_locale( $choice['wp_locale'] ) : $this->sanitize_wp_locale( $choice_locale );
			$wp_key        = sanitize_key( $wp_locale );
			$prefix        = isset( $choice['prefix'] ) ? sanitize_title( $choice['prefix'] ) : $this->locale_to_prefix( $choice_locale );

			if ( isset( $settings['languages'][ $choice_locale ] ) || isset( $settings['languages'][ $wp_key ] ) || isset( $active[ $choice_locale ] ) || isset( $active[ $wp_key ] ) ) {
				return true;
			}

			foreach ( $active as $locale => $language ) {
				$active_wp_locale = isset( $language['wp_locale'] ) ? $this->sanitize_wp_locale( $language['wp_locale'] ) : $this->guess_wp_locale_from_language( $locale );
				$active_prefix    = isset( $language['prefix'] ) ? sanitize_title( $language['prefix'] ) : '';

				if ( '' !== $wp_locale && $active_wp_locale === $wp_locale ) {
					return true;
				}

				if ( '' !== $prefix && '' !== $active_prefix && $active_prefix === $prefix ) {
					return true;
				}
			}

			return false;
		}

/**
		 * Convert a WordPress locale country part to a flag emoji.
		 *
		 * This intentionally only uses explicit country/region parts, for example
		 * es_ES -> ES -> 🇪🇸. For language-only locales such as vi or ja, it falls
		 * back to 🌐 instead of guessing a country incorrectly.
		 *
		 * @param string $locale WordPress locale.
		 * @return string
		 */
		private function locale_to_flag_emoji( $locale ) {
			$locale     = $this->sanitize_wp_locale( $locale );
			$normalized = strtolower( str_replace( '-', '_', $locale ) );
			$base       = $this->get_locale_base_language( $locale );
			$flag_map   = $this->get_locale_flag_map();

			/*
			 * Prefer explicit static mapping first. This covers WordPress locales that
			 * do not include a country part, such as vi, ja, th, id, etc.
			 */
			if ( isset( $flag_map[ $normalized ] ) ) {
				return $flag_map[ $normalized ];
			}

			if ( isset( $flag_map[ $base ] ) ) {
				return $flag_map[ $base ];
			}

			if ( '' === $locale || false === strpos( $locale, '_' ) ) {
				return '🌐';
			}

			$parts   = explode( '_', $locale );
			$country = isset( $parts[1] ) ? strtoupper( preg_replace( '/[^A-Z]/', '', $parts[1] ) ) : '';

			if ( 2 !== strlen( $country ) ) {
				return '🌐';
			}

			$first  = 0x1F1E6 + ( ord( $country[0] ) - ord( 'A' ) );
			$second = 0x1F1E6 + ( ord( $country[1] ) - ord( 'A' ) );

			return html_entity_decode( '&#' . $first . ';&#' . $second . ';', ENT_NOQUOTES, 'UTF-8' );
		}

/**
		 * Static flag map for common WordPress locales.
		 *
		 * This is intentionally only used for UI autofill. Users can still override
		 * the flag manually after selecting a language.
		 *
		 * @return array<string,string>
		 */
		private function get_locale_flag_map() {
			return array(
				'af'    => '🇿🇦',
				'ar'    => '🇸🇦',
				'arq'   => '🇩🇿',
				'ary'   => '🇲🇦',
				'az'    => '🇦🇿',
				'be'    => '🇧🇾',
				'bg'    => '🇧🇬',
				'bn'    => '🇧🇩',
				'bs'    => '🇧🇦',
				'ca'    => '🇪🇸',
				'cs'    => '🇨🇿',
				'cy'    => '🏴',
				'da'    => '🇩🇰',
				'de'    => '🇩🇪',
				'de_at' => '🇦🇹',
				'de_ch' => '🇨🇭',
				'el'    => '🇬🇷',
				'en'    => '🇺🇸',
				'en_us' => '🇺🇸',
				'en_gb' => '🇬🇧',
				'en_ca' => '🇨🇦',
				'en_au' => '🇦🇺',
				'en_nz' => '🇳🇿',
				'en_za' => '🇿🇦',
				'eo'    => '🌐',
				'es'    => '🇪🇸',
				'es_es' => '🇪🇸',
				'es_mx' => '🇲🇽',
				'es_ar' => '🇦🇷',
				'es_cl' => '🇨🇱',
				'es_co' => '🇨🇴',
				'es_pe' => '🇵🇪',
				'es_ve' => '🇻🇪',
				'et'    => '🇪🇪',
				'eu'    => '🇪🇸',
				'fa'    => '🇮🇷',
				'fi'    => '🇫🇮',
				'fr'    => '🇫🇷',
				'fr_be' => '🇧🇪',
				'fr_ca' => '🇨🇦',
				'fr_ch' => '🇨🇭',
				'ga'    => '🇮🇪',
				'gd'    => '🏴',
				'gl'    => '🇪🇸',
				'gu'    => '🇮🇳',
				'he'    => '🇮🇱',
				'hi'    => '🇮🇳',
				'hr'    => '🇭🇷',
				'hu'    => '🇭🇺',
				'hy'    => '🇦🇲',
				'id'    => '🇮🇩',
				'is'    => '🇮🇸',
				'it'    => '🇮🇹',
				'ja'    => '🇯🇵',
				'ka'    => '🇬🇪',
				'kk'    => '🇰🇿',
				'km'    => '🇰🇭',
				'kn'    => '🇮🇳',
				'ko'    => '🇰🇷',
				'ko_kr' => '🇰🇷',
				'lo'    => '🇱🇦',
				'lt'    => '🇱🇹',
				'lv'    => '🇱🇻',
				'mk'    => '🇲🇰',
				'ml'    => '🇮🇳',
				'mn'    => '🇲🇳',
				'mr'    => '🇮🇳',
				'ms'    => '🇲🇾',
				'my'    => '🇲🇲',
				'nb'    => '🇳🇴',
				'nl'    => '🇳🇱',
				'nl_be' => '🇧🇪',
				'nn'    => '🇳🇴',
				'pa'    => '🇮🇳',
				'pl'    => '🇵🇱',
				'ps'    => '🇦🇫',
				'pt'    => '🇵🇹',
				'pt_br' => '🇧🇷',
				'pt_pt' => '🇵🇹',
				'ro'    => '🇷🇴',
				'ru'    => '🇷🇺',
				'si'    => '🇱🇰',
				'sk'    => '🇸🇰',
				'sl'    => '🇸🇮',
				'sq'    => '🇦🇱',
				'sr'    => '🇷🇸',
				'sv'    => '🇸🇪',
				'ta'    => '🇮🇳',
				'te'    => '🇮🇳',
				'th'    => '🇹🇭',
				'tl'    => '🇵🇭',
				'tr'    => '🇹🇷',
				'uk'    => '🇺🇦',
				'ur'    => '🇵🇰',
				'uz'    => '🇺🇿',
				'vi'    => '🇻🇳',
				'zh'    => '🇨🇳',
				'zh_cn' => '🇨🇳',
				'zh_hk' => '🇭🇰',
				'zh_sg' => '🇸🇬',
				'zh_tw' => '🇹🇼',
			);
		}

/**
		 * Get WordPress locale for a plugin language.
		 *
		 * @param string $language        Plugin language code.
		 * @param string $fallback_locale Fallback WordPress locale.
		 * @return string
		 */
		private function get_wp_locale_for_language( $language, $fallback_locale = '' ) {
			$language = sanitize_key( $language );
			$settings = $this->get_settings();

			if ( isset( $settings['languages'][ $language ]['wp_locale'] ) && '' !== $settings['languages'][ $language ]['wp_locale'] ) {
				return $this->resolve_wordpress_locale_for_language( $language, $settings['languages'][ $language ]['wp_locale'] );
			}

			$wp_locale = $this->resolve_wordpress_locale_for_language( $language, '' );

			if ( '' !== $wp_locale ) {
				return $wp_locale;
			}

			return $this->sanitize_wp_locale( $fallback_locale );
		}

/**
		 * Resolve plugin language/base locale to a real WordPress locale.
		 *
		 * Examples:
		 * - de    -> de_DE when WordPress knows de_DE.
		 * - es    -> es_ES when unambiguous.
		 * - es_ES -> es_ES.
		 * - pt    -> pt only if ambiguous between pt_BR and pt_PT.
		 *
		 * @param string $language  Plugin language key.
		 * @param string $wp_locale Optional stored WP locale.
		 * @return string
		 */
		private function resolve_wordpress_locale_for_language( $language, $wp_locale = '' ) {
			$language  = sanitize_key( $language );
			$wp_locale = $this->sanitize_wp_locale( $wp_locale );
			$choices   = $this->get_wordpress_language_choices();

			if ( '' !== $wp_locale ) {
				$wp_key = sanitize_key( $wp_locale );

				if ( isset( $choices[ $wp_key ]['wp_locale'] ) ) {
					return $this->sanitize_wp_locale( $choices[ $wp_key ]['wp_locale'] );
				}
			}

			if ( isset( $choices[ $language ]['wp_locale'] ) ) {
				return $this->sanitize_wp_locale( $choices[ $language ]['wp_locale'] );
			}

			$base = $this->get_locale_base_language( '' !== $wp_locale ? $wp_locale : $language );

			if ( '' !== $base ) {
				$matches = array();

				foreach ( $choices as $choice ) {
					$choice_wp_locale = isset( $choice['wp_locale'] ) ? $this->sanitize_wp_locale( $choice['wp_locale'] ) : '';

					if ( '' !== $choice_wp_locale && $this->get_locale_base_language( $choice_wp_locale ) === $base ) {
						$matches[] = $choice_wp_locale;
					}
				}

				$matches = array_values( array_unique( array_filter( $matches ) ) );

				if ( 1 === count( $matches ) ) {
					return $matches[0];
				}
			}

			if ( '' !== $wp_locale && false !== strpos( $wp_locale, '_' ) ) {
				return $wp_locale;
			}

			return $this->guess_wp_locale_from_language( $language );
		}

/**
		 * Sanitize WordPress locale codes while preserving case and underscore.
		 *
		 * @param string $locale Locale.
		 * @return string
		 */
		private function sanitize_wp_locale( $locale ) {
			$locale = trim( (string) $locale );
			$locale = str_replace( '-', '_', $locale );
			$locale = preg_replace( '/[^A-Za-z0-9_]/', '', $locale );

			if ( ! is_string( $locale ) || '' === $locale ) {
				return '';
			}

			$parts = explode( '_', $locale );

			if ( 1 === count( $parts ) ) {
				return strtolower( $parts[0] );
			}

			return strtolower( $parts[0] ) . '_' . strtoupper( $parts[1] );
		}

/**
		 * Guess WordPress locale from plugin language code.
		 *
		 * @param string $language Plugin language code.
		 * @return string
		 */
		private function guess_wp_locale_from_language( $language ) {
			$language = $this->sanitize_wp_locale( $language );

			if ( '' === $language ) {
				return '';
			}

			$target = $this->normalize_locale_for_compare( $language );
			$locales = $this->get_wordpress_locale_candidates();

			/*
			 * 1. Exact normalized match.
			 * Examples:
			 * - en_us -> en_US
			 * - ko_kr -> ko_KR
			 * - pt_br -> pt_BR
			 */
			foreach ( $locales as $locale ) {
				if ( $this->normalize_locale_for_compare( $locale ) === $target ) {
					return $this->sanitize_wp_locale( $locale );
				}
			}

			/*
			 * 2. Match by base language only when it is unambiguous.
			 * Examples:
			 * - ko -> ko_KR when ko_KR is the only Korean locale known to WordPress.
			 * - de -> de_DE when de_DE is the only German locale known to WordPress.
			 *
			 * If WordPress knows multiple variants such as pt_BR and pt_PT, this will
			 * not guess. The admin should store the exact WordPress locale in settings.
			 */
			$base = $this->get_locale_base_language( $language );

			if ( '' !== $base ) {
				$matches = array();

				foreach ( $locales as $locale ) {
					if ( $this->get_locale_base_language( $locale ) === $base ) {
						$matches[] = $this->sanitize_wp_locale( $locale );
					}
				}

				$matches = array_values( array_unique( array_filter( $matches ) ) );

				if ( 1 === count( $matches ) ) {
					return $matches[0];
				}
			}

			/*
			 * 3. Last fallback: return sanitized input. This allows WordPress-native
			 * locales such as vi or ja to work even when they are not installed yet.
			 */
			return $language;
		}

/**
		 * Get WordPress locale candidates from installed and available translations.
		 *
		 * @return string[]
		 */
		private function get_wordpress_locale_candidates() {
			$locales = array();

			/*
			 * Do not call get_locale() here. This method can run inside the locale /
			 * determine_locale filters, and get_locale() would re-enter the same filter.
			 */
			$site_locale = get_option( 'WPLANG' );

			if ( is_string( $site_locale ) && '' !== $site_locale ) {
				$locales[] = $site_locale;
			}

			foreach ( get_available_languages() as $locale ) {
				if ( is_string( $locale ) && '' !== $locale ) {
					$locales[] = $locale;
				}
			}

			/*
			 * In frontend locale filtering, avoid wp_get_available_translations(). It can
			 * be expensive and may trigger code paths that need locale resolution. Admins
			 * can still enter the exact WP Locale manually in settings for languages that
			 * are not installed yet.
			 */

			$locales = array_map( array( $this, 'sanitize_wp_locale' ), $locales );
			$locales = array_values( array_unique( array_filter( $locales ) ) );

			return $locales;
		}

/**
		 * Normalize locale for loose comparison.
		 *
		 * @param string $locale Locale.
		 * @return string
		 */
		private function normalize_locale_for_compare( $locale ) {
			return strtolower( str_replace( '-', '_', $this->sanitize_wp_locale( $locale ) ) );
		}

/**
		 * Get base language from a locale.
		 *
		 * @param string $locale Locale.
		 * @return string
		 */
		private function get_locale_base_language( $locale ) {
			$locale = $this->normalize_locale_for_compare( $locale );

			if ( '' === $locale ) {
				return '';
			}

			$parts = explode( '_', $locale );

			return isset( $parts[0] ) ? sanitize_key( $parts[0] ) : '';
		}

/**
		 * Check active language.
		 *
		 * @param string $locale Locale.
		 * @return bool
		 */
		private function is_active_language( $locale ) {
			$active = $this->get_active_languages();

			return isset( $active[ sanitize_key( $locale ) ] );
		}

/**
		 * Text label.
		 *
		 * @param string $locale Locale.
		 * @param array  $language Language config.
		 * @return string
		 */
		private function format_language_label_text( $locale, $language ) {
			$flag  = isset( $language['flag'] ) ? trim( $language['flag'] ) : '';
			$label = isset( $language['label'] ) ? $language['label'] : $locale;

			return '' !== $flag ? $flag . ' ' . $label : $label;
		}

/**
		 * HTML label.
		 *
		 * @param string $locale Locale.
		 * @param array  $language Language config.
		 * @return string
		 */
		private function get_language_label_html( $locale, $language ) {
			$label = isset( $language['label'] ) ? $language['label'] : $locale;
			$flag  = isset( $language['flag'] ) ? trim( $language['flag'] ) : '';
			$image = isset( $language['flag_image'] ) ? esc_url_raw( $language['flag_image'] ) : '';
			$parts = array();

			if ( '' !== $image ) {
				$parts[] = sprintf( '<img class="scm-language-flag-image" src="%1$s" alt="" loading="lazy" decoding="async" />', esc_url( $image ) );
			} elseif ( '' !== $flag ) {
				$parts[] = sprintf( '<span class="scm-language-flag-text">%s</span>', esc_html( $flag ) );
			}

			$parts[] = sprintf( '<span class="scm-language-label-text">%s</span>', esc_html( $label ) );

			return implode( ' ', $parts );
		}

/**
		 * Candidate label.
		 *
		 * @param WP_Post $post Post.
		 * @return string
		 */
		private function get_candidate_label( $post ) {
			$title = get_the_title( $post );

			if ( '' === trim( $title ) ) {
				$title = __( '(No title)', 'simple-company-multilingual' );
			}

			return $title . ' #' . $post->ID;
		}
}

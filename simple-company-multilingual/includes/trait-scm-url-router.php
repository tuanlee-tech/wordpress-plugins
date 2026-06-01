<?php
/**
 * SCM URL Router trait.
 *
 * @package Simple_Company_Multilingual
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait SCM_URL_Router {
/**
		 * Copy post meta.
		 *
		 * @param int $source_id Source ID.
		 * @param int $target_id Target ID.
		 * @return void
		 */
		private function ensure_translation_url_slug( $post_id ) {
			$post_id = absint( $post_id );
			$post    = get_post( $post_id );
			$stored  = sanitize_title( get_post_meta( $post_id, self::META_URL_SLUG, true ) );

			/*
			 * Keep an existing stored slug only when it is a real public slug.
			 * Temporary fallbacks such as auto-draft or translation-278 must be rebuilt
			 * as soon as WordPress has a real post_name/title.
			 */
			if ( '' !== $stored && ! $this->is_temporary_translation_slug( $stored ) ) {
				return $stored;
			}

			$candidates = array();

			if ( isset( $_POST['post_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Sanitized only; save_post nonce is checked by caller where relevant.
				$candidates[] = sanitize_title( wp_unslash( $_POST['post_name'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}

			if ( $post instanceof WP_Post ) {
				$candidates[] = sanitize_title( $post->post_name );
				$candidates[] = sanitize_title( $post->post_title );
			}

			$root_id = $this->get_root_post_id( $post_id );

			if ( $root_id > 0 && $root_id !== $post_id ) {
				$root_post = get_post( $root_id );

				if ( $root_post instanceof WP_Post ) {
					$candidates[] = sanitize_title( get_post_meta( $root_id, self::META_URL_SLUG, true ) );
					$candidates[] = sanitize_title( $root_post->post_name );
					$candidates[] = sanitize_title( $root_post->post_title );
				}
			}

			$slug = '';

			foreach ( $candidates as $candidate ) {
				$candidate = sanitize_title( $candidate );

				if ( '' !== $candidate && ! $this->is_temporary_translation_slug( $candidate ) ) {
					$slug = $candidate;
					break;
				}
			}

			/*
			 * Last resort only. This fallback is intentionally considered temporary,
			 * so a later save/permalink generation can replace it with the real slug.
			 */
			if ( '' === $slug ) {
				$slug = 'translation-' . $post_id;
			}

			update_post_meta( $post_id, self::META_URL_SLUG, $slug );

			return $slug;
		}

/**
		 * Sync one public URL slug to every item in the translation group.
		 *
		 * This is required when changing default language. The public URL slug is a
		 * group-level concept, not a per-language WordPress post_name. Otherwise the
		 * old default language and the new default language can disagree about /slug/.
		 *
		 * @param int    $post_id Post ID in the group.
		 * @param string $slug    Optional slug to force.
		 * @return string Synced slug.
		 */
		private function sync_translation_group_url_slug( $post_id, $slug = '' ) {
			$post_id = absint( $post_id );
			$slug    = sanitize_title( $slug );

			if ( '' === $slug || $this->is_temporary_translation_slug( $slug ) ) {
				$slug = $this->ensure_translation_url_slug( $post_id );
			}

			if ( '' === $slug || $this->is_temporary_translation_slug( $slug ) ) {
				return $slug;
			}

			$group = $this->get_translation_group( $post_id );

			foreach ( $group as $sibling_id ) {
				$sibling_id = absint( $sibling_id );

				if ( $sibling_id > 0 ) {
					update_post_meta( $sibling_id, self::META_URL_SLUG, $slug );
				}
			}

			return $slug;
		}

/**
		 * Check temporary/internal slugs that must not become public URLs.
		 *
		 * @param string $slug Slug.
		 * @return bool
		 */
		private function is_temporary_translation_slug( $slug ) {
			$slug = sanitize_title( $slug );

			if ( '' === $slug || 'auto-draft' === $slug || 0 === strpos( $slug, 'auto-draft-' ) ) {
				return true;
			}

			return 1 === preg_match( '/^translation-[0-9]+$/', $slug );
		}

/**
		 * Get shared public URL slug for a translation item.
		 *
		 * The public URL slug is group-level behavior. It must not depend on the
		 * translated post's WordPress post_name, because WordPress may append -2 when
		 * duplicate drafts are created.
		 *
		 * @param int $post_id Post ID.
		 * @return string
		 */
		private function get_translation_url_slug( $post_id ) {
			$post_id = absint( $post_id );
			$slug    = sanitize_title( get_post_meta( $post_id, self::META_URL_SLUG, true ) );

			if ( '' !== $slug && ! $this->is_temporary_translation_slug( $slug ) ) {
				return $slug;
			}

			/*
			 * Repair old temporary stored slugs such as auto-draft or translation-278
			 * and then sync the repaired slug to the whole translation group.
			 */
			$slug = $this->ensure_translation_url_slug( $post_id );

			if ( '' !== $slug && ! $this->is_temporary_translation_slug( $slug ) ) {
				$this->sync_translation_group_url_slug( $post_id, $slug );
				return $slug;
			}

			return $slug;
		}



		private function get_language_by_prefix( $prefix ) {
			$prefix = sanitize_title( $prefix );

			foreach ( $this->get_active_languages() as $locale => $language ) {
				$config_prefix = isset( $language['prefix'] ) ? sanitize_title( $language['prefix'] ) : '';

				if ( '' !== $config_prefix && $config_prefix === $prefix ) {
					return $locale;
				}
			}

			return '';
		}


		/**
		 * Infer a supported custom post type from a prefixed path.
		 *
		 * Example: /vi/event-item/my-event/ can be limited to the event post type
		 * when event's rewrite slug is event-item. Posts/pages keep the previous
		 * shared /prefix/slug/ behavior.
		 *
		 * @param string[] $parts Path parts after the language prefix.
		 * @return string Post type name, or empty string when unknown.
		 */
		private function get_post_type_from_prefixed_path_parts( $parts ) {
			if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
				return '';
			}

			$first = sanitize_title( $parts[0] );

			if ( '' === $first ) {
				return '';
			}

			foreach ( $this->get_supported_post_types() as $post_type ) {
				if ( in_array( $post_type, array( 'post', 'page' ), true ) || ! post_type_exists( $post_type ) ) {
					continue;
				}

				$object = get_post_type_object( $post_type );

				if ( ! $object ) {
					continue;
				}

				$rewrite = is_array( $object->rewrite ) ? $object->rewrite : array();
				$slug    = isset( $rewrite['slug'] ) && '' !== $rewrite['slug'] ? sanitize_title( $rewrite['slug'] ) : sanitize_title( $post_type );

				if ( $slug === $first ) {
					return $post_type;
				}
			}

			return '';
		}


		/**
		 * Parse a prefixed translation URL directly from REQUEST_URI.
		 *
		 * @return array{locale:string,slug:string}
		 */
		private function get_prefixed_translation_from_request_path() {
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$path        = wp_parse_url( esc_url_raw( $request_uri ), PHP_URL_PATH );
			$path        = is_string( $path ) ? trim( $path, '/' ) : '';

			if ( '' === $path ) {
				return array(
					'locale'    => '',
					'slug'      => '',
					'post_type' => '',
				);
			}

			$parts = array_values( array_filter( explode( '/', $path ) ) );

			if ( count( $parts ) < 2 ) {
				return array(
					'locale'    => '',
					'slug'      => '',
					'post_type' => '',
				);
			}

			$prefix     = sanitize_title( $parts[0] );
			$path_parts = array_slice( $parts, 1 );
			$slug       = sanitize_title( end( $path_parts ) );
			$locale     = $this->get_language_by_prefix( $prefix );
			$post_type  = $this->get_post_type_from_prefixed_path_parts( $path_parts );

			if ( '' === $locale || '' === $slug ) {
				return array(
					'locale'    => '',
					'slug'      => '',
					'post_type' => '',
				);
			}

			return array(
				'locale'    => $locale,
				'slug'      => $slug,
				'post_type' => $post_type,
			);
		}


		/**
		 * Parse a language-root homepage request directly from REQUEST_URI.
		 *
		 * Examples:
		 * - /vi/ -> vi
		 * - /de/ -> de
		 *
		 * This is intentionally separate from get_prefixed_translation_from_request_path(),
		 * because homepage URLs have only one path segment.
		 *
		 * @return string Plugin language code, or empty string.
		 */
		private function get_prefixed_home_locale_from_request_path() {
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$path        = wp_parse_url( esc_url_raw( $request_uri ), PHP_URL_PATH );
			$path        = is_string( $path ) ? trim( $path, '/' ) : '';

			if ( '' === $path || false !== strpos( $path, '/' ) ) {
				return '';
			}

			$locale = $this->get_language_by_prefix( sanitize_title( $path ) );

			if ( '' === $locale || $locale === $this->get_default_language() ) {
				return '';
			}

			return $locale;
		}

		/**
		 * Get translated static front page ID for a target language.
		 *
		 * @param string $language Target language.
		 * @return int
		 */
		private function get_static_front_page_translation_id_for_language( $language ) {
			$language      = sanitize_key( $language );
			$front_page_id = absint( get_option( 'page_on_front' ) );

			if ( $front_page_id <= 0 || 'page' !== get_option( 'show_on_front' ) || '' === $language ) {
				return 0;
			}

			if ( $language === $this->get_default_language() ) {
				return $front_page_id;
			}

			$group = $this->get_translation_group( $front_page_id );

			$translated_id = isset( $group[ $language ] ) ? absint( $group[ $language ] ) : 0;

			/*
			 * Repair legacy/inconsistent homepage groups. Some sites have the
			 * translated homepage linked by _scm_translation_group but missing, stale,
			 * or mismatched _scm_language meta. In that state /vi/ is parsed correctly
			 * but no translated front page is found, so WordPress falls through to
			 * "Nothing Found". Reuse the group-repair path before giving up.
			 */
			if ( $translated_id <= 0 ) {
				$front_slug    = $this->get_translation_url_slug( $front_page_id );
				$translated_id = $this->repair_missing_language_in_group( $front_page_id, $language, $front_slug );
			}

			if ( $translated_id <= 0 ) {
				return 0;
			}

			$post = get_post( $translated_id );

			if ( ! $post instanceof WP_Post || 'page' !== $post->post_type || 'trash' === $post->post_status ) {
				return 0;
			}

			update_post_meta( $translated_id, self::META_LANGUAGE, $language );
			update_post_meta( $translated_id, self::META_GROUP, $this->get_group_id( $front_page_id ) );

			return $translated_id;
		}

/**
		 * Register rewrite rules for prefixed post translations.
		 *
		 * Pages use WordPress-native parent pages such as /vi/about/.
		 * Posts do not have parents, so we add rewrite rules for /vi/post-slug/.
		 *
		 * @return void
		 */
		public function register_rewrite_rules() {
			foreach ( $this->get_active_languages() as $locale => $language ) {
				if ( $locale === $this->get_default_language() ) {
					continue;
				}

				$prefix = isset( $language['prefix'] ) ? sanitize_title( $language['prefix'] ) : '';

				if ( '' === $prefix ) {
					continue;
				}

				add_rewrite_rule(
					'^' . preg_quote( $prefix, '#' ) . '/?$',
					'index.php?scm_lang_prefix=' . $prefix . '&scm_translation_home=1',
					'top'
				);

				add_rewrite_rule(
					'^' . preg_quote( $prefix, '#' ) . '/(.+?)/?$',
					'index.php?scm_lang_prefix=' . $prefix . '&scm_translation_slug=$matches[1]',
					'top'
				);
			}
		}

/**
		 * Register custom query vars.
		 *
		 * @param array $vars Query vars.
		 * @return array
		 */
		public function register_query_vars( $vars ) {
			$vars[] = 'scm_lang_prefix';
			$vars[] = 'scm_translation_slug';
			$vars[] = 'scm_translation_home';

			return $vars;
		}

/**
		 * Resolve /prefix/post-slug/ requests to the translated post.
		 *
		 * @param array $vars Request vars.
		 * @return array
		 */
		public function resolve_prefixed_translation_request( $vars ) {
			$locale = '';
			$slug   = '';
			$requested_post_type = '';

			/*
			 * Language-root homepage URLs, for example /vi/ or /de/.
			 * These should resolve to the translated static front page, not to a
			 * normal page with slug "vi" and not to the default homepage.
			 */
			$home_locale = $this->get_prefixed_home_locale_from_request_path();

			if ( '' === $home_locale && ! empty( $vars['scm_lang_prefix'] ) && ! empty( $vars['scm_translation_home'] ) ) {
				$home_locale = $this->get_language_by_prefix( $vars['scm_lang_prefix'] );
			}

			if ( '' !== $home_locale ) {
				$home_id = $this->get_static_front_page_translation_id_for_language( $home_locale );

				if ( $home_id > 0 ) {
					return array(
						'page_id' => $home_id,
					);
				}
			}

			/*
			 * Highest-priority resolver: parse the actual request path.
			 *
			 * This fixes translated static homepage URLs such as /vi/home/.
			 * Depending on the current rewrite state, WordPress can parse that URL as
			 * pagename=vi/home or even miss this plugin's custom query vars. Reading
			 * REQUEST_URI here keeps the router independent from rewrite-rule order.
			 */
			$path_request = $this->get_prefixed_translation_from_request_path();

			if ( ! empty( $path_request['locale'] ) && ! empty( $path_request['slug'] ) ) {
				$locale = $path_request['locale'];
				$slug   = $path_request['slug'];
				$requested_post_type = ! empty( $path_request['post_type'] ) ? sanitize_key( $path_request['post_type'] ) : '';
			}

			/*
			 * Non-default language URL, for example:
			 * /en/hello-world/
			 * /ko/hello-world/
			 */
			if ( '' === $locale && ! empty( $vars['scm_lang_prefix'] ) && ! empty( $vars['scm_translation_slug'] ) ) {
				$locale = $this->get_language_by_prefix( $vars['scm_lang_prefix'] );
				$slug   = sanitize_title( $vars['scm_translation_slug'] );
			} elseif ( '' === $locale && ! empty( $vars['pagename'] ) && false !== strpos( $vars['pagename'], '/' ) ) {
				/*
				 * Fallback for WordPress native page parsing: pagename=vi/home.
				 */
				$parts  = array_values( array_filter( explode( '/', (string) $vars['pagename'] ) ) );
				$prefix = isset( $parts[0] ) ? sanitize_title( $parts[0] ) : '';
				$locale = $this->get_language_by_prefix( $prefix );
				$slug   = isset( $parts[1] ) ? sanitize_title( $parts[1] ) : '';
			} elseif ( '' === $locale && empty( $vars['scm_lang_prefix'] ) ) {
				/*
				 * Default language URL, for example /hello-world/.
				 */
				if ( ! empty( $vars['name'] ) ) {
					$slug = sanitize_title( $vars['name'] );
				} elseif ( ! empty( $vars['pagename'] ) && false === strpos( $vars['pagename'], '/' ) ) {
					$slug = sanitize_title( $vars['pagename'] );
				}

				if ( '' !== $slug ) {
					$locale = $this->get_default_language();
				}
			}

			if ( '' === $locale || '' === $slug ) {
				return $vars;
			}

			$post_status = is_user_logged_in() ? array( 'publish', 'draft', 'pending', 'private' ) : array( 'publish' );
			$target_id   = $this->find_translation_by_language_slug( $locale, $slug, $post_status, $requested_post_type );

			if ( $target_id <= 0 ) {
				return $vars;
			}

			$target = get_post( $target_id );

			if ( ! $target instanceof WP_Post ) {
				return $vars;
			}

			if ( 'page' === $target->post_type ) {
				return array(
					'page_id' => $target_id,
				);
			}

			if ( 'post' === $target->post_type ) {
				return array(
					'p' => $target_id,
				);
			}

			return array(
				'p'         => $target_id,
				'post_type' => $target->post_type,
			);
		}

/**
		 * Find a translation by language and shared public URL slug.
		 *
		 * @param string       $locale      Locale.
		 * @param string       $slug        Shared public slug.
		 * @param string|array $post_status Allowed post statuses.
		 * @return int
		 */
		private function find_translation_by_language_slug( $locale, $slug, $post_status, $post_type = '' ) {
			$locale = sanitize_key( $locale );
			$slug   = sanitize_title( $slug );
			$post_type = sanitize_key( $post_type );
			$query_post_types = ( '' !== $post_type && $this->is_supported_post_type( $post_type ) ) ? array( $post_type ) : $this->get_supported_post_types();

			/*
			 * Fast path: normal data where the target already has both language and
			 * group URL slug metadata.
			 */
			$query = new WP_Query(
				array(
					'post_type'                 => $query_post_types,
					'post_status'               => $post_status,
					'posts_per_page'            => 1,
					'fields'                    => 'ids',
					'no_found_rows'             => true,
					'update_post_meta_cache'    => false,
					'update_post_term_cache'    => false,
					'scm_include_all_languages' => true,
					'meta_query'                => array(
						'relation' => 'AND',
						array(
							'key'     => self::META_LANGUAGE,
							'value'   => $locale,
							'compare' => '=',
						),
						array(
							'key'     => self::META_URL_SLUG,
							'value'   => $slug,
							'compare' => '=',
						),
					),
				)
			);

			if ( ! empty( $query->posts ) ) {
				return absint( $query->posts[0] );
			}

			/*
			 * Repair path for groups created while a different language was default.
			 *
			 * The root may have no _scm_language because older saves relied on the
			 * current default language. After changing default, /ko/slug/ cannot be
			 * found by the fast query above. So we load every item with the same shared
			 * public slug, inspect each translation group, infer missing language meta,
			 * persist the repair, then return the matching item.
			 */
			$slug_query = new WP_Query(
				array(
					'post_type'                 => $query_post_types,
					'post_status'               => $post_status,
					'posts_per_page'            => -1,
					'fields'                    => 'ids',
					'no_found_rows'             => true,
					'update_post_meta_cache'    => true,
					'update_post_term_cache'    => false,
					'scm_include_all_languages' => true,
					'meta_query'                => array(
						array(
							'key'     => self::META_URL_SLUG,
							'value'   => $slug,
							'compare' => '=',
						),
					),
				)
			);

			$seen_groups = array();

			foreach ( $slug_query->posts as $candidate_id ) {
				$candidate_id = absint( $candidate_id );
				$group_id     = $this->get_group_id( $candidate_id );

				if ( isset( $seen_groups[ $group_id ] ) ) {
					continue;
				}

				$seen_groups[ $group_id ] = true;
				$group                   = $this->get_translation_group( $candidate_id );

				foreach ( $group as $group_locale => $group_post_id ) {
					$group_post_id = absint( $group_post_id );

					if ( $group_post_id > 0 ) {
						update_post_meta( $group_post_id, self::META_LANGUAGE, sanitize_key( $group_locale ) );
						update_post_meta( $group_post_id, self::META_URL_SLUG, $slug );
					}
				}

				if ( ! empty( $group[ $locale ] ) ) {
					return absint( $group[ $locale ] );
				}

				$repaired_id = $this->repair_missing_language_in_group( $candidate_id, $locale, $slug );

				if ( $repaired_id > 0 ) {
					return $repaired_id;
				}
			}

			/*
			 * Backward-compatible fallback for very old content that has post_name but
			 * no _scm_translation_url_slug yet.
			 */
			$fallback_query = new WP_Query(
				array(
					'post_type'                 => $query_post_types,
					'post_status'               => $post_status,
					'name'                      => $slug,
					'posts_per_page'            => 1,
					'fields'                    => 'ids',
					'no_found_rows'             => true,
					'update_post_meta_cache'    => true,
					'update_post_term_cache'    => false,
					'scm_include_all_languages' => true,
				)
			);

			foreach ( $fallback_query->posts as $fallback_id ) {
				$fallback_id = absint( $fallback_id );
				$this->sync_translation_group_url_slug( $fallback_id, $slug );
				$repaired_id = $this->repair_missing_language_in_group( $fallback_id, $locale, $slug );

				if ( $repaired_id > 0 ) {
					return $repaired_id;
				}

				if ( $this->get_post_language( $fallback_id ) === $locale ) {
					return $fallback_id;
				}
			}

			return 0;
		}

/**
		 * Repair a group where one item is missing language meta.
		 *
		 * @param int    $post_id          Any post ID in the group.
		 * @param string $requested_locale Requested locale.
		 * @param string $slug             Shared public slug.
		 * @return int Repaired post ID, or 0.
		 */
		private function repair_missing_language_in_group( $post_id, $requested_locale, $slug ) {
			$post_id          = absint( $post_id );
			$requested_locale = sanitize_key( $requested_locale );
			$slug             = sanitize_title( $slug );
			$group_id         = $this->get_group_id( $post_id );
			$active_locales   = array_keys( $this->get_active_languages() );

			$query = new WP_Query(
				array(
					'post_type'                 => $this->get_supported_post_types(),
					'post_status'               => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'posts_per_page'            => -1,
					'fields'                    => 'ids',
					'no_found_rows'             => true,
					'update_post_meta_cache'    => true,
					'update_post_term_cache'    => false,
					'scm_include_all_languages' => true,
					'meta_query'                => array(
						array(
							'key'     => self::META_GROUP,
							'value'   => $group_id,
							'compare' => '=',
						),
					),
				)
			);

			$ids = array_map( 'absint', $query->posts );

			if ( $group_id > 0 && ! in_array( $group_id, $ids, true ) ) {
				$ids[] = $group_id;
			}

			$used     = array();
			$missing  = array();
			$unlabeled = array();

			foreach ( $ids as $id ) {
				$lang = sanitize_key( get_post_meta( $id, self::META_LANGUAGE, true ) );

				if ( '' !== $lang && in_array( $lang, $active_locales, true ) ) {
					$used[] = $lang;
				} else {
					$unlabeled[] = $id;
				}
			}

			$missing = array_values( array_diff( $active_locales, array_unique( $used ) ) );

			if ( in_array( $requested_locale, $missing, true ) && 1 === count( $unlabeled ) ) {
				$target_id = absint( $unlabeled[0] );
				update_post_meta( $target_id, self::META_LANGUAGE, $requested_locale );
				update_post_meta( $target_id, self::META_GROUP, $group_id );
				update_post_meta( $target_id, self::META_URL_SLUG, $slug );

				return $target_id;
			}

			return 0;
		}

/**
		 * Apply language prefix to translated post permalinks.
		 *
		 * @param string  $permalink Permalink.
		 * @param WP_Post $post      Post.
		 * @return string
		 */
		public function filter_post_translation_permalink( $permalink, $post ) {
			if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
				return $permalink;
			}

			return $this->get_public_translation_permalink( $post->ID, $permalink );
		}

/**
		 * Apply language prefix to translated page permalinks.
		 *
		 * @param string $permalink Page permalink.
		 * @param int    $post_id   Page ID.
		 * @return string
		 */
		public function filter_page_translation_permalink( $permalink, $post_id ) {
			$post_id = absint( $post_id );
			$post    = get_post( $post_id );

			if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
				return $permalink;
			}

			return $this->get_public_translation_permalink( $post_id, $permalink );
		}

/**
		 * Apply language prefix to translated custom post type permalinks.
		 *
		 * @param string  $permalink Permalink.
		 * @param WP_Post $post      Post.
		 * @return string
		 */
		public function filter_custom_post_type_translation_permalink( $permalink, $post ) {
			if ( ! $post instanceof WP_Post || in_array( $post->post_type, array( 'post', 'page' ), true ) || ! $this->is_supported_post_type( $post->post_type ) ) {
				return $permalink;
			}

			return $this->get_public_translation_permalink( $post->ID, $permalink );
		}

/**
		 * Get public multilingual permalink for a post/page.
		 *
		 * This intentionally uses _scm_translation_url_slug instead of post_name.
		 * WordPress may internally append -2 to duplicated content, but public
		 * multilingual URLs must stay stable when the default language changes.
		 *
		 * @param int    $post_id       Post ID.
		 * @param string $fallback_url  Original WordPress permalink.
		 * @return string
		 */
		private function get_public_translation_permalink( $post_id, $fallback_url ) {
			$post_id = absint( $post_id );
			$post    = get_post( $post_id );

			if ( ! $post instanceof WP_Post || ! $this->is_supported_post_type( $post->post_type ) ) {
				return $fallback_url;
			}

			$locale = $this->get_post_language( $post_id );

			if ( 'page' === $post->post_type && 'page' === get_option( 'show_on_front' ) ) {
				$front_translation_id = $this->get_static_front_page_translation_id_for_language( $locale );

				if ( $front_translation_id > 0 && $front_translation_id === $post_id ) {
					if ( $locale === $this->get_default_language() ) {
						return home_url( '/' );
					}

					$active = $this->get_active_languages();
					$prefix = isset( $active[ $locale ]['prefix'] ) ? sanitize_title( $active[ $locale ]['prefix'] ) : '';

					if ( '' === $prefix ) {
						$prefix = $this->locale_to_prefix( $locale );
					}

					if ( '' !== $prefix ) {
						return home_url( user_trailingslashit( $prefix ) );
					}
				}
			}

			$slug   = $this->get_translation_url_slug( $post_id );

			if ( '' === $slug ) {
				return $fallback_url;
			}

			if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
				if ( $locale === $this->get_default_language() ) {
					return $fallback_url;
				}

				$active = $this->get_active_languages();
				$prefix = isset( $active[ $locale ]['prefix'] ) ? sanitize_title( $active[ $locale ]['prefix'] ) : '';

				if ( '' === $prefix ) {
					return $fallback_url;
				}

				$path = wp_parse_url( $fallback_url, PHP_URL_PATH );
				$path = is_string( $path ) ? trim( $path, '/' ) : '';

				if ( '' === $path ) {
					return $fallback_url;
				}

				return home_url( user_trailingslashit( $prefix . '/' . $path ) );
			}

			if ( $locale === $this->get_default_language() ) {
				return home_url( user_trailingslashit( $slug ) );
			}

			$active = $this->get_active_languages();
			$prefix = isset( $active[ $locale ]['prefix'] ) ? sanitize_title( $active[ $locale ]['prefix'] ) : '';

			if ( '' === $prefix ) {
				return $fallback_url;
			}

			return home_url( user_trailingslashit( $prefix . '/' . $slug ) );
		}
}

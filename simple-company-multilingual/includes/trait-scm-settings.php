<?php
/**
 * SCM Settings trait.
 *
 * @package Simple_Company_Multilingual
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait SCM_Settings {
/**
		 * Flush rewrite rules after multilingual URL settings change.
		 *
		 * Settings are saved in options.php after admin_init has already fired, so
		 * sanitize_settings() only sets a short-lived flag. On the next admin request,
		 * rewrite rules are registered on init using the updated settings, then this
		 * method flushes them safely.
		 *
		 * @return void
		 */
		public function maybe_flush_rewrite_rules() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			if ( ! get_transient( 'scm_flush_rewrite_rules' ) ) {
				return;
			}

			delete_transient( 'scm_flush_rewrite_rules' );
			flush_rewrite_rules( false );
		}

/**
		 * Register settings page.
		 *
		 * @return void
		 */
		public function register_settings_page() {
			add_options_page(
				__( 'Company Multilingual', 'simple-company-multilingual' ),
				__( 'Company Multilingual', 'simple-company-multilingual' ),
				'manage_options',
				'simple-company-multilingual',
				array( $this, 'render_settings_page' )
			);
		}

/**
		 * Register settings.
		 *
		 * @return void
		 */
		public function register_settings() {
			register_setting(
				'scm_settings_group',
				self::OPTION_KEY,
				array(
					'type'              => 'array',
					'sanitize_callback' => array( $this, 'sanitize_settings' ),
					'default'           => $this->get_default_settings(),
				)
			);
		}

/**
		 * Install a WordPress language pack if it is available and not installed yet.
		 *
		 * This mirrors the useful part of Settings > General > Site Language: when a
		 * new language is added to this plugin, WordPress core/theme strings can be
		 * translated because the matching language pack exists locally.
		 *
		 * @param string $wp_locale WordPress locale, for example vi, ko_KR, de_DE.
		 * @return true|WP_Error True when installed/already available, WP_Error on failure.
		 */
		private function maybe_install_language_pack( $wp_locale ) {
			$wp_locale = $this->sanitize_wp_locale( $wp_locale );

			if ( '' === $wp_locale || 'en_US' === $wp_locale ) {
				return true;
			}

			$installed = get_available_languages();

			if ( in_array( $wp_locale, $installed, true ) ) {
				return true;
			}

			if ( ! current_user_can( 'install_languages' ) && ! current_user_can( 'update_core' ) && ! current_user_can( 'manage_options' ) ) {
				return new WP_Error( 'scm_language_permission', __( 'You do not have permission to install language packs.', 'simple-company-multilingual' ) );
			}

			if ( ! function_exists( 'wp_download_language_pack' ) ) {
				require_once ABSPATH . 'wp-admin/includes/translation-install.php';
			}

			if ( ! function_exists( 'wp_download_language_pack' ) ) {
				return new WP_Error( 'scm_language_installer_missing', __( 'WordPress language installer is not available.', 'simple-company-multilingual' ) );
			}

			$result = wp_download_language_pack( $wp_locale );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( false === $result ) {
				return new WP_Error(
					'scm_language_pack_unavailable',
					sprintf(
						/* translators: %s: WordPress locale. */
						__( 'Language pack for %s is not available or could not be installed.', 'simple-company-multilingual' ),
						$wp_locale
					)
				);
			}

			return true;
		}

/**
		 * Queue language pack installation result notice.
		 *
		 * @param string        $wp_locale Locale.
		 * @param true|WP_Error $result    Install result.
		 * @return void
		 */
		private function queue_language_pack_notice( $wp_locale, $result ) {
			$notices = get_transient( 'scm_language_pack_notices' );

			if ( ! is_array( $notices ) ) {
				$notices = array();
			}

			if ( is_wp_error( $result ) ) {
				$notices[] = array(
					'type'    => 'warning',
					'message' => sprintf(
						/* translators: 1: locale, 2: error message. */
						__( 'Could not install language pack %1$s: %2$s', 'simple-company-multilingual' ),
						$wp_locale,
						$result->get_error_message()
					),
				);
			} else {
				$notices[] = array(
					'type'    => 'success',
					'message' => sprintf(
						/* translators: %s: locale. */
						__( 'Language pack %s is installed or already available.', 'simple-company-multilingual' ),
						$wp_locale
					),
				);
			}

			set_transient( 'scm_language_pack_notices', $notices, MINUTE_IN_SECONDS );
		}

/**
		 * Render language pack install notices.
		 *
		 * @return void
		 */
		public function render_language_pack_notices() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$notices = get_transient( 'scm_language_pack_notices' );

			if ( empty( $notices ) || ! is_array( $notices ) ) {
				return;
			}

			delete_transient( 'scm_language_pack_notices' );

			foreach ( $notices as $notice ) {
				$type    = isset( $notice['type'] ) && 'success' === $notice['type'] ? 'success' : 'warning';
				$message = isset( $notice['message'] ) ? $notice['message'] : '';

				if ( '' === $message ) {
					continue;
				}

				printf(
					'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
					esc_attr( $type ),
					esc_html( $message )
				);
			}
		}

/**
		 * Queue a generic settings notice.
		 *
		 * @param string $type    Notice type.
		 * @param string $message Message.
		 * @return void
		 */
		private function queue_settings_notice( $type, $message ) {
			$notices = get_transient( 'scm_language_pack_notices' );

			if ( ! is_array( $notices ) ) {
				$notices = array();
			}

			$notices[] = array(
				'type'    => 'success' === $type ? 'success' : 'warning',
				'message' => sanitize_text_field( $message ),
			);

			set_transient( 'scm_language_pack_notices', $notices, MINUTE_IN_SECONDS );
		}

/**
		 * Sanitize settings.
		 *
		 * @param array $input Raw input.
		 * @return array
		 */
		public function sanitize_settings( $input ) {
			$settings = $this->get_default_settings();
			$catalog  = $this->get_language_catalog();
			$language_packs_to_install = array();

			if ( ! is_array( $input ) ) {
				return $settings;
			}

			$delete_custom_language = isset( $input['delete_custom_language'] ) ? sanitize_key( wp_unslash( $input['delete_custom_language'] ) ) : '';

			$settings['default_language'] = isset( $input['default_language'] ) ? sanitize_key( wp_unslash( $input['default_language'] ) ) : '';

			foreach ( array( 'auto_append_switcher', 'floating_switcher', 'filter_frontend_lists', 'auto_create_translation_drafts', 'delete_data_on_uninstall' ) as $key ) {
				$settings[ $key ] = isset( $input[ $key ] ) && 'yes' === $input[ $key ] ? 'yes' : 'no';
			}

			if ( isset( $input['floating_position'] ) ) {
				$position = sanitize_key( wp_unslash( $input['floating_position'] ) );

				if ( in_array( $position, array( 'bottom-right', 'bottom-left', 'top-right', 'top-left' ), true ) ) {
					$settings['floating_position'] = $position;
				}
			}

			if ( isset( $input['custom_languages'] ) && is_array( $input['custom_languages'] ) ) {
				foreach ( $input['custom_languages'] as $locale => $config ) {
					$locale = sanitize_key( $locale );

					if ( '' === $locale || $locale === $delete_custom_language || ! is_array( $config ) ) {
						continue;
					}

					$label  = isset( $config['label'] ) ? sanitize_text_field( wp_unslash( $config['label'] ) ) : '';
					$prefix = isset( $config['prefix'] ) ? sanitize_title( wp_unslash( $config['prefix'] ) ) : '';

					$settings['custom_languages'][ $locale ] = array(
						'label'  => '' !== $label ? $label : $locale,
						'prefix' => '' !== $prefix ? $prefix : $this->locale_to_prefix( $locale ),
					);
				}
			}

			if ( isset( $input['new_language'] ) && is_array( $input['new_language'] ) ) {
				$new_locale = isset( $input['new_language']['locale'] ) ? sanitize_key( wp_unslash( $input['new_language']['locale'] ) ) : '';
				$new_label  = isset( $input['new_language']['label'] ) ? sanitize_text_field( wp_unslash( $input['new_language']['label'] ) ) : '';
				$new_prefix = isset( $input['new_language']['prefix'] ) ? sanitize_title( wp_unslash( $input['new_language']['prefix'] ) ) : '';
				$new_flag   = isset( $input['new_language']['flag'] ) ? sanitize_text_field( wp_unslash( $input['new_language']['flag'] ) ) : '';
				$next_language_order = 10;

				if ( isset( $input['languages'] ) && is_array( $input['languages'] ) ) {
					foreach ( $input['languages'] as $existing_language ) {
						if ( is_array( $existing_language ) && isset( $existing_language['order'] ) ) {
							$next_language_order = max( $next_language_order, absint( wp_unslash( $existing_language['order'] ) ) + 10 );
						}
					}
				}


				if ( '' !== $new_locale ) {
					if ( isset( $settings['languages'][ $new_locale ] ) || isset( $settings['custom_languages'][ $new_locale ] ) || $new_locale === $this->get_default_language() ) {
						$this->queue_settings_notice(
							'warning',
							sprintf(
								/* translators: %s: locale. */
								__( 'Language %s already exists and was not added again.', 'simple-company-multilingual' ),
								$new_locale
							)
						);
					} else {
					$wordpress_choices = $this->get_wordpress_language_choices();
					$catalog_item      = isset( $catalog[ $new_locale ] ) ? $catalog[ $new_locale ] : array(
						'label'  => isset( $wordpress_choices[ $new_locale ]['label'] ) ? $wordpress_choices[ $new_locale ]['label'] : $new_locale,
						'prefix' => $this->locale_to_prefix( $new_locale ),
					);

					if ( '' === $new_label ) {
						$new_label = isset( $catalog_item['label'] ) && '' !== $catalog_item['label'] ? $catalog_item['label'] : $new_locale;
					}

					if ( '' === $new_prefix ) {
						$new_prefix = isset( $catalog_item['prefix'] ) && '' !== $catalog_item['prefix'] ? sanitize_title( $catalog_item['prefix'] ) : $this->locale_to_prefix( $new_locale );
					}

					$settings['custom_languages'][ $new_locale ] = array(
						'label'  => $new_label,
						'prefix' => $new_prefix,
					);

					$new_wp_locale = isset( $input['new_language']['wp_locale'] ) ? $this->sanitize_wp_locale( wp_unslash( $input['new_language']['wp_locale'] ) ) : '';

					$new_wp_locale = $this->resolve_wordpress_locale_for_language( $new_locale, $new_wp_locale );

					$settings['languages'][ $new_locale ] = array(
						'enabled'    => 'yes',
						'prefix'     => $new_prefix,
						'flag'       => $new_flag,
						'flag_image' => '',
						'wp_locale'  => $new_wp_locale,
						'order'      => $next_language_order,
					);

					if ( '' !== $new_wp_locale ) {
						$language_packs_to_install[] = $new_wp_locale;
					}
					}
				}
			}

			if ( isset( $input['languages'] ) && is_array( $input['languages'] ) ) {
				foreach ( $input['languages'] as $locale => $config ) {
					$locale = sanitize_key( $locale );

					if ( '' === $locale || $locale === $delete_custom_language || ! is_array( $config ) ) {
						continue;
					}

					if ( ! isset( $config['enabled'] ) || 'yes' !== $config['enabled'] ) {
						continue;
					}

					$prefix = isset( $config['prefix'] ) ? sanitize_title( wp_unslash( $config['prefix'] ) ) : '';
					$label  = isset( $config['label'] ) ? sanitize_text_field( wp_unslash( $config['label'] ) ) : '';
					$flag   = isset( $config['flag'] ) ? sanitize_text_field( wp_unslash( $config['flag'] ) ) : '';
					$image  = isset( $config['flag_image'] ) ? esc_url_raw( wp_unslash( $config['flag_image'] ) ) : '';
					$wp_locale = isset( $config['wp_locale'] ) ? $this->sanitize_wp_locale( wp_unslash( $config['wp_locale'] ) ) : '';
					$order     = isset( $config['order'] ) ? max( 0, absint( wp_unslash( $config['order'] ) ) ) : 999;

					if ( '' === $prefix ) {
						if ( isset( $catalog[ $locale ]['prefix'] ) && '' !== $catalog[ $locale ]['prefix'] ) {
							$prefix = sanitize_title( $catalog[ $locale ]['prefix'] );
						} else {
							$prefix = $this->locale_to_prefix( $locale );
						}
					}

					$settings['languages'][ $locale ] = array(
						'enabled'    => 'yes',
						'label'      => $label,
						'prefix'     => $prefix,
						'flag'       => $flag,
						'flag_image' => $image,
						'wp_locale'  => $this->resolve_wordpress_locale_for_language( $locale, $wp_locale ),
						'order'      => $order,
					);


				}
			}

			/*
			* Save menu mappings.
			*/
			if ( isset( $input['menu_mappings'] ) && is_array( $input['menu_mappings'] ) ) {
				foreach ( $input['menu_mappings'] as $location => $language_menus ) {
					$location = sanitize_key( $location );

					if ( '' === $location || ! is_array( $language_menus ) ) {
						continue;
					}

					foreach ( $language_menus as $locale => $menu_id ) {
						$locale  = sanitize_key( $locale );
						$menu_id = absint( $menu_id );

						if ( '' === $locale || $menu_id <= 0 ) {
							continue;
						}

						$settings['menu_mappings'][ $location ][ $locale ] = $menu_id;
					}
				}
			}

			if ( isset( $input['theme_element_overrides'] ) && is_array( $input['theme_element_overrides'] ) ) {
				foreach ( $input['theme_element_overrides'] as $key => $override ) {
					$key = sanitize_key( $key );

					if ( '' === $key || ! is_array( $override ) ) {
						continue;
					}

					if ( isset( $input['delete_theme_element_override'] ) && sanitize_key( wp_unslash( $input['delete_theme_element_override'] ) ) === $key ) {
						continue;
					}

					$selector = isset( $override['selector'] ) ? sanitize_text_field( wp_unslash( $override['selector'] ) ) : '';
					$mode     = isset( $override['mode'] ) && 'html' === $override['mode'] ? 'html' : 'text';

					if ( '' === $selector ) {
						continue;
					}

					$settings['theme_element_overrides'][ $key ] = array(
						'selector'     => $selector,
						'mode'         => $mode,
						'translations' => array(),
					);

					if ( isset( $override['translations'] ) && is_array( $override['translations'] ) ) {
						foreach ( $override['translations'] as $locale => $value ) {
							$locale = sanitize_key( $locale );

							if ( '' === $locale ) {
								continue;
							}

							if ( 'html' === $mode ) {
								$value = wp_kses_post( wp_unslash( $value ) );
							} else {
								$value = sanitize_text_field( wp_unslash( $value ) );
							}

							$settings['theme_element_overrides'][ $key ]['translations'][ $locale ] = $value;
						}
					}
				}
			}

			if ( isset( $input['new_theme_element_override'] ) && is_array( $input['new_theme_element_override'] ) ) {
				$new_key      = isset( $input['new_theme_element_override']['key'] ) ? sanitize_key( wp_unslash( $input['new_theme_element_override']['key'] ) ) : '';
				$new_selector = isset( $input['new_theme_element_override']['selector'] ) ? sanitize_text_field( wp_unslash( $input['new_theme_element_override']['selector'] ) ) : '';
				$new_mode     = isset( $input['new_theme_element_override']['mode'] ) && 'html' === $input['new_theme_element_override']['mode'] ? 'html' : 'text';

				if ( '' !== $new_key && '' !== $new_selector && ! isset( $settings['theme_element_overrides'][ $new_key ] ) ) {
					$settings['theme_element_overrides'][ $new_key ] = array(
						'selector'     => $new_selector,
						'mode'         => $new_mode,
						'translations' => array(),
					);
				}
			}
			/*
			 * Default language, language prefixes and active language list affect
			 * frontend URLs such as /slug/ and /en/slug/. Mark rewrite rules for a
			 * safe refresh on the next admin request so users do not need to visit
			 * Settings → Permalinks manually.
			 */
			if ( isset( $input['string_sources'] ) && is_array( $input['string_sources'] ) ) {
				foreach ( $input['string_sources'] as $source ) {
					$source = sanitize_text_field( wp_unslash( $source ) );

					if ( '' !== $source ) {
						$settings['string_sources'][] = $source;
					}
				}
			}

			if ( isset( $input['new_string_source'] ) ) {
				$new_source = sanitize_text_field( wp_unslash( $input['new_string_source'] ) );

				if ( '' !== $new_source ) {
					$settings['string_sources'][] = $new_source;
				}
			}

			$settings['string_sources'] = array_values( array_unique( array_filter( $settings['string_sources'] ) ) );

			if ( isset( $input['delete_string_source'] ) ) {
				$delete_source = sanitize_text_field( wp_unslash( $input['delete_string_source'] ) );

				if ( '' !== $delete_source ) {
					$settings['string_sources'] = array_values( array_diff( $settings['string_sources'], array( $delete_source ) ) );
					unset( $settings['string_overrides'][ $delete_source ] );
				}
			}

			if ( isset( $input['string_overrides'] ) && is_array( $input['string_overrides'] ) ) {
				foreach ( $input['string_overrides'] as $source => $translations ) {
					$source = sanitize_text_field( wp_unslash( $source ) );

					if ( '' === $source || ! is_array( $translations ) ) {
						continue;
					}

					foreach ( $translations as $locale => $value ) {
						$locale = sanitize_key( $locale );
						$value  = sanitize_text_field( wp_unslash( $value ) );

						if ( '' !== $locale && '' !== $value ) {
							$settings['string_overrides'][ $source ][ $locale ] = $value;
						}
					}
				}
			}

			$language_packs_to_install = array_values( array_unique( array_filter( array_map( array( $this, 'sanitize_wp_locale' ), $language_packs_to_install ) ) ) );

			foreach ( $language_packs_to_install as $wp_locale ) {
				$result = $this->maybe_install_language_pack( $wp_locale );
				$this->queue_language_pack_notice( $wp_locale, $result );
			}

			set_transient( 'scm_flush_rewrite_rules', 1, MINUTE_IN_SECONDS );

			return $settings;
		}


		/**
		 * Enqueue admin assets.
		 *
		 * @param string $hook_suffix Hook suffix.
		 * @return void
		 */
public function enqueue_admin_assets( $hook_suffix ) {
	$is_settings_page = 'settings_page_simple-company-multilingual' === $hook_suffix;
	$is_posts_list    = 'edit.php' === $hook_suffix;

	if ( ! $is_settings_page && ! $is_posts_list ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( $is_posts_list ) {
		if ( ! $screen || ! in_array( $screen->post_type, self::SUPPORTED_TYPES, true ) ) {
			return;
		}
	}

	wp_enqueue_style(
		self::ADMIN_STYLE,
		SCM_PLUGIN_URL . 'assets/admin.css',
		array(),
		SCM_PLUGIN_VERSION
	);

	wp_enqueue_script(
		self::ADMIN_SCRIPT,
		SCM_PLUGIN_URL . 'assets/admin.js',
		array( 'jquery' ),
		SCM_PLUGIN_VERSION,
		true
	);

	if ( $is_settings_page ) {
		wp_enqueue_media();
	}
}

/**
		 * Render settings page.
		 *
		 * @return void
		 */
		public function render_settings_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$settings       = $this->get_settings();
			$catalog        = $this->get_language_catalog();
			$active         = $this->get_active_languages();
			$wp_default     = $this->get_wordpress_default_language();
			$plugin_default = $this->get_default_language();
			$positions      = array(
				'bottom-right' => __( 'Bottom Right', 'simple-company-multilingual' ),
				'bottom-left'  => __( 'Bottom Left', 'simple-company-multilingual' ),
				'top-right'    => __( 'Top Right', 'simple-company-multilingual' ),
				'top-left'     => __( 'Top Left', 'simple-company-multilingual' ),
			);

			$ordered_catalog = array();

			foreach ( $catalog as $locale => $language ) {
				$config = isset( $settings['languages'][ $locale ] ) && is_array( $settings['languages'][ $locale ] ) ? $settings['languages'][ $locale ] : array();

				$language['order'] = $this->get_language_order_value( $config, $locale === $plugin_default ? 0 : 999 );
				$ordered_catalog[ $locale ] = $language;
			}

			$ordered_catalog = $this->sort_languages_by_order( $ordered_catalog );
			?>
			<div class="wrap scm-settings-wrap">
				<h1><?php esc_html_e( 'Simple Company Multilingual', 'simple-company-multilingual' ); ?></h1>

				<form method="post" action="options.php">
					<?php settings_fields( 'scm_settings_group' ); ?>

					<nav class="nav-tab-wrapper scm-tabs" aria-label="<?php esc_attr_e( 'Company Multilingual settings tabs', 'simple-company-multilingual' ); ?>">
						<a href="#scm-tab-general" class="nav-tab nav-tab-active"><?php esc_html_e( 'General', 'simple-company-multilingual' ); ?></a>
						<a href="#scm-tab-languages" class="nav-tab"><?php esc_html_e( 'Languages', 'simple-company-multilingual' ); ?></a>
						<a href="#scm-tab-menus" class="nav-tab"><?php esc_html_e( 'Menus', 'simple-company-multilingual' ); ?></a>
						<a href="#scm-tab-theme-elements" class="nav-tab"><?php esc_html_e( 'Theme Elements', 'simple-company-multilingual' ); ?></a>
						<a href="#scm-tab-strings" class="nav-tab"><?php esc_html_e( 'String Overrides', 'simple-company-multilingual' ); ?></a>
						
					</nav>

					<div id="scm-tab-general" class="scm-tab-panel is-active">
					<div class="scm-settings-section">
						<h2><?php esc_html_e( 'General Settings', 'simple-company-multilingual' ); ?></h2>

						<div class="scm-settings-grid">
							<table class="form-table" role="presentation">
								<tr>
									<th scope="row"><?php esc_html_e( 'Default Language', 'simple-company-multilingual' ); ?></th>
									<td>
										<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[default_language]">
											<option value="">
												<?php
												printf(
													/* translators: %s: WordPress site language. */
													esc_html__( 'Use WordPress Site Language (%s)', 'simple-company-multilingual' ),
													esc_html( $wp_default )
												);
												?>
											</option>

											<?php foreach ( $ordered_catalog as $locale => $language ) : ?>
												<option value="<?php echo esc_attr( $locale ); ?>" <?php selected( $settings['default_language'], $locale ); ?>>
													<?php echo esc_html( $this->get_language_display_name( $locale, $language ) . ' — ' . $locale ); ?>
												</option>
											<?php endforeach; ?>
										</select>

										<p class="description">
											<?php echo esc_html__( 'Effective default:', 'simple-company-multilingual' ) . ' '; ?>
											<code><?php echo esc_html( $plugin_default ); ?></code>
										</p>
									</td>
								</tr>

								<tr>
									<th scope="row"><?php esc_html_e( 'Floating Position', 'simple-company-multilingual' ); ?></th>
									<td>
										<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[floating_position]">
											<?php foreach ( $positions as $position => $label ) : ?>
												<option value="<?php echo esc_attr( $position ); ?>" <?php selected( $settings['floating_position'], $position ); ?>>
													<?php echo esc_html( $label ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
							</table>

							<table class="form-table" role="presentation">
								<tr>
									<th scope="row"><?php esc_html_e( 'Behavior', 'simple-company-multilingual' ); ?></th>
									<td>
										<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[auto_create_translation_drafts]" value="yes" <?php checked( $settings['auto_create_translation_drafts'], 'yes' ); ?> /> <?php esc_html_e( 'Auto-create draft translations from root only', 'simple-company-multilingual' ); ?></label><br />
										<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[auto_append_switcher]" value="yes" <?php checked( $settings['auto_append_switcher'], 'yes' ); ?> /> <?php esc_html_e( 'Append switcher after content', 'simple-company-multilingual' ); ?></label><br />
										<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[floating_switcher]" value="yes" <?php checked( $settings['floating_switcher'], 'yes' ); ?> /> <?php esc_html_e( 'Show floating switcher', 'simple-company-multilingual' ); ?></label><br />
										<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[filter_frontend_lists]" value="yes" <?php checked( $settings['filter_frontend_lists'], 'yes' ); ?> /> <?php esc_html_e( 'Hide translated children from lists', 'simple-company-multilingual' ); ?></label><br />
										<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[delete_data_on_uninstall]" value="yes" <?php checked( $settings['delete_data_on_uninstall'], 'yes' ); ?> /> <?php esc_html_e( 'Delete plugin metadata on uninstall', 'simple-company-multilingual' ); ?></label>
									</td>
								</tr>
							</table>
						</div>
						<div class="scm-tab-save">
							<?php submit_button( __( 'Save General Settings', 'simple-company-multilingual' ), 'primary', 'submit', false ); ?>
						</div>
					</div>
					</div>

					<div id="scm-tab-languages" class="scm-tab-panel">
					<div class="scm-settings-section">
						<h2><?php esc_html_e( 'Languages', 'simple-company-multilingual' ); ?></h2>

						<div class="scm-add-language-box">
							<h3><?php esc_html_e( 'Add Language', 'simple-company-multilingual' ); ?></h3>
							<div class="scm-add-language-row">
								<p>
									<label><?php esc_html_e( 'Language', 'simple-company-multilingual' ); ?></label>
									<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[new_language][locale]" class="regular-text scm-new-language-select">
										<option value=""><?php esc_html_e( '— Select language —', 'simple-company-multilingual' ); ?></option>
										<?php foreach ( $this->get_wordpress_language_choices() as $add_locale => $add_language ) : ?>
											<?php
											$is_already_added = $this->is_language_choice_already_added( $add_locale, $add_language, $settings, $active );
											$option_label     = $this->get_language_display_name( $add_locale, $add_language ) . ' — ' . $add_locale;

											if ( $is_already_added ) {
												$option_label .= ' (' . __( 'Already added', 'simple-company-multilingual' ) . ')';
											}
											?>
											<option value="<?php echo esc_attr( $add_locale ); ?>" data-label="<?php echo esc_attr( $this->get_language_display_name( $add_locale, $add_language ) ); ?>" data-prefix="<?php echo esc_attr( isset( $add_language['prefix'] ) ? $add_language['prefix'] : $this->locale_to_prefix( $add_locale ) ); ?>" data-wp-locale="<?php echo esc_attr( isset( $add_language['wp_locale'] ) ? $add_language['wp_locale'] : $this->guess_wp_locale_from_language( $add_locale ) ); ?>" data-flag="<?php echo esc_attr( isset( $add_language['flag'] ) && '' !== $add_language['flag'] ? $add_language['flag'] : $this->locale_to_flag_emoji( isset( $add_language['wp_locale'] ) ? $add_language['wp_locale'] : $this->guess_wp_locale_from_language( $add_locale ) ) ); ?>" <?php disabled( $is_already_added ); ?>><?php echo esc_html( $option_label ); ?></option>
										<?php endforeach; ?>
									</select>
								</p>
								<p>
									<label><?php esc_html_e( 'Custom Label', 'simple-company-multilingual' ); ?></label>
									<input type="text" class="scm-new-language-label" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[new_language][label]" placeholder="<?php esc_attr_e( 'Auto', 'simple-company-multilingual' ); ?>" />
								</p>
								<p>
									<label><?php esc_html_e( 'Prefix', 'simple-company-multilingual' ); ?></label>
									<input type="text" class="scm-new-language-prefix" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[new_language][prefix]" placeholder="<?php esc_attr_e( 'Auto', 'simple-company-multilingual' ); ?>" />
								</p>
								<p>
									<label><?php esc_html_e( 'Flag', 'simple-company-multilingual' ); ?></label>
									<input type="text" class="small-text scm-new-language-flag" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[new_language][flag]" placeholder="🌐" />
									<input type="hidden" class="scm-new-language-wp-locale" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[new_language][wp_locale]" value="" />
								</p>
								<p class="scm-add-language-action">
									<?php submit_button( __( 'Add Language', 'simple-company-multilingual' ), 'secondary', 'scm_save_custom_language', false ); ?>
								</p>
							</div>
							<p class="description"><?php esc_html_e( 'Select a WordPress language to avoid locale typos. Label, prefix and flag are optional overrides.', 'simple-company-multilingual' ); ?></p>
						</div>

						<div class="scm-language-help">
							<span><?php esc_html_e( 'Default language always uses / and cannot be disabled.', 'simple-company-multilingual' ); ?></span>
							<span><?php esc_html_e( 'Flag image is optional; emoji is the fallback.', 'simple-company-multilingual' ); ?></span>
						</div>

						<table class="widefat striped scm-language-table">
							<thead>
								<tr>
									<th class="scm-col-active"><?php esc_html_e( 'Active', 'simple-company-multilingual' ); ?></th>
									<th class="scm-col-language"><?php esc_html_e( 'Language', 'simple-company-multilingual' ); ?></th>
									<th class="scm-col-locale"><?php esc_html_e( 'Locale', 'simple-company-multilingual' ); ?></th>
									<th class="scm-col-prefix"><?php esc_html_e( 'Prefix', 'simple-company-multilingual' ); ?></th>
									<th class="scm-col-flag"><?php esc_html_e( 'Flag', 'simple-company-multilingual' ); ?></th>
									<th class="scm-col-image"><?php esc_html_e( 'Image / SVG', 'simple-company-multilingual' ); ?></th>
									<th class="scm-col-order"><?php esc_html_e( 'Order', 'simple-company-multilingual' ); ?></th>
									<th class="scm-col-actions"><?php esc_html_e( 'Actions', 'simple-company-multilingual' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $ordered_catalog as $locale => $language ) : ?>
									<?php
									$is_default = $locale === $plugin_default;
									$is_custom  = isset( $settings['custom_languages'][ $locale ] );
									$is_active  = $is_default || isset( $active[ $locale ] );
									$config     = isset( $settings['languages'][ $locale ] ) && is_array( $settings['languages'][ $locale ] ) ? $settings['languages'][ $locale ] : array();
									$prefix     = isset( $config['prefix'] ) ? $config['prefix'] : $language['prefix'];
									$flag       = isset( $config['flag'] ) ? $config['flag'] : '';
									$image      = isset( $config['flag_image'] ) ? $config['flag_image'] : '';
									$order      = $this->get_language_order_value( $config, $is_default ? 0 : 999 );
									?>
									<tr>
										<td class="scm-col-active">
											<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[languages][<?php echo esc_attr( $locale ); ?>][enabled]" value="yes" <?php checked( $is_active ); ?> <?php disabled( $is_default ); ?> />
											<?php if ( $is_default ) : ?>
												<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[languages][<?php echo esc_attr( $locale ); ?>][enabled]" value="yes" />
											<?php endif; ?>
										</td>

										<td>
											<div class="scm-language-name">
												<input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[languages][<?php echo esc_attr( $locale ); ?>][label]" value="<?php echo esc_attr( isset( $config['label'] ) && '' !== $config['label'] && sanitize_key( $config['label'] ) !== sanitize_key( $locale ) ? $config['label'] : $this->get_language_display_name( $locale, $language ) ); ?>" />

												<?php if ( $is_custom ) : ?>
													<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[custom_languages][<?php echo esc_attr( $locale ); ?>][label]" value="<?php echo esc_attr( isset( $config['label'] ) && '' !== $config['label'] ? $config['label'] : $language['label'] ); ?>" />
												<?php endif; ?>

												<?php if ( $is_default ) : ?>
													<span class="scm-language-default-badge"><?php esc_html_e( 'Default', 'simple-company-multilingual' ); ?></span>
												<?php endif; ?>
											</div>
										</td>

										<td><code><?php echo esc_html( $locale ); ?></code></td>

										<td>
											<?php if ( $is_default ) : ?>
												<code>/</code>
												<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[languages][<?php echo esc_attr( $locale ); ?>][prefix]" value="" />
											<?php else : ?>
												<input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[languages][<?php echo esc_attr( $locale ); ?>][prefix]" value="<?php echo esc_attr( $prefix ); ?>" placeholder="<?php echo esc_attr( $language['prefix'] ); ?>" />
											<?php endif; ?>

											<?php if ( $is_custom ) : ?>
												<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[custom_languages][<?php echo esc_attr( $locale ); ?>][prefix]" value="<?php echo esc_attr( $is_default ? '' : $prefix ); ?>" />
											<?php endif; ?>
										</td>

										<td><input type="text" class="small-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[languages][<?php echo esc_attr( $locale ); ?>][flag]" value="<?php echo esc_attr( $flag ); ?>" placeholder="🌐" /></td>

										<td>
											<div class="scm-flag-image-control">
												<?php if ( ! empty( $image ) ) : ?>
													<img class="scm-flag-image-preview" src="<?php echo esc_url( $image ); ?>" alt="" />
												<?php endif; ?>

												<input type="url" class="scm-flag-image-url" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[languages][<?php echo esc_attr( $locale ); ?>][flag_image]" value="<?php echo esc_url( $image ); ?>" placeholder="https://example.com/flag.svg" />
												<button type="button" class="button scm-flag-upload"><?php esc_html_e( 'Upload', 'simple-company-multilingual' ); ?></button>
											</div>
										</td>

										<td class="scm-col-order">
											<input type="number" class="small-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[languages][<?php echo esc_attr( $locale ); ?>][order]" value="<?php echo esc_attr( $order ); ?>" min="0" step="1" />
										</td>

										<td>
											<?php if ( $is_custom && ! $is_default ) : ?>
												<button type="submit" class="button scm-button-delete" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[delete_custom_language]" value="<?php echo esc_attr( $locale ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Remove this custom language from plugin settings? Existing posts/pages will not be deleted.', 'simple-company-multilingual' ) ); ?>');">
													<?php esc_html_e( 'Delete', 'simple-company-multilingual' ); ?>
												</button>
											<?php else : ?>
												<span class="description">—</span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<div class="scm-tab-save">
							<?php submit_button( __( 'Save Languages', 'simple-company-multilingual' ), 'primary', 'submit', false ); ?>
						</div>
					</div>
					</div>

					<div id="scm-tab-menus" class="scm-tab-panel">
						<div class="scm-settings-section">
							<h2><?php esc_html_e( 'Menus by Language', 'simple-company-multilingual' ); ?></h2>

							<?php $this->render_menu_mapping_settings(); ?>

							<div class="scm-tab-save">
								<?php submit_button( __( 'Save Menus', 'simple-company-multilingual' ), 'primary', 'submit', false ); ?>
							</div>
						</div>
					</div>

					<div id="scm-tab-theme-elements" class="scm-tab-panel">
						<div class="scm-settings-section">
							<h2><?php esc_html_e( 'Theme Element Overrides', 'simple-company-multilingual' ); ?></h2>

							<?php $this->render_theme_element_overrides_settings(); ?>

							<div class="scm-tab-save">
								<?php submit_button( __( 'Save Theme Elements', 'simple-company-multilingual' ), 'primary', 'submit', false ); ?>
							</div>
						</div>
					</div>

					<div id="scm-tab-strings" class="scm-tab-panel">
					<div class="scm-settings-section">
						<h2><?php esc_html_e( 'Frontend String Overrides', 'simple-company-multilingual' ); ?></h2>
						<p class="description">
							<?php esc_html_e( 'Default sources are generated from WordPress in the current Site Language when possible. Add custom source text exactly as it appears on the frontend for theme/header/footer/widget labels.', 'simple-company-multilingual' ); ?>
						</p>

						<div class="scm-add-language-box">
							<h3><?php esc_html_e( 'Add Source Text', 'simple-company-multilingual' ); ?></h3>
							<div class="scm-add-language-row" style="grid-template-columns:1fr auto;">
								<p>
									<label><?php esc_html_e( 'Source text', 'simple-company-multilingual' ); ?></label>
									<input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[new_string_source]" placeholder="<?php esc_attr_e( 'Written by', 'simple-company-multilingual' ); ?>" />
								</p>
								<p class="scm-add-language-action">
									<?php submit_button( __( 'Add Source', 'simple-company-multilingual' ), 'secondary', 'scm_add_string_source', false ); ?>
								</p>
							</div>
							<p class="description"><?php esc_html_e( 'For theme-specific text, copy the exact text shown on the frontend. Example: Written by, More posts, Read more.', 'simple-company-multilingual' ); ?></p>
						</div>

						<table class="widefat striped scm-language-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Source text', 'simple-company-multilingual' ); ?></th>
									<?php foreach ( $active as $override_locale => $override_language ) : ?>
										<th><?php echo esc_html( $this->format_language_label_text( $override_locale, $override_language ) ); ?></th>
									<?php endforeach; ?>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $this->get_string_override_sources() as $source_text ) : ?>
									<tr>
										<td>
											<code><?php echo esc_html( $source_text ); ?></code>
											<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[string_sources][]" value="<?php echo esc_attr( $source_text ); ?>" />
											<?php if ( ! in_array( $source_text, $this->get_default_string_override_sources(), true ) ) : ?>
												<br /><button type="submit" class="button button-small scm-button-delete" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[delete_string_source]" value="<?php echo esc_attr( $source_text ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Remove this source text and its translations?', 'simple-company-multilingual' ) ); ?>');"><?php esc_html_e( 'Delete', 'simple-company-multilingual' ); ?></button>
											<?php endif; ?>
										</td>
										<?php foreach ( $active as $override_locale => $override_language ) : ?>
											<?php $override_value = isset( $settings['string_overrides'][ $source_text ][ $override_locale ] ) ? $settings['string_overrides'][ $source_text ][ $override_locale ] : ''; ?>
											<td>
												<input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[string_overrides][<?php echo esc_attr( $source_text ); ?>][<?php echo esc_attr( $override_locale ); ?>]" value="<?php echo esc_attr( $override_value ); ?>" placeholder="<?php echo esc_attr( $source_text ); ?>" />
											</td>
										<?php endforeach; ?>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<div class="scm-tab-save">
							<?php submit_button( __( 'Save String Overrides', 'simple-company-multilingual' ), 'primary', 'submit', false ); ?>
						</div>
					</div>
					</div>
				</form>
			</div>
			<?php
		}
}

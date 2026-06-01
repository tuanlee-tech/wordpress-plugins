<?php
/**
 * SCM Post Translations trait.
 *
 * @package Simple_Company_Multilingual
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait SCM_Post_Translations {
	/**
	 * Get post types managed by this multilingual plugin.
	 *
	 * Defaults stay intentionally conservative to avoid changing unrelated CPTs.
	 * The Event CPT is enabled automatically when it exists. Developers can opt in
	 * more post types with: add_filter( 'scm_supported_post_types', ... ).
	 *
	 * @return string[]
	 */
	private function get_supported_post_types() {
		$types = self::SUPPORTED_TYPES;

		if ( post_type_exists( 'event' ) ) {
			$types[] = 'event';
		}

		/**
		 * Filter translatable post types.
		 *
		 * @param string[] $types Post type names.
		 */
		$types = apply_filters( 'scm_supported_post_types', $types );

		if ( ! is_array( $types ) ) {
			$types = self::SUPPORTED_TYPES;
		}

		$types = array_map( 'sanitize_key', $types );
		$types = array_filter( array_unique( $types ) );

		return array_values( $types );
	}

	/**
	 * Check whether a post type is managed by this multilingual plugin.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	private function is_supported_post_type( $post_type ) {
		return in_array( sanitize_key( $post_type ), $this->get_supported_post_types(), true );
	}

	/**
	 * Register admin columns for custom post types added after plugin bootstrap.
	 *
	 * Built-in post/page hooks are still registered in the constructor for backward
	 * compatibility. This method adds the same UI to custom post types such as Event.
	 *
	 * @return void
	 */
	public function register_custom_post_type_admin_hooks() {
		foreach ( $this->get_supported_post_types() as $post_type ) {
			if ( in_array( $post_type, self::SUPPORTED_TYPES, true ) ) {
				continue;
			}

			add_filter( 'manage_' . $post_type . '_posts_columns', array( $this, 'add_language_column' ) );
			add_action( 'manage_' . $post_type . '_posts_custom_column', array( $this, 'render_language_column' ), 10, 2 );
		}
	}
/**
		 * Register meta box.
		 *
		 * @return void
		 */
		public function register_meta_box() {
			foreach ( $this->get_supported_post_types() as $post_type ) {
				add_meta_box(
					'scm_translation_box',
					__( 'Company Translations', 'simple-company-multilingual' ),
					array( $this, 'render_meta_box' ),
					$post_type,
					'side',
					'default'
				);
			}
		}


		/**
		 * Add admin row classes for translation groups.
		 *
		 * @param string[] $classes Current post classes.
		 * @param string[] $class   Extra classes.
		 * @param int      $post_id Post ID.
		 * @return string[]
		 */
		public function add_admin_translation_row_classes( $classes, $class, $post_id ) {
			if ( ! is_admin() ) {
				return $classes;
			}

			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

			if ( ! $screen || empty( $screen->post_type ) || ! $this->is_supported_post_type( $screen->post_type ) ) {
				return $classes;
			}

			$post_id = absint( $post_id );
			$post    = get_post( $post_id );

			if ( ! $post instanceof WP_Post || ! $this->is_supported_post_type( $post->post_type ) ) {
				return $classes;
			}

			$language = $this->get_post_language( $post_id );

			if ( '' === $language ) {
				$language = $this->get_post_language( $post_id );
			}

			$group_id = $this->get_group_id( $post_id );
			$is_root  = $group_id === $post_id;

			$classes[] = 'scm-translation-row';
			$classes[] = $is_root ? 'scm-translation-row--root' : 'scm-translation-row--child';
			$classes[] = 'scm-translation-group-' . absint( $group_id );

			if ( '' !== $language ) {
				$classes[] = 'scm-translation-row--lang-' . sanitize_html_class( $language );
			}

			return array_values( array_unique( $classes ) );
		}

/**
		 * Render meta box.
		 *
		 * @param WP_Post $post Post.
		 * @return void
		 */
		public function render_meta_box( $post ) {
			if ( ! $post instanceof WP_Post ) {
				return;
			}

			wp_nonce_field( 'scm_save_meta_' . $post->ID, self::NONCE_META );

			$active_languages = $this->get_active_languages();
			$current_language = $this->get_post_language( $post->ID );
			$group            = $this->get_translation_group( $post->ID );
			$is_root          = $this->is_group_root( $post->ID );
			$root_id          = $this->get_root_post_id( $post->ID );
			?>
			<p><?php esc_html_e( 'This item belongs to a translation group. Only the root item can create missing draft translations.', 'simple-company-multilingual' ); ?></p>
			<p>
				<strong><?php esc_html_e( 'Group role:', 'simple-company-multilingual' ); ?></strong>
				<?php echo $is_root ? esc_html__( 'Root', 'simple-company-multilingual' ) : esc_html__( 'Translation child', 'simple-company-multilingual' ); ?>
				<?php if ( ! $is_root && $root_id > 0 ) : ?>
					<br /><a href="<?php echo esc_url( get_edit_post_link( $root_id ) ); ?>"><?php esc_html_e( 'Edit root item', 'simple-company-multilingual' ); ?></a>
				<?php endif; ?>
			</p>
			<p><label for="scm_language"><strong><?php esc_html_e( 'This content language', 'simple-company-multilingual' ); ?></strong></label><select id="scm_language" name="scm_language" class="widefat"><?php foreach ( $active_languages as $locale => $language ) : ?><option value="<?php echo esc_attr( $locale ); ?>" <?php selected( $current_language, $locale ); ?>><?php echo esc_html( $this->format_language_label_text( $locale, $language ) ); ?></option><?php endforeach; ?></select></p>
			<hr />
			<?php if ( $is_root ) : ?>
				<p><?php echo $this->get_create_all_translations_button_html( $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Create Translation buttons are hidden for children to keep the group controlled from the root item.', 'simple-company-multilingual' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $active_languages as $locale => $language ) : ?>
				<?php
				$existing_id = isset( $group[ $locale ] ) ? absint( $group[ $locale ] ) : 0;
				?>
				<div class="scm-translation-row" style="margin-bottom:12px;">
					<strong><?php echo esc_html( $this->format_language_label_text( $locale, $language ) ); ?></strong>
					<?php if ( $existing_id > 0 ) : ?>
						<p style="margin:6px 0;"><a href="<?php echo esc_url( get_edit_post_link( $existing_id ) ); ?>"><?php echo esc_html( get_the_title( $existing_id ) ); ?> #<?php echo esc_html( (string) $existing_id ); ?></a><?php echo $existing_id === (int) $post->ID ? ' ' . esc_html__( '(current)', 'simple-company-multilingual' ) : ''; ?></p>
					<?php elseif ( $is_root && $locale !== $current_language ) : ?>
						<?php echo $this->get_create_translation_button_html( $post->ID, $locale ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<select name="scm_existing_translation[<?php echo esc_attr( $locale ); ?>]" class="widefat" style="margin-top:6px;"><option value="0"><?php esc_html_e( '— Or link existing —', 'simple-company-multilingual' ); ?></option><?php foreach ( $this->get_candidate_posts_by_language( $post->post_type, $locale, $post->ID ) as $candidate ) : ?><option value="<?php echo esc_attr( $candidate->ID ); ?>"><?php echo esc_html( $this->get_candidate_label( $candidate ) ); ?></option><?php endforeach; ?></select>
					<?php else : ?>
						<p class="description" style="margin:6px 0;"><?php esc_html_e( 'Not created yet.', 'simple-company-multilingual' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
			<?php
		}

/**
		 * Save meta box.
		 *
		 * @param int     $post_id Post ID.
		 * @param WP_Post $post Post.
		 * @return void
		 */
		public function save_post_meta( $post_id, $post ) {
			if ( ! $post instanceof WP_Post || ! $this->is_supported_post_type( $post->post_type ) ) {
				return;
			}

			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}

			if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
				return;
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}

			/*
			 * Always persist language/group/slug basics, even when the meta box nonce
			 * is not present. Some editors/builders can save content without posting
			 * sidebar meta box fields. If a root created while Korean was default does
			 * not store _scm_language=ko, changing the default later makes that root
			 * look like the new default language and breaks /ko/slug/.
			 */
			$this->ensure_post_language_meta( $post_id );
			$this->ensure_group_id( $post_id );
			$this->sync_translation_group_url_slug( $post_id );

			if ( ! isset( $_POST[ self::NONCE_META ] ) ) {
				return;
			}

			$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_META ] ) );

			if ( ! wp_verify_nonce( $nonce, 'scm_save_meta_' . $post_id ) ) {
				return;
			}

			$language = isset( $_POST['scm_language'] ) ? sanitize_key( wp_unslash( $_POST['scm_language'] ) ) : $this->get_default_language();

			if ( ! $this->is_active_language( $language ) ) {
				$language = $this->get_default_language();
			}

			update_post_meta( $post_id, self::META_LANGUAGE, $language );
			$this->ensure_group_id( $post_id );
			$this->sync_translation_group_url_slug( $post_id );

			if ( ! $this->is_creating_translation && $this->is_group_root( $post_id ) ) {
				$settings = $this->get_settings();

				if ( 'yes' === $settings['auto_create_translation_drafts'] ) {
					$this->create_missing_translation_drafts( $post_id );
				}
			}

			if ( isset( $_POST['scm_existing_translation'] ) && is_array( $_POST['scm_existing_translation'] ) ) {
				$existing_translations = wp_unslash( $_POST['scm_existing_translation'] );
				$group_id              = $this->get_group_id( $post_id );

				foreach ( $existing_translations as $locale => $target_id ) {
					$locale    = sanitize_key( $locale );
					$target_id = absint( $target_id );

					if ( $target_id <= 0 || $target_id === absint( $post_id ) ) {
						continue;
					}

					$target = get_post( $target_id );

					if ( ! $target instanceof WP_Post || $target->post_type !== $post->post_type || ! current_user_can( 'edit_post', $target_id ) ) {
						continue;
					}

					update_post_meta( $target_id, self::META_LANGUAGE, $locale );
					update_post_meta( $target_id, self::META_GROUP, $group_id );
				}
			}
		}

/**
		 * Add language column.
		 *
		 * @param array $columns Columns.
		 * @return array
		 */
		public function add_language_column( $columns ) {
			$columns['scm_language'] = __( 'Language / Group', 'simple-company-multilingual' );

			return $columns;
		}

/**
		 * Render language column.
		 *
		 * @param string $column Column.
		 * @param int    $post_id Post ID.
		 * @return void
		 */
		public function render_language_column( $column, $post_id ) {
			if ( 'scm_language' !== $column ) {
				return;
			}

			$language = $this->get_post_language( $post_id );
			$active   = $this->get_active_languages();
			$config   = isset( $active[ $language ] ) ? $active[ $language ] : array( 'label' => $language );
			$is_root  = $this->is_group_root( $post_id );
			$root_id  = $this->get_root_post_id( $post_id );
			$group    = $this->get_translation_group( $post_id );

			echo esc_html( $this->format_language_label_text( $language, $config ) );
			echo '<br />';
			if ( $is_root ) {
				echo '<strong>' . esc_html__( 'Root', 'simple-company-multilingual' ) . '</strong>';

				if ( count( $group ) > 1 ) {
					echo '<br />';
					echo '<button type="button" class="button button-small scm-toggle-children" data-group="' . esc_attr( (string) $post_id ) . '" aria-expanded="false">';
					echo esc_html__( 'Show translations', 'simple-company-multilingual' );
					echo '</button>';
				}
			} else {
				echo esc_html__( 'Child', 'simple-company-multilingual' );
			}

			if ( ! $is_root && $root_id > 0 ) {
				echo '<br /><a href="' . esc_url( get_edit_post_link( $root_id ) ) . '">' . esc_html__( 'Root', 'simple-company-multilingual' ) . ' #' . esc_html( (string) $root_id ) . '</a>';
			}

			echo '<br /><span class="description">' . esc_html( count( $group ) ) . ' ' . esc_html__( 'items in group', 'simple-company-multilingual' ) . '</span>';
		}

/**
		 * Add row quick actions.
		 *
		 * @param array   $actions Actions.
		 * @param WP_Post $post Post.
		 * @return array
		 */
		public function add_row_actions( $actions, $post ) {
			if ( ! $post instanceof WP_Post || ! $this->is_supported_post_type( $post->post_type ) || ! current_user_can( 'edit_post', $post->ID ) ) {
				return $actions;
			}

			if ( ! $this->is_group_root( $post->ID ) ) {
				$root_id = $this->get_root_post_id( $post->ID );

				if ( $root_id > 0 ) {
					$actions['scm_edit_root'] = sprintf(
						'<a href="%1$s">%2$s</a>',
						esc_url( get_edit_post_link( $root_id ) ),
						esc_html__( 'Edit translation root', 'simple-company-multilingual' )
					);
				}

				return $actions;
			}

			$active  = $this->get_active_languages();
			$current = $this->get_post_language( $post->ID );
			$group   = $this->get_translation_group( $post->ID );

			foreach ( $active as $locale => $language ) {
				if ( $locale === $current || isset( $group[ $locale ] ) ) {
					continue;
				}

				$label = sprintf(
					/* translators: %s: language label. */
					__( 'Create %s', 'simple-company-multilingual' ),
					$this->format_language_label_text( $locale, $language )
				);

				$actions[ 'scm_create_' . sanitize_key( $locale ) ] = $this->get_create_translation_link_html( $post->ID, $locale, $label );
			}

			return $actions;
		}

/**
		 * Handle create translation action.
		 *
		 * @return void
		 */
		public function handle_create_translation() {
			$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
			$locale  = isset( $_GET['locale'] ) ? sanitize_key( wp_unslash( $_GET['locale'] ) ) : '';
			$is_all  = '__all__' === $locale;

			if ( $post_id <= 0 || ( ! $is_all && ! $this->is_active_language( $locale ) ) ) {
				wp_die( esc_html__( 'Invalid translation request.', 'simple-company-multilingual' ) );
			}

			$nonce_action = self::ACTION_CREATE . '_' . $post_id . '_' . ( $is_all ? 'all' : $locale );

			if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), $nonce_action ) ) {
				wp_die( esc_html__( 'Invalid nonce.', 'simple-company-multilingual' ) );
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				wp_die( esc_html__( 'You are not allowed to create this translation.', 'simple-company-multilingual' ) );
			}

			if ( $is_all ) {
				$this->create_missing_translation_drafts( $post_id );
				wp_safe_redirect( get_edit_post_link( $post_id, 'raw' ) );
				exit;
			}

			$new_id = $this->create_translation_from_post( $post_id, $locale );

			if ( is_wp_error( $new_id ) ) {
				wp_die( esc_html( $new_id->get_error_message() ) );
			}

			wp_safe_redirect( get_edit_post_link( $new_id, 'raw' ) );
			exit;
		}

/**
		 * Duplicate post/page to draft translation.
		 *
		 * @param int    $source_id Source ID.
		 * @param string $locale Target locale.
		 * @return int|WP_Error
		 */
		private function create_translation_from_post( $source_id, $locale ) {
			$source_id = absint( $source_id );
			$locale    = sanitize_key( $locale );
			$source    = get_post( $source_id );

			if ( ! $source instanceof WP_Post ) {
				return new WP_Error( 'scm_missing_source', __( 'Source content not found.', 'simple-company-multilingual' ) );
			}

			if ( ! $this->is_supported_post_type( $source->post_type ) ) {
				return new WP_Error( 'scm_invalid_type', __( 'Unsupported post type.', 'simple-company-multilingual' ) );
			}

			if ( ! $this->is_active_language( $locale ) ) {
				return new WP_Error( 'scm_invalid_language', __( 'Target language is not active.', 'simple-company-multilingual' ) );
			}

			$group = $this->get_translation_group( $source_id );

			if ( isset( $group[ $locale ] ) ) {
				return absint( $group[ $locale ] );
			}

			$group_id = $this->ensure_group_id( $source_id );
			$slug     = $this->ensure_translation_url_slug( $source_id );

			/*
			 * Do not create language root pages such as /vi/ or /ko/ anymore.
			 *
			 * Earlier versions used translated page parents to create URLs like
			 * /vi/about/. That breaks when the default language changes and also
			 * creates extra root pages such as VI/KO/DE in Pages list.
			 *
			 * Public multilingual URLs are now generated by the permalink/rewrite
			 * layer using _scm_translation_url_slug, so the WordPress internal parent
			 * can safely follow the source page parent instead of a language root page.
			 */
			$parent = 'page' === $source->post_type ? absint( $source->post_parent ) : 0;

			$new_post = array(
				'post_author'           => get_current_user_id() > 0 ? get_current_user_id() : $source->post_author,
				'post_content'          => $source->post_content,
				'post_content_filtered' => $source->post_content_filtered,
				'post_title'            => $this->build_draft_translation_title( $source->post_title, $locale ),
				'post_excerpt'          => $source->post_excerpt,
				'post_status'           => 'draft',
				'post_type'             => $source->post_type,
				'post_name'             => $source->post_name,
				'post_parent'           => $parent,
				'menu_order'            => $source->menu_order,
				'comment_status'        => $source->comment_status,
				'ping_status'           => $source->ping_status,
			);

			$this->is_creating_translation = true;
			$new_id = wp_insert_post( wp_slash( $new_post ), true );
			$this->is_creating_translation = false;

			if ( is_wp_error( $new_id ) ) {
				return $new_id;
			}

			$this->copy_post_meta( $source_id, $new_id );
			$this->copy_taxonomies( $source_id, $new_id );

			update_post_meta( $new_id, self::META_LANGUAGE, $locale );
			update_post_meta( $new_id, self::META_GROUP, $group_id );
			update_post_meta( $new_id, self::META_URL_SLUG, $slug );
			$this->sync_translation_group_url_slug( $new_id, $slug );

			return absint( $new_id );
		}

/**
		 * Build draft translation title.
		 *
		 * Example: About (DRAFT : Vietnamese)
		 *
		 * @param string $source_title Source title.
		 * @param string $locale       Target locale.
		 * @return string
		 */
		private function build_draft_translation_title( $source_title, $locale ) {
			$active = $this->get_active_languages();
			$label  = isset( $active[ $locale ]['label'] ) ? $active[ $locale ]['label'] : $locale;
			$title  = trim( wp_strip_all_tags( $source_title ) );

			if ( '' === $title ) {
				$title = __( '(No title)', 'simple-company-multilingual' );
			}

			return sprintf(
				/* translators: 1: source title, 2: language label. */
				__( '%1$s (DRAFT : %2$s)', 'simple-company-multilingual' ),
				$title,
				$label
			);
		}



		private function create_missing_translation_drafts( $source_id ) {
			$source_id = absint( $source_id );
			$created   = array();
			$active    = $this->get_active_languages();
			$current   = $this->get_post_language( $source_id );
			$group     = $this->get_translation_group( $source_id );

			foreach ( $active as $locale => $language ) {
				if ( $locale === $current || isset( $group[ $locale ] ) ) {
					continue;
				}

				$new_id = $this->create_translation_from_post( $source_id, $locale );

				if ( ! is_wp_error( $new_id ) && $new_id > 0 ) {
					$created[ $locale ] = absint( $new_id );
					$group[ $locale ]   = absint( $new_id );
				}
			}

			return $created;
		}



		private function copy_post_meta( $source_id, $target_id ) {
			$meta = get_post_meta( $source_id );

			$excluded_keys = array(
				self::META_LANGUAGE,
				self::META_GROUP,
				'_edit_lock',
				'_edit_last',
				'_wp_old_slug',
			);

			foreach ( $meta as $key => $values ) {
				if ( in_array( $key, $excluded_keys, true ) || 0 === strpos( $key, '_scm_' ) ) {
					continue;
				}

				foreach ( $values as $value ) {
					add_post_meta( $target_id, $key, maybe_unserialize( $value ) );
				}
			}
		}

/**
		 * Copy taxonomies.
		 *
		 * @param int $source_id Source ID.
		 * @param int $target_id Target ID.
		 * @return void
		 */
		private function copy_taxonomies( $source_id, $target_id ) {
			$taxonomies = get_object_taxonomies( get_post_type( $source_id ) );
			$excluded_taxonomies = apply_filters(
				'scm_excluded_translation_taxonomies',
				array( 'event_language' ),
				absint( $source_id ),
				absint( $target_id )
			);

			if ( ! is_array( $excluded_taxonomies ) ) {
				$excluded_taxonomies = array();
			}

			$excluded_taxonomies = array_map( 'sanitize_key', $excluded_taxonomies );

			foreach ( $taxonomies as $taxonomy ) {
				if ( in_array( $taxonomy, $excluded_taxonomies, true ) ) {
					continue;
				}
				$terms = wp_get_object_terms( $source_id, $taxonomy, array( 'fields' => 'ids' ) );

				if ( is_wp_error( $terms ) ) {
					continue;
				}

				wp_set_object_terms( $target_id, array_map( 'absint', $terms ), $taxonomy, false );
			}
		}

/**
		 * Ensure group ID.
		 *
		 * @param int $post_id Post ID.
		 * @return int
		 */
		private function ensure_group_id( $post_id ) {
			$post_id      = absint( $post_id );
			$stored_group = absint( get_post_meta( $post_id, self::META_GROUP, true ) );

			if ( $stored_group <= 0 ) {
				$stored_group = $post_id;
				update_post_meta( $post_id, self::META_GROUP, $stored_group );
			}

			return $stored_group;
		}

/**
		 * Get group ID.
		 *
		 * @param int $post_id Post ID.
		 * @return int
		 */
		private function get_group_id( $post_id ) {
			$group_id = absint( get_post_meta( absint( $post_id ), self::META_GROUP, true ) );

			return $group_id > 0 ? $group_id : absint( $post_id );
		}

/**
		 * Check whether a post is the root item of its translation group.
		 *
		 * @param int $post_id Post ID.
		 * @return bool
		 */
		private function is_group_root( $post_id ) {
			$post_id = absint( $post_id );

			return $post_id > 0 && $this->get_group_id( $post_id ) === $post_id;
		}

/**
		 * Get root post ID for a translation group.
		 *
		 * @param int $post_id Post ID.
		 * @return int
		 */
		private function get_root_post_id( $post_id ) {
			$root_id = $this->get_group_id( $post_id );

			return get_post( $root_id ) instanceof WP_Post ? absint( $root_id ) : 0;
		}

/**
		 * Get post language.
		 *
		 * @param int $post_id Post ID.
		 * @return string
		 */
		public function get_post_language( $post_id ) {
			$post_id = absint( $post_id );

			if ( $post_id <= 0 ) {
				return $this->get_default_language();
			}

			/*
			 * Important: do not call get_post_language() from inside this method.
			 * That creates infinite recursion and quickly exhausts PHP memory.
			 */
			$language = $this->get_raw_post_language( $post_id );

			if ( '' !== $language ) {
				return $language;
			}

			$inferred = $this->infer_post_language_from_group( $post_id );

			if ( '' !== $inferred && $this->is_active_language( $inferred ) ) {
				update_post_meta( $post_id, self::META_LANGUAGE, $inferred );
				return $inferred;
			}

			return $this->get_default_language();
		}

/**
		 * Get raw stored language without fallback.
		 *
		 * This must be used by translation-group resolution. Do not call
		 * get_post_language() there, because get_post_language() falls back to the
		 * current default language and can corrupt groups after the default changes.
		 *
		 * @param int $post_id Post ID.
		 * @return string Empty when no valid language meta exists.
		 */
		private function get_raw_post_language( $post_id ) {
			$language = sanitize_key( get_post_meta( absint( $post_id ), self::META_LANGUAGE, true ) );

			if ( '' !== $language && $this->is_active_language( $language ) ) {
				return $language;
			}

			return '';
		}

/**
		 * Ensure a post has a persisted language meta value.
		 *
		 * @param int $post_id Post ID.
		 * @return string
		 */
		private function ensure_post_language_meta( $post_id ) {
			$post_id  = absint( $post_id );
			$language = $this->get_post_language( $post_id );

			if ( '' !== $language ) {
				return $language;
			}

			$language = $this->infer_post_language_from_group( $post_id );

			if ( '' !== $language && $this->is_active_language( $language ) ) {
				update_post_meta( $post_id, self::META_LANGUAGE, $language );
				return $language;
			}

			/*
			 * Do not blindly write the current default language when the item already
			 * belongs to a translation group with labeled siblings. That is exactly how
			 * an old Korean root becomes mislabeled as English/Vietnamese/German after
			 * changing the plugin default.
			 */
			if ( $this->group_has_any_language_meta( $post_id ) ) {
				return '';
			}

			$language = $this->get_default_language();
			update_post_meta( $post_id, self::META_LANGUAGE, $language );

			return $language;
		}

/**
		 * Check whether this translation group already has any explicit language meta.
		 *
		 * @param int $post_id Post ID.
		 * @return bool
		 */
		private function group_has_any_language_meta( $post_id ) {
			$post_id  = absint( $post_id );
			$group_id = $this->get_group_id( $post_id );

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

			foreach ( $ids as $id ) {
				if ( '' !== $this->get_raw_post_language( $id ) ) {
					return true;
				}
			}

			return false;
		}

/**
		 * Infer missing language from translation group siblings.
		 *
		 * This repairs old roots created when a different language was default but
		 * _scm_language was never stored. If siblings already use EN/VI/DE and KO is
		 * the only active language missing, the current item is inferred as KO.
		 *
		 * @param int $post_id Post ID.
		 * @return string Empty string when ambiguous.
		 */
		private function infer_post_language_from_group( $post_id ) {
			$post_id  = absint( $post_id );
			$group_id = $this->get_group_id( $post_id );
			$active   = array_keys( $this->get_active_languages() );

			if ( empty( $active ) ) {
				return '';
			}

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

			$used = array();
			$ids  = array_map( 'absint', $query->posts );

			if ( $group_id > 0 && ! in_array( $group_id, $ids, true ) ) {
				$ids[] = $group_id;
			}

			foreach ( $ids as $sibling_id ) {
				if ( $sibling_id === $post_id ) {
					continue;
				}

				$lang = sanitize_key( get_post_meta( $sibling_id, self::META_LANGUAGE, true ) );

				if ( '' !== $lang && in_array( $lang, $active, true ) ) {
					$used[] = $lang;
				}
			}

			$missing = array_values( array_diff( $active, array_unique( $used ) ) );

			if ( 1 === count( $missing ) ) {
				return sanitize_key( $missing[0] );
			}

			return '';
		}

/**
		 * Get translation group.
		 *
		 * @param int $post_id Post ID.
		 * @return array
		 */
		public function get_translation_group( $post_id ) {
			$post_id  = absint( $post_id );
			$group_id = $this->get_group_id( $post_id );

			$query = new WP_Query(
				array(
					'post_type'              => $this->get_supported_post_types(),
					'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'posts_per_page'         => -1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => false,
					'scm_include_all_languages' => true,
					'meta_query'             => array(
						array(
							'key'     => self::META_GROUP,
							'value'   => $group_id,
							'compare' => '=',
						),
					),
				)
			);

			$group = array();

			/*
			 * Always include the root item manually.
			 * Some older groups may have children pointing to the root while the root itself
			 * does not yet have _scm_translation_group stored. This prevents the switcher
			 * from disabling the default/root language.
			 */
			$root_post = get_post( $group_id );

			if ( $root_post instanceof WP_Post && $this->is_supported_post_type( $root_post->post_type ) ) {
				$root_language = $this->get_raw_post_language( $group_id );

				if ( '' !== $root_language ) {
					$group[ $root_language ] = absint( $group_id );
				}
			}

			if ( empty( $query->posts ) ) {
				if ( empty( $group ) ) {
					$language = $this->get_post_language( $post_id );

					if ( '' === $language ) {
						$language = $this->get_default_language();
					}

					$group[ $language ] = $post_id;
				}

				return $group;
			}

			foreach ( $query->posts as $sibling_id ) {
				$language = $this->get_raw_post_language( $sibling_id );

				if ( '' !== $language && ! isset( $group[ $language ] ) ) {
					$group[ $language ] = absint( $sibling_id );
				}
			}

			return $group;
		}

/**
		 * Get candidates for manual linking.
		 *
		 * @param string $post_type Post type.
		 * @param string $locale Locale.
		 * @param int    $current_post_id Current post ID.
		 * @return WP_Post[]
		 */
		private function get_candidate_posts_by_language( $post_type, $locale, $current_post_id ) {
			$query = new WP_Query(
				array(
					'post_type'              => $post_type,
					'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page'         => 100,
					'post__not_in'           => array( absint( $current_post_id ) ),
					'orderby'                => 'title',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => false,
					'meta_query'             => array(
						array(
							'key'     => self::META_LANGUAGE,
							'value'   => sanitize_key( $locale ),
							'compare' => '=',
						),
					),
				)
			);

			return $query->posts;
		}

/**
		 * Create action link.
		 *
		 * @param int    $post_id Post ID.
		 * @param string $locale Locale.
		 * @param string $label Label.
		 * @return string
		 */
		private function get_create_translation_link_html( $post_id, $locale, $label ) {
			$post_id = absint( $post_id );
			$locale  = sanitize_key( $locale );

			$url = wp_nonce_url(
				add_query_arg(
					array(
						'action'  => self::ACTION_CREATE,
						'post_id' => $post_id,
						'locale'  => $locale,
					),
					admin_url( 'admin-post.php' )
				),
				self::ACTION_CREATE . '_' . $post_id . '_' . $locale
			);

			return sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html( $label ) );
		}

/**
		 * Create button.
		 *
		 * @param int    $post_id Post ID.
		 * @param string $locale Locale.
		 * @return string
		 */
		private function get_create_translation_button_html( $post_id, $locale ) {
			$active = $this->get_active_languages();
			$label  = isset( $active[ $locale ] ) ? $this->format_language_label_text( $locale, $active[ $locale ] ) : $locale;
			$link   = $this->get_create_translation_link_html(
				$post_id,
				$locale,
				sprintf(
					/* translators: %s: language label. */
					__( 'Create %s translation', 'simple-company-multilingual' ),
					$label
				)
			);

			return str_replace( '<a ', '<a class="button button-small" ', $link );
		}

/**
		 * Create all button.
		 *
		 * @param int $post_id Post ID.
		 * @return string
		 */
		private function get_create_all_translations_button_html( $post_id ) {
			$post_id = absint( $post_id );

			$url = wp_nonce_url(
				add_query_arg(
					array(
						'action'  => self::ACTION_CREATE,
						'post_id' => $post_id,
						'locale'  => '__all__',
					),
					admin_url( 'admin-post.php' )
				),
				self::ACTION_CREATE . '_' . $post_id . '_all'
			);

			return sprintf( '<a class="button button-primary button-small" href="%1$s">%2$s</a>', esc_url( $url ), esc_html__( 'Create Draft Translations', 'simple-company-multilingual' ) );
		}
}
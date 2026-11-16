<?php
/**
 * Admin UI & AJAX Controller for FSE Performance Optimizer.
 *
 * Provides 4 tabs:
 * 1. Dashboard Trước / Sau (Before vs After Telemetry)
 * 2. Cấu hình Runtime (Settings)
 * 3. Bulk Media & Fonts (Share-Hosting Safe AJAX Batch)
 * 4. Database & Autoload Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSE_Opt_Admin {

	/**
	 * Transient key for cross-tab execution lock.
	 */
	const LOCK_TRANSIENT = 'fse_opt_active_task_lock';

	/**
	 * Plugin instance.
	 *
	 * @var FSE_Performance_Optimizer
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param FSE_Performance_Optimizer $plugin Main plugin object.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Acquire a backend transient lock to prevent concurrent execution across browser tabs.
	 *
	 * @param string $task_key   Machine key (e.g. `bulk_media`, `convert_fonts`, `db_cleanup`).
	 * @param string $task_label Human-readable label.
	 * @param int    $ttl        Auto-expire seconds.
	 * @return true|string True if acquired, or error message if locked by another task.
	 */
	private function acquire_task_lock( $task_key, $task_label, $ttl = 120 ) {
		$current = get_transient( self::LOCK_TRANSIENT );
		if ( is_array( $current ) && ! empty( $current['key'] ) ) {
			if ( $current['key'] !== $task_key ) {
				return sprintf(
					'Hệ thống đang chạy tác vụ "%s", vui lòng chờ hoàn tất trước khi thực hiện thao tác khác.',
					(string) ( $current['label'] ?? $current['key'] )
				);
			}
		}
		set_transient(
			self::LOCK_TRANSIENT,
			array(
				'key'        => $task_key,
				'label'      => $task_label,
				'started_at' => time(),
			),
			$ttl
		);
		return true;
	}

	/**
	 * Release backend transient lock.
	 *
	 * @param string $task_key Optional key check; empty releases unconditionally.
	 */
	private function release_task_lock( $task_key = '' ) {
		if ( '' !== $task_key ) {
			$current = get_transient( self::LOCK_TRANSIENT );
			if ( is_array( $current ) && ( $current['key'] ?? '' ) !== $task_key ) {
				return;
			}
		}
		delete_transient( self::LOCK_TRANSIENT );
	}

	/**
	 * Register admin menus, admin bar links, and AJAX endpoints.
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_bar_menu', array( $this, 'register_admin_bar_node' ), 100 );
		add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );

		// AJAX endpoints for Share-Hosting safe batch optimization.
		add_action( 'wp_ajax_fse_opt_get_unoptimized_media', array( $this, 'ajax_get_unoptimized_media' ) );
		add_action( 'wp_ajax_fse_opt_process_media_batch', array( $this, 'ajax_process_media_batch' ) );
	}

	/**
	 * Add Top-Level Admin Menu + submenu under Settings & Tools.
	 */
	public function register_admin_menu() {
		add_menu_page(
			'FSE Performance Optimizer',
			'⚡ FSE Optimizer',
			'manage_options',
			'fse-performance-optimizer',
			array( $this, 'render_admin_page' ),
			'dashicons-performance',
			59
		);

		add_options_page(
			'FSE Performance Optimizer',
			'FSE Optimizer',
			'manage_options',
			'fse-performance-optimizer',
			array( $this, 'render_admin_page' )
		);

		add_management_page(
			'FSE Performance Optimizer',
			'FSE Optimizer & Migration',
			'manage_options',
			'fse-performance-optimizer',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Add quick Clear Cache button to WordPress Admin Bar.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar object.
	 */
	public function register_admin_bar_node( $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$url = wp_nonce_url(
			admin_url( 'admin.php?page=fse-performance-optimizer&fse_action=clear_cache' ),
			'fse_opt_quick_action'
		);
		$wp_admin_bar->add_node(
			array(
				'id'    => 'fse-opt-clear-cache',
				'title' => '⚡ Xóa Cache FSE',
				'href'  => $url,
			)
		);
	}

	/**
	 * Handle synchronous admin actions (Clear Cache, Save Settings, Run Benchmark, Run DB Cleanup, Convert Fonts).
	 */
	public function handle_admin_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Quick GET clear cache from Admin Bar.
		if ( isset( $_GET['fse_action'] ) && 'clear_cache' === $_GET['fse_action'] && check_admin_referer( 'fse_opt_quick_action' ) ) {
			$this->plugin->cache->clear_all_cache();
			wp_safe_redirect( remove_query_arg( array( 'fse_action', '_wpnonce' ), wp_get_referer() ?: admin_url( 'options-general.php?page=fse-performance-optimizer' ) ) );
			exit;
		}

		if ( empty( $_POST['fse_opt_form_action'] ) ) {
			return;
		}

		check_admin_referer( 'fse_opt_admin_nonce', 'fse_opt_nonce' );
		$action = sanitize_key( wp_unslash( $_POST['fse_opt_form_action'] ) );

		$lock = $this->acquire_task_lock( 'sync_' . $action, 'Tác vụ ' . $action, 180 );
		if ( true !== $lock ) {
			add_settings_error( 'fse_opt_notices', 'task_locked', $lock, 'error' );
			return;
		}

		try {
			if ( 'save_settings' === $action ) {
				$new_settings = array(
					'enable_image_opt'      => ! empty( $_POST['enable_image_opt'] ),
					'max_image_edge'        => max( 600, min( 4096, (int) ( $_POST['max_image_edge'] ?? 1920 ) ) ),
					'image_quality'         => max( 80, min( 100, (int) ( $_POST['image_quality'] ?? 100 ) ) ),
					'enable_lossy_saving'   => ! empty( $_POST['enable_lossy_saving'] ),
					'preserve_icc'          => ! empty( $_POST['preserve_icc'] ),
					'replace_original'      => ! empty( $_POST['replace_original'] ),
					'enable_js_defer'       => ! empty( $_POST['enable_js_defer'] ),
					'enable_minicart_delay' => ! empty( $_POST['enable_minicart_delay'] ),
					'enable_asset_unload'   => ! empty( $_POST['enable_asset_unload'] ),
					'enable_font_opt'       => ! empty( $_POST['enable_font_opt'] ),
					'preload_fonts_count'   => max( 0, min( 6, (int) ( $_POST['preload_fonts_count'] ?? 2 ) ) ),
					'enable_cwv_guard'      => ! empty( $_POST['enable_cwv_guard'] ),
					'enable_page_cache'     => ! empty( $_POST['enable_page_cache'] ),
					'enable_minify'         => ! empty( $_POST['enable_minify'] ),
					'enable_htaccess'       => ! empty( $_POST['enable_htaccess'] ),
					'cache_ttl'             => max( 300, (int) ( $_POST['cache_ttl'] ?? 36000 ) ),
					'max_revisions'         => max( 0, min( 50, (int) ( $_POST['max_revisions'] ?? 5 ) ) ),
				);

				update_option( FSE_Performance_Optimizer::OPTION_KEY, $new_settings );
				$this->plugin->reload_settings();
				$this->plugin->cache->update_htaccess_rules( $new_settings['enable_htaccess'] );
				$this->plugin->cache->clear_all_cache();
				add_settings_error( 'fse_opt_notices', 'settings_saved', 'Đã lưu cấu hình và làm mới Page Cache.', 'updated' );
			} elseif ( 'run_benchmark' === $action ) {
				if ( ! get_option( 'fse_opt_baseline_snapshot' ) ) {
					$base_snap = $this->plugin->benchmark->measure_all_pages( true );
					update_option( 'fse_opt_baseline_snapshot', $base_snap, false );
				}
				// Warm page cache first so HIT TTFB is recorded accurately.
				$this->plugin->benchmark->measure_all_pages( false );
				$snap = $this->plugin->benchmark->measure_all_pages( false );
				update_option( 'fse_opt_current_snapshot', $snap, false );
				add_settings_error( 'fse_opt_notices', 'bench_done', 'Đã đo lường lại toàn bộ các mẫu trang hiện tại.', 'updated' );
			} elseif ( 'capture_baseline' === $action ) {
				$snap = $this->plugin->benchmark->measure_all_pages( true );
				update_option( 'fse_opt_baseline_snapshot', $snap, false );
				add_settings_error( 'fse_opt_notices', 'base_done', 'Đã lưu mốc Baseline (Trước tối ưu).', 'updated' );
			} elseif ( 'clear_cache' === $action ) {
				$cnt = $this->plugin->cache->clear_all_cache();
				add_settings_error( 'fse_opt_notices', 'cache_cleared', sprintf( 'Đã xóa sạch Page Cache (%d file).', $cnt ), 'updated' );
			} elseif ( 'convert_fonts' === $action ) {
				if ( ! get_option( 'fse_opt_baseline_snapshot' ) ) {
					update_option( 'fse_opt_baseline_snapshot', $this->plugin->benchmark->measure_all_pages( true ), false );
				}
				$res = $this->plugin->fonts->convert_uploaded_fonts_to_woff2();
				$this->plugin->cache->clear_all_cache();
				add_settings_error(
					'fse_opt_notices',
					'fonts_done',
					sprintf(
						'Đã xử lý %d/%d file font sang .woff2 (Trước: %s → Sau: %s).',
						(int) $res['converted'],
						(int) $res['total_files'],
						size_format( $res['before_bytes'], 2 ),
						size_format( $res['after_bytes'], 2 )
					),
					'updated'
				);
			} elseif ( 'upload_woff2_fonts' === $action ) {
				$upload_dir = wp_upload_dir();
				$fonts_dir  = $upload_dir['basedir'] . '/fonts';
				wp_mkdir_p( $fonts_dir );
				$uploaded_cnt = 0;
				if ( ! empty( $_FILES['fse_woff2_files']['name'] ) && is_array( $_FILES['fse_woff2_files']['name'] ) ) {
					foreach ( $_FILES['fse_woff2_files']['name'] as $idx => $fname ) {
						$safe_name = sanitize_file_name( $fname );
						$tmp_name  = $_FILES['fse_woff2_files']['tmp_name'][ $idx ] ?? '';
						if ( str_ends_with( strtolower( $safe_name ), '.woff2' ) && is_uploaded_file( $tmp_name ) ) {
							if ( @move_uploaded_file( $tmp_name, $fonts_dir . '/' . $safe_name ) ) {
								++$uploaded_cnt;
							}
						}
					}
				}
				$res = $this->plugin->fonts->convert_uploaded_fonts_to_woff2();
				$this->plugin->cache->clear_all_cache();
				add_settings_error(
					'fse_opt_notices',
					'woff2_uploaded',
					sprintf( 'Đã tải lên %d file .woff2 và đồng bộ %d/%d font trong FSE Global Styles.', $uploaded_cnt, (int) $res['converted'], (int) $res['total_files'] ),
					'updated'
				);
			} elseif ( 'run_db_cleanup' === $action ) {
				if ( ! get_option( 'fse_opt_baseline_snapshot' ) ) {
					update_option( 'fse_opt_baseline_snapshot', $this->plugin->benchmark->measure_all_pages( true ), false );
				}
				$res = $this->plugin->database->run_cleanup();
				add_settings_error(
					'fse_opt_notices',
					'db_cleaned',
					sprintf(
						'Đã dọn dẹp DB thành công: xóa %d Revisions, %d Transients, %d Action Scheduler logs. Dung lượng DB: %s → %s.',
						(int) $res['deleted_revisions'],
						(int) $res['deleted_transients'],
						(int) $res['deleted_as'],
						size_format( $res['before_bytes'], 2 ),
						size_format( $res['after_bytes'], 2 )
					),
					'updated'
				);
			}
		} finally {
			$this->release_task_lock( 'sync_' . $action );
		}
	}

	/**
	 * AJAX: Return list of attachment IDs to process in Bulk Media Optimizer.
	 */
	public function ajax_get_unoptimized_media() {
		check_ajax_referer( 'fse_opt_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden' );
		}

		$lock = $this->acquire_task_lock( 'bulk_media', 'Tối ưu hàng loạt ảnh Media', 120 );
		if ( true !== $lock ) {
			wp_send_json_error( $lock );
		}

		if ( ! get_option( 'fse_opt_baseline_snapshot' ) ) {
			update_option( 'fse_opt_baseline_snapshot', $this->plugin->benchmark->measure_all_pages( true ), false );
		}

		global $wpdb;
		$force_all = ! empty( $_POST['force_all'] );

		if ( $force_all ) {
			$ids = $wpdb->get_col(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = 'attachment'
				   AND post_mime_type IN ('image/jpeg', 'image/png', 'image/webp')
				 ORDER BY ID ASC"
			);
		} else {
			$ids = $wpdb->get_col(
				"SELECT p.ID FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON (pm.post_id = p.ID AND pm.meta_key = '_fse_opt_stats')
				 WHERE p.post_type = 'attachment'
				   AND p.post_mime_type IN ('image/jpeg', 'image/png', 'image/webp')
				   AND (pm.meta_id IS NULL OR p.post_mime_type != 'image/webp')
				 ORDER BY p.ID ASC"
			);
		}

		if ( empty( $ids ) ) {
			$this->release_task_lock( 'bulk_media' );
		}

		wp_send_json_success(
			array(
				'ids'   => array_map( 'intval', is_array( $ids ) ? $ids : array() ),
				'total' => is_array( $ids ) ? count( $ids ) : 0,
			)
		);
	}

	/**
	 * AJAX: Process a small batch of attachment IDs (Share-Hosting safe, avoids max_execution_time).
	 */
	public function ajax_process_media_batch() {
		check_ajax_referer( 'fse_opt_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden' );
		}

		$lock = $this->acquire_task_lock( 'bulk_media', 'Tối ưu hàng loạt ảnh Media', 120 );
		if ( true !== $lock ) {
			wp_send_json_error( $lock );
		}

		$raw_ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'intval', $_POST['ids'] ) : array();
		if ( empty( $raw_ids ) ) {
			$this->release_task_lock( 'bulk_media' );
			wp_send_json_error( 'No IDs provided' );
		}

		$items    = array();
		$path_map = array();

		foreach ( $raw_ids as $att_id ) {
			$res = $this->plugin->image_opt->optimize_existing_attachment( $att_id );
			if ( ! empty( $res['success'] ) ) {
				if ( ! empty( $res['url_replacements'] ) && is_array( $res['url_replacements'] ) ) {
					$path_map = array_merge( $path_map, $res['url_replacements'] );
				}
				$st      = $res['stats'];
				$items[] = array(
					'id'     => $att_id,
					'file'   => $res['file'],
					'before' => size_format( $st['before_bytes'], 2 ),
					'after'  => size_format( $st['after_bytes'], 2 ),
					'dims'   => $st['before_width'] . '×' . $st['before_height'] . ' → ' . $st['after_width'] . '×' . $st['after_height'],
					'icc'    => $st['icc_preserved'] ? 'Giữ nguyên ICC' : 'sRGB mặc định',
					'engine' => $st['engine'],
				);
			} else {
				$items[] = array(
					'id'    => $att_id,
					'error' => $res['error'] ?? $res['reason'] ?? 'Lỗi không xác định',
				);
			}
		}

		if ( ! empty( $path_map ) ) {
			$this->plugin->image_opt->replace_image_urls_in_db( $path_map );
		}

		// On the final batch, also convert & clean any remaining unattached .jpg/.png in uploads/YYYY/MM.
		if ( ! empty( $_POST['is_last'] ) ) {
			$upload_dir = wp_upload_dir()['basedir'];
			$settings   = $this->plugin->get_settings();
			$max_edge   = max( 600, (int) ( $settings['max_image_edge'] ?? 1920 ) );
			$quality    = $this->plugin->image_opt->get_effective_quality();
			$keep_icc   = ! empty( $settings['preserve_icc'] );
			$freed      = 0;

			$year_dirs = glob( $upload_dir . '/20*', GLOB_ONLYDIR );
			if ( is_array( $year_dirs ) ) {
				foreach ( $year_dirs as $yd ) {
					$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $yd, RecursiveDirectoryIterator::SKIP_DOTS ) );
					foreach ( $it as $f ) {
						if ( ! $f->isFile() ) {
							continue;
						}
						$p_str = $f->getPathname();
						if ( preg_match( '/\.(jpe?g|png)$/i', $p_str ) ) {
							$w_str   = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $p_str );
							$orig_sz = (int) $f->getSize();
							if ( ! file_exists( $w_str ) ) {
								$c_res = $this->plugin->image_opt->convert_and_resize_to_webp( $p_str, $w_str, $max_edge, $quality, $keep_icc );
								if ( file_exists( $w_str ) && (int) @filesize( $w_str ) >= $orig_sz ) {
									// Never keep an unattached WebP larger than original if not smaller.
									@unlink( $w_str );
								}
							}
							if ( file_exists( $w_str ) && (int) @filesize( $w_str ) < $orig_sz && ! empty( $settings['replace_original'] ) ) {
								$freed += $orig_sz;
								@unlink( $p_str );
							}
						}
					}
				}
			}
			if ( $freed > 0 ) {
				$prev_freed = (int) get_option( 'fse_opt_extra_freed_bytes', 0 );
				update_option( 'fse_opt_extra_freed_bytes', $prev_freed + $freed, false );
			}
			$this->plugin->cache->clear_all_cache();
			$this->release_task_lock( 'bulk_media' );
		}

		wp_send_json_success( array( 'items' => $items ) );
	}

	/**
	 * Render the 4-tab Admin Page.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Bạn không có quyền truy cập.' );
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		$settings   = $this->plugin->get_settings();
		$media_sum  = $this->plugin->benchmark->get_media_summary();
		$font_stats = get_option( 'fse_opt_font_stats', array() );
		$db_report  = $this->plugin->database->get_database_report();
		$db_stats   = get_option( 'fse_opt_db_stats', array() );
		$baseline   = get_option( 'fse_opt_baseline_snapshot', array() );
		$current    = get_option( 'fse_opt_current_snapshot', array() );
		$cache_st   = $this->plugin->cache->get_cache_stats();
		$active_lock = get_transient( self::LOCK_TRANSIENT );
		?>
		<div class="wrap fse-opt-wrap">
			<h1>⚡ FSE Performance Optimizer <small style="font-size:13px;color:#666;font-weight:normal;">v1.2.0 — Bởi TuanLee (100% Share-Hosting Ready)</small></h1>

			<?php if ( is_array( $active_lock ) && ! empty( $active_lock['label'] ) ) : ?>
				<div class="notice notice-warning inline" style="padding:10px 15px;">
					<strong>⏳ Đang có tác vụ nền hoạt động ở một tab khác:</strong> <?php echo esc_html( $active_lock['label'] ); ?>. Các thao tác song song sẽ tạm khóa cho tới khi tác vụ đó hoàn tất.
				</div>
			<?php endif; ?>

			<?php settings_errors( 'fse_opt_notices' ); ?>

			<h2 class="nav-tab-wrapper" style="margin-bottom: 18px;">
				<a href="?page=fse-performance-optimizer&tab=dashboard" class="nav-tab <?php echo 'dashboard' === $active_tab ? 'nav-tab-active' : ''; ?>">📊 Trước &amp; Sau Tối Ưu (Dashboard)</a>
				<a href="?page=fse-performance-optimizer&tab=settings" class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">⚙️ Cấu Hình Runtime</a>
				<a href="?page=fse-performance-optimizer&tab=bulk" class="nav-tab <?php echo 'bulk' === $active_tab ? 'nav-tab-active' : ''; ?>">🖼️ Bulk Media &amp; Fonts (Q=100 + ICC)</a>
				<a href="?page=fse-performance-optimizer&tab=database" class="nav-tab <?php echo 'database' === $active_tab ? 'nav-tab-active' : ''; ?>">🗄️ Database &amp; Autoload</a>
				<a href="?page=fse-performance-optimizer&tab=migration" class="nav-tab <?php echo 'migration' === $active_tab ? 'nav-tab-active' : ''; ?>">🔄 Chuyển Đổi Domain (FSE Migration)</a>
				<a href="?page=fse-performance-optimizer&tab=guide" class="nav-tab <?php echo 'guide' === $active_tab ? 'nav-tab-active' : ''; ?>">📖 Hướng Dẫn Sử Dụng</a>
			</h2>

			<?php if ( 'dashboard' === $active_tab ) : ?>
				<?php $this->render_dashboard_tab( $media_sum, $font_stats, $db_report, $db_stats, $baseline, $current, $cache_st ); ?>
			<?php elseif ( 'settings' === $active_tab ) : ?>
				<?php $this->render_settings_tab( $settings ); ?>
			<?php elseif ( 'bulk' === $active_tab ) : ?>
				<?php $this->render_bulk_tab( $media_sum, $font_stats ); ?>
			<?php elseif ( 'database' === $active_tab ) : ?>
				<?php $this->render_database_tab( $db_report, $db_stats ); ?>
			<?php elseif ( 'migration' === $active_tab ) : ?>
				<?php $this->render_migration_tab(); ?>
			<?php elseif ( 'guide' === $active_tab ) : ?>
				<?php $this->render_guide_tab(); ?>
			<?php endif; ?>
		</div>
		<script>
		(function(){
			var isLocked = false;
			function setButtonsLocked(lock, activeBtn, activeMsg){
				isLocked = !!lock;
				var wrap = document.querySelector('.fse-opt-wrap');
				if(!wrap) return;
				wrap.querySelectorAll('button, input[type="submit"]').forEach(function(el){
					if(el.classList && el.classList.contains('fse-fill-old') && !lock){ return; }
					if(lock){
						if(!el.dataset.origText){ el.dataset.origText = el.textContent; }
						el.disabled = true;
						if(activeBtn && el === activeBtn && activeMsg){
							el.textContent = activeMsg;
						}
					} else {
						el.disabled = false;
						if(el.dataset.origText){
							el.textContent = el.dataset.origText;
						}
					}
				});
				wrap.querySelectorAll('.nav-tab').forEach(function(tab){
					if(lock){
						tab.style.pointerEvents = 'none';
						tab.style.opacity = '0.55';
					} else {
						tab.style.pointerEvents = '';
						tab.style.opacity = '';
					}
				});
			}
			window.fseOptSetUILock = setButtonsLocked;

			var wrap = document.querySelector('.fse-opt-wrap');
			if(wrap){
				wrap.querySelectorAll('form').forEach(function(form){
					form.addEventListener('submit', function(e){
						if(isLocked){
							e.preventDefault();
							return false;
						}
						var submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
						// Disable all other buttons after current submit event starts so POST data includes submit button name/value.
						setTimeout(function(){
							setButtonsLocked(true, submitBtn, '⏳ Đang xử lý, vui lòng chờ...');
						}, 0);
					});
				});
			}
		})();
		</script>
		<?php
	}

	/**
	 * Render Tab 1: Before vs After Telemetry Dashboard.
	 */
	private function render_dashboard_tab( $media_sum, $font_stats, $db_report, $db_stats, $baseline, $current, $cache_st ) {
		$media_before = (int) ( $media_sum['before_bytes'] ?? 0 );
		$media_after  = (int) ( $media_sum['after_bytes'] ?? 0 );
		$media_pct    = $media_before > 0 ? round( ( 1 - $media_after / $media_before ) * 100, 1 ) : 0;

		$font_before = (int) ( $font_stats['before_bytes'] ?? 0 );
		$font_after  = (int) ( $font_stats['after_bytes'] ?? 0 );
		$font_pct    = $font_before > 0 ? round( ( 1 - $font_after / $font_before ) * 100, 1 ) : 0;

		$db_before = ! empty( $db_stats['before_bytes'] ) ? (int) $db_stats['before_bytes'] : (int) $db_report['total_bytes'];
		$db_after  = (int) $db_report['total_bytes'];
		$db_pct    = $db_before > 0 ? round( ( 1 - $db_after / $db_before ) * 100, 1 ) : 0;

		$home_base = $baseline['pages']['Homepage'] ?? null;
		$home_curr = $current['pages']['Homepage'] ?? null;
		?>
		<style>
			.fse-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-bottom: 22px; }
			.fse-kpi-card { background: #fff; border: 1px solid #ccd0d4; border-left: 4px solid #2271b1; border-radius: 4px; padding: 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
			.fse-kpi-card h3 { margin: 0 0 10px; font-size: 14px; color: #1d2327; }
			.fse-kpi-compare { font-size: 18px; font-weight: 600; margin-bottom: 6px; }
			.fse-kpi-before { color: #b32d2e; text-decoration: line-through; font-weight: normal; font-size: 14px; margin-right: 6px; }
			.fse-kpi-after { color: #007017; }
			.fse-badge-save { display: inline-block; background: #e6f6e6; color: #007017; font-size: 12px; font-weight: 600; padding: 2px 8px; border-radius: 10px; }
			.fse-bench-table th, .fse-bench-table td { vertical-align: middle; font-size: 13px; }
		</style>

		<div class="fse-kpi-grid">
			<div class="fse-kpi-card">
				<h3>🖼️ Kho Ảnh Media (WebP Q=100 + ICC)</h3>
				<div class="fse-kpi-compare">
					<?php if ( $media_before > 0 ) : ?>
						<span class="fse-kpi-before"><?php echo esc_html( size_format( $media_before, 2 ) ); ?></span>
						→ <span class="fse-kpi-after"><?php echo esc_html( size_format( $media_after, 2 ) ); ?></span>
						<span class="fse-badge-save">-<?php echo esc_html( (string) $media_pct ); ?>%</span>
					<?php else : ?>
						<span>Chưa chạy Bulk Media</span>
					<?php endif; ?>
				</div>
				<p style="margin:0;color:#50575e;font-size:12px;">
					Đã chuyển WebP: <strong><?php echo (int) $media_sum['webp_attachments']; ?> / <?php echo (int) $media_sum['total_attachments']; ?></strong> ảnh
					(Giữ ICC: <strong><?php echo (int) $media_sum['icc_count']; ?></strong> ảnh)
				</p>
			</div>

			<div class="fse-kpi-card" style="border-left-color:#8c5eff;">
				<h3>🔤 Font Chữ (.ttf/.otf → .woff2 + Swap)</h3>
				<div class="fse-kpi-compare">
					<?php if ( $font_before > 0 ) : ?>
						<span class="fse-kpi-before"><?php echo esc_html( size_format( $font_before, 2 ) ); ?></span>
						→ <span class="fse-kpi-after"><?php echo esc_html( size_format( $font_after, 2 ) ); ?></span>
						<span class="fse-badge-save">-<?php echo esc_html( (string) $font_pct ); ?>%</span>
					<?php else : ?>
						<span>Chưa convert .woff2</span>
					<?php endif; ?>
				</div>
				<p style="margin:0;color:#50575e;font-size:12px;">
					Đã convert: <strong><?php echo (int) ( $font_stats['converted'] ?? 0 ); ?> / <?php echo (int) ( $font_stats['total_files'] ?? 0 ); ?></strong> font | Runtime <code>font-display: swap</code>: <strong>Bật</strong>
				</p>
			</div>

			<div class="fse-kpi-card" style="border-left-color:#d63638;">
				<h3>🗄️ Database &amp; FSE Revisions</h3>
				<div class="fse-kpi-compare">
					<span class="fse-kpi-before"><?php echo esc_html( size_format( $db_before, 2 ) ); ?></span>
					→ <span class="fse-kpi-after"><?php echo esc_html( size_format( $db_after, 2 ) ); ?></span>
					<?php if ( $db_pct > 0 ) : ?>
						<span class="fse-badge-save">-<?php echo esc_html( (string) $db_pct ); ?>%</span>
					<?php endif; ?>
				</div>
				<p style="margin:0;color:#50575e;font-size:12px;">
					Revisions hiện tại: <strong><?php echo (int) $db_report['revisions_count']; ?></strong> dòng (<?php echo esc_html( size_format( $db_report['revisions_bytes'], 2 ) ); ?>) | Autoload: <strong><?php echo esc_html( size_format( $db_report['autoload_bytes'], 1 ) ); ?></strong>
				</p>
			</div>

			<div class="fse-kpi-card" style="border-left-color:#00a32a;">
				<h3>🚀 Trang Chủ &amp; Page Cache</h3>
				<div class="fse-kpi-compare">
					<?php if ( $home_base && $home_curr ) : ?>
						<span class="fse-kpi-before"><?php echo esc_html( size_format( $home_base['total_bytes'], 2 ) ); ?></span>
						→ <span class="fse-kpi-after"><?php echo esc_html( size_format( $home_curr['total_bytes'], 2 ) ); ?></span>
						<?php
						$hp_pct = $home_base['total_bytes'] > 0
							? round( ( 1 - $home_curr['total_bytes'] / $home_base['total_bytes'] ) * 100, 1 )
							: 0;
						?>
						<span class="fse-badge-save">-<?php echo esc_html( (string) $hp_pct ); ?>%</span>
					<?php else : ?>
						<span>Đang sẵn sàng đo</span>
					<?php endif; ?>
				</div>
				<p style="margin:0;color:#50575e;font-size:12px;">
					Blocking JS: <strong><?php echo (int) ( $home_base['blocking_js'] ?? 0 ); ?> → <?php echo (int) ( $home_curr['blocking_js'] ?? 0 ); ?></strong> |
					Cache tĩnh: <strong><?php echo (int) $cache_st['files']; ?> trang</strong> (<?php echo esc_html( size_format( $cache_st['bytes'], 1 ) ); ?>)
				</p>
			</div>
		</div>

		<div style="background:#fff;border:1px solid #ccd0d4;padding:18px;border-radius:4px;">
			<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
				<div>
					<h2 style="margin:0;">Bảng Đối Chiếu Thông Số Trước &amp; Sau Tối Ưu (Theo Từng Mẫu Trang Unique)</h2>
					<p style="margin:4px 0 0;color:#646970;">So sánh trực tiếp TTFB, HTML/Inline CSS, JS tải ban đầu &amp; Blocking JS, Dung lượng Ảnh, CLS (`width`/`height`) và Tổng trọng lượng trang.</p>
				</div>
				<div style="display:flex;gap:8px;">
					<form method="post" style="margin:0;">
						<?php wp_nonce_field( 'fse_opt_admin_nonce', 'fse_opt_nonce' ); ?>
						<input type="hidden" name="fse_opt_form_action" value="capture_baseline">
						<button type="submit" class="button">📌 Chụp Mốc Trước Tối Ưu (Baseline)</button>
					</form>
					<form method="post" style="margin:0;">
						<?php wp_nonce_field( 'fse_opt_admin_nonce', 'fse_opt_nonce' ); ?>
						<input type="hidden" name="fse_opt_form_action" value="run_benchmark">
						<button type="submit" class="button button-primary">🔄 Đo Lại Hiện Trạng (Sau Tối Ưu)</button>
					</form>
					<form method="post" style="margin:0;">
						<?php wp_nonce_field( 'fse_opt_admin_nonce', 'fse_opt_nonce' ); ?>
						<input type="hidden" name="fse_opt_form_action" value="clear_cache">
						<button type="submit" class="button">🧹 Xóa Page Cache</button>
					</form>
				</div>
			</div>

			<table class="widefat striped fse-bench-table">
				<thead>
					<tr>
						<th>Mẫu Trang (Unique Page)</th>
						<th>TTFB (Trước → Sau)</th>
						<th>HTML / Inline CSS (Trước → Sau)</th>
						<th>JS Tải Đầu (Trước → Sau)</th>
						<th>Blocking JS (Trước → Sau)</th>
						<th>Ảnh &amp; Thiếu W×H (Trước → Sau)</th>
						<th>Fonts (.woff2 / swap)</th>
						<th>Tổng Trang (Trước → Sau)</th>
					</tr>
				</thead>
				<tbody>
					<?php
					$all_labels = array_unique(
						array_merge(
							array_keys( $baseline['pages'] ?? array() ),
							array_keys( $current['pages'] ?? array() )
						)
					);
					if ( empty( $all_labels ) ) :
						?>
						<tr><td colspan="8">Chưa có dữ liệu benchmark. Hãy bấm <strong>Đo Lại Hiện Trạng</strong> để cập nhật bảng.</td></tr>
					<?php else : ?>
						<?php foreach ( $all_labels as $label ) : ?>
							<?php
							$b = $baseline['pages'][ $label ] ?? null;
							$c = $current['pages'][ $label ] ?? null;
							$url = $c['url'] ?? $b['url'] ?? '';
							$tot_b = (int) ( $b['total_bytes'] ?? 0 );
							$tot_c = (int) ( $c['total_bytes'] ?? 0 );
							$row_pct = ( $tot_b > 0 && $tot_c > 0 ) ? round( ( 1 - $tot_c / $tot_b ) * 100, 1 ) : 0;
							?>
							<tr>
								<td>
									<strong><a href="<?php echo esc_url( $url ); ?>" target="_blank"><?php echo esc_html( $label ); ?></a></strong>
									<?php if ( ! empty( $c['cache_status'] ) ) : ?>
										<br><small style="color:#007017;">Cache: <code><?php echo esc_html( $c['cache_status'] ); ?></code></small>
									<?php endif; ?>
								</td>
								<td>
									<?php echo $b ? esc_html( $b['ttfb_ms'] . ' ms' ) : '—'; ?>
									→ <strong><?php echo $c ? esc_html( $c['ttfb_ms'] . ' ms' ) : '—'; ?></strong>
								</td>
								<td>
									<?php echo $b ? esc_html( size_format( $b['html_bytes'], 1 ) ) : '—'; ?>
									→ <strong><?php echo $c ? esc_html( size_format( $c['html_bytes'], 1 ) ) : '—'; ?></strong>
									<br><small style="color:#666;">Inline CSS: <?php echo $b ? esc_html( size_format( $b['inline_css_bytes'], 1 ) ) : '—'; ?> → <?php echo $c ? esc_html( size_format( $c['inline_css_bytes'], 1 ) ) : '—'; ?></small>
								</td>
								<td>
									<?php echo $b ? esc_html( $b['js_files'] . ' file (' . size_format( $b['js_bytes'], 1 ) . ')' ) : '—'; ?>
									→ <strong style="color:#007017;"><?php echo $c ? esc_html( $c['js_files'] . ' file (' . size_format( $c['js_bytes'], 1 ) . ')' ) : '—'; ?></strong>
									<?php if ( ! empty( $c['delayed_js'] ) ) : ?>
										<br><small style="color:#2271b1;">Đã delay: <?php echo (int) $c['delayed_js']; ?> file Mini-Cart/React</small>
									<?php endif; ?>
								</td>
								<td>
									<span style="color:#b32d2e;"><?php echo $b ? (int) $b['blocking_js'] : '—'; ?></span>
									→ <strong style="color:#007017;"><?php echo $c ? (int) $c['blocking_js'] : '—'; ?></strong>
								</td>
								<td>
									<?php echo $b ? esc_html( size_format( $b['img_bytes'], 2 ) ) : '—'; ?>
									→ <strong style="color:#007017;"><?php echo $c ? esc_html( size_format( $c['img_bytes'], 2 ) ) : '—'; ?></strong>
									<br><small style="color:#666;">Thiếu W×H: <?php echo $b ? (int) $b['missing_dims'] : '—'; ?> → <strong><?php echo $c ? (int) $c['missing_dims'] : '—'; ?></strong> | Non-WebP: <?php echo $b ? (int) $b['non_webp'] : '—'; ?> → <strong><?php echo $c ? (int) $c['non_webp'] : '—'; ?></strong></small>
								</td>
								<td>
									<?php if ( $c ) : ?>
										<code><?php echo (int) $c['woff2_faces']; ?>/<?php echo (int) $c['font_faces']; ?></code> .woff2<br>
										<small>swap: <code><?php echo (int) $c['swap_faces']; ?>/<?php echo (int) $c['font_faces']; ?></code></small>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $b ) : ?>
										<span class="fse-kpi-before"><?php echo esc_html( size_format( $tot_b, 2 ) ); ?></span><br>
									<?php endif; ?>
									<?php if ( $c ) : ?>
										<strong class="fse-kpi-after" style="font-size:15px;"><?php echo esc_html( size_format( $tot_c, 2 ) ); ?></strong>
										<?php if ( $row_pct > 0 ) : ?>
											<span class="fse-badge-save">-<?php echo esc_html( (string) $row_pct ); ?>%</span>
										<?php endif; ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render Tab 2: Runtime Configuration Settings.
	 */
	private function render_settings_tab( $s ) {
		?>
		<form method="post" style="background:#fff;border:1px solid #ccd0d4;padding:20px;max-width:860px;border-radius:4px;">
			<?php wp_nonce_field( 'fse_opt_admin_nonce', 'fse_opt_nonce' ); ?>
			<input type="hidden" name="fse_opt_form_action" value="save_settings">

			<h2>1. Tự Động Tối Ưu Ảnh Khi Upload (Non-Tech Friendly)</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Tự động chuyển WebP &amp; Resize</th>
					<td>
						<label><input type="checkbox" name="enable_image_opt" value="1" <?php checked( ! empty( $s['enable_image_opt'] ) ); ?>> Tự động resize &amp; chuyển mọi ảnh upload mới sang WebP</label><br>
						<label><input type="checkbox" name="preserve_icc" value="1" <?php checked( ! empty( $s['preserve_icc'] ) ); ?>> <strong>Bảo toàn 100% ICC Color Profile</strong> (giữ nguyên màu gốc iPhone Display P3 / sRGB trên cả Imagick và PHP GD)</label><br>
						<label><input type="checkbox" name="replace_original" value="1" <?php checked( ! empty( $s['replace_original'] ) ); ?>> Xóa file gốc quá khổ sau khi đã tạo bản WebP (tiết kiệm dung lượng hosting/VPS)</label>
					</td>
				</tr>
				<tr>
					<th scope="row">Kích thước cạnh dài tối đa &amp; Quality</th>
					<td>
						Cạnh dài tối đa: <input type="number" name="max_image_edge" value="<?php echo (int) ( $s['max_image_edge'] ?? 1920 ); ?>" min="600" max="4096" style="width:90px;"> px &nbsp;|&nbsp;
						WebP Quality: <input type="number" name="image_quality" value="<?php echo (int) ( $s['image_quality'] ?? 100 ); ?>" min="80" max="100" style="width:75px;"> (Mặc định <code>100</code> = giữ 100% chất lượng gốc, không lược bỏ chi tiết)<br><br>
						<label><input type="checkbox" name="enable_lossy_saving" value="1" <?php checked( ! empty( $s['enable_lossy_saving'] ) ); ?>> <em>(Tùy chọn nâng cao)</em> Bật chế độ <strong>Ưu tiên giảm dung lượng tối đa (Q=94)</strong> — chấp nhận lược bỏ các chi tiết cực nhỏ mà mắt thường khó thấy để tiết kiệm thêm ~30–40% dung lượng.</label>
					</td>
				</tr>
			</table>

			<hr>
			<h2>2. Tối Ưu JS, WooCommerce Mini-Cart Drawer &amp; CSS</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Xử lý JS &amp; Tài nguyên dư thừa</th>
					<td>
						<label><input type="checkbox" name="enable_js_defer" value="1" <?php checked( ! empty( $s['enable_js_defer'] ) ); ?>> Tự động gắn <code>defer</code> cho toàn bộ script chặn hiển thị (giữ nguyên thứ tự phụ thuộc)</label><br>
						<label><input type="checkbox" name="enable_minicart_delay" value="1" <?php checked( ! empty( $s['enable_minicart_delay'] ) ); ?>> <strong>Smart Delay cho Mini-Cart &amp; React Bundle (~1.9 MB):</strong> Trì hoãn tải tới khi người dùng tương tác hoặc bấm vào icon Giỏ hàng (giữ nguyên 100% chức năng mở Drawer)</label><br>
						<label><input type="checkbox" name="enable_asset_unload" value="1" <?php checked( ! empty( $s['enable_asset_unload'] ) ); ?>> Tự động gỡ CSS/JS thừa theo ngữ cảnh trang (gỡ <code>video-wc-gallery</code> khi sản phẩm không có video, gỡ CSS sản phẩm trên trang tĩnh)</label>
					</td>
				</tr>
			</table>

			<hr>
			<h2>3. Runtime Font Optimizer &amp; Core Web Vitals (LCP / CLS)</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Tối ưu Font Runtime (FSE &amp; Google Fonts)</th>
					<td>
						<label><input type="checkbox" name="enable_font_opt" value="1" <?php checked( ! empty( $s['enable_font_opt'] ) ); ?>> Tự động ép <code>font-display: swap</code>, ưu tiên nạp <code>.woff2</code>, loại bỏ <code>@font-face</code> trùng lặp và thêm <code>preconnect</code> cho Google Fonts</label><br>
						Số lượng font chính tự động <code>preload</code>: <input type="number" name="preload_fonts_count" value="<?php echo (int) ( $s['preload_fonts_count'] ?? 2 ); ?>" min="0" max="6" style="width:65px;">
					</td>
				</tr>
				<tr>
					<th scope="row">Chuẩn hóa LCP &amp; Chống CLS</th>
					<td>
						<label><input type="checkbox" name="enable_cwv_guard" value="1" <?php checked( ! empty( $s['enable_cwv_guard'] ) ); ?>> Tự động điền <code>width</code>/<code>height</code> còn thiếu cho thẻ <code>&lt;img&gt;</code>, gắn <code>fetchpriority="high"</code> cho ảnh LCP đầu trang và <code>loading="lazy"</code> cho các ảnh bên dưới</label>
					</td>
				</tr>
			</table>

			<hr>
			<h2>4. Page Cache Tĩnh, Minify &amp; Giới Hạn Revisions FSE</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Static HTML Page Cache &amp; Minify</th>
					<td>
						<label><input type="checkbox" name="enable_page_cache" value="1" <?php checked( ! empty( $s['enable_page_cache'] ) ); ?>> Bật Static HTML Page Cache (<code>.html</code> + <code>.html.gz</code>, tự động loại trừ Cart/Checkout/Account &amp; tự xóa khi cập nhật bài/sản phẩm)</label><br>
						<label><input type="checkbox" name="enable_minify" value="1" <?php checked( ! empty( $s['enable_minify'] ) ); ?>> Minify HTML &amp; Inline CSS (thu gọn CSS nội tuyến của FSE/Greenshift)</label><br>
						<label><input type="checkbox" name="enable_htaccess" value="1" <?php checked( ! empty( $s['enable_htaccess'] ) ); ?>> Tự động cấu hình Browser Cache 1 năm &amp; Gzip trong <code>.htaccess</code> (dành cho Apache/LiteSpeed Share Hosting)</label>
					</td>
				</tr>
				<tr>
					<th scope="row">Thời gian sống Cache &amp; Giới hạn Revisions</th>
					<td>
						TTL Page Cache: <input type="number" name="cache_ttl" value="<?php echo (int) ( $s['cache_ttl'] ?? 36000 ); ?>" min="300" style="width:90px;"> giây &nbsp;|&nbsp;
						Giữ tối đa: <input type="number" name="max_revisions" value="<?php echo (int) ( $s['max_revisions'] ?? 5 ); ?>" min="0" max="50" style="width:65px;"> bản Revision cho mỗi trang/template FSE
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary button-large">💾 Lưu Cấu Hình</button>
			</p>
		</form>
		<?php
	}

	/**
	 * Render Tab 3: Bulk Media & Fonts Optimizer.
	 */
	private function render_bulk_tab( $media_sum, $font_stats ) {
		$nonce    = wp_create_nonce( 'fse_opt_ajax_nonce' );
		$font_cap = $this->plugin->fonts->detect_font_converter_capability();
		?>
		<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:1100px;">
			<div style="background:#fff;border:1px solid #ccd0d4;padding:20px;border-radius:4px;">
				<h2 style="margin-top:0;">🖼️ Bulk Media Optimizer (WebP Q=100 + Giữ ICC)</h2>
				<p>Xử lý theo từng lô nhỏ qua AJAX (an toàn tuyệt đối cho cả Share Hosting), tự động resize cạnh dài tối đa theo cấu hình (<code>1920px</code>), xuất <strong>WebP Q=100</strong> bảo toàn <strong>ICC Color Profile</strong> (tự động giữ nguyên file nếu ảnh nhỏ chưa cần nén lại) và cập nhật link ảnh trong Database.</p>
				<p>
					Tổng số ảnh trong thư viện: <strong><?php echo (int) $media_sum['total_attachments']; ?></strong> |
					Đã là WebP: <strong><?php echo (int) $media_sum['webp_attachments']; ?></strong> |
					Dung lượng đã đo: <strong><?php echo esc_html( size_format( $media_sum['before_bytes'], 2 ) ); ?> → <?php echo esc_html( size_format( $media_sum['after_bytes'], 2 ) ); ?></strong>
				</p>
				<p>
					<label><input type="checkbox" id="fse-bulk-force-all" value="1"> Quét lại toàn bộ ảnh (kể cả ảnh đã là WebP)</label>
				</p>
				<button type="button" id="fse-start-bulk-media" class="button button-primary button-large">▶️ Bắt Đầu Tối Ưu Toàn Bộ Ảnh</button>

				<div id="fse-bulk-progress-wrap" style="display:none;margin-top:15px;">
					<div style="background:#f0f0f1;border-radius:4px;overflow:hidden;height:22px;">
						<div id="fse-bulk-bar" style="background:#2271b1;height:100%;width:0%;color:#fff;text-align:center;font-size:12px;line-height:22px;transition:width 0.2s;">0%</div>
					</div>
					<p id="fse-bulk-status" style="margin:6px 0;font-weight:600;"></p>
				</div>
			</div>

			<div style="background:#fff;border:1px solid #ccd0d4;padding:20px;border-radius:4px;">
				<h2 style="margin-top:0;">🔤 Bulk Font Converter (.ttf/.otf → .woff2)</h2>
				<p>Chuyển toàn bộ các file font nặng (<code>.ttf</code> / <code>.otf</code>) trong <code>wp-content/uploads/fonts/</code> sang chuẩn nén <strong><code>.woff2</code></strong> (giữ nguyên 100% nét chữ, giảm ~70% dung lượng) và cập nhật FSE Global Styles sang <code>font-display: swap</code>.</p>
				<p>
					Trạng thái hiện tại: Đã convert <strong><?php echo (int) ( $font_stats['converted'] ?? 0 ); ?> / <?php echo (int) ( $font_stats['total_files'] ?? 0 ); ?></strong> file
					(<strong><?php echo esc_html( size_format( $font_stats['before_bytes'] ?? 0, 2 ) ); ?> → <?php echo esc_html( size_format( $font_stats['after_bytes'] ?? 0, 2 ) ); ?></strong>)
				</p>
				<?php if ( $font_cap['available'] ) : ?>
					<p style="color:#007017;font-size:12px;">✅ Môi trường Server: <?php echo esc_html( $font_cap['reason'] ); ?></p>
					<form method="post">
						<?php wp_nonce_field( 'fse_opt_admin_nonce', 'fse_opt_nonce' ); ?>
						<input type="hidden" name="fse_opt_form_action" value="convert_fonts">
						<button type="submit" class="button button-primary button-large">⚡ Convert Toàn Bộ Font sang .woff2</button>
					</form>
				<?php else : ?>
					<div class="notice notice-warning inline" style="margin:10px 0;padding:8px 12px;">
						<strong>ℹ️ Lưu ý Share Hosting:</strong> <?php echo esc_html( $font_cap['reason'] ); ?><br>
						Bộ tối ưu Runtime (<code>font-display: swap</code>, chống trùng <code>@font-face</code>, preload <code>.woff2</code>) vẫn hoạt động bình thường bằng PHP thuần. Bạn có thể upload sẵn file <code>.woff2</code> trực tiếp bên dưới để thay thế:
					</div>
					<form method="post" enctype="multipart/form-data">
						<?php wp_nonce_field( 'fse_opt_admin_nonce', 'fse_opt_nonce' ); ?>
						<input type="hidden" name="fse_opt_form_action" value="upload_woff2_fonts">
						<input type="file" name="fse_woff2_files[]" accept=".woff2" multiple style="margin-bottom:8px;"><br>
						<button type="submit" class="button button-primary">⬆️ Tải Lên .woff2 &amp; Đồng Bộ FSE</button>
					</form>
				<?php endif; ?>
			</div>
		</div>

		<div style="background:#fff;border:1px solid #ccd0d4;padding:20px;border-radius:4px;max-width:1100px;margin-top:20px;">
			<h3 style="margin-top:0;">Nhật ký xử lý ảnh chi tiết (Trước → Sau)</h3>
			<table class="widefat striped" id="fse-bulk-log-table">
				<thead>
					<tr>
						<th>ID</th>
						<th>File WebP</th>
						<th>Kích thước (Trước → Sau)</th>
						<th>Dung lượng (Trước → Sau)</th>
						<th>Hệ màu ICC</th>
						<th>Engine</th>
					</tr>
				</thead>
				<tbody>
					<tr id="fse-bulk-empty-row"><td colspan="6">Nhấn <strong>Bắt Đầu Tối Ưu Toàn Bộ Ảnh</strong> để xem kết quả từng ảnh trực tiếp.</td></tr>
				</tbody>
			</table>
		</div>

		<script>
		(function(){
			var btn = document.getElementById('fse-start-bulk-media');
			if(!btn) return;
			function unlockAll(msg, statusEl){
				if(statusEl && msg){ statusEl.textContent = msg; }
				window.onbeforeunload = null;
				if(window.fseOptSetUILock){ window.fseOptSetUILock(false); }
				btn.disabled = false;
			}
			btn.addEventListener('click', function(){
				if(window.fseOptSetUILock){
					window.fseOptSetUILock(true, btn, '⏳ Đang tối ưu ảnh...');
				} else {
					btn.disabled = true;
				}
				window.onbeforeunload = function(){ return 'Quá trình tối ưu ảnh đang chạy, bạn có chắc muốn rời trang?'; };

				var wrap = document.getElementById('fse-bulk-progress-wrap');
				var bar = document.getElementById('fse-bulk-bar');
				var status = document.getElementById('fse-bulk-status');
				var tbody = document.querySelector('#fse-bulk-log-table tbody');
				var emptyRow = document.getElementById('fse-bulk-empty-row');
				if(emptyRow) emptyRow.remove();
				wrap.style.display = 'block';
				bar.style.width = '0%';
				bar.textContent = '0%';
				status.textContent = 'Đang lấy danh sách ảnh cần tối ưu...';

				var fd = new FormData();
				fd.append('action', 'fse_opt_get_unoptimized_media');
				fd.append('nonce', '<?php echo esc_js( $nonce ); ?>');
				if(document.getElementById('fse-bulk-force-all').checked){
					fd.append('force_all', '1');
				}

				fetch(ajaxurl, {method:'POST', body:fd})
					.then(function(r){ return r.json(); })
					.then(function(res){
						if(!res.success){
							unlockAll('❌ ' + (res.data || 'Không thể khởi chạy do có tác vụ khác đang xử lý.'), status);
							return;
						}
						if(!res.data.ids.length){
							bar.style.width = '100%';
							bar.textContent = '100%';
							unlockAll('✅ Tất cả ảnh đã được tối ưu ở mức tối đa!', status);
							return;
						}
						var ids = res.data.ids;
						var total = ids.length;
						var done = 0;
						var batchSize = 3;

						function nextBatch(){
							if(done >= total){
								unlockAll('✅ Hoàn tất tối ưu ' + total + ' ảnh và cập nhật Database!', status);
								return;
							}
							var slice = ids.slice(done, done + batchSize);
							status.textContent = 'Đang xử lý ' + (done + 1) + '–' + Math.min(done + slice.length, total) + ' / ' + total + ' ảnh... (Không đóng tab này)';
							var bfd = new FormData();
							bfd.append('action', 'fse_opt_process_media_batch');
							bfd.append('nonce', '<?php echo esc_js( $nonce ); ?>');
							if(done + slice.length >= total){ bfd.append('is_last', '1'); }
							slice.forEach(function(id){ bfd.append('ids[]', id); });

							fetch(ajaxurl, {method:'POST', body:bfd})
								.then(function(r){ return r.json(); })
								.then(function(bRes){
									if(!bRes.success){
										unlockAll('❌ Lỗi: ' + (bRes.data || 'Xử lý bị gián đoạn.'), status);
										return;
									}
									done += slice.length;
									var pct = Math.round((done / total) * 100);
									bar.style.width = pct + '%';
									bar.textContent = pct + '%';
									if(bRes.data.items){
										bRes.data.items.forEach(function(it){
											var tr = document.createElement('tr');
											if(it.error){
												tr.innerHTML = '<td>#' + it.id + '</td><td colspan="5" style="color:#b32d2e;">' + it.error + '</td>';
											} else {
												tr.innerHTML = '<td>#' + it.id + '</td><td><code>' + it.file + '</code></td><td>' + it.dims + '</td><td><span style="color:#b32d2e;text-decoration:line-through;">' + it.before + '</span> → <strong style="color:#007017;">' + it.after + '</strong></td><td><span class="fse-badge-save">' + it.icc + '</span></td><td><code>' + it.engine + '</code></td>';
											}
											tbody.insertBefore(tr, tbody.firstChild);
										});
									}
									nextBatch();
								})
								.catch(function(){
									done += slice.length;
									nextBatch();
								});
						}
						nextBatch();
					})
					.catch(function(){
						unlockAll('❌ Lỗi kết nối máy chủ khi tải danh sách ảnh.', status);
					});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Render Tab 4: Database & Autoload Optimizer.
	 */
	private function render_database_tab( $db_report, $db_stats ) {
		?>
		<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:1100px;">
			<div style="background:#fff;border:1px solid #ccd0d4;padding:20px;border-radius:4px;">
				<h2 style="margin-top:0;">🗄️ Dọn Dẹp &amp; Tối Ưu Database (An Toàn 100%)</h2>
				<p>Xóa các bản nháp lịch sử (Revisions) gây phình bảng <code>wp_posts</code> của FSE/Greenshift, xóa Auto-Drafts, Trash, Transients cũ, Action Scheduler logs đã hoàn tất và chạy <code>OPTIMIZE TABLE</code> để thu hồi dung lượng đĩa.</p>
				<table class="widefat striped" style="margin-bottom:15px;">
					<tbody>
						<tr><td><strong>Tổng dung lượng Database hiện tại</strong></td><td><strong><?php echo esc_html( size_format( $db_report['total_bytes'], 2 ) ); ?></strong> (Trước khi tối ưu: <?php echo esc_html( size_format( ! empty( $db_stats['before_bytes'] ) ? $db_stats['before_bytes'] : $db_report['total_bytes'], 2 ) ); ?>)</td></tr>
						<tr><td>Dung lượng bảng <code>wp_posts</code></td><td><?php echo esc_html( size_format( $db_report['posts_table_bytes'], 2 ) ); ?></td></tr>
						<tr><td>Bản nháp lịch sử (Revisions)</td><td><strong><?php echo (int) $db_report['revisions_count']; ?></strong> dòng (<?php echo esc_html( size_format( $db_report['revisions_bytes'], 2 ) ); ?>)</td></tr>
						<tr><td>Auto-Drafts &amp; bài trong Thùng rác (Trash)</td><td><?php echo (int) $db_report['autodraft_count']; ?> auto-drafts / <?php echo (int) $db_report['trash_count']; ?> trash</td></tr>
						<tr><td>Orphaned Postmeta (Meta mồ côi)</td><td><?php echo (int) $db_report['orphan_postmeta']; ?> dòng</td></tr>
						<tr><td>Transients trong <code>wp_options</code></td><td><?php echo (int) $db_report['transients_count']; ?> dòng</td></tr>
						<tr><td>WooCommerce Action Scheduler (Done / Logs)</td><td><?php echo (int) $db_report['as_done_count']; ?> actions / <?php echo (int) $db_report['as_logs_count']; ?> logs</td></tr>
					</tbody>
				</table>
				<form method="post">
					<?php wp_nonce_field( 'fse_opt_admin_nonce', 'fse_opt_nonce' ); ?>
					<input type="hidden" name="fse_opt_form_action" value="run_db_cleanup">
					<button type="submit" class="button button-primary button-large">🧹 Dọn Dẹp &amp; OPTIMIZE TABLE Ngay</button>
				</form>
			</div>

			<div style="background:#fff;border:1px solid #ccd0d4;padding:20px;border-radius:4px;">
				<h2 style="margin-top:0;">⚡ Kiểm Toán Autoload &amp; Object Cache</h2>
				<p>
					Tổng dung lượng <code>autoload</code> trong <code>wp_options</code>:
					<strong style="color:<?php echo $db_report['autoload_bytes'] > 800000 ? '#b32d2e' : '#007017'; ?>;">
						<?php echo esc_html( size_format( $db_report['autoload_bytes'], 2 ) ); ?>
					</strong>
					(Khuyến nghị &lt; 800 KB).<br>
					Trạng thái RAM Object Cache trên Server:
					Redis: <code><?php echo $db_report['has_redis'] ? 'Có' : 'Không'; ?></code> |
					APCu: <code><?php echo $db_report['has_apcu'] ? 'Có' : 'Không'; ?></code> |
					External Object Cache: <code><?php echo $db_report['has_object_cache'] ? 'Đang bật' : 'Chưa bật (Đang dùng Static HTML Page Cache)'; ?></code>
				</p>
				<h4>Top 10 Option Autoload lớn nhất</h4>
				<table class="widefat striped">
					<thead>
						<tr><th>Option Name</th><th>Kích thước</th><th>Autoload</th></tr>
					</thead>
					<tbody>
						<?php foreach ( $db_report['top_autoload'] as $opt ) : ?>
							<tr>
								<td><code><?php echo esc_html( $opt['option_name'] ); ?></code></td>
								<td><?php echo esc_html( size_format( (int) $opt['bytes'], 2 ) ); ?></td>
								<td><code><?php echo esc_html( $opt['autoload'] ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Tab 5: Integrated FSE Domain Migration Tool.
	 */
	private function render_migration_tab() {
		$migrator         = $this->plugin->migrator;
		$detected_domains = $migrator->detect_foreign_domains();
		$default_new_url  = untrailingslashit( home_url() );

		$old_url              = isset( $_POST['fse_old_url'] ) ? sanitize_text_field( wp_unslash( $_POST['fse_old_url'] ) ) : '';
		$new_url              = isset( $_POST['fse_new_url'] ) ? sanitize_text_field( wp_unslash( $_POST['fse_new_url'] ) ) : $default_new_url;
		$dry_run              = isset( $_POST['fse_mig_submit'] ) ? ! empty( $_POST['fse_dry_run'] ) : true;
		$include_both_schemes = isset( $_POST['fse_mig_submit'] ) ? ! empty( $_POST['fse_both_schemes'] ) : true;
		$include_guid         = isset( $_POST['fse_mig_submit'] ) ? ! empty( $_POST['fse_include_guid'] ) : false;
		$cleanup_caches       = isset( $_POST['fse_mig_submit'] ) ? ! empty( $_POST['fse_cleanup_caches'] ) : true;

		$report = null;

		if ( isset( $_POST['fse_mig_submit'] ) ) {
			check_admin_referer( 'fse_migration_action', 'fse_migration_nonce' );
			$lock = $this->acquire_task_lock( 'sync_migration', 'Chuyển đổi Domain (FSE Migration)', 180 );
			if ( true !== $lock ) {
				$report = array( 'error' => $lock );
			} else {
				try {
					$report = $migrator->run(
						$old_url,
						$new_url,
						array(
							'dry_run'              => $dry_run,
							'include_both_schemes' => $include_both_schemes,
							'include_guid'         => $include_guid,
							'cleanup_caches'       => $cleanup_caches,
						)
					);

					if ( ! $dry_run && empty( $report['error'] ) ) {
						$detected_domains = $migrator->detect_foreign_domains();
					}
				} finally {
					$this->release_task_lock( 'sync_migration' );
				}
			}
		}
		?>
		<div style="max-width: 900px;">
			<?php if ( ! empty( $detected_domains ) ) : ?>
				<div class="notice notice-info inline" style="padding: 10px 15px; margin-bottom: 15px;">
					<strong>Phát hiện root domain WordPress khác với site hiện tại (<code><?php echo esc_html( $default_new_url ); ?></code>):</strong>
					<ul style="margin: 6px 0 0 18px; list-style: disc;">
						<?php foreach ( $detected_domains as $domain => $hits ) : ?>
							<li>
								<code><?php echo esc_html( $domain ); ?></code> (<?php echo (int) $hits; ?> vị trí mẫu)
								<button type="button" class="button button-small fse-fill-old" data-url="<?php echo esc_attr( $domain ); ?>" style="margin-left:6px;">Chọn làm URL cũ</button>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php else : ?>
				<div class="notice notice-success inline" style="padding: 10px 15px; margin-bottom: 15px;">
					Không phát hiện root domain lạ nào trong FSE Global Styles / Greenshift / nội dung mẫu.
				</div>
			<?php endif; ?>

			<form method="post" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
				<?php wp_nonce_field( 'fse_migration_action', 'fse_migration_nonce' ); ?>
				<h2 style="margin-top:0;">🔄 Chuyển Đổi Domain An Toàn Cho Theme FSE (Gutenberg / Greenshift / WooCommerce)</h2>
				<p>Xử lý đồng thời URL thường (<code>https://...</code>), URL bị escape trong JSON (<code>https:\/\/...</code>), URL-encoded và mảng PHP serialized mà không làm hỏng cấu trúc dữ liệu.</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="fse_old_url">URL cũ (cần thay thế)</label></th>
						<td>
							<input name="fse_old_url" id="fse_old_url" type="text" class="regular-text code" value="<?php echo esc_attr( $old_url ); ?>" placeholder="https://example.com" required>
							<p class="description">Không cần thêm dấu <code>/</code> ở cuối.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fse_new_url">URL mới (site hiện tại)</label></th>
						<td>
							<input name="fse_new_url" id="fse_new_url" type="text" class="regular-text code" value="<?php echo esc_attr( $new_url ); ?>" required>
						</td>
					</tr>
					<tr>
						<th scope="row">Tùy chọn xử lý</th>
						<td>
							<fieldset>
								<label><input type="checkbox" name="fse_both_schemes" value="1" <?php checked( $include_both_schemes ); ?>> Tự động thay cả <code>http://</code> và <code>https://</code> của URL cũ</label><br>
								<label><input type="checkbox" name="fse_cleanup_caches" value="1" <?php checked( $cleanup_caches ); ?>> Tự động xóa Transients, Page Cache &amp; Flush Rewrite Rules sau khi chạy thật</label><br>
								<label><input type="checkbox" name="fse_include_guid" value="1" <?php checked( $include_guid ); ?>> Bao gồm cột <code>wp_posts.guid</code> (thường nên tắt)</label><br>
								<label style="margin-top: 8px; display: inline-block;"><input type="checkbox" name="fse_dry_run" value="1" <?php checked( $dry_run ); ?>> <strong>Chạy thử (Dry Run)</strong> — chỉ quét và báo cáo, chưa ghi vào database</label>
							</fieldset>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" name="fse_mig_submit" value="1" class="button button-primary button-large">Thực hiện quét / Migration</button>
				</p>
			</form>

			<?php if ( is_array( $report ) ) : ?>
				<?php if ( ! empty( $report['error'] ) ) : ?>
					<div class="notice notice-error inline" style="margin-top:15px;"><p><?php echo esc_html( $report['error'] ); ?></p></div>
				<?php else : ?>
					<div style="margin-top: 20px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
						<h3 style="margin-top:0;">
							<?php echo $report['dry_run'] ? 'Kết quả Chạy thử (Dry Run)' : 'Kết quả Migration (Đã ghi Database)'; ?>
						</h3>
						<p>
							Tổng số dòng khớp: <strong><?php echo (int) $report['total_rows']; ?></strong> |
							Tổng số dòng đã cập nhật: <strong><?php echo (int) $report['total_updated']; ?></strong>
						</p>

						<?php if ( ! empty( $report['caches'] ) ) : ?>
							<div class="notice notice-success inline"><p><?php echo implode( '<br>', array_map( 'esc_html', $report['caches'] ) ); ?></p></div>
						<?php endif; ?>

						<table class="widefat striped" style="margin-top: 12px;">
							<thead>
								<tr>
									<th>Bảng</th>
									<th>Dòng khớp</th>
									<th>Đã cập nhật</th>
									<th>URL thường</th>
									<th>JSON Escaped (<code>\/</code>)</th>
									<th>PHP Serialized</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $report['tables'] as $table_name => $st ) : ?>
									<?php if ( 0 === (int) $st['matched_rows'] ) { continue; } ?>
									<tr>
										<td><code><?php echo esc_html( $table_name ); ?></code></td>
										<td><?php echo (int) $st['matched_rows']; ?></td>
										<td><?php echo (int) $st['updated_rows']; ?></td>
										<td><?php echo (int) $st['normal_hits']; ?></td>
										<td><?php echo (int) $st['escaped_hits']; ?></td>
										<td><?php echo (int) $st['serialized_hits']; ?></td>
									</tr>
								<?php endforeach; ?>
								<?php if ( 0 === (int) $report['total_rows'] ) : ?>
									<tr><td colspan="6">Không tìm thấy chuỗi nào khớp với URL cũ.</td></tr>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<script>
			document.querySelectorAll('.fse-fill-old').forEach(function (btn) {
				btn.addEventListener('click', function () {
					document.getElementById('fse_old_url').value = btn.getAttribute('data-url');
				});
			});
		</script>
		<?php
	}

	/**
	 * Render Tab 6: User Guide & Workflow Instructions.
	 */
	private function render_guide_tab() {
		?>
		<div style="max-width: 960px; display: grid; gap: 18px;">
			<div style="background:#fff;border:1px solid #ccd0d4;border-left:4px solid #2271b1;padding:20px;border-radius:4px;">
				<h2 style="margin-top:0;">🚀 1. Quy Trình 4 Bước Khi Cài Đặt Cho Website Mới (Hoặc Khi Chuyển Host/VPS)</h2>
				<ol style="line-height:1.75;font-size:14px;">
					<li>
						<strong>Bước 1 — Đổi tên miền (Nếu vừa kéo site về Local hoặc đưa lên Domain thật):</strong><br>
						Mở tab <a href="?page=fse-performance-optimizer&tab=migration"><strong>🔄 Chuyển Đổi Domain (FSE Migration)</strong></a> → Nhập <em>URL cũ</em> (hoặc bấm nút gợi ý tự động) và <em>URL mới</em> → Bấm chạy thử ở chế độ <strong>Dry Run</strong> để xem số dòng khớp → Bỏ tick <strong>Dry Run</strong> và bấm chạy thật để cập nhật sạch cả URL thường, chuỗi JSON escaped (<code>https:\/\/...</code> của FSE/Greenshift) và dữ liệu PHP serialized.
					</li>
					<li>
						<strong>Bước 2 — Lưu mốc Trước Tối Ưu (Baseline) &amp; Kiểm tra Cấu hình:</strong><br>
						Mở tab <a href="?page=fse-performance-optimizer&tab=settings"><strong>⚙️ Cấu Hình Runtime</strong></a> để tùy chỉnh ngưỡng cạnh dài tối đa (mặc định <code>1920px</code>), chất lượng ảnh (<code>Q=100</code>), giữ hệ màu <code>ICC Profile</code>, Smart Delay Mini-Cart, Page Cache và số bản Revision giữ lại (mặc định <code>5</code>).
					</li>
					<li>
						<strong>Bước 3 — Tối ưu hàng loạt Kho Ảnh &amp; Font có sẵn (Chỉ cần chạy 1 lần):</strong><br>
						Mở tab <a href="?page=fse-performance-optimizer&tab=bulk"><strong>🖼️ Bulk Media &amp; Fonts</strong></a>:
						<ul style="list-style:disc;margin:4px 0 4px 20px;">
							<li>Bấm <strong>▶️ Bắt Đầu Tối Ưu Toàn Bộ Ảnh</strong>: Hệ thống tự động xử lý từng lô 3 ảnh qua AJAX (không bao giờ bị timeout trên Share Hosting), chuyển sang <code>.webp Q=100</code> giữ nguyên <code>ICC Profile</code> và tự cập nhật link ảnh trong Database.</li>
							<li>Bấm <strong>⚡ Convert Toàn Bộ Font sang .woff2</strong>: Nén các font <code>.ttf</code>/<code>.otf</code> sang <code>.woff2</code> (giảm ~65% dung lượng, giữ nguyên 100% nét chữ).</li>
						</ul>
					</li>
					<li>
						<strong>Bước 4 — Dọn rác Database &amp; Nghiệm thu Bảng Trước/Sau:</strong><br>
						Mở tab <a href="?page=fse-performance-optimizer&tab=database"><strong>🗄️ Database &amp; Autoload</strong></a> → Bấm <strong>🧹 Dọn Dẹp &amp; OPTIMIZE TABLE Ngay</strong> để xóa sạch các bản nháp FSE Revisions gây phình DB → Quay về tab <a href="?page=fse-performance-optimizer&tab=dashboard"><strong>📊 Trước &amp; Sau Tối Ưu (Dashboard)</strong></a> và bấm <strong>🔄 Đo Lại Hiện Trạng (Sau Tối Ưu)</strong> để xem bảng đối chiếu chi tiết.
					</li>
				</ol>
			</div>

			<div style="background:#fff;border:1px solid #ccd0d4;border-left:4px solid #00a32a;padding:20px;border-radius:4px;">
				<h2 style="margin-top:0;">✨ 2. Vận Hành Tự Động Hàng Ngày Cho Chủ Shop Non-Tech (Không Cần Thao Tác Kỹ Thuật)</h2>
				<p style="font-size:14px;">Sau khi hoàn tất 4 bước trên, chủ website chỉ cần sử dụng WordPress như bình thường — toàn bộ quy trình tối ưu diễn ra tự động ngầm:</p>
				<ul style="line-height:1.75;font-size:14px;list-style:disc;margin-left:20px;">
					<li><strong>Khi chụp ảnh từ điện thoại/máy ảnh và Upload lên Media/Sản phẩm:</strong> Plugin tự động xoay ảnh đúng chiều EXIF, resize cạnh dài về tối đa <code>1920px</code>, xuất <strong>WebP <code>Q=100</code></strong> và nhúng nguyên vẹn <strong>ICC Color Profile</strong> (Display P3 / sRGB) bằng cả <code>Imagick</code> lẫn <code>PHP GD</code> thuần — màu sắc và độ chi tiết hoa giữ nguyên 100% như ảnh gốc mà dung lượng giảm 70–85%.</li>
					<li><strong>Khi chèn ảnh vào bài viết / trang FSE / Greenshift:</strong> Tự động bổ sung <code>width</code>/<code>height</code> chống nhảy trang (CLS), tự động gắn <code>fetchpriority="high"</code> cho ảnh đầu trang (LCP), bật <code>loading="lazy"</code> cho các ảnh bên dưới và tự chọn biến thể <code>768w</code> Retina kèm <code>srcset</code>.</li>
					<li><strong>Khi đổi hoặc thêm Font mới trong FSE Editor (Site Editor):</strong> Bộ lọc Runtime tự động chuyển mọi <code>@font-face</code> sang <code>font-display: swap</code>, ưu tiên file <code>.woff2</code>, loại bỏ khai báo trùng lặp và tự thêm <code>preconnect</code> nếu dùng Google Fonts.</li>
					<li><strong>Khi thêm/sửa sản phẩm hoặc giao diện:</strong> Page Cache tĩnh (<code>.html</code> / <code>.html.gz</code>) tự động được làm mới, và số bản nháp lịch sử (Revisions) tự động giữ ở mức tối đa <code>5</code> bản để Database luôn nhẹ (~5–6 MB).</li>
				</ul>
			</div>

			<div style="background:#fff;border:1px solid #ccd0d4;border-left:4px solid #8c5eff;padding:20px;border-radius:4px;">
				<h2 style="margin-top:0;">🛠️ 3. Mẹo Kiểm Tra Nhanh &amp; Xử Lý Sự Cố (Dành Cho Quản Trị Viên)</h2>
				<ul style="line-height:1.75;font-size:14px;list-style:disc;margin-left:20px;">
					<li><strong>Xóa nhanh Page Cache:</strong> Bấm nút <strong>⚡ Xóa Cache FSE</strong> ngay trên thanh Admin Bar trên cùng ở bất kỳ trang nào.</li>
					<li><strong>Xem trang gốc chưa qua tối ưu Runtime (Bypass để so sánh/debug):</strong> Thêm tham số <code>?fse_raw=1</code> vào cuối đường dẫn bất kỳ (ví dụ: <code><?php echo esc_html( home_url( '/?fse_raw=1' ) ); ?></code>) để tạm thời bỏ qua bộ lọc HTML/JS/CSS của plugin trên lần tải đó.</li>
					<li><strong>Kiểm tra trạng thái Cache:</strong> Mở DevTools (F12 → tab Network → chọn dòng Document đầu tiên) và xem Response Header <code>X-FSE-Cache</code>:
						<code>HIT</code> (trả thẳng từ file tĩnh ~10ms, 0 SQL query), <code>MISS</code> (vừa tạo file cache mới), hoặc <code>BYPASS</code> (trang Giỏ hàng, Thanh toán, Tài khoản hoặc khi đang đăng nhập Admin).</li>
					<li><strong>Mang plugin sang website khác (Share Hosting hoặc VPS):</strong> Chỉ cần copy thư mục <code>wp-content/plugins/fse-performance-optimizer</code> (hoặc nén thành file <code>.zip</code> để cài qua WP Admin) — không cần cài thêm bất kỳ plugin cache, tối ưu JS, tối ưu ảnh, dọn DB hay migration nào khác.</li>
				</ul>
			</div>
		</div>
		<?php
	}
}

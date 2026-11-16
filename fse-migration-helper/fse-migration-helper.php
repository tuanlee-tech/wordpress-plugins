<?php
/**
 * Plugin Name: FSE Migration Helper
 * Description: Công cụ chuyển đổi domain cho theme FSE (Greenshift, Gutenberg Global Styles, WooCommerce) — xử lý cả URL thường, URL bị escape trong JSON (https:\/\/...) và dữ liệu PHP serialized, kèm dọn cache tự động.
 * Version: 1.0.0
 * Author: TuanLee
 * Text Domain: fse-migration-helper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-fse-migrator.php';

class FSE_Migration_Helper_Plugin {

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
	}

	/**
	 * Add submenu under Tools.
	 */
	public function register_admin_menu() {
		add_management_page(
			'FSE Migration Helper',
			'FSE Migration',
			'manage_options',
			'fse-migration-helper',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render admin UI and handle form submission.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Bạn không có quyền truy cập trang này.' );
		}

		$migrator         = new FSE_Migration_Helper_Migrator();
		$detected_domains = $migrator->detect_foreign_domains();
		$default_new_url  = untrailingslashit( home_url() );
		$default_old_url  = '';

		$old_url              = isset( $_POST['fse_old_url'] ) ? sanitize_text_field( wp_unslash( $_POST['fse_old_url'] ) ) : $default_old_url;
		$new_url              = isset( $_POST['fse_new_url'] ) ? sanitize_text_field( wp_unslash( $_POST['fse_new_url'] ) ) : $default_new_url;
		$dry_run              = isset( $_POST['fse_submit'] ) ? ! empty( $_POST['fse_dry_run'] ) : true;
		$include_both_schemes = isset( $_POST['fse_submit'] ) ? ! empty( $_POST['fse_both_schemes'] ) : true;
		$include_guid         = isset( $_POST['fse_submit'] ) ? ! empty( $_POST['fse_include_guid'] ) : false;
		$cleanup_caches       = isset( $_POST['fse_submit'] ) ? ! empty( $_POST['fse_cleanup_caches'] ) : true;

		$report = null;

		if ( isset( $_POST['fse_submit'] ) ) {
			check_admin_referer( 'fse_migration_action', 'fse_migration_nonce' );

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
		}
		?>
		<div class="wrap">
			<h1>FSE Migration Helper</h1>
			<p>Thay thế an toàn URL khi chuyển site WordPress dùng <strong>FSE / Gutenberg / Greenshift / WooCommerce</strong>: tự động xử lý cả URL thường (<code>https://...</code>), URL bị escape trong JSON (<code>https:\/\/...</code>), URL-encoded và mảng PHP serialized.</p>

			<?php if ( ! empty( $detected_domains ) ) : ?>
				<div class="notice notice-info" style="padding: 10px 15px;">
					<strong>Phát hiện domain khác với site hiện tại (<code><?php echo esc_html( $default_new_url ); ?></code>):</strong>
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
				<div class="notice notice-success" style="padding: 10px 15px;">
					Không phát hiện domain lạ nào trong FSE Global Styles / Greenshift / nội dung mẫu.
				</div>
			<?php endif; ?>

			<form method="post" style="max-width: 780px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin-top: 15px;">
				<?php wp_nonce_field( 'fse_migration_action', 'fse_migration_nonce' ); ?>
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
								<label><input type="checkbox" name="fse_cleanup_caches" value="1" <?php checked( $cleanup_caches ); ?>> Tự động xóa Transients, WP Fastest Cache &amp; Flush Rewrite Rules sau khi chạy thật</label><br>
								<label><input type="checkbox" name="fse_include_guid" value="1" <?php checked( $include_guid ); ?>> Bao gồm cột <code>wp_posts.guid</code> (thường nên tắt)</label><br>
								<label style="margin-top: 8px; display: inline-block;"><input type="checkbox" name="fse_dry_run" value="1" <?php checked( $dry_run ); ?>> <strong>Chạy thử (Dry Run)</strong> — chỉ quét và báo cáo, chưa ghi vào database</label>
							</fieldset>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" name="fse_submit" value="1" class="button button-primary button-large">Thực hiện quét / Migration</button>
				</p>
			</form>

			<?php if ( is_array( $report ) ) : ?>
				<?php if ( ! empty( $report['error'] ) ) : ?>
					<div class="notice notice-error"><p><?php echo esc_html( $report['error'] ); ?></p></div>
				<?php else : ?>
					<div style="max-width: 900px; margin-top: 20px; background: #fff; padding: 20px; border: 1px solid #ccd0d4;">
						<h2>
							<?php echo $report['dry_run'] ? 'Kết quả Chạy thử (Dry Run)' : 'Kết quả Migration (Đã ghi Database)'; ?>
						</h2>
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
}

( new FSE_Migration_Helper_Plugin() )->init();

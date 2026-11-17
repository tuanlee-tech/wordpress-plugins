<?php
/**
 * Admin Dashboard & Settings UI for WC Instant Order Notifier.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCION_Admin {

	/**
	 * @var WC_Instant_Order_Notifier
	 */
	private $plugin;

	/**
	 * @param WC_Instant_Order_Notifier $plugin Main plugin instance.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register admin menus, form handlers, and AJAX endpoints.
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ), 60 );
		add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
		add_action( 'wp_ajax_wcion_detect_chat_id', array( $this, 'ajax_detect_chat_id' ) );
	}

	/**
	 * Register top-level admin sidebar menu right below WooCommerce.
	 */
	public function register_admin_menu() {
		add_menu_page(
			'WC Instant Order Notifier (Telegram & SMTP)',
			'Order Notifier',
			'manage_options',
			'wc-instant-order-notifier',
			array( $this, 'render_admin_page' ),
			'dashicons-bell',
			56
		);
	}

	/**
	 * AJAX handler to auto-detect Telegram Chat ID from Bot Token.
	 */
	public function ajax_detect_chat_id() {
		check_ajax_referer( 'wcion_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Không có quyền thực hiện.' ) );
		}

		$token = isset( $_POST['bot_token'] ) ? sanitize_text_field( wp_unslash( $_POST['bot_token'] ) ) : '';
		$res   = $this->plugin->telegram->detect_chat_id( $token );

		if ( ! empty( $res['success'] ) && ! empty( $res['chat_id'] ) ) {
			$settings                       = $this->plugin->get_settings();
			$settings['telegram_bot_token'] = $token;
			$settings['telegram_chat_id']   = $res['chat_id'];
			update_option( WC_Instant_Order_Notifier::OPTION_KEY, $settings );
			$this->plugin->reload_settings();
			wp_send_json_success( $res );
		} else {
			wp_send_json_error( $res );
		}
	}

	/**
	 * Handle settings save, test Telegram, test SMTP, resend log, and clear logs.
	 */
	public function handle_admin_actions() {
		if ( ! isset( $_POST['wcion_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'wcion_admin_action', 'wcion_nonce' );

		$action   = sanitize_key( wp_unslash( $_POST['wcion_action'] ) );
		$settings = $this->plugin->get_settings();
		$tab      = isset( $_POST['wcion_tab'] ) ? sanitize_key( wp_unslash( $_POST['wcion_tab'] ) ) : 'telegram';

		if ( 'save_settings' === $action ) {
			if ( 'telegram' === $tab ) {
				$settings['enable_telegram']    = ! empty( $_POST['enable_telegram'] );
				$settings['telegram_bot_token'] = sanitize_text_field( wp_unslash( $_POST['telegram_bot_token'] ?? '' ) );
				$settings['telegram_chat_id']   = sanitize_text_field( wp_unslash( $_POST['telegram_chat_id'] ?? '' ) );
				$settings['site_tag']           = preg_replace( '/[^a-zA-Z0-9_]/', '', sanitize_text_field( wp_unslash( $_POST['site_tag'] ?? '' ) ) );
				$settings['include_zalo_link']  = ! empty( $_POST['include_zalo_link'] );
				$settings['include_admin_link'] = ! empty( $_POST['include_admin_link'] );

				foreach ( WCION_Telegram::get_categories() as $cat ) {
					$key              = $cat['setting'];
					$settings[ $key ] = ! empty( $_POST[ $key ] );
				}
			} elseif ( 'smtp' === $tab ) {
				$settings['enable_async_dispatch'] = ! empty( $_POST['enable_async_dispatch'] );
				$settings['defer_wc_emails']       = ! empty( $_POST['defer_wc_emails'] );
				$settings['enable_smtp']           = ! empty( $_POST['enable_smtp'] );
				$settings['smtp_host']             = sanitize_text_field( wp_unslash( $_POST['smtp_host'] ?? 'smtp.gmail.com' ) );
				$settings['smtp_port']             = max( 1, (int) ( $_POST['smtp_port'] ?? 587 ) );
				$settings['smtp_encryption']       = sanitize_key( wp_unslash( $_POST['smtp_encryption'] ?? 'tls' ) );
				$settings['smtp_username']         = sanitize_text_field( wp_unslash( $_POST['smtp_username'] ?? '' ) );
				$new_pass                          = isset( $_POST['smtp_password'] ) ? trim( (string) wp_unslash( $_POST['smtp_password'] ) ) : '';
				if ( '' !== $new_pass && '********' !== $new_pass ) {
					// Strip spaces from Google 16-char App Passwords automatically.
					if ( false !== stripos( $settings['smtp_host'], 'gmail.com' ) ) {
						$new_pass = str_replace( ' ', '', $new_pass );
					}
					$settings['smtp_password'] = $new_pass;
				}
				$settings['smtp_from_name']       = sanitize_text_field( wp_unslash( $_POST['smtp_from_name'] ?? '' ) );
				$settings['smtp_from_email']      = sanitize_email( wp_unslash( $_POST['smtp_from_email'] ?? '' ) );
				$settings['admin_notify_email']   = sanitize_email( wp_unslash( $_POST['admin_notify_email'] ?? '' ) );
				$settings['sync_wc_email_sender'] = ! empty( $_POST['sync_wc_email_sender'] );

				// Sync WooCommerce sender options in DB if requested so WC->Settings->Emails reflects it immediately.
				if ( $settings['sync_wc_email_sender'] ) {
					if ( '' !== $settings['smtp_from_name'] ) {
						update_option( 'woocommerce_email_from_name', $settings['smtp_from_name'] );
					}
					if ( '' !== $settings['smtp_from_email'] && is_email( $settings['smtp_from_email'] ) ) {
						update_option( 'woocommerce_email_from_address', $settings['smtp_from_email'] );
					}
				}
			}

			update_option( WC_Instant_Order_Notifier::OPTION_KEY, $settings );
			$this->plugin->reload_settings();

			$this->set_flash_notice( 'success', '✅ Đã lưu cấu hình thành công!' );
			wp_safe_redirect( admin_url( 'admin.php?page=wc-instant-order-notifier&tab=' . $tab ) );
			exit;
		}

		if ( 'test_telegram' === $action ) {
			$token = sanitize_text_field( wp_unslash( $_POST['test_bot_token'] ?? $settings['telegram_bot_token'] ) );
			$chat  = sanitize_text_field( wp_unslash( $_POST['test_chat_id'] ?? $settings['telegram_chat_id'] ) );

			// Also save Token & Chat ID if user typed them and clicked Test right away.
			if ( '' !== $token && '' !== $chat ) {
				$settings['telegram_bot_token'] = $token;
				$settings['telegram_chat_id']   = $chat;
				update_option( WC_Instant_Order_Notifier::OPTION_KEY, $settings );
				$this->plugin->reload_settings();
			}

			$sample = $this->plugin->telegram->build_sample_test_message();
			$res    = $this->plugin->telegram->send_telegram_message( $sample, '#TestBot', 'Tin nhắn kiểm tra kết nối Telegram', $token, $chat );

			if ( ! empty( $res['success'] ) ) {
				$this->set_flash_notice( 'success', '🎉 Đã gửi tin nhắn mẫu về Telegram thành công! Hãy kiểm tra ứng dụng Telegram trên điện thoại của bạn.' );
			} else {
				$this->set_flash_notice( 'error', '❌ Gửi Telegram thất bại: ' . $res['message'] );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=wc-instant-order-notifier&tab=telegram' ) );
			exit;
		}

		if ( 'test_smtp' === $action ) {
			$to = sanitize_email( wp_unslash( $_POST['test_email_to'] ?? $settings['admin_notify_email'] ) );
			if ( ! is_email( $to ) ) {
				$this->set_flash_notice( 'error', '❌ Vui lòng nhập địa chỉ email nhận thử hợp lệ.' );
			} else {
				$site_name = wp_specialchars_decode( get_option( 'blogname', 'Shop' ), ENT_QUOTES );
				$subject   = '[' . $site_name . '] Email kiểm tra kết nối SMTP (' . current_time( 'H:i:s d/m/Y' ) . ')';
				$body      = "Xin chào,\n\nĐây là email kiểm tra từ plugin WC Instant Order Notifier trên website " . home_url() . ".\nNếu bạn nhận được email này, cấu hình Gmail / Custom SMTP đã hoạt động chính xác 100%!";
				$sent      = wp_mail( $to, $subject, $body );

				if ( $sent ) {
					$this->set_flash_notice( 'success', '🎉 Đã gửi email thử nghiệm tới ' . $to . ' thành công!' );
				} else {
					$this->set_flash_notice( 'error', '❌ Gửi email thất bại. Vui lòng xem chi tiết lỗi trong tab Nhật ký (Logs).' );
				}
			}
			wp_safe_redirect( admin_url( 'admin.php?page=wc-instant-order-notifier&tab=smtp' ) );
			exit;
		}

		if ( 'resend_log' === $action ) {
			$log_id = sanitize_text_field( wp_unslash( $_POST['log_id'] ?? '' ) );
			$entry  = $this->plugin->logger->get_log_by_id( $log_id );
			if ( $entry ) {
				if ( 'telegram' === $entry['channel'] && ! empty( $entry['payload']['text'] ) ) {
					$res = $this->plugin->telegram->send_telegram_message(
						$entry['payload']['text'],
						$entry['category'],
						'[Gửi lại] ' . $entry['title']
					);
					if ( ! empty( $res['success'] ) ) {
						$this->set_flash_notice( 'success', '✅ Đã gửi lại thông báo Telegram thành công!' );
					} else {
						$this->set_flash_notice( 'error', '❌ Gửi lại thất bại: ' . $res['message'] );
					}
				} elseif ( 'smtp' === $entry['channel'] && ! empty( $entry['payload']['to'] ) ) {
					$p    = $entry['payload'];
					$sent = wp_mail( $p['to'], $p['subject'] ?? '', $p['message'] ?? '', $p['headers'] ?? array() );
					if ( $sent ) {
						$this->set_flash_notice( 'success', '✅ Đã gửi lại email thành công!' );
					} else {
						$this->set_flash_notice( 'error', '❌ Gửi lại email thất bại.' );
					}
				}
			}
			wp_safe_redirect( admin_url( 'admin.php?page=wc-instant-order-notifier&tab=logs' ) );
			exit;
		}

		if ( 'clear_logs' === $action ) {
			$this->plugin->logger->clear_logs();
			$this->set_flash_notice( 'success', '🗑️ Đã xóa toàn bộ nhật ký gửi.' );
			wp_safe_redirect( admin_url( 'admin.php?page=wc-instant-order-notifier&tab=logs' ) );
			exit;
		}
	}

	/**
	 * Store a flash notice in transient.
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message Notice text.
	 */
	private function set_flash_notice( $type, $message ) {
		set_transient(
			'wcion_flash_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
	}

	/**
	 * Render the plugin admin page.
	 */
	public function render_admin_page() {
		$settings = $this->plugin->get_settings();
		$tab      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'telegram';
		$flash    = get_transient( 'wcion_flash_' . get_current_user_id() );
		if ( $flash ) {
			delete_transient( 'wcion_flash_' . get_current_user_id() );
		}
		?>
		<div class="wrap wcion-wrap">
			<style>
				.wcion-wrap { max-width: 1080px; margin-top: 20px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
				.wcion-header { background: linear-gradient(135deg, #0088cc 0%, #005f8f 100%); color: #fff; padding: 22px 28px; border-radius: 10px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; box-shadow: 0 4px 14px rgba(0, 136, 204, 0.18); }
				.wcion-header h1 { color: #fff; margin: 0 0 6px; font-size: 22px; font-weight: 700; }
				.wcion-header p { margin: 0; opacity: 0.92; font-size: 13.5px; }
				.wcion-badges { display: flex; gap: 10px; flex-wrap: wrap; }
				.wcion-badge { background: rgba(255,255,255,0.18); padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
				.wcion-tabs { display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 2px solid #dcdcdc; padding-bottom: 0; }
				.wcion-tab { text-decoration: none; padding: 11px 18px; font-weight: 600; color: #50575e; border-radius: 8px 8px 0 0; background: #f0f0f1; border: 1px solid transparent; margin-bottom: -2px; font-size: 14px; }
				.wcion-tab:hover { color: #0088cc; background: #fff; }
				.wcion-tab.active { background: #fff; color: #0088cc; border-color: #dcdcdc; border-bottom-color: #fff; }
				.wcion-card { background: #fff; border: 1px solid #e2e4e7; border-radius: 10px; padding: 24px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
				.wcion-card h2 { margin-top: 0; font-size: 17px; border-bottom: 1px solid #f0f0f1; padding-bottom: 12px; margin-bottom: 16px; }
				.wcion-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(310px, 1fr)); gap: 12px; margin-top: 12px; }
				.wcion-cat-box { border: 1px solid #dcdcde; border-radius: 8px; padding: 12px 14px; display: flex; align-items: flex-start; gap: 10px; background: #fafafa; transition: all .15s ease; }
				.wcion-cat-box:hover { border-color: #0088cc; background: #f4faff; }
				.wcion-cat-box input[type="checkbox"] { margin-top: 3px; }
				.wcion-cat-tag { display: inline-block; background: #e5f5fa; color: #006ba1; font-family: monospace; font-size: 12px; padding: 2px 7px; border-radius: 4px; font-weight: 600; margin-top: 4px; }
				.wcion-table { width: 100%; border-collapse: collapse; font-size: 13px; }
				.wcion-table th, .wcion-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid #eee; vertical-align: middle; }
				.wcion-table th { background: #f8f9fa; font-weight: 600; }
				.wcion-status-ok { background: #def7ec; color: #03543f; padding: 3px 8px; border-radius: 12px; font-weight: 600; font-size: 11.5px; }
				.wcion-status-err { background: #fde8e8; color: #9b1c1c; padding: 3px 8px; border-radius: 12px; font-weight: 600; font-size: 11.5px; }
				.wcion-step { background: #f8fafc; border-left: 4px solid #0088cc; padding: 14px 18px; margin-bottom: 14px; border-radius: 0 8px 8px 0; }
				.wcion-step h3 { margin: 0 0 6px; font-size: 15px; color: #0a4b78; }
				.wcion-step ol, .wcion-step ul { margin: 6px 0 0 18px; }
				.wcion-step li { margin-bottom: 6px; line-height: 1.5; }
			</style>

			<div class="wcion-header">
				<div>
					<h1>🔔 WC Instant Order Notifier (Telegram &amp; SMTP)</h1>
					<p>Đẩy thông báo WooCommerce Email có phân loại (#Hashtag) về Telegram Bot + Gmail SMTP &amp; Tăng tốc Đặt hàng</p>
				</div>
				<div class="wcion-badges">
					<span class="wcion-badge">🤖 Telegram: <?php echo ( ! empty( $settings['enable_telegram'] ) && $this->plugin->telegram->is_configured() ) ? 'Đang bật' : 'Chưa điền Token'; ?></span>
					<span class="wcion-badge">📧 SMTP: <?php echo ! empty( $settings['enable_smtp'] ) ? 'Đang bật' : 'Đang tắt'; ?></span>
					<span class="wcion-badge">⚡ Async Checkout: <?php echo ! empty( $settings['enable_async_dispatch'] ) ? 'Bật (<0.3s)' : 'Tắt'; ?></span>
				</div>
			</div>

			<?php if ( ! empty( $flash['message'] ) ) : ?>
				<div class="notice notice-<?php echo 'success' === $flash['type'] ? 'success' : 'error'; ?> is-dismissible" style="margin: 0 0 18px; padding: 10px 14px;">
					<p style="margin:0; font-weight:600;"><?php echo esc_html( $flash['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<nav class="wcion-tabs">
				<a class="wcion-tab <?php echo 'telegram' === $tab ? 'active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-instant-order-notifier&tab=telegram' ) ); ?>">🤖 Telegram Bot &amp; Phân loại</a>
				<a class="wcion-tab <?php echo 'smtp' === $tab ? 'active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-instant-order-notifier&tab=smtp' ) ); ?>">📧 Gmail SMTP &amp; Tăng tốc Đặt hàng</a>
				<a class="wcion-tab <?php echo 'logs' === $tab ? 'active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-instant-order-notifier&tab=logs' ) ); ?>">📋 Nhật ký gửi (Logs)</a>
				<a class="wcion-tab <?php echo 'guide' === $tab ? 'active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-instant-order-notifier&tab=guide' ) ); ?>">📖 Hướng dẫn lấy Token &amp; Gmail (1 phút)</a>
			</nav>

			<?php
			if ( 'smtp' === $tab ) {
				$this->render_smtp_tab( $settings );
			} elseif ( 'logs' === $tab ) {
				$this->render_logs_tab();
			} elseif ( 'guide' === $tab ) {
				$this->render_guide_tab();
			} else {
				$this->render_telegram_tab( $settings );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render Tab 1: Telegram Bot & Categorized Notifications.
	 *
	 * @param array $settings Current settings.
	 */
	private function render_telegram_tab( $settings ) {
		$categories = WCION_Telegram::get_categories();
		?>
		<form method="post" action="">
			<?php wp_nonce_field( 'wcion_admin_action', 'wcion_nonce' ); ?>
			<input type="hidden" name="wcion_action" value="save_settings">
			<input type="hidden" name="wcion_tab" value="telegram">

			<div class="wcion-card">
				<h2>1. Kết nối Telegram Bot (Web ➡️ Telegram 1 chiều an toàn)</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Kích hoạt đẩy thông báo Telegram</th>
						<td>
							<label>
								<input type="checkbox" name="enable_telegram" value="1" <?php checked( ! empty( $settings['enable_telegram'] ) ); ?>>
								<strong>Bật gửi thông báo đơn hàng &amp; sự kiện WooCommerce về Telegram</strong>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcion_bot_token">Telegram Bot Token</label></th>
						<td>
							<input type="text" id="wcion_bot_token" name="telegram_bot_token" value="<?php echo esc_attr( $settings['telegram_bot_token'] ?? '' ); ?>" class="regular-text" style="width: 440px; font-family: monospace;" placeholder="1234567890:AAHxyz...">
							<p class="description">Lấy từ <code>@BotFather</code> trên Telegram (xem tab <strong>📖 Hướng dẫn</strong> nếu bạn chưa có).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcion_chat_id">Telegram Chat ID nhận tin</label></th>
						<td>
							<div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
								<input type="text" id="wcion_chat_id" name="telegram_chat_id" value="<?php echo esc_attr( $settings['telegram_chat_id'] ?? '' ); ?>" class="regular-text" style="width: 240px; font-family: monospace;" placeholder="Ví dụ: 987654321 hoặc -100...">
								<button type="button" class="button button-secondary" id="wcion-btn-detect-chat">🔍 Tự động lấy Chat ID</button>
								<span id="wcion-detect-status" style="font-weight:600; font-size:13px;"></span>
							</div>
							<p class="description">Chỉ cần mở Bot trên điện thoại bấm <strong>Start</strong> (hoặc nhắn "hi"), sau đó bấm nút <strong>🔍 Tự động lấy Chat ID</strong> ở trên là hệ thống tự điền!</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcion_site_tag">Hashtag định danh Website</label></th>
						<td>
							<code>#</code><input type="text" id="wcion_site_tag" name="site_tag" value="<?php echo esc_attr( $settings['site_tag'] ?? '' ); ?>" class="regular-text" style="width: 200px;" placeholder="AyAFlorist">
							<p class="description">Hữu ích khi bạn dùng chung 1 Bot cho nhiều website khác nhau — mỗi tin nhắn sẽ đính kèm hashtag tên web.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Tiện ích kèm theo tin nhắn</th>
						<td>
							<label style="display:block; margin-bottom:6px;">
								<input type="checkbox" name="include_zalo_link" value="1" <?php checked( ! empty( $settings['include_zalo_link'] ) ); ?>>
								Kèm link <strong>👉 Chat Zalo (`https://zalo.me/SĐT`)</strong> và link bấm gọi điện ngay cạnh SĐT khách
							</label>
							<label style="display:block;">
								<input type="checkbox" name="include_admin_link" value="1" <?php checked( ! empty( $settings['include_admin_link'] ) ); ?>>
								Kèm link mở nhanh trang chi tiết đơn hàng trong WP Admin
							</label>
						</td>
					</tr>
				</table>
			</div>

			<div class="wcion-card">
				<h2>2. Phân loại thông báo WooCommerce Email muốn đẩy về Telegram</h2>
				<p style="margin-top:0; color:#50575e;">Mỗi loại thông báo sẽ gắn sẵn <strong>Nhãn tiêu đề + #Hashtag riêng</strong>. Trên ứng dụng Telegram điện thoại, bạn chỉ cần chạm vào hashtag (ví dụ <code>#DonHangMoi</code> hoặc <code>#DonDaHuy</code>) là lọc được toàn bộ đơn thuộc loại đó.</p>

				<div class="wcion-grid">
					<?php foreach ( $categories as $cat ) : ?>
						<label class="wcion-cat-box">
							<input type="checkbox" name="<?php echo esc_attr( $cat['setting'] ); ?>" value="1" <?php checked( ! empty( $settings[ $cat['setting'] ] ) ); ?>>
							<div>
								<div style="font-weight:700; color:#1d2327;"><?php echo esc_html( $cat['badge'] ); ?></div>
								<div style="font-size:12px; color:#646970; margin-top:2px;"><?php echo esc_html( $cat['wc_email'] ); ?></div>
								<span class="wcion-cat-tag"><?php echo esc_html( $cat['hashtag'] ); ?></span>
							</div>
						</label>
					<?php endforeach; ?>
				</div>

				<p style="margin-top: 20px;">
					<button type="submit" class="button button-primary button-hero">💾 Lưu cấu hình Telegram</button>
				</p>
			</div>
		</form>

		<div class="wcion-card">
			<h2>3. Kiểm tra gửi tin nhắn mẫu (Test Telegram Bot)</h2>
			<form method="post" action="" onsubmit="document.getElementById('wcion_test_token').value = document.getElementById('wcion_bot_token').value; document.getElementById('wcion_test_chat').value = document.getElementById('wcion_chat_id').value;">
				<?php wp_nonce_field( 'wcion_admin_action', 'wcion_nonce' ); ?>
				<input type="hidden" name="wcion_action" value="test_telegram">
				<input type="hidden" id="wcion_test_token" name="test_bot_token" value="">
				<input type="hidden" id="wcion_test_chat" name="test_chat_id" value="">
				<p style="margin-top:0;">Bấm nút dưới đây để gửi ngay 1 bản tin mẫu <strong>#DonHangMoi</strong> về Telegram của bạn:</p>
				<button type="submit" class="button button-secondary">🚀 Gửi tin nhắn Đơn hàng Mẫu về Telegram ngay</button>
			</form>
		</div>

		<script>
		(function(){
			var btn = document.getElementById('wcion-btn-detect-chat');
			var status = document.getElementById('wcion-detect-status');
			if(!btn) return;
			btn.addEventListener('click', function(){
				var token = document.getElementById('wcion_bot_token').value.trim();
				if(!token){
					status.style.color = '#d63638';
					status.textContent = '⚠️ Vui lòng dán Bot Token ở ô bên trên trước!';
					return;
				}
				btn.disabled = true;
				status.style.color = '#0088cc';
				status.textContent = '⏳ Đang dò tìm Chat ID từ Telegram...';

				var fd = new FormData();
				fd.append('action', 'wcion_detect_chat_id');
				fd.append('nonce', '<?php echo esc_js( wp_create_nonce( 'wcion_ajax_nonce' ) ); ?>');
				fd.append('bot_token', token);

				fetch(ajaxurl, { method: 'POST', body: fd })
					.then(function(r){ return r.json(); })
					.then(function(res){
						btn.disabled = false;
						if(res && res.success && res.data && res.data.chat_id){
							document.getElementById('wcion_chat_id').value = res.data.chat_id;
							status.style.color = '#008a20';
							status.textContent = '✅ ' + res.data.message;
						} else {
							status.style.color = '#d63638';
							status.textContent = '❌ ' + ((res && res.data && res.data.message) ? res.data.message : 'Không tìm thấy Chat ID.');
						}
					})
					.catch(function(){
						btn.disabled = false;
						status.style.color = '#d63638';
						status.textContent = '❌ Lỗi kết nối khi gọi AJAX.';
					});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Render Tab 2: Gmail / Custom SMTP & Async Checkout Acceleration.
	 *
	 * @param array $settings Current settings.
	 */
	private function render_smtp_tab( $settings ) {
		$has_pass = ! empty( $settings['smtp_password'] );
		?>
		<form method="post" action="">
			<?php wp_nonce_field( 'wcion_admin_action', 'wcion_nonce' ); ?>
			<input type="hidden" name="wcion_action" value="save_settings">
			<input type="hidden" name="wcion_tab" value="smtp">

			<div class="wcion-card">
				<h2>1. Tăng tốc nút "Đặt hàng" (Async Background Checkout)</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Đẩy thông báo Telegram chạy ngầm</th>
						<td>
							<label>
								<input type="checkbox" name="enable_async_dispatch" value="1" <?php checked( ! empty( $settings['enable_async_dispatch'] ) ); ?>>
								<strong>Trả kết quả trang "Đặt hàng thành công" tức thì cho khách (&lt;0.3s), gửi Telegram ở nền (`fastcgi_finish_request`)</strong>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">Gửi Email WooCommerce chạy ngầm</th>
						<td>
							<label>
								<input type="checkbox" name="defer_wc_emails" value="1" <?php checked( ! empty( $settings['defer_wc_emails'] ) ); ?>>
								<strong>Bật `woocommerce_defer_transactional_emails` để khách không phải chờ kết nối SMTP lúc bấm Đặt hàng</strong>
							</label>
						</td>
					</tr>
				</table>
			</div>

			<div class="wcion-card">
				<h2>2. Cấu hình Gmail / Custom SMTP &amp; Đồng bộ Email Chủ Shop</h2>
				<p style="margin-top:0;">
					Điền nhanh cấu hình chuẩn:
					<button type="button" class="button button-secondary" onclick="document.getElementById('wcion_smtp_host').value='smtp.gmail.com';document.getElementById('wcion_smtp_port').value='587';document.getElementById('wcion_smtp_enc').value='tls';">⚡ Điền tự động thông số Gmail (TLS / 587)</button>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Kích hoạt gửi Mail qua SMTP</th>
						<td>
							<label>
								<input type="checkbox" name="enable_smtp" value="1" <?php checked( ! empty( $settings['enable_smtp'] ) ); ?>>
								<strong>Bật gửi toàn bộ email WordPress &amp; WooCommerce qua SMTP (không cần sửa DNS Mắt Bão)</strong>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcion_admin_notify_email">Email chủ shop nhận thông báo đơn</label></th>
						<td>
							<input type="email" id="wcion_admin_notify_email" name="admin_notify_email" value="<?php echo esc_attr( $settings['admin_notify_email'] ?? '' ); ?>" class="regular-text" placeholder="yourshop@gmail.com">
							<p class="description">Tự động thay thế các địa chỉ email cũ (như <code>admin@renaiflowers.com</code>) trong thông báo đơn hàng của WooCommerce.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcion_smtp_from_name">Tên người gửi (From Name)</label></th>
						<td>
							<input type="text" id="wcion_smtp_from_name" name="smtp_from_name" value="<?php echo esc_attr( $settings['smtp_from_name'] ?? '' ); ?>" class="regular-text" placeholder="AyA Florist">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcion_smtp_from_email">Email người gửi (From Email)</label></th>
						<td>
							<input type="email" id="wcion_smtp_from_email" name="smtp_from_email" value="<?php echo esc_attr( $settings['smtp_from_email'] ?? '' ); ?>" class="regular-text" placeholder="yourshop@gmail.com">
							<br>
							<label style="display:inline-block; margin-top:6px;">
								<input type="checkbox" name="sync_wc_email_sender" value="1" <?php checked( ! empty( $settings['sync_wc_email_sender'] ) ); ?>>
								Đồng bộ luôn Tên &amp; Email người gửi này vào <code>WooCommerce → Cài đặt → Email</code>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcion_smtp_host">SMTP Host &amp; Port</label></th>
						<td>
							<input type="text" id="wcion_smtp_host" name="smtp_host" value="<?php echo esc_attr( $settings['smtp_host'] ?? 'smtp.gmail.com' ); ?>" class="regular-text" style="width: 240px;">
							Port: <input type="number" id="wcion_smtp_port" name="smtp_port" value="<?php echo esc_attr( $settings['smtp_port'] ?? 587 ); ?>" style="width: 90px;">
							Mã hóa:
							<select id="wcion_smtp_enc" name="smtp_encryption">
								<option value="tls" <?php selected( ( $settings['smtp_encryption'] ?? 'tls' ), 'tls' ); ?>>TLS (Khuyên dùng - Port 587)</option>
								<option value="ssl" <?php selected( ( $settings['smtp_encryption'] ?? 'tls' ), 'ssl' ); ?>>SSL (Port 465)</option>
								<option value="none" <?php selected( ( $settings['smtp_encryption'] ?? 'tls' ), 'none' ); ?>>Không mã hóa</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcion_smtp_user">Tài khoản Gmail (SMTP Username)</label></th>
						<td>
							<input type="text" id="wcion_smtp_user" name="smtp_username" value="<?php echo esc_attr( $settings['smtp_username'] ?? '' ); ?>" class="regular-text" placeholder="yourshop@gmail.com">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcion_smtp_pass">Mật khẩu ứng dụng (App Password)</label></th>
						<td>
							<input type="password" id="wcion_smtp_pass" name="smtp_password" value="<?php echo $has_pass ? '********' : ''; ?>" class="regular-text" placeholder="16 ký tự App Password của Google">
							<p class="description">Nhập mã 16 ký tự lấy tại <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">Google App Passwords</a> (hệ thống tự động bỏ khoảng trắng).<?php echo $has_pass ? ' <strong>(Đã lưu mật khẩu — để trống nếu không muốn đổi)</strong>' : ''; ?></p>
						</td>
					</tr>
				</table>

				<p style="margin-top: 16px;">
					<button type="submit" class="button button-primary button-hero">💾 Lưu cấu hình SMTP &amp; Async</button>
				</p>
			</div>
		</form>

		<div class="wcion-card">
			<h2>3. Gửi Email Kiểm tra (Test SMTP)</h2>
			<form method="post" action="">
				<?php wp_nonce_field( 'wcion_admin_action', 'wcion_nonce' ); ?>
				<input type="hidden" name="wcion_action" value="test_smtp">
				<p style="margin-top:0;">
					Gửi thử 1 email tới:
					<input type="email" name="test_email_to" value="<?php echo esc_attr( $settings['admin_notify_email'] ?: get_option( 'admin_email' ) ); ?>" class="regular-text" style="margin: 0 8px;">
					<button type="submit" class="button button-secondary">📨 Gửi Email Test ngay</button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render Tab 3: Notification & Email Logs with 1-click Resend + Collapsible Debug Matrix.
	 */
	private function render_logs_tab() {
		$logs = $this->plugin->logger->get_logs();
		?>
		<div class="wcion-card" style="padding: 16px 22px;">
			<details>
				<summary style="cursor:pointer; font-weight:700; font-size:14.5px; color:#0a4b78; user-select:none;">
					🧭 Ma trận kích hoạt Email WooCommerce vs Telegram Bot (Bấm để mở/thu gọn khi cần debug)
				</summary>
				<div style="margin-top:14px;">
					<p style="margin:0 0 10px; color:#50575e; font-size:13px;">
						<strong>Lưu ý quan trọng:</strong> Telegram Bot lắng nghe <em>mọi</em> lần chuyển sang trạng thái đích, còn <strong>Email mặc định của WooCommerce</strong> chỉ bắn khi chuyển từ đúng trạng thái nguồn hợp lệ dưới đây:
					</p>
					<table class="wcion-table" style="font-size:12.5px;">
						<thead>
							<tr>
								<th>Loại thông báo</th>
								<th>#Hashtag</th>
								<th>Người nhận Email</th>
								<th>Điều kiện kích hoạt Email WooCommerce (Từ ➡️ Sang)</th>
								<th>Telegram Bot</th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td><strong>Đơn hàng mới</strong></td>
								<td><span class="wcion-cat-tag">#DonHangMoi</span></td>
								<td>Chủ shop</td>
								<td><code>pending</code> / <code>failed</code> / <code>cancelled</code> ➡️ <code>processing</code> / <code>on-hold</code> / <code>completed</code></td>
								<td>Gửi khi tạo đơn mới</td>
							</tr>
							<tr>
								<td><strong>Đang xử lý</strong></td>
								<td><span class="wcion-cat-tag">#DangXuLy</span></td>
								<td>Khách hàng</td>
								<td><code>pending</code> / <code>on-hold</code> / <code>failed</code> / <code>cancelled</code> ➡️ <code>processing</code></td>
								<td>Gửi mọi lần ➡️ <code>processing</code> (trừ lúc mới tạo đơn nếu đã báo #DonHangMoi)</td>
							</tr>
							<tr>
								<td><strong>Đơn tạm giữ</strong></td>
								<td><span class="wcion-cat-tag">#ChoThanhToan</span></td>
								<td>Khách hàng</td>
								<td><code>pending</code> / <code>failed</code> / <code>cancelled</code> ➡️ <code>on-hold</code><br><span style="color:#9b1c1c;">(Không gửi nếu đổi từ <code>processing</code> / <code>completed</code> / <code>refunded</code>)</span></td>
								<td>Gửi mọi lần ➡️ <code>on-hold</code></td>
							</tr>
							<tr>
								<td><strong>Đã hoàn thành</strong></td>
								<td><span class="wcion-cat-tag">#HoanThanh</span></td>
								<td>Khách hàng</td>
								<td>Mọi trạng thái ➡️ <code>completed</code></td>
								<td>Gửi mọi lần ➡️ <code>completed</code></td>
							</tr>
							<tr>
								<td><strong>Đã hoàn tiền</strong></td>
								<td><span class="wcion-cat-tag">#HoanTien</span></td>
								<td>Khách hàng</td>
								<td>Mọi trạng thái ➡️ <code>refunded</code> (hoặc hoàn tiền một phần)</td>
								<td>Gửi mọi lần ➡️ <code>refunded</code></td>
							</tr>
							<tr>
								<td><strong>Hủy đơn hàng</strong></td>
								<td><span class="wcion-cat-tag">#DonDaHuy</span></td>
								<td>Chủ shop &amp; Khách</td>
								<td>Chủ: <code>processing</code> / <code>on-hold</code> / <code>pending</code> ➡️ <code>cancelled</code><br>Khách: <code>processing</code> / <code>on-hold</code> ➡️ <code>cancelled</code><br><span style="color:#9b1c1c;">(Không gửi nếu đổi từ <code>completed</code> / <code>refunded</code> / <code>failed</code>)</span></td>
								<td>Gửi mọi lần ➡️ <code>cancelled</code></td>
							</tr>
							<tr>
								<td><strong>Đơn thất bại</strong></td>
								<td><span class="wcion-cat-tag">#DonThatBai</span></td>
								<td>Chủ shop &amp; Khách</td>
								<td>Chủ: <code>pending</code> / <code>on-hold</code> ➡️ <code>failed</code><br>Khách: Mọi trạng thái ➡️ <code>failed</code></td>
								<td>Gửi mọi lần ➡️ <code>failed</code></td>
							</tr>
							<tr>
								<td><strong>Ghi chú đơn</strong></td>
								<td><span class="wcion-cat-tag">#GhiChuDonHang</span></td>
								<td>Khách hàng</td>
								<td>Thêm ghi chú đơn và chọn <em>"Ghi chú cho khách hàng"</em></td>
								<td>Gửi ngay khi thêm ghi chú khách</td>
							</tr>
						</tbody>
					</table>
					<p style="margin:10px 0 0; color:#646970; font-size:12px;">
						💡 <em>Mẹo debug:</em> Khi bật <strong>Gửi Email WooCommerce chạy ngầm</strong>, WooCommerce xếp email vào Action Scheduler (có thể trễ 5–60 giây so với Telegram tùy WP-Cron). Nếu test ở LocalWP, mở <strong>Công cụ → Tác vụ đã lên lịch (Scheduled Actions)</strong> để kiểm tra hàng đợi <code>woocommerce_send_queued_transactional_email</code>.
					</p>
				</div>
			</details>
		</div>

		<div class="wcion-card">
			<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
				<h2 style="margin:0; border:none; padding:0;">📋 Nhật ký gửi Thông báo Telegram &amp; Email (<?php echo count( $logs ); ?> bản ghi gần nhất)</h2>
				<?php if ( ! empty( $logs ) ) : ?>
					<form method="post" action="">
						<?php wp_nonce_field( 'wcion_admin_action', 'wcion_nonce' ); ?>
						<input type="hidden" name="wcion_action" value="clear_logs">
						<button type="submit" class="button button-link-delete" onclick="return confirm('Xóa toàn bộ nhật ký gửi?');">🗑️ Xóa toàn bộ nhật ký</button>
					</form>
				<?php endif; ?>
			</div>

			<?php if ( empty( $logs ) ) : ?>
				<p style="color:#646970;">Chưa có bản ghi nào. Khi có đơn hàng mới hoặc khi bạn bấm nút Gửi Test, lịch sử gửi sẽ hiển thị tại đây.</p>
			<?php else : ?>
				<table class="wcion-table">
					<thead>
						<tr>
							<th style="width:140px;">Thời gian</th>
							<th style="width:95px;">Kênh</th>
							<th style="width:130px;">Phân loại</th>
							<th>Nội dung tóm tắt</th>
							<th style="width:110px;">Trạng thái</th>
							<th style="width:220px;">Phản hồi API / SMTP</th>
							<th style="width:90px;">Thao tác</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td><?php echo esc_html( $log['time'] ?? '' ); ?></td>
								<td><strong><?php echo 'telegram' === ( $log['channel'] ?? '' ) ? '🤖 Telegram' : '📧 SMTP'; ?></strong></td>
								<td><span class="wcion-cat-tag"><?php echo esc_html( $log['category'] ?? '' ); ?></span></td>
								<td><?php echo esc_html( $log['title'] ?? '' ); ?></td>
								<td>
									<?php if ( ! empty( $log['success'] ) ) : ?>
										<span class="wcion-status-ok">Thành công</span>
									<?php else : ?>
										<span class="wcion-status-err">Thất bại</span>
									<?php endif; ?>
								</td>
								<td style="font-family:monospace; font-size:12px; color:#50575e;"><?php echo esc_html( $log['response'] ?? '' ); ?></td>
								<td>
									<?php if ( ! empty( $log['payload'] ) ) : ?>
										<form method="post" action="" style="margin:0;">
											<?php wp_nonce_field( 'wcion_admin_action', 'wcion_nonce' ); ?>
											<input type="hidden" name="wcion_action" value="resend_log">
											<input type="hidden" name="log_id" value="<?php echo esc_attr( $log['id'] ); ?>">
											<button type="submit" class="button button-small">🔄 Gửi lại</button>
										</form>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render Tab 4: Step-by-step 1-minute setup guide.
	 */
	private function render_guide_tab() {
		?>
		<div class="wcion-card">
			<h2>📖 Hướng dẫn cài đặt miễn phí 100% trong 1 phút (Không cần giấy phép ĐKKD, không cần sửa DNS)</h2>

			<div class="wcion-step">
				<h3>Bước 1: Tạo Bot Telegram nhận đơn hàng trên điện thoại (30 giây)</h3>
				<ol>
					<li>Mở ứng dụng <strong>Telegram</strong> trên điện thoại hoặc máy tính, tìm tài khoản chính chủ <strong><a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a></strong> (có tích xanh).</li>
					<li>Bấm <strong>Start</strong> và gõ lệnh <code>/newbot</code></li>
					<li>Nhập <strong>Tên hiển thị của Bot</strong> (ví dụ: <code>Thông Báo Đơn Hàng Shop</code>).</li>
					<li>Nhập <strong>Username của Bot</strong> có đuôi chữ <code>bot</code> (ví dụ: <code>ayaflorist_order_bot</code>).</li>
					<li><code>@BotFather</code> sẽ trả về đoạn mã <strong>HTTP API Token</strong> (dạng <code>7123456789:AAH...</code>) ➡️ Copy mã này dán vào ô <strong>Telegram Bot Token</strong> ở tab đầu tiên.</li>
					<li>Bấm vào link Bot vừa tạo mà <code>@BotFather</code> gửi, bấm nút <strong>Start</strong> (hoặc nhắn chữ <code>hi</code>) để kích hoạt cuộc trò chuyện.</li>
					<li>Quay lại trang cấu hình plugin này, bấm nút <strong>🔍 Tự động lấy Chat ID</strong> ➡️ Hệ thống sẽ tự điền Chat ID của bạn ➡️ Bấm <strong>Lưu cấu hình</strong>!</li>
				</ol>
			</div>

			<div class="wcion-step">
				<h3>Bước 2: Mẹo lọc thông báo đơn hàng theo phân loại trên Telegram</h3>
				<ul>
					<li>Mỗi thông báo gửi về Telegram đều có dòng phân loại như: <code>#DonHangMoi</code>, <code>#DonDaHuy</code>, <code>#DonThatBai</code>, <code>#HoanThanh</code>, <code>#SapHetHang</code>...</li>
					<li>Khi muốn xem lại riêng các đơn hàng mới hoặc các đơn bị hủy trên điện thoại, bạn chỉ cần <strong>chạm tay vào chữ <code>#DonHangMoi</code></strong> ngay trong tin nhắn Telegram là toàn bộ đơn cùng loại sẽ hiện ra.</li>
					<li>Nếu muốn nhiều nhân viên cùng nhận thông báo: chỉ cần thêm Bot vào 1 <strong>Nhóm (Group) Telegram</strong> của shop, nhắn 1 câu trong nhóm rồi bấm nút <strong>🔍 Tự động lấy Chat ID</strong> để lấy ID của nhóm (có dấu trừ <code>-100...</code> ở đầu).</li>
				</ul>
			</div>

			<div class="wcion-step">
				<h3>Bước 3: Tạo Mật khẩu ứng dụng Gmail (App Password) để gửi Email cho khách (30 giây)</h3>
				<ol>
					<li>Truy cập trang quản lý tài khoản Google: <strong><a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">https://myaccount.google.com/apppasswords</a></strong> (yêu cầu tài khoản Gmail đã bật Xác minh 2 bước).</li>
					<li>Nhập tên ứng dụng tùy ý (ví dụ: <code>WooCommerce Mail</code>) rồi bấm <strong>Tạo (Create)</strong>.</li>
					<li>Google sẽ hiện ra <strong>mã 16 chữ cái</strong> (ví dụ: <code>abcd efgh ijkl mnop</code>).</li>
					<li>Sang tab <strong>📧 Gmail SMTP</strong> của plugin này: tích chọn <strong>Bật gửi Mail qua SMTP</strong>, nhập địa chỉ Gmail của bạn và dán mã 16 ký tự đó vào ô <strong>Mật khẩu ứng dụng</strong> ➡️ Bấm <strong>Lưu cấu hình</strong>.</li>
				</ol>
			</div>
		</div>
		<?php
	}
}

<?php
/**
 * Categorized WooCommerce Email / Event -> Telegram Bot Push Engine.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCION_Telegram {

	/**
	 * @var array
	 */
	private $settings;

	/**
	 * @var WCION_Logger
	 */
	private $logger;

	/**
	 * @var WCION_Async
	 */
	private $async;

	/**
	 * Orders created during the current PHP request to prevent duplicate status-transition alerts on checkout.
	 *
	 * @var array<int, bool>
	 */
	private $just_created_orders = array();

	/**
	 * Flag to avoid intercepting wp_mail during WC transactional emails.
	 *
	 * @var bool
	 */
	private $in_wc_email = false;

	/**
	 * @param array        $settings Plugin settings.
	 * @param WCION_Logger $logger   Logger instance.
	 * @param WCION_Async  $async    Async dispatcher.
	 */
	public function __construct( $settings, $logger, $async ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->async    = $async;
	}

	/**
	 * Return all supported notification categories and their metadata.
	 *
	 * @return array<string, array{setting:string, hashtag:string, badge:string, label:string, wc_email:string}>
	 */
	public static function get_categories() {
		return array(
			'new_order'         => array(
				'setting'  => 'notify_new_order',
				'hashtag'  => '#DonHangMoi',
				'badge'    => '🛒 ĐƠN HÀNG MỚI',
				'label'    => 'Đơn hàng mới (New Order)',
				'wc_email' => 'WC_Email_New_Order — Khi khách vừa đặt đơn thành công',
			),
			'cancelled_order'   => array(
				'setting'  => 'notify_cancelled_order',
				'hashtag'  => '#DonDaHuy',
				'badge'    => '❌ ĐƠN HÀNG ĐÃ HỦY',
				'label'    => 'Đơn hàng đã hủy (Cancelled Order)',
				'wc_email' => 'WC_Email_Cancelled_Order — Khi đơn chuyển sang Đã hủy',
			),
			'failed_order'      => array(
				'setting'  => 'notify_failed_order',
				'hashtag'  => '#DonThatBai',
				'badge'    => '⚠️ ĐƠN HÀNG THẤT BẠI',
				'label'    => 'Đơn hàng thất bại (Failed Order)',
				'wc_email' => 'WC_Email_Failed_Order — Khi thanh toán thất bại',
			),
			'on_hold_order'     => array(
				'setting'  => 'notify_on_hold_order',
				'hashtag'  => '#ChoThanhToan',
				'badge'    => '⏳ ĐƠN TẠM GIỮ / CHỜ THANH TOÁN',
				'label'    => 'Đơn tạm giữ (Order On-Hold)',
				'wc_email' => 'WC_Email_Customer_On_Hold_Order — Khi đơn chuyển sang Tạm giữ',
			),
			'processing_order'  => array(
				'setting'  => 'notify_processing_order',
				'hashtag'  => '#DangXuLy',
				'badge'    => '🔄 ĐƠN ĐANG XỬ LÝ',
				'label'    => 'Đơn đang xử lý (Processing Order)',
				'wc_email' => 'WC_Email_Customer_Processing_Order — Khi đơn chuyển từ trạng thái khác sang Đang xử lý',
			),
			'completed_order'   => array(
				'setting'  => 'notify_completed_order',
				'hashtag'  => '#HoanThanh',
				'badge'    => '✅ ĐƠN ĐÃ HOÀN THÀNH',
				'label'    => 'Đơn đã hoàn thành (Completed Order)',
				'wc_email' => 'WC_Email_Customer_Completed_Order — Khi đơn giao thành công',
			),
			'refunded_order'    => array(
				'setting'  => 'notify_refunded_order',
				'hashtag'  => '#HoanTien',
				'badge'    => '💸 ĐƠN ĐÃ HOÀN TIỀN',
				'label'    => 'Đơn đã hoàn tiền (Refunded Order)',
				'wc_email' => 'WC_Email_Customer_Refunded_Order — Khi hoàn tiền đơn hàng',
			),
			'customer_note'     => array(
				'setting'  => 'notify_customer_note',
				'hashtag'  => '#GhiChuDonHang',
				'badge'    => '📝 GHI CHÚ ĐƠN HÀNG MỚI',
				'label'    => 'Ghi chú đơn hàng (Customer Note)',
				'wc_email' => 'WC_Email_Customer_Note — Khi có ghi chú mới gửi khách',
			),
			'low_stock'         => array(
				'setting'  => 'notify_low_stock',
				'hashtag'  => '#SapHetHang',
				'badge'    => '📦 CẢNH BÁO SẮP HẾT HÀNG',
				'label'    => 'Sắp hết hàng (Low Stock)',
				'wc_email' => 'Email cảnh báo tồn kho thấp của WooCommerce',
			),
			'no_stock'          => array(
				'setting'  => 'notify_no_stock',
				'hashtag'  => '#HetHang',
				'badge'    => '🚨 CẢNH BÁO HẾT HÀNG',
				'label'    => 'Hết hàng (No Stock)',
				'wc_email' => 'Email cảnh báo hết sạch tồn kho của WooCommerce',
			),
			'other_admin_email' => array(
				'setting'  => 'notify_other_admin_email',
				'hashtag'  => '#EmailHeThong',
				'badge'    => '📧 EMAIL QUẢN TRỊ HỆ THỐNG',
				'label'    => 'Email quản trị khác (Form liên hệ, hệ thống...)',
				'wc_email' => 'Các email wp_mail khác gửi về email chủ shop ngoài đơn hàng',
			),
		);
	}

	/**
	 * Register WooCommerce and WordPress hooks.
	 */
	public function register_hooks() {
		if ( empty( $this->settings['enable_telegram'] ) || ! $this->is_configured() ) {
			return;
		}

		// 1. New Order hooks (Classic Checkout, Block Store API Checkout, and Manual/Admin Orders).
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_new_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_new_order_object' ), 20, 1 );

		// 2. Order status changes (Cancelled, Failed, On-Hold, Processing, Completed, Refunded, or Draft->Active).
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 20, 4 );

		// 3. Customer note added.
		add_action( 'woocommerce_new_customer_note', array( $this, 'on_new_customer_note' ), 20, 1 );

		// 4. Stock alerts.
		add_action( 'woocommerce_low_stock', array( $this, 'on_low_stock' ), 20, 1 );
		add_action( 'woocommerce_no_stock', array( $this, 'on_no_stock' ), 20, 1 );

		// 5. Optional mirror for non-WooCommerce admin emails (contact forms, system alerts).
		if ( ! empty( $this->settings['notify_other_admin_email'] ) ) {
			add_action( 'woocommerce_email_before_transactional_emails', array( $this, 'mark_wc_email_start' ) );
			add_action( 'woocommerce_email_after_transactional_emails', array( $this, 'mark_wc_email_end' ) );
			add_filter( 'wp_mail', array( $this, 'maybe_mirror_admin_wp_mail' ), 20, 1 );
		}
	}

	/**
	 * Check if Bot Token and Chat ID are configured.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return ! empty( $this->settings['telegram_bot_token'] ) && ! empty( $this->settings['telegram_chat_id'] );
	}

	/**
	 * Handle new order from Block Checkout (passes WC_Order object).
	 *
	 * @param WC_Order|int $order Order object or ID.
	 */
	public function on_new_order_object( $order ) {
		$order_id = is_object( $order ) && method_exists( $order, 'get_id' ) ? $order->get_id() : (int) $order;
		if ( $order_id > 0 ) {
			$this->on_new_order( $order_id );
		}
	}

	/**
	 * Handle new order creation.
	 *
	 * @param int $order_id Order ID.
	 */
	public function on_new_order( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Ignore checkout-draft until it transitions to a real status.
		if ( in_array( $order->get_status(), array( 'checkout-draft', 'auto-draft', 'trash' ), true ) ) {
			return;
		}

		$this->just_created_orders[ $order->get_id() ] = true;

		if ( $order->get_meta( '_wcion_new_order_notified' ) ) {
			return;
		}

		$order->update_meta_data( '_wcion_new_order_notified', time() );
		$order->save_meta_data();

		if ( empty( $this->settings['notify_new_order'] ) ) {
			return;
		}

		$text  = $this->format_order_message( $order, 'new_order' );
		$title = sprintf( 'Đơn mới #%s - %s (%s)', $order->get_order_number(), $this->get_customer_display_name( $order ), wp_strip_all_tags( wc_price( $order->get_total() ) ) );

		$this->queue_telegram_message( $text, '#DonHangMoi', $title );
	}

	/**
	 * Handle order status transitions.
	 *
	 * @param int      $order_id   Order ID.
	 * @param string   $old_status Old status slug (without wc-).
	 * @param string   $new_status New status slug (without wc-).
	 * @param WC_Order $order      Order object.
	 */
	public function on_order_status_changed( $order_id, $old_status, $new_status, $order = null ) {
		if ( ! $order && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}

		// If transitioning out of checkout-draft or pending on initial checkout and not yet notified as a new order:
		if ( ! $order->get_meta( '_wcion_new_order_notified' ) && in_array( $new_status, array( 'processing', 'on-hold', 'completed' ), true ) ) {
			$this->on_new_order( $order_id );
			return;
		}

		// Avoid sending redundant Processing/On-Hold alert in the exact same request right after #DonHangMoi!
		if ( ! empty( $this->just_created_orders[ $order_id ] ) && in_array( $new_status, array( 'processing', 'on-hold' ), true ) ) {
			return;
		}

		$status_map = array(
			'cancelled'  => 'cancelled_order',
			'failed'     => 'failed_order',
			'on-hold'    => 'on_hold_order',
			'processing' => 'processing_order',
			'completed'  => 'completed_order',
			'refunded'   => 'refunded_order',
		);

		if ( ! isset( $status_map[ $new_status ] ) ) {
			return;
		}

		$cat_key = $status_map[ $new_status ];
		$cats    = self::get_categories();
		$setting = $cats[ $cat_key ]['setting'];

		if ( empty( $this->settings[ $setting ] ) ) {
			return;
		}

		$extra = sprintf( 'Trạng thái: <b>%s</b> ➡️ <b>%s</b>', esc_html( wc_get_order_status_name( $old_status ) ), esc_html( wc_get_order_status_name( $new_status ) ) );
		$text  = $this->format_order_message( $order, $cat_key, $extra );
		$title = sprintf( 'Đơn #%s (%s -> %s)', $order->get_order_number(), $old_status, $new_status );

		$this->queue_telegram_message( $text, $cats[ $cat_key ]['hashtag'], $title );
	}

	/**
	 * Handle new customer note on an order.
	 *
	 * @param array $args Array with 'order_id' and 'customer_note'.
	 */
	public function on_new_customer_note( $args ) {
		if ( empty( $this->settings['notify_customer_note'] ) || empty( $args['order_id'] ) || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( (int) $args['order_id'] );
		if ( ! $order ) {
			return;
		}

		$note  = trim( wp_strip_all_tags( (string) ( $args['customer_note'] ?? '' ) ) );
		$extra = '💬 <b>Nội dung ghi chú mới:</b> ' . esc_html( $note );
		$text  = $this->format_order_message( $order, 'customer_note', $extra );
		$title = sprintf( 'Ghi chú đơn #%s: %s', $order->get_order_number(), mb_substr( $note, 0, 40 ) );

		$this->queue_telegram_message( $text, '#GhiChuDonHang', $title );
	}

	/**
	 * Handle Low Stock alert.
	 *
	 * @param WC_Product $product Product object.
	 */
	public function on_low_stock( $product ) {
		if ( empty( $this->settings['notify_low_stock'] ) || ! is_object( $product ) ) {
			return;
		}
		$this->send_stock_alert( $product, 'low_stock' );
	}

	/**
	 * Handle No Stock alert.
	 *
	 * @param WC_Product $product Product object.
	 */
	public function on_no_stock( $product ) {
		if ( empty( $this->settings['notify_no_stock'] ) || ! is_object( $product ) ) {
			return;
		}
		$this->send_stock_alert( $product, 'no_stock' );
	}

	/**
	 * Format and send stock alert.
	 *
	 * @param WC_Product $product Product object.
	 * @param string     $cat_key 'low_stock' or 'no_stock'.
	 */
	private function send_stock_alert( $product, $cat_key ) {
		$cats     = self::get_categories();
		$cat      = $cats[ $cat_key ];
		$site_tag = $this->get_site_hashtag();
		$sku      = $product->get_sku() ? ' (SKU: ' . $product->get_sku() . ')' : '';
		$qty      = (int) $product->get_stock_quantity();

		$lines   = array();
		$lines[] = '<b>' . $cat['badge'] . '</b>';
		$lines[] = '🏷 Phân loại: ' . $cat['hashtag'] . ' ' . $site_tag;
		$lines[] = '━━━━━━━━━━━━━━━━━━';
		$lines[] = '💐 <b>Sản phẩm:</b> ' . esc_html( $product->get_name() ) . esc_html( $sku );
		$lines[] = '📊 <b>Số lượng còn lại:</b> <b>' . $qty . '</b>';
		$lines[] = '💰 <b>Giá bán:</b> ' . esc_html( wp_strip_all_tags( wc_price( $product->get_price() ) ) );
		if ( ! empty( $this->settings['include_admin_link'] ) ) {
			$edit_url = admin_url( 'post.php?post=' . $product->get_id() . '&action=edit' );
			$lines[]  = '🔗 <a href="' . esc_url( $edit_url ) . '">Cập nhật kho trong Admin</a>';
		}

		$title = sprintf( '%s: %s (Còn %d)', $cat['label'], $product->get_name(), $qty );
		$this->queue_telegram_message( implode( "\n", $lines ), $cat['hashtag'], $title );
	}

	/**
	 * Mark start of WC transactional email.
	 */
	public function mark_wc_email_start() {
		$this->in_wc_email = true;
	}

	/**
	 * Mark end of WC transactional email.
	 */
	public function mark_wc_email_end() {
		$this->in_wc_email = false;
	}

	/**
	 * Mirror non-WooCommerce admin emails to Telegram if enabled.
	 *
	 * @param array $args wp_mail arguments.
	 * @return array
	 */
	public function maybe_mirror_admin_wp_mail( $args ) {
		if ( $this->in_wc_email ) {
			return $args;
		}

		$to_raw      = is_array( $args['to'] ?? '' ) ? implode( ',', $args['to'] ) : (string) ( $args['to'] ?? '' );
		$admin_email = get_option( 'admin_email', '' );
		$custom_adm  = $this->settings['admin_notify_email'] ?? '';

		$is_for_admin = ( '' !== $admin_email && false !== stripos( $to_raw, $admin_email ) )
			|| ( '' !== $custom_adm && false !== stripos( $to_raw, $custom_adm ) );

		if ( ! $is_for_admin ) {
			return $args;
		}

		$subject = trim( (string) ( $args['subject'] ?? '' ) );
		// Skip standard WooCommerce order subjects that are already handled by our rich order hooks.
		if ( preg_match( '/(đơn hàng|order\s*#|new order|cancelled order|failed order)/iu', $subject ) ) {
			return $args;
		}

		$body_plain = trim( wp_strip_all_tags( (string) ( $args['message'] ?? '' ) ) );
		$body_short = mb_substr( preg_replace( '/\n{3,}/', "\n\n", $body_plain ), 0, 800 );

		$cats     = self::get_categories();
		$cat      = $cats['other_admin_email'];
		$site_tag = $this->get_site_hashtag();

		$lines   = array();
		$lines[] = '<b>' . $cat['badge'] . '</b>';
		$lines[] = '🏷 Phân loại: ' . $cat['hashtag'] . ' ' . $site_tag;
		$lines[] = '━━━━━━━━━━━━━━━━━━';
		$lines[] = '📌 <b>Tiêu đề:</b> ' . esc_html( $subject );
		$lines[] = '🕒 <b>Thời gian:</b> ' . esc_html( current_time( 'H:i:s d/m/Y' ) );
		if ( '' !== $body_short ) {
			$lines[] = '━━━━━━━━━━━━━━━━━━';
			$lines[] = esc_html( $body_short );
		}

		$this->queue_telegram_message( implode( "\n", $lines ), $cat['hashtag'], 'Email: ' . $subject );
		return $args;
	}

	/**
	 * Build a rich, categorized Telegram HTML message for a WooCommerce Order.
	 *
	 * @param WC_Order $order      Order object.
	 * @param string   $cat_key    Category key.
	 * @param string   $extra_line Optional extra status/note line.
	 * @return string Formatted HTML string for Telegram.
	 */
	public function format_order_message( $order, $cat_key = 'new_order', $extra_line = '' ) {
		$cats     = self::get_categories();
		$cat      = $cats[ $cat_key ] ?? $cats['new_order'];
		$site_tag = $this->get_site_hashtag();

		$order_number = $order->get_order_number();
		$status_name  = wc_get_order_status_name( $order->get_status() );
		$created_date = $order->get_date_created()
			? $order->get_date_created()->date_i18n( 'H:i - d/m/Y' )
			: current_time( 'H:i - d/m/Y' );

		$full_name = $this->get_customer_display_name( $order );
		$phone     = trim( (string) $order->get_billing_phone() );
		$email     = trim( (string) $order->get_billing_email() );

		// Build full address string.
		$address_parts = array_filter(
			array(
				$order->get_shipping_address_1() ?: $order->get_billing_address_1(),
				$order->get_shipping_address_2() ?: $order->get_billing_address_2(),
				$order->get_shipping_city() ?: $order->get_billing_city(),
				$order->get_shipping_state() ?: $order->get_billing_state(),
			)
		);
		$address_str = ! empty( $address_parts ) ? implode( ', ', $address_parts ) : 'Chưa cung cấp';

		$customer_note  = trim( (string) $order->get_customer_note() );
		$payment_method = $order->get_payment_method_title() ?: 'Không xác định';
		$total_str      = html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' );

		$lines   = array();
		$lines[] = '<b>' . $cat['badge'] . ' #' . esc_html( $order_number ) . '</b>';
		$lines[] = '🏷 Phân loại: ' . $cat['hashtag'] . ' ' . $site_tag;
		if ( '' !== $extra_line ) {
			$lines[] = $extra_line;
		}
		$lines[] = '━━━━━━━━━━━━━━━━━━';
		$lines[] = '👤 <b>Khách hàng:</b> ' . esc_html( $full_name );

		if ( '' !== $phone ) {
			$clean_phone = preg_replace( '/[^0-9+]/', '', $phone );
			$zalo_phone  = preg_replace( '/^\+?84/', '0', $clean_phone );
			$phone_line  = '📞 <b>SĐT:</b> <a href="tel:' . esc_attr( $clean_phone ) . '">' . esc_html( $phone ) . '</a>';
			if ( ! empty( $this->settings['include_zalo_link'] ) && '' !== $zalo_phone ) {
				$phone_line .= '  👉 <a href="https://zalo.me/' . esc_attr( $zalo_phone ) . '">Chat Zalo</a>';
			}
			$lines[] = $phone_line;
		} else {
			$lines[] = '📞 <b>SĐT:</b> Chưa cung cấp';
		}

		if ( '' !== $email ) {
			$lines[] = '✉️ <b>Email:</b> ' . esc_html( $email );
		}

		$lines[] = '📍 <b>Địa chỉ:</b> ' . esc_html( $address_str );

		if ( '' !== $customer_note ) {
			$lines[] = '📝 <b>Ghi chú / Lời chúc:</b> <i>' . esc_html( $customer_note ) . '</i>';
		}

		$lines[] = '━━━━━━━━━━━━━━━━━━';
		$lines[] = '💐 <b>CHI TIẾT SẢN PHẨM:</b>';

		$idx = 1;
		foreach ( $order->get_items() as $item ) {
			$item_name  = $item->get_name();
			$qty        = $item->get_quantity();
			$line_total = html_entity_decode( wp_strip_all_tags( wc_price( $item->get_total(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' );
			$product    = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
			$sku_str    = ( $product && $product->get_sku() ) ? ' [' . $product->get_sku() . ']' : '';

			$lines[] = sprintf(
				'%d. <b>%s</b>%s × <b>%s</b> = %s',
				$idx++,
				esc_html( $item_name ),
				esc_html( $sku_str ),
				esc_html( $qty ),
				esc_html( $line_total )
			);
		}

		$lines[] = '━━━━━━━━━━━━━━━━━━';
		if ( (float) $order->get_shipping_total() > 0 ) {
			$ship_str = html_entity_decode( wp_strip_all_tags( wc_price( $order->get_shipping_total(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' );
			$lines[]  = '🚚 <b>Phí giao hàng:</b> ' . esc_html( $ship_str );
		}
		if ( (float) $order->get_discount_total() > 0 ) {
			$disc_str = html_entity_decode( wp_strip_all_tags( wc_price( $order->get_discount_total(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' );
			$lines[]  = '🎟 <b>Giảm giá:</b> -' . esc_html( $disc_str );
		}

		$lines[] = '💰 <b>TỔNG THANH TOÁN: ' . esc_html( $total_str ) . '</b>';
		$lines[] = '💳 <b>Thanh toán:</b> ' . esc_html( $payment_method );
		$lines[] = '📌 <b>Trạng thái:</b> ' . esc_html( $status_name );
		$lines[] = '🕒 <b>Thời gian:</b> ' . esc_html( $created_date );

		if ( ! empty( $this->settings['include_admin_link'] ) ) {
			$edit_url = $order->get_edit_order_url() ?: admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );
			$lines[]  = '🔗 <a href="' . esc_url( $edit_url ) . '">Mở xem đơn #' . esc_html( $order_number ) . ' trên Web</a>';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Build a sample test message to verify Telegram Bot configuration.
	 *
	 * @return string
	 */
	public function build_sample_test_message() {
		$site_name = wp_specialchars_decode( get_option( 'blogname', 'Shop' ), ENT_QUOTES );
		$site_tag  = $this->get_site_hashtag();

		$lines   = array();
		$lines[] = '<b>🛒 ĐƠN HÀNG MỚI #TEST-999 (TIN NHẮN THỬ NGHIỆM)</b>';
		$lines[] = '🏷 Phân loại: #DonHangMoi #TestBot ' . $site_tag;
		$lines[] = '━━━━━━━━━━━━━━━━━━';
		$lines[] = '🌐 <b>Website:</b> ' . esc_html( $site_name ) . ' (' . esc_html( home_url() ) . ')';
		$lines[] = '👤 <b>Khách hàng:</b> Nguyễn Văn A (Khách Test)';
		$lines[] = '📞 <b>SĐT:</b> <a href="tel:0901234567">0901234567</a>  👉 <a href="https://zalo.me/0901234567">Chat Zalo</a>';
		$lines[] = '📍 <b>Địa chỉ:</b> 123 Nguyễn Huệ, Quận 1, TP.HCM';
		$lines[] = '📝 <b>Ghi chú / Lời chúc:</b> <i>Giao trước 10h sáng, ghi thiệp Chúc mừng sinh nhật</i>';
		$lines[] = '━━━━━━━━━━━━━━━━━━';
		$lines[] = '💐 <b>CHI TIẾT SẢN PHẨM:</b>';
		$lines[] = '1. <b>Sản phẩm mẫu A</b> [SKU-01] × <b>2</b> = 1.500.000 ₫';
		$lines[] = '━━━━━━━━━━━━━━━━━━';
		$lines[] = '💰 <b>TỔNG THANH TOÁN: 1.500.000 ₫</b>';
		$lines[] = '💳 <b>Thanh toán:</b> Thanh toán khi nhận hàng (COD)';
		$lines[] = '🕒 <b>Thời gian:</b> ' . esc_html( current_time( 'H:i:s d/m/Y' ) );
		$lines[] = '✅ <i>Kết nối WC Instant Order Notifier thành công!</i>';

		return implode( "\n", $lines );
	}

	/**
	 * Queue a Telegram message via async dispatcher.
	 *
	 * @param string $text     HTML message text.
	 * @param string $category Category hashtag.
	 * @param string $title    Summary title for logs.
	 */
	public function queue_telegram_message( $text, $category, $title ) {
		$this->async->dispatch(
			function () use ( $text, $category, $title ) {
				$this->send_telegram_message( $text, $category, $title );
			}
		);
	}

	/**
	 * Send a message immediately to the Telegram Bot API and log the result.
	 *
	 * @param string      $text       HTML formatted message.
	 * @param string      $category   Hashtag category (e.g. '#DonHangMoi').
	 * @param string      $title      Brief summary for logs.
	 * @param string|null $token_ovr  Optional token override.
	 * @param string|null $chat_ovr   Optional chat_id override.
	 * @return array{success:bool, message:string}
	 */
	public function send_telegram_message( $text, $category = '#ThongBao', $title = 'Thông báo Telegram', $token_ovr = null, $chat_ovr = null ) {
		$token   = trim( (string) ( null !== $token_ovr ? $token_ovr : ( $this->settings['telegram_bot_token'] ?? '' ) ) );
		$chat_id = trim( (string) ( null !== $chat_ovr ? $chat_ovr : ( $this->settings['telegram_chat_id'] ?? '' ) ) );

		if ( '' === $token || '' === $chat_id ) {
			$msg = 'Chưa cấu hình Bot Token hoặc Chat ID.';
			$this->logger->add_log( 'telegram', $category, $title, false, $msg, array( 'text' => $text ) );
			return array(
				'success' => false,
				'message' => $msg,
			);
		}

		$clean_token = preg_replace( '/[^0-9a-zA-Z:_-]/', '', $token );
		$url         = 'https://api.telegram.org/bot' . $clean_token . '/sendMessage';
		$body = array(
			'chat_id'                  => $chat_id,
			'text'                     => $text,
			'parse_mode'               => 'HTML',
			'disable_web_page_preview' => true,
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 10,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$err = $response->get_error_message();
			$this->logger->add_log( 'telegram', $category, $title, false, $err, array( 'text' => $text ) );
			return array(
				'success' => false,
				'message' => $err,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		if ( 200 === $code && ! empty( $json['ok'] ) ) {
			$msg_id = $json['result']['message_id'] ?? '';
			$info   = '200 OK (message_id: ' . $msg_id . ')';
			$this->logger->add_log( 'telegram', $category, $title, true, $info, array( 'text' => $text ) );
			return array(
				'success' => true,
				'message' => $info,
			);
		}

		$err_desc = isset( $json['description'] ) ? 'Telegram API (' . $code . '): ' . $json['description'] : 'HTTP ' . $code . ': ' . mb_substr( $raw, 0, 120 );
		$this->logger->add_log( 'telegram', $category, $title, false, $err_desc, array( 'text' => $text ) );

		return array(
			'success' => false,
			'message' => $err_desc,
		);
	}

	/**
	 * Auto-detect Chat ID from recent messages sent to the Bot (`getUpdates`).
	 *
	 * @param string $bot_token Telegram Bot Token.
	 * @return array{success:bool, chat_id:string, name:string, message:string}
	 */
	public function detect_chat_id( $bot_token ) {
		$token = trim( (string) $bot_token );
		if ( '' === $token ) {
			return array(
				'success' => false,
				'chat_id' => '',
				'name'    => '',
				'message' => 'Vui lòng nhập Bot Token trước.',
			);
		}

		$clean_token = preg_replace( '/[^0-9a-zA-Z:_-]/', '', $token );
		$url         = 'https://api.telegram.org/bot' . $clean_token . '/getUpdates?limit=10';
		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'chat_id' => '',
				'name'    => '',
				'message' => 'Lỗi kết nối Telegram API: ' . $response->get_error_message(),
			);
		}

		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $json['ok'] ) ) {
			return array(
				'success' => false,
				'chat_id' => '',
				'name'    => '',
				'message' => 'Token không hợp lệ hoặc bị từ chối: ' . ( $json['description'] ?? 'Unknown error' ),
			);
		}

		$results = $json['result'] ?? array();
		if ( empty( $results ) || ! is_array( $results ) ) {
			return array(
				'success' => false,
				'chat_id' => '',
				'name'    => '',
				'message' => 'Chưa tìm thấy tin nhắn nào! Hãy mở ứng dụng Telegram, bấm Start hoặc nhắn 1 chữ bất kỳ (vd: "hi") vào Bot của bạn rồi bấm lại nút này.',
			);
		}

		// Find most recent chat from updates.
		for ( $i = count( $results ) - 1; $i >= 0; $i-- ) {
			$upd  = $results[ $i ];
			$chat = $upd['message']['chat'] ?? ( $upd['my_chat_member']['chat'] ?? ( $upd['channel_post']['chat'] ?? null ) );
			if ( ! empty( $chat['id'] ) ) {
				$chat_id = (string) $chat['id'];
				$name    = trim( ( $chat['title'] ?? '' ) . ' ' . ( $chat['first_name'] ?? '' ) . ' ' . ( $chat['last_name'] ?? '' ) );
				if ( ! empty( $chat['username'] ) ) {
					$name .= ' (@' . $chat['username'] . ')';
				}
				return array(
					'success' => true,
					'chat_id' => $chat_id,
					'name'    => trim( $name ),
					'message' => 'Đã tìm thấy Chat ID: ' . $chat_id . ' — ' . trim( $name ),
				);
			}
		}

		return array(
			'success' => false,
			'chat_id' => '',
			'name'    => '',
			'message' => 'Không đọc được Chat ID từ phản hồi. Hãy nhắn 1 tin nhắn mới vào Bot rồi thử lại.',
		);
	}

	/**
	 * Get customer display name from order.
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	private function get_customer_display_name( $order ) {
		$name = trim( $order->get_formatted_billing_full_name() );
		if ( '' === $name ) {
			$name = trim( $order->get_formatted_shipping_full_name() );
		}
		return '' !== $name ? $name : 'Khách vãng lai';
	}

	/**
	 * Get clean hashtag for current site.
	 *
	 * @return string
	 */
	private function get_site_hashtag() {
		$raw = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) ( $this->settings['site_tag'] ?? '' ) );
		return '' !== $raw ? '#' . $raw : '';
	}
}

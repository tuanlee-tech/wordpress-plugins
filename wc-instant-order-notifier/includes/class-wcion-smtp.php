<?php
/**
 * Gmail / Custom SMTP Delivery & WooCommerce Email Recipient Sync.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCION_SMTP {

	/**
	 * @var array
	 */
	private $settings;

	/**
	 * @var WCION_Logger
	 */
	private $logger;

	/**
	 * Last email metadata for logging.
	 *
	 * @var array
	 */
	private $last_mail_meta = array();

	/**
	 * Current WooCommerce email context captured right before wp_mail().
	 *
	 * @var array
	 */
	private $current_wc_email_context = array();

	/**
	 * @param array        $settings Plugin settings.
	 * @param WCION_Logger $logger   Logger instance.
	 */
	public function __construct( $settings, $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Register SMTP and email recipient hooks.
	 */
	public function register_hooks() {
		// Override WooCommerce admin notification recipients if configured.
		if ( ! empty( $this->settings['sync_wc_email_sender'] ) && ! empty( $this->settings['admin_notify_email'] ) && is_email( $this->settings['admin_notify_email'] ) ) {
			$admin_Hooks = array(
				'woocommerce_email_recipient_new_order',
				'woocommerce_email_recipient_cancelled_order',
				'woocommerce_email_recipient_failed_order',
				'woocommerce_email_recipient_low_stock',
				'woocommerce_email_recipient_no_stock',
				'woocommerce_email_recipient_backorder',
			);
			foreach ( $admin_Hooks as $hook ) {
				add_filter( $hook, array( $this, 'filter_wc_admin_recipient' ), 99, 1 );
			}
		}

		// Capture WooCommerce email ID and order context for concise SMTP logs.
		add_filter( 'woocommerce_email_headers', array( $this, 'capture_wc_email_context' ), 999, 4 );

		if ( empty( $this->settings['enable_smtp'] ) ) {
			return;
		}

		add_action( 'phpmailer_init', array( $this, 'configure_phpmailer' ), 999 );
		add_filter( 'wp_mail_from', array( $this, 'filter_mail_from' ), 999 );
		add_filter( 'wp_mail_from_name', array( $this, 'filter_mail_from_name' ), 999 );
		add_filter( 'wp_mail', array( $this, 'capture_mail_meta' ), 999 );
		add_action( 'wp_mail_succeeded', array( $this, 'on_mail_succeeded' ) );
		add_action( 'wp_mail_failed', array( $this, 'on_mail_failed' ) );
	}

	/**
	 * Ensure WooCommerce admin order emails go to the configured shop owner email
	 * (instead of stale migrated addresses like admin@renaiflowers.com).
	 *
	 * @param string $recipient Existing recipient.
	 * @return string
	 */
	public function filter_wc_admin_recipient( $recipient ) {
		$target = trim( (string) ( $this->settings['admin_notify_email'] ?? '' ) );
		if ( '' !== $target ) {
			return $target;
		}
		return $recipient;
	}

	/**
	 * Configure PHPMailer for Gmail / Custom SMTP.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance.
	 */
	public function configure_phpmailer( $phpmailer ) {
		$host = trim( (string) ( $this->settings['smtp_host'] ?? '' ) );
		$user = trim( (string) ( $this->settings['smtp_username'] ?? '' ) );
		$pass = (string) ( $this->settings['smtp_password'] ?? '' );

		if ( '' === $host || '' === $user || '' === $pass ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host       = $host;
		$phpmailer->SMTPAuth   = true;
		$phpmailer->Port       = (int) ( $this->settings['smtp_port'] ?? 587 );
		$phpmailer->Username   = $user;
		$phpmailer->Password   = $pass;
		$encryption            = strtolower( (string) ( $this->settings['smtp_encryption'] ?? 'tls' ) );
		$phpmailer->SMTPSecure = in_array( $encryption, array( 'tls', 'ssl' ), true ) ? $encryption : '';
		$phpmailer->Timeout    = 10;
	}

	/**
	 * Override From Email.
	 *
	 * @param string $from Existing From email.
	 * @return string
	 */
	public function filter_mail_from( $from ) {
		$custom = trim( (string) ( $this->settings['smtp_from_email'] ?? '' ) );
		if ( '' !== $custom && is_email( $custom ) ) {
			return $custom;
		}
		$user = trim( (string) ( $this->settings['smtp_username'] ?? '' ) );
		if ( '' !== $user && is_email( $user ) ) {
			return $user;
		}
		return $from;
	}

	/**
	 * Override From Name.
	 *
	 * @param string $name Existing From name.
	 * @return string
	 */
	public function filter_mail_from_name( $name ) {
		$custom = trim( (string) ( $this->settings['smtp_from_name'] ?? '' ) );
		return '' !== $custom ? $custom : $name;
	}

	/**
	 * Capture outgoing mail args for logging.
	 *
	 * @param array $args wp_mail arguments.
	 * @return array
	 */
	public function capture_mail_meta( $args ) {
		$this->last_mail_meta = array(
			'to'          => is_array( $args['to'] ?? '' ) ? implode( ', ', $args['to'] ) : (string) ( $args['to'] ?? '' ),
			'subject'     => (string) ( $args['subject'] ?? '' ),
			'message'     => (string) ( $args['message'] ?? '' ),
			'headers'     => $args['headers'] ?? array(),
			'attachments' => $args['attachments'] ?? array(),
		);
		return $args;
	}

	/**
	 * Capture WooCommerce email ID and order number right before wp_mail().
	 *
	 * @param string|array $headers  Email headers.
	 * @param string       $email_id WooCommerce email ID.
	 * @param mixed        $object   Order, product, or user object.
	 * @param mixed        $email    WC_Email instance.
	 * @return string|array
	 */
	public function capture_wc_email_context( $headers, $email_id = '', $object = null, $email = null ) {
		$map = array(
			'new_order'                 => array( '#DonHangMoi', 'Chủ shop' ),
			'cancelled_order'           => array( '#DonDaHuy', 'Chủ shop' ),
			'customer_cancelled_order'  => array( '#DonDaHuy', 'Khách' ),
			'failed_order'              => array( '#DonThatBai', 'Chủ shop' ),
			'customer_failed_order'     => array( '#DonThatBai', 'Khách' ),
			'customer_on_hold_order'    => array( '#ChoThanhToan', 'Khách' ),
			'customer_processing_order' => array( '#DangXuLy', 'Khách' ),
			'customer_completed_order'  => array( '#HoanThanh', 'Khách' ),
			'customer_refunded_order'   => array( '#HoanTien', 'Khách' ),
			'customer_partially_refunded_order' => array( '#HoanTien', 'Khách' ),
			'customer_note'             => array( '#GhiChuDonHang', 'Khách' ),
			'customer_invoice'          => array( '#ChiTietDon', 'Khách' ),
			'low_stock'                 => array( '#SapHetHang', 'Chủ shop' ),
			'no_stock'                  => array( '#HetHang', 'Chủ shop' ),
			'backorder'                 => array( '#DatTruoc', 'Chủ shop' ),
			'customer_new_account'      => array( '#TaiKhoanMoi', 'Khách' ),
			'customer_reset_password'   => array( '#DatLaiMatKhau', 'Khách' ),
		);

		$hashtag   = $map[ $email_id ][0] ?? '#EmailWC';
		$role      = $map[ $email_id ][1] ?? ( ( is_object( $email ) && method_exists( $email, 'is_customer_email' ) && $email->is_customer_email() ) ? 'Khách' : 'Chủ shop' );
		$order_ref = '';

		if ( is_object( $object ) && method_exists( $object, 'get_order_number' ) ) {
			$order_ref = 'Đơn #' . $object->get_order_number();
		} elseif ( is_object( $object ) && method_exists( $object, 'get_name' ) ) {
			$order_ref = $object->get_name();
		}

		$this->current_wc_email_context = array(
			'hashtag'   => $hashtag,
			'role'      => $role,
			'order_ref' => $order_ref,
		);

		return $headers;
	}

	/**
	 * Build concise category hashtag and summary title for SMTP logs (matching Telegram style).
	 *
	 * @param string $to      Recipient address.
	 * @param string $subject Email subject.
	 * @return array{0:string, 1:string} [category, title]
	 */
	private function format_smtp_log_summary( $to, $subject ) {
		if ( ! empty( $this->current_wc_email_context ) ) {
			$ctx     = $this->current_wc_email_context;
			$hashtag = $ctx['hashtag'];
			$prefix  = '' !== $ctx['order_ref'] ? $ctx['order_ref'] : mb_substr( $subject, 0, 45 );
			$title   = sprintf( '%s → %s (%s)', $prefix, $ctx['role'], $to );
			return array( $hashtag, $title );
		}

		if ( false !== stripos( $subject, 'kiểm tra kết nối SMTP' ) ) {
			return array( '#TestSMTP', sprintf( 'Email test → %s', $to ) );
		}

		$short_subject = mb_substr( trim( $subject ), 0, 50 );
		return array( '#EmailHeThong', sprintf( '%s → %s', $short_subject, $to ) );
	}

	/**
	 * Log successful SMTP email.
	 *
	 * @param array $mail_data Mail data from WP.
	 */
	public function on_mail_succeeded( $mail_data ) {
		$to      = isset( $mail_data['to'] ) ? ( is_array( $mail_data['to'] ) ? implode( ', ', $mail_data['to'] ) : (string) $mail_data['to'] ) : ( $this->last_mail_meta['to'] ?? '' );
		$subject = (string) ( $mail_data['subject'] ?? ( $this->last_mail_meta['subject'] ?? '' ) );

		list( $category, $title ) = $this->format_smtp_log_summary( $to, $subject );
		$this->current_wc_email_context = array();

		$this->logger->add_log(
			'smtp',
			$category,
			$title,
			true,
			'250 OK (' . ( $this->settings['smtp_host'] ?? 'SMTP' ) . ')',
			$this->last_mail_meta
		);
	}

	/**
	 * Log failed SMTP email.
	 *
	 * @param WP_Error $error WP_Error from PHPMailer.
	 */
	public function on_mail_failed( $error ) {
		$to      = $this->last_mail_meta['to'] ?? '';
		$subject = $this->last_mail_meta['subject'] ?? '';
		$msg     = is_wp_error( $error ) ? $error->get_error_message() : 'Unknown SMTP error';

		list( $category, $title ) = $this->format_smtp_log_summary( $to, $subject );
		$this->current_wc_email_context = array();

		$this->logger->add_log(
			'smtp',
			$category,
			$title,
			false,
			$msg,
			$this->last_mail_meta
		);
	}
}

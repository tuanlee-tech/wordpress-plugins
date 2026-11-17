<?php
/**
 * Notification & Email Logger with Resend capability.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCION_Logger {

	/**
	 * Option name for logs.
	 */
	const LOG_OPTION = 'wcion_notification_logs';

	/**
	 * @var array
	 */
	private $settings;

	/**
	 * @param array $settings Plugin settings.
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Add a log entry.
	 *
	 * @param string $channel  'telegram' or 'smtp'.
	 * @param string $category Category hashtag/label (e.g. '#DonHangMoi').
	 * @param string $title    Brief summary (e.g. 'Đơn hàng #1502 - Nguyễn Văn A').
	 * @param bool   $success  Whether dispatch succeeded.
	 * @param string $response Response or error message.
	 * @param array  $payload  Optional payload for resending.
	 * @return string Log entry ID.
	 */
	public function add_log( $channel, $category, $title, $success, $response = '', $payload = array() ) {
		$logs = $this->get_logs();
		$id   = uniqid( 'wcion_', true );

		$entry = array(
			'id'        => $id,
			'time'      => current_time( 'mysql' ),
			'channel'   => sanitize_text_field( $channel ),
			'category'  => sanitize_text_field( $category ),
			'title'     => sanitize_text_field( $title ),
			'success'   => (bool) $success,
			'response'  => sanitize_text_field( (string) $response ),
			'payload'   => $payload,
		);

		array_unshift( $logs, $entry );
		$max = max( 20, min( 500, (int) ( $this->settings['max_logs'] ?? 100 ) ) );
		if ( count( $logs ) > $max ) {
			$logs = array_slice( $logs, 0, $max );
		}

		update_option( self::LOG_OPTION, $logs, false );
		return $id;
	}

	/**
	 * Get all logs.
	 *
	 * @return array
	 */
	public function get_logs() {
		$logs = get_option( self::LOG_OPTION, array() );
		return is_array( $logs ) ? $logs : array();
	}

	/**
	 * Find a specific log entry by ID.
	 *
	 * @param string $id Log ID.
	 * @return array|null
	 */
	public function get_log_by_id( $id ) {
		foreach ( $this->get_logs() as $log ) {
			if ( isset( $log['id'] ) && $log['id'] === $id ) {
				return $log;
			}
		}
		return null;
	}

	/**
	 * Clear all logs.
	 */
	public function clear_logs() {
		delete_option( self::LOG_OPTION );
	}
}

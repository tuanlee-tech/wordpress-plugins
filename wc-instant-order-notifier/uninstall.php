<?php
/**
 * Uninstall handler for WC Instant Order Notifier.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wcion_settings' );
delete_option( 'wcion_notification_logs' );

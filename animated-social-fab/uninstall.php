<?php
/**
 * Uninstall handler for Animated Social Media FAB.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'asfab_settings' );

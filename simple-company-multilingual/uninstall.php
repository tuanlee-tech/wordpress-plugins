<?php
/**
 * Uninstall cleanup for Simple Company Multilingual.
 *
 * @package Simple_Company_Multilingual
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'scm_settings', array() );

if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) || 'yes' !== $settings['delete_data_on_uninstall'] ) {
	return;
}

delete_option( 'scm_settings' );
delete_transient( 'scm_flush_rewrite_rules' );
delete_transient( 'scm_language_pack_notices' );

global $wpdb;

$meta_keys = array(
	'_scm_language',
	'_scm_translation_group',
	'_scm_translation_url_slug',
);

foreach ( $meta_keys as $meta_key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta_key ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

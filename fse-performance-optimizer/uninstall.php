<?php
/**
 * Uninstall handler for FSE Performance Optimizer.
 *
 * Automatically cleans up all plugin options, telemetry postmeta (_fse_opt_stats),
 * static HTML page cache files (wp-content/cache/fse-optimizer), and .htaccess rules
 * while keeping optimized WebP images, .woff2 fonts, and migrated URLs intact.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// 1. Delete plugin options & benchmark snapshots in wp_options.
$option_keys = array(
	'fse_performance_optimizer_settings',
	'fse_opt_baseline_snapshot',
	'fse_opt_current_snapshot',
	'fse_opt_font_stats',
	'fse_opt_db_stats',
	'fse_opt_extra_freed_bytes',
);

foreach ( $option_keys as $opt ) {
	delete_option( $opt );
}

// 2. Delete per-attachment telemetry metadata in wp_postmeta.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_fse_opt_stats'" );

// 3. Remove static HTML page cache directory (wp-content/cache/fse-optimizer).
$cache_dir = WP_CONTENT_DIR . '/cache/fse-optimizer';

$delete_recursive = static function ( $dir ) use ( &$delete_recursive ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$items = scandir( $dir );
	if ( ! is_array( $items ) ) {
		return;
	}
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		if ( is_dir( $path ) ) {
			$delete_recursive( $path );
			@rmdir( $path );
		} else {
			@unlink( $path );
		}
	}
	@rmdir( $dir );
};

$delete_recursive( $cache_dir );

// 4. Remove Browser Cache & Gzip block from root .htaccess.
$htaccess = ABSPATH . '.htaccess';
if ( file_exists( $htaccess ) && is_writable( $htaccess ) ) {
	$content = (string) @file_get_contents( $htaccess );
	$cleaned = preg_replace( '/# BEGIN FSE Performance Optimizer.*?# END FSE Performance Optimizer\s*/s', '', $content );
	if ( null !== $cleaned && $cleaned !== $content ) {
		@file_put_contents( $htaccess, $cleaned );
	}
}

<?php
/**
 * Plugin Name: Simple Company Multilingual
 * Description: Lightweight multilingual manager for company websites. Supports configurable languages, draft translation duplication, sibling linking, quick actions, and frontend duplicate filtering.
 * Version:     2.1.7
 * Author:      Your Company
 * Text Domain: simple-company-multilingual
 * Domain Path: /languages
 *
 * @package Simple_Company_Multilingual
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCM_PLUGIN_VERSION', '2.1.7' );
define( 'SCM_PLUGIN_FILE', __FILE__ );
define( 'SCM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

$scm_includes = array(
	'includes/trait-scm-core.php',
	'includes/trait-scm-languages.php',
	'includes/trait-scm-locale.php',
	'includes/trait-scm-string-overrides.php',
	'includes/trait-scm-settings.php',
	'includes/trait-scm-post-translations.php',
	'includes/trait-scm-url-router.php',
	'includes/trait-scm-frontend.php',
	'includes/trait-scm-seo.php',
	'includes/trait-scm-menus.php',
	'includes/trait-scm-widgets.php',
	'includes/trait-scm-special-pages.php',
	'includes/trait-scm-theme-element-overrides.php',
	'includes/class-simple-company-multilingual.php',
);

foreach ( $scm_includes as $scm_include ) {
	require_once SCM_PLUGIN_DIR . $scm_include;
}

Simple_Company_Multilingual::instance();

<?php
/**
 * Module E: Static HTML Page Cache (.html + .html.gz), HTML/CSS/JS Minifier & Browser Cache (.htaccess).
 *
 * Replaces WP Fastest Cache and woo-js-optimizer in a single clean, Share-Hosting ready module.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSE_Opt_Cache {

	/**
	 * Plugin settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Base directory for static HTML page cache.
	 *
	 * @var string
	 */
	private $cache_dir;

	/**
	 * Constructor.
	 *
	 * @param array $settings Plugin settings.
	 */
	public function __construct( $settings ) {
		$this->settings  = $settings;
		$this->cache_dir = WP_CONTENT_DIR . '/cache/fse-optimizer';
	}

	/**
	 * Register cache purge and serving hooks.
	 */
	public function register_hooks() {
		// Automatically purge cache when content, products, categories, FSE templates, or settings change.
		$purge_actions = array(
			'save_post',
			'deleted_post',
			'trashed_post',
			'untrashed_post',
			'switch_theme',
			'wp_update_nav_menu',
			'created_term',
			'edited_term',
			'delete_term',
			'woocommerce_update_product',
			'woocommerce_product_set_stock',
			'woocommerce_variation_set_stock',
			'woocommerce_new_order',
			'woocommerce_order_status_changed',
			'customize_save_after',
			'activated_plugin',
			'deactivated_plugin',
			'update_option_gspb_global_settings',
			'update_option_page_on_front',
			'update_option_blogname',
			'update_option_blogdescription',
		);
		foreach ( $purge_actions as $act ) {
			add_action( $act, array( $this, 'clear_all_cache' ) );
		}
	}

	/**
	 * Attempt to serve cached HTML page before WordPress queries run.
	 */
	public function maybe_serve_cached_page() {
		if ( empty( $this->settings['enable_page_cache'] ) || ! $this->is_cacheable_request() ) {
			return;
		}

		$file = $this->get_cache_file_path();
		if ( '' === $file || ! file_exists( $file ) ) {
			return;
		}

		$ttl = max( 300, (int) ( $this->settings['cache_ttl'] ?? 36000 ) );
		if ( ( time() - (int) @filemtime( $file ) ) > $ttl ) {
			return;
		}

		header( 'X-FSE-Cache: HIT' );
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'Cache-Control: no-cache, must-revalidate, max-age=0' );
		header( 'Vary: Accept-Encoding, Cookie' );

		$accept_gz = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) && false !== strpos( $_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip' );
		$gz_file   = $file . '.gz';

		if ( $accept_gz && file_exists( $gz_file ) && ! ini_get( 'zlib.output_compression' ) ) {
			header( 'Content-Encoding: gzip' );
			header( 'Content-Length: ' . (int) @filesize( $gz_file ) );
			readfile( $gz_file );
			exit;
		}

		readfile( $file );
		exit;
	}

	/**
	 * Minify HTML & inline CSS/JS and save to static page cache if request is cacheable.
	 *
	 * @param string $html Processed HTML buffer.
	 * @return string Final HTML output.
	 */
	public function minify_and_store_html( $html ) {
		if ( '' === $html || strlen( $html ) < 200 ) {
			return $html;
		}

		if ( ! empty( $this->settings['enable_minify'] ) ) {
			$html = $this->minify_html_and_inline_css( $html );
		}

		if ( ! empty( $this->settings['enable_page_cache'] ) && $this->is_cacheable_request() && http_response_code() === 200 ) {
			if ( ! headers_sent() ) {
				header( 'X-FSE-Cache: MISS' );
				header( 'Cache-Control: no-cache, must-revalidate, max-age=0' );
			}
			$file = $this->get_cache_file_path();
			if ( '' !== $file ) {
				$dir = dirname( $file );
				if ( ! is_dir( $dir ) ) {
					wp_mkdir_p( $dir );
				}
				$stamped = $html . "\n<!-- Cached by FSE Performance Optimizer (" . gmdate( 'Y-m-d H:i:s' ) . ' UTC) -->';
				@file_put_contents( $file, $stamped );
				if ( function_exists( 'gzencode' ) ) {
					@file_put_contents( $file . '.gz', gzencode( $stamped, 6 ) );
				}
			}
		}

		return $html;
	}

	/**
	 * Minify inline <style> blocks and collapse redundant HTML whitespace safely.
	 *
	 * @param string $html Input HTML.
	 * @return string Minified HTML.
	 */
	public function minify_html_and_inline_css( $html ) {
		// 1. Minify inline <style> contents.
		$html = preg_replace_callback(
			'#(<style\b[^>]*>)(.*?)(</style>)#is',
			static function ( $m ) {
				$css = $m[2];
				// Strip CSS comments.
				$css = preg_replace( '#/\*.*?\*/#s', '', $css );
				// Collapse whitespace.
				$css = preg_replace( '/\s+/', ' ', $css );
				// Strip spaces around structural tokens, NEVER before `:` (preserves `:root :where(...)` descendant selectors!).
				$css = preg_replace( '/\s*([{};,>~])\s*/', '$1', $css );
				// Strip spaces around `:` only inside property declarations (immediately after `{` or `;`).
				$css = preg_replace( '/([;{])\s*([a-zA-Z_-]+)\s*:\s*/', '$1$2:', $css );
				$css = str_replace( ';}', '}', $css );
				return $m[1] . trim( $css ) . $m[3];
			},
			$html
		);

		// 2. Remove HTML comments (except IE conditional comments).
		$html = preg_replace( '/<!--(?!\s*(?:\[if [^\]]+]|<!|>))(?:(?!-->).)*-->/s', '', $html );

		// 3. Protect <pre>, <textarea>, and <script> blocks while trimming inter-tag whitespace.
		$placeholders = array();
		$html         = preg_replace_callback(
			'#<(pre|textarea|script)\b[^>]*>.*?</\1>#is',
			static function ( $m ) use ( &$placeholders ) {
				$key                  = '%%FSE_MIN_HOLD_' . count( $placeholders ) . '%%';
				$placeholders[ $key ] = $m[0];
				return $key;
			},
			$html
		);

		$html = preg_replace( '/>\s{2,}</', '> <', $html );
		$html = preg_replace( '/^\s+|\s+$/m', '', $html );

		if ( ! empty( $placeholders ) ) {
			$html = strtr( $html, $placeholders );
		}

		return $html;
	}

	/**
	 * Check whether the current HTTP request can be served from or stored into static page cache.
	 *
	 * @return bool
	 */
	public function is_cacheable_request() {
		if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		$method = strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' );
		if ( 'GET' !== $method ) {
			return false;
		}

		// Bypass if any query string parameters exist (allow pure UTM/tracking params only if desired, or strict empty query).
		if ( ! empty( $_GET ) ) {
			return false;
		}

		// Bypass logged-in users or active WooCommerce cart with items.
		if ( ! empty( $_COOKIE ) ) {
			foreach ( $_COOKIE as $ck_name => $ck_val ) {
				if ( 0 === strpos( $ck_name, 'wordpress_logged_in_' ) || 0 === strpos( $ck_name, 'comment_author_' ) || 0 === strpos( $ck_name, 'wp-postpass_' ) ) {
					return false;
				}
				if ( 'woocommerce_items_in_cart' === $ck_name && (int) $ck_val > 0 ) {
					return false;
				}
			}
		}

		$uri = $_SERVER['REQUEST_URI'] ?? '/';
		$excluded_prefixes = array(
			'/cart',
			'/gio-hang',
			'/checkout',
			'/thanh-toan',
			'/my-account',
			'/tai-khoan',
			'/wp-admin',
			'/wp-login.php',
			'/wp-json',
			'/xmlrpc.php',
		);
		foreach ( $excluded_prefixes as $prefix ) {
			if ( 0 === stripos( $uri, $prefix ) ) {
				return false;
			}
		}

		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() || is_404() || is_search() ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Compute the disk cache file path for the current request URI.
	 *
	 * @return string
	 */
	private function get_cache_file_path() {
		$host = preg_replace( '/[^a-zA-Z0-9.-]/', '_', strtolower( $_SERVER['HTTP_HOST'] ?? 'localhost' ) );
		$uri  = parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
		$uri  = trim( (string) $uri, '/' );
		$safe = '' === $uri ? '_home' : preg_replace( '/[^a-zA-Z0-9\/_-]/', '_', $uri );

		return $this->cache_dir . '/' . $host . '/' . $safe . '/index.html';
	}

	/**
	 * Clear all cached HTML pages and return number of deleted files.
	 *
	 * @return int
	 */
	public function clear_all_cache() {
		return $this->delete_dir_recursive( $this->cache_dir );
	}

	/**
	 * Count cached HTML files and total bytes on disk.
	 *
	 * @return array{files:int, bytes:int}
	 */
	public function get_cache_stats() {
		$stats = array(
			'files' => 0,
			'bytes' => 0,
		);
		if ( ! is_dir( $this->cache_dir ) ) {
			return $stats;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->cache_dir, RecursiveDirectoryIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				if ( str_ends_with( $file->getFilename(), '.html' ) ) {
					++$stats['files'];
				}
				$stats['bytes'] += (int) $file->getSize();
			}
		}
		return $stats;
	}

	/**
	 * Write or remove Browser Cache & Gzip rules in root `.htaccess` for Apache/LiteSpeed Share Hosting.
	 *
	 * @param bool $enable Whether to write or remove the rules.
	 * @return bool
	 */
	public function update_htaccess_rules( $enable = true ) {
		$htaccess = ABSPATH . '.htaccess';
		if ( ! file_exists( $htaccess ) && ! is_writable( ABSPATH ) ) {
			return false;
		}
		if ( file_exists( $htaccess ) && ! is_writable( $htaccess ) ) {
			return false;
		}

		$current = file_exists( $htaccess ) ? (string) @file_get_contents( $htaccess ) : '';
		$current = preg_replace( '/# BEGIN FSE Performance Optimizer.*?# END FSE Performance Optimizer\s*/s', '', $current );

		if ( $enable ) {
			$rules = "# BEGIN FSE Performance Optimizer\n"
				. "<IfModule mod_expires.c>\n"
				. "    ExpiresActive On\n"
				. "    ExpiresByType image/webp \"access plus 1 year\"\n"
				. "    ExpiresByType image/jpeg \"access plus 1 year\"\n"
				. "    ExpiresByType image/png \"access plus 1 year\"\n"
				. "    ExpiresByType image/svg+xml \"access plus 1 year\"\n"
				. "    ExpiresByType font/woff2 \"access plus 1 year\"\n"
				. "    ExpiresByType application/font-woff2 \"access plus 1 year\"\n"
				. "    ExpiresByType text/css \"access plus 1 year\"\n"
				. "    ExpiresByType application/javascript \"access plus 1 year\"\n"
				. "</IfModule>\n"
				. "<IfModule mod_headers.c>\n"
				. "    <FilesMatch \"\\.(webp|woff2|css|js|jpg|jpeg|png|svg)$\">\n"
				. "        Header set Cache-Control \"public, max-age=31536000, immutable\"\n"
				. "    </FilesMatch>\n"
				. "</IfModule>\n"
				. "<IfModule mod_deflate.c>\n"
				. "    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css application/javascript application/json image/svg+xml\n"
				. "</IfModule>\n"
				. "# END FSE Performance Optimizer\n\n";

			$current = $rules . ltrim( $current );
		}

		return false !== @file_put_contents( $htaccess, $current );
	}

	/**
	 * Recursively delete contents of a directory.
	 *
	 * @param string $dir Directory path.
	 * @return int Deleted file count.
	 */
	private function delete_dir_recursive( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$count = 0;
		$items = scandir( $dir );
		if ( ! is_array( $items ) ) {
			return 0;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$count += $this->delete_dir_recursive( $path );
				@rmdir( $path );
			} else {
				if ( @unlink( $path ) ) {
					++$count;
				}
			}
		}
		return $count;
	}
}

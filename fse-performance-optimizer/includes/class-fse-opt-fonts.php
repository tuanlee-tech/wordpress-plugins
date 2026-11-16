<?php
/**
 * Module C: Runtime Font Optimizer (FSE Font Library + Google Fonts + WOFF2 Upgrade).
 *
 * Works 100% in pure PHP at runtime on any Share Hosting, plus supports converting
 * local .ttf/.otf files in wp-content/uploads/fonts/ to .woff2 when CLI tools exist.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSE_Opt_Fonts {

	/**
	 * Plugin settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param array $settings Plugin settings.
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Optimize @font-face rules, Google Fonts, and preload links inside HTML output buffer.
	 *
	 * @param string $html Full HTML document.
	 * @return string Optimized HTML document.
	 */
	public function optimize_fonts_in_html( $html ) {
		if ( empty( $this->settings['enable_font_opt'] ) || '' === $html ) {
			return $html;
		}

		$upload_dir  = wp_upload_dir();
		$basedir     = $upload_dir['basedir'];
		$baseurl     = $upload_dir['baseurl'];
		$preloaded   = array();
		$max_preload = isset( $this->settings['preload_fonts_count'] ) ? (int) $this->settings['preload_fonts_count'] : 2;

		// 1. Process all @font-face blocks: enforce font-display:swap and upgrade .ttf/.otf -> .woff2 if file exists.
		$html = preg_replace_callback(
			'/@font-face\s*\{([^}]+)\}/i',
			function ( $matches ) use ( $basedir, $baseurl, &$preloaded, $max_preload ) {
				$body = $matches[1];

				// Enforce font-display: swap.
				if ( preg_match( '/font-display\s*:\s*[a-zA-Z-]+/i', $body ) ) {
					$body = preg_replace( '/font-display\s*:\s*[a-zA-Z-]+/i', 'font-display:swap', $body );
				} else {
					$body = rtrim( $body, '; ' ) . ';font-display:swap;';
				}

				// Upgrade .ttf / .otf URLs to .woff2 if corresponding .woff2 exists on disk.
				$body = preg_replace_callback(
					'#url\((["\']?)([^"\')]+\.(?:ttf|otf))\1\)\s*(?:format\(["\']?(?:truetype|opentype)["\']?\))?#i',
					function ( $url_match ) use ( $basedir, $baseurl ) {
						$quote    = $url_match[1] ?: "'";
						$orig_url = $url_match[2];
						$woff2_u  = preg_replace( '/\.(ttf|otf)$/i', '.woff2', $orig_url );

						// Check if local file exists in uploads.
						$rel_pos = strpos( $woff2_u, '/wp-content/uploads/' );
						if ( false !== $rel_pos ) {
							$rel_sub   = substr( $woff2_u, $rel_pos + strlen( '/wp-content/uploads/' ) );
							$disk_path = $basedir . '/' . ltrim( $rel_sub, '/' );
							if ( file_exists( $disk_path ) ) {
								return 'url(' . $quote . $woff2_u . $quote . ") format('woff2')";
							}
						} elseif ( 0 === strpos( $woff2_u, $baseurl ) ) {
							$disk_path = str_replace( $baseurl, $basedir, $woff2_u );
							if ( file_exists( $disk_path ) ) {
								return 'url(' . $quote . $woff2_u . $quote . ") format('woff2')";
							}
						}

						return $url_match[0];
					},
					$body
				);

				// Collect primary regular (400) .woff2 fonts for preload.
				if ( count( $preloaded ) < $max_preload && preg_match( '/font-weight\s*:\s*(?:400|normal)\b/i', $body ) && preg_match( '/font-style\s*:\s*normal\b/i', $body ) ) {
					if ( preg_match( '#url\(["\']?([^"\')]+\.woff2)["\']?\)#i', $body, $wm ) ) {
						$f_url = $wm[1];
						if ( ! in_array( $f_url, $preloaded, true ) ) {
							$preloaded[] = $f_url;
						}
					}
				}

				return '@font-face{' . $body . '}';
			},
			$html
		);

		// 2. Deduplicate identical @font-face blocks across inline style tags.
		$seen_faces = array();
		$html       = preg_replace_callback(
			'/@font-face\{[^}]+\}/i',
			static function ( $m ) use ( &$seen_faces ) {
				$key = strtolower( preg_replace( '/\s+/', '', $m[0] ) );
				if ( isset( $seen_faces[ $key ] ) ) {
					return '';
				}
				$seen_faces[ $key ] = true;
				return $m[0];
			},
			$html
		);

		// 3. Optimize Google Fonts if present (ensure display=swap and preconnect).
		if ( false !== strpos( $html, 'fonts.googleapis.com' ) ) {
			$html = preg_replace_callback(
				'#href=(["\'])(https?://fonts\.googleapis\.com/css2?\?[^"\']+)\1#i',
				static function ( $m ) {
					$url = $m[2];
					if ( false === strpos( $url, 'display=' ) ) {
						$url .= ( false !== strpos( $url, '?' ) ? '&amp;' : '?' ) . 'display=swap';
					} else {
						$url = preg_replace( '/display=[a-zA-Z]+/i', 'display=swap', $url );
					}
					return 'href=' . $m[1] . $url . $m[1];
				},
				$html
			);

			if ( false === strpos( $html, 'rel="preconnect" href="https://fonts.gstatic.com"' ) ) {
				$preconnect = '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n"
					. '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
				$html       = preg_replace( '/<head\b[^>]*>/i', "$0\n" . $preconnect, $html, 1 );
			}
		}

		// 4. Preload primary .woff2 fonts in <head>.
		if ( ! empty( $preloaded ) ) {
			$preload_tags = '';
			foreach ( $preloaded as $f_url ) {
				if ( false === strpos( $html, 'href="' . $f_url . '" as="font"' ) ) {
					$preload_tags .= '<link rel="preload" href="' . esc_url( $f_url ) . '" as="font" type="font/woff2" crossorigin>' . "\n";
				}
			}
			if ( '' !== $preload_tags ) {
				$html = preg_replace( '/<head\b[^>]*>/i', "$0\n" . $preload_tags, $html, 1 );
			}
		}

		return $html;
	}

	/**
	 * Detect whether local server supports CLI conversion from .ttf/.otf to .woff2.
	 *
	 * @return array{available:bool, method:string, reason:string}
	 */
	public function detect_font_converter_capability() {
		if ( ! function_exists( 'exec' ) ) {
			return array(
				'available' => false,
				'method'    => 'none',
				'reason'    => 'Hàm PHP exec() đang bị tắt trên Share Hosting.',
			);
		}
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		if ( in_array( 'exec', $disabled, true ) ) {
			return array(
				'available' => false,
				'method'    => 'none',
				'reason'    => 'Hàm PHP exec() nằm trong disable_functions của Hosting.',
			);
		}

		$env_bin = file_exists( '/usr/bin/env' ) ? '/usr/bin/env' : ( file_exists( '/bin/env' ) ? '/bin/env' : 'env' );
		$home    = getenv( 'HOME' );
		if ( ( false === $home || '' === $home ) && function_exists( 'posix_getpwuid' ) && function_exists( 'posix_geteuid' ) ) {
			$pw = @posix_getpwuid( posix_geteuid() );
			if ( ! empty( $pw['dir'] ) ) {
				$home = $pw['dir'];
			}
		}
		if ( false === $home || '' === $home ) {
			$home = '/home/' . get_current_user();
		}

		$py_bin = file_exists( '/usr/bin/python3' ) ? '/usr/bin/python3' : 'python3';
		$prefix = sprintf(
			'%s -i PATH=/usr/local/bin:/usr/bin:/bin HOME=%s %s',
			$env_bin,
			escapeshellarg( $home ),
			$py_bin
		);

		$out = array();
		$rc  = 1;
		@exec( $prefix . ' -c "import fontTools, brotli" 2>/dev/null', $out, $rc );
		if ( 0 === $rc ) {
			return array(
				'available' => true,
				'method'    => 'python-fonttools',
				'cmd_prefix'=> $prefix,
				'reason'    => 'Hỗ trợ Python fontTools + Brotli.',
			);
		}

		$w_out = array();
		$w_rc  = 1;
		@exec( $env_bin . ' PATH=/usr/local/bin:/usr/bin:/bin which woff2_compress 2>/dev/null', $w_out, $w_rc );
		if ( 0 === $w_rc && ! empty( $w_out[0] ) ) {
			return array(
				'available' => true,
				'method'    => 'woff2_compress',
				'cmd_prefix'=> trim( $w_out[0] ),
				'reason'    => 'Hỗ trợ CLI woff2_compress.',
			);
		}

		return array(
			'available' => false,
			'method'    => 'none',
			'reason'    => 'Hosting chưa cài fontTools hoặc woff2_compress (có thể upload sẵn file .woff2 ở bên dưới).',
		);
	}

	/**
	 * Convert all .ttf/.otf font files in wp-content/uploads/fonts/ to .woff2 and update DB references.
	 *
	 * @return array<string, mixed>
	 */
	public function convert_uploaded_fonts_to_woff2() {
		global $wpdb;

		$upload_dir = wp_upload_dir();
		$fonts_dir  = $upload_dir['basedir'] . '/fonts';
		if ( ! is_dir( $fonts_dir ) ) {
			return array(
				'converted'    => 0,
				'total_files'  => 0,
				'before_bytes' => 0,
				'after_bytes'  => 0,
				'message'      => 'Thư mục wp-content/uploads/fonts không tồn tại.',
			);
		}

		$cap          = $this->detect_font_converter_capability();
		$files        = glob( $fonts_dir . '/*.{ttf,otf,TTF,OTF}', GLOB_BRACE );
		$before_bytes = 0;
		$after_bytes  = 0;
		$converted    = 0;
		$url_map      = array();

		if ( is_array( $files ) && ! empty( $files ) && $cap['available'] ) {
			if ( 'python-fonttools' === $cap['method'] ) {
				$py_script = 'import sys, glob, os; from fontTools.ttLib import TTFont; d=sys.argv[1]; '
					. '[(lambda p,w: (not os.path.exists(w)) and (lambda f: (setattr(f,"flavor","woff2"), f.save(w)))(TTFont(p)))(p, os.path.splitext(p)[0]+".woff2") '
					. 'for p in glob.glob(os.path.join(d,"*")) if p.lower().endswith((".ttf",".otf"))]';
				$cmd       = sprintf(
					'%s -c %s %s 2>/dev/null',
					$cap['cmd_prefix'],
					escapeshellarg( $py_script ),
					escapeshellarg( $fonts_dir )
				);
				@exec( $cmd );
			} elseif ( 'woff2_compress' === $cap['method'] ) {
				foreach ( $files as $src_font ) {
					$woff2_path = preg_replace( '/\.(ttf|otf)$/i', '.woff2', $src_font );
					if ( ! file_exists( $woff2_path ) ) {
						@exec( escapeshellarg( $cap['cmd_prefix'] ) . ' ' . escapeshellarg( $src_font ) . ' 2>/dev/null' );
					}
				}
			}
		}

		if ( is_array( $files ) ) {
			foreach ( $files as $src_font ) {
				$f_size        = (int) @filesize( $src_font );
				$before_bytes += $f_size;
				$woff2_path    = preg_replace( '/\.(ttf|otf)$/i', '.woff2', $src_font );

				if ( file_exists( $woff2_path ) ) {
					$after_bytes += (int) @filesize( $woff2_path );
					++$converted;
					$old_base = basename( $src_font );
					$new_base = basename( $woff2_path );
					$url_map[ '/wp-content/uploads/fonts/' . $old_base ] = '/wp-content/uploads/fonts/' . $new_base;
				} else {
					$after_bytes += $f_size;
				}
			}
		}

		// Save font telemetry snapshot (preserving initial before_bytes if already set).
		$prev_stats = get_option( 'fse_opt_font_stats', array() );
		$stat_data  = array(
			'before_bytes' => ! empty( $prev_stats['before_bytes'] ) ? (int) $prev_stats['before_bytes'] : $before_bytes,
			'after_bytes'  => $after_bytes,
			'total_files'  => is_array( $files ) ? count( $files ) : 0,
			'converted'    => $converted,
			'timestamp'    => time(),
		);
		update_option( 'fse_opt_font_stats', $stat_data, false );

		// Update FSE wp_font_face & wp_global_styles posts in DB so WordPress outputs .woff2 natively.
		if ( ! empty( $url_map ) ) {
			$pairs = array(
				"format('truetype')"  => "format('woff2')",
				"format('opentype')"  => "format('woff2')",
				'format("truetype")'  => 'format("woff2")',
				'format("opentype")'  => 'format("woff2")',
				'"fontDisplay":"fallback"' => '"fontDisplay":"swap"',
				'font-display:fallback'    => 'font-display:swap',
				'font-display: fallback'   => 'font-display: swap',
			);
			foreach ( $url_map as $old_u => $new_u ) {
				$pairs[ $old_u ]                          = $new_u;
				$pairs[ str_replace( '/', '\/', $old_u ) ] = str_replace( '/', '\/', $new_u );
			}

			$font_posts = $wpdb->get_results(
				"SELECT ID, post_content FROM {$wpdb->posts}
				 WHERE post_type IN ('wp_font_face', 'wp_global_styles')
				    OR post_content LIKE '%/wp-content/uploads/fonts/%'
				    OR post_content LIKE '%\\\\/wp-content\\\\/uploads\\\\/fonts\\\\/%'",
				ARRAY_A
			);
			if ( is_array( $font_posts ) ) {
				foreach ( $font_posts as $fp ) {
					$updated = strtr( (string) $fp['post_content'], $pairs );
					if ( $updated !== $fp['post_content'] ) {
						$wpdb->update(
							$wpdb->posts,
							array( 'post_content' => $updated ),
							array( 'ID' => (int) $fp['ID'] )
						);
					}
				}
			}
		}

		// Delete WP font transient/cache if any.
		delete_transient( 'global_styles' );
		wp_clean_theme_json_cache();

		return $stat_data;
	}
}

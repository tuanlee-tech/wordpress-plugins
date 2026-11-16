<?php
/**
 * Module D: Core Web Vitals Guard (LCP, CLS width/height auto-fill, Lazy-Load, and Runtime WebP Upgrade).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSE_Opt_CWV {

	/**
	 * Plugin settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * In-memory cache of image dimensions keyed by relative upload path.
	 *
	 * @var array<string, array{0:int,1:int}>
	 */
	private $dim_cache = array();

	/**
	 * Constructor.
	 *
	 * @param array $settings Plugin settings.
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Process all <img> tags and inline background-image URLs in the HTML output buffer.
	 *
	 * @param string $html Full HTML output.
	 * @return string Optimized HTML.
	 */
	public function optimize_images_in_html( $html ) {
		if ( empty( $this->settings['enable_cwv_guard'] ) || '' === $html ) {
			return $html;
		}

		$upload_dir    = wp_upload_dir();
		$basedir       = $upload_dir['basedir'];
		$content_imgs  = 0;
		$header_end_pos = stripos( $html, '</header>' );

		// 1. Upgrade any remaining .jpg/.jpeg/.png in inline CSS url(...) to .webp if .webp exists on disk.
		$html = preg_replace_callback(
			'#url\((["\']?)([^"\')]*/wp-content/uploads/[^"\')]+\.(?:jpe?g|png))\1\)#i',
			function ( $m ) use ( $basedir ) {
				$quote    = $m[1];
				$orig_url = $m[2];
				$webp_url = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $orig_url );
				$disk     = $this->resolve_upload_disk_path( $webp_url, $basedir );
				if ( '' !== $disk && file_exists( $disk ) ) {
					return 'url(' . $quote . $webp_url . $quote . ')';
				}
				return $m[0];
			},
			$html
		);

		// 2. Process all <img> tags: runtime .webp upgrade, missing width/height injection, and LCP vs Lazy-load.
		$html = preg_replace_callback(
			'#<img\b([^>]*)>#is',
			function ( $matches, $offset = 0 ) use ( $basedir, &$content_imgs, $header_end_pos ) {
				$full_match = is_array( $matches[0] ) ? $matches[0][0] : $matches[0];
				$match_pos  = is_array( $matches[0] ) ? (int) $matches[0][1] : 0;
				$raw_attrs  = is_array( $matches[1] ) ? $matches[1][0] : $matches[1];
				$attrs      = rtrim( $raw_attrs, "/ \t\n\r\0\x0B" );

				// Extract src attribute (avoiding data-src).
				if ( ! preg_match( '#(?<![a-zA-Z0-9_-])src\s*=\s*(["\'])([^"\']+)\1#i', $attrs, $src_m ) ) {
					return $full_match;
				}
				$src_quote = $src_m[1];
				$src_url   = $src_m[2];

				// Upgrade src to .webp if corresponding .webp exists on disk.
				if ( preg_match( '/\.(jpe?g|png)$/i', $src_url ) && false !== strpos( $src_url, '/wp-content/uploads/' ) ) {
					$webp_candidate = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $src_url );
					$webp_disk      = $this->resolve_upload_disk_path( $webp_candidate, $basedir );
					if ( '' !== $webp_disk && file_exists( $webp_disk ) ) {
						$src_url = $webp_candidate;
						$attrs   = preg_replace(
							'#(?<![a-zA-Z0-9_-])src\s*=\s*(["\'])[^"\']+\1#i',
							'src=' . $src_quote . $src_url . $src_quote,
							$attrs,
							1
						);
					}
				}

				// Upgrade srcset entries to .webp if corresponding .webp files exist on disk.
				if ( preg_match( '#\bsrcset\s*=\s*(["\'])([^"\']+)\1#i', $attrs, $ss_m ) ) {
					$ss_quote = $ss_m[1];
					$ss_val   = $ss_m[2];
					$parts    = explode( ',', $ss_val );
					$new_pts  = array();
					foreach ( $parts as $pt ) {
						$pt = trim( $pt );
						if ( preg_match( '#^(\S+\.(?:jpe?g|png))(\s+.*)$#i', $pt, $pm ) ) {
							$w_url  = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $pm[1] );
							$w_disk = $this->resolve_upload_disk_path( $w_url, $basedir );
							if ( '' !== $w_disk && file_exists( $w_disk ) ) {
								$new_pts[] = $w_url . $pm[2];
								continue;
							}
						}
						$new_pts[] = $pt;
					}
					$attrs = preg_replace(
						'#\bsrcset\s*=\s*(["\'])[^"\']+\1#i',
						'srcset=' . $ss_quote . implode( ', ', $new_pts ) . $ss_quote,
						$attrs,
						1
					);
				}

				// Resolve image dimensions for CLS prevention (ignore percentage values like width="100%").
				$has_w = preg_match( '#\bwidth\s*=\s*["\']?(\d+)(?!%)\b#i', $attrs, $wm );
				$has_h = preg_match( '#\bheight\s*=\s*["\']?(\d+)(?!%)\b#i', $attrs, $hm );
				$img_w = $has_w ? (int) $wm[1] : 0;
				$img_h = $has_h ? (int) $hm[1] : 0;

				if ( ! $has_w || ! $has_h ) {
					$dims = $this->get_local_image_dimensions( $src_url, $basedir );
					if ( $dims[0] > 0 && $dims[1] > 0 ) {
						$img_w = $dims[0];
						$img_h = $dims[1];
					} elseif ( false !== stripos( $attrs, 'aspect-ratio:1' ) || false !== stripos( $attrs, 'aspect-ratio: 1' ) ) {
						$img_w = 600;
						$img_h = 600;
					}
				}

				// Determine if this is inside <header> / logo vs main page content image.
				$in_header = ( false !== $header_end_pos && $match_pos > 0 && $match_pos < $header_end_pos );
				$is_logo   = $in_header
					|| ( false !== stripos( $attrs, 'custom-logo' ) || false !== stripos( $attrs, 'site-logo' ) )
					|| ( $img_w > 0 && $img_w <= 220 && $img_h > 0 && $img_h <= 120 );

				if ( ! $is_logo ) {
					++$content_imgs;
				}

				// For non-LCP content images (or oversized header logos) pointing to a full-size upload in src,
				// use the 768w/600w Retina Q=100+ICC sub-size as default src and attach srcset if missing.
				if ( ( $in_header || ( ! $is_logo && $content_imgs > 1 ) ) && false !== strpos( $src_url, '/wp-content/uploads/' ) ) {
					$responsive = $this->find_responsive_webp_variants( $src_url, $basedir );
					if ( ! empty( $responsive['preferred_src'] ) && $responsive['preferred_src'] !== $src_url ) {
						$src_url = $responsive['preferred_src'];
						$attrs   = preg_replace(
							'#(\s|^)src\s*=\s*(["\'])[^"\']+\2#i',
							'$1src=' . $src_quote . $src_url . $src_quote,
							$attrs,
							1
						);
						if ( false === stripos( $attrs, 'srcset=' ) && ! empty( $responsive['srcset'] ) ) {
							$attrs .= ' srcset="' . esc_attr( $responsive['srcset'] ) . '" sizes="(max-width: 768px) 92vw, 600px"';
						}
						// Update resolved dimensions to match the selected sub-size.
						$sub_dims = $this->get_local_image_dimensions( $src_url, $basedir );
						if ( $sub_dims[0] > 0 && $sub_dims[1] > 0 ) {
							$img_w = $sub_dims[0];
							$img_h = $sub_dims[1];
						}
					}
				}

				if ( ( ! $has_w || ! $has_h ) && $img_w > 0 && $img_h > 0 ) {
					if ( preg_match( '#\bwidth\s*=\s*["\']?\d+%["\']?#i', $attrs ) ) {
						$attrs = preg_replace( '#\bwidth\s*=\s*["\']?\d+%["\']?#i', 'width="' . $img_w . '"', $attrs, 1 );
					} elseif ( ! $has_w ) {
						$attrs .= ' width="' . $img_w . '"';
					}
					if ( preg_match( '#\bheight\s*=\s*["\']?\d+%["\']?#i', $attrs ) ) {
						$attrs = preg_replace( '#\bheight\s*=\s*["\']?\d+%["\']?#i', 'height="' . $img_h . '"', $attrs, 1 );
					} elseif ( ! $has_h ) {
						$attrs .= ' height="' . $img_h . '"';
					}
				}

				if ( $is_logo ) {
					// Header logo is above the fold: never lazy-load it.
					$attrs = preg_replace( '#\s*\bloading\s*=\s*(["\'])lazy\1#i', '', $attrs );
					if ( ! preg_match( '#\bdecoding\s*=#i', $attrs ) ) {
						$attrs .= ' decoding="async"';
					}
				} else {
					if ( 1 === $content_imgs ) {
						// First content image = LCP candidate: remove loading="lazy" and set fetchpriority="high".
						$attrs = preg_replace( '#\s*\bloading\s*=\s*(["\'])lazy\1#i', '', $attrs );
						if ( ! preg_match( '#\bfetchpriority\s*=#i', $attrs ) ) {
							$attrs .= ' fetchpriority="high"';
						}
						if ( ! preg_match( '#\bdecoding\s*=#i', $attrs ) ) {
							$attrs .= ' decoding="async"';
						}
					} else {
						// Below-the-fold content images: enforce loading="lazy" and decoding="async".
						if ( ! preg_match( '#\bloading\s*=#i', $attrs ) ) {
							$attrs .= ' loading="lazy"';
						}
						if ( ! preg_match( '#\bdecoding\s*=#i', $attrs ) ) {
							$attrs .= ' decoding="async"';
						}
					}
				}

				return '<img' . $attrs . '>';
			},
			$html,
			-1,
			$count,
			PREG_OFFSET_CAPTURE
		);

		return $html;
	}

	/**
	 * Map a URL containing `/wp-content/uploads/...` to its absolute disk path.
	 *
	 * @param string $url     Image URL.
	 * @param string $basedir Uploads base directory.
	 * @return string Absolute path or empty string.
	 */
	private function resolve_upload_disk_path( $url, $basedir ) {
		$clean_url = strtok( $url, '?' );
		$pos        = strpos( $clean_url, '/wp-content/uploads/' );
		if ( false !== $pos ) {
			$rel = substr( $clean_url, $pos + strlen( '/wp-content/uploads/' ) );
			return $basedir . '/' . ltrim( rawurldecode( $rel ), '/' );
		}
		if ( false !== strpos( $clean_url, '/wp-content/' ) ) {
			$pos_c = strpos( $clean_url, '/wp-content/' );
			$rel_c = substr( $clean_url, $pos_c + strlen( '/wp-content/' ) );
			return WP_CONTENT_DIR . '/' . ltrim( rawurldecode( $rel_c ), '/' );
		}
		return '';
	}

	/**
	 * Lookup width and height of a local image file with in-memory caching.
	 *
	 * @param string $url     Image URL.
	 * @param string $basedir Uploads base directory.
	 * @return array{0:int,1:int}
	 */
	private function get_local_image_dimensions( $url, $basedir ) {
		$disk = $this->resolve_upload_disk_path( $url, $basedir );
		if ( '' === $disk || ! file_exists( $disk ) ) {
			return array( 0, 0 );
		}
		if ( isset( $this->dim_cache[ $disk ] ) ) {
			return $this->dim_cache[ $disk ];
		}
		$info = @getimagesize( $disk );
		$dims = array(
			isset( $info[0] ) ? (int) $info[0] : 0,
			isset( $info[1] ) ? (int) $info[1] : 0,
		);
		$this->dim_cache[ $disk ] = $dims;
		return $dims;
	}

	/**
	 * Find existing responsive WebP sub-sizes on disk for a full-size upload URL.
	 *
	 * @param string $src_url Full image URL.
	 * @param string $basedir Uploads base directory.
	 * @return array{preferred_src:string, srcset:string}
	 */
	private function find_responsive_webp_variants( $src_url, $basedir ) {
		// Skip if URL is already a WordPress `-WxH.webp` sub-size.
		if ( preg_match( '/-\d+x\d+\.webp$/i', strtok( $src_url, '?' ) ) ) {
			return array(
				'preferred_src' => $src_url,
				'srcset'        => '',
			);
		}

		$disk = $this->resolve_upload_disk_path( $src_url, $basedir );
		if ( '' === $disk || ! file_exists( $disk ) ) {
			return array(
				'preferred_src' => $src_url,
				'srcset'        => '',
			);
		}

		$dir      = dirname( $disk );
		$url_dir  = trailingslashit( dirname( strtok( $src_url, '?' ) ) );
		$filename = pathinfo( $disk, PATHINFO_FILENAME );
		$matches  = glob( $dir . '/' . $filename . '-*x*.webp' );

		if ( empty( $matches ) || ! is_array( $matches ) ) {
			return array(
				'preferred_src' => $src_url,
				'srcset'        => '',
			);
		}

		$variants = array();
		foreach ( $matches as $m_path ) {
			$base = basename( $m_path );
			if ( preg_match( '/^' . preg_quote( $filename, '/' ) . '-(\d+)x(\d+)\.webp$/i', $base, $dm ) ) {
				$w = (int) $dm[1];
				if ( $w >= 300 ) {
					$variants[ $w ] = $url_dir . $base;
				}
			}
		}

		if ( empty( $variants ) ) {
			return array(
				'preferred_src' => $src_url,
				'srcset'        => '',
			);
		}

		ksort( $variants );

		// Choose 768w (or closest between 600w and 1024w) as default card/carousel src.
		$preferred = $src_url;
		foreach ( array( 768, 600, 1024, 1152 ) as $target_w ) {
			if ( isset( $variants[ $target_w ] ) ) {
				$preferred = $variants[ $target_w ];
				break;
			}
		}
		if ( $preferred === $src_url ) {
			foreach ( $variants as $vw => $vu ) {
				if ( $vw >= 600 && $vw <= 1100 ) {
					$preferred = $vu;
					break;
				}
			}
		}

		$full_dims = $this->get_local_image_dimensions( $src_url, $basedir );
		if ( $full_dims[0] > 0 ) {
			$variants[ $full_dims[0] ] = strtok( $src_url, '?' );
		}

		$srcset_parts = array();
		foreach ( $variants as $vw => $vu ) {
			$srcset_parts[] = $vu . ' ' . $vw . 'w';
		}

		return array(
			'preferred_src' => $preferred,
			'srcset'        => implode( ', ', $srcset_parts ),
		);
	}
}

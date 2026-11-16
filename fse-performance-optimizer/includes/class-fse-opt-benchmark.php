<?php
/**
 * Telemetry & Page Benchmark Engine (Before vs After Metrics).
 *
 * Dynamically discovers unique page templates on ANY WordPress/WooCommerce site
 * and measures HTML weight, Inline CSS/JS, External CSS/JS, Blocking JS, Images, Fonts, and TTFB.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSE_Opt_Benchmark {

	/**
	 * Discover representative unique page URLs dynamically for the current WordPress site.
	 *
	 * @return array<string, string> Map of Page Label => Full URL.
	 */
	public function discover_unique_pages() {
		$pages = array(
			'Homepage' => home_url( '/' ),
		);

		// WooCommerce pages if active.
		if ( function_exists( 'wc_get_page_id' ) ) {
			$shop_id = wc_get_page_id( 'shop' );
			if ( $shop_id > 0 && 'publish' === get_post_status( $shop_id ) ) {
				$pages['Shop'] = get_permalink( $shop_id );
			}

			// First non-empty product category.
			$cats = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => true,
					'number'     => 2,
				)
			);
			if ( ! is_wp_error( $cats ) && ! empty( $cats ) ) {
				foreach ( $cats as $c ) {
					if ( 'uncategorized' !== $c->slug ) {
						$pages[ 'Category (' . $c->name . ')' ] = get_term_link( $c );
						break;
					}
				}
			}

			// First published product.
			$prods = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
				)
			);
			if ( ! empty( $prods ) ) {
				$pages[ 'Product (' . wp_trim_words( $prods[0]->post_title, 4, '' ) . ')' ] = get_permalink( $prods[0]->ID );
			}

			foreach ( array( 'cart' => 'Cart', 'checkout' => 'Checkout', 'myaccount' => 'My Account' ) as $wc_key => $label ) {
				$pid = wc_get_page_id( $wc_key );
				if ( $pid > 0 && 'publish' === get_post_status( $pid ) ) {
					$pages[ $label ] = get_permalink( $pid );
				}
			}
		}

		// Other published static pages (up to 4).
		$exclude_ids = array( (int) get_option( 'page_on_front' ) );
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'shop', 'cart', 'checkout', 'myaccount' ) as $k ) {
				$exclude_ids[] = (int) wc_get_page_id( $k );
			}
		}

		$static_pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 4,
				'post__not_in'   => array_filter( $exclude_ids ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		foreach ( $static_pages as $sp ) {
			$pages[ 'Page (' . wp_trim_words( $sp->post_title, 4, '' ) . ')' ] = get_permalink( $sp->ID );
		}

		return $pages;
	}

	/**
	 * Measure all discovered unique pages and return detailed metrics per page + aggregate totals.
	 *
	 * @param bool $bypass_cache Whether to append `?fse_bench=1` to measure uncached generation vs normal cached response.
	 * @return array<string, mixed>
	 */
	public function measure_all_pages( $bypass_cache = false ) {
		$pages   = $this->discover_unique_pages();
		$results = array();

		$totals = array(
			'html_bytes'       => 0,
			'inline_css_bytes' => 0,
			'css_files'        => 0,
			'css_bytes'        => 0,
			'js_files'         => 0,
			'js_bytes'         => 0,
			'blocking_js'      => 0,
			'delayed_js'       => 0,
			'img_bytes'        => 0,
			'missing_dims'     => 0,
			'non_webp'         => 0,
			'total_bytes'      => 0,
			'avg_ttfb_ms'      => 0,
		);

		$asset_size_cache = array();

		foreach ( $pages as $label => $url ) {
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}
			$target_url = $bypass_cache ? add_query_arg( 'fse_raw', '1', $url ) : $url;

			$t0   = microtime( true );
			$resp = wp_remote_get(
				$target_url,
				array(
					'timeout'   => 15,
					'sslverify' => false,
					'headers'   => array(
						'Accept-Encoding' => 'identity',
						'User-Agent'      => 'FSE-Performance-Optimizer-Benchmark/1.0',
					),
				)
			);
			$ttfb = round( ( microtime( true ) - $t0 ) * 1000, 1 );

			if ( is_wp_error( $resp ) ) {
				continue;
			}

			$html         = (string) wp_remote_retrieve_body( $resp );
			$cache_header = (string) wp_remote_retrieve_header( $resp, 'x-fse-cache' );
			$html_bytes   = strlen( $html );

			// Inline CSS & JS bytes.
			$inline_css = 0;
			if ( preg_match_all( '#<style\b[^>]*>(.*?)</style>#is', $html, $sm ) ) {
				foreach ( $sm[1] as $s_body ) {
					$inline_css += strlen( $s_body );
				}
			}

			// External CSS files.
			$css_urls = array();
			if ( preg_match_all( '#<link\b[^>]+rel=["\']stylesheet["\'][^>]+href=["\']([^"\']+)["\']#i', $html, $cm1 ) ) {
				$css_urls = array_merge( $css_urls, $cm1[1] );
			}
			if ( preg_match_all( '#<link\b[^>]+href=["\']([^"\']+\.css[^"\']*)["\'][^>]+rel=["\']stylesheet["\']#i', $html, $cm2 ) ) {
				$css_urls = array_merge( $css_urls, $cm2[1] );
			}
			$css_urls  = array_values( array_unique( $css_urls ) );
			$css_bytes = 0;
			foreach ( $css_urls as $cu ) {
				$css_bytes += $this->get_local_asset_filesize( $cu, $asset_size_cache );
			}

			// External JS files (active on initial load vs delayed).
			$js_urls     = array();
			$blocking_js = 0;
			$delayed_js  = 0;

			if ( preg_match_all( '#<script\b([^>]*)>#i', $html, $jm ) ) {
				foreach ( $jm[1] as $attrs ) {
					if ( preg_match( '#\btype\s*=\s*["\']?fse-delay["\']?#i', $attrs ) ) {
						if ( false !== stripos( $attrs, 'data-fse-src=' ) ) {
							++$delayed_js;
						}
						continue;
					}
					if ( preg_match( '#\bsrc\s*=\s*["\']([^"\']+)["\']#i', $attrs, $src_m ) ) {
						$src_val = $src_m[1];
						if ( 0 === strpos( $src_val, 'data:' ) ) {
							continue;
						}
						$js_urls[] = $src_val;
						if ( ! preg_match( '#\b(defer|async)\b#i', $attrs ) && ! preg_match( '#\btype\s*=\s*["\']?module["\']?#i', $attrs ) ) {
							++$blocking_js;
						}
					}
				}
			}
			$js_urls  = array_values( array_unique( $js_urls ) );
			$js_bytes = 0;
			foreach ( $js_urls as $ju ) {
				$js_bytes += $this->get_local_asset_filesize( $ju, $asset_size_cache );
			}

			// Images.
			$img_srcs     = array();
			$img_count    = 0;
			$lazy_count   = 0;
			$missing_dims = 0;
			$non_webp     = 0;

			if ( preg_match_all( '#<img\b([^>]*)>#is', $html, $im ) ) {
				$img_count = count( $im[1] );
				foreach ( $im[1] as $ia ) {
					if ( preg_match( '#\bloading\s*=\s*["\']?lazy#i', $ia ) ) {
						++$lazy_count;
					}
					if ( ! preg_match( '#\bwidth\s*=#i', $ia ) || ! preg_match( '#\bheight\s*=#i', $ia ) ) {
						++$missing_dims;
					}
					if ( preg_match( '#(?<![a-zA-Z0-9_-])src\s*=\s*["\']([^"\']+)["\']#i', $ia, $ism ) ) {
						$img_srcs[] = $ism[1];
					}
				}
			}
			$img_srcs  = array_values( array_unique( $img_srcs ) );
			$img_bytes = 0;
			foreach ( $img_srcs as $iu ) {
				$img_bytes += $this->get_local_asset_filesize( $iu, $asset_size_cache );
				$clean_ext  = strtolower( (string) pathinfo( strtok( $iu, '?' ), PATHINFO_EXTENSION ) );
				if ( in_array( $clean_ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
					++$non_webp;
				}
			}

			// Font-face rules count & woff2 ratio.
			$font_faces  = preg_match_all( '/@font-face\s*\{/i', $html );
			$woff2_faces = preg_match_all( '/@font-face\s*\{[^}]*\.woff2/i', $html );
			$swap_faces  = preg_match_all( '/@font-face\s*\{[^}]*font-display\s*:\s*swap/i', $html );

			$page_total_bytes = $html_bytes + $css_bytes + $js_bytes + $img_bytes;

			$row = array(
				'url'              => $url,
				'ttfb_ms'          => $ttfb,
				'cache_status'     => '' !== $cache_header ? $cache_header : 'BYPASS',
				'html_bytes'       => $html_bytes,
				'inline_css_bytes' => $inline_css,
				'css_files'        => count( $css_urls ),
				'css_bytes'        => $css_bytes,
				'js_files'         => count( $js_urls ),
				'js_bytes'         => $js_bytes,
				'blocking_js'      => $blocking_js,
				'delayed_js'       => $delayed_js,
				'img_count'        => $img_count,
				'img_unique'       => count( $img_srcs ),
				'img_bytes'        => $img_bytes,
				'lazy_count'       => $lazy_count,
				'missing_dims'     => $missing_dims,
				'non_webp'         => $non_webp,
				'font_faces'       => (int) $font_faces,
				'woff2_faces'      => (int) $woff2_faces,
				'swap_faces'       => (int) $swap_faces,
				'total_bytes'      => $page_total_bytes,
			);

			$results[ $label ] = $row;

			$totals['html_bytes']       += $html_bytes;
			$totals['inline_css_bytes'] += $inline_css;
			$totals['css_files']        += count( $css_urls );
			$totals['css_bytes']        += $css_bytes;
			$totals['js_files']         += count( $js_urls );
			$totals['js_bytes']         += $js_bytes;
			$totals['blocking_js']      += $blocking_js;
			$totals['delayed_js']       += $delayed_js;
			$totals['img_bytes']        += $img_bytes;
			$totals['missing_dims']     += $missing_dims;
			$totals['non_webp']         += $non_webp;
			$totals['total_bytes']      += $page_total_bytes;
			$totals['avg_ttfb_ms']      += $ttfb;
		}

		$page_cnt = count( $results );
		if ( $page_cnt > 0 ) {
			$totals['avg_ttfb_ms'] = round( $totals['avg_ttfb_ms'] / $page_cnt, 1 );
		}

		return array(
			'timestamp' => time(),
			'pages'     => $results,
			'totals'    => $totals,
		);
	}

	/**
	 * Resolve local WordPress file path from asset URL and return its byte size on disk.
	 *
	 * @param string $url   Asset URL.
	 * @param array  $cache Size cache map.
	 * @return int
	 */
	private function get_local_asset_filesize( $url, &$cache ) {
		$clean = strtok( $url, '?' );
		if ( isset( $cache[ $clean ] ) ) {
			return $cache[ $clean ];
		}

		$size = 0;
		foreach ( array( '/wp-content/', '/wp-includes/' ) as $marker ) {
			$pos = strpos( $clean, $marker );
			if ( false !== $pos ) {
				$rel  = substr( $clean, $pos );
				$disk = ABSPATH . ltrim( rawurldecode( $rel ), '/' );
				if ( file_exists( $disk ) ) {
					$size = (int) @filesize( $disk );
				}
				break;
			}
		}

		$cache[ $clean ] = $size;
		return $size;
	}

	/**
	 * Aggregate Media optimization stats across all attachments.
	 *
	 * @return array<string, int>
	 */
	public function get_media_summary() {
		global $wpdb;

		$total_attachments = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg', 'image/png', 'image/webp')"
		);

		$webp_attachments = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type = 'attachment' AND post_mime_type = 'image/webp'"
		);

		$rows = $wpdb->get_col(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_fse_opt_stats'"
		);

		$optimized_count = 0;
		$before_bytes    = 0;
		$after_bytes     = 0;
		$icc_count       = 0;

		if ( is_array( $rows ) ) {
			foreach ( $rows as $raw ) {
				$st = maybe_unserialize( $raw );
				if ( is_array( $st ) && ! empty( $st['before_bytes'] ) ) {
					++$optimized_count;
					$before_bytes += (int) $st['before_bytes'];
					$after_bytes  += (int) ( $st['after_bytes'] ?? 0 );
					if ( ! empty( $st['icc_preserved'] ) ) {
						++$icc_count;
					}
				}
			}
		}

		$extra_freed   = (int) get_option( 'fse_opt_extra_freed_bytes', 0 );
		$before_bytes += $extra_freed;

		return array(
			'total_attachments' => $total_attachments,
			'webp_attachments'  => $webp_attachments,
			'optimized_count'   => $optimized_count,
			'before_bytes'      => $before_bytes,
			'after_bytes'       => $after_bytes,
			'icc_count'         => $icc_count,
		);
	}
}

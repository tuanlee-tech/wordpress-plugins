<?php
/**
 * Module A: 100% Share-Hosting Ready Image Optimizer (Q=100 WebP + Pure-PHP ICC Profile Preservation).
 *
 * Works with either PHP Imagick OR standard PHP GD + Pure-PHP binary RIFF/VP8X ICCP chunk injection.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSE_Opt_ICC_WebP {

	/**
	 * Plugin settings array.
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
	 * Register WordPress upload and image generation hooks.
	 */
	public function register_hooks() {
		if ( empty( $this->settings['enable_image_opt'] ) ) {
			return;
		}

		// Force high quality (default 100) for all WordPress sub-sizes.
		add_filter( 'wp_editor_set_quality', array( $this, 'filter_editor_quality' ), 999 );
		add_filter( 'jpeg_quality', array( $this, 'filter_editor_quality' ), 999 );

		// Output WebP for generated sub-sizes.
		add_filter( 'image_editor_output_format', array( $this, 'filter_output_format' ), 999 );

		// Intercept uploaded file before metadata generation to resize & convert to WebP Q=100 with ICC.
		add_filter( 'wp_handle_upload', array( $this, 'handle_upload' ), 20 );

		// Inject ICC profile into all generated WebP sub-sizes after WordPress creates them.
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'handle_attachment_metadata' ), 20, 2 );
	}

	/**
	 * Enforce configured image quality (default 100; 94 when optional lossy saving mode is enabled).
	 *
	 * @return int
	 */
	public function filter_editor_quality() {
		return $this->get_effective_quality();
	}

	/**
	 * Resolve effective WebP encoding quality from settings.
	 *
	 * @return int
	 */
	public function get_effective_quality() {
		if ( ! empty( $this->settings['enable_lossy_saving'] ) ) {
			return 94;
		}
		return max( 80, min( 100, (int) ( $this->settings['image_quality'] ?? 100 ) ) );
	}

	/**
	 * Map JPEG/PNG sub-sizes to WebP.
	 *
	 * @param array $formats Map of mime types.
	 * @return array
	 */
	public function filter_output_format( $formats ) {
		$formats['image/jpeg'] = 'image/webp';
		$formats['image/png']  = 'image/webp';
		return $formats;
	}

	/**
	 * Process newly uploaded image at upload time.
	 *
	 * @param array $upload Upload array (`file`, `url`, `type`).
	 * @return array
	 */
	public function handle_upload( $upload ) {
		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) || empty( $upload['type'] ) ) {
			return $upload;
		}

		$allowed = array( 'image/jpeg', 'image/png', 'image/webp' );
		if ( ! in_array( $upload['type'], $allowed, true ) || ! file_exists( $upload['file'] ) ) {
			return $upload;
		}

		$src_file    = $upload['file'];
		$before_size = (int) @filesize( $src_file );
		$before_dims = @getimagesize( $src_file );

		$max_edge = max( 600, (int) ( $this->settings['max_image_edge'] ?? 1920 ) );
		$quality  = $this->get_effective_quality();
		$keep_icc = ! empty( $this->settings['preserve_icc'] );

		$path_info = pathinfo( $src_file );
		$webp_file = $path_info['dirname'] . '/' . $path_info['filename'] . '.webp';
		if ( strtolower( $path_info['extension'] ?? '' ) !== 'webp' && file_exists( $webp_file ) ) {
			$webp_file = $path_info['dirname'] . '/' . wp_unique_filename( $path_info['dirname'], $path_info['filename'] . '.webp' );
		}

		$result = $this->convert_and_resize_to_webp( $src_file, $webp_file, $max_edge, $quality, $keep_icc );
		if ( $result['success'] && file_exists( $webp_file ) ) {
			$was_resized = ( isset( $before_dims[0], $before_dims[1] ) && ( $result['width'] < (int) $before_dims[0] || $result['height'] < (int) $before_dims[1] ) );
			$after_size  = (int) @filesize( $webp_file );

			// Never-Inflate Guard: if image was NOT resized and WebP at Q=100 is larger than original,
			// keep the original file untouched (100% original quality, zero bloat).
			if ( ! $was_resized && $after_size >= $before_size && $webp_file !== $src_file ) {
				@unlink( $webp_file );
				return $upload;
			}

			if ( $webp_file !== $src_file && ! empty( $this->settings['replace_original'] ) ) {
				@unlink( $src_file );
			}
			$upload['file'] = $webp_file;
			$upload['url']  = trailingslashit( dirname( $upload['url'] ) ) . basename( $webp_file );
			$upload['type'] = 'image/webp';

			// Store temporary stats keyed by file path so wp_generate_attachment_metadata can attach them.
			$GLOBALS['fse_opt_last_upload_stats'][ $webp_file ] = array(
				'before_bytes'  => $before_size,
				'after_bytes'   => $after_size,
				'before_width'  => isset( $before_dims[0] ) ? (int) $before_dims[0] : 0,
				'before_height' => isset( $before_dims[1] ) ? (int) $before_dims[1] : 0,
				'after_width'   => $result['width'],
				'after_height'  => $result['height'],
				'icc_preserved' => $result['icc_preserved'],
				'icc_bytes'     => $result['icc_raw'] ?? '',
				'engine'        => $result['engine'],
				'timestamp'     => time(),
			);
		}

		return $upload;
	}

	/**
	 * Ensure ICC profile is injected into all generated WebP sub-sizes and save stats on attachment.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array
	 */
	public function handle_attachment_metadata( $metadata, $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return $metadata;
		}

		$icc_bytes = '';
		if ( ! empty( $GLOBALS['fse_opt_last_upload_stats'][ $file ] ) ) {
			$st        = $GLOBALS['fse_opt_last_upload_stats'][ $file ];
			$icc_bytes = $st['icc_bytes'];
			unset( $st['icc_bytes'] );
			update_post_meta( $attachment_id, '_fse_opt_stats', $st );
		} else {
			$icc_bytes = $this->extract_icc_profile( $file );
		}

		if ( '' !== $icc_bytes && ! empty( $this->settings['preserve_icc'] ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$base_dir = dirname( $file );
			foreach ( $metadata['sizes'] as $size_data ) {
				if ( ! empty( $size_data['file'] ) && str_ends_with( strtolower( $size_data['file'] ), '.webp' ) ) {
					$sub_path = $base_dir . '/' . $size_data['file'];
					if ( file_exists( $sub_path ) ) {
						$this->inject_webp_icc_profile( $sub_path, $icc_bytes );
					}
				}
			}
		}

		return $metadata;
	}

	/**
	 * Core converter: Resize to max_edge, convert to WebP at Q=100, and preserve ICC profile
	 * using either Imagick or PHP GD + pure-PHP RIFF VP8X/ICCP chunk injection.
	 *
	 * @param string $src_path  Source image path.
	 * @param string $dest_path Destination `.webp` path.
	 * @param int    $max_edge  Max width/height in pixels.
	 * @param int    $quality   WebP quality (80-100).
	 * @param bool   $keep_icc  Whether to preserve ICC profile.
	 * @return array{success:bool, width:int, height:int, icc_preserved:bool, icc_raw:string, engine:string, error?:string}
	 */
	public function convert_and_resize_to_webp( $src_path, $dest_path, $max_edge = 1920, $quality = 100, $keep_icc = true ) {
		$icc_raw = $keep_icc ? $this->extract_icc_profile( $src_path ) : '';

		// Try PHP GD first or Imagick (both supported; ensure pure-PHP ICC injection always verifies ICCP chunk!).
		if ( class_exists( 'Imagick' ) ) {
			try {
				$im      = new Imagick( $src_path );
				$formats = $im->queryFormats( 'WEBP' );
				if ( ! empty( $formats ) ) {
					if ( method_exists( $im, 'autoOrient' ) ) {
						$im->autoOrient();
					}
					$w = $im->getImageWidth();
					$h = $im->getImageHeight();

					if ( $w > $max_edge || $h > $max_edge ) {
						if ( $w >= $h ) {
							$new_w = $max_edge;
							$new_h = max( 1, (int) round( $h * ( $max_edge / $w ) ) );
						} else {
							$new_h = $max_edge;
							$new_w = max( 1, (int) round( $w * ( $max_edge / $h ) ) );
						}
						$im->resizeImage( $new_w, $new_h, Imagick::FILTER_LANCZOS, 1 );
						$w = $new_w;
						$h = $new_h;
					}

					$im->setImageFormat( 'webp' );
					// Explicitly disable WebP lossless (VP8L) so Q=100 uses lossy VP8 instead of inflating JPEGs 2-3x.
					$im->setOption( 'webp:lossless', 'false' );
					$im->setImageCompressionQuality( $quality );
					$im->setOption( 'webp:method', '4' );
					if ( $quality >= 94 ) {
						$im->setOption( 'webp:use-sharp-yuv', 'true' );
					}

					$tmp_dest = $dest_path . '.tmp.webp';
					$im->writeImage( $tmp_dest );
					$im->clear();
					$im->destroy();

					if ( file_exists( $tmp_dest ) ) {
						rename( $tmp_dest, $dest_path );
						$icc_ok = false;
						if ( '' !== $icc_raw ) {
							$icc_ok = $this->inject_webp_icc_profile( $dest_path, $icc_raw );
						}
						return array(
							'success'       => true,
							'width'         => $w,
							'height'        => $h,
							'icc_preserved' => $icc_ok,
							'icc_raw'       => $icc_raw,
							'engine'        => 'imagick+riff-icc',
						);
					}
				}
			} catch ( Exception $e ) {
				// Fall through to PHP GD engine.
			}
		}

		// Standard Share-Hosting Fallback: PHP GD + Pure-PHP RIFF VP8X/ICCP Injector.
		return $this->convert_via_gd( $src_path, $dest_path, $max_edge, $quality, $icc_raw );
	}

	/**
	 * Convert and resize image using standard PHP GD, then inject ICC profile in pure PHP.
	 *
	 * @param string $src_path  Source file.
	 * @param string $dest_path Target WebP file.
	 * @param int    $max_edge  Max dimension.
	 * @param int    $quality   WebP quality.
	 * @param string $icc_raw   Raw ICC profile bytes.
	 * @return array
	 */
	private function convert_via_gd( $src_path, $dest_path, $max_edge, $quality, $icc_raw ) {
		if ( ! function_exists( 'imagewebp' ) ) {
			return array(
				'success'       => false,
				'width'         => 0,
				'height'        => 0,
				'icc_preserved' => false,
				'icc_raw'       => '',
				'engine'        => 'none',
				'error'         => 'PHP GD imagewebp() không khả dụng.',
			);
		}

		$info = @getimagesize( $src_path );
		if ( ! $info || empty( $info['mime'] ) ) {
			return array(
				'success'       => false,
				'width'         => 0,
				'height'        => 0,
				'icc_preserved' => false,
				'icc_raw'       => '',
				'engine'        => 'gd',
				'error'         => 'Không đọc được định dạng ảnh.',
			);
		}

		$mime = $info['mime'];
		$src  = false;
		if ( 'image/jpeg' === $mime ) {
			$src = @imagecreatefromjpeg( $src_path );
			$src = $this->apply_exif_orientation_gd( $src, $src_path );
		} elseif ( 'image/png' === $mime ) {
			$src = @imagecreatefrompng( $src_path );
		} elseif ( 'image/webp' === $mime ) {
			$src = @imagecreatefromwebp( $src_path );
		}

		if ( ! $src ) {
			return array(
				'success'       => false,
				'width'         => 0,
				'height'        => 0,
				'icc_preserved' => false,
				'icc_raw'       => '',
				'engine'        => 'gd',
				'error'         => 'GD không thể mở ảnh nguồn.',
			);
		}

		imagepalettetotruecolor( $src );
		imagealphablending( $src, true );
		imagesavealpha( $src, true );

		$w = imagesx( $src );
		$h = imagesy( $src );

		if ( $w > $max_edge || $h > $max_edge ) {
			if ( $w >= $h ) {
				$new_w = $max_edge;
				$new_h = max( 1, (int) round( $h * ( $max_edge / $w ) ) );
			} else {
				$new_h = $max_edge;
				$new_w = max( 1, (int) round( $w * ( $max_edge / $h ) ) );
			}

			$dst = imagecreatetruecolor( $new_w, $new_h );
			imagealphablending( $dst, false );
			imagesavealpha( $dst, true );
			$transparent = imagecolorallocatealpha( $dst, 0, 0, 0, 127 );
			imagefilledrectangle( $dst, 0, 0, $new_w, $new_h, $transparent );
			imagecopyresampled( $dst, $src, 0, 0, 0, 0, $new_w, $new_h, $w, $h );
			imagedestroy( $src );
			$src = $dst;
			$w   = $new_w;
			$h   = $new_h;
		}

		$tmp_dest = $dest_path . '.tmp.webp';
		$saved    = @imagewebp( $src, $tmp_dest, $quality );
		imagedestroy( $src );

		if ( ! $saved || ! file_exists( $tmp_dest ) ) {
			@unlink( $tmp_dest );
			return array(
				'success'       => false,
				'width'         => 0,
				'height'        => 0,
				'icc_preserved' => false,
				'icc_raw'       => '',
				'engine'        => 'gd',
				'error'         => 'GD không thể ghi file WebP.',
			);
		}

		rename( $tmp_dest, $dest_path );

		$icc_ok = false;
		if ( '' !== $icc_raw ) {
			$icc_ok = $this->inject_webp_icc_profile( $dest_path, $icc_raw );
		}

		return array(
			'success'       => true,
			'width'         => $w,
			'height'        => $h,
			'icc_preserved' => $icc_ok,
			'icc_raw'       => $icc_raw,
			'engine'        => 'gd+riff-icc',
		);
	}

	/**
	 * Auto-orient GD image resource based on JPEG EXIF Orientation.
	 *
	 * @param resource|GdImage|false $img      GD image.
	 * @param string                 $src_path File path.
	 * @return resource|GdImage|false
	 */
	private function apply_exif_orientation_gd( $img, $src_path ) {
		if ( ! $img || ! function_exists( 'exif_read_data' ) ) {
			return $img;
		}
		$exif = @exif_read_data( $src_path );
		if ( empty( $exif['Orientation'] ) ) {
			return $img;
		}
		switch ( (int) $exif['Orientation'] ) {
			case 3:
				return imagerotate( $img, 180, 0 );
			case 6:
				return imagerotate( $img, -90, 0 );
			case 8:
				return imagerotate( $img, 90, 0 );
		}
		return $img;
	}

	/**
	 * Extract raw ICC_PROFILE binary bytes from JPEG (APP2), PNG (iCCP), or WebP (ICCP) in pure PHP.
	 *
	 * @param string $file_path Image file path.
	 * @return string Raw ICC profile bytes, or empty string if none.
	 */
	public function extract_icc_profile( $file_path ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return '';
		}

		$data = @file_get_contents( $file_path );
		if ( false === $data || strlen( $data ) < 16 ) {
			return '';
		}

		// 1. JPEG (starts with \xFF\xD8)
		if ( "\xFF\xD8" === substr( $data, 0, 2 ) ) {
			return $this->extract_icc_from_jpeg_bytes( $data );
		}

		// 2. PNG (starts with \x89PNG\r\n\x1a\n)
		if ( "\x89PNG\r\n\x1a\n" === substr( $data, 0, 8 ) ) {
			return $this->extract_icc_from_png_bytes( $data );
		}

		// 3. WebP (RIFF....WEBP)
		if ( 'RIFF' === substr( $data, 0, 4 ) && 'WEBP' === substr( $data, 8, 4 ) ) {
			return $this->extract_icc_from_webp_bytes( $data );
		}

		return '';
	}

	/**
	 * Extract multi-chunk ICC_PROFILE from JPEG APP2 (\xFF\xE2) segments.
	 *
	 * @param string $data Raw JPEG bytes.
	 * @return string
	 */
	private function extract_icc_from_jpeg_bytes( $data ) {
		$len    = strlen( $data );
		$pos    = 2;
		$chunks = array();

		while ( $pos + 4 <= $len ) {
			if ( "\xFF" !== $data[ $pos ] ) {
				++$pos;
				continue;
			}
			$marker = ord( $data[ $pos + 1 ] );
			// SOS (0xDA) or EOI (0xD9) ends metadata headers.
			if ( 0xDA === $marker || 0xD9 === $marker ) {
				break;
			}
			// Standalone markers without length.
			if ( 0xD8 === $marker || ( $marker >= 0xD0 && $marker <= 0xD7 ) || 0x01 === $marker ) {
				$pos += 2;
				continue;
			}

			$seg_len = unpack( 'n', substr( $data, $pos + 2, 2 ) )[1];
			if ( $seg_len < 2 || $pos + 2 + $seg_len > $len ) {
				break;
			}

			// APP2 = 0xE2; header starts with "ICC_PROFILE\0" (12 bytes) + seq_no (1B) + total_chunks (1B).
			if ( 0xE2 === $marker && $seg_len > 16 ) {
				$payload = substr( $data, $pos + 4, $seg_len - 2 );
				if ( 0 === strpos( $payload, "ICC_PROFILE\x00" ) ) {
					$seq            = ord( $payload[12] );
					$chunks[ $seq ] = substr( $payload, 14 );
				}
			}

			$pos += 2 + $seg_len;
		}

		if ( empty( $chunks ) ) {
			return '';
		}
		ksort( $chunks );
		return implode( '', $chunks );
	}

	/**
	 * Extract and decompress iCCP chunk from PNG bytes.
	 *
	 * @param string $data Raw PNG bytes.
	 * @return string
	 */
	private function extract_icc_from_png_bytes( $data ) {
		$len = strlen( $data );
		$pos = 8;

		while ( $pos + 12 <= $len ) {
			$chunk_len  = unpack( 'N', substr( $data, $pos, 4 ) )[1];
			$chunk_type = substr( $data, $pos + 4, 4 );
			if ( $pos + 12 + $chunk_len > $len ) {
				break;
			}
			if ( 'iCCP' === $chunk_type && $chunk_len > 4 ) {
				$payload  = substr( $data, $pos + 8, $chunk_len );
				$null_pos = strpos( $payload, "\x00" );
				if ( false !== $null_pos && $null_pos + 2 < strlen( $payload ) ) {
					$compressed = substr( $payload, $null_pos + 2 );
					$icc        = @gzuncompress( $compressed );
					if ( is_string( $icc ) && '' !== $icc ) {
						return $icc;
					}
				}
			}
			if ( 'IEND' === $chunk_type ) {
				break;
			}
			$pos += 12 + $chunk_len;
		}

		return '';
	}

	/**
	 * Extract ICCP chunk from WebP RIFF bytes.
	 *
	 * @param string $data Raw WebP bytes.
	 * @return string
	 */
	private function extract_icc_from_webp_bytes( $data ) {
		$len = strlen( $data );
		$pos = 12;

		while ( $pos + 8 <= $len ) {
			$cid = substr( $data, $pos, 4 );
			$csz = unpack( 'V', substr( $data, $pos + 4, 4 ) )[1];
			if ( $pos + 8 + $csz > $len ) {
				break;
			}
			if ( 'ICCP' === $cid ) {
				return substr( $data, $pos + 8, $csz );
			}
			$pos += 8 + $csz + ( $csz % 2 );
		}

		return '';
	}

	/**
	 * Inject raw ICC profile into a WebP file on disk using Extended WebP (VP8X + ICCP) RIFF container format.
	 *
	 * @param string $webp_path Path to WebP file.
	 * @param string $icc_bytes Raw ICC profile binary.
	 * @return bool True if ICCP chunk is present/injected.
	 */
	public function inject_webp_icc_profile( $webp_path, $icc_bytes ) {
		if ( '' === $icc_bytes || ! file_exists( $webp_path ) ) {
			return false;
		}

		$data = @file_get_contents( $webp_path );
		if ( false === $data || strlen( $data ) < 20 || 'RIFF' !== substr( $data, 0, 4 ) || 'WEBP' !== substr( $data, 8, 4 ) ) {
			return false;
		}

		// Parse existing RIFF chunks.
		$len        = strlen( $data );
		$pos        = 12;
		$chunks     = array();
		$has_alpha  = false;
		$vp8x_flags = 0;

		while ( $pos + 8 <= $len ) {
			$cid     = substr( $data, $pos, 4 );
			$csz     = unpack( 'V', substr( $data, $pos + 4, 4 ) )[1];
			$payload = substr( $data, $pos + 8, $csz );

			if ( 'VP8X' === $cid && strlen( $payload ) >= 10 ) {
				$vp8x_flags = ord( $payload[0] );
			} elseif ( 'ICCP' === $cid ) {
				// Skip old ICCP chunk; we will write our exact $icc_bytes.
			} else {
				if ( 'ALPH' === $cid ) {
					$has_alpha = true;
				} elseif ( 'VP8L' === $cid && strlen( $payload ) >= 5 ) {
					// Bit 28 of VP8L header indicates alpha_is_used.
					$b4 = ord( $payload[4] );
					if ( ( $b4 & 0x10 ) !== 0 ) {
						$has_alpha = true;
					}
				}
				$chunks[] = array(
					'id'      => $cid,
					'payload' => $payload,
				);
			}

			$pos += 8 + $csz + ( $csz % 2 );
		}

		$dims = @getimagesize( $webp_path );
		if ( ! $dims || empty( $dims[0] ) || empty( $dims[1] ) ) {
			return false;
		}

		$width_minus_1  = max( 0, (int) $dims[0] - 1 );
		$height_minus_1 = max( 0, (int) $dims[1] - 1 );

		// Set bit 5 (0x20 = ICCP) and bit 4 (0x10 = Alpha if present).
		$flags = ( $vp8x_flags | 0x20 ) | ( $has_alpha ? 0x10 : 0x00 );

		$w24 = substr( pack( 'V', $width_minus_1 ), 0, 3 );
		$h24 = substr( pack( 'V', $height_minus_1 ), 0, 3 );

		$vp8x_payload = chr( $flags ) . "\x00\x00\x00" . $w24 . $h24;
		$vp8x_chunk   = 'VP8X' . pack( 'V', 10 ) . $vp8x_payload;

		$icc_len    = strlen( $icc_bytes );
		$iccp_chunk = 'ICCP' . pack( 'V', $icc_len ) . $icc_bytes . ( ( $icc_len % 2 ) ? "\x00" : '' );

		$body = 'WEBP' . $vp8x_chunk . $iccp_chunk;
		foreach ( $chunks as $ch ) {
			$clen  = strlen( $ch['payload'] );
			$body .= $ch['id'] . pack( 'V', $clen ) . $ch['payload'] . ( ( $clen % 2 ) ? "\x00" : '' );
		}

		$riff = 'RIFF' . pack( 'V', strlen( $body ) ) . $body;
		return false !== @file_put_contents( $webp_path, $riff );
	}

	/**
	 * Optimize an existing WordPress attachment (main image + all sub-sizes) to WebP Q=100 with ICC,
	 * and return detailed before/after telemetry + URL replacement map.
	 *
	 * @param int $attachment_id Attachment Post ID.
	 * @return array<string, mixed>
	 */
	public function optimize_existing_attachment( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return array(
				'success' => false,
				'error'   => 'File không tồn tại: ' . (string) $file,
			);
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			return array(
				'success' => false,
				'skipped' => true,
				'reason'  => 'Bỏ qua mime: ' . (string) $mime,
			);
		}

		$existing_stats = get_post_meta( $attachment_id, '_fse_opt_stats', true );
		$base_dir       = dirname( $file );
		$meta           = wp_get_attachment_metadata( $attachment_id );

		// Calculate total disk usage BEFORE (main file + original_image if -scaled + all sub-size files).
		$before_total_bytes = (int) @filesize( $file );
		if ( ! empty( $meta['original_image'] ) && file_exists( $base_dir . '/' . $meta['original_image'] ) && ( $base_dir . '/' . $meta['original_image'] ) !== $file ) {
			$before_total_bytes += (int) @filesize( $base_dir . '/' . $meta['original_image'] );
		}
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $sz ) {
				if ( ! empty( $sz['file'] ) && file_exists( $base_dir . '/' . $sz['file'] ) ) {
					$before_total_bytes += (int) @filesize( $base_dir . '/' . $sz['file'] );
				}
			}
		}

		$before_dims = @getimagesize( $file );
		$before_w    = isset( $before_dims[0] ) ? (int) $before_dims[0] : 0;
		$before_h    = isset( $before_dims[1] ) ? (int) $before_dims[1] : 0;

		if ( is_array( $existing_stats ) && ! empty( $existing_stats['before_bytes'] ) ) {
			$before_total_bytes = (int) $existing_stats['before_bytes'];
			$before_w           = (int) ( $existing_stats['before_width'] ?? $before_w );
			$before_h           = (int) ( $existing_stats['before_height'] ?? $before_h );
		}

		$max_edge = max( 600, (int) ( $this->settings['max_image_edge'] ?? 1920 ) );
		$quality  = $this->get_effective_quality();
		$keep_icc = ! empty( $this->settings['preserve_icc'] );

		$path_info    = pathinfo( $file );
		$old_filename = $path_info['basename'];
		$new_filename = $path_info['filename'] . '.webp';
		$webp_path    = $base_dir . '/' . $new_filename;
		$src_main_sz  = (int) @filesize( $file );
		$tmp_main_out = $base_dir . '/.fse_tmp_' . $new_filename;

		$res = $this->convert_and_resize_to_webp( $file, $tmp_main_out, $max_edge, $quality, $keep_icc );
		if ( ! $res['success'] || ! file_exists( $tmp_main_out ) ) {
			@unlink( $tmp_main_out );
			return array(
				'success' => false,
				'error'   => $res['error'] ?? 'Lỗi chuyển đổi WebP',
			);
		}

		$icc_raw          = $res['icc_raw'] ?? '';
		$url_replacements = array();
		$upload_dir       = wp_upload_dir();
		$rel_dir          = trim( str_replace( $upload_dir['basedir'], '', $base_dir ), '/' );
		$rel_prefix       = '' !== $rel_dir ? '/wp-content/uploads/' . $rel_dir . '/' : '/wp-content/uploads/';

		$was_resized = ( $before_w > 0 && $before_h > 0 ) && ( $res['width'] < $before_w || $res['height'] < $before_h );
		$new_main_sz = (int) @filesize( $tmp_main_out );

		// Never-Inflate Guard:
		// If image was NOT resized and new WebP is larger than source file, keep source file untouched
		// (unless source itself is a bloated VP8L lossless WebP that is shrinking).
		$use_new_main = $was_resized || ( $new_main_sz < $src_main_sz );

		$files_to_cleanup = array();
		$final_main_path  = $file;
		$final_filename   = $old_filename;
		$final_mime       = $mime;

		if ( $use_new_main ) {
			rename( $tmp_main_out, $webp_path );
			$final_main_path = $webp_path;
			$final_filename  = $new_filename;
			$final_mime      = 'image/webp';
			if ( $old_filename !== $new_filename ) {
				$url_replacements[ $rel_prefix . $old_filename ] = $rel_prefix . $new_filename;
				$files_to_cleanup[]                              = $file;
			}
		} else {
			@unlink( $tmp_main_out );
		}

		if ( ! empty( $meta['original_image'] ) ) {
			$orig_full = $base_dir . '/' . $meta['original_image'];
			if ( file_exists( $orig_full ) && $orig_full !== $final_main_path ) {
				$files_to_cleanup[]                                         = $orig_full;
				$url_replacements[ $rel_prefix . $meta['original_image'] ] = $rel_prefix . $final_filename;
			}
			unset( $meta['original_image'] );
		}

		if ( ! is_array( $meta ) ) {
			$meta = array();
		}
		$meta['width']    = $use_new_main ? $res['width'] : $before_w;
		$meta['height']   = $use_new_main ? $res['height'] : $before_h;
		$meta['filesize'] = (int) @filesize( $final_main_path );
		if ( ! empty( $meta['file'] ) ) {
			$meta['file'] = ( '' !== $rel_dir ? $rel_dir . '/' : '' ) . $final_filename;
		}

		$after_total_bytes = $meta['filesize'];

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size_key => $sz ) {
				if ( empty( $sz['file'] ) ) {
					continue;
				}
				$sub_old_name = $sz['file'];
				$sub_old_path = $base_dir . '/' . $sub_old_name;
				$sub_w        = (int) ( $sz['width'] ?? 0 );
				$sub_h        = (int) ( $sz['height'] ?? 0 );

				// Drop redundant sub-sizes larger than max_edge (e.g. 1536x2048 when max_edge is 1920).
				if ( $sub_w > $max_edge || $sub_h > $max_edge ) {
					$url_replacements[ $rel_prefix . $sub_old_name ] = $rel_prefix . $final_filename;
					if ( file_exists( $sub_old_path ) ) {
						$files_to_cleanup[] = $sub_old_path;
					}
					unset( $meta['sizes'][ $size_key ] );
					continue;
				}

				if ( ! file_exists( $sub_old_path ) ) {
					continue;
				}

				$sub_old_sz   = (int) @filesize( $sub_old_path );
				$sub_pi       = pathinfo( $sub_old_name );
				$sub_new_name = $sub_pi['filename'] . '.webp';
				$sub_new_path = $base_dir . '/' . $sub_new_name;
				$sub_tmp_path = $base_dir . '/.fse_tmp_' . $sub_new_name;

				$sub_edge = max( $sub_w, $sub_h, 300 );
				$sub_res  = $this->convert_and_resize_to_webp( $sub_old_path, $sub_tmp_path, $sub_edge, $quality, $keep_icc );

				if ( $sub_res['success'] && file_exists( $sub_tmp_path ) ) {
					if ( '' !== $icc_raw && ! $sub_res['icc_preserved'] ) {
						$this->inject_webp_icc_profile( $sub_tmp_path, $icc_raw );
					}
					$sub_new_sz = (int) @filesize( $sub_tmp_path );
					if ( $sub_new_sz < $sub_old_sz ) {
						rename( $sub_tmp_path, $sub_new_path );
						$meta['sizes'][ $size_key ]['file']      = $sub_new_name;
						$meta['sizes'][ $size_key ]['mime-type'] = 'image/webp';
						$meta['sizes'][ $size_key ]['filesize']  = $sub_new_sz;
						$after_total_bytes                      += $sub_new_sz;

						if ( $sub_old_name !== $sub_new_name ) {
							$url_replacements[ $rel_prefix . $sub_old_name ] = $rel_prefix . $sub_new_name;
							$files_to_cleanup[]                              = $sub_old_path;
						}
					} else {
						@unlink( $sub_tmp_path );
						$after_total_bytes += $sub_old_sz;
					}
				} else {
					@unlink( $sub_tmp_path );
					$after_total_bytes += $sub_old_sz;
				}
			}
		}

		// Update attachment post & metadata in WordPress DB.
		update_attached_file( $attachment_id, $final_main_path );
		wp_update_attachment_metadata( $attachment_id, $meta );
		if ( $final_mime !== $mime ) {
			wp_update_post(
				array(
					'ID'             => $attachment_id,
					'post_mime_type' => $final_mime,
				)
			);
		}

		// Clean up old non-WebP or oversized files on disk if replace_original is enabled.
		if ( ! empty( $this->settings['replace_original'] ) ) {
			foreach ( array_unique( $files_to_cleanup ) as $old_f ) {
				if ( $old_f !== $final_main_path && file_exists( $old_f ) ) {
					@unlink( $old_f );
				}
			}
		}

		$stats = array(
			'before_bytes'  => $before_total_bytes,
			'after_bytes'   => $after_total_bytes,
			'before_width'  => $before_w,
			'before_height' => $before_h,
			'after_width'   => $meta['width'],
			'after_height'  => $meta['height'],
			'icc_preserved' => $res['icc_preserved'],
			'engine'        => $use_new_main ? $res['engine'] : 'kept-optimal',
			'timestamp'     => time(),
		);
		update_post_meta( $attachment_id, '_fse_opt_stats', $stats );

		return array(
			'success'          => true,
			'attachment_id'    => $attachment_id,
			'file'             => $final_filename,
			'stats'            => $stats,
			'url_replacements' => $url_replacements,
		);
	}

	/**
	 * Safely replace relative image paths (normal, JSON-escaped \/, and PHP serialized) across DB tables.
	 *
	 * @param array<string, string> $path_map Map of `/wp-content/uploads/...old.jpg` => `/wp-content/uploads/...new.webp`.
	 * @return int Number of rows updated.
	 */
	public function replace_image_urls_in_db( $path_map ) {
		global $wpdb;

		if ( empty( $path_map ) ) {
			return 0;
		}

		$pairs = array();
		foreach ( $path_map as $old_rel => $new_rel ) {
			if ( $old_rel === $new_rel ) {
				continue;
			}
			$pairs[ $old_rel ]                          = $new_rel;
			$pairs[ str_replace( '/', '\/', $old_rel ) ] = str_replace( '/', '\/', $new_rel );
			$pairs[ str_replace( '/', '\\/', $old_rel ) ] = str_replace( '/', '\\/', $new_rel );
		}

		uksort(
			$pairs,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		$updated_rows = 0;

		// 1. Update wp_posts (post_content, post_excerpt).
		$posts = $wpdb->get_results(
			"SELECT ID, post_content, post_excerpt FROM {$wpdb->posts}
			 WHERE post_type != 'revision'
			   AND (post_content LIKE '%/wp-content/uploads/%' OR post_content LIKE '%\\\\/wp-content\\\\/uploads\\\\/%' OR post_excerpt LIKE '%/wp-content/uploads/%')",
			ARRAY_A
		);

		if ( is_array( $posts ) ) {
			foreach ( $posts as $p ) {
				$new_content = strtr( (string) $p['post_content'], $pairs );
				$new_excerpt = strtr( (string) $p['post_excerpt'], $pairs );
				if ( $new_content !== $p['post_content'] || $new_excerpt !== $p['post_excerpt'] ) {
					$wpdb->update(
						$wpdb->posts,
						array(
							'post_content' => $new_content,
							'post_excerpt' => $new_excerpt,
						),
						array( 'ID' => (int) $p['ID'] )
					);
					++$updated_rows;
				}
			}
		}

		// 2. Update wp_postmeta (handling serialized data like Greenshift / WooCommerce meta).
		$metas = $wpdb->get_results(
			"SELECT meta_id, meta_value FROM {$wpdb->postmeta}
			 WHERE meta_key != '_fse_opt_stats'
			   AND (meta_value LIKE '%/wp-content/uploads/%' OR meta_value LIKE '%\\\\/wp-content\\\\/uploads\\\\/%')",
			ARRAY_A
		);

		if ( is_array( $metas ) ) {
			foreach ( $metas as $m ) {
				$new_val = $this->replace_serialized_or_plain( (string) $m['meta_value'], $pairs );
				if ( $new_val !== $m['meta_value'] ) {
					$wpdb->update(
						$wpdb->postmeta,
						array( 'meta_value' => $new_val ),
						array( 'meta_id' => (int) $m['meta_id'] )
					);
					++$updated_rows;
				}
			}
		}

		// 3. Update wp_options (excluding transients & our own stats).
		$opts = $wpdb->get_results(
			"SELECT option_id, option_value FROM {$wpdb->options}
			 WHERE option_name NOT LIKE '\_transient\_%'
			   AND option_name NOT LIKE 'fse_opt_%'
			   AND (option_value LIKE '%/wp-content/uploads/%' OR option_value LIKE '%\\\\/wp-content\\\\/uploads\\\\/%')",
			ARRAY_A
		);

		if ( is_array( $opts ) ) {
			foreach ( $opts as $o ) {
				$new_val = $this->replace_serialized_or_plain( (string) $o['option_value'], $pairs );
				if ( $new_val !== $o['option_value'] ) {
					$wpdb->update(
						$wpdb->options,
						array( 'option_value' => $new_val ),
						array( 'option_id' => (int) $o['option_id'] )
					);
					++$updated_rows;
				}
			}
		}

		return $updated_rows;
	}

	/**
	 * Replace strings safely inside plain or PHP-serialized values.
	 *
	 * @param string $value Input string.
	 * @param array  $pairs Replacement map.
	 * @return string
	 */
	private function replace_serialized_or_plain( $value, $pairs ) {
		if ( is_serialized( $value ) ) {
			if ( preg_match( '/(^|;|{|})O:\d+:"/', $value ) ) {
				return (string) preg_replace_callback(
					'/s:(\d+):"(.*?)";/s',
					static function ( $m ) use ( $pairs ) {
						if ( strlen( $m[2] ) !== (int) $m[1] ) {
							return $m[0];
						}
						$rep = strtr( $m[2], $pairs );
						return 's:' . strlen( $rep ) . ':"' . $rep . '";';
					},
					$value
				);
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
			$unser = @unserialize( $value, array( 'allowed_classes' => false ) );
			if ( false !== $unser || 'b:0;' === $value ) {
				$Walk = function ( $node ) use ( &$Walk, $pairs ) {
					if ( is_string( $node ) ) {
						return strtr( $node, $pairs );
					}
					if ( is_array( $node ) ) {
						$out = array();
						foreach ( $node as $k => $v ) {
							$nk         = is_string( $k ) ? strtr( $k, $pairs ) : $k;
							$out[ $nk ] = $Walk( $v );
						}
						return $out;
					}
					return $node;
				};
				$replaced = $Walk( $unser );
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
				return serialize( $replaced );
			}
		}
		return strtr( $value, $pairs );
	}
}

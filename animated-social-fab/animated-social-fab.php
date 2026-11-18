<?php
/**
 * Plugin Name: Animated Social Media FAB (Zalo, Messenger, IG, TikTok, FB)
 * Description: Nút liên hệ mạng xã hội nổi có hiệu ứng chuyển động mượt mà (Zalo, Messenger, Instagram, TikTok, Facebook) với 2 mẫu giao diện chọn sẵn (Expandable Speed-Dial & Glass Pulse Dock), SVG thuần nhẹ 100%, kèm Live Preview trong Admin.
 * Version: 1.0.0
 * Author: TuanLee
 * License: GPL2
 * Text Domain: animated-social-fab
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Animated_Social_FAB {

	/**
	 * Option key in wp_options.
	 */
	const OPTION_KEY = 'asfab_settings';

	/**
	 * Current plugin settings.
	 *
	 * @var array
	 */
	private $settings = array();

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function get_default_settings() {
		return array(
			'enabled'            => true,
			'template'           => 'template_1', // 'template_1' (Expandable Speed-Dial) or 'template_2' (Vertical Pulse Dock).
			'position'           => 'right',      // 'right' or 'left'.
			'bottom_desktop'     => 28,
			'side_desktop'       => 24,
			'bottom_mobile'      => 20,
			'side_mobile'        => 16,
			'main_button_color'  => '#b08946',
			'main_tooltip_text'  => 'Chat với chúng tôi!',
			'show_main_tooltip'  => true,
			'hide_on_checkout'   => false,
			'channels'           => array(
				'zalo'      => array(
					'enabled' => true,
					'value'   => '0901234567',
					'label'   => 'Chat Zalo ngay',
				),
				'messenger' => array(
					'enabled' => true,
					'value'   => 'https://m.me/',
					'label'   => 'Chat Messenger',
				),
				'facebook'  => array(
					'enabled' => true,
					'value'   => 'https://facebook.com/',
					'label'   => 'Fanpage Facebook',
				),
				'instagram' => array(
					'enabled' => true,
					'value'   => 'https://instagram.com/',
					'label'   => 'Instagram',
				),
				'tiktok'    => array(
					'enabled' => true,
					'value'   => 'https://tiktok.com/',
					'label'   => 'Kênh TikTok',
				),
			),
		);
	}

	/**
	 * Metadata for the 5 supported social networks (colors & crisp inline SVGs).
	 *
	 * @return array<string, array{name:string, placeholder:string, hint:string, bg:string, svg:string}>
	 */
	public static function get_channel_definitions() {
		return array(
			'zalo'      => array(
				'name'        => 'Zalo',
				'placeholder' => '0901234567 hoặc https://zalo.me/...',
				'hint'        => 'Chỉ cần nhập SĐT (tự chuyển thành link zalo.me/SĐT) hoặc dán link Zalo.',
				'bg'          => '#0068FF',
				'color'       => '#0068ff',
				'svg'         => '<svg width="100%" height="100%" viewBox="0 0 50 50" fill="none" xmlns="http://www.w3.org/2000/svg" style="border-radius:50%;display:block;"><path fill-rule="evenodd" clip-rule="evenodd" d="M22.782 0.166016H27.199C33.2653 0.166016 36.8103 1.05701 39.9572 2.74421C43.1041 4.4314 45.5875 6.89585 47.2557 10.0428C48.9429 13.1897 49.8339 16.7347 49.8339 22.801V27.1991C49.8339 33.2654 48.9429 36.8104 47.2557 39.9573C45.5685 43.1042 43.1041 45.5877 39.9572 47.2559C36.8103 48.9431 33.2653 49.8341 27.199 49.8341H22.8009C16.7346 49.8341 13.1896 48.9431 10.0427 47.2559C6.89583 45.5687 4.41243 43.1042 2.7442 39.9573C1.057 36.8104 0.166016 33.2654 0.166016 27.1991V22.801C0.166016 16.7347 1.057 13.1897 2.7442 10.0428C4.43139 6.89585 6.89583 4.41245 10.0427 2.74421C13.1707 1.05701 16.7346 0.166016 22.782 0.166016Z" fill="#0068FF"/><path opacity="0.12" fill-rule="evenodd" clip-rule="evenodd" d="M49.8336 26.4736V27.1994C49.8336 33.2657 48.9427 36.8107 47.2555 39.9576C45.5683 43.1045 43.1038 45.5879 39.9569 47.2562C36.81 48.9434 33.265 49.8344 27.1987 49.8344H22.8007C17.8369 49.8344 14.5612 49.2378 11.8104 48.0966L7.27539 43.4267L49.8336 26.4736Z" fill="#001A33"/><path fill-rule="evenodd" clip-rule="evenodd" d="M7.779 43.5892C10.1019 43.846 13.0061 43.1836 15.0682 42.1825C24.0225 47.1318 38.0197 46.8954 46.4923 41.4732C46.8209 40.9803 47.1279 40.4677 47.4128 39.9363C49.1062 36.7779 50.0004 33.22 50.0004 27.1316V22.7175C50.0004 16.629 49.1062 13.0711 47.4128 9.91273C45.7385 6.75436 43.2461 4.28093 40.0877 2.58758C36.9293 0.894239 33.3714 0 27.283 0H22.8499C17.6644 0 14.2982 0.652754 11.4699 1.89893C11.3153 2.03737 11.1636 2.17818 11.0151 2.32135C2.71734 10.3203 2.08658 27.6593 9.12279 37.0782C9.13064 37.0921 9.13933 37.1061 9.14889 37.1203C10.2334 38.7185 9.18694 41.5154 7.55068 43.1516C7.28431 43.399 7.37944 43.5512 7.779 43.5892Z" fill="white"/><path d="M20.5632 17H10.8382V19.0853H17.5869L10.9329 27.3317C10.7244 27.635 10.5728 27.9194 10.5728 28.5639V29.0947H19.748C20.203 29.0947 20.5822 28.7156 20.5822 28.2606V27.1421H13.4922L19.748 19.2938C19.8428 19.1801 20.0134 18.9716 20.0893 18.8768L20.1272 18.8199C20.4874 18.2891 20.5632 17.8341 20.5632 17.2844V17Z" fill="#0068FF"/><path d="M32.9416 29.0947H34.3255V17H32.2402V28.3933C32.2402 28.7725 32.5435 29.0947 32.9416 29.0947Z" fill="#0068FF"/><path d="M25.814 19.6924C23.1979 19.6924 21.0747 21.8156 21.0747 24.4317C21.0747 27.0478 23.1979 29.171 25.814 29.171C28.4301 29.171 30.5533 27.0478 30.5533 24.4317C30.5723 21.8156 28.4491 19.6924 25.814 19.6924ZM25.814 27.2184C24.2785 27.2184 23.0273 25.9672 23.0273 24.4317C23.0273 22.8962 24.2785 21.645 25.814 21.645C27.3495 21.645 28.6007 22.8962 28.6007 24.4317C28.6007 25.9672 27.3685 27.2184 25.814 27.2184Z" fill="#0068FF"/><path d="M40.4867 19.6162C37.8516 19.6162 35.7095 21.7584 35.7095 24.3934C35.7095 27.0285 37.8516 29.1707 40.4867 29.1707C43.1217 29.1707 45.2639 27.0285 45.2639 24.3934C45.2639 21.7584 43.1217 19.6162 40.4867 19.6162ZM40.4867 27.2181C38.9322 27.2181 37.681 25.9669 37.681 24.4124C37.681 22.8579 38.9322 21.6067 40.4867 21.6067C42.0412 21.6067 43.2924 22.8579 43.2924 24.4124C43.2924 25.9669 42.0412 27.2181 40.4867 27.2181Z" fill="#0068FF"/><path d="M29.4562 29.0944H30.5747V19.957H28.6221V28.2793C28.6221 28.7153 29.0012 29.0944 29.4562 29.0944Z" fill="#0068FF"/></svg>',
			),
			'messenger' => array(
				'name'        => 'Messenger',
				'placeholder' => 'https://m.me/ten_fanpage hoặc username',
				'hint'        => 'Nhập username Fanpage (tự chuyển thành m.me/username) hoặc dán link đầy đủ.',
				'bg'          => 'linear-gradient(135deg, #00B2FF 0%, #006AFF 50%, #9b30ff 100%)',
				'color'       => '#0084ff',
				'svg'         => '<svg viewBox="0 0 24 24" width="22" height="22" fill="#fff" xmlns="http://www.w3.org/2000/svg"><path d="M12 2C6.36 2 2 6.13 2 11.7c0 2.91 1.19 5.44 3.14 7.17.16.13.26.35.27.57l.05 1.78c.04.57.61.94 1.13.71l1.98-.87c.17-.08.36-.1.55-.06.91.25 1.87.38 2.88.38 5.64 0 10-4.13 10-9.7C22 6.13 17.64 2 12 2zm5.89 7.56l-2.93 4.65c-.46.73-1.45.92-2.14.4l-2.33-1.75a.6.6 0 00-.72 0l-3.15 2.39c-.42.32-.97-.18-.69-.63l2.93-4.65c.46-.73 1.45-.92 2.14-.4l2.33 1.75a.6.6 0 00.72 0l3.15-2.39c.42-.32.97.18.69.63z"/></svg>',
			),
			'instagram' => array(
				'name'        => 'Instagram',
				'placeholder' => 'https://instagram.com/ten_shop',
				'hint'        => 'Nhập link trang Instagram hoặc @username.',
				'bg'          => 'radial-gradient(circle at 30% 107%, #fdf497 0%, #fdf497 5%, #fd5949 45%, #d6249f 60%, #285AEB 90%)',
				'color'       => '#e1306c',
				'svg'         => '<svg viewBox="0 0 24 24" width="22" height="22" fill="#fff" xmlns="http://www.w3.org/2000/svg"><path d="M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23a3.72 3.72 0 01-.9 1.38c-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.72 3.72 0 01-1.38-.9 3.72 3.72 0 01-.9-1.38c-.16-.42-.36-1.06-.41-2.23C2.17 15.58 2.16 15.2 2.16 12s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41C8.42 2.17 8.8 2.16 12 2.16zM12 0C8.74 0 8.33.01 7.05.07 5.78.13 4.9.33 4.14.63c-.79.31-1.46.72-2.13 1.39A5.88 5.88 0 00.63 4.14C.33 4.9.13 5.78.07 7.05.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.06 1.27.26 2.15.56 2.91.31.79.72 1.46 1.39 2.13.67.67 1.34 1.08 2.13 1.39.76.3 1.64.5 2.91.56 1.28.06 1.69.07 4.95.07s3.67-.01 4.95-.07c1.27-.06 2.15-.26 2.91-.56.79-.31 1.46-.72 2.13-1.39.67-.67 1.08-1.34 1.39-2.13.3-.76.5-1.64.56-2.91.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95c-.06-1.27-.26-2.15-.56-2.91a5.88 5.88 0 00-1.39-2.13A5.88 5.88 0 0019.86.63c-.76-.3-1.64-.5-2.91-.56C15.67.01 15.26 0 12 0zm0 5.84a6.16 6.16 0 100 12.32 6.16 6.16 0 000-12.32zM12 16a4 4 0 110-8 4 4 0 010 8zm6.4-11.85a1.44 1.44 0 100 2.88 1.44 1.44 0 000-2.88z"/></svg>',
			),
			'tiktok'    => array(
				'name'        => 'TikTok',
				'placeholder' => 'https://tiktok.com/@ten_shop',
				'hint'        => 'Nhập link kênh TikTok hoặc @username.',
				'bg'          => 'linear-gradient(135deg, #111111 0%, #2b2b2b 100%)',
				'color'       => '#111111',
				'svg'         => '<svg viewBox="0 0 24 24" width="21" height="21" fill="#fff" xmlns="http://www.w3.org/2000/svg"><path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-5.2 1.74 2.89 2.89 0 012.31-4.64 2.93 2.93 0 01.88.13V9.4a6.84 6.84 0 00-1-.05A6.33 6.33 0 005 20.1a6.34 6.34 0 0010.86-4.43v-7a8.16 8.16 0 004.77 1.52v-3.4a4.85 4.85 0 01-1-.1z"/></svg>',
			),
			'facebook'  => array(
				'name'        => 'Facebook',
				'placeholder' => 'https://facebook.com/ten_fanpage',
				'hint'        => 'Nhập link Fanpage hoặc trang cá nhân Facebook.',
				'bg'          => 'linear-gradient(135deg, #1877F2 0%, #0C5DC7 100%)',
				'color'       => '#1877F2',
				'svg'         => '<svg viewBox="0 0 24 24" width="22" height="22" fill="#fff" xmlns="http://www.w3.org/2000/svg"><path d="M22.68 0H1.32C.59 0 0 .59 0 1.32v21.36C0 23.41.59 24 1.32 24h11.5v-9.29H9.69v-3.62h3.13V8.41c0-3.1 1.89-4.79 4.66-4.79 1.32 0 2.46.1 2.79.14v3.24h-1.92c-1.5 0-1.79.72-1.79 1.76v2.31h3.59l-.47 3.62h-3.12V24h6.12c.73 0 1.32-.59 1.32-1.32V1.32C24 .59 23.41 0 22.68 0z"/></svg>',
			),
		);
	}

	/**
	 * Initialize hooks.
	 */
	public function init() {
		$this->reload_settings();

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'register_admin_menu' ), 57 );
			add_action( 'admin_init', array( $this, 'handle_save_settings' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'filter_action_links' ) );
		} else {
			add_action( 'wp_footer', array( $this, 'render_frontend_fab' ), 99 );
		}
	}

	/**
	 * Reload settings from DB merged with defaults.
	 */
	public function reload_settings() {
		$saved          = get_option( self::OPTION_KEY, array() );
		$defaults       = self::get_default_settings();
		$this->settings = wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );

		$channels = array();
		foreach ( $defaults['channels'] as $key => $def_ch ) {
			$saved_ch         = isset( $this->settings['channels'][ $key ] ) && is_array( $this->settings['channels'][ $key ] )
				? $this->settings['channels'][ $key ]
				: array();
			$channels[ $key ] = wp_parse_args( $saved_ch, $def_ch );
		}
		$this->settings['channels'] = $channels;
	}

	/**
	 * Normalize a channel input value (phone number, @username, or full URL) into a valid target href.
	 *
	 * @param string $channel_key Channel key.
	 * @param string $raw_value   Raw input string.
	 * @return string Normalized URL.
	 */
	public static function normalize_channel_url( $channel_key, $raw_value ) {
		$val = trim( (string) $raw_value );
		if ( '' === $val ) {
			return '#';
		}

		if ( preg_match( '#^https?://#i', $val ) || 0 === strpos( $val, 'tel:' ) ) {
			return $val;
		}

		if ( 'zalo' === $channel_key ) {
			$phone = preg_replace( '/[^0-9+]/', '', $val );
			$phone = preg_replace( '/^\+?84/', '0', $phone );
			if ( '' !== $phone ) {
				return 'https://zalo.me/' . $phone;
			}
			return 'https://zalo.me/' . ltrim( $val, '@/' );
		}

		if ( 'messenger' === $channel_key ) {
			return 'https://m.me/' . ltrim( $val, '@/' );
		}

		if ( 'instagram' === $channel_key ) {
			return 'https://instagram.com/' . ltrim( $val, '@/' );
		}

		if ( 'tiktok' === $channel_key ) {
			$clean = ltrim( $val, '/' );
			if ( 0 !== strpos( $clean, '@' ) ) {
				$clean = '@' . $clean;
			}
			return 'https://www.tiktok.com/' . $clean;
		}

		if ( 'facebook' === $channel_key ) {
			return 'https://facebook.com/' . ltrim( $val, '@/' );
		}

		return 'https://' . ltrim( $val, '/' );
	}

	/**
	 * Render the Floating Action Button widget on the frontend.
	 */
	public function render_frontend_fab() {
		if ( empty( $this->settings['enabled'] ) ) {
			return;
		}

		if ( ! empty( $this->settings['hide_on_checkout'] ) && function_exists( 'is_checkout' ) && is_checkout() ) {
			return;
		}

		$defs   = self::get_channel_definitions();
		$active = array();

		foreach ( $defs as $key => $meta ) {
			$ch = $this->settings['channels'][ $key ] ?? array();
			if ( ! empty( $ch['enabled'] ) && '' !== trim( (string) ( $ch['value'] ?? '' ) ) ) {
				$active[ $key ] = array(
					'key'   => $key,
					'name'  => $meta['name'],
					'label' => '' !== trim( (string) ( $ch['label'] ?? '' ) ) ? $ch['label'] : $meta['name'],
					'url'   => self::normalize_channel_url( $key, $ch['value'] ),
					'bg'    => $meta['bg'],
					'color' => $meta['color'],
					'svg'   => $meta['svg'],
				);
			}
		}

		if ( empty( $active ) ) {
			return;
		}

		echo $this->build_fab_markup_and_assets( $this->settings, $active, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Build complete self-contained HTML + CSS + Vanilla JS for both Frontend and Admin Live Preview.
	 *
	 * @param array $settings Settings array.
	 * @param array $active   Active channels list.
	 * @param bool  $is_preview Whether rendering inside the Admin Live Preview box.
	 * @return string HTML output.
	 */
	public function build_fab_markup_and_assets( $settings, $active, $is_preview = false ) {
		$valid_tpls     = array( 'template_1', 'template_2', 'template_3' );
		$template       = in_array( $settings['template'] ?? 'template_1', $valid_tpls, true ) ? $settings['template'] : 'template_1';
		$pos            = 'left' === ( $settings['position'] ?? 'right' ) ? 'left' : 'right';
		$bottom_desktop = max( 8, (int) ( $settings['bottom_desktop'] ?? 28 ) );
		$side_desktop   = max( 8, (int) ( $settings['side_desktop'] ?? 24 ) );
		$bottom_mobile  = max( 8, (int) ( $settings['bottom_mobile'] ?? 20 ) );
		$side_mobile    = max( 8, (int) ( $settings['side_mobile'] ?? 16 ) );
		$main_color     = sanitize_hex_color( $settings['main_button_color'] ?? '#b08946' ) ?: '#b08946';
		$main_tooltip   = trim( (string) ( $settings['main_tooltip_text'] ?? 'Chat với chúng tôi!' ) );
		$show_tooltip   = ! empty( $settings['show_main_tooltip'] ) && '' !== $main_tooltip;

		$wrapper_classes = array(
			'asfab-root',
			'asfab-' . $template,
			'asfab-pos-' . $pos,
		);
		if ( $is_preview ) {
			$wrapper_classes[] = 'asfab-in-preview';
		}

		ob_start();
		?>
		<style id="asfab-inline-styles">
			.asfab-root {
				--asfab-main: <?php echo esc_html( $main_color ); ?>;
				--asfab-bottom: <?php echo (int) $bottom_desktop; ?>px;
				--asfab-side: <?php echo (int) $side_desktop; ?>px;
				position: fixed;
				bottom: var(--asfab-bottom);
				z-index: 99990;
				display: flex;
				flex-direction: column;
				align-items: center;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
				user-select: none;
				-webkit-tap-highlight-color: transparent;
			}
			.asfab-root.asfab-pos-right { right: var(--asfab-side); align-items: flex-end; }
			.asfab-root.asfab-pos-left { left: var(--asfab-side); align-items: flex-start; }
			.asfab-root.asfab-in-preview {
				position: absolute;
				bottom: 24px !important;
				z-index: 20;
			}
			.asfab-root.asfab-in-preview.asfab-pos-right { right: 24px !important; left: auto !important; }
			.asfab-root.asfab-in-preview.asfab-pos-left { left: 24px !important; right: auto !important; }

			@media (max-width: 767px) {
				.asfab-root:not(.asfab-in-preview) {
					--asfab-bottom: <?php echo (int) $bottom_mobile; ?>px;
					--asfab-side: <?php echo (int) $side_mobile; ?>px;
				}
			}

			/* Shared item link styles */
			.asfab-list {
				display: flex;
				flex-direction: column;
				gap: 11px;
				margin: 0;
				padding: 0;
				list-style: none;
			}
			.asfab-pos-right .asfab-list { align-items: flex-end; }
			.asfab-pos-left .asfab-list { align-items: flex-start; }

			.asfab-item {
				position: relative;
				display: flex;
				align-items: center;
				text-decoration: none !important;
				outline: none;
			}
			.asfab-pos-left .asfab-item { flex-direction: row-reverse; }

			.asfab-icon-circle {
				width: 48px;
				height: 48px;
				border-radius: 50%;
				display: flex;
				align-items: center;
				justify-content: center;
				box-shadow: 0 6px 18px rgba(0, 0, 0, 0.22);
				transition: transform 0.28s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.28s ease;
				position: relative;
				flex-shrink: 0;
			}
			.asfab-item:hover .asfab-icon-circle {
				transform: scale(1.12) translateY(-2px);
				box-shadow: 0 10px 24px rgba(0, 0, 0, 0.3);
			}

			.asfab-label {
				background: rgba(24, 24, 27, 0.92);
				backdrop-filter: blur(8px);
				color: #fff;
				font-size: 13px;
				font-weight: 600;
				line-height: 1.2;
				padding: 7px 13px;
				border-radius: 20px;
				white-space: nowrap;
				box-shadow: 0 4px 14px rgba(0, 0, 0, 0.16);
				margin: 0 10px;
				pointer-events: none;
				transition: opacity 0.25s ease, transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
			}

			/* =========================================================
			 * TEMPLATE 1: EXPANDABLE SPEED-DIAL FAB (Nút Gộp Bung Menu)
			 * ========================================================= */
			.asfab-template_1 .asfab-list {
				margin-bottom: 14px;
				pointer-events: none;
			}
			.asfab-template_1 .asfab-item {
				opacity: 0;
				transform: translateY(24px) scale(0.6);
				transition: opacity 0.28s ease, transform 0.36s cubic-bezier(0.34, 1.56, 0.64, 1);
				transition-delay: calc(var(--asfab-idx, 0) * 40ms);
			}
			.asfab-template_1.is-open .asfab-list {
				pointer-events: auto;
			}
			.asfab-template_1.is-open .asfab-item {
				opacity: 1;
				transform: translateY(0) scale(1);
			}

			.asfab-trigger-wrap {
				position: relative;
				display: flex;
				align-items: center;
				pointer-events: none;
				z-index: 10;
			}
			.asfab-pos-left .asfab-trigger-wrap { flex-direction: row-reverse; }

			.asfab-main-tooltip {
				background: #fff;
				color: #1d2327;
				font-size: 13.5px;
				font-weight: 600;
				padding: 8px 14px;
				border-radius: 22px;
				margin: 0 12px;
				box-shadow: 0 5px 20px rgba(0,0,0,0.14);
				white-space: nowrap;
				pointer-events: auto;
				animation: asfabFloatText 3s ease-in-out infinite;
				transition: opacity 0.2s ease, transform 0.2s ease;
			}
			.asfab-template_1.is-open .asfab-main-tooltip,
			.asfab-template_3.is-open .asfab-main-tooltip {
				opacity: 0;
				pointer-events: none;
				transform: scale(0.85);
			}

			.asfab-trigger {
				width: 58px;
				height: 58px;
				border-radius: 50%;
				border: none;
				background: var(--asfab-main);
				color: #fff;
				cursor: pointer;
				pointer-events: auto;
				display: flex;
				align-items: center;
				justify-content: center;
				position: relative;
				box-shadow: 0 8px 25px rgba(0, 0, 0, 0.28);
				transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1), background 0.3s ease;
				padding: 0;
				outline: none;
			}
			.asfab-trigger:hover { transform: scale(1.07); }
			.asfab-trigger::before,
			.asfab-trigger::after {
				content: "";
				position: absolute;
				inset: -4px;
				border-radius: 50%;
				border: 2px solid var(--asfab-main);
				opacity: 0;
				pointer-events: none;
				animation: asfabPulseRing 2.4s cubic-bezier(0.215, 0.61, 0.355, 1) infinite;
			}
			.asfab-trigger::after {
				animation-delay: 1.2s;
			}
			.asfab-template_1.is-open .asfab-trigger::before,
			.asfab-template_1.is-open .asfab-trigger::after,
			.asfab-template_3.is-open .asfab-trigger::before,
			.asfab-template_3.is-open .asfab-trigger::after {
				animation: none;
				opacity: 0;
			}

			.asfab-trigger-icon-chat,
			.asfab-trigger-icon-close {
				position: absolute;
				transition: opacity 0.25s ease, transform 0.32s cubic-bezier(0.34, 1.56, 0.64, 1);
				display: flex;
				align-items: center;
				justify-content: center;
			}
			.asfab-trigger-icon-chat {
				opacity: 1;
				transform: rotate(0deg) scale(1);
				animation: asfabWiggle 3.2s ease-in-out infinite;
			}
			.asfab-trigger-icon-close {
				opacity: 0;
				transform: rotate(-90deg) scale(0.5);
			}
			.asfab-template_1.is-open .asfab-trigger-icon-chat,
			.asfab-template_3.is-open .asfab-trigger-icon-chat {
				opacity: 0;
				transform: rotate(90deg) scale(0.5);
				animation: none;
			}
			.asfab-template_1.is-open .asfab-trigger-icon-close,
			.asfab-template_3.is-open .asfab-trigger-icon-close {
				opacity: 1;
				transform: rotate(0deg) scale(1);
			}

			/* =========================================================
			 * TEMPLATE 3: RADIAL FAN-OUT ARC (Nút Xòe Quạt Vòng Cung)
			 * ========================================================= */
			.asfab-template_3 .asfab-list {
				position: absolute;
				bottom: 6px;
				width: 46px;
				height: 46px;
				pointer-events: none;
				margin: 0;
				z-index: 25;
			}
			.asfab-template_3.asfab-pos-right .asfab-list { right: 6px; }
			.asfab-template_3.asfab-pos-left .asfab-list { left: 6px; }
			.asfab-template_3 .asfab-item {
				position: absolute;
				bottom: 0;
				opacity: 0;
				pointer-events: none;
				transform: translate3d(0, 0, 0) scale(0.25) rotate(-120deg);
				transition: transform 0.44s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.28s ease;
				transition-delay: calc(var(--asfab-idx, 0) * 45ms);
			}
			.asfab-template_3.asfab-pos-right .asfab-item { right: 0; }
			.asfab-template_3.asfab-pos-left .asfab-item { left: 0; }
			.asfab-template_3 .asfab-icon-circle {
				width: 45px;
				height: 45px;
			}
			.asfab-template_3.is-open .asfab-item {
				opacity: 1;
				pointer-events: auto;
			}
			.asfab-template_3.asfab-pos-right.is-open .asfab-item {
				transform: translate3d(calc(var(--asfab-arc-x, 0px) * -1), var(--asfab-arc-y, 0px), 0) scale(1) rotate(0deg);
			}
			.asfab-template_3.asfab-pos-left.is-open .asfab-item {
				transform: translate3d(var(--asfab-arc-x, 0px), var(--asfab-arc-y, 0px), 0) scale(1) rotate(0deg);
			}
			.asfab-template_3 .asfab-label {
				position: absolute;
				bottom: calc(100% + 8px);
				left: 50%;
				transform: translateX(-50%) translateY(6px);
				margin: 0;
				opacity: 0;
				font-size: 12px;
				padding: 5px 10px;
				z-index: 30;
			}
			.asfab-template_3 .asfab-item:hover .asfab-label {
				opacity: 1;
				transform: translateX(-50%) translateY(0);
			}

			/* =========================================================
			 * TEMPLATE 2: VERTICAL PULSE GLASS DOCK (Thanh Dock Luôn Mở)
			 * ========================================================= */
			.asfab-template_2 .asfab-trigger-wrap {
				display: none !important;
			}
			.asfab-template_2 .asfab-list {
				background: rgba(255, 255, 255, 0.78);
				backdrop-filter: blur(12px);
				-webkit-backdrop-filter: blur(12px);
				padding: 10px 8px;
				border-radius: 36px;
				border: 1px solid rgba(255, 255, 255, 0.65);
				box-shadow: 0 10px 32px rgba(0, 0, 0, 0.14);
				gap: 10px;
			}
			.asfab-template_2 .asfab-icon-circle {
				width: 46px;
				height: 46px;
			}
			.asfab-template_2 .asfab-label {
				position: absolute;
				opacity: 0;
				transform: translateX(10px);
			}
			.asfab-template_2.asfab-pos-right .asfab-label {
				right: 54px;
			}
			.asfab-template_2.asfab-pos-left .asfab-label {
				left: 54px;
				transform: translateX(-10px);
			}
			.asfab-template_2 .asfab-item:hover .asfab-label {
				opacity: 1;
				transform: translateX(0);
			}
			/* Subtle pulse on the first 2 primary items (Zalo / Messenger) in Template 2 */
			.asfab-template_2 .asfab-item:nth-child(1) .asfab-icon-circle::after,
			.asfab-template_2 .asfab-item:nth-child(2) .asfab-icon-circle::after {
				content: "";
				position: absolute;
				inset: -3px;
				border-radius: 50%;
				border: 2px solid currentColor;
				opacity: 0;
				pointer-events: none;
				animation: asfabPulseRing 2.6s cubic-bezier(0.215, 0.61, 0.355, 1) infinite;
			}
			.asfab-template_2 .asfab-item:nth-child(2) .asfab-icon-circle::after {
				animation-delay: 1.3s;
			}

			@keyframes asfabPulseRing {
				0% { transform: scale(0.92); opacity: 0.75; }
				80%, 100% { transform: scale(1.42); opacity: 0; }
			}
			@keyframes asfabWiggle {
				0%, 76%, 100% { transform: rotate(0deg); }
				80% { transform: rotate(-12deg) scale(1.06); }
				84% { transform: rotate(12deg) scale(1.06); }
				88% { transform: rotate(-8deg); }
				92% { transform: rotate(8deg); }
			}
			@keyframes asfabFloatText {
				0%, 100% { transform: translateY(0); }
				50% { transform: translateY(-3px); }
			}
		</style>

		<div class="<?php echo esc_attr( implode( ' ', $wrapper_classes ) ); ?>" id="<?php echo $is_preview ? 'asfab-preview-widget' : 'asfab-widget'; ?>">
			<div class="asfab-list" role="navigation" aria-label="Liên hệ mạng xã hội">
				<?php
				$total  = count( $active );
				$radius = 148;
				$i      = 0;
				foreach ( $active as $item ) :
					// Reverse index so bottom-most item animates first when opening upward.
					$reverse_idx = ( $total - 1 ) - $i;

					// Compute quarter-circle radial fan coordinates (-4 deg to 94 deg for wider gap between icons).
					$angle_deg = $total > 1 ? -4 + ( $i / ( $total - 1 ) ) * 98 : 45;
					$angle_rad = deg2rad( $angle_deg );
					$arc_x     = round( sin( $angle_rad ) * $radius, 1 );
					$arc_y     = round( -cos( $angle_rad ) * $radius, 1 );
					++$i;
					?>
					<a class="asfab-item"
					   href="<?php echo esc_url( $item['url'] ); ?>"
					   target="_blank"
					   rel="noopener noreferrer"
					   style="--asfab-idx: <?php echo (int) $reverse_idx; ?>; --asfab-arc-x: <?php echo esc_attr( $arc_x . 'px' ); ?>; --asfab-arc-y: <?php echo esc_attr( $arc_y . 'px' ); ?>;"
					   aria-label="<?php echo esc_attr( $item['label'] ); ?>">
						<span class="asfab-label"><?php echo esc_html( $item['label'] ); ?></span>
						<span class="asfab-icon-circle" style="background: <?php echo esc_attr( $item['bg'] ); ?>; color: <?php echo esc_attr( $item['color'] ); ?>;">
							<?php echo $item['svg']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</span>
					</a>
				<?php endforeach; ?>
			</div>

			<?php if ( in_array( $template, array( 'template_1', 'template_3' ), true ) || $is_preview ) : ?>
				<div class="asfab-trigger-wrap">
					<?php if ( $show_tooltip || $is_preview ) : ?>
						<span class="asfab-main-tooltip" <?php echo ! $show_tooltip ? 'style="display:none;"' : ''; ?>><?php echo esc_html( $main_tooltip ); ?></span>
					<?php endif; ?>
					<button type="button" class="asfab-trigger" aria-label="Mở menu liên hệ" aria-expanded="false">
						<span class="asfab-trigger-icon-chat">
							<svg viewBox="0 0 24 24" width="27" height="27" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
						</span>
						<span class="asfab-trigger-icon-close">
							<svg viewBox="0 0 24 24" width="25" height="25" fill="none" stroke="#fff" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
						</span>
					</button>
				</div>
			<?php endif; ?>
		</div>

		<script>
		(function(){
			var root = document.getElementById('<?php echo $is_preview ? 'asfab-preview-widget' : 'asfab-widget'; ?>');
			if(!root) return;
			var btn = root.querySelector('.asfab-trigger');
			if(!btn) return;
			btn.addEventListener('click', function(e){
				e.stopPropagation();
				var open = root.classList.toggle('is-open');
				btn.setAttribute('aria-expanded', open ? 'true' : 'false');
			});
			document.addEventListener('click', function(e){
				if(root.classList.contains('is-open') && !root.contains(e.target)){
					root.classList.remove('is-open');
					btn.setAttribute('aria-expanded', 'false');
				}
			});
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * Add quick link on Plugins page.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function filter_action_links( $links ) {
		$custom = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=animated-social-fab' ) ) . '"><strong>💬 Cấu hình &amp; Chọn Mẫu</strong></a>',
		);
		return array_merge( $custom, $links );
	}

	/**
	 * Register top-level admin sidebar link.
	 */
	public function register_admin_menu() {
		add_menu_page(
			'Animated Social Media FAB',
			'Social FAB',
			'manage_options',
			'animated-social-fab',
			array( $this, 'render_admin_page' ),
			'dashicons-share',
			57
		);
	}

	/**
	 * Handle saving settings in WP Admin.
	 */
	public function handle_save_settings() {
		if ( ! isset( $_POST['asfab_save'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'asfab_save_action', 'asfab_nonce' );

		$defs     = self::get_channel_definitions();
		$channels = array();

		foreach ( array_keys( $defs ) as $key ) {
			$channels[ $key ] = array(
				'enabled' => ! empty( $_POST['channels'][ $key ]['enabled'] ),
				'value'   => sanitize_text_field( wp_unslash( $_POST['channels'][ $key ]['value'] ?? '' ) ),
				'label'   => sanitize_text_field( wp_unslash( $_POST['channels'][ $key ]['label'] ?? '' ) ),
			);
		}

		$new_settings = array(
			'enabled'           => ! empty( $_POST['enabled'] ),
			'template'          => in_array( $_POST['template'] ?? 'template_1', array( 'template_1', 'template_2', 'template_3' ), true ) ? sanitize_key( $_POST['template'] ) : 'template_1',
			'position'          => 'left' === ( $_POST['position'] ?? 'right' ) ? 'left' : 'right',
			'bottom_desktop'    => max( 8, (int) ( $_POST['bottom_desktop'] ?? 28 ) ),
			'side_desktop'      => max( 8, (int) ( $_POST['side_desktop'] ?? 24 ) ),
			'bottom_mobile'     => max( 8, (int) ( $_POST['bottom_mobile'] ?? 20 ) ),
			'side_mobile'       => max( 8, (int) ( $_POST['side_mobile'] ?? 16 ) ),
			'main_button_color' => sanitize_hex_color( wp_unslash( $_POST['main_button_color'] ?? '#b08946' ) ) ?: '#b08946',
			'main_tooltip_text' => sanitize_text_field( wp_unslash( $_POST['main_tooltip_text'] ?? '' ) ),
			'show_main_tooltip' => ! empty( $_POST['show_main_tooltip'] ),
			'hide_on_checkout'  => ! empty( $_POST['hide_on_checkout'] ),
			'channels'          => $channels,
		);

		update_option( self::OPTION_KEY, $new_settings );
		$this->reload_settings();

		// Clear FSE Performance Optimizer page cache if present so changes appear immediately for guests.
		if ( isset( $GLOBALS['fse_performance_optimizer']->cache ) && method_exists( $GLOBALS['fse_performance_optimizer']->cache, 'clear_all_cache' ) ) {
			$GLOBALS['fse_performance_optimizer']->cache->clear_all_cache();
		}

		set_transient( 'asfab_saved_notice_' . get_current_user_id(), 1, 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=animated-social-fab' ) );
		exit;
	}

	/**
	 * Render Admin Settings + Interactive Live Preview Page.
	 */
	public function render_admin_page() {
		$s     = $this->settings;
		$defs  = self::get_channel_definitions();
		$saved = get_transient( 'asfab_saved_notice_' . get_current_user_id() );
		if ( $saved ) {
			delete_transient( 'asfab_saved_notice_' . get_current_user_id() );
		}

		// Build preview channel list.
		$preview_channels = array();
		foreach ( $defs as $key => $meta ) {
			$ch = $s['channels'][ $key ] ?? array();
			if ( ! empty( $ch['enabled'] ) ) {
				$preview_channels[ $key ] = array(
					'key'   => $key,
					'name'  => $meta['name'],
					'label' => '' !== trim( (string) ( $ch['label'] ?? '' ) ) ? $ch['label'] : $meta['name'],
					'url'   => self::normalize_channel_url( $key, $ch['value'] ?? '' ),
					'bg'    => $meta['bg'],
					'color' => $meta['color'],
					'svg'   => $meta['svg'],
				);
			}
		}
		?>
		<div class="wrap asfab-admin-wrap">
			<style>
				.asfab-admin-wrap { max-width: 1140px; margin-top: 20px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
				.asfab-banner { background: linear-gradient(135deg, #1e293b 0%, #334155 100%); color: #fff; padding: 22px 28px; border-radius: 12px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
				.asfab-banner h1 { color: #fff; margin: 0 0 6px; font-size: 22px; font-weight: 700; }
				.asfab-banner p { margin: 0; opacity: 0.88; font-size: 13.5px; }
				.asfab-layout { display: grid; grid-template-columns: 1fr 360px; gap: 22px; align-items: start; }
				@media (max-width: 960px) { .asfab-layout { grid-template-columns: 1fr; } }
				.asfab-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 22px 24px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
				.asfab-card h2 { margin-top: 0; font-size: 16.5px; border-bottom: 1px solid #f1f5f9; padding-bottom: 12px; margin-bottom: 16px; color: #0f172a; }
				.asfab-tpl-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 16px; }
				@media (max-width: 782px) { .asfab-tpl-grid { grid-template-columns: 1fr; } }
				.asfab-tpl-option { border: 2px solid #e2e8f0; border-radius: 10px; padding: 14px 16px; cursor: pointer; transition: all 0.2s ease; background: #f8fafc; display: block; }
				.asfab-tpl-option:hover { border-color: #94a3b8; }
				.asfab-tpl-option.active { border-color: #0284c7; background: #f0f9ff; box-shadow: 0 0 0 1px #0284c7; }
				.asfab-tpl-title { font-weight: 700; font-size: 14.5px; color: #0f172a; display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
				.asfab-tpl-desc { font-size: 12.5px; color: #475569; line-height: 1.45; }
				.asfab-ch-row { border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 16px; margin-bottom: 12px; background: #fafafa; display: grid; grid-template-columns: 140px 1fr 180px; gap: 12px; align-items: center; }
				@media (max-width: 782px) { .asfab-ch-row { grid-template-columns: 1fr; } }
				.asfab-ch-badge { display: inline-flex; align-items: center; gap: 8px; font-weight: 700; font-size: 14px; }
				.asfab-ch-icon-mini { width: 32px; height: 32px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
				.asfab-ch-icon-mini svg { width: 17px; height: 17px; }
				.asfab-preview-box { position: sticky; top: 46px; background: linear-gradient(145deg, #f1f5f9 0%, #e2e8f0 100%); border: 1px solid #cbd5e1; border-radius: 12px; height: 480px; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between; padding: 18px; box-sizing: border-box; }
			</style>

			<div class="asfab-banner">
				<div>
					<h1>💬 Animated Social Media Floating Button</h1>
					<p>Zalo, Messenger, Instagram, TikTok, Facebook — 2 mẫu chuyển động mượt mà, 100% Vector SVG nhẹ tốc độ cao</p>
				</div>
				<div>
					<span style="background:rgba(255,255,255,0.15); padding:6px 14px; border-radius:20px; font-size:12.5px; font-weight:600;">
						Trạng thái: <?php echo ! empty( $s['enabled'] ) ? '🟢 Đang hiển thị' : '⚪ Đang tắt'; ?>
					</span>
				</div>
			</div>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible" style="margin: 0 0 18px; padding: 10px 14px;">
					<p style="margin:0; font-weight:600;">✅ Đã lưu cấu hình Social FAB và làm mới bộ nhớ đệm!</p>
				</div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'asfab_save_action', 'asfab_nonce' ); ?>
				<input type="hidden" name="asfab_save" value="1">

				<div class="asfab-layout">
					<div>
						<div class="asfab-card">
							<h2>1. Chọn Mẫu Giao Diện (3 Mẫu Chuyển Động Có Sẵn)</h2>
							<div class="asfab-tpl-grid">
								<label class="asfab-tpl-option <?php echo 'template_1' === $s['template'] ? 'active' : ''; ?>" onclick="document.querySelectorAll('.asfab-tpl-option').forEach(el=>el.classList.remove('active')); this.classList.add('active');">
									<div class="asfab-tpl-title">
										<input type="radio" name="template" value="template_1" <?php checked( $s['template'], 'template_1' ); ?>>
										✨ Mẫu 1: Bung Dọc (Speed-Dial)
									</div>
									<div class="asfab-tpl-desc">
										Nút tròn chính có sóng lan tỏa + lắc chuông nhẹ. Khi bấm vào sẽ <strong>bung dọc thẳng đứng 5 kênh</strong> theo hiệu ứng lò xo phân tầng.
									</div>
								</label>

								<label class="asfab-tpl-option <?php echo 'template_2' === $s['template'] ? 'active' : ''; ?>" onclick="document.querySelectorAll('.asfab-tpl-option').forEach(el=>el.classList.remove('active')); this.classList.add('active');">
									<div class="asfab-tpl-title">
										<input type="radio" name="template" value="template_2" <?php checked( $s['template'], 'template_2' ); ?>>
										💎 Mẫu 2: Thanh Dock Kính Mờ
									</div>
									<div class="asfab-tpl-desc">
										Hiển thị trực tiếp <strong>thanh Dock dọc nền kính mờ</strong> chứa cả 5 icon. Rê chuột hoặc chạm là trượt nhãn tên kênh và mở ngay chỉ với <strong>1 chạm</strong>.
									</div>
								</label>

								<label class="asfab-tpl-option <?php echo 'template_3' === $s['template'] ? 'active' : ''; ?>" onclick="document.querySelectorAll('.asfab-tpl-option').forEach(el=>el.classList.remove('active')); this.classList.add('active');">
									<div class="asfab-tpl-title">
										<input type="radio" name="template" value="template_3" <?php checked( $s['template'], 'template_3' ); ?>>
										🎡 Mẫu 3: Xòe Quạt Vòng Cung
									</div>
									<div class="asfab-tpl-desc">
										Khi bấm vào nút chính, <strong>5 icon xoay và xòe ra theo hình cánh quạt vòng cung 90°</strong> ôm quanh góc màn hình cực kỳ bắt mắt.
									</div>
								</label>
							</div>

							<table class="form-table" role="presentation" style="margin-top:0;">
								<tr>
									<th scope="row">Trạng thái hiển thị</th>
									<td>
										<label>
											<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>>
											<strong>Hiển thị nút liên hệ nổi ngoài trang web</strong>
										</label>
										<br>
										<label style="display:inline-block; margin-top:6px;">
											<input type="checkbox" name="hide_on_checkout" value="1" <?php checked( ! empty( $s['hide_on_checkout'] ) ); ?>>
											Ẩn trên trang Thanh toán (<code>/checkout/</code>) để không che nút Đặt hàng
										</label>
									</td>
								</tr>
								<tr>
									<th scope="row">Vị trí góc màn hình</th>
									<td>
										<label style="margin-right:18px;">
											<input type="radio" name="position" value="right" <?php checked( $s['position'], 'right' ); ?>>
											<strong>Góc phải dưới (Khuyên dùng)</strong>
										</label>
										<label>
											<input type="radio" name="position" value="left" <?php checked( $s['position'], 'left' ); ?>>
											<strong>Góc trái dưới</strong>
										</label>
									</td>
								</tr>
								<tr>
									<th scope="row">Màu &amp; Lời chào nút chính (Mẫu 1)</th>
									<td>
										Màu nút chính:
										<input type="color" name="main_button_color" value="<?php echo esc_attr( $s['main_button_color'] ); ?>" style="vertical-align:middle; width:46px; height:32px; padding:1px; cursor:pointer;">
										&nbsp;&nbsp;Lời chào cạnh nút:
										<input type="text" name="main_tooltip_text" value="<?php echo esc_attr( $s['main_tooltip_text'] ); ?>" class="regular-text" style="width:200px;" placeholder="Chat với chúng tôi!">
										<label style="margin-left:8px;">
											<input type="checkbox" name="show_main_tooltip" value="1" <?php checked( ! empty( $s['show_main_tooltip'] ) ); ?>>
											Hiện lời chào
										</label>
									</td>
								</tr>
								<tr>
									<th scope="row">Khoảng cách lề (px)</th>
									<td>
										Desktop — Cách đáy: <input type="number" name="bottom_desktop" value="<?php echo (int) $s['bottom_desktop']; ?>" style="width:70px;"> px,
										Cách lề ngang: <input type="number" name="side_desktop" value="<?php echo (int) $s['side_desktop']; ?>" style="width:70px;"> px
										<br><br>
										Mobile — Cách đáy: <input type="number" name="bottom_mobile" value="<?php echo (int) $s['bottom_mobile']; ?>" style="width:70px;"> px,
										Cách lề ngang: <input type="number" name="side_mobile" value="<?php echo (int) $s['side_mobile']; ?>" style="width:70px;"> px
									</td>
								</tr>
							</table>
						</div>

						<div class="asfab-card">
							<h2>2. Danh sách 5 Kênh Mạng Xã Hội (Zalo, Messenger, Instagram, TikTok, Facebook)</h2>
							<?php foreach ( $defs as $key => $meta ) :
								$ch = $s['channels'][ $key ] ?? array();
								?>
								<div class="asfab-ch-row">
									<label class="asfab-ch-badge">
										<input type="checkbox" name="channels[<?php echo esc_attr( $key ); ?>][enabled]" value="1" <?php checked( ! empty( $ch['enabled'] ) ); ?>>
										<span class="asfab-ch-icon-mini" style="background: <?php echo esc_attr( $meta['bg'] ); ?>;">
											<?php echo $meta['svg']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										</span>
										<span><?php echo esc_html( $meta['name'] ); ?></span>
									</label>

									<div>
										<input type="text"
										       name="channels[<?php echo esc_attr( $key ); ?>][value]"
										       value="<?php echo esc_attr( $ch['value'] ?? '' ); ?>"
										       placeholder="<?php echo esc_attr( $meta['placeholder'] ); ?>"
										       style="width:100%;">
										<div style="font-size:11.5px; color:#64748b; margin-top:3px;"><?php echo esc_html( $meta['hint'] ); ?></div>
									</div>

									<div>
										<input type="text"
										       name="channels[<?php echo esc_attr( $key ); ?>][label]"
										       value="<?php echo esc_attr( $ch['label'] ?? '' ); ?>"
										       placeholder="Nhãn hiển thị"
										       style="width:100%;">
										<div style="font-size:11.5px; color:#64748b; margin-top:3px;">Nhãn chữ khi di chuột/mở menu</div>
									</div>
								</div>
							<?php endforeach; ?>

							<p style="margin-top:18px;">
								<button type="submit" class="button button-primary button-hero">💾 Lưu cấu hình &amp; Cập nhật Mẫu</button>
							</p>
						</div>
					</div>

					<div>
						<div class="asfab-preview-box">
							<div>
								<div style="font-weight:700; font-size:14px; color:#0f172a; margin-bottom:4px;">👁️ Khung Xem Trước Trực Tiếp (Live Preview)</div>
								<div style="font-size:12px; color:#475569;">
									Đang hiển thị: <strong><?php echo 'template_1' === $s['template'] ? 'Mẫu 1 (Nút Gộp Bung Menu)' : 'Mẫu 2 (Thanh Dock Kính Mờ)'; ?></strong>.
									<?php if ( 'template_1' === $s['template'] ) : ?>
										Hãy thử <strong>bấm vào nút tròn bên dưới</strong> để xem hiệu ứng bung 5 kênh!
									<?php else : ?>
										Hãy thử <strong>rê chuột vào từng icon</strong> bên dưới để xem hiệu ứng!
									<?php endif; ?>
								</div>
							</div>

							<?php echo $this->build_fab_markup_and_assets( $s, $preview_channels, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					</div>
				</div>
			</form>
			<script>
			(function(){
				var pw = document.getElementById('asfab-preview-widget');
				if(!pw) return;
				document.querySelectorAll('input[name="template"]').forEach(function(r){
					r.addEventListener('change', function(){
						pw.classList.remove('asfab-template_1', 'asfab-template_2', 'asfab-template_3', 'is-open');
						pw.classList.add('asfab-' + this.value);
						if(this.value === 'template_1' || this.value === 'template_3'){
							setTimeout(function(){ pw.classList.add('is-open'); }, 80);
						}
					});
				});
				document.querySelectorAll('input[name="position"]').forEach(function(r){
					r.addEventListener('change', function(){
						pw.classList.remove('asfab-pos-left', 'asfab-pos-right');
						pw.classList.add('asfab-pos-' + this.value);
					});
				});
				var colorIn = document.querySelector('input[name="main_button_color"]');
				if(colorIn){
					colorIn.addEventListener('input', function(){
						pw.style.setProperty('--asfab-main', this.value);
					});
				}
			})();
			</script>
		</div>
		<?php
	}

	/**
	 * Plugin activation hook.
	 */
	public static function activate() {
		if ( ! get_option( self::OPTION_KEY ) ) {
			update_option( self::OPTION_KEY, self::get_default_settings() );
		}
	}
}

register_activation_hook( __FILE__, array( 'Animated_Social_FAB', 'activate' ) );

$GLOBALS['animated_social_fab'] = new Animated_Social_FAB();
add_action( 'plugins_loaded', array( $GLOBALS['animated_social_fab'], 'init' ), 20 );

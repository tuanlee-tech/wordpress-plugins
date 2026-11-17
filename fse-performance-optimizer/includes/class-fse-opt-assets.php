<?php
/**
 * Module B: Smart JS Defer, Mini-Cart Drawer Delay & Conditional Asset Unloading.
 *
 * Eliminates render-blocking scripts and delays heavy React/WooCommerce Mini-Cart bundles
 * on non-Cart/Checkout pages until user interaction while keeping Mini-Cart Drawer 100% functional.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSE_Opt_Assets {

	/**
	 * Plugin settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Handles belonging to the heavy WooCommerce Blocks / Mini-Cart React dependency tree.
	 *
	 * @var string[]
	 */
	private $delayed_handle_prefixes = array(
		'wc-mini-cart-block-frontend',
		'wc-cart-checkout-base',
		'wc-cart-checkout-vendors',
		'wc-blocks-',
		'wc-types',
		'wc-settings',
		'wc-price-format',
		'wp-polyfill',
		'wp-hooks',
		'wp-i18n',
		'react',
		'react-jsx-runtime',
		'react-dom',
		'wp-private-apis',
		'wp-url',
		'wp-api-fetch',
		'wp-deprecated',
		'wp-dom',
		'wp-escape-html',
		'wp-element',
		'wp-is-shallow-equal',
		'wp-keycodes',
		'wp-priority-queue',
		'wp-undo-manager',
		'wp-compose',
		'wp-redux-routine',
		'wp-data',
		'wp-data-controls',
		'wp-html-entities',
		'wp-dom-ready',
		'wp-a11y',
		'moment',
		'wp-date',
		'wp-primitives',
		'wp-rich-text',
		'wp-theme',
		'wp-warning',
		'wp-components',
		'wp-notices',
		'sourcebuster-js',
		'wc-order-attribution',
	);

	/**
	 * Constructor.
	 *
	 * @param array $settings Plugin settings.
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register frontend asset hooks.
	 */
	public function register_hooks() {
		if ( is_admin() ) {
			return;
		}

		add_action( 'init', array( $this, 'disable_emojis' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'unload_conditional_assets' ), 999 );
	}

	/**
	 * Remove WordPress core emoji scripts and styles.
	 */
	public function disable_emojis() {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
	}

	/**
	 * Unload unused plugin CSS/JS based on current page context.
	 */
	public function unload_conditional_assets() {
		if ( empty( $this->settings['enable_asset_unload'] ) || ( isset( $_GET['fse_raw'] ) && '1' === $_GET['fse_raw'] ) ) {
			return;
		}

		$is_product  = function_exists( 'is_product' ) && is_product();
		$is_shop_ctx = ( function_exists( 'is_woocommerce' ) && is_woocommerce() )
			|| ( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() )
			|| is_front_page();

		// 1. Video Gallery for WooCommerce: only needed on single product pages that actually have a video!
		$keep_vwg = false;
		if ( $is_product ) {
			$post_id   = get_queried_object_id();
			$video_url = $post_id ? get_post_meta( $post_id, 'vwg_video_url', true ) : '';
			$keep_vwg  = ! empty( $video_url );
		}

		if ( ! $keep_vwg ) {
			global $wp_styles, $wp_scripts;
			if ( isset( $wp_styles->registered ) ) {
				foreach ( array_keys( $wp_styles->registered ) as $h ) {
					if ( false !== strpos( $h, 'vwg' ) || false !== strpos( $h, 'video-wc-gallery' ) || 'videojs' === $h ) {
						wp_dequeue_style( $h );
					}
				}
			}
			if ( isset( $wp_scripts->registered ) ) {
				foreach ( array_keys( $wp_scripts->registered ) as $h ) {
					if ( false !== strpos( $h, 'vwg' ) || false !== strpos( $h, 'video-wc-gallery' ) || 'videojs' === $h ) {
						wp_dequeue_script( $h );
					}
				}
			}
		}

		// 2. On pure static informational pages (e.g. Gioi Thieu, Contact, Feedback),
		// check if page content has any WooCommerce blocks/shortcodes; if not, unload product-specific styles.
		if ( ! $is_shop_ctx && is_singular( 'page' ) ) {
			$post = get_post();
			$has_wc_content = $post && (
				false !== strpos( $post->post_content, 'wp:woocommerce/' ) ||
				false !== strpos( $post->post_content, '[products' ) ||
				false !== strpos( $post->post_content, '[woocommerce_' )
			);

			if ( ! $has_wc_content ) {
				$unused_styles = array(
					'wc-blocks-style-product-button',
					'wc-blocks-style-product-image',
					'wc-blocks-style-product-price',
					'wc-blocks-style-product-rating',
					'wc-blocks-style-product-results-count',
					'wc-blocks-style-product-Sale-badge',
					'wc-blocks-style-product-sku',
					'wc-blocks-style-product-summary',
					'wc-blocks-style-product-title',
					'brands-styles',
					'greenshift-qty-style',
				);
				foreach ( $unused_styles as $st_handle ) {
					wp_dequeue_style( $st_handle );
				}
				wp_dequeue_script( 'greenshift-quantity' );
			}
		}
	}

	/**
	 * Process <script> tags inside the HTML output buffer:
	 * 1. Delay heavy WooCommerce Mini-Cart / React bundle on non-Cart/Checkout pages until user interaction.
	 * 2. Add `defer` to all remaining external scripts and convert paired inline `-after` scripts safely.
	 *
	 * @param string $html Full HTML output.
	 * @return string Optimized HTML.
	 */
	public function optimize_scripts_in_html( $html ) {
		if ( '' === $html ) {
			return $html;
		}

		$enable_defer = ! empty( $this->settings['enable_js_defer'] );
		$enable_delay = ! empty( $this->settings['enable_minicart_delay'] );

		if ( ! $enable_defer && ! $enable_delay ) {
			return $html;
		}

		// Never delay on Cart, Checkout, or Account pages.
		$is_transactional = ( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() );

		if ( $is_transactional ) {
			$enable_delay = false;
		}

		$delayed_count = 0;

		$html = preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script>#is',
			function ( $matches ) use ( $enable_defer, $enable_delay, &$delayed_count ) {
				$attrs = $matches[1];
				$body  = $matches[2];

				// Skip non-executable script types (application/json, importmap, speculationrules, module).
				if ( preg_match( '#\btype\s*=\s*["\']?(application/json|application/ld\+json|importmap|speculationrules|module|fse-delay)["\']?#i', $attrs ) ) {
					return $matches[0];
				}

				$id = '';
				if ( preg_match( '#\bid\s*=\s*["\']([^"\']+)["\']#i', $attrs, $id_m ) ) {
					$id = $id_m[1];
				}

				$has_src = preg_match( '#\bsrc\s*=\s*(["\'])([^"\']+)\1#i', $attrs, $src_m );

				// Check if this script belongs to the delayed Mini-Cart / React dependency chain.
				if ( $enable_delay && '' !== $id && $this->should_delay_script_id( $id ) ) {
					++$delayed_count;
					if ( $has_src ) {
						$src_url   = $src_m[2];
						$new_attrs = preg_replace( '#\bsrc\s*=\s*(["\'])[^"\']+\1#i', 'data-fse-src="' . esc_attr( $src_url ) . '"', $attrs );
						$new_attrs = preg_replace( '#\btype\s*=\s*["\'][^"\']*["\']#i', '', $new_attrs );
						return '<script type="fse-delay"' . $new_attrs . '></script>';
					} else {
						$new_attrs = preg_replace( '#\btype\s*=\s*["\'][^"\']*["\']#i', '', $attrs );
						return '<script type="fse-delay"' . $new_attrs . '>' . $body . '</script>';
					}
				}

				// Otherwise, apply `defer` to external render-blocking scripts, and preserve order for dependent inline scripts.
				if ( $enable_defer ) {
					if ( $has_src ) {
						if ( ! preg_match( '#\b(defer|async)\b#i', $attrs ) ) {
							return '<script defer' . $attrs . '>' . $body . '</script>';
						}
					} elseif (
						preg_match( '/-(after|translations)$/i', $id ) ||
						false !== strpos( $body, 'jQuery(' ) ||
						false !== strpos( $body, 'wp.i18n' ) ||
						false !== strpos( $body, 'wp.apiFetch' ) ||
						false !== strpos( $body, 'wp.data' ) ||
						false !== strpos( $body, 'wp.date' ) ||
						false !== strpos( $body, 'wp.hooks' )
					) {
						$b64 = base64_encode( $body );
						return '<script defer' . $attrs . ' src="data:text/javascript;base64,' . $b64 . '"></script>';
					}
				}

				return $matches[0];
			},
			$html
		);

		// If any scripts were delayed, inject our lightweight sequential interaction loader before </body>.
		if ( $delayed_count > 0 ) {
			$loader = $this->get_delayed_script_loader_js();
			$html   = str_ireplace( '</body>', $loader . "\n</body>", $html );
		}

		return $html;
	}

	/**
	 * Determine if a script tag ID (e.g. `react-js`, `wp-i18n-js-after`, `wc-mini-cart-block-frontend-js`)
	 * belongs to the delayed bundle.
	 *
	 * @param string $script_id Script tag id attribute.
	 * @return bool
	 */
	private function should_delay_script_id( $script_id ) {
		foreach ( $this->delayed_handle_prefixes as $prefix ) {
			if ( 0 === strpos( $script_id, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Tiny inline script loader that restores `type="fse-delay"` scripts sequentially on first user interaction
	 * (or immediately if the user clicks the Mini-Cart button, replaying the click so the drawer opens!).
	 *
	 * @return string
	 */
	private function get_delayed_script_loader_js() {
		return '<script id="fse-opt-delay-loader">
(function(){
	var loaded = false, ready = false, pendingCartClick = null;
	var events = ["mousemove","touchstart","scroll","keydown","wheel"];
	var origWinAdd = window.addEventListener.bind(window);
	var origDocAdd = document.addEventListener.bind(document);
	var postDcl = [], postLoad = [], patched = false;

	function installLifecyclePatch(){
		if(patched) return;
		patched = true;
		window.addEventListener = function(type, fn, opts){
			if(type === "load" && document.readyState === "complete" && typeof fn === "function"){
				postLoad.push(fn);
				return;
			}
			return origWinAdd(type, fn, opts);
		};
		document.addEventListener = function(type, fn, opts){
			if(type === "DOMContentLoaded" && document.readyState !== "loading" && typeof fn === "function"){
				postDcl.push(fn);
				return;
			}
			return origDocAdd(type, fn, opts);
		};
	}

	function restoreAndFlushLifecycle(){
		if(patched){
			window.addEventListener = origWinAdd;
			document.addEventListener = origDocAdd;
			patched = false;
		}
		var dclList = postDcl.splice(0);
		for(var i = 0; i < dclList.length; i++){
			try { dclList[i].call(document, new Event("DOMContentLoaded")); } catch(err){}
		}
		var loadList = postLoad.splice(0);
		for(var j = 0; j < loadList.length; j++){
			try { loadList[j].call(window, new Event("load")); } catch(err){}
		}
		ready = true;
		if(pendingCartClick){
			var btn = pendingCartClick;
			pendingCartClick = null;
			setTimeout(function(){ btn.click(); }, 50);
		}
	}

	function triggerLoad(e){
		if(e && e.target && e.target.closest){
			var btn = e.target.closest(".wc-block-mini-cart__button");
			if(btn){ pendingCartClick = btn; }
		}
		if(loaded) return;
		loaded = true;
		events.forEach(function(ev){ window.removeEventListener(ev, triggerLoad, {passive:true}); });
		var queue = Array.prototype.slice.call(document.querySelectorAll("script[type=\"fse-delay\"]"));
		if(queue.length === 0){
			ready = true;
			return;
		}
		var head = document.head || document.getElementsByTagName("head")[0];
		if(head){
			queue.forEach(function(el){
				var u = el.getAttribute("data-fse-src");
				if(u){
					var link = document.createElement("link");
					link.rel = "preload";
					link.as = "script";
					link.href = u;
					head.appendChild(link);
				}
			});
		}
		installLifecyclePatch();
		function next(idx){
			if(idx >= queue.length){
				restoreAndFlushLifecycle();
				return;
			}
			var old = queue[idx];
			var s = document.createElement("script");
			for(var i=0; i<old.attributes.length; i++){
				var a = old.attributes[i];
				if(a.name !== "type" && a.name !== "data-fse-src"){ s.setAttribute(a.name, a.value); }
			}
			var src = old.getAttribute("data-fse-src");
			if(src){
				s.src = src;
				s.onload = s.onerror = function(){ next(idx + 1); };
				old.parentNode.replaceChild(s, old);
			} else {
				s.text = old.text || old.textContent;
				old.parentNode.replaceChild(s, old);
				next(idx + 1);
			}
		}
		next(0);
	}
	events.forEach(function(ev){ window.addEventListener(ev, triggerLoad, {passive:true}); });
	document.addEventListener("click", function(e){
		var btn = e.target && e.target.closest && e.target.closest(".wc-block-mini-cart__button");
		if(!ready && btn){
			e.preventDefault();
			e.stopImmediatePropagation();
			pendingCartClick = btn;
			if(!loaded){ triggerLoad(e); }
		}
	}, true);

	if(/(?:^|;\s*)woocommerce_items_in_cart=[1-9]\d*/.test(document.cookie) || /[?&]add-to-cart=/.test(window.location.search)){
		if(document.readyState === "loading"){
			origDocAdd("DOMContentLoaded", function(){ setTimeout(function(){ triggerLoad(); }, 0); });
		} else {
			setTimeout(function(){ triggerLoad(); }, 0);
		}
	}
})();
</script>';
	}
}

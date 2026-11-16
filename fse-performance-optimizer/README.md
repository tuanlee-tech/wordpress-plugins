# FSE Performance Optimizer (v1.2.0)

**Author:** TuanLee  
**Target Stack:** WordPress (Full Site Editing / Gutenberg / Greenshift) + WooCommerce  
**Environment:** 100% Share-Hosting Ready (Pure PHP fallback) + VPS compatible  

---

## 1. Triết lý thiết kế & Quy tắc bất biến (Design Philosophy)

Mọi thay đổi hoặc bảo trì trong tương lai **BẮT BUỘC** tuân thủ 5 nguyên tắc cốt lõi sau để tránh phá vỡ trải nghiệm chủ shop (non-tech) và khả năng chạy đa nền tảng:

1. **Zero External Dependencies (Chạy được trên Share Hosting rẻ tiền):**
   - Toàn bộ tính năng runtime (tối ưu ảnh upload + giữ ICC, Page Cache tĩnh, Minify HTML/inline CSS, Defer/Delay JS, Runtime Font `font-display: swap`, LCP/CLS, Database Cleanup, Domain Migration) **phải chạy được bằng PHP thuần** (`GD`, `zlib`, `preg_*`, `$wpdb`).
   - Không phụ thuộc SSH, Redis, Nginx config, `wp-config.php` (`WP_CACHE`), hay binary CLI. Các công cụ CLI (như `Imagick`, `python3 fontTools`, `woff2_compress`) chỉ đóng vai trò tăng tốc tùy chọn và luôn có fallback/thông báo an toàn.
2. **Bảo toàn tuyệt đối chất lượng & màu sắc ảnh (Color & Detail Fidelity First):**
   - Chủ website hoa/nghệ thuật yêu cầu khắt khe về độ chi tiết và màu sắc gốc (`Display P3` / `sRGB`).
   - Mặc định luôn dùng **`Quality = 100`** (`enable_lossy_saving = false`), **giữ nguyên `ICC Color Profile`** (`preserve_icc = true`), và **KHÔNG** lược bỏ chi tiết trừ khi người dùng chủ động bật checkbox nâng cao (`enable_lossy_saving = true` → `Q=94`).
   - **Cấm kích hoạt WebP Lossless (`VP8L`) ngầm:** Trong ImageMagick, gọi `setImageCompressionQuality(100)` mà thiếu `setOption('webp:lossless', 'false')` sẽ làm ảnh JPEG phình gấp 2–3×. Luôn phải ép `webp:lossless = 'false'`.
   - **Never-Inflate Guard:** Nếu ảnh gốc đã `<= max_image_edge` (không bị resize) và bản WebP tạo ra nặng hơn file gốc → **giữ nguyên file gốc** (`kept-optimal`), tuyệt đối không ghi file lớn hơn lên đĩa.
3. **Tổng quát & Không Hardcode (Site-Agnostic):**
   - Không hardcode bất kỳ domain, slug trang, tên theme hay ID bài viết cụ thể nào. Mọi đường dẫn và ngưỡng xử lý phải đọc từ `fse_performance_optimizer_settings` hoặc tự phát hiện động (`home_url()`, `wc_get_page_id()`, `wp_upload_dir()`).
4. **Không phá hỏng UX & Giao dịch (UX & WooCommerce Safety):**
   - **Mini-Cart Drawer:** Không xóa Mini-Cart Block trên Header; thay vào đó trì hoãn nạp bundle React/WC Blocks trên các trang không bán hàng và replay cú click khi user bấm vào `.wc-block-mini-cart__button`.
   - **Page Cache Bypass:** Tuyệt đối không cache cho user đã đăng nhập, request khác `GET`, có query string, có sản phẩm trong giỏ (`woocommerce_items_in_cart > 0`), hoặc trang giao dịch (`/cart`, `/checkout`, `/my-account`).
5. **Không tự chế File-Based SQL Query Cache:**
   - Static HTML Page Cache đã giúp khách vãng lai đạt `0 SQL query`. Trên Share Hosting, cache SQL query ra file/DB thường chậm hơn MySQL Buffer Pool vì I/O đĩa chậm và dễ gây lệch dữ liệu tồn kho/giỏ hàng WooCommerce. Thay bằng dọn dẹp DB + giới hạn Revision FSE + kiểm toán `autoload`.

---

## 2. Cấu trúc File & Trách nhiệm từng Module

```text
fse-performance-optimizer/
├── fse-performance-optimizer.php        # Bootstrap, defaults, output buffer pipeline, plugin action links
├── uninstall.php                        # Tự dọn options, _fse_opt_stats, cache dir, .htaccess khi gỡ plugin
├── README.md                            # Tài liệu kiến trúc & cơ chế cho developer/agent
└── includes/
    ├── class-fse-opt-icc-webp.php       # Module A: Auto resize + WebP + Pure-PHP RIFF VP8X/ICCP injector
    ├── class-fse-opt-assets.php         # Module B: JS Defer (data:base64 inline), Smart Mini-Cart Delay, Conditional Unload
    ├── class-fse-opt-fonts.php          # Module C: .ttf/.otf -> .woff2 batch & Runtime @font-face optimizer
    ├── class-fse-opt-cwv.php            # Module D: LCP fetchpriority, lazy-load, width/height CLS guard, srcset
    ├── class-fse-opt-cache.php          # Module E: Static HTML/.html.gz Page Cache, Minifier, Auto-Purge, .htaccess
    ├── class-fse-opt-database.php       # Module F: DB Cleanup, wp_revisions_to_keep cap, Autoload audit
    ├── class-fse-opt-migrator.php       # Module G: FSE Domain Migration (normal, \/ JSON-escaped, serialized)
    ├── class-fse-opt-benchmark.php      # Telemetry engine đo trực tiếp 10+ mẫu trang unique (Before vs After)
    └── class-fse-opt-admin.php          # Admin UI 6 tab + Global Execution Lock (JS + PHP Transient Lock)
```

---

## 3. Luồng thực thi Frontend (Request Lifecycle)

```text
HTTP GET Request
   │
   ▼
[plugins_loaded @ priority 1] -> FSE_Opt_Cache::maybe_serve_cached_page()
   ├─ Cache HIT (còn hạn TTL & is_cacheable_request() == true)
   │    └─ Trả thẳng index.html.gz (hoặc index.html) + Header:
   │       `X-FSE-Cache: HIT`, `Cache-Control: no-cache, must-revalidate, max-age=0` -> exit() [0 SQL query]
   │
   └─ Cache MISS / BYPASS (Admin login, Cart, Checkout, Query String, ?fse_raw=1...)
        │
        ▼
   [wp_enqueue_scripts @ priority 999] -> FSE_Opt_Assets::unload_conditional_assets()
        │  (Gỡ video-wc-gallery nếu không có vwg_video_url; gỡ CSS product trên trang tĩnh)
        ▼
   [template_redirect @ priority 1] -> ob_start('process_frontend_html_buffer')
        │  (Bỏ qua nếu ?fse_raw=1, feed, robots, REST, admin)
        ▼
   Pipeline lọc HTML theo thứ tự cố định:
     1. FSE_Opt_Fonts::optimize_fonts_in_html()    # @font-face -> .woff2, font-display:swap, dedup, preload
     2. FSE_Opt_CWV::optimize_images_in_html()     # .webp upgrade, sub-size 768w + srcset, width/height, LCP/lazy
     3. FSE_Opt_Assets::optimize_scripts_in_html() # Smart Delay Mini-Cart/React + defer external & inline
     4. FSE_Opt_Cache::minify_and_store_html()     # Minify HTML/inline CSS + lưu index.html & .html.gz
```

---

## 4. Chi tiết kỹ thuật & Các bẫy đã xử lý (Gotchas cho Agent tương lai)

### A. Module Ảnh (`class-fse-opt-icc-webp.php`)
- **Pure-PHP ICC Injector (`extract_icc_profile` + `inject_webp_icc_profile`):**
  - PHP `GD` (`imagewebp()`) mặc định làm mất ICC Color Profile. Module tự parse nhị phân để lấy ICC từ JPEG (`APP2` marker `ICC_PROFILE\0`), PNG (`iCCP` chunk giải nén `gzuncompress`), hoặc WebP (`ICCP` chunk).
  - Khi ghi WebP, module tự dựng container **Extended WebP (`RIFF` → `VP8X` + `ICCP` + `VP8`/`VP8L`)**: bật bit `0x20` (ICCP) và bit `0x10` (Alpha nếu có `ALPH` hoặc bit 28 của `VP8L`), căn chẵn 2-byte (`\x00` padding nếu chiều dài chunk lẻ).
- **Sub-sizes quá khổ (`> max_edge`):** Khi ảnh chính bị resize về `1920px`, mọi sub-size cũ trong `$meta['sizes']` có chiều `> 1920px` (ví dụ `1536x2048`) sẽ bị xóa khỏi đĩa/metadata và trỏ URL DB về file chính `1920px`.
- **Bypass WordPress `-scaled` (`original_image`):** Trong `optimize_existing_attachment()`, nếu tồn tại `$meta['original_image']`, file gốc quá khổ đó được cộng vào `before_bytes` và dọn sạch sau khi tạo bản `1920px` WebP.

### B. Module Assets & Smart Mini-Cart Delay (`class-fse-opt-assets.php`)
- **Thứ tự Inline Script khi bật `defer`:** Nếu script ngoài gắn `defer` nhưng inline script đi sau (như `wp-i18n-js-after`, `jQuery(document).ready(...)`) chạy đồng bộ ngay lập tức, JS sẽ báo lỗi `jQuery is not defined` hoặc `wp.i18n is undefined`. Vì vậy các inline script khớp `-(after|translations)` hoặc chứa `jQuery(`, `wp.i18n`, `wp.apiFetch`, `wp.data`, `wp.date`, `wp.hooks` được bọc thành `<script defer src="data:text/javascript;base64,...">` để chạy đúng thứ tự sau khi script chính load xong.
- **Smart Delay (`type="fse-delay"`):** Toàn bộ chuỗi phụ thuộc Mini-Cart/React (`$delayed_handle_prefixes`) bị đổi `src` → `data-fse-src` và `type="fse-delay"` (trừ trang Cart/Checkout/Account). Script loader nhỏ ở cuối `</body>` nạp tuần tự (`next(idx)`) ngay khi có tương tác đầu tiên (`mousemove`, `touchstart`, `scroll`, `keydown`, `wheel`) hoặc click vào `.wc-block-mini-cart__button` (tự lưu `pendingCartClick` và gọi `.click()` lại sau khi nạp xong 50ms).

### C. Module Fonts (`class-fse-opt-fonts.php`)
- **LocalWP `php-fpm` PATH Gotcha:** Một số môi trường (như LocalWP) ghi đè `env[PATH]` trong `php-fpm` chỉ trỏ vào thư mục `ghostscript/bin`, làm lệnh `env` hoặc `python3` không tìm thấy nếu gọi tên ngắn. Do đó `detect_font_converter_capability()` **luôn kiểm tra đường dẫn tuyệt đối** (`/usr/bin/env`, `/usr/bin/python3`) và truyền tường minh `HOME` (lấy qua `posix_getpwuid` hoặc `/home/<user>`) để Python tìm thấy thư viện `~/.local/lib/python3.*/site-packages/fontTools`.
- **Fallback Share Hosting:** Khi `exec()` bị tắt hoặc thiếu CLI, Tab 3 tự chuyển sang form upload trực tiếp file `.woff2` và vẫn cập nhật DB (`wp_font_face`, `wp_global_styles`).

### D. Module CWV (`class-fse-opt-cwv.php`)
- **Regex `src=` vs `data-src=`:** Luôn dùng negative lookbehind `(?<![a-zA-Z0-9_-])src\s*=` khi parse thẻ `<img>` (Greenshift hay xuất `data-src=""` ngay sau `src="..."`).
- **Percentage `width="100%"`:** Coi `width="100%"` / `height="100%"` như thiếu kích thước pixel thực để thay bằng kích thước pixel chuẩn từ `getimagesize()`, tránh lỗi CLS.
- **Header vs Content LCP:** Dùng vị trí `</header>` để phân biệt ảnh trong Header (logo) và ảnh nội dung đầu tiên (chỉ ảnh nội dung đầu tiên được gắn `fetchpriority="high"` và bỏ `loading="lazy"`).

### E. Module Page Cache (`class-fse-opt-cache.php`)
- **CSS Minifier Selector Gotcha (FSE `:root :where(...)`):** WordPress 6.6+ FSE Global Styles dùng rất nhiều descendant pseudo-selector có khoảng trắng trước dấu `:` như `:root :where(.wp-element-button)`. Tuyệt đối **không** xóa khoảng trắng trước dấu `:` trên toàn CSS (sẽ biến thành `:root:where(...)` làm gãy toàn bộ button & link styles). Chỉ nén khoảng trắng quanh `:` ngay sau `{` hoặc `;` (property declarations).
- **Lưu trữ kép `.html` + `.html.gz`:** Ghi tĩnh vào `wp-content/cache/fse-optimizer/<host>/<safe_uri>/index.html`. Nếu trình duyệt gửi `Accept-Encoding: gzip` và PHP không bật `zlib.output_compression`, serve thẳng `.html.gz`.
- **Chống kẹt trang cũ ở trình duyệt khách:** Luôn gửi `Cache-Control: no-cache, must-revalidate, max-age=0` cho document HTML; chỉ cache 1 năm (`immutable`) cho tài nguyên tĩnh trong `.htaccess`.
- **Auto Purge Hooks:** Xóa sạch cache khi có `save_post` (bao gồm FSE template/global styles), `deleted_post`, `trashed_post`, `untrashed_post`, `switch_theme`, `wp_update_nav_menu`, `created_term`/`edited_term`/`delete_term`, `woocommerce_update_product`, `woocommerce_product_set_stock`, `woocommerce_variation_set_stock`, `woocommerce_new_order`, `woocommerce_order_status_changed`, `customize_save_after`, `activated_plugin`, `deactivated_plugin`, và các option `gspb_global_settings`, `page_on_front`, `blogname`, `blogdescription`.
- **Debug Bypass:** Truyền `?fse_raw=1` để bỏ qua cache & toàn bộ bộ lọc HTML output buffer trên một request cụ thể (dùng cho Baseline Benchmark).

### F. Module Database (`class-fse-opt-database.php`) & Migration (`class-fse-opt-migrator.php`)
- **FSE Revision Bloat:** Gutenberg/Greenshift lưu CSS/JSON block trong mỗi revision (`~350 KB/revision`). Plugin đặt mặc định `wp_revisions_to_keep = 5` và dọn sạch revision/auto-draft/trash/transient/Action Scheduler logs cũ + `OPTIMIZE TABLE`.
- **FSE Domain Migration:** Tự động replace đủ 4 dạng trong cùng 1 lần chạy: `https://old.com`, `https:\/\/old.com` (JSON escaped), `https:\\/\\/old.com` (double-escaped), và `rawurlencode` (`https%3A%2F%2F...`), sắp xếp key dài nhất trước và unserialize/re-serialize đệ quy mảng PHP (với fallback `preg_replace_callback` chỉnh độ dài byte `s:LEN:"..."` nếu payload chứa PHP Object).
- **Lọc nhiễu khi dò domain lạ (`detect_foreign_domains`):** Chỉ quét bảng `wp_posts` (`WHERE post_type != 'revision'`) và `wp_options` (bỏ qua transient), match regex `(?=(?:\\)?/wp-(?:content|includes|json)(?:\\)?/)` để chỉ lấy root domain WordPress thực sự, bỏ qua link third-party/CDN/pattern mẫu.

### G. Cơ chế khóa thực thi (`class-fse-opt-admin.php`)
- **Dual Lock chống spam & chạy song song:**
  - *Frontend JS:* `window.fseOptSetUILock(true)` khóa toàn bộ button/submit & `.nav-tab` trên trang hiện tại (kèm `beforeunload` khi Bulk Media AJAX đang chạy).
  - *Backend PHP:* Transient `fse_opt_active_task_lock` (TTL `120–180s`) chặn mọi request đồng thời từ tab trình duyệt khác trên cùng site.

---

## 5. Ghi chú mở rộng cho tương lai (Known Extension Points)
- **Responsive sub-size cho ảnh `kept-optimal` giữ đuôi `.jpeg`:** Hiện tại `FSE_Opt_CWV::find_responsive_webp_variants()` tìm pattern `*-*x*.webp`. Nếu muốn giảm thêm dung lượng tải cho các ảnh `kept-optimal` vẫn giữ `.jpeg`/`.png`, có thể mở rộng `glob` tìm cả `*-*x*.{webp,jpg,jpeg,png}`.
- **Inline Script Base64 ở trang Cart/Checkout:** Việc chuyển đổi inline script lớn (translation JSON của WooCommerce Blocks) sang `data:text/javascript;base64,...` làm HTML trang Cart/Checkout tăng nhẹ (~15–60 KB) để đổi lấy `0 Blocking JS`. Nếu cần tối ưu dung lượng HTML Cart hơn nữa, có thể bỏ qua base64 đối với các block script `-translations` quá lớn khi không gây lỗi thứ tự.

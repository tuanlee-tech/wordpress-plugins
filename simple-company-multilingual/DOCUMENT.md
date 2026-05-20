# Simple Company Multilingual — Tài liệu cài đặt và sử dụng

## 1. Giới thiệu

**Simple Company Multilingual** là plugin đa ngôn ngữ nhẹ cho website công ty dùng WordPress. Plugin tập trung vào mô hình dễ dùng cho editor: tạo bản dịch từ Page/Post gốc, tự duplicate nội dung/layout/block sang bản draft, sau đó editor chỉ cần sửa text.

Plugin hiện hỗ trợ:

- Quản lý ngôn ngữ động, có default language.
- URL prefix cho ngôn ngữ phụ, ví dụ `/vi/`, `/de/`, `/es/`.
- Homepage đa ngôn ngữ dạng `/`, `/vi/`, `/de/`.
- Dịch Page/Post theo translation group.
- Tạo draft translations từ root item.
- Language switcher.
- Canonical/hreflang.
- Frontend locale switching theo language context.
- Frontend String Overrides cho text đi qua WordPress translation API.
- Menu by Language.
- Widget Visibility by Language.
- WooCommerce / WordPress special pages mapping.
- Theme Element Overrides bằng CSS selector.
- Admin list highlight/collapse root/children.

---

## 2. Cài đặt

1. Upload folder plugin vào:

```text
wp-content/plugins/simple-company-multilingual/
```

2. Vào:

```text
Plugins → Installed Plugins
```

3. Active plugin **Simple Company Multilingual**.

4. Vào:

```text
Settings → Company Multilingual
```

5. Cấu hình languages, sau đó vào:

```text
Settings → Permalinks → Save Changes
```

Việc save Permalinks giúp WordPress flush rewrite rules để các URL `/vi/slug/`, `/de/slug/` hoạt động đúng.

---

## 3. Cấu hình General

Tab:

```text
Settings → Company Multilingual → General
```

Các mục chính:

- **Default Language**: ngôn ngữ mặc định của site. Ngôn ngữ mặc định dùng URL gốc, không có prefix.
- **Auto append switcher**: tự thêm language switcher sau nội dung bài viết/trang.
- **Floating switcher**: hiển thị switcher nổi ở frontend.
- **Hide translated children from lists**: ẩn các bản dịch child khỏi archive/list để tránh duplicate content.
- **Delete plugin metadata on uninstall**: xoá data plugin khi uninstall.

Quy tắc URL:

```text
Default language homepage: /
Vietnamese homepage: /vi/
German homepage: /de/
Normal translated page: /vi/about-us/
Normal translated post: /vi/post-slug/
```

---

## 4. Quản lý Languages

Tab:

```text
Settings → Company Multilingual → Languages
```

### Add Language

Chọn language từ dropdown, plugin sẽ tự fill:

- Label.
- Prefix.
- Flag.
- WordPress locale.

Khi add language, plugin cố gắng cài WordPress language pack tương ứng để core/theme/plugin strings có thể dịch theo locale.

### Lưu ý

- Default language luôn active và không thể disable.
- Prefix của default language luôn là `/`.
- Các ngôn ngữ phụ nên có prefix ngắn như `vi`, `de`, `es`.
- Sau khi đổi prefix/default language, nên vào **Settings → Permalinks → Save Changes**.

---

## 5. Dịch Page/Post

Plugin hỗ trợ Page và Post.

### Tạo bản dịch

1. Mở Page/Post gốc.
2. Trong sidebar meta box **Company Translations**, bấm:

```text
Create Draft Translations
```

3. Plugin tạo các bản draft cho từng language active.
4. Editor mở từng bản draft và sửa text.
5. Publish khi hoàn tất.

### Root và Child

- **Root**: item gốc điều khiển translation group.
- **Child**: bản dịch thuộc group.

Chỉ root có nút tạo translation mới. Child chỉ có link quay về root.

### Admin list

Ở Pages/Posts list, plugin highlight:

- Root row: nền xanh nhạt.
- Child row: nền vàng nhạt.
- Có nút show/hide translations để list gọn hơn.

---

## 6. Language Switcher

Plugin có thể render switcher bằng:

### Auto append

Bật trong Settings để tự thêm switcher sau content.

### Floating switcher

Bật trong Settings để hiển thị switcher nổi.

### Shortcode

```text
[scm_language_switcher]
```

### PHP helper trong theme

Nếu cần render thủ công trong theme:

```php
echo do_shortcode( '[scm_language_switcher]' );
```

---

## 7. Homepage đa ngôn ngữ

Mô hình hiện tại:

```text
Default homepage: /
Vietnamese homepage: /vi/
German homepage: /de/
Spanish homepage: /es/
```

Điều kiện:

1. Settings → Reading đã chọn static homepage.
2. Homepage gốc có các bản dịch trong cùng translation group.
3. Các bản dịch homepage đã publish nếu muốn public user truy cập.
4. Đã save Permalinks sau khi thay đổi.

---

## 8. Menu by Language

Tab:

```text
Settings → Company Multilingual → Menus
```

Tính năng này cho phép map menu theo theme location và language.

Ví dụ:

```text
Primary Menu:
English → Main English
Vietnamese → Main Tiếng Việt
German → Main Deutsch
```

Nếu một language không chọn menu riêng, plugin fallback về menu của default language hoặc theme default.

---

## 9. Widget Visibility by Language

Plugin hỗ trợ hiển thị/ẩn widget theo ngôn ngữ.

### Classic Widgets

Trong widget form sẽ có section:

```text
Company Multilingual Visibility
```

Chọn:

- All languages.
- Hoặc một/nhiều language cụ thể.

### Block Widgets Editor

Trong Appearance → Widgets, chọn block widget, mở sidebar inspector và chọn visibility language.

Frontend sẽ render block/widget theo current language.

---

## 10. WooCommerce và WordPress Special Pages

Plugin hỗ trợ runtime mapping cho các trang đặc biệt:

### WordPress

```text
Settings → Reading → Posts page
```

### WooCommerce

```text
Shop page
Cart page
Checkout page
My Account page
```

Các page này vẫn chỉ cần assign một page trong WordPress/WooCommerce settings, thường là default language. Plugin sẽ tự resolve page ID theo current language khi frontend chạy.

Điều kiện:

- Special page gốc phải có các bản dịch trong cùng translation group.
- Các bản dịch phải publish nếu public user cần truy cập.

---

## 11. Frontend String Overrides

Tab:

```text
Settings → Company Multilingual → String Overrides
```

Dùng cho các text đi qua WordPress translation API như:

```text
Comments
Leave a Reply
Read more
Search
```

Bạn có thể add source text, sau đó nhập bản dịch theo từng language.

Lưu ý: nếu theme hardcode text trực tiếp mà không dùng `__()` / `_e()`, tính năng này không bắt được. Khi đó dùng Theme Element Overrides.

---

## 12. Theme Element Overrides

Tab:

```text
Settings → Company Multilingual → Theme Elements
```

Dùng để override element thuộc theme/builder bằng CSS selector.

Ví dụ:

```text
Key: header_cta
Selector: .site-header .cta-button
Mode: Text
English: Work With Us
Vietnamese: Làm việc cùng chúng tôi
German: Arbeiten Sie mit uns
```

Plugin dùng frontend JS để tìm element và thay text/html theo current language.

### Nên dùng khi nào?

Chỉ dùng như fallback cuối cùng, khi các cách sau không xử lý được:

1. Page/Post translation.
2. Menu by Language.
3. Widget Visibility.
4. Frontend String Overrides.

### Lưu ý SEO

Text thay bằng JS có thể không lý tưởng cho SEO-critical content. Với heading/content quan trọng, nên ưu tiên Page/Post/Menu/template translation.

---

## 13. Canonical và hreflang

Plugin render canonical và hreflang theo translation group.

Mỗi language page nên canonical về chính nó, không canonical tất cả về default language.

Ví dụ:

```text
/en/about/ canonical → /about/
/vi/about/ canonical → /vi/about/
/de/about/ canonical → /de/about/
```

---

## 14. Quy trình sử dụng đề xuất

1. Cấu hình languages.
2. Save Permalinks.
3. Tạo Page/Post gốc.
4. Bấm Create Draft Translations.
5. Sửa text trong từng bản dịch.
6. Publish bản dịch.
7. Cấu hình Menus theo language.
8. Cấu hình Widgets nếu cần.
9. Thêm String Overrides nếu theme/core text chưa đúng.
10. Dùng Theme Element Overrides cho các element hardcode cuối cùng.

---

## 15. Checklist test regression

Sau mỗi lần update plugin nên test:

```text
/                          default homepage
/vi/                       Vietnamese homepage
/de/                       German homepage
/about-page/               default page
/vi/about-page/            translated page
post default URL
post translated URL
language switcher root → child
language switcher child → root
Menus by Language
Widget Visibility
String Overrides
Theme Element Overrides
WooCommerce Shop
WooCommerce Cart
WooCommerce Checkout
WooCommerce My Account
Reading Posts Page
Canonical/hreflang source
Admin list collapse children
```

---

## 16. Troubleshooting

### URL translated không chạy

Vào:

```text
Settings → Permalinks → Save Changes
```

### Switcher disable language

Kiểm tra:

- Bản dịch đã được tạo chưa?
- Bản dịch đã publish chưa?
- Có cùng translation group không?
- Language đó có active không?

### WooCommerce page bị render như page thường

Kiểm tra page WooCommerce gốc có translation group đầy đủ chưa.

### Text theme không đổi

Nếu text không đi qua gettext và không nằm trong Page/Post/Menu/Widget, dùng Theme Element Overrides.

---

## 17. Không nên làm

- Không đổi Site Language trong WordPress General chỉ để đổi frontend language.
- Không canonical tất cả bản dịch về default language.
- Không duplicate menu/widget nếu có thể map/visibility.
- Không dùng Theme Element Overrides cho content lớn hoặc SEO-critical content.

---

## 18. Ghi chú maintain

Các option/meta chính:

```text
scm_settings
_scm_language
_scm_translation_group
_scm_translation_url_slug
```

Không nên đổi key này nếu không viết migration.

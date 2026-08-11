# Headless API

Plugin WordPress cung cấp REST API có cấu trúc cho frontend headless; hỗ trợ ACF Pro, Polylang, Rank Math, preview token, vô hiệu hóa cache và tích hợp tìm kiếm tùy chọn.

## Cài đặt

Cài file ZIP phát hành trong WordPress và thay thế thư mục plugin headless-api hiện có. Không cài đồng thời một bản sao khác dưới tên thư mục khác.

## Yêu cầu

- WordPress 6.0 trở lên
- PHP 8.0 trở lên

## Cấu hình bảo mật

- Thêm từng origin của frontend trên trình duyệt tại **Headless API → Cài đặt → Tên miền được phép**; mỗi dòng một origin, ví dụ: `https://app.example.com`.
- Endpoint công khai dùng danh sách này cho CORS. Các route đặc quyền (`preview-token`, `revalidation` và `cache`) cần một allowlist riêng, khai báo rõ ràng:

```php
add_filter( 'headless_api_privileged_cors_origins', function( array $origins ): array {
	$origins[] = 'https://app.example.com';
	return $origins;
} );
```

- Endpoint ACF options công khai bị tắt mặc định. Chỉ cho phép những options page thực sự chứa dữ liệu công khai:

```php
add_filter( 'headless_api_allowed_options_pages', function( array $keys ): array {
	return [ 'global_settings', 'header_options' ];
} );
```

## Polylang

Khi Polylang hoạt động, API nhận `lang` dưới dạng slug ngôn ngữ (`vi`, `en`) hoặc locale (`vi_VN`, `en_US`). Request dùng ngôn ngữ không hợp lệ hoặc khi Polylang chưa hoạt động sẽ nhận HTTP 400, thay vì âm thầm fallback sang ngôn ngữ khác.

- Các endpoint tra cứu nội dung (`/page`, `/page-blocks`, `/resolve`, `/seo`) trả về bản dịch đã xuất bản theo ngôn ngữ yêu cầu, hoặc HTTP 404 nếu bản dịch đó không tồn tại.
- Response page có `language` và `translations`. Response term/taxonomy cũng có metadata tương đương và liên kết đến các bản dịch công khai.
- Danh sách term, bài viết của term, archive và menu location được lọc theo ngôn ngữ yêu cầu. Cache archive và menu được tách riêng theo ngôn ngữ.
- Menu tra cứu theo location tuân theo quy ước Polylang `{location}____{lang}`. Menu tra cứu theo slug sẽ phân giải term menu đã dịch.

Để custom post type hoặc taxonomy tham gia đa ngôn ngữ, hãy bật chúng trong **Ngôn ngữ → Cài đặt** của Polylang và tạo các mối quan hệ bản dịch trực tiếp trong Polylang.

## Dữ liệu ACF cho frontend

Các field ACF Pro được chuẩn hóa để frontend luôn nhận đúng kiểu dữ liệu. `password` luôn trả về `null`, không bao giờ làm lộ giá trị đã nhập.

- `text`, `textarea`, `email`, `wysiwyg` trả về chuỗi; email không hợp lệ trả về chuỗi rỗng.
- `oembed` trả về `{ url, html }`; `html` đã được lọc an toàn và có thể rỗng nếu WordPress không tạo được embed.
- `select`, `radio`, `button_group` trả về `{ value, label }`; `checkbox` và select nhiều lựa chọn trả về mảng các object cùng cấu trúc.
- `google_map` trả về `{ address, lat, lng, zoom, place_id, city, state, post_code, country, country_short }`; tọa độ hoặc dữ liệu không có sẽ là `null` hoặc chuỗi rỗng.
- `date_picker`, `date_time_picker`, `time_picker` lần lượt trả về `YYYY-MM-DD`, ISO 8601 có múi giờ, và `HH:MM:SS`; giá trị không hợp lệ trả về `null`.
- `color_picker` trả về mã màu HEX hợp lệ hoặc `null`; `icon_picker` trả về `{ type, value, url }`.
- `page_link` nhận được mọi Return Format phổ biến của ACF (URL, post ID, `WP_Post`) và luôn trả về `{ title, url, target }`.

Schema 4.0 thay đổi một số field trước đây trả về giá trị thô. Hãy cập nhật frontend để dùng các cấu trúc ở trên.

## Content Type và Taxonomy từ ACF

ACF Pro đăng ký **Loại nội dung** và **Phân loại** dưới dạng Custom Post Type/Custom Taxonomy chuẩn của WordPress. Plugin tự phát hiện chúng, kể cả khi được tạo qua ACF UI, code hoặc plugin khác.

- GET /wp-json/headless/v1/content-types liệt kê các Content Type công khai; GET /content-types/{slug} lấy metadata một loại, gồm archive, supports và taxonomy gắn kèm.
- GET /wp-json/headless/v1/content-taxonomies liệt kê các Taxonomy công khai; GET /content-taxonomies/{slug} lấy metadata chi tiết.
- Lấy nội dung một CPT: /page?slug={slug}&post_type={post_type} hoặc /resolve?slug={slug}&post_type={post_type}. Lấy archive CPT: /archive?post_type={post_type}.
- Lấy term: /taxonomies/{taxonomy}/terms và /term/{taxonomy}/{term}. Lấy archive term: /archive?taxonomy={taxonomy}&term={term}.

Chỉ Content Type/Taxonomy có public và publicly_queryable mới được trả về. Không bắt buộc bật **Show in REST API** để dùng các endpoint của plugin này; tuy nhiên hãy bật tùy chọn đó nếu frontend cũng gọi REST API lõi WordPress (/wp-json/wp/v2/...).

## Phiên bản

Phiên bản plugin hiện tại: **1.8.0**. Phiên bản schema API: **4.1**.

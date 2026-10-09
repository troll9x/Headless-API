# Headless API

Plugin WordPress cung cấp REST API có cấu trúc cho frontend headless; hỗ trợ ACF Pro, Polylang, Rank Math, preview token, vô hiệu hóa cache và tích hợp tìm kiếm tùy chọn.

## Cài đặt

Cài file `headless-api.zip` trong WordPress. Gói luôn chứa thư mục gốc `headless-api/`, vì vậy WordPress sẽ nhận diện đúng plugin basename `headless-api/headless-api.php` và hiển thị thao tác **Thay thế bản hiện tại bằng bản đã tải lên**.

Nếu một bản cũ từng được cài dưới tên thư mục khác, khi kích hoạt bản mới plugin sẽ tự vô hiệu hóa bản Headless API trùng đó để tránh hai bộ class/hook chạy song song. Plugin không tự xóa file của bản cũ; sau khi kiểm tra website, quản trị viên có thể xóa bản đã bị vô hiệu hóa trong màn hình Plugin.

## Yêu cầu

- WordPress 6.0 trở lên
- PHP 8.0 trở lên

## Cấu hình bảo mật

For per-visitor search quotas through the Next.js proxy, set a dedicated secret of at least 32 characters in `wp-config.php`:

```php
define( 'TLU_HEADLESS_SEARCH_PROXY_SECRET', 'replace-with-a-random-32-plus-character-secret' );
```

Set the identical value as `SEARCH_PROXY_SECRET` on Next.js, and configure its trusted reverse-proxy client IP header. Requests without a valid, recent HMAC continue to use WordPress `REMOTE_ADDR` for the existing public search quota. The plugin schedules hourly WP-Cron cleanup of expired database fallback rate-limit rows; ensure WP-Cron is running (or trigger it with a real cron job).

- Thêm từng origin của frontend trên trình duyệt tại **Headless API → Cài đặt → Tên miền được phép**; mỗi dòng một origin, ví dụ: `https://app.example.com`.
- Endpoint công khai dùng danh sách này cho CORS. Các route đặc quyền (`preview-token`, `revalidation` và `cache`) cần một allowlist riêng, khai báo rõ ràng:

```php
add_filter( 'headless_api_privileged_cors_origins', function( array $origins ): array {
	$origins[] = 'https://app.example.com';
	return $origins;
} );
```

- Endpoint ACF options công khai bị tắt mặc định. Chỉ cho phép những options page thực sự chứa dữ liệu công khai:

  Bản TLU này đã cho phép sẵn các key chuyên biệt `tlu_site_hero`, `tlu_site_favicon`, `tlu_site_img`, `tlu_site_logo`, `tlu_site_footer` và `tlu_site_social`. Không đặt token, mật khẩu hoặc cấu hình nội bộ trong các options page đó.

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

Danh sách logo đối tác công khai có endpoint chuyên biệt `GET /wp-json/headless/v1/partner-logos?lang=vi`. Endpoint trả toàn bộ repeater `danh_sach_doi_tac` nhưng chỉ công khai `logo_cong_ty`, `link_doi_tac`, vị trí và số lượng; không mở toàn bộ ACF Options.

- `text`, `textarea`, `email`, `wysiwyg` trả về chuỗi; email không hợp lệ trả về chuỗi rỗng.
- `oembed` trả về `{ url, html }`; `html` đã được lọc an toàn và có thể rỗng nếu WordPress không tạo được embed.
- `select`, `radio`, `button_group` trả về `{ value, label }`; `checkbox` và select nhiều lựa chọn trả về mảng các object cùng cấu trúc.
- `google_map` trả về `{ address, lat, lng, zoom, place_id, city, state, post_code, country, country_short }`; tọa độ hoặc dữ liệu không có sẽ là `null` hoặc chuỗi rỗng.
- `date_picker`, `date_time_picker`, `time_picker` lần lượt trả về `YYYY-MM-DD`, ISO 8601 có múi giờ, và `HH:MM:SS`; giá trị không hợp lệ trả về `null`.
- `color_picker` trả về mã màu HEX hợp lệ hoặc `null`; `icon_picker` trả về `{ type, value, url }`.
- `page_link` nhận được mọi Return Format phổ biến của ACF (URL, post ID, `WP_Post`) và luôn trả về `{ title, url, target }`.

Schema 4.0 thay đổi một số field trước đây trả về giá trị thô. Hãy cập nhật frontend để dùng các cấu trúc ở trên.

### ACF trên bài viết và taxonomy

- Response chi tiết của post/CPT (/page, /page-blocks, /resolve) luôn có acf đã chuẩn hóa.
- Khi không truyền slug, /page?post_type={post_type}&page=1&per_page=10 trả danh sách CPT trong items, kèm pagination; mỗi item có acf đã chuẩn hóa.
- Mỗi item trong response /archive cũng có acf, vì vậy trang danh sách Next.js có thể render card bằng field ACF mà không phải gọi thêm API cho từng bài.
- Response term, danh sách term, term archive và term gắn trong archive post đều có acf đã chuẩn hóa. Các giá trị ACF thô không còn lặp lại trong meta.
- Relationship và Post Object vẫn chỉ trả bản tóm tắt bài viết, không nhúng ACF, để tránh payload quá lớn và vòng lặp dữ liệu. Hãy dùng id/slug để gọi endpoint chi tiết khi cần.

## Content Type và Taxonomy từ ACF

ACF Pro đăng ký **Loại nội dung** và **Phân loại** dưới dạng Custom Post Type/Custom Taxonomy chuẩn của WordPress. Plugin tự phát hiện chúng, kể cả khi được tạo qua ACF UI, code hoặc plugin khác.

- GET /wp-json/headless/v1/content-types liệt kê các Content Type công khai; GET /content-types/{slug} lấy metadata một loại, gồm archive, supports và taxonomy gắn kèm.
- GET /wp-json/headless/v1/content-taxonomies liệt kê các Taxonomy công khai; GET /content-taxonomies/{slug} lấy metadata chi tiết.
- Lấy chi tiết CPT: /page?slug={slug}&post_type={post_type} hoặc /resolve?slug={slug}&post_type={post_type}.
- Lấy danh sách CPT kể cả khi CPT không bật archive: /page?post_type={post_type}&page=1&per_page=10. Ví dụ tài liệu: /page?post_type=tai-lieu.
- Lấy archive CPT khi có bật archive: /archive?post_type={post_type}.
- Lấy term: /taxonomies/{taxonomy}/terms và /term/{taxonomy}/{term}. Lấy archive term: /archive?taxonomy={taxonomy}&term={term}.

Chỉ Content Type/Taxonomy có public và publicly_queryable mới được trả về. Không bắt buộc bật **Show in REST API** để dùng các endpoint của plugin này; tuy nhiên hãy bật tùy chọn đó nếu frontend cũng gọi REST API lõi WordPress (/wp-json/wp/v2/...).

## SonNH Media Gallery và Son NH Template Gallery

Headless API đọc trực tiếp cấu hình công khai của hai plugin gallery và chuẩn hóa attachment bằng cùng contract `media` đang dùng trong toàn bộ API. Media Library Organizer phải đăng ký taxonomy `mlo-category` trước khi các endpoint này hoạt động.

- `GET /wp-json/headless/v1/media-gallery/categories?page=1&per_page=12` trả danh sách thư mục của **SonNH Media Gallery**, gồm ảnh bìa mới nhất, số ảnh, URL cũ dùng `media_cat` và URL API lấy ảnh. `per_page` tối đa 50.
- `GET /wp-json/headless/v1/media-gallery/categories/{slug}?page=1&per_page=24&order=asc` trả ảnh trong một thư mục, có phân trang; `per_page` tối đa 100 và `order` nhận `asc` hoặc `desc`.
- `GET /wp-json/headless/v1/media-gallery/home` trả cấu hình **Son NH Template Gallery**, danh mục đã chọn, tối đa 15 ảnh theo đúng thứ tự hiển thị và ảnh nổi bật. Ảnh nổi bật được đưa về index 6 giống plugin gốc.
- Hai endpoint thư mục nhận `lang` khi taxonomy `mlo-category` được Polylang quản lý.
- Nếu gallery trang chủ chưa được cấu hình, `/media-gallery/home` vẫn trả HTTP 200 với `configured: false` và danh sách ảnh rỗng để frontend xử lý ổn định.
- `/media-gallery/home` chỉ trả các ảnh được chọn cho lưới trang chủ. Để tái tạo lightbox của plugin gốc, frontend lấy `category.images_url` với `order=desc`, loại các ID đã có trong `selected_images`, rồi nối phần còn lại vào sau ảnh đã chọn.
- Ba route gallery chỉ hỗ trợ `GET`, là dữ liệu hiển thị công khai và không cung cấp thao tác ghi, nonce, khóa bí mật hoặc quyền quản trị.

Các route trên chỉ hỗ trợ `GET`; việc chọn danh mục, sắp xếp ảnh và chọn ảnh nổi bật vẫn chỉ thực hiện trong WordPress Admin của hai plugin nguồn.

## Cơ cấu tổ chức từ Code Snippets

API thay thế phần hiển thị của shortcode `[hien_thi_to_chuc category="slug-danh-muc"]`:

- `GET /wp-json/headless/v1/organizations/{slug}` trả danh mục `danh-muc-to-chuc`, người đứng đầu và các bài `to-chuc` đã xuất bản.
- Có thể truyền `lang`, ví dụ `/organizations/ban-giam-hieu?lang=vi`, khi CPT và taxonomy được Polylang quản lý.
- `leader` được lấy từ term meta `_leader_member_id` và được loại khỏi `members` để frontend không render trùng.
- Mỗi người có `name`, `position` từ ACF `chuc_vu`, `description`, `priority`, `link`, `avatar` đã chuẩn hóa và `initial` dùng khi không có ảnh.
- Thành viên được sắp xếp theo `uu_tien_to_chuc` tăng dần rồi theo tên; bài chưa đặt ưu tiên vẫn được trả về và nằm cuối danh sách.
- Endpoint chỉ hỗ trợ `GET` và chỉ trả nội dung đã xuất bản. Phần metabox chọn người đứng đầu và nhập ưu tiên vẫn giữ trong Code Snippets/WordPress Admin.

API cho template `single-to-chuc.php`:

- `GET /wp-json/headless/v1/organizations/members/{slug}` trả hồ sơ chi tiết một bài `to-chuc`; hỗ trợ `lang` và bản dịch Polylang.
- Response gồm `ho_va_ten` có fallback về title, `chuc_vu`, `chuc_vu_phu`, `nam_sinh`, `que_quan`, `trinh_do`, ảnh đại diện và nội dung tiểu sử HTML đã lọc.
- Repeater `qua_trinh_cong_tac` được trả trong `work_history`; chỉ giữ dòng có đủ `ngay_thang_nam` và `mo_ta_qua_trinh`.
- Nút đọc, tạm dừng và tiếp tục bằng ResponsiveVoice là hành vi giao diện nên được triển khai tại Next.js, không chạy trong REST API.

## Trang chi tiết tài liệu

API dành riêng cho logic của template `single-tai-lieu.php`:

- `GET /wp-json/headless/v1/documents/{slug}` trả một bài `tai-lieu` đã xuất bản; có thể truyền `lang`, ví dụ `/documents/quyet-dinh-123?lang=vi`.
- `banner.image` lấy từ ACF `banner_tin_tuc` của taxonomy `loai-tai-lieu`. Khi term hiện tại không có banner, API tự lấy từ term cha và đặt `banner.inherited: true`.
- `documents` chuẩn hóa repeater `tai_len_tai_lieu`, gồm `symbol`, ngày ISO `issued_date`, giá trị hiển thị gốc `issued_date_display`, tiêu đề và `file` theo contract media.
- Khi repeater có đúng một dòng hợp lệ, `redirect.required` là `true` và `redirect.url` chứa URL tệp. Endpoint vẫn trả JSON; Next.js quyết định redirect thay vì REST API tự phát HTTP redirect.
- Dòng repeater không có URL tệp được loại bỏ để frontend không render liên kết tải xuống lỗi. Nếu không có tệp hợp lệ, `documents` là mảng rỗng.
- Endpoint chỉ hỗ trợ `GET`, chỉ trả bài đã xuất bản và không cung cấp thao tác sửa/xóa tài liệu.

Endpoint danh sách `/page?post_type=tai-lieu` vẫn giữ nguyên. Endpoint `/documents/{slug}` được dùng cho trang chi tiết cần banner, bảng tệp và hành vi redirect.

API cho template `taxonomy-loai-tai-lieu.php`:

- `GET /wp-json/headless/v1/documents/categories/{slug}?page=1&per_page=6` trả archive một term `loai-tai-lieu`; `per_page` tối đa 24 bài cho mỗi nhóm.
- Nếu các danh mục con có bài, response dùng `mode: children` và trả tối đa 50 nhóm trong `groups`. Nếu không có bài ở danh mục con, API fallback về term hiện tại với `mode: current`.
- Mỗi item có `ky_hieu`, `ngay_ban_hanh`, ảnh đại diện, URL chi tiết và `action`. Khi repeater có đúng một tệp hợp lệ, `action.type` là `file`; các trường hợp còn lại dùng `detail`.
- Banner được lấy trực tiếp từ term archive đang yêu cầu và fallback một cấp lên term cha. Cách này sửa lỗi template cũ dùng `get_the_ID()` trong trang taxonomy.
- `page` là phân trang chuẩn để Next.js tải thêm và nối kết quả. Chế độ list/grid là trạng thái trình bày của frontend, không phải dữ liệu API.
- Ảnh fallback mặc định giữ URL của template cũ và có thể thay bằng filter `headless_api_document_fallback_thumbnail_url`.

## Phiên bản

Phiên bản plugin hiện tại: **2.0.8**. Phiên bản schema API: **4.9**.

### Di trú URL khỏi Permalink Manager Pro

Khi plugin Permalink Manager Pro còn hoạt động, chạy `wp headless-api snapshot-permalinks` để xem trước và `wp headless-api snapshot-permalinks --apply` để lưu đường dẫn hiện hành vào post meta. Sau đó dùng `wp headless-api export-permalink-redirects --file=/tmp/tlu-article-redirects.json` để xuất alias bài viết sang URL chuẩn. Chỉ tắt Permalink Manager sau khi đã kiểm tra đủ snapshot, redirect và route trên staging.

# Nhật ký thay đổi

## [1.8.0] - 2026-08-11
### Bổ sung
- Thêm registry tự động nhận diện Custom Post Type và Custom Taxonomy do ACF Pro, code hoặc plugin khác đăng ký.
- Thêm endpoint khám phá GET /headless/v1/content-types, GET /headless/v1/content-types/{slug}, GET /headless/v1/content-taxonomies và GET /headless/v1/content-taxonomies/{slug}.
- Metadata Content Type gồm archive, REST base, supports và taxonomy liên kết; metadata Taxonomy gồm phân cấp, REST base và các Content Type liên kết.

### Đã sửa
- Đồng bộ điều kiện công khai cho danh sách taxonomy, endpoint term và taxonomy archive.
- Taxonomy công khai không còn phụ thuộc vào tùy chọn core show_in_rest khi sử dụng endpoint riêng của plugin.

### Thay đổi
- Nâng schema API lên 4.1 để bổ sung nhóm endpoint khám phá content model.

## [1.7.0] - 2026-08-11
### Bổ sung
- Chuẩn hóa field ACF `text`, `textarea`, `email`, `wysiwyg`, `oembed`, `select`, `checkbox`, `radio`, `button_group`, `google_map`, ngày giờ, màu sắc và icon để frontend nhận được kiểu dữ liệu ổn định.
- Chuẩn hóa lựa chọn ACF về `{ value, label }`; field nhiều lựa chọn trả về mảng object.
- Chuẩn hóa ngày, ngày giờ và thời gian về `YYYY-MM-DD`, ISO 8601 có múi giờ và `HH:MM:SS`.
- Bổ sung dữ liệu có cấu trúc cho Google Map, oEmbed và Icon Picker.
- Hỗ trợ Page Link với Return Format URL, post ID và `WP_Post`.

### Bảo mật
- Không trả về giá trị của field ACF `password` qua REST API.
- Lọc HTML WYSIWYG và oEmbed trước khi trả về response.

### Thay đổi
- Nâng schema API lên 4.0 do một số field ACF trước đây trả về giá trị thô nay dùng cấu trúc đã chuẩn hóa.

## [1.6.0] - 2026-08-11
### Bổ sung
- Thêm metadata ngôn ngữ term và liên kết các bản dịch công khai vào response term đã chuẩn hóa.
- Hỗ trợ tra cứu menu theo slug có xét bản dịch.

### Đã sửa
- Khởi tạo Polylang nhất quán trong content resolver và archive resolver.
- Phân giải bản dịch term bằng API `pll_get_term*` của Polylang thay vì API dành cho post.
- Chuẩn hóa và kiểm tra `lang` nhất quán cho request content, taxonomy, term, archive và menu.
- Thêm ngôn ngữ vào cache key archive và sửa tổng phân trang term theo ngôn ngữ.
- Dùng thông tin ngôn ngữ từ Polylang để vô hiệu hóa cache thay cho hook tương thích WPML.

## [1.5.1] - 2026-08-11
### Đã sửa
- Sửa mã hóa Base64URL cho chữ ký preview token.
- Giới hạn CORS tùy chỉnh chỉ trong namespace của plugin; REST API core WordPress và plugin bên thứ ba giữ nguyên hành vi CORS riêng.
- Bắt buộc allowlist đã chuẩn hóa, khai báo rõ ràng cho các CORS request có credentials đến route đặc quyền.
- Lọc nội dung công khai cho cả search suggestion và search result.
- Dùng HTTP client an toàn của WordPress cho fallback remote search.
- Trả đúng header `X-Headless-Cache: MISS` khi response vừa được lưu cache.

### Thay đổi
- Siết chặt việc so khớp namespace và chuẩn hóa CORS origin.

## 1.5.0

- Repair cache runtime bootstrap and REST hook callback contracts.
- Load the cache endpoint before REST route registration.
- Scope custom CORS handling to plugin namespaces without removing WordPress core CORS globally.

## [2.5] - 2026-07-10
### Bổ sung
- Kiến trúc HTTP Cache với CORS giới hạn theo namespace.
- Service `CorsPolicy` quản lý CORS cho route `/headless/v1/*` và `/tlu/v1/*`.
- Service `HttpCachePolicy` với các profile cache: STATIC, CONTENT, DISCOVERY, NEGATIVE, NO_STORE.
- Service `CacheVersionStore` dùng generation token để vô hiệu hóa cache theo từng domain.
- Service `CacheKeyBuilder` tạo cache key SHA-256 kèm generation token.
- `RestHttpIntegration` quản lý vòng đời CORS, cache, validation và response 304.
- `CacheInvalidationIntegration` tự động vô hiệu hóa cache khi nội dung thay đổi.
- `GET /headless/v1/cache/status` trả trạng thái cache và generation token.
- `POST /headless/v1/cache/purge` cho phép quản trị viên xóa cache.
- Hỗ trợ Conditional GET (ETag, Last-Modified, 304 Not Modified).
- `stale-while-revalidate` giúp cache hết hạn mượt mà.
- Cập nhật schema lên phiên bản 2.5.

## [2.4] - 2026-07-09
### Bổ sung
- Service `ContentResolver` hỗ trợ tra cứu nội dung nâng cao.
- Endpoint `GET /headless/v1/resolve` phân giải nội dung theo ID, slug, path hoặc URL.
- Tích hợp `PermalinkManagerIntegration` cho URI tùy chỉnh.
- Phân giải đường dẫn phân cấp và ánh xạ URL sang post.
- Xử lý lỗi 404/400/409 thống nhất.
- Cập nhật schema lên phiên bản 2.4.

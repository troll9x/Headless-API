# Nhật ký thay đổi

## [2.0.9] - 2026-10-09 (release candidate; chưa triển khai CMS)

- Gallery trang chủ giữ đủ ảnh đã chọn thuộc danh mục khác, miễn attachment là ảnh công khai có URL hợp lệ. Giữ thứ tự ảnh nổi bật tại vị trí thứ 7.
- Source và ZIP được tạo từ repository `Headless-API`; regression test `tests/gallery-selection.php` xác minh 15 ảnh và loại attachment không hợp lệ.
- API options chỉ normalize/trả về field name nằm trong allowlist công khai; transient cũ cũng được lọc trước khi phản hồi. `tests/options-public-fields.php` kiểm tra deny-by-default.
- Schema JSON giữ phiên bản 4.9 vì cấu trúc response không thay đổi.

## [2.0.8] - 2026-10-07
### Changed
- Add locale-aware search filtering and return stable frontend article paths independent of Permalink Manager.
- Include translation IDs/slugs and remove the Content Resolver's Permalink Manager dependency.
- Bump API schema to 4.9.

## [2.0.7] - 2026-10-03
### Bảo mật và hiệu năng
- Xác thực HMAC cho định danh người tìm kiếm do frontend chuyển tiếp; request không có chữ ký hợp lệ vẫn giới hạn theo `REMOTE_ADDR`.
- Dọn các bộ đếm giới hạn tốc độ đã hết hạn trong `wp_options` bằng WP-Cron theo giờ.

## [2.0.6] - 2026-10-01
### Bổ sung
- Cho phép công khai options page chuyên biệt `tlu_site_favicon` để frontend đọc cấu hình favicon; các options page khác vẫn bị chặn mặc định.
- Cho phép công khai options page chuyên biệt `tlu_site_img` để frontend đọc ảnh tĩnh riêng cho trang tiếng Việt và tiếng Anh.
- Cho phép công khai options page chuyên biệt `tlu_site_logo` để frontend đọc logo riêng cho trang tiếng Việt và tiếng Anh.
- Cho phép công khai options page chuyên biệt `tlu_site_footer` và `tlu_site_social` để frontend đọc nội dung Footer và liên kết mạng xã hội từ CMS.

## [2.0.5] - 2026-10-01
### Bổ sung
- Thêm `GET /headless/v1/priority-posts` để trả các bài HOT/NEW trang chủ từ post meta, có lọc ngôn ngữ Polylang.
- Hỗ trợ cả bộ key `post_priority_*` và các key legacy `_priority_*`; chỉ công khai dữ liệu cần thiết cho frontend.

## [2.0.4] - 2026-09-30
### Đã sửa
- Giữ đúng header `X-Headless-Cache: HIT` khi trả dữ liệu từ response cache; không ghi đè thành `MISS` trong `rest_post_dispatch`.
- Không ghi lại hoặc gia hạn TTL của response cache khi đọc trúng cache, tránh giữ dữ liệu cũ kéo dài trên endpoint có nhiều lượt truy cập.

## [2.0.3] - 2026-09-29
### Bổ sung
- Cho phép công khai options page chuyên biệt `tlu_site_hero` để frontend lấy danh sách ảnh và video banner; các options page khác vẫn bị chặn mặc định.

## [2.0.2] - 2026-09-15
### Đã sửa
- Phát hiện bản Headless API cũ theo plugin header ngay cả khi thư mục hoặc file PHP chính đã bị đổi tên.
- Trì hoãn việc xóa thông báo transient cho đến request kế tiếp, khi hook của bản legacy không còn trong bộ nhớ; tránh fatal error xuất hiện sau thông báo “Plugin đã được cập nhật thành công”.

## [2.0.1] - 2026-09-15
### Đã sửa
- Ngăn fatal error khi ACF hoặc WordPress xóa option bằng cách đồng bộ số tham số của hook `deleted_option`.
- Bỏ qua các post type quản trị nội bộ của ACF khi vô hiệu hóa cache và gửi revalidation.
- Sửa chữ ký callback cập nhật menu, callback xóa bài và callback WP-Cron để tương thích với dữ liệu WordPress thực tế truyền vào.
- Tạo lại đối tượng `WP_Post` hợp lệ khi gửi sự kiện xóa nội dung.
- Cho phép kích hoạt hoặc cập nhật gói mới an toàn khi một bản Headless API cũ đang active dưới thư mục khác; bootstrap tránh khai báo trùng class bất kể thứ tự nạp và bản cũ sẽ được vô hiệu hóa sau đó.

### Đóng gói
- Giữ cố định plugin basename `headless-api/headless-api.php` để WordPress nhận diện bản tải lên là bản thay thế.

## [1.14.0] - 2026-08-21
### Production hardening - 2026-09-08
- Unify response-cache invalidation on one generation store and serialize writes so concurrent Redis invalidations cannot overwrite each other.
- Automatically enable Headless API response caching when a persistent object cache is active and report Redis explicitly in cache diagnostics.
- Replace stale localhost frontend URLs with `https://tlu.edu.vn` on the production site and require HTTPS for non-local CORS origins.
- Preserve HTTP 200 for existing taxonomy terms and HTTP 404 only for genuinely missing resources.
- Add `GET /headless/v1/partner-logos`, returning every valid row from the public ACF repeater `danh_sach_doi_tac` without exposing unrelated Options fields.

### Bổ sung
- Hoàn tất API cho ba template trong `daihocthuyloi.zip`: `single-tai-lieu.php`, `single-to-chuc.php` và `taxonomy-loai-tai-lieu.php`.
- Thêm `GET /headless/v1/organizations/members/{slug}` với hồ sơ ACF, ảnh đại diện, các chức vụ, quá trình công tác, tiểu sử và bản dịch Polylang.
- Thêm `GET /headless/v1/documents/categories/{slug}` với banner taxonomy, cây term tổ tiên, các nhóm danh mục con và phân trang bài trong từng nhóm.
- Item archive tài liệu trả `action.type=file|detail`, URL, target và rel để Next.js xử lý đúng trường hợp có một tệp tải trực tiếp.
- Hỗ trợ ảnh fallback của template tài liệu qua filter `headless_api_document_fallback_thumbnail_url`.

### Đã sửa
- Banner archive `loai-tai-lieu` được lấy từ term đang yêu cầu thay vì dùng sai `get_the_ID()` trên trang taxonomy.
- API archive bỏ liên kết tải trực tiếp nếu repeater một dòng không có URL hợp lệ.
- Cache tự vô hiệu hóa khi các trường hồ sơ tổ chức, quá trình công tác, metadata archive hoặc repeater tài liệu thay đổi.

### Thay đổi
- Thay cơ chế `load` cộng dồn của template bằng phân trang `page`/`per_page` an toàn hơn; giới hạn 24 bài mỗi nhóm và 50 nhóm mỗi response.
- Nâng schema API lên 4.7 và phiên bản plugin lên 1.14.0.

## [1.13.0] - 2026-08-21
### Bổ sung
- Thêm `GET /headless/v1/documents/{slug}` để chuyển logic template `single-tai-lieu.php` sang Next.js.
- Trả banner ACF `banner_tin_tuc` của `loai-tai-lieu`, có fallback sang term cha và metadata cho biết banner được kế thừa.
- Chuẩn hóa repeater `tai_len_tai_lieu`, gồm `ky_hieu`, `ngay_ban_hanh`, tiêu đề và tệp tải xuống theo contract media.
- Trả `redirect.required`, `redirect.url` và status gợi ý khi bài chỉ có một tệp hợp lệ để Next.js tự điều hướng.
- Hỗ trợ Polylang cho tài liệu và trả danh sách bản dịch công khai.

### Đã sửa
- Loại dòng tài liệu không có URL khỏi response để frontend không tạo liên kết tải xuống hỏng.
- Cache tự vô hiệu hóa khi repeater tài liệu hoặc banner taxonomy thay đổi.

### Thay đổi
- Nâng schema API lên 4.6 và phiên bản plugin lên 1.13.0.

## [1.12.0] - 2026-08-21
### Bổ sung
- Thêm `GET /headless/v1/organizations/{slug}` để chuyển phần hiển thị của shortcode `[hien_thi_to_chuc]` sang Next.js.
- Response gồm danh mục `danh-muc-to-chuc`, người đứng đầu từ `_leader_member_id` và các bài `to-chuc` đã xuất bản.
- Chuẩn hóa chức vụ ACF `chuc_vu`, ảnh đại diện, mô tả, liên kết, ký tự đại diện và thứ tự `uu_tien_to_chuc` của từng thành viên.
- Hỗ trợ tham số `lang` và phân giải bản dịch danh mục tổ chức bằng Polylang.

### Đã sửa
- Thành viên chưa có `uu_tien_to_chuc` không còn bị loại khỏi kết quả như truy vấn `meta_key` trong shortcode cũ; các thành viên này được xếp cuối danh sách.
- Cache API tự vô hiệu hóa khi `chuc_vu`, `uu_tien_to_chuc` hoặc `_leader_member_id` thay đổi.

### Thay đổi
- Nâng schema API lên 4.5 và phiên bản plugin lên 1.12.0.

## [1.11.0] - 2026-08-21
### Bổ sung
- Tích hợp dữ liệu của SonNH Media Gallery qua `GET /headless/v1/media-gallery/categories` và `GET /headless/v1/media-gallery/categories/{slug}`.
- Tích hợp cấu hình Son NH Template Gallery qua `GET /headless/v1/media-gallery/home`, gồm danh mục, ảnh đã chọn, ảnh nổi bật và đúng thứ tự hiển thị của plugin gốc.
- Chuẩn hóa toàn bộ attachment gallery bằng contract media hiện có, hỗ trợ phân trang và ngôn ngữ Polylang cho taxonomy `mlo-category`.
- Endpoint chi tiết thư mục hỗ trợ `order=asc|desc` để dùng đúng thứ tự của gallery thư viện và lightbox trang chủ.
- Hiển thị trạng thái hai plugin gallery trong API settings và trang Tích hợp của Headless API.

### Đã sửa
- Tự động vô hiệu hóa cache và phát sự kiện revalidation khi các option công khai của hai gallery được thêm, cập nhật hoặc xóa.
- Loại ảnh ID không hợp lệ, không phải hình ảnh hoặc không còn thuộc danh mục đã cấu hình khỏi response gallery trang chủ.

### Thay đổi
- Nâng schema API lên 4.4 để bổ sung nhóm endpoint media gallery.

## [1.10.0] - 2026-08-13
### Bổ sung
- Cho phép GET /headless/v1/page?post_type={post_type} lấy danh sách post/CPT khi không truyền slug.
- Danh sách có phân trang qua page, per_page và mỗi item chứa ACF đã chuẩn hóa để Next.js render trực tiếp.
- Hỗ trợ danh sách CPT không bật has_archive, gồm trường hợp post_type=tai-lieu.

### Đã sửa
- Endpoint /page trả nguyên WP_Error với đúng HTTP status thay vì chuyển sai kiểu dữ liệu thành HTTP 500.

### Thay đổi
- Nâng schema API lên 4.3 vì /page bổ sung response danh sách.

## [1.9.2] - 2026-08-13
### Đã sửa
- Sửa HTTP 500 trên toàn bộ endpoint của plugin do gọi method get_header() không tồn tại trên WP_REST_Response.
- Đọc response header qua API get_headers() của WordPress và xử lý tên header không phân biệt chữ hoa/thường.
- Tránh truy cập key last_modified chưa tồn tại trong cache entry.

## [1.9.1] - 2026-08-12
### Đã sửa
- Sửa fatal error khi kích hoạt plugin do hook option gọi method allowlist revalidation không thể truy cập.
- Khai báo đúng kiểu trả về array hoặc false cho các hàm tạo webhook revalidation.
- Loại cảnh báo implicit nullable của Headless API trên PHP 8.4.

## [1.9.0] - 2026-08-12
### Bổ sung
- Chuẩn hóa và trả về acf cho term/danh mục/taxonomy, dùng cùng contract field với post và Custom Post Type.
- Mỗi bài viết trong endpoint archive nay có acf đã chuẩn hóa, phục vụ render danh sách trực tiếp trên Next.js.

### Đã sửa
- Loại các key ACF thô khỏi meta của term để frontend chỉ dùng dữ liệu acf có cấu trúc ổn định.

### Thay đổi
- Nâng schema API lên 4.2 để bổ sung ACF cho response term và archive item.

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

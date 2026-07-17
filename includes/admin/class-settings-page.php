<?php
namespace TLU_Headless_API\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trang quản trị: Cài đặt plugin Headless API.
 */
class Settings_Page {

	const OPTION_NAME  = 'tlu_headless_options';
	const OPTION_GROUP = 'tlu_headless_options_group';
	const PAGE_SLUG    = 'tlu-headless-settings';

	public function init(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			[
				'sanitize_callback' => [ $this, 'sanitize_options' ],
				'default'           => $this->defaults(),
			]
		);

		// --- Chung ---
		add_settings_section( 'tlu_section_general', 'Chung', null, self::PAGE_SLUG );

		add_settings_field(
			'frontend_url',
			'URL Frontend',
			[ $this, 'field_frontend_url' ],
			self::PAGE_SLUG,
			'tlu_section_general'
		);

		// --- Bộ nhớ đệm ---
		add_settings_section( 'tlu_section_cache', 'Bộ nhớ đệm', null, self::PAGE_SLUG );

		add_settings_field(
			'enable_cache',
			'Bật bộ nhớ đệm API',
			[ $this, 'field_enable_cache' ],
			self::PAGE_SLUG,
			'tlu_section_cache'
		);

		add_settings_field(
			'cache_ttl',
			'Thời gian đệm (giây)',
			[ $this, 'field_cache_ttl' ],
			self::PAGE_SLUG,
			'tlu_section_cache'
		);

		// --- Nâng cao ---
		add_settings_section( 'tlu_section_advanced', 'Nâng cao', null, self::PAGE_SLUG );

		add_settings_field(
			'allowed_domains',
			'Domain frontend được phép (CORS)',
			[ $this, 'field_allowed_domains' ],
			self::PAGE_SLUG,
			'tlu_section_advanced'
		);
	}

	public function defaults(): array {
		return [
			'frontend_url'    => 'http://localhost:3000',
			'enable_cache'    => false,
			'cache_ttl'       => 300,
			'allowed_domains' => '',
		];
	}

	public function get( ?string $key = null ) {
		$options = wp_parse_args(
			get_option( self::OPTION_NAME, [] ),
			$this->defaults()
		);

		if ( null !== $key ) {
			return $options[ $key ] ?? null;
		}

		return $options;
	}

	public function sanitize_options( $input ): array {
		$clean = [];

		$clean['frontend_url'] = ! empty( $input['frontend_url'] )
			? esc_url_raw( trim( $input['frontend_url'] ) )
			: 'http://localhost:3000';

		$clean['enable_cache'] = ! empty( $input['enable_cache'] );

		$clean['cache_ttl'] = isset( $input['cache_ttl'] )
			? max( 60, (int) $input['cache_ttl'] )
			: 300;

		$clean['allowed_domains'] = isset( $input['allowed_domains'] )
			? sanitize_textarea_field( $input['allowed_domains'] )
			: '';

		return $clean;
	}

	// ── Field renderers ───────────────────────────────────────────────────────

	public function field_frontend_url(): void {
		$val = $this->get( 'frontend_url' );
		printf(
			'<input type="url" name="%s[frontend_url]" value="%s" class="regular-text" placeholder="http://localhost:3000">
			<p class="description">URL gốc của ứng dụng Next.js frontend.</p>',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $val )
		);
	}

	public function field_enable_cache(): void {
		$val = $this->get( 'enable_cache' );
		printf(
			'<label><input type="checkbox" name="%s[enable_cache]" value="1" %s> Lưu đệm phản hồi API bằng WordPress transient.</label>',
			esc_attr( self::OPTION_NAME ),
			checked( $val, true, false )
		);
	}

	public function field_cache_ttl(): void {
		$val = $this->get( 'cache_ttl' );
		printf(
			'<input type="number" name="%s[cache_ttl]" value="%d" class="small-text" min="60" step="1">
			<p class="description">Thời gian lưu đệm phản hồi (giây). Tối thiểu 60. Mặc định: 300 (5 phút).</p>',
			esc_attr( self::OPTION_NAME ),
			(int) $val
		);
	}

	public function field_allowed_domains(): void {
		$val = $this->get( 'allowed_domains' );
		printf(
			'<textarea name="%s[allowed_domains]" rows="5" class="large-text" placeholder="https://example.com">%s</textarea>
			<p class="description">Mỗi domain một dòng. Plugin chỉ gửi <code>Access-Control-Allow-Origin</code> cho đúng domain được liệt kê — không phản chiếu Origin tùy ý.</p>',
			esc_attr( self::OPTION_NAME ),
			esc_textarea( $val )
		);
	}

	// ── Page render ───────────────────────────────────────────────────────────

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Bạn không có quyền truy cập trang này.' );
		}
		?>
		<div class="wrap tlu-headless-wrap">
			<h1>Cài đặt Headless</h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button( 'Lưu cài đặt' );
				?>
			</form>
		</div>
		<?php
	}
}

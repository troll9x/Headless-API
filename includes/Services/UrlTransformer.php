<?php

namespace TLU_Headless_API\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Chuyển URL điều hướng nội bộ từ WordPress CMS sang frontend headless.
 *
 * Navigation context:
 * - URL nội bộ được chuyển sang frontend origin.
 * - URL bên ngoài được giữ nguyên.
 * - URL nguy hiểm và WordPress system paths bị chặn.
 *
 * Asset context:
 * - URL media/asset được giữ nguyên.
 * - Scheme nguy hiểm vẫn bị chặn.
 */
final class UrlTransformer {

	public const CONTEXT_NAVIGATION = 'navigation';
	public const CONTEXT_ASSET      = 'asset';

	/**
	 * Frontend base đã được parse và chuẩn hóa.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $frontend_base;

	/**
	 * Danh sách WordPress backend bases.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $backend_bases = [];

	/**
	 * @param string|null $frontend_base_url Frontend base URL.
	 * @param array|null  $backend_base_urls Danh sách home_url/site_url.
	 */
	public function __construct(
		?string $frontend_base_url = null,
		?array $backend_base_urls = null
	) {
		if ( null === $frontend_base_url ) {
			$frontend_base_url = $this->default_frontend_url();
		}

		if ( null === $backend_base_urls ) {
			$backend_base_urls = $this->default_backend_urls();
		}

		if ( function_exists( 'apply_filters' ) ) {
			$backend_base_urls = apply_filters(
				'headless_api_backend_base_urls',
				$backend_base_urls,
				$this
			);
		}

		$this->frontend_base = $this->normalize_base_url(
			(string) $frontend_base_url
		);

		foreach ( (array) $backend_base_urls as $backend_url ) {
			$base = $this->normalize_base_url( (string) $backend_url );

			if ( null !== $base ) {
				$this->backend_bases[] = $base;
			}
		}

		usort(
			$this->backend_bases,
			static function ( array $a, array $b ): int {
				return strlen( (string) $b['path'] )
					<=> strlen( (string) $a['path'] );
			}
		);
	}

	public function transform(
		string $url,
		string $context = self::CONTEXT_NAVIGATION
	): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		if ( $this->has_dangerous_scheme( $url ) ) {
			return '';
		}

		if ( $this->has_passthrough_scheme( $url ) ) {
			return $url;
		}

		if ( '#' === $url[0] || '?' === $url[0] ) {
			return $url;
		}

		if ( self::CONTEXT_ASSET === $context ) {
			return $this->apply_transform_filter(
				$url,
				$url,
				$context
			);
		}

		$transformed = $this->transform_navigation_value( $url );

		$transformed = $this->apply_transform_filter(
			$transformed,
			$url,
			$context
		);

		if ( $this->has_dangerous_scheme( $transformed ) ) {
			return '';
		}

		if (
			'' !== $transformed
			&& $this->contains_blocked_navigation_path( $transformed )
		) {
			return '';
		}

		return $transformed;
	}

	public function transform_navigation_url( string $url ): string {
		return $this->transform(
			$url,
			self::CONTEXT_NAVIGATION
		);
	}

	public function transform_asset_url( string $url ): string {
		return $this->transform(
			$url,
			self::CONTEXT_ASSET
		);
	}

	public function is_internal_url( string $url ): bool {
		$url = trim( $url );

		if (
			'' === $url
			|| $this->has_dangerous_scheme( $url )
			|| $this->has_passthrough_scheme( $url )
			|| '#' === $url[0]
			|| '?' === $url[0]
		) {
			return false;
		}

		if ( '/' === $url[0] && 0 !== strpos( $url, '//' ) ) {
			return true;
		}

		$parsed = $this->parse_url_value( $url );

		if ( null === $parsed ) {
			return false;
		}

		return null !== $this->match_backend_base( $parsed );
	}

	private function transform_navigation_value( string $url ): string {
		$is_root_relative = (
			'/' === $url[0]
			&& 0 !== strpos( $url, '//' )
		);

		if ( $is_root_relative ) {
			$parsed = $this->parse_relative_url( $url );

			if ( null === $parsed ) {
				return $url;
			}

			$logical_path = $this->strip_backend_base_path(
				(string) ( $parsed['path'] ?? '/' )
			);

			if ( $this->is_blocked_path( $logical_path ) ) {
				return '';
			}

			return $this->build_frontend_url(
				$logical_path,
				(string) ( $parsed['query'] ?? '' ),
				(string) ( $parsed['fragment'] ?? '' ),
				$url
			);
		}

		$parsed = $this->parse_url_value( $url );

		if ( null === $parsed ) {
			return $url;
		}

		if ( $this->matches_frontend_base( $parsed ) ) {
			return $url;
		}

		$backend_base = $this->match_backend_base( $parsed );

		if ( null === $backend_base ) {
			return $url;
		}

		$path = (string) ( $parsed['path'] ?? '/' );

		$logical_path = $this->remove_base_path(
			$path,
			(string) $backend_base['path']
		);

		if ( $this->is_blocked_path( $logical_path ) ) {
			return '';
		}

		return $this->build_frontend_url(
			$logical_path,
			(string) ( $parsed['query'] ?? '' ),
			(string) ( $parsed['fragment'] ?? '' ),
			$url
		);
	}

	private function build_frontend_url(
		string $path,
		string $query,
		string $fragment,
		string $fallback
	): string {
		if ( null === $this->frontend_base ) {
			return $fallback;
		}

		$frontend_path = $this->join_paths(
			(string) $this->frontend_base['path'],
			$path
		);

		$url = (string) $this->frontend_base['scheme']
			. '://'
			. (string) $this->frontend_base['host'];

		if ( null !== $this->frontend_base['port'] ) {
			$url .= ':' . (int) $this->frontend_base['port'];
		}

		$url .= $frontend_path;

		if ( '' !== $query ) {
			$url .= '?' . $query;
		}

		if ( '' !== $fragment ) {
			$url .= '#' . $fragment;
		}

		return $url;
	}

	private function parse_url_value( string $url ): ?array {
		$protocol_relative = 0 === strpos( $url, '//' );

		if ( $protocol_relative ) {
			$scheme = null !== $this->frontend_base
				? (string) $this->frontend_base['scheme']
				: 'https';

			$url = $scheme . ':' . $url;
		}

		$parsed = $this->safe_parse_url( $url );

		if (
			! is_array( $parsed )
			|| empty( $parsed['host'] )
		) {
			return null;
		}

		$scheme = strtolower(
			(string) ( $parsed['scheme'] ?? '' )
		);

		if (
			'' !== $scheme
			&& ! in_array( $scheme, [ 'http', 'https' ], true )
		) {
			return null;
		}

		$parsed['scheme'] = $scheme;
		$parsed['host']   = strtolower( (string) $parsed['host'] );
		$parsed['port']   = isset( $parsed['port'] )
			? (int) $parsed['port']
			: null;
		$parsed['path']   = $this->normalize_path(
			(string) ( $parsed['path'] ?? '/' )
		);

		return $parsed;
	}

	private function parse_relative_url( string $url ): ?array {
		$parsed = $this->safe_parse_url( $url );

		if ( ! is_array( $parsed ) ) {
			return null;
		}

		$parsed['path'] = $this->normalize_path(
			(string) ( $parsed['path'] ?? '/' )
		);

		return $parsed;
	}

	private function normalize_base_url( string $url ): ?array {
		$url = trim( $url );

		if ( '' === $url ) {
			return null;
		}

		$parsed = $this->safe_parse_url( $url );

		if (
			! is_array( $parsed )
			|| empty( $parsed['scheme'] )
			|| empty( $parsed['host'] )
		) {
			return null;
		}

		$scheme = strtolower( (string) $parsed['scheme'] );

		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return null;
		}

		return [
			'scheme' => $scheme,
			'host'   => strtolower( (string) $parsed['host'] ),
			'port'   => isset( $parsed['port'] )
				? (int) $parsed['port']
				: null,
			'path'   => $this->normalize_base_path(
				(string) ( $parsed['path'] ?? '' )
			),
		];
	}

	private function match_backend_base( array $parsed ): ?array {
		foreach ( $this->backend_bases as $base ) {
			if ( ! $this->same_origin( $parsed, $base ) ) {
				continue;
			}

			$path      = (string) ( $parsed['path'] ?? '/' );
			$base_path = (string) $base['path'];

			if ( ! $this->path_matches_base( $path, $base_path ) ) {
				continue;
			}

			return $base;
		}

		return null;
	}

	private function matches_frontend_base( array $parsed ): bool {
		if ( null === $this->frontend_base ) {
			return false;
		}

		if ( ! $this->same_origin( $parsed, $this->frontend_base ) ) {
			return false;
		}

		return $this->path_matches_base(
			(string) ( $parsed['path'] ?? '/' ),
			(string) $this->frontend_base['path']
		);
	}

	private function same_origin( array $url, array $base ): bool {
		if (
			strtolower( (string) ( $url['host'] ?? '' ) )
			!== strtolower( (string) ( $base['host'] ?? '' ) )
		) {
			return false;
		}

		$url_scheme  = strtolower( (string) ( $url['scheme'] ?? '' ) );
		$base_scheme = strtolower( (string) ( $base['scheme'] ?? '' ) );

		if (
			'' !== $url_scheme
			&& '' !== $base_scheme
			&& $url_scheme !== $base_scheme
		) {
			return false;
		}

		return $this->effective_port(
			$url_scheme,
			$url['port'] ?? null
		) === $this->effective_port(
			$base_scheme,
			$base['port'] ?? null
		);
	}

	private function effective_port(
		string $scheme,
		$port
	): ?int {
		if ( null !== $port ) {
			return (int) $port;
		}

		if ( 'https' === $scheme ) {
			return 443;
		}

		if ( 'http' === $scheme ) {
			return 80;
		}

		return null;
	}

	private function path_matches_base(
		string $path,
		string $base_path
	): bool {
		$path      = $this->normalize_path( $path );
		$base_path = $this->normalize_base_path( $base_path );

		if ( '' === $base_path ) {
			return true;
		}

		if ( $path === $base_path ) {
			return true;
		}

		return 0 === strpos(
			$path,
			rtrim( $base_path, '/' ) . '/'
		);
	}

	private function remove_base_path(
		string $path,
		string $base_path
	): string {
		$path      = $this->normalize_path( $path );
		$base_path = $this->normalize_base_path( $base_path );

		if ( '' === $base_path ) {
			return $path;
		}

		if ( $path === $base_path ) {
			return '/';
		}

		$prefix = rtrim( $base_path, '/' ) . '/';

		if ( 0 === strpos( $path, $prefix ) ) {
			$path = substr(
				$path,
				strlen( rtrim( $base_path, '/' ) )
			);
		}

		return $this->normalize_path( $path );
	}

	private function strip_backend_base_path(
		string $path
	): string {
		foreach ( $this->backend_bases as $base ) {
			$base_path = (string) $base['path'];

			if (
				'' !== $base_path
				&& $this->path_matches_base( $path, $base_path )
			) {
				return $this->remove_base_path(
					$path,
					$base_path
				);
			}
		}

		return $this->normalize_path( $path );
	}

	private function join_paths(
		string $base_path,
		string $path
	): string {
		$base_path = $this->normalize_base_path( $base_path );
		$path      = $this->normalize_path( $path );

		if ( '' === $base_path ) {
			return $path;
		}

		if ( '/' === $path ) {
			return rtrim( $base_path, '/' ) . '/';
		}

		return rtrim( $base_path, '/' )
			. '/'
			. ltrim( $path, '/' );
	}

	private function normalize_path( string $path ): string {
		if ( '' === $path ) {
			return '/';
		}

		if ( '/' !== $path[0] ) {
			$path = '/' . $path;
		}

		return $path;
	}

	private function normalize_base_path(
		string $path
	): string {
		$path = trim( $path );

		if ( '' === $path || '/' === $path ) {
			return '';
		}

		return '/' . trim( $path, '/' );
	}

	private function has_dangerous_scheme(
		string $url
	): bool {
		return 1 === preg_match(
			'/^\s*(?:javascript|data|vbscript)\s*:/i',
			$url
		);
	}

	private function has_passthrough_scheme(
		string $url
	): bool {
		return 1 === preg_match(
			'/^\s*(?:mailto|tel|sms)\s*:/i',
			$url
		);
	}

	private function is_blocked_path(
		string $path
	): bool {
		$blocked_paths = [
			'/wp-admin',
			'/wp-login.php',
			'/wp-json',
			'/wp-cron.php',
			'/xmlrpc.php',
		];

		if ( function_exists( 'apply_filters' ) ) {
			$blocked_paths = apply_filters(
				'headless_api_blocked_internal_url_paths',
				$blocked_paths,
				$this
			);
		}

		$paths_to_check = [
			strtolower( $this->normalize_path( $path ) ),
			strtolower(
				$this->normalize_path(
					rawurldecode( $path )
				)
			),
		];

		foreach ( $paths_to_check as $candidate ) {
			foreach ( (array) $blocked_paths as $blocked ) {
				$blocked = strtolower(
					$this->normalize_path( (string) $blocked )
				);

				if (
					$candidate === $blocked
					|| 0 === strpos(
						$candidate,
						rtrim( $blocked, '/' ) . '/'
					)
				) {
					return true;
				}
			}
		}

		return false;
	}

	private function contains_blocked_navigation_path(
		string $url
	): bool {
		if (
			'' === $url
			|| '#' === $url[0]
			|| '?' === $url[0]
		) {
			return false;
		}

		$parsed = $this->safe_parse_url( $url );

		if ( ! is_array( $parsed ) ) {
			return false;
		}

		return $this->is_blocked_path(
			(string) ( $parsed['path'] ?? '/' )
		);
	}

	private function safe_parse_url( string $url ) {
		if ( function_exists( 'wp_parse_url' ) ) {
			return wp_parse_url( $url );
		}

		return parse_url( $url );
	}

	private function default_frontend_url(): string {
		if (
			class_exists( '\TLU_Headless_API\Config' )
			&& method_exists(
				'\TLU_Headless_API\Config',
				'frontend_url'
			)
		) {
			return (string) \TLU_Headless_API\Config::frontend_url();
		}

		return '';
	}

	private function default_backend_urls(): array {
		$urls = [];

		if ( function_exists( 'home_url' ) ) {
			$urls[] = (string) home_url( '/' );
		}

		if ( function_exists( 'site_url' ) ) {
			$urls[] = (string) site_url( '/' );
		}

		return array_values(
			array_unique(
				array_filter( $urls )
			)
		);
	}

	private function apply_transform_filter(
		string $transformed,
		string $original,
		string $context
	): string {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $transformed;
		}

		$filtered = apply_filters(
			'headless_api_transform_url',
			$transformed,
			$original,
			$context,
			$this
		);

		return is_string( $filtered )
			? $filtered
			: $transformed;
	}
}

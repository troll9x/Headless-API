<?php
namespace TLU_Headless_API\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_REST_Request;
use WP_REST_Response;

/**
 * Tập hợp dữ liệu nội dung trang chủ thành một response có cache.
 *
 * Các truy vấn nội bộ gọi trực tiếp REST dispatcher của WordPress, không tạo
 * thêm HTTP/TLS round-trip từ Next.js cho từng chuyên mục và nhóm bài viết.
 */
final class HomepageSnapshotService {
	private const PAGE_SLUGS = [
		'hero'      => [ 'trang-chu', 'home', 'trang-home', 'homepage' ],
		'admissions'=> [ 'tuyen-sinh', 'admissions', 'thong-tin-tuyen-sinh' ],
	];

	private const CATEGORY_SLUGS = [
		'announcements' => [ 'thong-bao', 'thong-bao-chung', 'announcements', 'notices' ],
		'events'        => [ 'su-kien', 'hoat-dong', 'lich-su-kien', 'events' ],
		'feature_training' => [ 'dao-tao', 'education', 'thong-tin-luan-an-ts', 'cong-tac-dao-tao', 'training-activities' ],
		'feature_students' => [ 'guong-mat-sinh-vien', 'sinh-vien', 'students', 'student-activities' ],
		'feature_alumni' => [ 'cuu-sinh-vien', 'alumni' ],
		'partners'      => [ 'doi-tac', 'hop-tac', 'lien-ket', 'partners' ],
		'cooperation'   => [ 'tin-tuc-doi-ngoai', 'doi-ngoai', 'hop-tac-quoc-te', 'quoc-te', 'external-relations', 'ir-news', 'international-cooperation', 'international' ],
		'research'      => [ 'nghien-cuu', 'khoa-hoc-cong-nghe', 'research', 'science-and-technology', 'science-and-technology-activites', 'science' ],
		'community'     => [ 'vi-cong-dong', 'hoat-dong-xa-hoi', 'community', 'tin-tuc-chung' ],
		'moments'       => [ 'khoanhkhac', 'thu-vien-anh', 'moments', 'gallery', 'hinh-anh' ],
	];

	private const NEWS_CATEGORY_IDS = [
		'vi' => [ 1826, 2178, 1858, 1828, 1912 ],
		'en' => [ 3248 ],
	];

	private const POST_FIELDS = 'id,date,date_gmt,modified,modified_gmt,slug,status,type,link,canonical_path,title,excerpt,author,featured_media,sticky,format,categories,tags,meta,acf,_links,_embedded';

	/** @return array|\WP_Error */
	public function get_snapshot( string $locale ) {
		$pages = $this->request( '/wp/v2/pages', [
			'slug' => self::PAGE_SLUGS['hero'],
			'lang' => $locale,
			'per_page' => 100,
			'_embed' => 1,
		] );
		if ( is_wp_error( $pages ) ) {
			return $pages;
		}

		$admissions = $this->request( '/wp/v2/pages', [
			'slug' => self::PAGE_SLUGS['admissions'],
			'lang' => $locale,
			'per_page' => 100,
			'_embed' => 1,
		] );
		if ( is_wp_error( $admissions ) ) {
			return $admissions;
		}

		$announcements = $this->posts_by_slugs( self::CATEGORY_SLUGS['announcements'], 8, $locale );
		$events        = $this->posts_by_slugs( self::CATEGORY_SLUGS['events'], 12, $locale );
		$partners      = $this->posts_by_slugs( self::CATEGORY_SLUGS['partners'], 12, $locale );
		$cooperation   = $this->posts_by_slugs( self::CATEGORY_SLUGS['cooperation'], 6, $locale, true );
		$research      = $this->posts_by_slugs( self::CATEGORY_SLUGS['research'], 6, $locale, true );
		$community     = $this->posts_by_slugs( self::CATEGORY_SLUGS['community'], 6, $locale );
		$moments       = $this->posts_by_slugs( self::CATEGORY_SLUGS['moments'], 15, $locale );
		$training      = $this->latest_post_by_slugs( self::CATEGORY_SLUGS['feature_training'], $locale, true );
		$students      = $this->latest_post_by_slugs( self::CATEGORY_SLUGS['feature_students'], $locale );
		$alumni        = $this->latest_post_by_slugs( self::CATEGORY_SLUGS['feature_alumni'], $locale );

		foreach ( [ $announcements, $events, $partners, $cooperation, $research, $community, $moments ] as $result ) {
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		foreach ( [ $training, $students, $alumni ] as $result ) {
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$news = $this->homepage_news( $locale );
		if ( is_wp_error( $news ) ) {
			return $news;
		}

		$event_items = $this->add_event_fields( $events );

		return [
			'schema' => 1,
			'lang'   => $locale,
			'data'   => [
				'heroPage' => $this->find_page( $pages, self::PAGE_SLUGS['hero'] ),
				'announcements' => $announcements,
				'news' => $news,
				'events' => $event_items,
				'admissionsPage' => $this->find_page( $admissions, self::PAGE_SLUGS['admissions'] ),
				'featurePosts' => [
					'training' => $training,
					'students' => $students,
					'alumni'   => $alumni,
				],
				'partners' => $partners,
				'cooperation' => $cooperation,
				'research' => $research,
				'community' => $community,
				'moments' => $moments,
			],
		];
	}

	/** @return array|\WP_Error */
	private function homepage_news( string $locale ) {
		$posts = $this->request( '/wp/v2/posts', [
			'categories' => self::NEWS_CATEGORY_IDS[ $locale ],
			'per_page' => 10,
			'orderby' => 'date',
			'order' => 'desc',
			'lang' => $locale,
			'_embed' => 1,
			'_fields' => self::POST_FIELDS,
		] );
		if ( is_wp_error( $posts ) ) {
			return $posts;
		}

		$priority = $this->request( '/headless/v1/priority-posts', [ 'lang' => $locale ] );
		if ( is_wp_error( $priority ) ) {
			// Priority labels are optional; the ordinary news list remains valid.
			return $posts;
		}
		$items = $priority['data']['items'] ?? $priority['items'] ?? [];
		if ( ! is_array( $items ) || empty( $items ) ) {
			return $posts;
		}

		$by_id = [];
		foreach ( $posts as $post ) {
			if ( is_array( $post ) && ! empty( $post['id'] ) ) {
				$by_id[ (int) $post['id'] ] = $post;
			}
		}
		$missing_ids = [];
		foreach ( $items as $item ) {
			$id = absint( $item['id'] ?? 0 );
			if ( $id > 0 && ! isset( $by_id[ $id ] ) ) {
				$missing_ids[] = $id;
			}
		}
		if ( ! empty( $missing_ids ) ) {
			$missing = $this->request( '/wp/v2/posts', [
				'include' => array_values( array_unique( $missing_ids ) ),
				'per_page' => min( 100, count( $missing_ids ) ),
				'lang' => $locale,
				'_embed' => 1,
				'_fields' => self::POST_FIELDS,
			] );
			if ( is_array( $missing ) ) {
				foreach ( $missing as $post ) {
					if ( is_array( $post ) && ! empty( $post['id'] ) ) {
						$by_id[ (int) $post['id'] ] = $post;
					}
				}
			}
		}

		foreach ( $items as $item ) {
			$id = absint( $item['id'] ?? 0 );
			if ( ! isset( $by_id[ $id ] ) ) {
				continue;
			}
			$by_id[ $id ]['post_priority_label'] = sanitize_key( (string) ( $item['label'] ?? '' ) );
			$by_id[ $id ]['post_priority_order'] = absint( $item['order'] ?? 0 );
			$by_id[ $id ]['post_priority_expire_date'] = sanitize_text_field( (string) ( $item['expire_date'] ?? '' ) );
		}

		return array_values( $by_id );
	}

	/** @return array|\WP_Error */
	private function latest_post_by_slugs( array $slugs, string $locale, bool $include_children = false ) {
		$posts = $this->posts_by_slugs( $slugs, 1, $locale, $include_children );
		return is_array( $posts ) ? ( $posts[0] ?? null ) : $posts;
	}

	/** @return array|\WP_Error */
	private function posts_by_slugs( array $slugs, int $limit, string $locale, bool $include_children = false ) {
		$categories = $this->request( '/wp/v2/categories', [
			'slug' => $slugs,
			'lang' => $locale,
			'per_page' => 100,
		] );
		if ( is_wp_error( $categories ) ) {
			return $categories;
		}
		$by_slug = [];
		foreach ( $categories as $category ) {
			if ( is_array( $category ) && isset( $category['slug'], $category['id'] ) ) {
				$by_slug[ (string) $category['slug'] ] = $category;
			}
		}

		foreach ( $slugs as $slug ) {
			if ( empty( $by_slug[ $slug ]['id'] ) ) {
				continue;
			}
			$category_ids = [ (int) $by_slug[ $slug ]['id'] ];
			if ( $include_children ) {
				$children = get_term_children( $category_ids[0], 'category' );
				if ( ! is_wp_error( $children ) ) {
					$category_ids = array_values( array_unique( array_merge( $category_ids, array_map( 'absint', $children ) ) ) );
				}
			}
			$posts = $this->request( '/wp/v2/posts', [
				'categories' => $category_ids,
				'per_page' => $limit,
				'orderby' => 'date',
				'order' => 'desc',
				'lang' => $locale,
				'_embed' => 1,
				'_fields' => self::POST_FIELDS,
			] );
			if ( is_wp_error( $posts ) ) {
				return $posts;
			}
			if ( ! empty( $posts ) ) {
				return $posts;
			}
		}

		return [];
	}

	private function add_event_fields( array $posts ): array {
		if ( ! function_exists( 'get_field' ) ) {
			return $posts;
		}
		$public_event_fields = [ 'ngay_bat_dau_su_kien', 'thoi_gian_ket_thuc', '_priority_label', '_priority_order', '_priority_expire' ];
		foreach ( $posts as &$post ) {
			$id = absint( $post['id'] ?? 0 );
			if ( 0 === $id ) {
				continue;
			}
			$acf = [];
			foreach ( $public_event_fields as $key ) {
				$value = get_field( $key, $id );
				if ( is_scalar( $value ) || null === $value ) {
					$acf[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
				}
			}
			$post['acf'] = array_merge( is_array( $post['acf'] ?? null ) ? $post['acf'] : [], $acf );
		}
		unset( $post );
		return $posts;
	}

	/** @return mixed|\WP_Error */
	private function request( string $route, array $params = [] ) {
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_query_params( $params );
		$response = rest_do_request( $request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$response = rest_ensure_response( $response );
		if ( ! $response instanceof WP_REST_Response ) {
			return new \WP_Error( 'homepage_invalid_internal_response', 'The WordPress API returned an invalid internal response.', [ 'status' => 502 ] );
		}
		if ( $response->get_status() < 200 || $response->get_status() >= 300 ) {
			return new \WP_Error( 'homepage_internal_api_failed', 'A required WordPress API request failed.', [ 'status' => 503 ] );
		}
		return rest_get_server()->response_to_data( $response, true );
	}

	private function find_page( array $pages, array $slugs ): ?array {
		$by_slug = [];
		foreach ( $pages as $page ) {
			if ( is_array( $page ) && isset( $page['slug'] ) ) {
				$by_slug[ (string) $page['slug'] ] = $page;
			}
		}
		foreach ( $slugs as $slug ) {
			if ( isset( $by_slug[ $slug ] ) ) {
				return $by_slug[ $slug ];
			}
		}
		return null;
	}
}

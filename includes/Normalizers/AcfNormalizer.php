<?php

namespace TLU_Headless_API\Normalizers;

use TLU_Headless_API\Services\UrlTransformer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dispatcher chuẩn hóa ACF field theo type.
 */
class AcfNormalizer {

	private MediaNormalizer $media;
	private LinkNormalizer $link;
	private RelationshipNormalizer $relationship;
	private TaxonomyNormalizer $taxonomy;
	private UserNormalizer $user;
	private UrlTransformer $transformer;

	public function __construct(
		?MediaNormalizer $media = null,
		?LinkNormalizer $link = null,
		?RelationshipNormalizer $relationship = null,
		?TaxonomyNormalizer $taxonomy = null,
		?UserNormalizer $user = null,
		?UrlTransformer $transformer = null
	) {
		$this->transformer  = $transformer ?? new UrlTransformer();
		$this->media        = $media ?? new MediaNormalizer( $this->transformer );
		$this->link         = $link ?? new LinkNormalizer( $this->transformer );
		$this->relationship = $relationship ?? new RelationshipNormalizer();
		$this->taxonomy     = $taxonomy ?? new TaxonomyNormalizer();
		$this->user         = $user ?? new UserNormalizer();
	}

	public function normalize(
		$value,
		string $type = 'text',
		array $field = []
	): mixed {
		switch ( $type ) {
			case 'text':
				return $this->normalize_text( $value );

			case 'textarea':
				return $this->normalize_textarea( $value );

			case 'email':
				return $this->normalize_email( $value );

			case 'password':
				return null;

			case 'image':
			case 'file':
				return $this->media->normalize( $value );

			case 'gallery':
				return $this->media->normalize_gallery( $value );

			case 'url':
				return $this->transformer->transform_navigation_url(
					(string) $value
				);

			case 'wysiwyg':
				return $this->normalize_wysiwyg( $value );

			case 'oembed':
				return $this->normalize_oembed( $value );

			case 'link':
			case 'page_link':
				return $this->link->normalize( $value );

			case 'relationship':
			case 'post_object':
				return $this->relationship->normalize( $value );

			case 'taxonomy':
				return $this->taxonomy->normalize( $value );

			case 'user':
				return $this->user->normalize( $value );

			case 'repeater':
				return $this->normalize_repeater(
					$value,
					$field['sub_fields'] ?? []
				);

			case 'group':
			case 'clone':
				return $this->normalize_group(
					$value,
					$field['sub_fields'] ?? []
				);

			case 'flexible_content':
				return $this->normalize_flexible(
					$value,
					$field['layouts'] ?? []
				);

			case 'true_false':
				return (bool) $value;

			case 'number':
			case 'range':
				return is_numeric( $value ) ? (float) $value : null;

			case 'select':
			case 'radio':
			case 'button_group':
				return $this->normalize_choice( $value, $field, ! empty( $field['multiple'] ) );

			case 'checkbox':
				return $this->normalize_choice( $value, $field, true );

			case 'google_map':
				return $this->normalize_google_map( $value );

			case 'date_picker':
				return $this->normalize_date( $value );

			case 'date_time_picker':
				return $this->normalize_date_time( $value );

			case 'time_picker':
				return $this->normalize_time( $value );

			case 'color_picker':
				return $this->normalize_color( $value );

			case 'icon_picker':
				return $this->normalize_icon( $value );

			case 'message':
			case 'accordion':
			case 'tab':
				return null;

			default:
				return $value;
		}
	}

	private function normalize_text( $value ): string {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	private function normalize_textarea( $value ): string {
		return is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';
	}

	private function normalize_email( $value ): string {
		$email = is_scalar( $value ) ? sanitize_email( (string) $value ) : '';
		return is_email( $email ) ? $email : '';
	}

	private function normalize_wysiwyg( $value ): string {
		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			return '';
		}

		$content = apply_filters( 'the_content', (string) $value );
		return wp_kses_post( $content );
	}

	private function normalize_oembed( $value ): array {
		$url = is_scalar( $value ) ? esc_url_raw( trim( (string) $value ) ) : '';
		if ( '' === $url ) {
			return [ 'url' => '', 'html' => '' ];
		}

		$html = function_exists( 'wp_oembed_get' ) ? wp_oembed_get( $url ) : false;

		return [
			'url'  => $url,
			'html' => is_string( $html ) ? $this->sanitize_embed_html( $html ) : '',
		];
	}

	private function sanitize_embed_html( string $html ): string {
		$allowed = wp_kses_allowed_html( 'post' );
		$allowed['iframe'] = [
			'allow'            => true,
			'allowfullscreen'  => true,
			'frameborder'      => true,
			'height'           => true,
			'loading'          => true,
			'referrerpolicy'   => true,
			'sandbox'          => true,
			'src'              => true,
			'title'            => true,
			'width'            => true,
		];

		return wp_kses( $html, $allowed );
	}

	private function normalize_choice( $value, array $field, bool $multiple ): array {
		$choices = is_array( $field['choices'] ?? null ) ? $field['choices'] : [];
		$values  = $multiple && ! $this->is_choice_item( $value )
			? ( is_array( $value ) ? $value : ( [] === $value || null === $value || '' === $value ? [] : [ $value ] ) )
			: [ $value ];

		$result = [];
		foreach ( $values as $item ) {
			$normalized = $this->normalize_choice_item( $item, $choices );
			if ( '' !== $normalized['value'] ) {
				$result[] = $normalized;
			}
		}

		if ( $multiple ) {
			return $result;
		}

		return $result[0] ?? [ 'value' => '', 'label' => '' ];
	}

	private function is_choice_item( $value ): bool {
		return is_array( $value ) && array_key_exists( 'value', $value );
	}

	private function normalize_choice_item( $value, array $choices ): array {
		$raw_value = is_array( $value ) ? ( $value['value'] ?? '' ) : $value;
		$raw_label = is_array( $value ) ? ( $value['label'] ?? null ) : null;
		$choice    = is_scalar( $raw_value ) ? sanitize_text_field( (string) $raw_value ) : '';
		$label     = null === $raw_label ? ( $this->find_choice_label( $choices, $choice ) ?? $choice ) : $raw_label;

		return [
			'value' => $choice,
			'label' => is_scalar( $label ) ? sanitize_text_field( (string) $label ) : '',
		];
	}

	private function find_choice_label( array $choices, string $value ): ?string {
		if ( array_key_exists( $value, $choices ) && is_scalar( $choices[ $value ] ) ) {
			return (string) $choices[ $value ];
		}

		foreach ( $choices as $choice ) {
			if ( is_array( $choice ) ) {
				$label = $this->find_choice_label( $choice, $value );
				if ( null !== $label ) {
					return $label;
				}
			}
		}

		return null;
	}

	private function normalize_google_map( $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}

		$coordinates = [];
		foreach ( [ 'lat', 'lng' ] as $key ) {
			$coordinates[ $key ] = isset( $value[ $key ] ) && is_numeric( $value[ $key ] ) ? (float) $value[ $key ] : null;
		}

		return [
			'address'       => sanitize_text_field( (string) ( $value['address'] ?? $value['formatted_address'] ?? '' ) ),
			'lat'           => $coordinates['lat'],
			'lng'           => $coordinates['lng'],
			'zoom'          => isset( $value['zoom'] ) && is_numeric( $value['zoom'] ) ? (int) $value['zoom'] : null,
			'place_id'      => sanitize_text_field( (string) ( $value['place_id'] ?? '' ) ),
			'city'          => sanitize_text_field( (string) ( $value['city'] ?? '' ) ),
			'state'         => sanitize_text_field( (string) ( $value['state'] ?? '' ) ),
			'post_code'     => sanitize_text_field( (string) ( $value['post_code'] ?? '' ) ),
			'country'       => sanitize_text_field( (string) ( $value['country'] ?? '' ) ),
			'country_short' => sanitize_text_field( (string) ( $value['country_short'] ?? '' ) ),
		];
	}

	private function normalize_date( $value ): ?string {
		$date = $this->parse_date_time( $value, [ 'Ymd', 'Y-m-d', 'd/m/Y', 'm/d/Y' ] );
		return $date ? $date->format( 'Y-m-d' ) : null;
	}

	private function normalize_date_time( $value ): ?string {
		$date = $this->parse_date_time( $value, [ 'Y-m-d H:i:s', 'Y-m-d H:i', 'Ymd H:i:s', 'Ymd H:i', DATE_ATOM ] );
		return $date ? $date->format( DATE_ATOM ) : null;
	}

	private function normalize_time( $value ): ?string {
		$date = $this->parse_date_time( $value, [ 'H:i:s', 'H:i', 'g:i A', 'g:i a' ] );
		return $date ? $date->format( 'H:i:s' ) : null;
	}

	private function parse_date_time( $value, array $formats ): ?\DateTimeImmutable {
		if ( $value instanceof \DateTimeInterface ) {
			return \DateTimeImmutable::createFromInterface( $value );
		}

		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			return null;
		}

		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		foreach ( $formats as $format ) {
			$date = \DateTimeImmutable::createFromFormat( $format, trim( (string) $value ), $timezone );
			$errors = \DateTimeImmutable::getLastErrors();
			if ( $date instanceof \DateTimeImmutable && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) ) {
				return $date;
			}
		}

		return null;
	}

	private function normalize_color( $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$color = sanitize_hex_color( trim( (string) $value ) );
		return is_string( $color ) ? $color : null;
	}

	private function normalize_icon( $value ): array {
		if ( is_string( $value ) ) {
			return [
				'type'  => 'class',
				'value' => $this->sanitize_icon_value( $value ),
				'url'   => '',
			];
		}

		if ( ! is_array( $value ) ) {
			return [ 'type' => '', 'value' => '', 'url' => '' ];
		}

		$url = esc_url_raw( (string) ( $value['url'] ?? '' ) );
		return [
			'type'  => sanitize_key( (string) ( $value['type'] ?? ( '' !== $url ? 'url' : 'class' ) ) ),
			'value' => $this->sanitize_icon_value( (string) ( $value['value'] ?? $value['class'] ?? $value['icon'] ?? '' ) ),
			'url'   => $url,
		];
	}

	private function sanitize_icon_value( string $value ): string {
		return trim( (string) preg_replace( '/[^A-Za-z0-9_\-\s:]/', '', $value ) );
	}

	public function normalize_field_objects(
		array $field_objects
	): array {
		$result = [];

		foreach ( $field_objects as $name => $field ) {
			$result[ $name ] = $this->normalize(
				$field['value'],
				$field['type'] ?? 'text',
				$field
			);
		}

		return $result;
	}

	private function normalize_repeater(
		$rows,
		array $sub_fields
	): array {
		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return [];
		}

		$type_map = [];

		foreach ( $sub_fields as $sub_field ) {
			if ( isset( $sub_field['name'] ) ) {
				$type_map[ $sub_field['name'] ] = $sub_field;
			}
		}

		return array_values(
			array_map(
				function ( $row ) use ( $type_map ): array {
					if ( ! is_array( $row ) ) {
						return [];
					}

					$normalized = [];

					foreach ( $row as $key => $value ) {
						$field = $type_map[ $key ] ?? [];

						$normalized[ $key ] = $this->normalize(
							$value,
							$field['type'] ?? 'text',
							$field
						);
					}

					return $normalized;
				},
				$rows
			)
		);
	}

	private function normalize_group(
		$value,
		array $sub_fields
	): array {
		if ( empty( $value ) || ! is_array( $value ) ) {
			return [];
		}

		$type_map = [];

		foreach ( $sub_fields as $sub_field ) {
			if ( isset( $sub_field['name'] ) ) {
				$type_map[ $sub_field['name'] ] = $sub_field;
			}
		}

		$result = [];

		foreach ( $value as $key => $item_value ) {
			$field = $type_map[ $key ] ?? [];

			$result[ $key ] = $this->normalize(
				$item_value,
				$field['type'] ?? 'text',
				$field
			);
		}

		return $result;
	}

	private function normalize_flexible(
		$blocks,
		array $layouts
	): array {
		if ( empty( $blocks ) || ! is_array( $blocks ) ) {
			return [];
		}

		$layout_map = [];

		foreach ( $layouts as $layout ) {
			$name = $layout['name'] ?? '';

			if ( '' === $name ) {
				continue;
			}

			$sub_map = [];

			foreach ( $layout['sub_fields'] ?? [] as $sub_field ) {
				if ( isset( $sub_field['name'] ) ) {
					$sub_map[ $sub_field['name'] ] = $sub_field;
				}
			}

			$layout_map[ $name ] = $sub_map;
		}

		$result = [];

		foreach ( $blocks as $block ) {
			if (
				! is_array( $block )
				|| ! isset( $block['acf_fc_layout'] )
			) {
				continue;
			}

			$layout_name = (string) $block['acf_fc_layout'];
			$sub_fields  = $layout_map[ $layout_name ] ?? [];
			$data        = [];

			foreach ( $block as $key => $value ) {
				if ( 'acf_fc_layout' === $key ) {
					continue;
				}

				$field = $sub_fields[ $key ] ?? [];

				$data[ $key ] = $this->normalize(
					$value,
					$field['type'] ?? 'text',
					$field
				);
			}

			$result[] = [
				'layout' => $layout_name,
				'data'   => $data,
			];
		}

		return $result;
	}
}

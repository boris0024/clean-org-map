<?php
/**
 * Данные точки на сайте: адрес, телефон, часы — из карточки в Яндекс Картах.
 *
 * Значение отдаётся готовым HTML на сервере (робот поисковика видит сам адрес, а не шорткод):
 *
 *   [clean_org_map_field]                                  — адрес точки по умолчанию
 *   [clean_org_map_field field="phone" location="spb"]
 *   [clean_org_map_field field="address" link="org"]       — ссылкой на карточку (org) или маршрут (route)
 *
 *   <?php clean_org_map_the_field( 'address', 'spb' ); ?>  — в теме, экранировано
 *   clean_org_map_field( 'address', 'spb' )                — строка без экранирования
 *
 * Подстановка в поля ACF без правки темы: Настройки → Clean Org Map → «Подставлять в поля ACF».
 * Строка `contacts-contacts_address@options = address` — и везде, где тема выводит это поле
 * (the_field / get_field), будет адрес из карточки. Пустое значение из Яндекса поле не трогает.
 */

defined( 'ABSPATH' ) || exit;

/** Какие поля точки можно выводить. */
function clean_org_map_output_fields() {
	return [
		'address'      => 'Адрес',
		'full_address' => 'Полный адрес',
		'title'        => 'Название',
		'phone'        => 'Телефон',
		'hours'        => 'Часы работы',
		'rating'       => 'Рейтинг',
		'reviews'      => 'Число отзывов',
		'org_url'      => 'Ссылка на карточку',
	];
}

/**
 * Значение поля точки: ручная правка из настроек, иначе — из карточки в Яндекс Картах.
 *
 * @return string Пустая строка, если точки или значения нет.
 */
function clean_org_map_field( $field = 'address', $location = '' ) {
	$loc = clean_org_map_get_location( $location );
	if ( ! $loc ) {
		return '';
	}
	if ( isset( $loc[ $field ] ) ) {
		$value = $loc[ $field ];
	} else {
		// Поля, которых нет в ручном слое (полный адрес, индекс), — прямо из карточки.
		$raw   = clean_org_map_settings()['locations'][ $loc['key'] ] ?? [];
		$value = $raw['yandex'][ $field ] ?? '';
	}
	$value = trim( (string) $value );
	// Яндекс пишет «наб. реки…», «ул. …» со строчной — на сайте адрес часто стоит в начале строки.
	if ( in_array( $field, [ 'address', 'full_address' ], true ) && '' !== $value ) {
		$value = mb_strtoupper( mb_substr( $value, 0, 1 ) ) . mb_substr( $value, 1 );
	}
	return (string) apply_filters( 'clean_org_map_field', $value, $field, $loc );
}

function clean_org_map_the_field( $field = 'address', $location = '' ) {
	echo esc_html( clean_org_map_field( $field, $location ) );
}

add_shortcode( 'clean_org_map_field', function ( $atts ) {
	$atts  = shortcode_atts( [ 'field' => 'address', 'location' => '', 'link' => '', 'class' => '' ], $atts, 'clean_org_map_field' );
	$value = clean_org_map_field( $atts['field'], $atts['location'] );
	if ( '' === $value ) {
		return '';
	}

	$html = esc_html( $value );
	if ( in_array( $atts['link'], [ 'org', 'route' ], true ) ) {
		$loc = clean_org_map_get_location( $atts['location'] );
		$url = $loc[ 'org' === $atts['link'] ? 'org_url' : 'route_url' ] ?? '';
		if ( $url ) {
			$html = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . $html . '</a>';
		}
	}
	return $atts['class'] ? '<span class="' . esc_attr( $atts['class'] ) . '">' . $html . '</span>' : $html;
} );

/**
 * Разбор строк «Подставлять в поля ACF»: `имя_поля[@options|@тип_записи] [= поле_точки]`.
 *
 * @return array[] [ [ 'name' => …, 'scope' => '' | 'options' | тип записи, 'field' => … ], … ]
 */
function clean_org_map_parse_acf_map( $text ) {
	$rules = [];
	foreach ( preg_split( '/\R/', (string) $text ) as $line ) {
		$line = trim( $line );
		if ( '' === $line || '#' === $line[0] ) {
			continue;
		}
		$parts = array_map( 'trim', explode( '=', $line, 2 ) );
		$field = $parts[1] ?? 'address';
		if ( ! isset( clean_org_map_output_fields()[ $field ] ) || ! preg_match( '/^([A-Za-z0-9_\-]+)(?:@([a-z0-9_\-]+))?$/', $parts[0], $m ) ) {
			continue;
		}
		$rules[] = [ 'name' => $m[1], 'scope' => $m[2] ?? '', 'field' => $field ];
	}
	return $rules;
}

// Подстановка в поля ACF: один фильтр на все поля, правила — из настроек точек.
add_action( 'acf/init', 'clean_org_map_register_acf_filters' );

function clean_org_map_register_acf_filters() {
	$rules = [];
	foreach ( clean_org_map_settings()['locations'] as $key => $raw ) {
		foreach ( clean_org_map_parse_acf_map( $raw['acf_map'] ?? '' ) as $rule ) {
			$rules[ $rule['name'] ][] = $rule + [ 'location' => $key ];
		}
	}
	if ( ! $rules ) {
		return;
	}
	// Вариант acf/format_value/name= у подполя группы видит только короткое имя
	// («address» вместо «contacts_address»), поэтому сверяем сами: сначала полное имя, потом короткое.
	add_filter( 'acf/format_value', function ( $value, $post_id, $field ) use ( $rules ) {
		if ( ! is_scalar( $value ) ) {
			return $value;
		}
		foreach ( [ $field['name'] ?? '', $field['_name'] ?? '' ] as $name ) {
			foreach ( $rules[ $name ] ?? [] as $rule ) {
				if ( clean_org_map_acf_scope_matches( $rule['scope'], $post_id ) ) {
					$new = clean_org_map_field( $rule['field'], $rule['location'] );
					// Поле выводится темой как есть (the_field) — отдаём уже экранированный текст.
					return '' !== $new ? esc_html( $new ) : $value;
				}
			}
		}
		return $value;
	}, 20, 3 );
}

function clean_org_map_acf_scope_matches( $scope, $post_id ) {
	if ( '' === $scope ) {
		return true;
	}
	$is_options = is_string( $post_id ) && in_array( $post_id, [ 'options', 'option' ], true );
	if ( 'options' === $scope ) {
		return $is_options;
	}
	return ! $is_options && is_numeric( $post_id ) && get_post_type( (int) $post_id ) === $scope;
}

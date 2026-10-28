<?php
/**
 * Хранение настроек и сборка данных точки.
 *
 * У каждой точки два слоя: `yandex` — что пришло из Яндекс Карт, и `manual` —
 * ручные правки из настроек. Показывается ручное значение, если оно заполнено,
 * иначе яндексовое. Еженедельное обновление трогает только слой `yandex`.
 */

defined( 'ABSPATH' ) || exit;

const CLEAN_ORG_MAP_FIELDS = [ 'title', 'address', 'phone', 'hours', 'lat', 'lon' ];

function clean_org_map_defaults() {
	return [
		// vector — MapLibre GL со стилем OpenFreeMap (бесплатно, без ключа, коммерческое использование разрешено);
		// raster — Leaflet с растровыми тайлами (лёгкий запасной вариант).
		'engine'      => 'vector',
		'style'       => 'https://tiles.openfreemap.org/styles/bright',
		'lang'        => 'ru',
		'one_font'    => true, // одно начертание подписей вместо трёх — меньше шрифтов качать
		'tiles'       => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
		'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
		'zoom'        => 16,
		'height'      => 400,
		'locations'   => [],
	];
}

function clean_org_map_settings() {
	$saved = get_option( CLEAN_ORG_MAP_OPTION, [] );
	return wp_parse_args( is_array( $saved ) ? $saved : [], clean_org_map_defaults() );
}

/**
 * Данные точки для вывода.
 *
 * @param string $key Ключ точки из настроек; пусто — точка по умолчанию
 *                    (фильтр `clean_org_map_default_location`, иначе первая).
 * @return array|null
 */
function clean_org_map_get_location( $key = '' ) {
	$settings  = clean_org_map_settings();
	$locations = $settings['locations'];
	if ( ! $locations ) {
		return null;
	}

	if ( '' === (string) $key ) {
		$key = (string) apply_filters( 'clean_org_map_default_location', array_key_first( $locations ) );
	}
	if ( ! isset( $locations[ $key ] ) ) {
		return null;
	}

	$raw = $locations[ $key ];
	$loc = [
		'key'     => $key,
		'org_id'  => $raw['org_id'] ?? '',
		'rating'  => $raw['yandex']['rating'] ?? '',
		'reviews' => $raw['yandex']['reviews'] ?? '',
		'zoom'    => (int) ( $raw['zoom'] ?? 0 ) ?: (int) $settings['zoom'],
	];
	foreach ( CLEAN_ORG_MAP_FIELDS as $field ) {
		$manual        = trim( (string) ( $raw['manual'][ $field ] ?? '' ) );
		$loc[ $field ] = '' !== $manual ? $manual : trim( (string) ( $raw['yandex'][ $field ] ?? '' ) );
	}

	$loc['org_url']   = $raw['yandex']['url'] ?? ( $loc['org_id'] ? 'https://yandex.ru/maps/org/' . $loc['org_id'] . '/' : '' );
	$loc['route_url'] = clean_org_map_route_url( $loc );

	return apply_filters( 'clean_org_map_location', $loc, $key );
}

/**
 * Маршрут в Яндекс Картах до точки. `ruri` привязывает конец маршрута
 * к самой организации, а не к безымянной точке на карте.
 */
function clean_org_map_route_url( $loc ) {
	if ( '' === $loc['lat'] || '' === $loc['lon'] ) {
		return '';
	}
	$args = [
		'rtext' => '~' . $loc['lat'] . ',' . $loc['lon'],
		'rtt'   => 'auto',
	];
	if ( $loc['org_id'] ) {
		$args['ruri'] = '~ymapsbm1://org?oid=' . $loc['org_id'];
	}
	return 'https://yandex.ru/maps/?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
}

function clean_org_map_refresh_all() {
	$settings = clean_org_map_settings();
	$changed  = false;

	foreach ( $settings['locations'] as $key => $raw ) {
		if ( empty( $raw['org_id'] ) ) {
			continue;
		}
		$data = clean_org_map_fetch_org( $raw['org_id'] );
		if ( is_wp_error( $data ) ) {
			// Старые данные остаются, ошибку видно в настройках.
			$settings['locations'][ $key ]['error'] = $data->get_error_message();
		} else {
			$merged = clean_org_map_merge_yandex( $raw['yandex'] ?? [], $data );
			$settings['locations'][ $key ]['yandex'] = $merged['data'];
			$settings['locations'][ $key ]['error']  = $merged['notice'];
		}
		$changed = true;
	}

	if ( $changed ) {
		update_option( CLEAN_ORG_MAP_OPTION, $settings, false );
	}
}

/**
 * Свежие данные из Яндекса поверх прежних. Пустое поле прежнее значение не стирает:
 * Яндекс отдаёт карточку без адреса или телефона и при сбое, и когда адрес в ней скрыт
 * («Оказывает услуги в …»), — без этой защиты адрес пропал бы с сайта молча.
 *
 * @return array [ 'data' => …, 'notice' => текст для настроек или '' ]
 */
function clean_org_map_merge_yandex( $old, $new ) {
	$labels = clean_org_map_output_fields() + [ 'postal_code' => 'Индекс' ];
	$kept   = [];
	foreach ( [ 'title', 'address', 'full_address', 'postal_code', 'phone', 'hours' ] as $field ) {
		if ( '' === (string) ( $new[ $field ] ?? '' ) && '' !== (string) ( $old[ $field ] ?? '' ) ) {
			$new[ $field ] = $old[ $field ];
			$kept[]        = mb_strtolower( $labels[ $field ] ?? $field );
		}
	}
	return [
		'data'   => $new,
		'notice' => $kept ? 'В карточке Яндекса сейчас нет: ' . implode( ', ', $kept ) . '. На сайте оставлены прежние значения — проверьте карточку или впишите вручную.' : '',
	];
}

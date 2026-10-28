<?php
/**
 * Вывод карты: шорткод [clean_org_map] и функции для темы.
 *
 *   [clean_org_map]                          — точка по умолчанию
 *   [clean_org_map location="spb" height="500" buttons="0"]
 *   [clean_org_map location="moskva,spb"]               — несколько точек сразу
 *
 *   clean_org_map_render( [ 'points' => [ [ 'lat' => 55.7, 'lon' => 37.6, 'title' => 'Объект', 'url' => '/…' ] ] ] )
 *
 *   <?php echo clean_org_map_render( [ 'location' => 'spb', 'height' => '100%' ] ); ?>
 *   <?php echo clean_org_map_buttons( clean_org_map_get_location( 'spb' ) ); ?>
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', function () {
	wp_register_style( 'clean-org-map', CLEAN_ORG_MAP_URL . 'assets/clean-org-map.css', [], CLEAN_ORG_MAP_VERSION );
	wp_register_script( 'clean-org-map', CLEAN_ORG_MAP_URL . 'assets/clean-org-map.js', [], CLEAN_ORG_MAP_VERSION, true );
} );

add_shortcode( 'clean_org_map', function ( $atts ) {
	$atts = shortcode_atts( [
		'location' => '',
		'height'   => '',
		'zoom'     => '',
		'buttons'  => '1',
		'class'    => '',
	], $atts, 'clean_org_map' );

	$atts['buttons'] = ! in_array( strtolower( (string) $atts['buttons'] ), [ '0', 'no', 'false', 'нет' ], true );
	return clean_org_map_render( $atts );
} );

/**
 * HTML карты. Leaflet подгружается скриптом, только когда карта близко к экрану.
 */
function clean_org_map_render( $args = [] ) {
	$args = wp_parse_args( $args, [
		'location' => '',
		'height'   => '',
		'zoom'     => '',
		'buttons'  => true,
		'class'    => '',
		// Сколько пикселей карты закрыто вёрсткой (например, карточкой поверх
		// карты): метка центрируется в оставшейся видимой части.
		// [ 'top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0 ]
		'padding'        => [],
		'padding_mobile' => [], // экраны уже 768 px
		// CSS-селектор плашки поверх карты: карта сама обходит её на любой ширине экрана.
		'avoid'          => '',
		'points'         => [],
		// Своя иконка метки (URL картинки) и её размер в px; пусто — стандартная метка.
		'marker'         => '',
		'marker_size'    => 40,
	] );

	// Точки: одна или несколько из настроек ('moskva' / 'moskva,spb' / [ 'moskva', 'spb' ])
	// либо произвольные метки 'points' => [ [ 'lat', 'lon', 'title', 'address', 'description', 'image', 'url' ], … ].
	$keys = is_array( $args['location'] ) ? $args['location'] : array_filter( array_map( 'trim', explode( ',', (string) $args['location'] ) ) );
	$locs = [];
	foreach ( $keys ?: [ '' ] as $key ) {
		$loc = clean_org_map_get_location( $key );
		if ( $loc && '' !== $loc['lat'] && '' !== $loc['lon'] ) {
			$locs[] = $loc;
		}
	}

	$points = [];
	if ( ! empty( $args['points'] ) ) {
		foreach ( (array) $args['points'] as $p ) {
			if ( isset( $p['lat'], $p['lon'] ) && is_numeric( $p['lat'] ) && is_numeric( $p['lon'] ) ) {
				$points[] = [
					'lat'         => (float) $p['lat'],
					'lon'         => (float) $p['lon'],
					'title'       => (string) ( $p['title'] ?? '' ),
					'address'     => (string) ( $p['address'] ?? '' ),
					'description' => (string) ( $p['description'] ?? '' ),
					'image'       => esc_url_raw( (string) ( $p['image'] ?? '' ) ),
					'url'         => esc_url_raw( (string) ( $p['url'] ?? '' ) ),
				];
			}
		}
		$locs = [];
	} else {
		foreach ( $locs as $loc ) {
			// description и image точке может добавить тема фильтром clean_org_map_location.
			$points[] = [
				'lat'         => (float) $loc['lat'],
				'lon'         => (float) $loc['lon'],
				'title'       => $loc['title'],
				'address'     => clean_org_map_field( 'address', $loc['key'] ),
				'description' => (string) ( $loc['description'] ?? '' ),
				'image'       => esc_url_raw( (string) ( $loc['image'] ?? '' ) ),
				'orgUrl'      => $loc['org_url'],
				'routeUrl'    => $loc['route_url'],
			];
		}
	}

	if ( ! $points ) {
		return current_user_can( 'manage_options' )
			? '<p class="clean-org-map-error">Clean Org Map: точка «' . esc_html( implode( ',', (array) $args['location'] ) ) . '» не найдена или без координат. Проверьте Настройки → Clean Org Map.</p>'
			: '';
	}

	$settings = clean_org_map_settings();
	$height   = clean_org_map_css_length( $args['height'] ) ?: (int) $settings['height'] . 'px';
	$zoom     = (int) $args['zoom'] ?: ( $locs ? $locs[0]['zoom'] : (int) $settings['zoom'] );

	wp_enqueue_style( 'clean-org-map' );
	wp_enqueue_script( 'clean-org-map' );

	$config = [
		'points'      => $points,
		'zoom'        => $zoom,
		'engine'      => $settings['engine'],
		'style'       => $settings['style'],
		'lang'        => $settings['lang'],
		'oneFont'     => (bool) $settings['one_font'],
		'tiles'       => $settings['tiles'],
		'attribution' => $settings['attribution'],
		'leaflet'     => CLEAN_ORG_MAP_URL . 'assets/leaflet/',
		'maplibre'    => CLEAN_ORG_MAP_URL . 'assets/maplibre/',
		'padding'     => clean_org_map_padding( $args['padding'] ),
		'paddingMob'  => clean_org_map_padding( $args['padding_mobile'] ),
		'avoid'       => (string) $args['avoid'],
		'marker'      => esc_url_raw( (string) $args['marker'] ),
		'markerSize'  => min( 128, max( 16, (int) $args['marker_size'] ) ),
	];

	// Высота в процентах считается от обёртки — растягиваем и её.
	$wrap  = '%' === substr( $height, -1 ) ? ' style="height:100%"' : '';
	$html  = '<div class="clean-org-map ' . esc_attr( $args['class'] ) . '"' . $wrap . '>';
	$html .= '<div class="clean-org-map__canvas" style="height:' . esc_attr( $height ) . '" data-clean-org-map="' . esc_attr( wp_json_encode( $config ) ) . '">';
	if ( $locs ) {
		$html .= '<a class="clean-org-map__fallback" href="' . esc_url( $locs[0]['org_url'] ) . '" target="_blank" rel="noopener">'
			. esc_html( trim( $locs[0]['title'] . ', ' . clean_org_map_field( 'address', $locs[0]['key'] ), ', ' ) ) . ' — открыть в Яндекс Картах</a>';
	} else {
		$html .= '<span class="clean-org-map__fallback">Карта загружается…</span>';
	}
	$html .= '</div>';
	if ( $args['buttons'] && 1 === count( $locs ) ) {
		$html .= clean_org_map_buttons( $locs[0] );
	}
	$html .= '</div>';

	return $html;
}

/**
 * Кнопки «Открыть в Яндекс Картах» и «Построить маршрут».
 *
 * @param array  $loc   Результат clean_org_map_get_location().
 * @param string $class CSS-класс ссылок, если тема хочет свои стили.
 */
function clean_org_map_buttons( $loc, $class = '' ) {
	if ( ! $loc ) {
		return '';
	}
	wp_enqueue_style( 'clean-org-map' );

	$class = $class ?: 'clean-org-map__button';
	$html  = '<div class="clean-org-map__buttons">';
	if ( $loc['org_url'] ) {
		$html .= '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $loc['org_url'] ) . '" target="_blank" rel="noopener">Открыть в Яндекс Картах</a>';
	}
	if ( $loc['route_url'] ) {
		$html .= '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $loc['route_url'] ) . '" target="_blank" rel="noopener">Построить маршрут</a>';
	}
	return $html . '</div>';
}

function clean_org_map_padding( $padding ) {
	$out = [];
	foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
		$out[ $side ] = max( 0, (int) ( $padding[ $side ] ?? 0 ) );
	}
	return $out;
}

/** Только безопасные значения высоты: 400, 400px, 60vh, 100%. */
function clean_org_map_css_length( $value ) {
	$value = trim( (string) $value );
	if ( preg_match( '/^\d{1,4}$/', $value ) ) {
		return $value . 'px';
	}
	return preg_match( '/^\d{1,4}(\.\d+)?(px|vh|%|rem|em)$/', $value ) ? $value : '';
}

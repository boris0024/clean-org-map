<?php
/**
 * Разбор ссылки на организацию в Яндекс Картах и загрузка её данных.
 */

defined( 'ABSPATH' ) || exit;

const CLEAN_ORG_MAP_UA = 'Mozilla/5.0 (compatible; CleanOrgMap/1.0; +https://wordpress.org/)';

/**
 * Достаёт ID организации из того, что вставил пользователь.
 *
 * Понимает: yandex.ru/profile/ID, yandex.ru/maps/org/slug/ID/, ?oid=ID,
 * короткие ссылки yandex.ru/maps/-/XXXX (их раскрываем запросом) и просто число.
 *
 * @return string ID или пустая строка.
 */
function clean_org_map_parse_org_id( $input ) {
	$input = trim( (string) $input );

	if ( preg_match( '/^\d{6,20}$/', $input ) ) {
		return $input;
	}

	$host = strtolower( (string) wp_parse_url( $input, PHP_URL_HOST ) );
	if ( ! preg_match( '/^(www\.)?yandex\.(ru|com|by|kz|uz|com\.tr)$/', $host ) ) {
		return '';
	}

	if ( preg_match( '~/(?:profile|org(?:/[^/?#]+)?)/(\d{6,20})(?:[/?#]|$)~', $input, $m ) ) {
		return $m[1];
	}
	if ( preg_match( '/[?&]oid=(\d{6,20})/', $input, $m ) ) {
		return $m[1];
	}

	// Короткая ссылка «Поделиться»: открываем и ищем ID в итоговой странице.
	if ( false !== strpos( $input, '/maps/-/' ) ) {
		$body = clean_org_map_http_get( $input );
		if ( $body && preg_match( '~yandex\.[a-z.]+/maps/org/(?:[^/"]+/)?(\d{6,20})/~', $body, $m ) ) {
			return $m[1];
		}
	}

	return '';
}

function clean_org_map_http_get( $url ) {
	$response = wp_remote_get( $url, [
		'timeout'     => 15,
		'redirection' => 5,
		'user-agent'  => CLEAN_ORG_MAP_UA,
		'headers'     => [ 'Accept-Language' => 'ru-RU,ru;q=0.9' ],
	] );

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return '';
	}
	return (string) wp_remote_retrieve_body( $response );
}

/**
 * Загружает страницу организации и вынимает из неё данные.
 *
 * @return array|WP_Error
 */
function clean_org_map_fetch_org( $org_id ) {
	$body = clean_org_map_http_get( 'https://yandex.ru/maps/org/' . rawurlencode( $org_id ) . '/' );
	if ( '' === $body ) {
		return new WP_Error( 'http', 'Яндекс не отдал страницу организации (сеть или капча). Данные можно заполнить вручную.' );
	}

	$data = clean_org_map_parse_org_page( $body, $org_id );
	if ( '' === $data['lat'] ) {
		return new WP_Error( 'parse', 'Страница получена, но координаты в ней не найдены — возможно, Яндекс показал капчу. Повторите позже или впишите координаты вручную.' );
	}
	return $data;
}

function clean_org_map_parse_org_page( $html, $org_id ) {
	$json_str = function ( $key ) use ( $html ) {
		if ( preg_match( '/"' . $key . '":"((?:[^"\\\\]|\\\\.)*)"/u', $html, $m ) ) {
			$value = json_decode( '"' . $m[1] . '"' );
			return is_string( $value ) ? trim( $value ) : '';
		}
		return '';
	};

	$data = [
		'org_id'       => (string) $org_id,
		'title'        => '',
		'address'      => $json_str( 'address' ),
		'full_address' => $json_str( 'fullAddress' ),
		'postal_code'  => $json_str( 'postalCode' ),
		'phone'        => '',
		'hours'        => $json_str( 'workingTimeText' ),
		'lat'          => '',
		'lon'          => '',
		'rating'       => '',
		'reviews'      => '',
		'url'          => 'https://yandex.ru/maps/org/' . $org_id . '/',
		'fetched'      => time(),
	];

	// Координаты организации: атрибут data-coordinates="долгота,широта".
	if ( preg_match( '/data-coordinates="(-?[\d.]+),(-?[\d.]+)"/', $html, $m ) ) {
		$data['lon'] = $m[1];
		$data['lat'] = $m[2];
	} elseif ( preg_match( '/"coordinates":\[(-?[\d.]+),(-?[\d.]+)\]/', $html, $m ) ) {
		$data['lon'] = $m[1];
		$data['lat'] = $m[2];
	}

	// og:title = «Название, рубрика, адрес — Яндекс Карты».
	if ( preg_match( '/<meta property="og:title" content="([^"]*)"/u', $html, $m ) ) {
		$title = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
		$title = preg_replace( '/\s+—\s+Яндекс\s+Карты$/u', '', $title );
		if ( '' !== $data['address'] && substr( $title, -strlen( ', ' . $data['address'] ) ) === ', ' . $data['address'] ) {
			$title = substr( $title, 0, -strlen( ', ' . $data['address'] ) );
		}
		$data['title'] = preg_replace( '/,\s+[^,]+$/u', '', $title );
	}
	if ( '' === $data['title'] ) {
		$data['title'] = $json_str( 'shortTitle' ) ?: $json_str( 'title' );
	}

	if ( preg_match( '/"phones":\[\{"number":"([^"]+)"/u', $html, $m ) ) {
		$data['phone'] = json_decode( '"' . $m[1] . '"' ) ?: '';
	}
	if ( preg_match( '/"ratingValue":([\d.]+)/', $html, $m ) ) {
		// Яндекс отдаёт float как есть: 4.900000095367432.
		$data['rating'] = (string) round( (float) $m[1], 1 );
	}
	if ( preg_match( '/"reviewCount":(\d+)/', $html, $m ) ) {
		$data['reviews'] = $m[1];
	}
	if ( preg_match( '~<link rel="canonical" href="(https://yandex\.ru/maps/org/[^"]+)"~', $html, $m ) ) {
		$data['url'] = $m[1];
	}

	return $data;
}

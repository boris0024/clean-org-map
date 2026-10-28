<?php
/**
 * Plugin Name: Clean Org Map
 * Description: Карта организации без рекламы (MapLibre + OpenFreeMap или Leaflet + OpenStreetMap) с кнопками на настоящую карточку в Яндекс Картах. В настройках достаточно вставить ссылку на организацию.
 * Version:     1.4.3
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author:      Максим | rtseo.ru | t.me/robotseo
 * Author URI:  https://rtseo.ru
 * License:     GPL-2.0-or-later
 * Text Domain: clean-org-map
 */

defined( 'ABSPATH' ) || exit;

define( 'CLEAN_ORG_MAP_VERSION', '1.4.3' );
define( 'CLEAN_ORG_MAP_FILE', __FILE__ );
define( 'CLEAN_ORG_MAP_URL', plugin_dir_url( __FILE__ ) );
define( 'CLEAN_ORG_MAP_OPTION', 'clean_org_map' );

require_once __DIR__ . '/includes/yandex.php';
require_once __DIR__ . '/includes/data.php';
require_once __DIR__ . '/includes/render.php';
require_once __DIR__ . '/includes/fields.php';

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin.php';
}

// Раз в неделю подтягиваем свежие данные из Яндекса (часы работы, телефон, адрес).
// Ручные правки в настройках при этом не затираются — они хранятся отдельно.
register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( 'clean_org_map_refresh' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', 'clean_org_map_refresh' );
	}
} );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'clean_org_map_refresh' );
} );

add_action( 'clean_org_map_refresh', 'clean_org_map_refresh_all' );

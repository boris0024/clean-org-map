<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'clean_org_map' );
wp_clear_scheduled_hook( 'clean_org_map_refresh' );

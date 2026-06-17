<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option('mdmap_app_mappings');
delete_option('mdmap_app_settings');

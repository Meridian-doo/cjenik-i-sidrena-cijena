<?php
/**
 * Removes the plugin's data on uninstall, but only if the shop owner chose
 * that in the settings. By default the Price History, anchors, Publication
 * Log and published files are kept as evidence.
 *
 * @package Cjenik
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$cjenik_settings = get_option( 'cjenik_settings', array() );
if ( empty( $cjenik_settings['delete_data_on_uninstall'] ) ) {
	return;
}

require_once __DIR__ . '/src/Install/Installer.php';
Cjenik\Install\Installer::uninstall();

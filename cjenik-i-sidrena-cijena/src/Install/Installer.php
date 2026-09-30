<?php
/**
 * @package Cjenik
 */

namespace Cjenik\Install;

/**
 * Creates and migrates the plugin's tables, and sets up schedules and the
 * archive page on activation.
 */
final class Installer {
	public const DB_VERSION        = 1;
	public const DB_VERSION_OPTION = 'cjenik_db_version';

	public static function activate(): void {
		self::install();
		\Cjenik\Archive\Archive::ensure_page();
		add_option( 'cjenik_needs_setup', 1 );
		// Start the Price History with every item's current price.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( \Cjenik\Publishing\Scheduler::SNAPSHOT_HOOK, array(), \Cjenik\Publishing\Scheduler::GROUP );
		}
		// The rewrite rules are registered on `init`, which has already run.
		delete_option( 'rewrite_rules' );
	}

	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			$hooks = array( \Cjenik\Publishing\Scheduler::PUBLISH_HOOK, \Cjenik\Publishing\Scheduler::WATCHDOG_HOOK, \Cjenik\Publishing\Scheduler::SNAPSHOT_HOOK, \Cjenik\SelfCheck::HOOK );
			foreach ( $hooks as $hook ) {
				as_unschedule_all_actions( $hook );
			}
		}
		delete_option( 'rewrite_rules' );
	}

	/** Runs versioned migrations. Safe to call on every load. */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) < self::DB_VERSION ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}cjenik_price_history (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				product_id bigint(20) unsigned NOT NULL,
				variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
				item_id bigint(20) unsigned NOT NULL,
				price decimal(19,4) NOT NULL,
				regular_price decimal(19,4) NOT NULL,
				is_sale tinyint(1) NOT NULL DEFAULT 0,
				sale_name varchar(191) NOT NULL DEFAULT '',
				source varchar(20) NOT NULL DEFAULT 'observed',
				valid_from datetime NOT NULL,
				valid_to datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY item_from (item_id,valid_from),
				KEY item_to (item_id,valid_to)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}cjenik_publications (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				outlet_id varchar(64) NOT NULL,
				storage_number bigint(20) unsigned DEFAULT NULL,
				published_at datetime NOT NULL,
				status varchar(20) NOT NULL,
				file_name varchar(255) NOT NULL DEFAULT '',
				format varchar(10) NOT NULL DEFAULT '',
				row_count int(10) unsigned NOT NULL DEFAULT 0,
				sha256 char(64) NOT NULL DEFAULT '',
				warnings longtext,
				error text,
				deleted_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY outlet_storage (outlet_id,storage_number),
				KEY outlet_published (outlet_id,published_at)
			) $charset;"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public static function uninstall(): void {
		global $wpdb;
		$page_id = (int) get_option( 'cjenik_archive_page_id' );
		if ( $page_id ) {
			wp_delete_post( $page_id, true );
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cjenik_price_history" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cjenik_publications" );
		// phpcs:enable
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_cjenik\\_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'cjenik\\_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'cjenik\\_%'" );

		$dir = wp_upload_dir( null, false )['basedir'] . '/cjenik';
		foreach ( (array) glob( $dir . '/{,.}*', GLOB_BRACE ) as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		if ( is_dir( $dir ) ) {
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
	}
}

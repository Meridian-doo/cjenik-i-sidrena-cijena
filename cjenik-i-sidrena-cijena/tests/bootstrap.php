<?php
/**
 * PHPUnit bootstrap: WordPress core test suite + WooCommerce + this plugin.
 *
 * @package Cjenik\Tests
 */

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

require dirname( __DIR__ ) . '/vendor/autoload.php';

$_tests_dir = getenv( 'WP_PHPUNIT__DIR' ) ?: dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';

require $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		require dirname( __DIR__ ) . '/cjenik-i-sidrena-cijena.php';
	}
);

tests_add_filter(
	'setup_theme',
	static function (): void {
		// Tables must exist before the suite starts wrapping tests in transactions,
		// which turn CREATE TABLE into temporary tables.
		WC_Install::install();
		$GLOBALS['wp_roles'] = new WP_Roles();
		Cjenik\Install\Installer::install();
	}
);

// Files the tests publish go to a throwaway uploads folder, not the dev site's.
tests_add_filter(
	'upload_dir',
	static function ( array $dirs ): array {
		$base            = sys_get_temp_dir() . '/cjenik-tests-uploads';
		$dirs['basedir'] = $base;
		$dirs['baseurl'] = 'http://example.org/wp-content/uploads';
		$dirs['path']    = $base . $dirs['subdir'];
		$dirs['url']     = $dirs['baseurl'] . $dirs['subdir'];
		return $dirs;
	}
);

require $_tests_dir . '/includes/bootstrap.php';

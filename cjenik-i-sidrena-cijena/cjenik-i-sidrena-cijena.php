<?php
/**
 * Plugin Name:       Cjenik i sidrena cijena
 * Plugin URI:        https://github.com/Meridian-doo/cjenik-i-sidrena-cijena
 * Description:       Helps Croatian WooCommerce shops publish the daily price list (cjenik) and show the anchor price and the lowest 30-day price.
 * Version:           0.1.0
 * Requires at least: 6.8
 * Tested up to:      7.1
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Meridian d.o.o.
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cjenik-i-sidrena-cijena
 * Domain Path:       /languages
 * WC requires at least: 9.1
 * WC tested up to:   11.1
 *
 * @package Cjenik
 */

defined( 'ABSPATH' ) || exit;

define( 'CJENIK_VERSION', '0.1.0' );
define( 'CJENIK_FILE', __FILE__ );
define( 'CJENIK_DIR', __DIR__ );

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( ! str_starts_with( $class_name, 'Cjenik\\' ) || str_starts_with( $class_name, 'Cjenik\\Tests\\' ) ) {
			return;
		}
		$path = CJENIK_DIR . '/src/' . str_replace( '\\', '/', substr( $class_name, 7 ) ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

/**
 * The plugin's service container.
 */
function cjenik(): Cjenik\Plugin {
	return Cjenik\Plugin::instance();
}

register_activation_hook( __FILE__, array( Cjenik\Install\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Cjenik\Install\Installer::class, 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		cjenik()->boot();
	},
	20
);

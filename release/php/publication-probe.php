<?php
/**
 * Sets up a minimal shop, publishes once and runs the self-check, recording
 * every outbound HTTP request the plugin makes. Requests to other hosts are
 * recorded and blocked. Run on the release-check site after installing the
 * zip: wp eval-file publication-probe.php
 *
 * Prints JSON: { published, file_url, archive_url, product_url, requests: [ url ] }.
 *
 * @package Cjenik
 */

$cjenik_site_host = wp_parse_url( home_url(), PHP_URL_HOST );
$cjenik_requests  = array();
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$cjenik_requests, $cjenik_site_host ) {
		$cjenik_requests[] = $url;
		return wp_parse_url( $url, PHP_URL_HOST ) === $cjenik_site_host ? $pre : new WP_Error( 'cjenik_release_check', 'Blocked by the release check.' );
	},
	1,
	3
);

foreach (
	array(
		'permalink_structure'            => '/%postname%/',
		'woocommerce_store_address'      => 'Ilica 150',
		'woocommerce_store_city'         => 'Zagreb',
		'woocommerce_store_postcode'     => '10000',
		'woocommerce_default_country'    => 'HR',
		'woocommerce_currency'           => 'EUR',
		'woocommerce_price_decimal_sep'  => ',',
		'woocommerce_price_thousand_sep' => '.',
		'woocommerce_coming_soon'        => 'no',
	) as $cjenik_option => $cjenik_value
) {
	update_option( $cjenik_option, $cjenik_value );
}
flush_rewrite_rules();

$cjenik_product = null;
foreach ( array( array( 'Kava 500 g', '6.40', '' ), array( 'Maslinovo ulje 1 l', '12.00', '9.90' ) ) as [ $cjenik_name, $cjenik_regular, $cjenik_sale ] ) {
	$cjenik_product = new WC_Product_Simple();
	$cjenik_product->set_name( $cjenik_name );
	$cjenik_product->set_sku( sanitize_title( $cjenik_name ) . '-' . wp_rand() );
	$cjenik_product->set_regular_price( $cjenik_regular );
	$cjenik_product->set_sale_price( $cjenik_sale );
	$cjenik_product->set_status( 'publish' );
	$cjenik_product->save();
}

cjenik()->settings()->update( array( 'wizard_done' => true ) );
$cjenik_publications = cjenik()->scheduler()->publish_all();
cjenik()->self_check()->run();

$cjenik_publication = reset( $cjenik_publications );
echo wp_json_encode(
	array(
		'published'   => $cjenik_publication && $cjenik_publication->succeeded() && is_file( $cjenik_publication->path() ),
		'file_url'    => $cjenik_publication ? $cjenik_publication->url() : null,
		'archive_url' => cjenik()->archive()->page_url(),
		'product_url' => $cjenik_product ? get_permalink( $cjenik_product->get_id() ) : null,
		'requests'    => $cjenik_requests,
	),
	JSON_UNESCAPED_SLASHES
);

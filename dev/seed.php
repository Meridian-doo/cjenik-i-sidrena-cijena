<?php
/**
 * Seeds the local wp-env site as a Croatian WooCommerce shop with products
 * that cover the plugin's edge cases.
 *
 * Run:   npm run env:seed                 (skips if already seeded)
 *        npm run env:seed -- --force      (deletes seeded products, seeds again)
 *        npm run env:seed -- --bulk=5000  (also adds 5000 plain products for load tests)
 *
 * Every seeded product and variation carries the meta key `_cjenik_seed`.
 */

if ( ! class_exists( 'WooCommerce' ) ) {
	WP_CLI::error( 'WooCommerce is not active.' );
}

$force = in_array( '--force', $args, true );
$bulk  = 0;
foreach ( $args as $arg ) {
	if ( str_starts_with( $arg, '--bulk=' ) ) {
		$bulk = max( 0, (int) substr( $arg, 7 ) );
	}
}

$already_seeded = (bool) get_posts(
	array(
		'post_type'      => array( 'product', 'product_variation' ),
		'post_status'    => 'any',
		'meta_key'       => '_cjenik_seed',
		'posts_per_page' => 1,
		'fields'         => 'ids',
	)
);

install_translation();

if ( $already_seeded && ! $force && ! $bulk ) {
	WP_CLI::log( 'Already seeded. Use --force to reseed.' );
	return;
}

configure_store();
configure_taxes();

if ( $already_seeded && $force ) {
	delete_seeded_products();
}
if ( ! $already_seeded || $force ) {
	seed_products();
}
if ( $bulk ) {
	seed_bulk_products( $bulk );
}

WP_CLI::success( 'Seeded. Shop: ' . home_url( '/shop/' ) . ' · Admin: ' . admin_url() . ' (admin / password)' );

/**
 * Copies the plugin's Croatian translation to where its WordPress.org language
 * pack would go, since the plugin no longer bundles it.
 */
function install_translation(): void {
	$domain = 'meridian-digital-cjenik-i-sidrena-cijena';
	wp_mkdir_p( WP_LANG_DIR . '/plugins' );
	copy( WP_PLUGIN_DIR . "/{$domain}/languages/{$domain}-hr.mo", WP_LANG_DIR . "/plugins/{$domain}-hr.mo" );
}

/**
 * Croatian store settings. Tax is configured so that prices are entered
 * without VAT and the tax location comes from geolocation: the storefront
 * falls back to the HR base location and shows VAT, but a cron/CLI run with
 * no customer gets no location and `wc_get_price_including_tax()` silently
 * drops VAT (see "Known trap: VAT outside a customer request" in README.md).
 */
function configure_store(): void {
	$options = array(
		'blogname'                             => 'Testna trgovina d.o.o.',
		'timezone_string'                      => 'Europe/Zagreb',
		'gmt_offset'                           => '',
		'date_format'                          => 'd.m.Y.',
		'time_format'                          => 'H:i',
		'permalink_structure'                  => '/%postname%/',
		'woocommerce_store_address'            => 'Ilica 150',
		'woocommerce_store_city'               => 'Zagreb',
		'woocommerce_store_postcode'           => '10000',
		'woocommerce_default_country'          => 'HR',
		'woocommerce_currency'                 => 'EUR',
		'woocommerce_currency_pos'             => 'right_space',
		'woocommerce_price_thousand_sep'       => '.',
		'woocommerce_price_decimal_sep'        => ',',
		'woocommerce_price_num_decimals'       => '2',
		'woocommerce_calc_taxes'               => 'yes',
		'woocommerce_prices_include_tax'       => 'no',
		'woocommerce_tax_based_on'             => 'shipping',
		'woocommerce_default_customer_address' => 'geolocation',
		'woocommerce_tax_display_shop'         => 'incl',
		'woocommerce_tax_display_cart'         => 'incl',
		'woocommerce_coming_soon'              => 'no',
		'woocommerce_task_list_hidden'         => 'yes',
		'woocommerce_onboarding_profile'       => array(
			'skipped'   => true,
			'completed' => true,
		),
	);
	foreach ( $options as $name => $value ) {
		update_option( $name, $value );
	}

	WC_Install::create_pages();
	flush_rewrite_rules();
}

/**
 * Croatian VAT: 25% standard, 13% and 5% reduced. The 5% rate goes in a new
 * "Super-reduced rate" class.
 */
function configure_taxes(): void {
	global $wpdb;

	if ( ! WC_Tax::get_tax_class_by( 'slug', 'super-reduced-rate' ) ) {
		WC_Tax::create_tax_class( 'Super-reduced rate', 'super-reduced-rate' );
	}

	$existing = $wpdb->get_col( "SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_country = 'HR'" );
	foreach ( $existing as $rate_id ) {
		WC_Tax::_delete_tax_rate( (int) $rate_id );
	}

	$rates = array(
		''                   => array( '25.0000', 'PDV 25%' ),
		'reduced-rate'       => array( '13.0000', 'PDV 13%' ),
		'super-reduced-rate' => array( '5.0000', 'PDV 5%' ),
	);
	foreach ( $rates as $class => [ $rate, $name ] ) {
		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'HR',
				'tax_rate_state'    => '',
				'tax_rate'          => $rate,
				'tax_rate_name'     => $name,
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => '' === $class ? 1 : 0,
				'tax_rate_order'    => 0,
				'tax_rate_class'    => $class,
			)
		);
	}
}

function delete_seeded_products(): void {
	foreach ( array( 'product_variation', 'product' ) as $post_type ) {
		$ids = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'meta_key'       => '_cjenik_seed',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			wc_get_product( $id )?->delete( true );
		}
		WP_CLI::log( sprintf( 'Deleted %d seeded %s posts.', count( $ids ), $post_type ) );
	}
	delete_option( 'cjenik_seed_bulk_offset' );
}

/** Returns the term ID for a product category or brand, creating it if needed. */
function term_id( string $name, string $taxonomy ): int {
	$term = get_term_by( 'name', $name, $taxonomy );
	if ( $term ) {
		return (int) $term->term_id;
	}
	return (int) wp_insert_term( $name, $taxonomy )['term_id'];
}

/** Builds a valid EAN-13 with the Croatian 385 prefix from a 9-digit item number. */
function ean13( int $item ): string {
	$digits = '385' . str_pad( (string) $item, 9, '0', STR_PAD_LEFT );
	$sum    = 0;
	foreach ( str_split( $digits ) as $i => $digit ) {
		$sum += (int) $digit * ( 0 === $i % 2 ? 1 : 3 );
	}
	return $digits . ( ( 10 - $sum % 10 ) % 10 );
}

/** Timestamp for a wall-clock time in Europe/Zagreb, e.g. 'tomorrow 00:00'. */
function zagreb( string $when ): int {
	return ( new DateTimeImmutable( $when, new DateTimeZone( 'Europe/Zagreb' ) ) )->getTimestamp();
}

/**
 * Applies the shared fields from a spec array to a product or variation.
 * Keys: name, sku, gtin, regular, sale, sale_from, sale_to, tax_class, stock,
 * status, created, category, brand, weight.
 */
function apply_fields( WC_Product $product, array $spec ): void {
	isset( $spec['name'] ) && $product->set_name( $spec['name'] );
	isset( $spec['sku'] ) && $product->set_sku( $spec['sku'] );
	isset( $spec['gtin'] ) && $product->set_global_unique_id( $spec['gtin'] );
	$product->set_regular_price( $spec['regular'] );
	isset( $spec['sale'] ) && $product->set_sale_price( $spec['sale'] );
	isset( $spec['sale_from'] ) && $product->set_date_on_sale_from( $spec['sale_from'] );
	isset( $spec['sale_to'] ) && $product->set_date_on_sale_to( $spec['sale_to'] );
	$product->set_tax_class( $spec['tax_class'] ?? '' );
	$product->set_stock_status( $spec['stock'] ?? 'instock' );
	$product->set_status( $spec['status'] ?? 'publish' );
	isset( $spec['created'] ) && $product->set_date_created( zagreb( $spec['created'] ) );
	isset( $spec['weight'] ) && $product->set_weight( $spec['weight'] );
	if ( isset( $spec['category'] ) ) {
		$product->set_category_ids( array( term_id( $spec['category'], 'product_cat' ) ) );
	}
	$product->update_meta_data( '_cjenik_seed', '1' );
}

function set_brand( int $product_id, ?string $brand ): void {
	if ( $brand && taxonomy_exists( 'product_brand' ) ) {
		wp_set_object_terms( $product_id, array( term_id( $brand, 'product_brand' ) ), 'product_brand' );
	}
}

function seed_products(): void {
	$simple = array(
		// Food and drink (FMCG: 2 May 2025 anchor category), mixed VAT classes.
		array( 'name' => 'Kruh bijeli 500 g', 'sku' => 'HR-KRUH-500', 'gtin' => ean13( 1001 ), 'regular' => '1.43', 'tax_class' => 'super-reduced-rate', 'category' => 'Hrana', 'brand' => 'Pekara Dubravica', 'created' => '2025-03-01 09:00', 'weight' => '0.5' ),
		array( 'name' => 'Mlijeko trajno 2,8% m.m. 1 l', 'sku' => 'HR-MLIJEKO-1L', 'gtin' => ean13( 1002 ), 'regular' => '1.13', 'tax_class' => 'super-reduced-rate', 'category' => 'Hrana', 'brand' => 'Dukat', 'created' => '2025-03-01 09:00' ),
		array( 'name' => 'Vegeta 500 g', 'sku' => 'HR-VEGETA-500', 'gtin' => ean13( 1003 ), 'regular' => '4.72', 'category' => 'Hrana', 'brand' => 'Podravka', 'created' => '2025-03-01 09:00', 'weight' => '0.5' ),
		// On sale now, no end date.
		array( 'name' => 'Čokolada Dorina mliječna 80 g', 'sku' => 'HR-DORINA-80', 'gtin' => ean13( 1004 ), 'regular' => '1.59', 'sale' => '1.19', 'category' => 'Hrana', 'brand' => 'Kraš', 'created' => '2025-03-01 09:00' ),
		// Scheduled sale starting at midnight: tomorrow morning `_price` stays stale until WooCommerce's scheduled-sales job runs.
		array( 'name' => 'Kava mljevena Jubilarna 250 g', 'sku' => 'HR-KAVA-250', 'gtin' => ean13( 1005 ), 'regular' => '4.99', 'sale' => '3.99', 'sale_from' => zagreb( 'tomorrow 00:00' ), 'sale_to' => zagreb( '+8 days 23:59' ), 'category' => 'Piće', 'brand' => 'Franck', 'created' => '2025-03-01 09:00' ),
		// Sale that ends tonight.
		array( 'name' => 'Mineralna voda 1,5 l', 'sku' => 'HR-VODA-15', 'gtin' => ean13( 1006 ), 'regular' => '0.99', 'sale' => '0.79', 'sale_from' => zagreb( '-6 days 00:00' ), 'sale_to' => zagreb( 'today 23:59' ), 'category' => 'Piće', 'brand' => 'Jamnica', 'created' => '2025-03-01 09:00' ),
		// Name with a quote, comma and semicolon (CSV escaping).
		array( 'name' => 'Sok "Jamnica", jabuka; 1 l', 'sku' => 'HR-SOK-JAB-1L', 'gtin' => ean13( 1007 ), 'regular' => '1.89', 'category' => 'Piće', 'brand' => 'Jamnica', 'created' => '2025-03-01 09:00' ),
		// Cosmetics and cleaning (FMCG categories).
		array( 'name' => 'Krema za ruke 100 ml', 'sku' => 'HR-KREMA-100', 'gtin' => ean13( 1008 ), 'regular' => '3.49', 'category' => 'Kozmetika', 'brand' => 'Nivea', 'created' => '2025-03-01 09:00' ),
		array( 'name' => 'Deterdžent za suđe 900 ml', 'sku' => 'HR-DETERDZENT-900', 'gtin' => ean13( 1009 ), 'regular' => '2.29', 'stock' => 'outofstock', 'category' => 'Sredstva za čišćenje', 'brand' => 'Saponia', 'created' => '2025-03-01 09:00' ),
		// Non-FMCG: 10 Sep 2026 anchor. Backorder and first listed after 10 Sep.
		array( 'name' => 'Usisavač bez vrećice 800 W', 'sku' => 'HR-USISAVAC-800', 'gtin' => ean13( 1010 ), 'regular' => '119.00', 'stock' => 'onbackorder', 'category' => 'Kućanski aparati', 'brand' => 'Gorenje', 'created' => '2026-09-20 11:00' ),
		array( 'name' => 'Aparat za kavu espresso', 'sku' => 'HR-ESPRESSO', 'gtin' => ean13( 1011 ), 'regular' => '249.00', 'sale' => '199.00', 'category' => 'Kućanski aparati', 'brand' => 'Bosch', 'created' => '2026-06-15 11:00' ),
		// Missing SKU, barcode and brand.
		array( 'name' => 'Knjiga "Zagrebačke priče"', 'regular' => '18.00', 'tax_class' => 'super-reduced-rate', 'category' => 'Knjige', 'created' => '2026-09-25 10:00' ),
		// CSV formula injection.
		array( 'name' => '=HYPERLINK("http://example.com","klik") test', 'sku' => 'HR-INJECT', 'regular' => '1.00', 'category' => 'Ostalo', 'created' => '2026-09-01 10:00' ),
		// Must never appear in the Price List.
		array( 'name' => 'Skica proizvoda (draft)', 'sku' => 'HR-DRAFT', 'regular' => '9.99', 'status' => 'draft', 'category' => 'Ostalo' ),
		array( 'name' => 'Interni artikl (private)', 'sku' => 'HR-PRIVATE', 'regular' => '9.99', 'status' => 'private', 'category' => 'Ostalo' ),
	);

	foreach ( $simple as $spec ) {
		$product = new WC_Product_Simple();
		apply_fields( $product, $spec );
		$id = $product->save();
		set_brand( $id, $spec['brand'] ?? null );
	}

	// Variable product: one variation on sale, one out of stock, one disabled.
	$size = new WC_Product_Attribute();
	$size->set_name( 'Veličina' );
	$size->set_options( array( 'S', 'M', 'L', 'XL' ) );
	$size->set_visible( true );
	$size->set_variation( true );

	$parent = new WC_Product_Variable();
	$parent->set_name( 'Majica pamučna "Šibenik"' );
	$parent->set_sku( 'HR-MAJICA' );
	$parent->set_status( 'publish' );
	$parent->set_category_ids( array( term_id( 'Odjeća', 'product_cat' ) ) );
	$parent->set_attributes( array( $size ) );
	$parent->set_date_created( zagreb( '2026-07-01 10:00' ) );
	$parent->update_meta_data( '_cjenik_seed', '1' );
	$parent_id = $parent->save();
	set_brand( $parent_id, 'Galeb' );

	$variations = array(
		'S'  => array( 'sku' => 'HR-MAJICA-S', 'gtin' => ean13( 2001 ), 'regular' => '14.99' ),
		'M'  => array( 'sku' => 'HR-MAJICA-M', 'gtin' => ean13( 2002 ), 'regular' => '14.99', 'sale' => '11.99' ),
		'L'  => array( 'sku' => 'HR-MAJICA-L', 'gtin' => ean13( 2003 ), 'regular' => '16.99', 'stock' => 'outofstock' ),
		'XL' => array( 'sku' => 'HR-MAJICA-XL', 'gtin' => ean13( 2004 ), 'regular' => '16.99', 'status' => 'private' ),
	);
	foreach ( $variations as $option => $spec ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_attributes( array( sanitize_title( 'Veličina' ) => $option ) );
		apply_fields( $variation, $spec );
		$variation->save();
	}
	WC_Product_Variable::sync( $parent_id );

	WP_CLI::log( sprintf( 'Seeded %d simple products and 1 variable product with %d variations.', count( $simple ), count( $variations ) ) );
}

function seed_bulk_products( int $count ): void {
	wp_defer_term_counting( true );
	$category = term_id( 'Masovni artikli', 'product_cat' );
	$progress = WP_CLI\Utils\make_progress_bar( "Adding {$count} bulk products", $count );
	$offset   = (int) get_option( 'cjenik_seed_bulk_offset', 0 );

	for ( $i = 1; $i <= $count; $i++ ) {
		$n       = $offset + $i;
		$product = new WC_Product_Simple();
		apply_fields(
			$product,
			array(
				'name'    => sprintf( 'Masovni artikl %06d', $n ),
				'sku'     => sprintf( 'BULK-%06d', $n ),
				'gtin'    => ean13( 100000 + $n ),
				'regular' => number_format( 1 + ( $n * 37 % 9900 ) / 100, 2, '.', '' ),
				'sale'    => 0 === $n % 7 ? number_format( 0.5 + ( $n * 37 % 9900 ) / 200, 2, '.', '' ) : null,
			)
		);
		$product->set_category_ids( array( $category ) );
		$product->save();
		$progress->tick();
	}

	update_option( 'cjenik_seed_bulk_offset', $offset + $count );
	wp_defer_term_counting( false );
	$progress->finish();
}

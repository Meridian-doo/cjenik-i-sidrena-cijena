<?php
/**
 * Dresses the dev shop up as a real Croatian neighbourhood store on the Botiga theme:
 * product photos, a wordmark logo, Croatian page titles and menus, a three-row header
 * (contact bar, logo row, category navigation), a filter sidebar on the shop page,
 * left-aligned product cards with percentage sale badges, a three-column footer, the
 * shop as the front page and Croatian as the site language. Ends by applying the
 * colour scheme from botiga-colors.php.
 *
 * Run with: npm run env:theme   (Botiga itself is installed by hand, not seeded)
 *
 * Photos come from Pexels and Unsplash (both free to use, no attribution required).
 */

if ( 'botiga' !== get_template() ) {
	WP_CLI::error( 'The Botiga theme is not active; nothing to do.' );
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

/* ---------------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------------- */

/** Imports a remote or local image once (keyed on its source) and returns the attachment ID. */
function cjenik_demo_image( string $source, string $name, string $title ): int {
	$existing = get_posts( array(
		'post_type'   => 'attachment',
		'post_status' => 'any',
		'numberposts' => 1,
		'fields'      => 'ids',
		'meta_key'    => '_cjenik_demo_source',
		'meta_value'  => $source,
	) );
	if ( $existing ) {
		return (int) $existing[0];
	}
	if ( preg_match( '#^https?://#', $source ) ) {
		$tmp = download_url( $source, 60 );
		if ( is_wp_error( $tmp ) ) {
			WP_CLI::warning( "Could not download $source: " . $tmp->get_error_message() );
			return 0;
		}
	} else {
		$tmp = wp_tempnam( $name );
		copy( $source, $tmp );
	}
	$id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), 0, $title );
	if ( is_wp_error( $id ) ) {
		WP_CLI::warning( "Could not import $name: " . $id->get_error_message() );
		return 0;
	}
	update_post_meta( $id, '_cjenik_demo_source', $source );
	update_post_meta( $id, '_cjenik_seed', 1 );
	return (int) $id;
}

function pexels( int $id ): string {
	return "https://images.pexels.com/photos/$id/pexels-photo-$id.jpeg?auto=compress&cs=tinysrgb&w=1200&h=1200&fit=crop";
}

/** Returns a menu's ID, creating it empty (and clearing it if it already exists). */
function cjenik_demo_menu( string $name ): int {
	$menu = wp_get_nav_menu_object( $name );
	$id   = $menu ? (int) $menu->term_id : (int) wp_create_nav_menu( $name );
	foreach ( wp_get_nav_menu_items( $id ) ?: array() as $item ) {
		wp_delete_post( $item->ID, true );
	}
	return $id;
}

function cjenik_demo_menu_item( int $menu_id, string $title, array $args ): void {
	wp_update_nav_menu_item( $menu_id, 0, array_merge( array( 'menu-item-title' => $title, 'menu-item-status' => 'publish' ), $args ) );
}

function cjenik_demo_category_item( int $menu_id, string $category, string $label = '' ): void {
	$term = get_term_by( 'name', $category, 'product_cat' );
	if ( ! $term ) {
		return;
	}
	cjenik_demo_menu_item( $menu_id, $label ?: $term->name, array(
		'menu-item-type'      => 'taxonomy',
		'menu-item-object'    => 'product_cat',
		'menu-item-object-id' => $term->term_id,
	) );
}

function cjenik_demo_page_item( int $menu_id, int $page_id, string $label = '' ): void {
	cjenik_demo_menu_item( $menu_id, $label ?: get_the_title( $page_id ), array(
		'menu-item-type'      => 'post_type',
		'menu-item-object'    => 'page',
		'menu-item-object-id' => $page_id,
	) );
}

/* ---------------------------------------------------------------------------
 * WooCommerce image sizes (before importing, so the crops are generated once)
 * ------------------------------------------------------------------------- */

update_option( 'woocommerce_thumbnail_cropping', '1:1' );
update_option( 'woocommerce_thumbnail_image_width', 600 );
update_option( 'woocommerce_single_image_width', 800 );

/* ---------------------------------------------------------------------------
 * Product photos
 * ------------------------------------------------------------------------- */

$photos = array(
	'HR-KRUH-500'       => array( pexels( 8599600 ), 'kruh-bijeli.jpg', 'Kruh bijeli' ),
	'HR-MLIJEKO-1L'     => array( pexels( 4578396 ), 'mlijeko.jpg', 'Mlijeko' ),
	'HR-VEGETA-500'     => array( pexels( 4198755 ), 'vegeta.jpg', 'Začini' ),
	'HR-DORINA-80'      => array( 'https://unsplash.com/photos/jxRbFiXaj6w/download?w=1200', 'cokolada.jpg', 'Čokolada' ),
	'HR-KAVA-250'       => array( pexels( 31890552 ), 'kava.jpg', 'Kava' ),
	'HR-VODA-15'        => array( pexels( 1540235 ), 'mineralna-voda.jpg', 'Mineralna voda' ),
	'HR-SOK-JAB-1L'     => array( pexels( 14510445 ), 'sok-jabuka.jpg', 'Sok od jabuke' ),
	'HR-KREMA-100'      => array( pexels( 33756874 ), 'krema-za-ruke.jpg', 'Krema za ruke' ),
	'HR-DETERDZENT-900' => array( pexels( 10566507 ), 'deterdzent.jpg', 'Deterdžent' ),
	'HR-USISAVAC-800'   => array( pexels( 9462148 ), 'usisavac.jpg', 'Usisavač' ),
	'HR-ESPRESSO'       => array( pexels( 7162906 ), 'aparat-za-kavu.jpg', 'Aparat za kavu' ),
	'HR-MAJICA'         => array( pexels( 8217483 ), 'majica.jpg', 'Majica' ),
);
// The book has no SKU in the seed.
$book = get_posts( array( 'post_type' => 'product', 'title' => 'Knjiga "Zagrebačke priče"', 'numberposts' => 1, 'fields' => 'ids' ) );

$assigned = 0;
foreach ( $photos as $sku => list( $url, $file, $title ) ) {
	$product_id = wc_get_product_id_by_sku( $sku );
	if ( ! $product_id && 'HR-MAJICA' === $sku ) {
		$parent = get_posts( array( 'post_type' => 'product', 'title' => 'Majica pamučna', 'numberposts' => 1, 'fields' => 'ids' ) );
		$product_id = $parent ? (int) $parent[0] : 0;
	}
	if ( ! $product_id ) {
		continue;
	}
	$image = cjenik_demo_image( $url, $file, $title );
	if ( $image ) {
		set_post_thumbnail( $product_id, $image );
		$assigned++;
	}
}
if ( $book ) {
	$image = cjenik_demo_image( pexels( 433333 ), 'knjiga.jpg', 'Knjiga' );
	if ( $image ) {
		set_post_thumbnail( (int) $book[0], $image );
		$assigned++;
	}
}
WP_CLI::log( "Product photos: $assigned assigned." );

/* ---------------------------------------------------------------------------
 * Logo
 * ------------------------------------------------------------------------- */

$logo = cjenik_demo_image( __DIR__ . '/assets/logo.png', 'testna-trgovina-logo.png', 'Testna trgovina' );
if ( $logo ) {
	set_theme_mod( 'custom_logo', $logo );
	set_theme_mod( 'site_logo_size_desktop', 237 ); // The PNG is 2x.
	set_theme_mod( 'site_logo_size_tablet', 200 );
	set_theme_mod( 'site_logo_size_mobile', 170 );
	// Botiga always prints the site title next to the logo; WordPress hides it via the header-text colour.
	set_theme_mod( 'header_textcolor', 'blank' );
}

/* ---------------------------------------------------------------------------
 * Croatian page titles, front page, language
 * ------------------------------------------------------------------------- */

$shop_page    = (int) wc_get_page_id( 'shop' );
$archive_page = get_page_by_path( 'arhiva-cjenika' );
$titles = array(
	$shop_page                         => 'Trgovina',
	(int) wc_get_page_id( 'cart' )     => 'Košarica',
	(int) wc_get_page_id( 'checkout' ) => 'Blagajna',
	(int) wc_get_page_id( 'myaccount' ) => 'Moj račun',
);
if ( $archive_page ) {
	$titles[ $archive_page->ID ] = 'Arhiva cjenika';
}
foreach ( $titles as $id => $title ) {
	if ( $id > 0 ) {
		wp_update_post( array( 'ID' => $id, 'post_title' => $title ) );
	}
}
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $shop_page );
update_option( 'blogdescription', '' );
update_option( 'WPLANG', 'hr' );
delete_user_meta( 1, 'locale' );

/* ---------------------------------------------------------------------------
 * Menus
 * ------------------------------------------------------------------------- */

$main = cjenik_demo_menu( 'Glavni izbornik' );
cjenik_demo_page_item( $main, $shop_page, 'Sve' );
foreach ( array( 'Hrana', 'Piće', 'Kozmetika', 'Sredstva za čišćenje' => 'Kućanstvo', 'Kućanski aparati' => 'Aparati', 'Knjige', 'Odjeća' ) as $key => $value ) {
	is_int( $key ) ? cjenik_demo_category_item( $main, $value ) : cjenik_demo_category_item( $main, $key, $value );
}
if ( $archive_page ) {
	cjenik_demo_page_item( $main, $archive_page->ID, 'Arhiva cjenika' );
}

$categories = cjenik_demo_menu( 'Kategorije' );
foreach ( array( 'Hrana', 'Piće', 'Kozmetika', 'Sredstva za čišćenje', 'Kućanski aparati', 'Knjige', 'Odjeća' ) as $category ) {
	cjenik_demo_category_item( $categories, $category );
}

$locations = get_theme_mod( 'nav_menu_locations', array() );
$locations['primary'] = $main;
set_theme_mod( 'nav_menu_locations', $locations );

/* ---------------------------------------------------------------------------
 * Header: contact bar / logo row / category navigation
 * ------------------------------------------------------------------------- */

$mods = array(
	'botiga_header_row__above_header_row' => '{"desktop":[["contact_info"],[],["html"]],"mobile":[[],[],[]]}',
	'botiga_header_row__main_header_row'  => '{"desktop":[["logo"],[],["search","woo_icons"]],"mobile":[["search"],["logo"],["woo_icons","mobile_hamburger"]]}',
	'botiga_header_row__below_header_row' => '{"desktop":[["menu"]],"mobile":[[],[],[]]}',

	'botiga_header_row__above_header_row_background_color'    => '#14261C',
	'botiga_header_row__above_header_row_border_bottom_color' => '#14261C',
	'botiga_header_row__above_header_row_height_desktop'      => 40,
	'botiga_header_row__main_header_row_height_desktop'       => 96,
	'botiga_header_row__main_header_row_border_bottom_desktop' => 0,
	'botiga_header_row__below_header_row_border_bottom_desktop' => 1,
	'botiga_header_row__below_header_row_border_bottom_color'  => '#D6DFD9',
	'botiga_header_row__below_header_row_height_desktop'      => 52,

	'header_contact_mail'                  => 'info@testna-trgovina.hr',
	'header_contact_phone'                 => '01 4567 890',
	'bhfb_contact_info_display_inline'     => 1,
	'bhfb_contact_info_text_color'         => '#C9D6CD',
	'bhfb_contact_info_text_color_hover'   => '#FFFFFF',
	'bhfb_contact_info_icon_color'         => '#8FD1A8',
	'bhfb_contact_info_icon_color_hover'   => '#FFFFFF',

	'header_html_content'                              => 'Besplatna dostava za narudžbe iznad 40 € · Pon–sub 7–21 h',
	'botiga_section_hb_component__html_text_color'     => '#C9D6CD',
	'botiga_section_hb_component__html_link_color'     => '#8FD1A8',
	'botiga_section_hb_component__html_text_align_desktop' => 'right',

	/* Shop page */
	'shop_archive_sidebar'                    => 'sidebar-left',
	'shop_woocommerce_catalog_columns_desktop' => 3,
	'shop_woocommerce_catalog_rows'           => 4,
	'shop_card_elements'                      => array( 'botiga_loop_product_category', 'botiga_shop_loop_product_title', 'woocommerce_template_loop_price' ),
	'shop_product_alignment'                  => 'left',
	'shop_product_card_border_size'           => 0,
	'shop_product_card_radius'                => 0,
	'shop_product_card_thumb_radius'          => 8,
	'shop_product_element_spacing'            => 8,
	'shop_sale_tag_radius'                    => 4,
	'shop_sale_tag_spacing'                   => 12,
	'sale_badge_text'                         => 'Akcija',
	'sale_badge_percent'                      => 1,
	'sale_percentage_text'                    => '-{value}%',

	/* Footer: three widget columns above the credits row */
	'botiga_footer_row__above_footer_row'                 => '{"desktop":[["widget1"],["widget2"],["widget3"]],"mobile":[["widget1"],["widget2"],["widget3"]],"mobile_offcanvas":[[]]}',
	'botiga_footer_row__above_footer_row_padding_desktop' => '{"unit":"px","linked":false,"top":"56","right":"","bottom":"32","left":""}',
	'botiga_footer_row__main_footer_row_height_desktop'   => 88,
	'botiga_footer_row__main_footer_row_border_top_desktop' => 1,
	'botiga_footer_row__main_footer_row_border_top_color' => '#2A4234',
	'footer_credits'                                      => '© 2026 Testna trgovina d.o.o. · Ilica 150, 10000 Zagreb · OIB 12345678901',
	'botiga_section_fb_component__copyright_text_color'   => '#C9D6CD',
	'botiga_section_fb_component__copyright_links_color'  => '#FFFFFF',
	'botiga_section_fb_component__copyright_links_color_hover' => '#8FD1A8',
);
foreach ( $mods as $key => $value ) {
	set_theme_mod( $key, $value );
}

/* ---------------------------------------------------------------------------
 * Widgets: shop sidebar and footer columns
 * ------------------------------------------------------------------------- */

update_option( 'widget_woocommerce_product_categories', array(
	2 => array( 'title' => 'Kategorije', 'orderby' => 'name', 'dropdown' => 0, 'count' => 1, 'hierarchical' => 1, 'show_children_only' => 0, 'hide_empty' => 1, 'max_depth' => '' ),
	'_multiwidget' => 1,
) );
update_option( 'widget_woocommerce_price_filter', array( 2 => array( 'title' => 'Cijena' ), '_multiwidget' => 1 ) );
// No product-list widget: the plugin's anchor and lowest-price lines don't fit a narrow column.
delete_option( 'widget_woocommerce_products' );
update_option( 'widget_text', array(
	2 => array(
		'title'  => 'Testna trgovina',
		'text'   => "Kvartovska trgovina u srcu Zagreba od 1998. Svježi kruh, mliječni proizvodi, pića, kozmetika i sve za kućanstvo.\n\nCjenik objavljujemo svaki dan, a arhivu svih objavljenih cjenika možete pregledati na stranici Arhiva cjenika.",
		'filter' => 1,
		'visual' => 0,
	),
	3 => array(
		'title'  => 'Kontakt',
		'text'   => "Ilica 150, 10000 Zagreb\nPon–sub 7:00–21:00, ned 8:00–13:00\n01 4567 890\ninfo@testna-trgovina.hr",
		'filter' => 1,
		'visual' => 0,
	),
	'_multiwidget' => 1,
) );
update_option( 'widget_nav_menu', array( 2 => array( 'title' => 'Kategorije', 'nav_menu' => $categories ), '_multiwidget' => 1 ) );
update_option( 'sidebars_widgets', array(
	'wp_inactive_widgets' => array(),
	'sidebar-1'           => array( 'woocommerce_product_categories-2', 'woocommerce_price_filter-2' ),
	'footer-1'            => array( 'text-2' ),
	'footer-2'            => array( 'nav_menu-2' ),
	'footer-3'            => array( 'text-3' ),
	'footer-4'            => array(),
	'array_version'       => 3,
) );

/* ---------------------------------------------------------------------------
 * Colours, then rebuild Botiga's CSS file
 * ------------------------------------------------------------------------- */

require __DIR__ . '/botiga-colors.php';

<?php
/**
 * Colour scheme for the dev shop when the Botiga theme is active: a fresh grocery
 * green with a dark green footer and a red-orange sale badge, so screenshots read
 * as a real store rather than Botiga's black-and-white default.
 *
 * Run with: npm run env:theme-colors   (Botiga itself is installed by hand, not seeded)
 */

if ( 'botiga' !== get_template() ) {
	WP_CLI::error( 'The Botiga theme is not active; nothing to do.' );
}

// Grouped the same way Botiga's customizer fills the individual colours when you pick a palette.
$accent = '#1E7A4C'; $hover = '#155D39'; $ink = '#14261C'; $body = '#3F4A44';
$border = '#D6DFD9'; $surface = '#F6F3EC'; $white = '#FFFFFF'; $header = '#FFFFFF';
$footer_bg = '#14261C'; $footer_text = '#C9D6CD'; $footer_link = '#FFFFFF'; $sale = '#D9482B';
$slots = array(
	array( $accent, array( 'custom_color1', 'scrolltop_bg_color', 'button_background_color', 'button_border_color', 'color_link_default', 'single_product_tabs_border_color_active', 'single_product_tabs_text_color_active', 'single_product_tabs_text_color', 'shop_archive_header_button_color', 'shop_archive_header_button_border_color', 'ql_item_bg_hover' ) ),
	array( $hover, array( 'custom_color2', 'scrolltop_bg_color_hover', 'button_background_color_hover', 'button_border_color_hover', 'color_link_hover', 'shop_archive_header_button_background_color_hover', 'shop_archive_header_button_border_color_hover', 'main_header_sticky_active_color_hover', 'main_header_color_hover', 'main_header_sticky_active_submenu_color_hover', 'main_header_submenu_color_hover', 'ql_item_color_hover' ) ),
	array( $ink, array( 'custom_color3', 'single_post_title_color', 'main_header_submenu_color', 'main_header_sticky_active_submenu_color', 'offcanvas_menu_color', 'mobile_header_color', 'single_product_title_color', 'color_forms_text', 'shop_product_product_title', 'loop_post_meta_color', 'loop_post_title_color', 'main_header_color', 'main_header_sticky_active_color', 'site_title_color', 'site_description_color', 'color_heading_1', 'color_heading_2', 'color_heading_3', 'color_heading_4', 'color_heading_5', 'color_heading_6', 'shop_archive_header_title_color', 'shop_archive_header_description_color', 'bhfb_search_icon_color', 'bhfb_woo_icons_color', 'bhfb_contact_info_icon_color', 'ql_item_color' ) ),
	array( $body, array( 'custom_color4', 'color_body_text', 'color_forms_placeholder', 'topbar_color', 'main_header_bottom_color', 'single_sticky_add_to_cart_style_color_content', 'loop_post_text_color' ) ),
	array( $border, array( 'custom_color5', 'color_forms_borders', 'single_product_tabs_remaining_borders', 'single_sticky_add_to_cart_style_color_border', 'botiga_header_row__above_header_row_border_bottom_color', 'botiga_header_row__main_header_row_border_bottom_color', 'botiga_header_row__below_header_row_border_bottom_color', 'ql_item_border_color', 'shop_product_card_border_color' ) ),
	array( $surface, array( 'custom_color6', 'content_cards_background', 'single_product_tabs_background_color', 'single_product_tabs_background_color_active', 'single_product_gallery_styles_background_color', 'single_sticky_add_to_cart_style_color_background', 'ql_background_color' ) ),
	array( $white, array( 'custom_color7', 'background_color', 'button_color', 'button_color_hover', 'scrolltop_color', 'scrolltop_color_hover', 'color_forms_background', 'topbar_background', 'single_product_reviews_advanced_section_bg_color', 'single_product_sale_color' ) ),
	array( $header, array( 'custom_color8', 'main_header_submenu_background', 'main_header_sticky_active_submenu_background_color', 'main_header_background', 'main_header_sticky_active_background', 'main_header_bottom_background', 'mobile_header_background', 'offcanvas_menu_background', 'shop_archive_header_background_color', 'shop_archive_header_button_background_color', 'shop_archive_header_button_color_hover', 'botiga_header_row__above_header_row_background_color', 'botiga_header_row__main_header_row_background_color', 'botiga_header_row__below_header_row_background_color', 'login_register_submenu_background' ) ),
	array( $footer_bg, array( 'footer_widgets_background', 'footer_credits_background', 'botiga_footer_row__above_footer_row_background_color', 'botiga_footer_row__main_footer_row_background_color', 'botiga_footer_row__below_footer_row_background_color', 'botiga_footer_row__above_footer_row_border_top_color', 'botiga_footer_row__main_footer_row_border_top_color', 'botiga_footer_row__below_footer_row_border_top_color' ) ),
	array( $footer_text, array( 'footer_widgets_text_color', 'footer_credits_text_color', 'footer_widgets_links_color' ) ),
	array( $footer_link, array( 'footer_widgets_title_color', 'footer_credits_links_color', 'footer_widgets_links_hover_color', 'footer_credits_links_color_hover' ) ),
	array( $white, array( 'bhfb_footer_button_background_color', 'bhfb_footer_button_border_color' ) ),
	array( $footer_text, array( 'bhfb_footer_button_background_color_hover', 'bhfb_footer_button_border_color_hover' ) ),
	array( $ink, array( 'bhfb_footer_button_color', 'bhfb_footer_button_color_hover' ) ),
	array( $sale, array( 'single_product_sale_background_color' ) ),
);
$n = 0;
foreach ( $slots as list( $color, $keys ) ) { foreach ( $keys as $key ) { set_theme_mod( $key, $color ); $n++; } }
set_theme_mod( 'custom_palette_toggle', 1 );
// Botiga writes its generated CSS to uploads/botiga/custom-styles.css and only rebuilds it on a
// customizer save; this flag makes it rebuild on the next request.
set_transient( 'botiga_update_custom_css_flag', true, 0 );
WP_CLI::success( "Set $n Botiga colour settings." );

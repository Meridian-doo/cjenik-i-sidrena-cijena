/** Lowercase, without diacritics (đ → d as WordPress's remove_accents() does). */
export function fold( text ) {
	return text.normalize( 'NFD' ).replace( /\p{M}/gu, '' ).replace( /đ/g, 'd' ).replace( /Đ/g, 'D' ).toLowerCase();
}

/**
 * The slug WordPress.org derives from `Plugin Name:` on upload:
 * remove_accents(), keep [a-z0-9 _.-], "_" → "-", sanitize_title_with_dashes().
 */
export function pluginSlug( name ) {
	return fold( name )
		.replace( /[^a-z0-9 _.-]/g, '' )
		.replace( /_/g, '-' )
		.replace( /\./g, '-' )
		.replace( /\s+/g, '-' )
		.replace( /-+/g, '-' )
		.replace( /^-|-$/g, '' );
}

/**
 * The directory's trademark rule for WooCommerce (plugin-directory
 * class-trademarks.php): "woo" may not appear in the slug at all, except as
 * a trailing "-for-woocommerce".
 */
export function wooTrademarkProblem( name ) {
	const slug = pluginSlug( name );
	const withoutSuffix = slug.endsWith( '-for-woocommerce' ) ? slug.slice( 0, -'-for-woocommerce'.length ) : slug;
	return withoutSuffix.includes( 'woo' ) ? `"${ name }" (slug "${ slug }") uses "woo"; only a trailing "for WooCommerce" is allowed.` : null;
}

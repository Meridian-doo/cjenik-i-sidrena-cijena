#!/usr/bin/env node
// Captures the WordPress.org screenshots from the seeded dev site
// (npm run env:start), once with the site in English and once in Croatian:
// .wordpress-org/screenshot-N.png and screenshot-N-hr.png, in the order of the
// readme's == Screenshots == captions.
//
// Needs Playwright's Chromium: npx playwright install chromium
import path from 'node:path';
import { chromium } from 'playwright';
import { ASSETS_DIR, SLUG } from './config.mjs';
import { wp } from './lib/wpenv.mjs';

const SITE = 'http://localhost:8888';
const ADMIN = `${ SITE }/wp-admin/admin.php`;
// Keep the plugin's own notices; hide WordPress and WooCommerce nags.
const TIDY = `
	#wpbody-content > .notice:not([class*="cjenik"]), #wpbody-content > .update-nag, .woocommerce-layout__activity-panel, #wpfooter { display: none !important; }
`;

const evalPhp = ( code ) => wp( [ 'eval', code ] ).stdout.trim();

function prepareSite() {
	for ( const args of [ [ 'language', 'core', 'install', 'hr' ], [ 'language', 'plugin', 'install', 'woocommerce', 'hr' ], [ 'language', 'theme', 'install', '--all', 'hr' ] ] ) {
		wp( args, { allowFail: true } );
	}
	// Stands in for the plugin's own language pack until translate.wordpress.org has one.
	evalPhp( `wp_mkdir_p( WP_LANG_DIR . '/plugins' ); copy( WP_PLUGIN_DIR . '/${ SLUG }/languages/${ SLUG }-hr.mo', WP_LANG_DIR . '/plugins/${ SLUG }-hr.mo' );` );
	wp( [ 'cjenik', 'publish' ] );
	return {
		// A product on sale, with the anchor an owner would have imported for 10 Sep 2026.
		product: evalPhp(
			"foreach ( wc_get_product_ids_on_sale() as $id ) { $p = wc_get_product( $id ); if ( $p && $p->is_type( 'simple' ) && 'publish' === $p->get_status() ) { cjenik()->anchors()->set( $id, (float) wc_get_price_including_tax( $p, array( 'price' => $p->get_regular_price() ) ), '2026-09-10', 'manual' ); echo get_permalink( $id ); break; } }"
		),
		archive: evalPhp( 'echo cjenik()->archive()->page_url();' ),
	};
}

async function capture( page, { url, file, focus } ) {
	await page.goto( url, { waitUntil: 'networkidle' } );
	await page.addStyleTag( { content: TIDY } );
	// The seed's CSV-injection test product isn't something a shop would sell.
	await page.locator( 'tr', { hasText: '=HYPERLINK' } ).evaluateAll( ( rows ) => rows.forEach( ( row ) => row.remove() ) );
	if ( focus ) {
		await page.locator( focus ).first().scrollIntoViewIfNeeded();
		await page.evaluate( () => window.scrollBy( 0, -200 ) );
	}
	await page.screenshot( { path: path.join( ASSETS_DIR, file ) } );
	console.log( `  ${ file }` );
}

const urls = prepareSite();
const browser = await chromium.launch();
try {
	for ( const [ locale, suffix ] of [ [ 'en_US', '' ], [ 'hr', '-hr' ] ] ) {
		wp( [ 'site', 'switch-language', locale ] );
		wp( [ 'user', 'meta', 'delete', 'admin', 'locale' ], { allowFail: true } );
		const page = await browser.newPage( { viewport: { width: 1280, height: 860 } } );
		await page.goto( `${ SITE }/wp-login.php` );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'password' );
		await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
		console.log( locale );
		const shots = [
			{ url: `${ ADMIN }?page=cjenik-setup&step=1` },
			{ url: `${ ADMIN }?page=cjenik&tab=status` },
			{ url: `${ ADMIN }?page=cjenik&tab=log` },
			{ url: urls.product, focus: '.cjenik-cijene' },
			{ url: `${ ADMIN }?page=cjenik&tab=anchors` },
			{ url: urls.archive, focus: '.cjenik-arhiva' },
		];
		for ( const [ i, shot ] of shots.entries() ) {
			await capture( page, { ...shot, file: `screenshot-${ i + 1 }${ suffix }.png` } );
		}
		await page.close();
	}
} finally {
	wp( [ 'site', 'switch-language', 'en_US' ] );
	await browser.close();
}

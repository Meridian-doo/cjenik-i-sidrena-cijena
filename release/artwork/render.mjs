#!/usr/bin/env node
// Renders the icon and banners from icon.svg and banner.html into
// .wordpress-org/ at the exact sizes WordPress.org expects.
//
//   node release/artwork/render.mjs     (needs: npx playwright install chromium)
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { chromium } from 'playwright';
import { ASSETS_DIR, ROOT } from '../config.mjs';

const here = path.join( ROOT, 'release', 'artwork' );
const url = ( file, query = '' ) => pathToFileURL( path.join( here, file ) ).href + query;

const browser = await chromium.launch();
try {
	for ( const size of [ 128, 256 ] ) {
		const page = await browser.newPage( { viewport: { width: size, height: size } } );
		// The SVG has no width/height, so it fills the viewport.
		await page.goto( url( 'icon.svg' ) );
		await page.screenshot( { path: path.join( ASSETS_DIR, `icon-${ size }x${ size }.png` ), omitBackground: true } );
		await page.close();
	}
	for ( const [ lang, suffix ] of [ [ 'en', '' ], [ 'hr', '-hr' ] ] ) {
		for ( const scale of [ 1, 2 ] ) {
			const page = await browser.newPage( { viewport: { width: 772, height: 250 }, deviceScaleFactor: scale } );
			await page.goto( url( 'banner.html', `?lang=${ lang }` ), { waitUntil: 'networkidle' } );
			await page.screenshot( { path: path.join( ASSETS_DIR, `banner-${ 772 * scale }x${ 250 * scale }${ suffix }.png` ) } );
			await page.close();
		}
	}
} finally {
	await browser.close();
}
console.log( 'Rendered the icon and banners into .wordpress-org/.' );

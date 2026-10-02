// Deliberately broken releases, one per rule (a few rules have more). The
// release check proves on every run that each fixture's rule fails on it and
// passes on the unbroken baseline, so a rule that can no longer fail is caught.
// A fixture may break other rules too (a renamed folder breaks most of them);
// only its own rule is asserted.
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { SLUG } from './config.mjs';
import { readPo, serializePo } from './lib/po.mjs';

const edit = ( file, change ) => fs.writeFileSync( file, change( fs.readFileSync( file, 'utf8' ) ) );
const readme = ( ws, change ) => edit( path.join( ws.plugin, 'readme.txt' ), change );
const main = ( ws, change ) => edit( path.join( ws.plugin, `${ SLUG }.php` ), change );
const appendToMain = ( ws, code ) => main( ws, ( s ) => `${ s }\n${ code }\n` );
/** The readme PO entry with this extracted comment (e.g. "Short description."). */
const entry = ( parsed, comment ) => parsed.entries.find( ( e ) => e.comments.includes( `#. ${ comment }` ) );
const po = ( file, change ) => {
	const parsed = readPo( file );
	change( parsed );
	fs.writeFileSync( file, serializePo( parsed ) );
};

/** Fixtures checked against the zip and the listing files (stages 1–5). */
export const STATIC_FIXTURES = [
	{ rule: 'artifact.forbidden-files', name: 'a .sh script in the zip', mutate: ( ws ) => fs.writeFileSync( path.join( ws.plugin, 'build.sh' ), '#!/bin/sh\n' ) },
	{ rule: 'artifact.size', name: 'an 11 MB zip', mutate: ( ws ) => fs.writeFileSync( path.join( ws.plugin, 'languages', 'padding.dat' ), crypto.randomBytes( 11 * 1024 * 1024 ) ) },
	{ rule: 'artifact.top-folder', name: 'the old folder name', mutate: ( ws ) => ( ws.topFolder = 'cjenik-sidrena-cijena' ) },
	{ rule: 'artifact.main-file', name: 'the main file named plugin.php', mutate: ( ws ) => fs.renameSync( path.join( ws.plugin, `${ SLUG }.php` ), path.join( ws.plugin, 'plugin.php' ) ) },
	{ rule: 'metadata.readme-parses', name: 'a readme name that differs from the header', mutate: ( ws ) => readme( ws, ( s ) => s.replace( /^=== .+ ===/, '=== Cjenik ===' ) ) },
	{
		rule: 'metadata.slug',
		name: 'the name "Cjenik – sidrena cijena"',
		mutate: ( ws ) => {
			main( ws, ( s ) => s.replace( /Plugin Name:(\s*).+/, 'Plugin Name:$1Cjenik – sidrena cijena' ) );
			readme( ws, ( s ) => s.replace( /^=== .+ ===/, '=== Cjenik – sidrena cijena ===' ) );
		},
	},
	{
		rule: 'metadata.trademark',
		name: '"za WooCommerce" in the name',
		mutate: ( ws ) => {
			main( ws, ( s ) => s.replace( /Plugin Name:(\s*)(.+)/, 'Plugin Name:$1$2 za WooCommerce' ) );
			readme( ws, ( s ) => s.replace( /^=== (.+) ===/, '=== $1 za WooCommerce ===' ) );
		},
	},
	{ rule: 'metadata.tags', name: 'six tags', mutate: ( ws ) => readme( ws, ( s ) => s.replace( /^(Tags: .+)$/m, '$1, price list' ) ) },
	{ rule: 'metadata.short-description', name: 'a 151-character short description', mutate: ( ws ) => readme( ws, ( s ) => s.replace( /lowest 30-day price\.\n/, 'lowest 30-day price. Xx\n' ) ) },
	{ rule: 'metadata.versions', name: 'a Stable tag ahead of the Version header', mutate: ( ws ) => readme( ws, ( s ) => s.replace( /^Stable tag: .+$/m, 'Stable tag: 99.0.0' ) ) },
	{ rule: 'metadata.requirements', name: 'a readme Requires PHP that disagrees with the header', mutate: ( ws ) => readme( ws, ( s ) => s.replace( /^Requires PHP: .+$/m, 'Requires PHP: 7.4' ) ) },
	{ rule: 'metadata.requirements', name: 'a Tested up to line in the plugin header', mutate: ( ws ) => main( ws, ( s ) => s.replace( /^( \* Requires PHP:.*\n)/m, ' * Tested up to:      7.1\n$1' ) ) },
	{ rule: 'metadata.text-domain', name: 'the old text domain in the header', mutate: ( ws ) => main( ws, ( s ) => s.replace( /Text Domain:(\s*).+/, 'Text Domain:$1cjenik-sidrena-cijena' ) ) },
	{
		rule: 'metadata.changelog',
		name: 'three versions in the readme changelog',
		mutate: ( ws ) => readme( ws, ( s ) => s.replace( /== Changelog ==\n/, '== Changelog ==\n\n= 0.0.3 =\n* Old.\n\n= 0.0.2 =\n* Older.\n' ) ),
	},
	{ rule: 'metadata.disclaimer', name: 'no disclaimer', mutate: ( ws ) => readme( ws, ( s ) => s.replace( 'No plugin can guarantee legal compliance;', 'Nothing can guarantee anything;' ) ) },
	{ rule: 'wording.compliance-claims', name: '"100% compliant" in the readme', mutate: ( ws ) => readme( ws, ( s ) => s.replace( '= Features =', 'Makes your shop 100% compliant.\n\n= Features =' ) ) },
	{
		rule: 'wording.compliance-claims',
		name: '"zakonski usklađen" in the Croatian readme',
		mutate: ( ws ) => po( ws.readmePoPath, ( p ) => ( entry( p, 'Short description.' ).msgstr[ 0 ] += ' Zakonski usklađen cjenik.' ) ),
	},
	{ rule: 'wording.compliance-claims', name: 'a compliance claim on a banner', mutate: ( ws ) => edit( ws.assetTextPath, ( s ) => JSON.stringify( { ...JSON.parse( s ), 'banner-772x250.png': 'Fully compliant price lists' } ) ) },
	{ rule: 'translation.readme-po-current', name: 'a readme edit without regenerating the PO', mutate: ( ws ) => readme( ws, ( s ) => s.replace( 'Link the archive page from your site footer.', 'Link the archive page from your footer.' ) ) },
	{ rule: 'translation.readme-po-complete', name: 'an untranslated readme string', mutate: ( ws ) => po( ws.readmePoPath, ( p ) => ( entry( p, 'Short description.' ).msgstr = [ '' ] ) ) },
	{ rule: 'translation.readme-po-complete', name: 'a translated plugin name', mutate: ( ws ) => po( ws.readmePoPath, ( p ) => ( entry( p, 'Plugin name.' ).msgstr = [ 'Price list and anchor price' ] ) ) },
	{
		rule: 'translation.code-po-complete',
		name: 'an untranslated code string',
		mutate: ( ws ) => edit( path.join( ws.plugin, 'languages', `${ SLUG }-hr.po` ), ( s ) => s.replace( /msgid "Anchor prices"\nmsgstr ".+"/, 'msgid "Anchor prices"\nmsgstr ""' ) ),
	},
	{ rule: 'assets.names', name: 'an uppercase asset name', mutate: ( ws ) => fs.renameSync( path.join( ws.assetsDir, 'screenshot-1.png' ), path.join( ws.assetsDir, 'Screenshot-1.png' ) ) },
	{ rule: 'assets.icon', name: 'a missing 256×256 icon', mutate: ( ws ) => fs.rmSync( path.join( ws.assetsDir, 'icon-256x256.png' ) ) },
	{ rule: 'assets.banners', name: 'an English banner copied as the -hr one', mutate: ( ws ) => fs.copyFileSync( path.join( ws.assetsDir, 'banner-772x250.png' ), path.join( ws.assetsDir, 'banner-772x250-hr.png' ) ) },
	{ rule: 'assets.screenshots', name: 'a missing -hr screenshot', mutate: ( ws ) => fs.rmSync( path.join( ws.assetsDir, 'screenshot-2-hr.png' ) ) },
	{
		rule: 'assets.blueprint',
		name: 'a blueprint that sets the language',
		mutate: ( ws ) => edit( path.join( ws.assetsDir, 'blueprints', 'blueprint.json' ), ( s ) => {
			const b = JSON.parse( s );
			b.steps.push( { step: 'setSiteLanguage', language: 'hr' } );
			return JSON.stringify( b );
		} ),
	},
	{ rule: 'assets.text-manifest', name: 'no text recorded for a banner', mutate: ( ws ) => edit( ws.assetTextPath, ( s ) => JSON.stringify( { ...JSON.parse( s ), 'banner-1544x500-hr.png': undefined } ) ) },
];

/** Fixtures installed into the release-check site (stage 6). */
export const INSTALL_FIXTURES = [
	{ rule: 'install.activates', name: 'a fatal error on load', mutate: ( ws ) => appendToMain( ws, "throw new RuntimeException( 'Release check fixture' );" ) },
	{ rule: 'install.plugin-check', name: 'unescaped output', mutate: ( ws ) => fs.writeFileSync( path.join( ws.plugin, 'src', 'Fixture.php' ), "<?php\necho $_GET['cjenik'];\n" ) },
	{ rule: 'install.readme-strings', name: 'a readme paragraph the PO lacks', mutate: ( ws ) => readme( ws, ( s ) => s.replace( '= Features =', 'A new paragraph.\n\n= Features =' ) ) },
	{ rule: 'install.code-pot-current', name: 'a new untranslated code string', mutate: ( ws ) => appendToMain( ws, `add_action( 'init', static fn() => __( 'Release check fixture', '${ SLUG }' ) );` ) },
	{ rule: 'install.publishes', name: 'a publication that fails', mutate: ( ws ) => appendToMain( ws, "add_filter( 'cjenik_price_list_row', static function () { throw new RuntimeException( 'fixture' ); } );" ) },
	{
		rule: 'install.no-external-requests',
		name: 'a request to example.com after publishing',
		mutate: ( ws ) => appendToMain( ws, "add_action( 'cjenik_published', static function () { wp_remote_get( 'https://example.com/' ); } );" ),
	},
	{ rule: 'install.no-credits', name: 'a "Powered by" line under the archive', mutate: ( ws ) => appendToMain( ws, "add_filter( 'the_content', static fn( $c ) => $c . '<p>Powered by Cjenik i sidrena cijena</p>' );" ) },
];

/** Fixtures booted in Playground (stage 7). */
export const PREVIEW_FIXTURES = [
	{
		rule: 'preview.landing-page',
		name: 'a fatal error on the status page',
		mutate: ( ws ) => appendToMain( ws, "add_action( 'admin_notices', static function () { if ( 'cjenik' === ( $_GET['page'] ?? '' ) ) { cjenik_release_check_undefined(); } } );" ),
	},
];

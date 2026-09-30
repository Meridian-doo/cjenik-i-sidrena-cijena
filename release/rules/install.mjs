import path from 'node:path';
import { SLUG, ROOT } from '../config.mjs';
import { wp, wpEnv, lastJson } from '../lib/wpenv.mjs';
import { parsePo, poKey, translatedKeys } from '../lib/po.mjs';

const SITE_DIR = path.join( ROOT, 'release', 'env' );
const SITE_URL = 'http://localhost:8890';

const site = ( args, options = {} ) => wp( args, { cwd: SITE_DIR, ...options } );
const probe = ( ctx ) => ( ctx.probe ??= lastJson( site( [ 'eval-file', 'cjenik-release/publication-probe.php' ] ).stdout ) );
/** The strings translate.wordpress.org will import from the installed readme.txt. */
const importedReadmeStrings = ( ctx ) =>
	( ctx.importedReadmeStrings ??= lastJson( site( [ 'eval-file', 'cjenik-release/readme.php', `wp-content/plugins/${ SLUG }/readme.txt` ] ).stdout ).strings.map( ( s ) => s.text ) );

/** Text that would credit the plugin on the public site (guideline 10). */
const CREDIT = new RegExp( [ 'powered by', 'cjenik i sidrena cijena', SLUG, 'meridian', 'github\\.com/meridian-doo' ].join( '|' ), 'i' );

/**
 * Starts the clean release-check site (release/env), empties its database and
 * installs the current Plugin Check from WordPress.org, as the uploader uses.
 */
export function startSite() {
	for ( const args of [ [ 'start' ], [ 'clean', 'development' ] ] ) {
		const result = wpEnv( args, { cwd: SITE_DIR } );
		if ( ! result.ok ) {
			throw new Error( `wp-env ${ args.join( ' ' ) } failed in release/env:\n${ result.stderr }` );
		}
	}
	site( [ 'plugin', 'install', 'plugin-check', '--force', '--activate' ] );
}

/** Path of a zip under dist/ as the release site's containers see it. */
const containerZip = ( zipPath ) => `/var/www/html/cjenik-dist/${ path.relative( path.join( ROOT, 'dist' ), zipPath ).split( path.sep ).join( '/' ) }`;

/** Every other install rule needs the zip installed; stage 6 runs this first. */
export const activates = {
	id: 'install.activates',
	title: 'The zip installs through WP-CLI and activates next to WooCommerce',
	run: ( ctx ) => {
		// Without loading plugins, so a broken copy from an earlier run can't crash WP-CLI.
		site( [ 'plugin', 'deactivate', SLUG, '--skip-plugins' ], { allowFail: true } );
		site( [ 'plugin', 'delete', SLUG, '--skip-plugins' ], { allowFail: true } );
		const install = site( [ 'plugin', 'install', containerZip( ctx.zipPath ), '--activate' ], { allowFail: true } );
		const active = site( [ 'plugin', 'is-active', SLUG ], { allowFail: true } );
		return install.ok && active.ok ? [] : [ `Install or activation failed:\n${ install.stdout }${ install.stderr }${ active.stderr }`.trim() ];
	},
};

export default [
	activates,
	{
		id: 'install.plugin-check',
		title: 'Plugin Check (plugin_repo category) reports no errors',
		run: ( ctx ) => {
			const result = site( [ 'plugin', 'check', SLUG, '--categories=plugin_repo', '--format=json', '--require=./wp-content/plugins/plugin-check/cli.php' ], { allowFail: true } );
			const findings = [];
			let file = '';
			for ( const line of result.stdout.split( '\n' ) ) {
				if ( line.startsWith( 'FILE: ' ) ) {
					file = line.slice( 6 ).trim();
				} else if ( line.trim().startsWith( '[' ) ) {
					JSON.parse( line ).forEach( ( f ) => findings.push( { ...f, file } ) );
				}
			}
			if ( ! result.ok && ! findings.length ) {
				return [ `Plugin Check did not run:\n${ result.stdout }${ result.stderr }`.trim() ];
			}
			const describe = ( f ) => `${ f.file }:${ f.line } ${ f.code }: ${ f.message }`;
			findings.filter( ( f ) => f.type !== 'ERROR' ).forEach( ( f ) => ctx.log( `      warning ${ describe( f ) }` ) );
			return findings.filter( ( f ) => f.type === 'ERROR' ).map( describe );
		},
	},
	{
		id: 'install.readme-strings',
		title: 'release/readme-hr.po has exactly the strings translate.wordpress.org imports from readme.txt',
		run: ( ctx ) => {
			const imported = new Set( importedReadmeStrings( ctx ) );
			const inPo = new Set( ( ctx.readmePo?.entries ?? [] ).map( ( e ) => e.msgid ) );
			return [
				...[ ...imported ].filter( ( s ) => ! inPo.has( s ) ).map( ( s ) => `Not in readme-hr.po: "${ s.slice( 0, 80 ) }". Run npm run readme:po.` ),
				...[ ...inPo ].filter( ( s ) => ! imported.has( s ) ).map( ( s ) => `No longer in readme.txt: "${ s.slice( 0, 80 ) }". Run npm run readme:po.` ),
			];
		},
	},
	{
		id: 'install.code-pot-current',
		title: 'Every translatable string in the installed code is translated in the Croatian PO',
		run: ( ctx ) => {
			const pot = '/tmp/cjenik-release-check.pot';
			site( [ 'i18n', 'make-pot', `wp-content/plugins/${ SLUG }`, pot, `--domain=${ SLUG }`, '--skip-js', '--skip-block-json', '--skip-theme-json' ] );
			const generated = wpEnv( [ 'run', 'cli', 'cat', pot ], { cwd: SITE_DIR } );
			if ( ! generated.ok ) {
				return [ `Could not read the generated POT:\n${ generated.stderr }` ];
			}
			const found = parsePo( generated.stdout );
			const translated = translatedKeys( ctx.codePo );
			return found.entries.filter( ( e ) => ! translated.has( poKey( e ) ) ).map( ( e ) => `"${ e.msgid.slice( 0, 80 ) }" is in the code but not translated in languages/${ SLUG }-hr.po. Regenerate the POT, merge and translate.` );
		},
	},
	{
		id: 'install.publishes',
		title: 'The installed plugin publishes a price list file',
		run: ( ctx ) => ( probe( ctx ).published ? [] : [ `No price list file after publishing: ${ JSON.stringify( probe( ctx ) ) }` ] ),
	},
	{
		id: 'install.no-external-requests',
		title: "A publication and self-check make no HTTP request except to the site's own host",
		run: ( ctx ) => {
			const own = new URL( SITE_URL ).hostname;
			return probe( ctx ).requests.filter( ( url ) => new URL( url ).hostname !== own ).map( ( url ) => `Outbound request to ${ url }` );
		},
	},
	{
		id: 'install.no-credits',
		title: 'The archive page, the price list file and a product page carry no plugin credit',
		run: async ( ctx ) => {
			const { archive_url: archive, file_url: file, product_url: product } = probe( ctx );
			const problems = [];
			for ( const [ label, url ] of [ [ 'Archive page', archive ], [ 'Price list file', file ], [ 'Product page', product ] ] ) {
				if ( ! url ) {
					problems.push( `${ label }: no URL to check.` );
					continue;
				}
				const response = await fetch( url );
				// Asset URLs under the plugin folder name it without crediting it.
				const body = ( await response.text() ).replaceAll( `/wp-content/plugins/${ SLUG }/`, '/' );
				const hit = body.match( CREDIT );
				if ( ! response.ok ) {
					problems.push( `${ label } ${ url } returned ${ response.status }.` );
				} else if ( hit ) {
					problems.push( `${ label } ${ url } contains "${ body.slice( Math.max( 0, hit.index - 60 ), hit.index + 60 ).replace( /\s+/g, ' ' ) }"` );
				}
			}
			return problems;
		},
	},
];

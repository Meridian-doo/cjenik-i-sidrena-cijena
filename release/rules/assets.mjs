import fs from 'node:fs';
import path from 'node:path';
import { SLUG } from '../config.mjs';
import { pngSize } from '../lib/png.mjs';

const MB = 1024 * 1024;
const LOCALE = 'hr';
const ICONS = [ [ 'icon-128x128.png', 128, 128 ], [ 'icon-256x256.png', 256, 256 ] ];
const BANNERS = [ [ 'banner-772x250', 772, 250 ], [ 'banner-1544x500', 1544, 500 ] ];
// The file names the directory's importer accepts (plugin-directory cli/class-import.php).
const ASSET_NAME = /^((screenshot-\d+|banner-\d+x\d+|icon-\d+x\d+)(-rtl)?(-[a-z]{2,3}(_[A-Z]{2})?)?\.(png|jpg|jpeg|gif)|icon\.svg)$/;

function image( dir, file, width, height, maxBytes ) {
	const full = path.join( dir, file );
	if ( ! fs.existsSync( full ) ) {
		return [ `${ file } is missing.` ];
	}
	const bytes = fs.readFileSync( full );
	const size = pngSize( bytes );
	const problems = [];
	if ( ! size ) {
		problems.push( `${ file } is not a PNG.` );
	} else if ( width && ( size.width !== width || size.height !== height ) ) {
		problems.push( `${ file } is ${ size.width }×${ size.height }; expected ${ width }×${ height }.` );
	}
	if ( bytes.length > maxBytes ) {
		problems.push( `${ file } is ${ ( bytes.length / MB ).toFixed( 1 ) } MB; the limit is ${ maxBytes / MB } MB.` );
	}
	return problems;
}

/** A localised image must differ from the English one (Plugin Assets handbook). */
function localisedDiffers( dir, english, localised ) {
	const a = path.join( dir, english );
	const b = path.join( dir, localised );
	return fs.existsSync( a ) && fs.existsSync( b ) && fs.readFileSync( a ).equals( fs.readFileSync( b ) ) ? [ `${ localised } is an unchanged copy of ${ english }.` ] : [];
}

export default [
	{
		id: 'assets.names',
		title: 'Every file in .wordpress-org/ has a lowercase name the directory accepts',
		run: ( { assetsDir } ) => {
			if ( ! fs.existsSync( assetsDir ) ) {
				return [ `${ assetsDir } is missing.` ];
			}
			return fs
				.readdirSync( assetsDir, { withFileTypes: true } )
				.filter( ( e ) => ! ( e.isDirectory() && e.name === 'blueprints' ) )
				.filter( ( e ) => ! e.isFile() || e.name !== e.name.toLowerCase() || ! ASSET_NAME.test( e.name ) )
				.map( ( e ) => `${ e.name } is not a valid asset name (lowercase screenshot-N, banner-WxH, icon-WxH, optional -${ LOCALE }).` );
		},
	},
	{
		id: 'assets.icon',
		title: 'icon-128x128.png and icon-256x256.png exist at their sizes, ≤ 1 MB (icon.svg optional)',
		run: ( { assetsDir } ) => {
			const problems = ICONS.flatMap( ( [ file, w, h ] ) => image( assetsDir, file, w, h, MB ) );
			const svg = path.join( assetsDir, 'icon.svg' );
			if ( fs.existsSync( svg ) && fs.statSync( svg ).size > MB ) {
				problems.push( 'icon.svg is over 1 MB.' );
			}
			return problems;
		},
	},
	{
		id: 'assets.banners',
		title: `Banners 772×250 and 1544×500 exist in English and -${ LOCALE }, ≤ 4 MB, and the -${ LOCALE } ones differ`,
		run: ( { assetsDir } ) =>
			BANNERS.flatMap( ( [ name, w, h ] ) => [
				...image( assetsDir, `${ name }.png`, w, h, 4 * MB ),
				...image( assetsDir, `${ name }-${ LOCALE }.png`, w, h, 4 * MB ),
				...localisedDiffers( assetsDir, `${ name }.png`, `${ name }-${ LOCALE }.png` ),
			] ),
	},
	{
		id: 'assets.screenshots',
		title: `One screenshot-N.png and screenshot-N-${ LOCALE }.png per readme Screenshots entry, ≤ 10 MB`,
		run: ( { assetsDir, readme } ) => {
			const count = readme.screenshots.length;
			const problems = [];
			for ( let n = 1; n <= count; n++ ) {
				problems.push(
					...image( assetsDir, `screenshot-${ n }.png`, null, null, 10 * MB ),
					...image( assetsDir, `screenshot-${ n }-${ LOCALE }.png`, null, null, 10 * MB ),
					...localisedDiffers( assetsDir, `screenshot-${ n }.png`, `screenshot-${ n }-${ LOCALE }.png` )
				);
			}
			const extra = fs.existsSync( assetsDir )
				? fs.readdirSync( assetsDir ).filter( ( f ) => Number( f.match( /^screenshot-(\d+)/ )?.[ 1 ] ?? 0 ) > count )
				: [];
			extra.forEach( ( f ) => problems.push( `${ f } has no caption; the readme lists ${ count } screenshots.` ) );
			return problems;
		},
	},
	{
		id: 'assets.blueprint',
		title: 'blueprints/blueprint.json is valid, ≤ 100 KB, installs this plugin and sets no language',
		run: ( { assetsDir } ) => {
			const file = path.join( assetsDir, 'blueprints', 'blueprint.json' );
			if ( ! fs.existsSync( file ) ) {
				return [ 'blueprints/blueprint.json is missing.' ];
			}
			const raw = fs.readFileSync( file, 'utf8' );
			const problems = raw.length > 100 * 1024 ? [ `blueprint.json is ${ ( raw.length / 1024 ).toFixed( 0 ) } KB; the limit is 100 KB.` ] : [];
			let blueprint;
			try {
				blueprint = JSON.parse( raw );
			} catch ( e ) {
				return [ ...problems, `blueprint.json is not valid JSON: ${ e.message }` ];
			}
			const steps = Array.isArray( blueprint.steps ) ? blueprint.steps : [];
			if ( steps.some( ( s ) => s?.step === 'setSiteLanguage' ) || 'language' in ( blueprint.preferredVersions ?? {} ) ) {
				problems.push( 'blueprint.json must not set a language; the directory adds setSiteLanguage for locale visitors.' );
			}
			if ( ! steps.some( ( s ) => s?.step === 'installPlugin' && s.pluginData?.slug === SLUG ) ) {
				problems.push( `blueprint.json has no installPlugin step for wordpress.org/plugins slug "${ SLUG }".` );
			}
			if ( ! blueprint.landingPage ) {
				problems.push( 'blueprint.json has no landingPage.' );
			}
			return problems;
		},
	},
	{
		id: 'assets.text-manifest',
		title: 'release/asset-text.json records the text drawn into every icon and banner',
		run: ( { assetText } ) => {
			if ( ! assetText ) {
				return [ 'release/asset-text.json is missing.' ];
			}
			const needed = [ ...ICONS.map( ( [ f ] ) => f ), ...BANNERS.flatMap( ( [ n ] ) => [ `${ n }.png`, `${ n }-${ LOCALE }.png` ] ) ];
			return needed.filter( ( f ) => typeof assetText[ f ] !== 'string' ).map( ( f ) => `asset-text.json has no entry for ${ f } (use "" if it has no text).` );
		},
	},
];

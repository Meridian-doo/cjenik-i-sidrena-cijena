import { SLUG, VERSION_CONSTANT, DISCLAIMERS } from '../config.mjs';
import { pluginSlug, wooTrademarkProblem } from '../lib/text.mjs';

const REQUIRED_SECTIONS = [ 'description', 'installation', 'faq', 'screenshots', 'changelog', 'upgrade_notice' ];

export default [
	{
		id: 'metadata.readme-parses',
		title: 'readme.txt parses, has the expected sections and the same name as the plugin header',
		run: ( { readme, header } ) => {
			if ( readme.missing ) {
				return [ 'readme.txt is missing.' ];
			}
			if ( readme.error ) {
				return [ readme.error ];
			}
			const problems = REQUIRED_SECTIONS.filter( ( s ) => ! readme.sections[ s ] ).map( ( s ) => `readme.txt has no "${ s }" section.` );
			if ( readme.name !== header.name ) {
				problems.push( `readme name "${ readme.name }" differs from Plugin Name "${ header.name }".` );
			}
			return problems;
		},
	},
	{
		id: 'metadata.slug',
		title: `The plugin name produces the slug ${ SLUG }`,
		run: ( { header } ) => ( pluginSlug( header.name ) === SLUG ? [] : [ `"${ header.name }" produces the slug "${ pluginSlug( header.name ) }", not "${ SLUG }".` ] ),
	},
	{
		id: 'metadata.trademark',
		title: 'The name uses "WooCommerce" only as a trailing "for WooCommerce"',
		run: ( { header, readme } ) => [ ...new Set( [ header.name, readme.name ] ) ].map( wooTrademarkProblem ).filter( Boolean ),
	},
	{
		id: 'metadata.tags',
		title: 'readme.txt has exactly five tags',
		run: ( { readme } ) => ( readme.tags.length === 5 ? [] : [ `readme.txt has ${ readme.tags.length } tags (${ readme.tags.join( ', ' ) }); use exactly 5.` ] ),
	},
	{
		id: 'metadata.short-description',
		title: 'The short description is 1–150 characters',
		run: ( { readme } ) => {
			const length = [ ...readme.short_description ].length;
			return length > 0 && length <= 150 ? [] : [ `The short description is ${ length } characters; it must be 1–150.` ];
		},
	},
	{
		id: 'metadata.versions',
		title: `Stable tag, Version header, ${ VERSION_CONSTANT } and the newest changelog entry agree`,
		run: ( { readme, header, mainPhp } ) => {
			const constant = mainPhp.match( new RegExp( `define\\(\\s*['"]${ VERSION_CONSTANT }['"]\\s*,\\s*['"]([^'"]+)['"]` ) )?.[ 1 ];
			const versions = {
				'readme Stable tag': readme.headers.stable_tag,
				'Version header': header.version,
				[ VERSION_CONSTANT ]: constant,
				'newest changelog entry': readme.changelog_versions[ 0 ],
			};
			if ( ! /^\d+(\.\d+)*$/.test( versions[ 'Version header' ] ?? '' ) ) {
				return [ `Version "${ header.version }" must be numbers and dots only (SVN tags allow nothing else).` ];
			}
			const distinct = new Set( Object.values( versions ) );
			return distinct.size === 1 ? [] : [ 'Versions disagree: ' + Object.entries( versions ).map( ( [ k, v ] ) => `${ k } = ${ v ?? '(missing)' }` ).join( ', ' ) ];
		},
	},
	{
		id: 'metadata.requirements',
		title: 'Requires at least and Requires PHP agree between header and readme; Tested up to is only in the readme',
		run: ( { readme, header } ) => {
			const problems = [
				[ 'Requires at least', header.requires, readme.headers.requires ],
				[ 'Requires PHP', header.requires_php, readme.headers.requires_php ],
			]
				.filter( ( [ , h, r ] ) => ! h || h !== r )
				.map( ( [ field, h, r ] ) => `${ field }: plugin header "${ h ?? '' }", readme "${ r ?? '' }".` );
			if ( ! readme.headers.tested ) {
				problems.push( 'Tested up to is missing from the readme.' );
			}
			// WordPress.org's upload scan rejects it in the header (plugin_header_tested_up_to_not_allowed).
			if ( header.tested ) {
				problems.push( `Tested up to "${ header.tested }" is in the plugin header; keep it only in the readme.` );
			}
			return problems;
		},
	},
	{
		id: 'metadata.text-domain',
		title: `The text domain is ${ SLUG } and the zip bundles no translations (WordPress.org language packs deliver them)`,
		run: ( { header, entries } ) => {
			const problems = [];
			if ( header.text_domain !== SLUG ) {
				problems.push( `Text Domain is "${ header.text_domain }", expected "${ SLUG }".` );
			}
			if ( header.domain_path ) {
				problems.push( `Remove the Domain Path header ("${ header.domain_path }"); translations come from translate.wordpress.org.` );
			}
			entries.filter( ( e ) => /\.(po|mo|l10n\.php)$/.test( e ) ).forEach( ( e ) => problems.push( `${ e } is a translation file; leave translations to translate.wordpress.org.` ) );
			return problems;
		},
	},
	{
		id: 'metadata.changelog',
		title: 'The readme changelog keeps only the current and previous version; changelog.txt holds the rest',
		run: ( { readme, entries } ) => {
			const problems = [];
			if ( readme.changelog_versions.length > 2 ) {
				problems.push( `The readme changelog lists ${ readme.changelog_versions.length } versions; move all but the newest two to changelog.txt.` );
			}
			if ( ! entries.includes( `${ SLUG }/changelog.txt` ) ) {
				problems.push( 'changelog.txt is missing from the zip.' );
			}
			return problems;
		},
	},
	{
		id: 'metadata.disclaimer',
		title: 'The readme carries the compliance disclaimer',
		run: ( { readmeText } ) => ( readmeText.includes( DISCLAIMERS[ 0 ] ) ? [] : [ `readme.txt must contain, verbatim: "${ DISCLAIMERS[ 0 ] }"` ] ),
	},
];

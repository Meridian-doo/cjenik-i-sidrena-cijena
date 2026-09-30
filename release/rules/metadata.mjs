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
		title: 'Tested up to, Requires at least and Requires PHP are set and agree between header and readme',
		run: ( { readme, header } ) =>
			[
				[ 'Tested up to', header.tested, readme.headers.tested ],
				[ 'Requires at least', header.requires, readme.headers.requires ],
				[ 'Requires PHP', header.requires_php, readme.headers.requires_php ],
			]
				.filter( ( [ , h, r ] ) => ! h || h !== r )
				.map( ( [ field, h, r ] ) => `${ field }: plugin header "${ h ?? '' }", readme "${ r ?? '' }".` ),
	},
	{
		id: 'metadata.text-domain',
		title: `The text domain is ${ SLUG } and its languages folder exists`,
		run: ( { header, entries } ) => {
			const problems = [];
			if ( header.text_domain !== SLUG ) {
				problems.push( `Text Domain is "${ header.text_domain }", expected "${ SLUG }".` );
			}
			const langDir = `${ SLUG }/${ header.domain_path.replace( /^\/|\/$/g, '' ) }/`;
			if ( ! header.domain_path || ! entries.some( ( e ) => e.startsWith( langDir ) && e !== langDir ) ) {
				problems.push( `Domain Path "${ header.domain_path }" is missing or empty in the zip.` );
			}
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

import { SLUG } from '../config.mjs';
import { isTranslated, poKey, translatedKeys, moMessageCount } from '../lib/po.mjs';
import { readmeSourceHash } from '../lib/readme.mjs';

export const README_HASH_HEADER = 'X-Readme-Source-Hash';

const untranslated = ( po, label ) =>
	po.entries.filter( ( e ) => ! isTranslated( e ) ).map( ( e ) => `${ label }: "${ e.msgid.slice( 0, 70 ) }" is ${ e.flags.includes( 'fuzzy' ) ? 'fuzzy' : 'untranslated' }.` );

export default [
	{
		id: 'translation.readme-po-current',
		title: 'release/readme-hr.po was generated from this readme.txt',
		run: ( { readmePo, readmeText } ) => {
			if ( ! readmePo ) {
				return [ 'release/readme-hr.po is missing. Run npm run readme:po.' ];
			}
			return readmePo.headers[ README_HASH_HEADER ] === readmeSourceHash( readmeText )
				? []
				: [ 'readme.txt text changed since release/readme-hr.po was generated. Run npm run readme:po and translate the new strings.' ];
		},
	},
	{
		id: 'translation.readme-po-complete',
		title: 'Every readme string has a Croatian translation, and the name is copied unchanged',
		run: ( { readmePo, readme } ) => {
			const problems = untranslated( readmePo, 'readme-hr.po' );
			const name = readmePo.entries.find( ( e ) => e.msgid === readme.name );
			if ( ! name ) {
				problems.push( 'readme-hr.po has no entry for the plugin name.' );
			} else if ( name.msgstr[ 0 ] !== readme.name ) {
				problems.push( `The plugin name must be copied unchanged (hr style guide), not "${ name.msgstr[ 0 ] }".` );
			}
			return problems;
		},
	},
	{
		id: 'translation.code-po-complete',
		title: `languages/${ SLUG }-hr.po translates every string in the POT, and the .mo is compiled from it`,
		run: ( { codePo, codePot, codeMo } ) => {
			if ( ! codePo || ! codePot || ! codeMo ) {
				return [ `languages/ must contain ${ SLUG }.pot, ${ SLUG }-hr.po and ${ SLUG }-hr.mo.` ];
			}
			const problems = untranslated( codePo, `${ SLUG }-hr.po` );
			for ( const [ file, po ] of [ [ 'POT', codePot ], [ 'PO', codePo ] ] ) {
				if ( po.headers[ 'X-Domain' ] !== SLUG ) {
					problems.push( `${ file } X-Domain is "${ po.headers[ 'X-Domain' ] }", expected "${ SLUG }".` );
				}
			}
			const translated = translatedKeys( codePo );
			codePot.entries.filter( ( e ) => ! translated.has( poKey( e ) ) ).forEach( ( e ) => problems.push( `"${ e.msgid.slice( 0, 70 ) }" is in the POT but not translated in the PO.` ) );
			const expected = codePo.entries.filter( isTranslated ).length + 1;
			if ( moMessageCount( codeMo ) !== expected ) {
				problems.push( `The .mo has ${ moMessageCount( codeMo ) } messages, the PO ${ expected }. Recompile it: msgfmt -o languages/${ SLUG }-hr.mo languages/${ SLUG }-hr.po` );
			}
			return problems;
		},
	},
];

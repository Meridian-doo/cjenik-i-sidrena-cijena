import { CLAIM_DENYLIST, DISCLAIMERS } from '../config.mjs';
import { fold } from '../lib/text.mjs';

/** Every text a reviewer or visitor reads: name, readme, readme and code translations, asset text. */
function texts( { header, readme, readmeText, readmePo, codePo, assetText } ) {
	return [
		[ 'Plugin Name header', header.name ],
		[ 'readme name', readme.name ],
		[ 'readme.txt', readmeText ],
		...( readmePo?.entries ?? [] ).flatMap( ( e ) => [ [ 'readme-hr.po msgid', e.msgid ], [ 'readme-hr.po msgstr', e.msgstr.join( '\n' ) ] ] ),
		...( codePo?.entries ?? [] ).flatMap( ( e ) => [ [ 'code PO msgid', e.msgid ], [ 'code PO msgstr', e.msgstr.join( '\n' ) ] ] ),
		...Object.entries( assetText ?? {} ).map( ( [ file, text ] ) => [ `asset ${ file }`, String( text ) ] ),
	];
}

export default [
	{
		id: 'wording.compliance-claims',
		title: 'No compliance claims (guideline 9), except the disclaimer sentence',
		run: ( ctx ) => {
			const problems = [];
			for ( const [ where, text ] of texts( ctx ) ) {
				// The disclaimer is allowed only verbatim.
				const folded = fold( DISCLAIMERS.reduce( ( rest, sentence ) => rest.split( sentence ).join( ' ' ), text ?? '' ) );
				for ( const phrase of CLAIM_DENYLIST ) {
					const at = folded.indexOf( fold( phrase ) );
					if ( at !== -1 ) {
						problems.push( `${ where }: "${ phrase }" in "…${ folded.slice( Math.max( 0, at - 40 ), at + 40 ).replace( /\s+/g, ' ' ) }…"` );
					}
				}
			}
			return problems;
		},
	},
];

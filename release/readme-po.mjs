#!/usr/bin/env node
// Regenerates release/readme-hr.po from the plugin's readme.txt, keeping every
// existing Croatian translation whose English string is unchanged. New strings
// come out untranslated; the release check fails until they are translated.
//
// Needs the dev wp-env (npm run env:start): the strings come from WordPress.org's
// own readme parser, so they match what translate.wordpress.org imports.
import fs from 'node:fs';
import { SLUG, README_PO, PLUGIN_DIR } from './config.mjs';
import { wp, lastJson } from './lib/wpenv.mjs';
import { readPo, serializePo } from './lib/po.mjs';
import { readmeSourceHash } from './lib/readme.mjs';
import { README_HASH_HEADER } from './rules/translation.mjs';

const readmeText = fs.readFileSync( `${ PLUGIN_DIR }/readme.txt`, 'utf8' );
const { strings } = lastJson( wp( [ 'eval-file', 'cjenik-release/readme.php', `wp-content/plugins/${ SLUG }/readme.txt` ] ).stdout );

const previous = fs.existsSync( README_PO ) ? readPo( README_PO ) : { headers: {}, entries: [] };
const known = new Map( previous.entries.map( ( e ) => [ e.msgid, e ] ) );

const entries = strings.map( ( { text, comment } ) => {
	const old = known.get( text );
	return { comments: [ `#. ${ comment }` ], flags: [], msgid: text, msgstr: [ old?.msgstr[ 0 ] ?? '' ] };
} );

const headers = {
	'Project-Id-Version': `${ SLUG } readme`,
	'Report-Msgid-Bugs-To': `https://wordpress.org/support/plugin/${ SLUG }`,
	'Last-Translator': 'Meridian, obrt za računalno programiranje',
	'Language-Team': 'Croatian',
	Language: 'hr',
	'MIME-Version': '1.0',
	'Content-Type': 'text/plain; charset=UTF-8',
	'Content-Transfer-Encoding': '8bit',
	'Plural-Forms': 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
	[ README_HASH_HEADER ]: readmeSourceHash( readmeText ),
};

fs.writeFileSync( README_PO, serializePo( { headers, entries } ) );

const current = new Set( strings.map( ( s ) => s.text ) );
const dropped = previous.entries.filter( ( e ) => ! current.has( e.msgid ) );
const missing = entries.filter( ( e ) => ! e.msgstr[ 0 ] );
console.log( `release/readme-hr.po: ${ entries.length } strings, ${ missing.length } untranslated, ${ dropped.length } no longer in the readme.` );
dropped.forEach( ( e ) => console.log( `  dropped: ${ e.msgid.slice( 0, 90 ) }\n       was: ${ e.msgstr[ 0 ].slice( 0, 90 ) }` ) );
missing.forEach( ( e ) => console.log( `  untranslated: ${ e.msgid.slice( 0, 100 ) }` ) );

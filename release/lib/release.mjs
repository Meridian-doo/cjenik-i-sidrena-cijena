import fs from 'node:fs';
import path from 'node:path';
import { SLUG } from '../config.mjs';
import { tempDir, unzip, zipEntries } from './files.mjs';
import { parsePluginHeader, parseReadme } from './readme.mjs';
import { parsePo, readPo } from './po.mjs';

/**
 * Everything the release check looks at: the built zip (unpacked), plus the
 * listing files that live beside it in the repo (assets, Croatian readme PO,
 * asset text manifest).
 */
export function loadRelease( zipPath, { assetsDir, readmePoPath, assetTextPath } ) {
	const dir = tempDir( 'cjenik-release' );
	unzip( zipPath, dir );
	const root = path.join( dir, SLUG );
	const read = ( rel, encoding = 'utf8' ) => ( fs.existsSync( path.join( root, rel ) ) ? fs.readFileSync( path.join( root, rel ), encoding ) : null );
	const readJson = ( file ) => ( fs.existsSync( file ) ? JSON.parse( fs.readFileSync( file, 'utf8' ) ) : null );

	const mainPhp = read( `${ SLUG }.php` );
	const readmeText = read( 'readme.txt' ) ?? '';
	const po = ( rel ) => ( read( rel ) === null ? null : parsePo( read( rel ) ) );
	return {
		zipPath,
		entries: zipEntries( zipPath ),
		zipBytes: fs.statSync( zipPath ).size,
		mainPhp,
		header: parsePluginHeader( mainPhp ?? '' ),
		readmeText,
		readme: readmeText ? parseReadme( readmeText ) : { ...parseReadme( '' ), missing: true },
		codePo: po( `languages/${ SLUG }-hr.po` ),
		codePot: po( `languages/${ SLUG }.pot` ),
		codeMo: read( `languages/${ SLUG }-hr.mo`, null ),
		readmePo: fs.existsSync( readmePoPath ) ? readPo( readmePoPath ) : null,
		assetText: readJson( assetTextPath ),
		assetsDir,
	};
}

/**
 * Runs rules in order. Stops after the first failing rule unless keepGoing.
 * A rule returns a list of problems; an exception counts as a problem.
 *
 * @return {Promise<Array<{rule: object, problems: string[]}>>} The failed rules.
 */
export async function runRules( rules, ctx, { keepGoing = false, log = console.log } = {} ) {
	const failed = [];
	for ( const rule of rules ) {
		let problems;
		try {
			problems = await rule.run( ctx );
		} catch ( e ) {
			problems = [ `Rule crashed: ${ e.stack ?? e }` ];
		}
		if ( problems.length ) {
			log( `  ✖ ${ rule.id }: ${ rule.title }` );
			problems.forEach( ( p ) => log( `      ${ p }` ) );
			failed.push( { rule, problems } );
			if ( ! keepGoing ) {
				break;
			}
		} else {
			log( `  ✔ ${ rule.id }` );
		}
	}
	return failed;
}

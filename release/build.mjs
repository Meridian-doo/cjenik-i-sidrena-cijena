#!/usr/bin/env node
// Builds dist/<slug>.zip from the plugin folder, leaving out what .distignore lists.
import fs from 'node:fs';
import path from 'node:path';
import { PLUGIN_DIR, SLUG, ZIP_PATH, DIST_DIR } from './config.mjs';
import { distignore, walk, zipFolder } from './lib/files.mjs';

const ignored = distignore( path.join( PLUGIN_DIR, '.distignore' ) );
const stage = path.join( DIST_DIR, 'build', SLUG );
fs.rmSync( path.dirname( stage ), { recursive: true, force: true } );

let count = 0;
for ( const rel of walk( PLUGIN_DIR ) ) {
	if ( ignored( rel ) ) {
		continue;
	}
	const target = path.join( stage, rel );
	fs.mkdirSync( path.dirname( target ), { recursive: true } );
	fs.copyFileSync( path.join( PLUGIN_DIR, rel ), target );
	count++;
}

zipFolder( stage, ZIP_PATH );
console.log( `Built ${ path.relative( process.cwd(), ZIP_PATH ) } (${ count } files, ${ ( fs.statSync( ZIP_PATH ).size / 1024 ).toFixed( 0 ) } KB)` );

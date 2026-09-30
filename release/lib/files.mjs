import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { execFileSync } from 'node:child_process';

/**
 * Reads .distignore: one pattern per line, "#" comments. A leading "/"
 * anchors the pattern to the plugin folder; otherwise it matches a name at
 * any depth. "*" matches within one name.
 *
 * @return {(relPath: string) => boolean} Whether a path (with "/" separators) is excluded.
 */
export function distignore( file ) {
	const patterns = fs.existsSync( file )
		? fs.readFileSync( file, 'utf8' ).split( /\r?\n/ ).map( ( l ) => l.trim() ).filter( ( l ) => l && ! l.startsWith( '#' ) )
		: [];
	const toRegex = ( glob ) => glob.split( '*' ).map( ( s ) => s.replace( /[.+?^${}()|[\]\\]/g, '\\$&' ) ).join( '[^/]*' );
	const matchers = patterns.map( ( p ) => {
		const anchored = p.startsWith( '/' );
		const body = toRegex( p.replace( /^\/|\/$/g, '' ) );
		return new RegExp( anchored ? `^${ body }(/|$)` : `(^|/)${ body }(/|$)` );
	} );
	return ( relPath ) => matchers.some( ( re ) => re.test( relPath ) );
}

/** Every file under dir, as paths relative to dir with "/" separators. */
export function walk( dir, base = dir ) {
	return fs.readdirSync( dir, { withFileTypes: true } ).flatMap( ( entry ) => {
		const full = path.join( dir, entry.name );
		return entry.isDirectory() ? walk( full, base ) : [ path.relative( base, full ).split( path.sep ).join( '/' ) ];
	} );
}

const created = [];
process.on( 'exit', () => created.forEach( ( dir ) => fs.rmSync( dir, { recursive: true, force: true } ) ) );

/** A temporary folder, removed when the process exits. */
export function tempDir( prefix ) {
	const dir = fs.mkdtempSync( path.join( os.tmpdir(), `${ prefix }-` ) );
	created.push( dir );
	return dir;
}

/** Zips `folder` (which ends up as the zip's single top-level folder). */
export function zipFolder( folder, zipPath ) {
	fs.rmSync( zipPath, { force: true } );
	fs.mkdirSync( path.dirname( zipPath ), { recursive: true } );
	execFileSync( 'zip', [ '-r', '-X', '-q', zipPath, path.basename( folder ) ], { cwd: path.dirname( folder ) } );
}

export function zipEntries( zipPath ) {
	return execFileSync( 'unzip', [ '-Z1', zipPath ], { encoding: 'utf8' } ).split( '\n' ).filter( Boolean );
}

export function unzip( zipPath, into ) {
	execFileSync( 'unzip', [ '-q', '-o', zipPath, '-d', into ] );
}

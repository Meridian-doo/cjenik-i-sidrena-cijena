import fs from 'node:fs';
import path from 'node:path';
import { spawn } from 'node:child_process';
import { SLUG, ROOT } from '../config.mjs';
import { tempDir } from '../lib/files.mjs';

const PORT = 9411;
const BOOT_TIMEOUT_MS = 10 * 60 * 1000;

/** The Live Preview blueprint, installing the given zip instead of the directory's download. */
function blueprintFor( assetsDir, zipPath ) {
	const blueprint = JSON.parse( fs.readFileSync( path.join( assetsDir, 'blueprints', 'blueprint.json' ), 'utf8' ) );
	for ( const step of blueprint.steps ) {
		if ( step.step === 'installPlugin' && step.pluginData?.slug === SLUG ) {
			step.pluginData = { resource: 'vfs', path: `/cjenik-dist/${ path.basename( zipPath ) }` };
		}
	}
	return blueprint;
}

/** Boots Playground with the blueprint and returns the landing page's final response. */
async function bootAndFetch( blueprint, zipPath ) {
	const dir = tempDir( 'cjenik-preview' );
	const file = path.join( dir, 'blueprint.json' );
	fs.writeFileSync( file, JSON.stringify( blueprint ) );
	const server = spawn(
		'npx',
		[ 'wp-playground-cli', 'server', `--blueprint=${ file }`, `--port=${ PORT }`, `--mount=${ path.dirname( zipPath ) }:/cjenik-dist`, '--internal-cookie-store' ],
		{ cwd: ROOT, detached: true, stdio: [ 'ignore', 'pipe', 'pipe' ] }
	);
	let output = '';
	server.stdout.on( 'data', ( d ) => ( output += d ) );
	server.stderr.on( 'data', ( d ) => ( output += d ) );
	try {
		const ready = await new Promise( ( resolve ) => {
			const timer = setTimeout( () => resolve( false ), BOOT_TIMEOUT_MS );
			const poll = setInterval( () => {
				if ( /Ready!/.test( output ) || server.exitCode !== null ) {
					clearInterval( poll );
					clearTimeout( timer );
					resolve( /Ready!/.test( output ) );
				}
			}, 1000 );
		} );
		if ( ! ready ) {
			return { error: `Playground did not start:\n${ output.slice( -3000 ) }` };
		}
		const url = `http://127.0.0.1:${ PORT }${ blueprint.landingPage }`;
		// The first request logs the admin in (cookie kept by Playground) and redirects back.
		let response = await fetch( url, { redirect: 'manual' } );
		for ( let hops = 0; response.status >= 300 && response.status < 400 && hops < 5; hops++ ) {
			const next = new URL( response.headers.get( 'location' ), url ).href;
			if ( next !== url ) {
				return { error: `The landing page redirects to ${ next } instead of rendering.` };
			}
			response = await fetch( url, { redirect: 'manual' } );
		}
		return { status: response.status, body: await response.text(), log: output };
	} finally {
		try {
			process.kill( -server.pid, 'SIGTERM' );
		} catch {}
	}
}

export default [
	{
		id: 'preview.landing-page',
		title: "The Live Preview blueprint boots in Playground and lands on the plugin's status page without a PHP fatal",
		run: async ( ctx ) => {
			const result = await bootAndFetch( blueprintFor( ctx.assetsDir, ctx.zipPath ), ctx.zipPath );
			if ( result.error ) {
				return [ result.error ];
			}
			const problems = [];
			if ( result.status !== 200 ) {
				problems.push( `The landing page returned HTTP ${ result.status }.` );
			}
			if ( /Fatal error|critical error on this website/i.test( result.body + result.log ) ) {
				const at = ( result.body + result.log ).search( /Fatal error|critical error/i );
				problems.push( `PHP fatal: ${ ( result.body + result.log ).slice( at, at + 300 ).replace( /<[^>]+>/g, '' ) }` );
			}
			if ( ! result.body.includes( 'class="wrap cjenik-admin"' ) ) {
				problems.push( "The landing page doesn't show the plugin's status screen." );
			}
			return problems;
		},
	},
];

import { spawnSync } from 'node:child_process';
import { ROOT } from '../config.mjs';

/**
 * Runs `wp-env <args>` for the wp-env config in `cwd` (the dev site at the
 * repo root, or the clean release-check site in release/env).
 *
 * @return {{ ok: boolean, stdout: string, stderr: string }}
 */
export function wpEnv( args, { cwd = ROOT } = {} ) {
	const result = spawnSync( 'npx', [ 'wp-env', ...args ], { cwd, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 } );
	return { ok: result.status === 0, stdout: result.stdout ?? '', stderr: result.stderr ?? '' };
}

/** Runs WP-CLI in the `cli` container; throws with its output if it fails. */
export function wp( args, { cwd = ROOT, allowFail = false } = {} ) {
	const result = wpEnv( [ 'run', 'cli', 'wp', ...args ], { cwd } );
	if ( ! result.ok && ! allowFail ) {
		throw new Error( `wp ${ args.join( ' ' ) } failed:\n${ result.stdout }\n${ result.stderr }`.trim() );
	}
	return result;
}

/** Parses the JSON a PHP helper printed, ignoring any notices before it. */
export function lastJson( stdout ) {
	const start = stdout.search( /^[[{]/m );
	return JSON.parse( stdout.slice( start ) );
}

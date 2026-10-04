#!/usr/bin/env node
// The release check: fails on the first broken WordPress.org listing rule.
//
//   node release/check.mjs [zip] [--static] [--no-preview] [--no-fixtures] [--keep-going]
//
// Stages 1–5 read the zip and the listing files. Stage 6 installs the zip into
// a clean wp-env site (release/env, needs Docker); stage 7 boots the Live
// Preview blueprint in WordPress Playground. Each stage first runs on the real
// zip, then proves every one of its rules fails on a deliberately broken
// fixture (fixtures.mjs).
import fs from 'node:fs';
import path from 'node:path';
import { ASSETS_DIR, README_PO, ASSET_TEXT, LANGUAGES_DIR, ZIP_PATH, DIST_DIR, SLUG } from './config.mjs';
import { loadRelease, runRules } from './lib/release.mjs';
import { tempDir, unzip, zipFolder } from './lib/files.mjs';
import { solidPng } from './lib/png.mjs';
import { STATIC_FIXTURES, INSTALL_FIXTURES, PREVIEW_FIXTURES } from './fixtures.mjs';
import artifact from './rules/artifact.mjs';
import metadata from './rules/metadata.mjs';
import wording from './rules/wording.mjs';
import translation from './rules/translation.mjs';
import assets from './rules/assets.mjs';
import install, { activates, startSite } from './rules/install.mjs';
import preview from './rules/preview.mjs';

const args = process.argv.slice( 2 );
const flag = ( name ) => args.includes( `--${ name }` );
const zipPath = path.resolve( args.find( ( a ) => ! a.startsWith( '--' ) ) ?? ZIP_PATH );
const keepGoing = flag( 'keep-going' );
const withFixtures = ! flag( 'no-fixtures' );
const silent = () => {};
/** The listing files that live in the repo beside the zip. */
const LISTING = { assetsDir: ASSETS_DIR, readmePoPath: README_PO, assetTextPath: ASSET_TEXT, languagesDir: LANGUAGES_DIR };

/**
 * Static stages share one fixture pass over all their rules. A live stage may
 * start a site (`setup`) and have a `prerequisite` rule that its other rules'
 * fixtures run after.
 */
const STAGES = [
	{ name: '1. Artifact rules', rules: artifact },
	{ name: '2. Metadata rules', rules: metadata },
	{ name: '3. Wording rules', rules: wording },
	{ name: '4. Translation rules', rules: translation },
	{ name: '5. Asset rules', rules: assets },
	{ name: '6. Install rules (clean wp-env site)', rules: install, live: true, fixtures: INSTALL_FIXTURES, setup: startSite, prerequisite: activates, skip: flag( 'static' ) },
	{ name: '7. Preview rule (WordPress Playground)', rules: preview, live: true, fixtures: PREVIEW_FIXTURES, skip: flag( 'static' ) || flag( 'no-preview' ) },
];
const STATIC_RULES = STAGES.filter( ( s ) => ! s.live ).flatMap( ( s ) => s.rules );

if ( ! fs.existsSync( zipPath ) ) {
	console.error( `${ zipPath } doesn't exist. Run npm run build first.` );
	process.exit( 1 );
}

// Fixture workspaces start from the real zip and listing files, with generated
// placeholder images so they don't depend on the artwork being finished.
function baselineWorkspace() {
	const dir = tempDir( 'cjenik-fixture' );
	const unpacked = path.join( dir, 'zip' );
	unzip( zipPath, unpacked );
	const listing = { assetsDir: path.join( dir, 'assets' ), readmePoPath: path.join( dir, 'readme-hr.po' ), assetTextPath: path.join( dir, 'asset-text.json' ), languagesDir: path.join( dir, 'languages' ) };
	fs.mkdirSync( listing.assetsDir );
	const images = { 'icon-128x128.png': [ 128, 128 ], 'icon-256x256.png': [ 256, 256 ], 'banner-772x250.png': [ 772, 250 ], 'banner-1544x500.png': [ 1544, 500 ] };
	const screenshots = loadRelease( zipPath, LISTING ).readme.screenshots.length;
	for ( let n = 1; n <= screenshots; n++ ) {
		images[ `screenshot-${ n }.png` ] = [ 64, 40 ];
	}
	for ( const [ file, [ w, h ] ] of Object.entries( images ) ) {
		fs.writeFileSync( path.join( listing.assetsDir, file ), solidPng( w, h, [ 200, 30, 30 ] ) );
		if ( ! file.startsWith( 'icon' ) ) {
			fs.writeFileSync( path.join( listing.assetsDir, file.replace( '.png', '-hr.png' ) ), solidPng( w, h, [ 30, 30, 200 ] ) );
		}
	}
	fs.cpSync( path.join( ASSETS_DIR, 'blueprints' ), path.join( listing.assetsDir, 'blueprints' ), { recursive: true } );
	fs.copyFileSync( README_PO, listing.readmePoPath );
	fs.copyFileSync( ASSET_TEXT, listing.assetTextPath );
	fs.cpSync( LANGUAGES_DIR, listing.languagesDir, { recursive: true } );
	return { dir, unpacked, plugin: path.join( unpacked, SLUG ), ...listing };
}

function fixtureRelease( baseline, fixture, name ) {
	const dir = tempDir( 'cjenik-fixture' );
	fs.cpSync( baseline.dir, dir, { recursive: true } );
	const ws = Object.fromEntries( Object.entries( baseline ).map( ( [ k, v ] ) => [ k, v.replace( baseline.dir, dir ) ] ) );
	fixture.mutate( ws );
	const folder = path.join( ws.unpacked, ws.topFolder ?? SLUG );
	if ( ws.topFolder ) {
		fs.renameSync( ws.plugin, folder );
	}
	// Under dist/ so the release-check site and Playground can reach it.
	const zip = path.join( DIST_DIR, 'fixtures', `${ name }.zip` );
	zipFolder( folder, zip );
	return { ...loadRelease( zip, ws ), log: silent };
}

/**
 * Proves each fixture's rule fails on the fixture and passes on the unbroken
 * release. Static fixtures run every static rule; a live fixture runs its
 * stage's prerequisite and its own rule, and nothing else may fail.
 *
 * @return {Promise<number>} Problems: unproven fixtures and rules without one.
 */
async function proveFixtures( fixtures, rules, { baselineFailures, prerequisite } ) {
	const baseline = baselineWorkspace();
	const unproven = [];
	for ( const [ i, fixture ] of fixtures.entries() ) {
		const release = fixtureRelease( baseline, fixture, `${ i }-${ fixture.rule }` );
		const toRun = prerequisite ? [ ...new Set( [ prerequisite, ...rules.filter( ( r ) => r.id === fixture.rule ) ] ) ] : rules;
		const failed = ( await runRules( toRun, release, { keepGoing: true, log: silent } ) ).map( ( f ) => f.rule.id );
		const proven = failed.includes( fixture.rule ) && ! baselineFailures.has( fixture.rule ) && ( ! prerequisite || failed.length === 1 );
		console.log( `  ${ proven ? '✔' : '✖' } ${ fixture.rule } fails on ${ fixture.name }` );
		if ( ! proven ) {
			unproven.push( `${ fixture.rule } (${ fixture.name }): ${ baselineFailures.has( fixture.rule ) ? 'the unbroken release fails it too' : `the fixture failed ${ failed.join( ', ' ) || 'nothing' }` }` );
		}
	}
	const untested = rules.filter( ( r ) => ! fixtures.some( ( f ) => f.rule === r.id ) ).map( ( r ) => `${ r.id } has no broken fixture.` );
	[ ...unproven, ...untested ].forEach( ( p ) => console.log( `      ${ p }` ) );
	return unproven.length + untested.length;
}

const release = { ...loadRelease( zipPath, LISTING ), log: console.log };
let failures = 0;
console.log( `Release check for ${ path.relative( process.cwd(), zipPath ) }\n` );

if ( withFixtures ) {
	console.log( 'Broken fixtures, stages 1–5' );
	const baseline = { ...loadRelease( zipPath, baselineWorkspace() ), log: silent };
	const baselineFailures = new Set( ( await runRules( STATIC_RULES, baseline, { keepGoing: true, log: silent } ) ).map( ( f ) => f.rule.id ) );
	failures += await proveFixtures( STATIC_FIXTURES, STATIC_RULES, { baselineFailures } );
	console.log();
}

for ( const stage of STAGES ) {
	if ( failures && ! keepGoing ) {
		break;
	}
	if ( stage.skip ) {
		console.log( `${ stage.name }: skipped\n` );
		continue;
	}
	console.log( stage.name );
	stage.setup?.();
	const failed = await runRules( stage.rules, release, { keepGoing } );
	failures += failed.length;
	if ( withFixtures && stage.fixtures && ( ! failures || keepGoing ) ) {
		const baselineFailures = new Set( failed.map( ( f ) => f.rule.id ) );
		failures += await proveFixtures( stage.fixtures, stage.rules, { baselineFailures, prerequisite: stage.prerequisite } );
	}
	console.log();
}

fs.rmSync( path.join( DIST_DIR, 'fixtures' ), { recursive: true, force: true } );
console.log( failures ? `✖ Release check failed (${ failures } problem${ failures > 1 ? 's' : '' }).` : '✔ Release check passed: the zip is ready to upload.' );
process.exit( failures ? 1 : 0 );

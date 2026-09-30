import { SLUG } from '../config.mjs';

const MAX_ZIP_BYTES = 10 * 1024 * 1024;
// Rejected by the directory's uploader (class-upload-handler.php) or by review as development files.
const FORBIDDEN_EXTENSIONS = /\.(phar|sh|zip|gz|tgz|rar|tar|7z|log)$/i;
const DEV_PATHS = /^[^/]+\/(tests?|bin|vendor|node_modules)\/|\/(composer\.lock|package(-lock)?\.json|phpunit\.xml(\.dist)?|phpstan\.neon(\.dist)?|phpcs\.xml(\.dist)?)$/;

export default [
	{
		id: 'artifact.forbidden-files',
		title: 'No archives, scripts, VCS folders, hidden files or dev tooling in the zip',
		run: ( { entries } ) =>
			entries
				.filter( ( e ) => FORBIDDEN_EXTENSIONS.test( e ) || DEV_PATHS.test( e ) || e.split( '/' ).some( ( part ) => part.startsWith( '.' ) ) )
				.map( ( e ) => `${ e } must not be in the release zip (add it to .distignore).` ),
	},
	{
		id: 'artifact.size',
		title: 'The zip is under 10 MB',
		run: ( { zipBytes } ) => ( zipBytes < MAX_ZIP_BYTES ? [] : [ `The zip is ${ ( zipBytes / 1048576 ).toFixed( 1 ) } MB; the limit is 10 MB.` ] ),
	},
	{
		id: 'artifact.top-folder',
		title: `The zip has one top-level folder, ${ SLUG }/`,
		run: ( { entries } ) => {
			const tops = [ ...new Set( entries.map( ( e ) => e.split( '/' )[ 0 ] ) ) ];
			return tops.length === 1 && tops[ 0 ] === SLUG ? [] : [ `Top-level entries are ${ tops.join( ', ' ) }; expected only ${ SLUG }/.` ];
		},
	},
	{
		id: 'artifact.main-file',
		title: `The main plugin file is ${ SLUG }/${ SLUG }.php`,
		run: ( { mainPhp, header } ) =>
			mainPhp === null ? [ `${ SLUG }/${ SLUG }.php is missing.` ] : header.name ? [] : [ `${ SLUG }.php has no "Plugin Name:" header.` ],
	},
];

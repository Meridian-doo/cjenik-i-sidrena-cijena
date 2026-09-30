import crypto from 'node:crypto';

const HEADERS = {
	contributors: /^contributors$/i,
	donate_link: /^donate[ _-]?link$/i,
	tags: /^tags$/i,
	requires: /^requires( at least)?$/i,
	tested: /^tested( up to)?$/i,
	requires_php: /^requires php$/i,
	stable_tag: /^stable tag$/i,
	license: /^license$/i,
	license_uri: /^license uri$/i,
};

const SECTION_ALIASES = {
	'frequently asked questions': 'faq',
	'change log': 'changelog',
	screenshot: 'screenshots',
	'upgrade notice': 'upgrade_notice',
	'other notes': 'other_notes',
};

/**
 * Reads the parts of readme.txt the directory displays, following the
 * WordPress.org readme standard (https://wordpress.org/plugins/readme.txt).
 */
export function parseReadme( text ) {
	const lines = text.replace( /\r\n?/g, '\n' ).split( '\n' );
	const readme = { name: '', headers: {}, tags: [], short_description: '', sections: {}, screenshots: [], changelog_versions: [], upgrade_notice: {} };

	let i = 0;
	while ( i < lines.length && ! lines[ i ].trim() ) {
		i++;
	}
	const title = ( lines[ i ] ?? '' ).match( /^\s*===\s*(.+?)\s*===\s*$/ );
	if ( ! title ) {
		return { ...readme, error: 'The first line must be the plugin name as "=== Name ===".' };
	}
	readme.name = title[ 1 ];
	i++;

	for ( ; i < lines.length && lines[ i ].trim(); i++ ) {
		const m = lines[ i ].match( /^\s*([^:]+?)\s*:\s*(.*?)\s*$/ );
		const key = m && Object.keys( HEADERS ).find( ( k ) => HEADERS[ k ].test( m[ 1 ] ) );
		if ( key ) {
			readme.headers[ key ] = m[ 2 ];
		}
	}
	readme.tags = ( readme.headers.tags ?? '' ).split( ',' ).map( ( t ) => t.trim() ).filter( Boolean );

	const shortLines = [];
	for ( ; i < lines.length && ! /^\s*==[^=]/.test( lines[ i ] ); i++ ) {
		if ( lines[ i ].trim() ) {
			shortLines.push( lines[ i ].trim() );
		} else if ( shortLines.length ) {
			break;
		}
	}
	readme.short_description = shortLines.join( ' ' );

	let section = null;
	for ( ; i < lines.length; i++ ) {
		const heading = lines[ i ].match( /^\s*==\s*([^=].*?)\s*==\s*$/ );
		if ( heading ) {
			const title = heading[ 1 ].toLowerCase();
			section = SECTION_ALIASES[ title ] ?? title.replace( /\s+/g, '_' );
			readme.sections[ section ] = '';
			continue;
		}
		if ( section ) {
			readme.sections[ section ] += lines[ i ] + '\n';
		}
	}
	for ( const key of Object.keys( readme.sections ) ) {
		readme.sections[ key ] = readme.sections[ key ].trim();
	}

	readme.screenshots = ( readme.sections.screenshots ?? '' ).split( '\n' ).map( ( l ) => l.match( /^\s*\d+\.\s+(.+)$/ ) ).filter( Boolean ).map( ( m ) => m[ 1 ] );
	readme.changelog_versions = [ ...( readme.sections.changelog ?? '' ).matchAll( /^\s*=\s*v?([\d.]+)[^=]*=\s*$/gm ) ].map( ( m ) => m[ 1 ] );
	let version = null;
	for ( const line of ( readme.sections.upgrade_notice ?? '' ).split( '\n' ) ) {
		const h = line.match( /^\s*=\s*(.+?)\s*=\s*$/ );
		if ( h ) {
			version = h[ 1 ];
			readme.upgrade_notice[ version ] = '';
		} else if ( version ) {
			readme.upgrade_notice[ version ] = ( readme.upgrade_notice[ version ] + ' ' + line.trim() ).trim();
		}
	}
	return readme;
}

/** Plugin headers from the main PHP file's first comment block, as WordPress reads them. */
export function parsePluginHeader( php ) {
	const head = php.slice( 0, 8192 );
	const field = ( name ) => head.match( new RegExp( `^[ \\t/*#@]*${ name }:(.*)$`, 'mi' ) )?.[ 1 ].replace( /\s*(?:\*\/|\?>).*/, '' ).trim() ?? '';
	return {
		name: field( 'Plugin Name' ),
		version: field( 'Version' ),
		requires: field( 'Requires at least' ),
		tested: field( 'Tested up to' ),
		requires_php: field( 'Requires PHP' ),
		text_domain: field( 'Text Domain' ),
		domain_path: field( 'Domain Path' ),
		plugin_uri: field( 'Plugin URI' ),
	};
}

/**
 * Fingerprint of everything in the readme that becomes a translatable string:
 * the whole file except the header fields below the name. A version bump in
 * `Stable tag` or `Tested up to` keeps it; any text change doesn't.
 */
export function readmeSourceHash( text ) {
	const lines = text.replace( /\r\n?/g, '\n' ).trim().split( '\n' );
	const firstBlank = lines.findIndex( ( l ) => ! l.trim() );
	const kept = [ lines[ 0 ], ...lines.slice( firstBlank === -1 ? lines.length : firstBlank ) ];
	return crypto.createHash( 'sha256' ).update( kept.join( '\n' ) ).digest( 'hex' );
}

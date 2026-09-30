import fs from 'node:fs';

/**
 * Minimal gettext PO reader/writer: enough for translation coverage checks
 * and for keeping the Croatian readme PO in step with readme.txt.
 */
export function parsePo( text ) {
	const entries = [];
	let entry = null;
	let field = null;
	const ESCAPES = { n: '\n', t: '\t', r: '\r', '"': '"', '\\': '\\' };
	const unquote = ( s ) => s.slice( 1, -1 ).replace( /\\(.)/g, ( _, c ) => ESCAPES[ c ] ?? c );

	const flush = () => {
		if ( entry && entry.msgid !== undefined ) {
			entries.push( entry );
		}
		entry = null;
		field = null;
	};

	for ( const raw of text.replace( /\r\n?/g, '\n' ).split( '\n' ) ) {
		const line = raw.trim();
		if ( ! line ) {
			flush();
			continue;
		}
		entry ??= { comments: [], flags: [], msgstr: [] };
		if ( line.startsWith( '#,' ) ) {
			entry.flags.push( ...line.slice( 2 ).split( ',' ).map( ( f ) => f.trim() ) );
		} else if ( line.startsWith( '#' ) ) {
			entry.comments.push( raw );
		} else {
			const m = line.match( /^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+(".*")$/ );
			if ( m ) {
				field = m[ 2 ] !== undefined ? [ 'msgstr', Number( m[ 2 ] ) ] : m[ 1 ] === 'msgstr' ? [ 'msgstr', 0 ] : [ m[ 1 ] ];
				set( entry, field, unquote( m[ 3 ] ) );
			} else if ( line.startsWith( '"' ) && field ) {
				set( entry, field, get( entry, field ) + unquote( line ) );
			}
		}
	}
	flush();

	const header = entries[ 0 ]?.msgid === '' ? entries.shift() : null;
	const headers = Object.fromEntries(
		( header?.msgstr[ 0 ] ?? '' ).split( '\n' ).filter( Boolean ).map( ( l ) => [ l.slice( 0, l.indexOf( ':' ) ), l.slice( l.indexOf( ':' ) + 1 ).trim() ] )
	);
	return { headers, entries };
}

function set( entry, [ key, index ], value ) {
	if ( key === 'msgstr' ) {
		entry.msgstr[ index ] = value;
	} else {
		entry[ key ] = value;
	}
}

function get( entry, [ key, index ] ) {
	return key === 'msgstr' ? entry.msgstr[ index ] : entry[ key ];
}

export const readPo = ( file ) => parsePo( fs.readFileSync( file, 'utf8' ) );

export const poKey = ( e ) => ( e.msgctxt ? `${ e.msgctxt }\u0004` : '' ) + e.msgid;

export const isTranslated = ( e ) => ! e.flags.includes( 'fuzzy' ) && e.msgstr.length > 0 && e.msgstr.every( ( s ) => s !== '' && s !== undefined );

/** Keys of the entries that have a usable translation. */
export const translatedKeys = ( po ) => new Set( ( po?.entries ?? [] ).filter( isTranslated ).map( poKey ) );

function quote( s ) {
	const escaped = ( part ) => '"' + part.replace( /\\/g, '\\\\' ).replace( /"/g, '\\"' ).replace( /\t/g, '\\t' ).replace( /\n/g, '\\n' ) + '"';
	if ( ! s.includes( '\n' ) || s.indexOf( '\n' ) === s.length - 1 ) {
		return escaped( s );
	}
	return '""\n' + s.split( /(?<=\n)/ ).map( escaped ).join( '\n' );
}

export function serializePo( { headers, entries } ) {
	const out = [ 'msgid ""', 'msgstr ""', ...Object.entries( headers ).map( ( [ k, v ] ) => quote( `${ k }: ${ v }\n` ) ), '' ];
	for ( const e of entries ) {
		out.push( ...e.comments );
		if ( e.flags.length ) {
			out.push( '#, ' + e.flags.join( ', ' ) );
		}
		if ( e.msgctxt ) {
			out.push( 'msgctxt ' + quote( e.msgctxt ) );
		}
		out.push( 'msgid ' + quote( e.msgid ) );
		if ( e.msgid_plural !== undefined ) {
			out.push( 'msgid_plural ' + quote( e.msgid_plural ) );
			e.msgstr.forEach( ( s, i ) => out.push( `msgstr[${ i }] ` + quote( s ?? '' ) ) );
		} else {
			out.push( 'msgstr ' + quote( e.msgstr[ 0 ] ?? '' ) );
		}
		out.push( '' );
	}
	return out.join( '\n' );
}

/** Number of messages in a compiled .mo file, header included. */
export function moMessageCount( buffer ) {
	const magic = buffer.readUInt32LE( 0 );
	const read = magic === 0x950412de ? ( o ) => buffer.readUInt32LE( o ) : ( o ) => buffer.readUInt32BE( o );
	if ( magic !== 0x950412de && buffer.readUInt32BE( 0 ) !== 0x950412de ) {
		return null;
	}
	return read( 8 );
}

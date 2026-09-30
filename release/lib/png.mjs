import zlib from 'node:zlib';

const SIGNATURE = Buffer.from( [ 0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a ] );

/** Width and height of a PNG, or null if the bytes aren't a PNG. */
export function pngSize( buffer ) {
	if ( buffer.length < 24 || ! buffer.subarray( 0, 8 ).equals( SIGNATURE ) || buffer.toString( 'ascii', 12, 16 ) !== 'IHDR' ) {
		return null;
	}
	return { width: buffer.readUInt32BE( 16 ), height: buffer.readUInt32BE( 20 ) };
}

/** A solid-colour PNG, for test fixtures. */
export function solidPng( width, height, [ r, g, b ] ) {
	const row = Buffer.alloc( 1 + width * 3 );
	for ( let x = 0; x < width; x++ ) {
		row.set( [ r, g, b ], 1 + x * 3 );
	}
	const raw = Buffer.concat( Array.from( { length: height }, () => row ) );
	const chunk = ( type, data ) => {
		const body = Buffer.concat( [ Buffer.from( type, 'ascii' ), data ] );
		const out = Buffer.alloc( 12 + data.length );
		out.writeUInt32BE( data.length, 0 );
		body.copy( out, 4 );
		out.writeUInt32BE( zlib.crc32( body ), 8 + data.length );
		return out;
	};
	const ihdr = Buffer.alloc( 13 );
	ihdr.writeUInt32BE( width, 0 );
	ihdr.writeUInt32BE( height, 4 );
	ihdr.set( [ 8, 2, 0, 0, 0 ], 8 );
	return Buffer.concat( [ SIGNATURE, chunk( 'IHDR', ihdr ), chunk( 'IDAT', zlib.deflateSync( raw ) ), chunk( 'IEND', Buffer.alloc( 0 ) ) ] );
}

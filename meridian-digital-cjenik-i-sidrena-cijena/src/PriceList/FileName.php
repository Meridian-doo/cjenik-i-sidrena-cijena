<?php
/**
 * @package Cjenik
 */

namespace Cjenik\PriceList;

use Cjenik\Outlet;
use Cjenik\Zagreb;
use DateTimeImmutable;

/**
 * The legal five-part file name (NN 101/2026 t. VI):
 * {oblik}_{adresa}_{oznaka}_{broj pohrane}_{dd.mm.yyyy}_{HH-MM}.
 *
 * Built deliberately and never passed through sanitize_file_name(), which
 * would strip the colon, commas and Croatian letters. Only characters that
 * can't be in a file name at all are replaced. URL-encode it in links.
 */
final class FileName {
	/**
	 * @param string $time_separator "-" (default) or ":" as in the Ministry's example.
	 */
	public static function build( Outlet $outlet, int $storage_number, DateTimeImmutable $published_at, string $time_separator, string $extension ): string {
		$local = Zagreb::local( $published_at );
		$parts = array(
			self::clean( $outlet->type ),
			self::clean( $outlet->address ),
			self::clean( $outlet->code ),
			(string) $storage_number,
			$local->format( 'd.m.Y' ),
			$local->format( 'H' ) . ( ':' === $time_separator ? ':' : '-' ) . $local->format( 'i' ),
		);
		return implode( '_', $parts ) . '.' . $extension;
	}

	private static function clean( string $part ): string {
		$part = (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $part );
		$part = str_replace( array( '/', '\\' ), '-', $part );
		return trim( (string) preg_replace( '/\s+/u', ' ', $part ) );
	}
}

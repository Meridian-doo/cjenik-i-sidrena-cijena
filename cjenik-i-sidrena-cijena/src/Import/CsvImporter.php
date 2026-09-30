<?php
/**
 * @package Cjenik
 */

namespace Cjenik\Import;

use Cjenik\Anchors\Anchor;
use Cjenik\Anchors\AnchorRegistry;
use Cjenik\Prices\PriceHistory;
use Cjenik\Settings;
use Cjenik\Zagreb;
use DateTimeImmutable;
use WC_Product;

/**
 * Imports Anchor Prices and past prices from CSV, matching items by SKU or
 * barcode. Amounts include VAT and may use a decimal comma. Headers are
 * matched loosely ("šifra", "sifra", "SKU"), and a HOK template column like
 * "Cijena 10.9.2026." is read as the anchor on that date.
 */
final class CsvImporter {
	private const HOK_ANCHOR_HEADER = '/^cijena (?:na )?(\d{1,2}) (\d{1,2}) (\d{4})$/';

	public function __construct(
		private AnchorRegistry $anchors,
		private PriceHistory $history,
		private Settings $settings,
	) {}

	public function import_anchors( string $path ): ImportResult {
		$result            = new ImportResult();
		[ $header, $rows ] = $this->read( $path );

		$price_column = $this->column( $header, array( 'sidrena cijena', 'dodatna cijena' ) );
		$header_date  = null;
		foreach ( $header as $index => $name ) {
			if ( null === $price_column && preg_match( self::HOK_ANCHOR_HEADER, $name, $m ) ) {
				$price_column = $index;
				$header_date  = sprintf( '%04d-%02d-%02d', $m[3], $m[2], $m[1] );
			}
		}
		$date_column = $this->column( $header, array( 'sidreni datum', 'datum' ) );
		if ( null === $price_column ) {
			$result->error( 1, __( 'No anchor price column ("sidrena cijena") found.', 'cjenik-i-sidrena-cijena' ) );
			return $result;
		}

		foreach ( $rows as $line => $row ) {
			$item = $this->find_item( $header, $row );
			if ( ! $item ) {
				$result->error( $line, __( 'No product with this SKU or barcode.', 'cjenik-i-sidrena-cijena' ) );
				continue;
			}
			$price = self::parse_amount( $row[ $price_column ] ?? '' );
			$date  = null !== $date_column ? self::parse_date( $row[ $date_column ] ?? '' ) : null;
			$date  = $date ? Zagreb::date( $date ) : ( $header_date ?? AnchorRegistry::REFERENCE_DATE );
			if ( null === $price ) {
				$result->error( $line, __( 'The anchor price is missing or not a number.', 'cjenik-i-sidrena-cijena' ) );
				continue;
			}
			$this->anchors->set( $item->get_id(), $price, $date, Anchor::SOURCE_IMPORTED );
			++$result->imported;
		}
		return $result;
	}

	/**
	 * Imports past prices. A row without an end runs until the item's next
	 * imported price, or until the recorded history begins.
	 */
	public function import_history( string $path ): ImportResult {
		$result            = new ImportResult();
		[ $header, $rows ] = $this->read( $path );

		$price_column   = $this->column( $header, array( 'cijena', 'maloprodajna cijena' ) );
		$regular_column = $this->column( $header, array( 'redovna cijena' ) );
		$from_column    = $this->column( $header, array( 'od', 'vrijedi od', 'datum' ) );
		$to_column      = $this->column( $header, array( 'do', 'vrijedi do' ) );
		if ( null === $price_column || null === $from_column ) {
			$result->error( 1, __( 'The file needs a price ("cijena") and a start ("od") column.', 'cjenik-i-sidrena-cijena' ) );
			return $result;
		}

		$by_item = array();
		foreach ( $rows as $line => $row ) {
			$item  = $this->find_item( $header, $row );
			$price = self::parse_amount( $row[ $price_column ] ?? '' );
			$from  = self::parse_date( $row[ $from_column ] ?? '' );
			if ( ! $item ) {
				$result->error( $line, __( 'No product with this SKU or barcode.', 'cjenik-i-sidrena-cijena' ) );
				continue;
			}
			if ( null === $price || ! $from ) {
				$result->error( $line, __( 'The price or the start date is missing or invalid.', 'cjenik-i-sidrena-cijena' ) );
				continue;
			}
			$regular                      = null !== $regular_column ? self::parse_amount( $row[ $regular_column ] ?? '' ) : null;
			$to                           = null !== $to_column ? self::parse_date( $row[ $to_column ] ?? '' ) : null;
			$by_item[ $item->get_id() ][] = array( $line, $item, $price, $regular ?? $price, $from, $to );
		}

		foreach ( $by_item as $entries ) {
			usort( $entries, static fn( $a, $b ) => $a[4] <=> $b[4] );
			foreach ( $entries as $index => [ $line, $item, $price, $regular, $from, $to ] ) {
				$to = $to ?? ( isset( $entries[ $index + 1 ] ) ? $entries[ $index + 1 ][4] : null );
				if ( $this->history->import( $item, $price, $regular, $from, $to ) ) {
					++$result->imported;
				} else {
					$result->error( $line, __( 'This period is already covered by the recorded history.', 'cjenik-i-sidrena-cijena' ) );
				}
			}
		}
		return $result;
	}

	/**
	 * @return array{0: list<string>, 1: array<int, list<string>>} Normalised header, and rows by line number.
	 */
	private function read( string $path ): array {
		$contents = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = preg_replace( '/^\xEF\xBB\xBF/', '', $contents );
		if ( ! mb_check_encoding( (string) $contents, 'UTF-8' ) ) {
			$contents = mb_convert_encoding( (string) $contents, 'UTF-8', 'Windows-1250' );
		}
		$first_line = strtok( (string) $contents, "\r\n" );
		$delimiter  = self::delimiter( (string) $first_line );

		$handle = fopen( 'php://temp', 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory stream for fgetcsv().
		fwrite( $handle, (string) $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $handle );
		$header = array();
		$rows   = array();
		$line   = 0;
		while ( false !== ( $row = fgetcsv( $handle, null, $delimiter, '"', '' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			++$line;
			if ( array( null ) === $row ) {
				continue;
			}
			$row = array_map( static fn( $value ) => trim( (string) $value ), $row );
			if ( ! $header ) {
				$header = array_map( array( self::class, 'normalise_header' ), $row );
				continue;
			}
			$rows[ $line ] = $row;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return array( $header, $rows );
	}

	/**
	 * @param list<string> $header
	 * @param list<string> $row
	 */
	private function find_item( array $header, array $row ): ?WC_Product {
		$sku_column     = $this->column( $header, array( 'sifra', 'sku', 'sifra proizvoda' ) );
		$barcode_column = $this->column( $header, array( 'barkod', 'barcode', 'ean', 'gtin', 'barkod ako ima' ) );
		$sku            = null !== $sku_column ? ( $row[ $sku_column ] ?? '' ) : '';
		$barcode        = null !== $barcode_column ? ( $row[ $barcode_column ] ?? '' ) : '';

		$id = '' !== $sku ? wc_get_product_id_by_sku( $sku ) : 0;
		if ( ! $id && '' !== $barcode ) {
			$id = $this->id_by_barcode( $barcode );
		}
		$item = $id ? wc_get_product( $id ) : null;
		return $item ? $item : null;
	}

	private function id_by_barcode( string $barcode ): int {
		global $wpdb;
		$source = (string) $this->settings->get( 'barcode_source' );
		if ( ! str_starts_with( $source, 'meta:' ) ) {
			return (int) wc_get_product_id_by_global_unique_id( $barcode );
		}
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1", substr( $source, 5 ), $barcode )
		);
	}

	/**
	 * @param list<string> $header
	 * @param list<string> $names
	 */
	private function column( array $header, array $names ): ?int {
		foreach ( $names as $name ) {
			$index = array_search( $name, $header, true );
			if ( false !== $index ) {
				return (int) $index;
			}
		}
		return null;
	}

	private static function normalise_header( string $name ): string {
		$name = strtolower( remove_accents( $name ) );
		return trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', $name ) );
	}

	private static function delimiter( string $line ): string {
		$counts = array(
			';'  => substr_count( $line, ';' ),
			','  => substr_count( $line, ',' ),
			"\t" => substr_count( $line, "\t" ),
		);
		arsort( $counts );
		return (string) array_key_first( $counts );
	}

	public static function parse_amount( string $value ): ?float {
		$value = str_replace( array( ' ', "\u{00A0}", '€', 'EUR' ), '', $value );
		if ( str_contains( $value, ',' ) ) {
			$value = str_replace( array( '.', ',' ), array( '', '.' ), $value );
		}
		return is_numeric( $value ) && (float) $value >= 0 ? round( (float) $value, 2 ) : null;
	}

	/** Parses a Zagreb date or date and time, e.g. "10.9.2026.", "15.09.2026 18:00" or "2026-09-10". */
	public static function parse_date( string $value ): ?DateTimeImmutable {
		$value = trim( (string) preg_replace( '/\.(\s|$)/', '$1', trim( $value ) ) );
		foreach ( array( 'd.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d' ) as $format ) {
			$date   = DateTimeImmutable::createFromFormat( '!' . $format, $value, Zagreb::zone() );
			$errors = DateTimeImmutable::getLastErrors();
			if ( $date && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) ) {
				return $date->setTimezone( Zagreb::utc() );
			}
		}
		return null;
	}
}

<?php
/**
 * @package Cjenik
 */

namespace Cjenik\PriceList;

use RuntimeException;

/**
 * RFC 4180 CSV. Codes (barcodes, SKUs) are always quoted so readers keep them
 * as text, and text that a spreadsheet would run as a formula is neutralised.
 */
final class CsvRowWriter implements RowWriter {
	/** @var resource|null */
	private $handle = null;

	/** @var array<string, array{label: string, type: string}> */
	private array $columns = array();

	public function __construct(
		private string $delimiter = ';',
		private string $decimal = ',',
		private bool $bom = false,
		private string $eol = "\r\n",
	) {}

	public function extension(): string {
		return 'csv';
	}

	public function open( string $path, array $columns, array $about ): void {
		$handle = fopen( $path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			throw new RuntimeException( esc_html( sprintf( 'Cannot write %s', $path ) ) );
		}
		$this->handle  = $handle;
		$this->columns = $columns;
		if ( $this->bom ) {
			$this->put( "\xEF\xBB\xBF" );
		}
		$this->put_line( array_values( array_map( fn( $column ) => $this->field( $this->guard( $column['label'] ), false ), $columns ) ) );
	}

	public function write( array $row ): void {
		$fields = array();
		foreach ( $this->columns as $key => $column ) {
			$fields[] = $this->cell( $row[ $key ] ?? null, $column['type'] );
		}
		$this->put_line( $fields );
	}

	public function close(): void {
		if ( $this->handle ) {
			fflush( $this->handle );
			fclose( $this->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->handle = null;
		}
	}

	private function cell( string|float|null $value, string $type ): string {
		if ( null === $value || '' === $value ) {
			return '';
		}
		switch ( $type ) {
			case Columns::PRICE:
				return number_format( (float) $value, 2, $this->decimal, '' );
			case Columns::NUMBER:
				return str_replace( '.', $this->decimal, rtrim( rtrim( number_format( (float) $value, 3, '.', '' ), '0' ), '.' ) );
			case Columns::CODE:
				// Codes must match other systems, so only a leading "=" is neutralised.
				$code = (string) $value;
				return $this->field( str_starts_with( $code, '=' ) ? "'" . $code : $code, true );
		}
		return $this->field( $this->guard( (string) $value ), false );
	}

	/** Neutralises text a spreadsheet would treat as a formula. */
	private function guard( string $text ): string {
		return '' !== $text && str_contains( "=+-@\t\r", $text[0] ) ? "'" . $text : $text;
	}

	private function field( string $text, bool $force_quotes ): string {
		$needs_quotes = $force_quotes
			|| strpbrk( $text, $this->delimiter . "\"\r\n" ) !== false
			|| trim( $text ) !== $text;
		return $needs_quotes ? '"' . str_replace( '"', '""', $text ) . '"' : $text;
	}

	/**
	 * @param list<string> $fields
	 */
	private function put_line( array $fields ): void {
		$this->put( implode( $this->delimiter, $fields ) . $this->eol );
	}

	private function put( string $bytes ): void {
		if ( ! $this->handle || false === fwrite( $this->handle, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			throw new RuntimeException( 'Cannot write the Price List file.' );
		}
	}
}

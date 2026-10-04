<?php
/**
 * @package Cjenik
 */

namespace Cjenik\PriceList;

use RuntimeException;
use XMLWriter;

/**
 * XML with one <proizvod> element per row and one child element per column.
 * Amounts use a decimal point.
 */
final class XmlRowWriter implements RowWriter {
	private const FLUSH_EVERY = 500;

	private ?XMLWriter $xml = null;

	/** @var array<string, array{label: string, type: string}> */
	private array $columns = array();

	private int $pending = 0;

	public function extension(): string {
		return 'xml';
	}

	public function open( string $path, array $columns, array $about ): void {
		$xml = new XMLWriter();
		if ( ! $xml->openUri( $path ) ) {
			throw new RuntimeException( esc_html( sprintf( 'Cannot write %s', $path ) ) );
		}
		$xml->setIndent( true );
		$xml->setIndentString( "\t" );
		$xml->startDocument( '1.0', 'UTF-8' );
		$xml->startElement( 'cjenik' );
		foreach ( $about as $name => $value ) {
			$xml->writeAttribute( $name, $value );
		}
		$this->xml     = $xml;
		$this->columns = $columns;
	}

	public function write( array $row ): void {
		if ( ! $this->xml ) {
			throw new RuntimeException( 'The XML writer is not open.' );
		}
		$this->xml->startElement( 'proizvod' );
		foreach ( $this->columns as $key => $column ) {
			$this->xml->writeElement( $key, $this->cell( $row[ $key ] ?? null, $column['type'] ) );
		}
		$this->xml->endElement();
		if ( ++$this->pending >= self::FLUSH_EVERY ) {
			$this->xml->flush();
			$this->pending = 0;
		}
	}

	public function close(): void {
		if ( $this->xml ) {
			$this->xml->endElement();
			$this->xml->endDocument();
			$this->xml->flush();
			$this->xml = null;
		}
	}

	private function cell( string|float|null $value, string $type ): string {
		if ( null === $value || '' === $value ) {
			return '';
		}
		if ( Columns::PRICE === $type ) {
			return number_format( (float) $value, 2, '.', '' );
		}
		if ( Columns::NUMBER === $type ) {
			return rtrim( rtrim( number_format( (float) $value, 3, '.', '' ), '0' ), '.' );
		}
		return (string) $value;
	}
}

<?php
/**
 * @package Cjenik\Tests
 */

namespace Cjenik\Tests;

use Cjenik\Publishing\Publication;

/**
 * Tests at the publication seam: set up a shop, move the Clock, publish, then
 * read the public artifacts the way an inspector or aggregator would.
 */
abstract class PublicationTestCase extends ShopTestCase {
	/** Publishes the webshop Price List at a Zagreb wall-clock time. */
	protected function publish_at( string $zagreb_time ): Publication {
		$this->clock->set( $zagreb_time );
		return cjenik()->publisher()->publish( cjenik()->outlets()->webshop(), $this->clock->now() );
	}

	/**
	 * The published CSV as a list of rows, header first.
	 *
	 * @return list<list<string>>
	 */
	protected function csv( Publication $publication, string $delimiter = ';' ): array {
		$this->assertTrue( $publication->succeeded(), 'Publication failed: ' . $publication->error );
		$handle = fopen( $publication->path(), 'rb' );
		$rows   = array();
		$row    = fgetcsv( $handle, null, $delimiter, '"', '' );
		while ( false !== $row ) {
			$rows[] = $row;
			$row    = fgetcsv( $handle, null, $delimiter, '"', '' );
		}
		fclose( $handle );
		return $rows;
	}

	/**
	 * The published CSV rows keyed by column header, indexed by šifra (SKU).
	 *
	 * @return array<string, array<string, string>>
	 */
	protected function rows_by_code( Publication $publication ): array {
		$rows   = $this->csv( $publication );
		$header = array_shift( $rows );
		$keyed  = array();
		foreach ( $rows as $row ) {
			$assoc                    = array_combine( $header, $row );
			$keyed[ $assoc['šifra'] ] = $assoc;
		}
		return $keyed;
	}
}

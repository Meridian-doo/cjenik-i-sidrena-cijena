<?php
/**
 * @package Cjenik\Tests
 */

namespace Cjenik\Tests;

/**
 * A large catalogue publishes within a shared-hosting time and memory budget.
 *
 * @group slow
 */
final class LargeCatalogueTest extends PublicationTestCase {
	private const SIMPLE     = 10000;
	private const PARENTS    = 2000;
	private const VARIATIONS = 5;

	/** PHP's default max_execution_time for web requests, which cron runs may hit. */
	private const TIME_LIMIT_SECONDS = 30;
	private const MEMORY_LIMIT_BYTES = 64 * MB_IN_BYTES;

	public function test_tens_of_thousands_of_items_publish_within_time_and_memory_limits(): void {
		$this->insert_catalogue();
		wp_cache_flush();
		$memory_before = memory_get_usage();
		memory_reset_peak_usage();
		$started = microtime( true );

		$publication = $this->publish_at( '2026-10-01 05:00' );

		$seconds = microtime( true ) - $started;
		$memory  = memory_get_peak_usage() - $memory_before;
		fwrite( STDERR, sprintf( "\n%d rows in %.1f s, peak memory +%.1f MB\n", $publication->row_count, $seconds, $memory / MB_IN_BYTES ) );
		$this->assertTrue( $publication->succeeded(), $publication->error );
		$this->assertSame( self::SIMPLE + self::PARENTS * self::VARIATIONS, $publication->row_count );
		$this->assertLessThan( self::TIME_LIMIT_SECONDS, $seconds );
		$this->assertLessThan( self::MEMORY_LIMIT_BYTES, $memory );
	}

	/**
	 * Inserts products with SQL: saving tens of thousands through WooCommerce
	 * would take far longer than the publication under test.
	 */
	private function insert_catalogue(): void {
		global $wpdb;
		$simple_type   = (int) get_term_by( 'slug', 'simple', 'product_type' )->term_taxonomy_id;
		$variable_type = (int) get_term_by( 'slug', 'variable', 'product_type' )->term_taxonomy_id;
		$next_id       = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ) + 1;

		$posts = array();
		$meta  = array();
		$terms = array();
		$items = array();
		$add   = function ( int $id, string $type, int $parent_id, string $name, ?string $sku, ?string $price ) use ( &$posts, &$meta, &$items ) {
			$posts[] = $GLOBALS['wpdb']->prepare( '(%d, 1, %s, %s, %s, %s, %d, %s, %s)', $id, '2026-09-01 10:00:00', '2026-09-01 08:00:00', $name, 'publish', $parent_id, sanitize_title( $name ), $type );
			if ( null !== $price ) {
				foreach ( array(
					'_sku'              => $sku,
					'_regular_price'    => $price,
					'_price'            => $price,
					'_global_unique_id' => '385' . str_pad( (string) $id, 10, '0', STR_PAD_LEFT ),
					'_stock_status'     => 'instock',
					'_tax_status'       => 'taxable',
					'_tax_class'        => '',
				) as $key => $value ) {
					$meta[] = $GLOBALS['wpdb']->prepare( '(%d, %s, %s)', $id, $key, $value );
				}
				$items[] = array( $id, $parent_id, $price );
			}
		};

		for ( $i = 0; $i < self::SIMPLE; $i++ ) {
			$id = $next_id++;
			$add( $id, 'product', 0, "Artikl $i", "BULK-$i", number_format( 1 + ( $i % 997 ) / 10, 2, '.', '' ) );
			$terms[] = "($id, $simple_type)";
		}
		for ( $p = 0; $p < self::PARENTS; $p++ ) {
			$parent = $next_id++;
			$add( $parent, 'product', 0, "Majica $p", "VAR-$p", null );
			$terms[] = "($parent, $variable_type)";
			for ( $v = 0; $v < self::VARIATIONS; $v++ ) {
				$add( $next_id++, 'product_variation', $parent, "Majica $p - $v", "VAR-$p-$v", number_format( 10 + $v, 2, '.', '' ) );
			}
		}

		foreach ( array_chunk( $posts, 1000 ) as $chunk ) {
			$wpdb->query( "INSERT INTO {$wpdb->posts} (ID, post_author, post_date, post_date_gmt, post_title, post_status, post_parent, post_name, post_type) VALUES " . implode( ',', $chunk ) );
		}
		foreach ( array_chunk( $meta, 2000 ) as $chunk ) {
			$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $chunk ) );
		}
		foreach ( array_chunk( $terms, 2000 ) as $chunk ) {
			$wpdb->query( "INSERT INTO {$wpdb->term_relationships} (object_id, term_taxonomy_id) VALUES " . implode( ',', $chunk ) );
		}
		// The Price History, as the plugin would have recorded it since 1 Sep.
		$history = array_map(
			static fn( $item ) => $wpdb->prepare( '(%d, %d, %d, %f, %f, 0, %s, %s, %s)', $item[1] ? $item[1] : $item[0], $item[1] ? $item[0] : 0, $item[0], $item[2] * 1.25, $item[2] * 1.25, '', 'observed', '2026-09-01 08:00:00' ),
			$items
		);
		foreach ( array_chunk( $history, 2000 ) as $chunk ) {
			$wpdb->query( "INSERT INTO {$wpdb->prefix}cjenik_price_history (product_id, variation_id, item_id, price, regular_price, is_sale, sale_name, source, valid_from) VALUES " . implode( ',', $chunk ) );
		}
	}
}

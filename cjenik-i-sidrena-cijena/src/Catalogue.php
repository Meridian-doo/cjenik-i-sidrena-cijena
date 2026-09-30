<?php
/**
 * @package Cjenik
 */

namespace Cjenik;

use WC_Product;

/**
 * Which products and variations are offered to customers, and so belong in
 * the Price List and the Price History: published products that have their
 * own price, and enabled variations of published variable products.
 */
final class Catalogue {
	/** Product types that have no price of their own. */
	private const CONTAINER_TYPES = array( 'variable', 'grouped' );

	public static function is_listed( WC_Product $item ): bool {
		if ( 'publish' !== $item->get_status() || in_array( $item->get_type(), self::CONTAINER_TYPES, true ) ) {
			return false;
		}
		if ( $item->is_type( 'variation' ) ) {
			return 'publish' === get_post_status( $item->get_parent_id() );
		}
		return true;
	}

	/**
	 * Listed item IDs in ascending order, one page at a time, so large
	 * catalogues are never loaded at once.
	 *
	 * @return list<int>
	 */
	public static function item_ids_after( int $after_id, int $limit ): array {
		global $wpdb;
		$ids = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One %s per CONTAINER_TYPES entry in the IN () below.
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->posts} parent ON parent.ID = p.post_parent
				WHERE p.ID > %d AND p.post_status = 'publish'
				AND (
					( p.post_type = 'product' AND p.ID NOT IN (
						SELECT tr.object_id FROM {$wpdb->term_relationships} tr
						JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
						JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
						WHERE tt.taxonomy = 'product_type' AND t.slug IN ( %s, %s )
					) )
					OR ( p.post_type = 'product_variation' AND parent.post_status = 'publish' )
				)
				ORDER BY p.ID ASC LIMIT %d",
				array_merge( array( $after_id ), self::CONTAINER_TYPES, array( $limit ) )
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Every listed item, loaded in batches with memory freed between batches.
	 *
	 * @param (callable(list<int>): void)|null $before_batch Called with each batch's IDs before
	 *                                                       its items load, to prefetch data.
	 * @return \Generator<WC_Product>
	 */
	public static function items( int $batch_size = 500, ?callable $before_batch = null ): \Generator {
		global $wpdb;
		$after = 0;
		while ( $ids = self::item_ids_after( $after, $batch_size ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			$in      = implode( ',', $ids );
			$parents = array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE ID IN ($in) AND post_parent > 0" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integers.
			_prime_post_caches( array_merge( $ids, $parents ), true, true );
			if ( $before_batch ) {
				$before_batch( $ids );
			}
			foreach ( $ids as $id ) {
				$item = wc_get_product( $id );
				if ( $item ) {
					yield $item;
				}
			}
			$after = end( $ids );
			self::free_memory();
		}
	}

	private static function free_memory(): void {
		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
	}
}

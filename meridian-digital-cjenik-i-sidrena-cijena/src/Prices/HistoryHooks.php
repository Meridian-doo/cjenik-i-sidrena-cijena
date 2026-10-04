<?php
/**
 * @package Cjenik
 */

namespace Cjenik\Prices;

use WC_Product;

/**
 * Feeds the Price History from every way a price can change: product saves
 * from the admin, bulk edit, REST, WP-CLI and imports (all go through the
 * WooCommerce CRUD layer), scheduled-sale transitions, and status changes.
 */
final class HistoryHooks {
	/** Nesting depth of WooCommerce product saves in progress. */
	private static int $saving = 0;

	public static function register(): void {
		// Records the state before an edit first, so a sale that ended unnoticed
		// is recorded with the old prices at the time it ended.
		add_action( 'woocommerce_before_product_object_save', array( self::class, 'before_save' ) );
		add_action( 'woocommerce_after_product_object_save', array( self::class, 'after_save' ) );
		add_action( 'wc_after_products_starting_sales', array( self::class, 'observe_ids' ) );
		add_action( 'wc_after_products_ending_sales', array( self::class, 'observe_ids' ) );
		add_action( 'transition_post_status', array( self::class, 'status_changed' ), 10, 3 );
		add_action( 'before_delete_post', array( self::class, 'deleted' ) );
	}

	public static function before_save( WC_Product $product ): void {
		++self::$saving;
		if ( ! $product->get_id() ) {
			return;
		}
		$stored = wc_get_product( $product->get_id() );
		if ( $stored ) {
			// If there is no history yet, the item was already listed before the
			// plugin saw it, like items found by the snapshot.
			cjenik()->history()->observe( $stored, null, PriceHistory::SNAPSHOT );
		}
	}

	public static function after_save( WC_Product $product ): void {
		self::$saving = max( 0, self::$saving - 1 );
		self::observe( $product );
	}

	public static function observe( WC_Product $product ): void {
		cjenik()->history()->observe( $product );
		if ( $product->is_type( 'variable' ) ) {
			// Publishing or unpublishing the parent lists or delists its variations.
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child ) {
					cjenik()->history()->observe( $child );
				}
			}
		}
	}

	/**
	 * @param array<int|string> $ids
	 */
	public static function observe_ids( $ids ): void {
		foreach ( (array) $ids as $id ) {
			$product = wc_get_product( (int) $id );
			if ( $product ) {
				self::observe( $product );
			}
		}
	}

	public static function status_changed( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( $new_status === $old_status || ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			return;
		}
		// Saves through WooCommerce are handled after the save, when all the
		// product data is written; this catches trashing and direct updates.
		if ( self::$saving > 0 ) {
			return;
		}
		$product = wc_get_product( $post->ID );
		if ( $product ) {
			self::observe( $product );
		}
	}

	public static function deleted( int $post_id ): void {
		if ( in_array( get_post_type( $post_id ), array( 'product', 'product_variation' ), true ) ) {
			cjenik()->history()->end( $post_id );
		}
	}
}

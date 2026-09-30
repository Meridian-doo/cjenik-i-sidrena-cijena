<?php
/**
 * @package Cjenik
 */

namespace Cjenik\Anchors;

use Cjenik\Catalogue;
use Cjenik\Clock;
use Cjenik\PriceList\FieldMapping;
use Cjenik\Prices\PriceHistory;
use Cjenik\Prices\PriceResolver;
use Cjenik\Settings;
use Cjenik\Zagreb;
use WC_Product;

/**
 * The Anchor Price and Anchor Date of every item, and where each came from.
 *
 * An anchor the shop owner entered or imported is stored in the item's meta
 * and always wins. Otherwise it is derived from the Price History:
 *  - the regular price on 10 Sep 2026 (never a Special Sale price);
 *  - the regular price on 2 May 2025 for items in the FMCG categories that
 *    keep their 2025 anchor;
 *  - for items first listed after the reference date, the first listed
 *    regular price and that date.
 *
 * Because the anchor lives on the item, it survives SKU and name changes.
 * A new barcode makes it a new product, which gets a new anchor.
 */
final class AnchorRegistry {
	public const REFERENCE_DATE      = '2026-09-10';
	public const FMCG_REFERENCE_DATE = '2025-05-02';

	public const META_PRICE  = '_cjenik_anchor_price';
	public const META_DATE   = '_cjenik_anchor_date';
	public const META_SOURCE = '_cjenik_anchor_source';

	public function __construct(
		private PriceHistory $history,
		private PriceResolver $resolver,
		private FieldMapping $fields,
		private Settings $settings,
		private Clock $clock,
	) {}

	public function for( WC_Product $item ): Anchor {
		$stored = $this->stored( $item->get_id() );
		if ( $stored ) {
			return $stored;
		}

		// The last price recorded on the reference date: still in effect that day.
		$reference = $this->reference_date( $item );
		$on_date   = $this->history->last_until( $item->get_id(), Zagreb::end_of_day( $reference ) );
		if ( $on_date && ( null === $on_date->valid_to || $on_date->valid_to > Zagreb::start_of_day( $reference ) ) ) {
			return new Anchor( $on_date->regular_price, $reference, Anchor::SOURCE_AUTO, true );
		}

		// Otherwise the first price after the reference date.
		$after = $this->history->first_after( $item->get_id(), Zagreb::end_of_day( $reference ) );
		if ( ! $after ) {
			return $on_date
				? new Anchor( $on_date->regular_price, Zagreb::date( $on_date->valid_from ), Anchor::SOURCE_AUTO, false )
				: new Anchor( null, null, Anchor::SOURCE_AUTO, false );
		}
		// That is the first listed price only if nothing was recorded before it
		// and the plugin saw the item being listed, rather than finding it listed.
		$after_date = Zagreb::date( $after->valid_from );
		$first      = $this->history->first( $item->get_id() );
		$confirmed  = $first && $first->valid_from == $after->valid_from // phpcs:ignore Universal.Operators.StrictComparisons
			&& ( PriceHistory::SNAPSHOT !== $after->source
				|| ( $item->get_date_created() && Zagreb::date( $item->get_date_created() ) >= $after_date ) );
		return new Anchor( $after->regular_price, $after_date, Anchor::SOURCE_AUTO, $confirmed );
	}

	/**
	 * Fetches the history the anchors of many items need in one go.
	 *
	 * @param list<int> $item_ids
	 */
	public function prefetch( array $item_ids ): void {
		// Items in the FMCG categories look up their 2025 date one by one.
		$this->history->prefetch_last_until( $item_ids, Zagreb::end_of_day( self::REFERENCE_DATE ) );
	}

	/**
	 * The date whose price is the anchor, unless the item is first listed later.
	 */
	public function reference_date( WC_Product $item ): string {
		$fmcg = array_map( 'intval', (array) $this->settings->get( 'fmcg_2025_categories' ) );
		if ( $fmcg && array_intersect( $fmcg, $this->fields->category_ids_with_ancestors( $item ) ) ) {
			return self::FMCG_REFERENCE_DATE;
		}
		return self::REFERENCE_DATE;
	}

	/**
	 * Stores an anchor entered by the shop owner or imported.
	 *
	 * @param string $date Y-m-d.
	 */
	public function set( int $item_id, float $price, string $date, string $source ): void {
		update_post_meta( $item_id, self::META_PRICE, wc_format_decimal( $price, 2 ) );
		update_post_meta( $item_id, self::META_DATE, $date );
		update_post_meta( $item_id, self::META_SOURCE, $source );
	}

	/** Removes a stored anchor, so the anchor is derived again. */
	public function clear( int $item_id ): void {
		delete_post_meta( $item_id, self::META_PRICE );
		delete_post_meta( $item_id, self::META_DATE );
		delete_post_meta( $item_id, self::META_SOURCE );
	}

	/**
	 * A product whose barcode changes is a new product: it gets its current
	 * regular price and today's date as its anchor. An anchor the shop owner
	 * entered or imported stays.
	 */
	public function on_before_save( WC_Product $item ): void {
		if ( ! $item->get_id() || ! Catalogue::is_listed( $item ) ) {
			return;
		}
		$stored = $this->stored( $item->get_id() );
		if ( $stored && Anchor::SOURCE_AUTO !== $stored->source ) {
			return;
		}
		$old = $this->fields->stored_barcode( $item->get_id() );
		$new = $this->fields->barcode( $item );
		if ( '' === $old || '' === $new || $old === $new ) {
			return;
		}
		$now   = $this->clock->now();
		$state = $this->resolver->resolve( $item, $now );
		if ( null !== $state->regular_price ) {
			$this->set( $item->get_id(), $state->regular_price, Zagreb::date( $now ), Anchor::SOURCE_AUTO );
		}
	}

	/**
	 * Listed items whose anchor isn't confirmed: none at all, or only the
	 * earliest recorded price because the history starts too late.
	 *
	 * @return array{count: int, items: list<array{item: WC_Product, anchor: Anchor}>} Up to $limit items.
	 */
	public function unconfirmed( int $limit = 200 ): array {
		$count = 0;
		$items = array();
		foreach ( Catalogue::items() as $item ) {
			$anchor = $this->for( $item );
			if ( $anchor->has_price() && $anchor->confirmed ) {
				continue;
			}
			++$count;
			if ( count( $items ) < $limit ) {
				$items[] = array(
					'item'   => $item,
					'anchor' => $anchor,
				);
			}
		}
		return array(
			'count' => $count,
			'items' => $items,
		);
	}

	private function stored( int $item_id ): ?Anchor {
		$price = get_post_meta( $item_id, self::META_PRICE, true );
		$date  = (string) get_post_meta( $item_id, self::META_DATE, true );
		if ( '' === $price || ! is_numeric( $price ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return null;
		}
		$source = (string) get_post_meta( $item_id, self::META_SOURCE, true );
		return new Anchor( (float) $price, $date, '' !== $source ? $source : Anchor::SOURCE_MANUAL, true );
	}
}

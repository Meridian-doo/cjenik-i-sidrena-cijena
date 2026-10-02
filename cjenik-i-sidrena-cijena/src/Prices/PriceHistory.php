<?php
/**
 * @package Cjenik
 */

namespace Cjenik\Prices;

use Cjenik\Catalogue;
use Cjenik\Clock;
use Cjenik\Zagreb;
use DateTimeImmutable;
use WC_Product;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Price History is the plugin's own table, read through $wpdb->prepare().

/**
 * Records every price an item was offered at, and answers "what was the price
 * then" and "what was the lowest price in the 30 days before".
 */
final class PriceHistory {
	public const LOWEST_PRICE_DAYS = 30;

	/** Seen changing: a save, a sale transition, a status change. */
	public const OBSERVED = 'observed';
	/** Found by the daily snapshot (or at installation), not seen changing. */
	public const SNAPSHOT = 'snapshot';
	/** From a CSV import. */
	public const IMPORTED = 'imported';

	/**
	 * Prefetched last_until() answers, by moment and item. Cleared on every write.
	 *
	 * @var array<int, array<int, Observation|null>>
	 */
	private array $prefetched = array();

	public function __construct( private PriceResolver $resolver, private Clock $clock ) {}

	/**
	 * Fetches last_until() for many items in one query, e.g. a Price List batch.
	 *
	 * @param list<int> $item_ids
	 */
	public function prefetch_last_until( array $item_ids, DateTimeImmutable $moment ): void {
		global $wpdb;
		if ( ! $item_ids ) {
			return;
		}
		$at = Zagreb::to_db( $moment );
		$in = implode( ',', array_map( 'intval', $item_ids ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of integers.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT h.* FROM {$wpdb->prefix}cjenik_price_history h
				JOIN (
					SELECT item_id, MAX(valid_from) AS valid_from FROM {$wpdb->prefix}cjenik_price_history
					WHERE item_id IN ($in) AND valid_from <= %s GROUP BY item_id
				) latest ON latest.item_id = h.item_id AND latest.valid_from = h.valid_from",
				$at
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = array_fill_keys( array_map( 'intval', $item_ids ), null );
		foreach ( $rows as $row ) {
			$found[ (int) $row->item_id ] = Observation::from_row( $row );
		}
		$this->prefetched = array( $moment->getTimestamp() => $found );
	}

	/**
	 * Records every listed item's current state. Fills gaps left by changes
	 * that bypassed the hooks, and starts the history on installation.
	 */
	public function snapshot( ?DateTimeImmutable $moment = null ): int {
		$moment = $moment ?? $this->clock->now();
		$count  = 0;
		foreach ( Catalogue::items() as $item ) {
			$this->observe( $item, $moment, self::SNAPSHOT );
			++$count;
		}
		return $count;
	}

	/**
	 * Records the item's current state. When a scheduled sale started or ended
	 * since the last observation, the change is recorded at the moment it took
	 * effect, not when this runs.
	 */
	public function observe( WC_Product $item, ?DateTimeImmutable $moment = null, string $source = self::OBSERVED ): void {
		$moment = $moment ?? $this->clock->now();
		$open   = $this->open( $item->get_id() );

		if ( ! Catalogue::is_listed( $item ) ) {
			if ( $open ) {
				$this->close( $open, $moment );
			}
			return;
		}

		$points = array();
		if ( $open ) {
			foreach ( $this->resolver->sale_transitions( $item ) as $transition ) {
				if ( $transition > $open->valid_from && $transition < $moment ) {
					$points[] = $transition;
				}
			}
			sort( $points );
		}
		$points[] = $moment;

		foreach ( $points as $point ) {
			if ( $open && $point < $open->valid_from ) {
				continue;
			}
			$state = $this->resolver->resolve( $item, $point );
			if ( $open && $state->has_price() && $open->matches( $state ) ) {
				continue;
			}
			if ( $open ) {
				$this->close( $open, $point );
				$open = null;
			}
			if ( $state->has_price() ) {
				$open = $this->insert( $item, $state, $point, null, $source );
			}
		}
	}

	/** Stops the item's current observation, e.g. when it is unpublished. */
	public function end( int $item_id, ?DateTimeImmutable $moment = null ): void {
		$open = $this->open( $item_id );
		if ( $open ) {
			$this->close( $open, $moment ?? $this->clock->now() );
		}
	}

	/**
	 * The last observation that started by a moment, even if it has ended
	 * since, e.g. the price last recorded on a reference date.
	 */
	public function last_until( int $item_id, DateTimeImmutable $moment ): ?Observation {
		global $wpdb;
		$prefetched = $this->prefetched[ $moment->getTimestamp() ] ?? array();
		if ( array_key_exists( $item_id, $prefetched ) ) {
			return $prefetched[ $item_id ];
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cjenik_price_history
				WHERE item_id = %d AND valid_from <= %s ORDER BY valid_from DESC LIMIT 1",
				$item_id,
				Zagreb::to_db( $moment )
			)
		);
		return $row ? Observation::from_row( $row ) : null;
	}

	/** The item's first observation starting at or after a moment. */
	public function first_after( int $item_id, DateTimeImmutable $moment ): ?Observation {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cjenik_price_history WHERE item_id = %d AND valid_from >= %s ORDER BY valid_from ASC LIMIT 1",
				$item_id,
				Zagreb::to_db( $moment )
			)
		);
		return $row ? Observation::from_row( $row ) : null;
	}

	/** The item's earliest observation. */
	public function first( int $item_id ): ?Observation {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cjenik_price_history WHERE item_id = %d ORDER BY valid_from ASC LIMIT 1",
				$item_id
			)
		);
		return $row ? Observation::from_row( $row ) : null;
	}

	/**
	 * The lowest price applied at any time in the 30 days before a moment,
	 * including prices that were live for only part of a day.
	 */
	public function lowest_before( int $item_id, DateTimeImmutable $moment ): ?float {
		global $wpdb;
		$lowest = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(price) FROM {$wpdb->prefix}cjenik_price_history
				WHERE item_id = %d AND valid_from < %s AND ( valid_to IS NULL OR valid_to > %s )",
				$item_id,
				Zagreb::to_db( $moment ),
				Zagreb::to_db( Zagreb::local( $moment )->modify( '-' . self::LOWEST_PRICE_DAYS . ' days' ) )
			)
		);
		return null === $lowest ? null : (float) $lowest;
	}

	/**
	 * The Lowest 30-day Price shown during a Special Sale: the lowest price
	 * applied in the 30 days before the sale began. Null when not on sale.
	 * Without any recorded price before the sale, the regular price is used.
	 */
	public function lowest_30_day_price( WC_Product $item, ResolvedPrice $current, DateTimeImmutable $moment ): ?float {
		if ( ! $current->is_sale ) {
			return null;
		}
		$start = $this->sale_started_at( $item->get_id(), $moment );
		if ( null === $start ) {
			// The sale began after the last observation, e.g. a scheduled sale
			// whose start WooCommerce hasn't processed yet.
			$from  = $item->get_date_on_sale_from( 'edit' );
			$start = $from && $from->getTimestamp() <= $moment->getTimestamp()
				? new DateTimeImmutable( '@' . $from->getTimestamp() )
				: $moment;
		}
		return $this->lowest_before( $item->get_id(), $start ) ?? $current->regular_price;
	}

	/**
	 * When the Special Sale in effect at a moment began: the start of the
	 * unbroken run of sale observations that contains the moment.
	 */
	public function sale_started_at( int $item_id, DateTimeImmutable $moment ): ?DateTimeImmutable {
		global $wpdb;
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cjenik_price_history
				WHERE item_id = %d AND valid_from <= %s ORDER BY valid_from DESC LIMIT 100",
				$item_id,
				Zagreb::to_db( $moment )
			)
		);
		$observations = array_map( array( Observation::class, 'from_row' ), $rows );
		$current      = array_shift( $observations );
		if ( ! $current || ! $current->is_sale || ( $current->valid_to && $current->valid_to <= $moment ) ) {
			return null;
		}
		$start = $current->valid_from;
		foreach ( $observations as $earlier ) {
			if ( ! $earlier->is_sale || $earlier->valid_to?->getTimestamp() !== $start->getTimestamp() ) {
				break;
			}
			$start = $earlier->valid_from;
		}
		return $start;
	}

	/**
	 * Adds a past price from an import. It never overwrites observed history:
	 * the range is cut off where recorded history begins.
	 */
	public function import( WC_Product $item, float $price, float $regular_price, DateTimeImmutable $from, ?DateTimeImmutable $to ): bool {
		global $wpdb;
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cjenik_price_history WHERE item_id = %d AND source <> %s ORDER BY valid_from ASC LIMIT 1",
				$item->get_id(),
				self::IMPORTED
			)
		);
		$first = $row ? Observation::from_row( $row ) : null;
		if ( $first && ( null === $to || $to > $first->valid_from ) ) {
			$to = $first->valid_from;
		}
		if ( null !== $to && $to <= $from ) {
			return false;
		}
		// Importing the same period again replaces it.
		$wpdb->delete(
			$wpdb->prefix . 'cjenik_price_history',
			array(
				'item_id'    => $item->get_id(),
				'source'     => self::IMPORTED,
				'valid_from' => Zagreb::to_db( $from ),
			)
		);
		$state = new ResolvedPrice( round( $price, 2 ), round( $regular_price, 2 ), $price < $regular_price, '' );
		$this->insert( $item, $state, $from, $to, self::IMPORTED );
		return true;
	}

	/** Deletes the item's whole history. */
	public function forget( int $item_id ): void {
		global $wpdb;
		$this->prefetched = array();
		$wpdb->delete( $wpdb->prefix . 'cjenik_price_history', array( 'item_id' => $item_id ), array( '%d' ) );
	}

	private function open( int $item_id ): ?Observation {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cjenik_price_history WHERE item_id = %d AND valid_to IS NULL ORDER BY valid_from DESC LIMIT 1",
				$item_id
			)
		);
		return $row ? Observation::from_row( $row ) : null;
	}

	private function close( Observation $open, DateTimeImmutable $moment ): void {
		global $wpdb;
		$this->prefetched = array();
		$wpdb->update(
			$wpdb->prefix . 'cjenik_price_history',
			array( 'valid_to' => Zagreb::to_db( max( $moment, $open->valid_from ) ) ),
			array(
				'item_id'    => $open->item_id,
				'valid_from' => Zagreb::to_db( $open->valid_from ),
				'valid_to'   => null,
			)
		);
	}

	private function insert( WC_Product $item, ResolvedPrice $state, DateTimeImmutable $from, ?DateTimeImmutable $to, string $source ): Observation {
		global $wpdb;
		$this->prefetched = array();
		$is_variation     = $item->is_type( 'variation' );
		$wpdb->insert(
			$wpdb->prefix . 'cjenik_price_history',
			array(
				'product_id'    => $is_variation ? $item->get_parent_id() : $item->get_id(),
				'variation_id'  => $is_variation ? $item->get_id() : 0,
				'item_id'       => $item->get_id(),
				'price'         => $state->price,
				'regular_price' => $state->regular_price,
				'is_sale'       => $state->is_sale ? 1 : 0,
				'sale_name'     => $state->sale_name,
				'source'        => $source,
				'valid_from'    => Zagreb::to_db( $from ),
				'valid_to'      => null === $to ? null : Zagreb::to_db( $to ),
			),
			array( '%d', '%d', '%d', '%f', '%f', '%d', '%s', '%s', '%s', '%s' )
		);
		return new Observation( $item->get_id(), (float) $state->price, (float) $state->regular_price, $state->is_sale, $state->sale_name, $source, $from, $to );
	}
}

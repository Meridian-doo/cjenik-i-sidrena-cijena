<?php
/**
 * @package Cjenik
 */

namespace Cjenik\PriceList;

use Cjenik\Anchors\AnchorRegistry;
use Cjenik\Catalogue;
use Cjenik\Outlet;
use Cjenik\Prices\PriceHistory;
use Cjenik\Prices\PriceResolver;
use Cjenik\Settings;
use Cjenik\Zagreb;
use DateTimeImmutable;
use WC_Product;

/**
 * Turns an Outlet and a moment into Price List rows: one per listed product
 * or variation, with prices as they are at that moment.
 */
final class PriceListBuilder {
	public function __construct(
		private Settings $settings,
		private PriceResolver $resolver,
		private PriceHistory $history,
		private AnchorRegistry $anchors,
		private FieldMapping $fields,
	) {}

	/**
	 * The Outlet's columns in file order, with the configured headers.
	 *
	 * @return array<string, array{label: string, type: string}>
	 */
	public function columns( Outlet $outlet ): array {
		$definitions = Columns::definitions();
		$columns     = array();
		foreach ( Columns::sanitize_selection( (array) $this->settings->get( 'columns' ) ) as $column ) {
			$columns[ $column['key'] ] = array(
				'label' => $column['label'],
				'type'  => $definitions[ $column['key'] ]['type'],
			);
		}
		/**
		 * Changes the columns of one Outlet's Price List.
		 *
		 * @param array<string, array{label: string, type: string}> $columns
		 * @param Outlet                                            $outlet
		 */
		return (array) apply_filters( 'cjenik_outlet_columns', $columns, $outlet );
	}

	/**
	 * The Price List rows, streamed.
	 *
	 * @return iterable<array<string, string|float|null>>
	 */
	public function rows( Outlet $outlet, DateTimeImmutable $moment, BuildReport $report ): iterable {
		$sources = array( fn() => $this->product_rows( $outlet, $moment, $report ) );
		/**
		 * The row sources of an Outlet's Price List, e.g. a service list.
		 * Each source is a callable( Outlet, DateTimeImmutable, BuildReport ): iterable.
		 *
		 * @param list<callable> $sources
		 * @param Outlet         $outlet
		 */
		$sources = (array) apply_filters( 'cjenik_row_sources', $sources, $outlet );
		foreach ( $sources as $source ) {
			foreach ( $source( $outlet, $moment, $report ) as $row ) {
				++$report->rows;
				yield $row;
			}
		}
	}

	/**
	 * @return \Generator<array<string, string|float|null>>
	 */
	private function product_rows( Outlet $outlet, DateTimeImmutable $moment, BuildReport $report ): \Generator {
		foreach ( Catalogue::items( 500, fn( array $ids ) => $this->anchors->prefetch( $ids ) ) as $item ) {
			$row = $this->product_row( $item, $outlet, $moment, $report );
			if ( null !== $row ) {
				yield $row;
			}
		}
	}

	/**
	 * @return array<string, string|float|null>|null
	 */
	private function product_row( WC_Product $item, Outlet $outlet, DateTimeImmutable $moment, BuildReport $report ): ?array {
		$price = $this->resolver->resolve( $item, $moment );
		if ( ! $price->has_price() ) {
			$report->warn( BuildReport::NO_PRICE, $item->get_id() );
			return null;
		}

		$anchor = $this->anchors->for( $item );
		if ( ! $anchor->has_price() && null === $this->history->first( $item->get_id() ) ) {
			// Not in the Price History yet, e.g. right after installation.
			$this->history->observe( $item, $moment, PriceHistory::SNAPSHOT );
			$anchor = $this->anchors->for( $item );
		}
		if ( ! $anchor->has_price() ) {
			$report->warn( BuildReport::NO_ANCHOR, $item->get_id() );
			$anchor_price = $price->regular_price;
			$anchor_date  = Zagreb::date( $moment );
		} else {
			if ( ! $anchor->confirmed ) {
				$report->warn( BuildReport::UNCONFIRMED_ANCHOR, $item->get_id() );
			}
			$anchor_price = $anchor->price;
			$anchor_date  = (string) $anchor->date;
		}

		$sku = $item->get_sku( 'edit' );
		if ( '' === $sku ) {
			$report->warn( BuildReport::NO_SKU, $item->get_id() );
			$sku = (string) $item->get_id();
		}
		$barcode = $this->fields->barcode( $item );
		if ( '' === $barcode ) {
			$report->warn( BuildReport::NO_BARCODE, $item->get_id() );
		}

		$row = array(
			'naziv'                         => html_entity_decode( wp_strip_all_tags( $item->get_name() ), ENT_QUOTES, 'UTF-8' ),
			'sifra'                         => $sku,
			'marka'                         => $this->fields->brand( $item ),
			'jedinica_mjere'                => $this->fields->unit( $item ),
			'cijena_za_jedinicu_mjere'      => $this->fields->unit_price( $item, $price->price ),
			'maloprodajna_cijena'           => $price->price,
			'poseban_oblik_prodaje'         => $price->is_sale ? 'DA' : 'NE',
			'naziv_posebnog_oblika_prodaje' => $price->sale_name,
			'sidrena_cijena'                => $anchor_price,
			'sidreni_datum'                 => ( new DateTimeImmutable( $anchor_date ) )->format( 'd.m.Y' ),
			'barkod'                        => $barcode,
			'dostupnost'                    => $this->availability( $item, $outlet ),
			'najniza_cijena_30_dana'        => $this->history->lowest_30_day_price( $item, $price, $moment ),
			'redovna_cijena'                => $price->regular_price,
			'neto_kolicina'                 => $this->fields->net_quantity( $item ),
			'kategorija'                    => $this->fields->category( $item ),
		);

		/**
		 * Changes one Price List row, e.g. to fill an added column.
		 *
		 * @param array<string, string|float|null> $row
		 * @param WC_Product                       $item
		 * @param Outlet                           $outlet
		 * @param DateTimeImmutable                $moment
		 */
		return (array) apply_filters( 'cjenik_price_list_row', $row, $item, $outlet, $moment );
	}

	private function availability( WC_Product $item, Outlet $outlet ): string {
		$status = $item->get_stock_status();
		if ( 'onbackorder' === $status ) {
			$available = 'dostupno' === $this->settings->get( 'backorder_availability' );
		} else {
			$available = 'instock' === $status;
		}
		/**
		 * Whether an item is available in an Outlet at publication time.
		 */
		return apply_filters( 'cjenik_item_available', $available, $item, $outlet ) ? 'dostupno' : 'nedostupno';
	}
}

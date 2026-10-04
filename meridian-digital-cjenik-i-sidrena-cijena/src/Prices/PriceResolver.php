<?php
/**
 * @package Cjenik
 */

namespace Cjenik\Prices;

use Cjenik\Settings;
use DateTimeImmutable;
use WC_Product;
use WC_Tax;

/**
 * The single place that turns a WooCommerce product or variation into the
 * legally relevant prices at a moment.
 *
 * It never reads the stored `_price`, which is stale until WooCommerce's
 * scheduled-sales job runs, and never asks WooCommerce for the customer's tax
 * location, which is empty in cron and REST requests.
 */
final class PriceResolver {
	public const SALE_NAME_META = '_cjenik_sale_name';

	public function __construct( private Settings $settings ) {}

	public function resolve( WC_Product $product, DateTimeImmutable $moment ): ResolvedPrice {
		$regular = $product->get_regular_price( 'edit' );
		if ( '' === $regular || ! is_numeric( $regular ) ) {
			return new ResolvedPrice( null, null, false, '' );
		}
		$regular = (float) $regular;
		$is_sale = $this->is_on_sale( $product, $moment );
		$price   = $is_sale ? (float) $product->get_sale_price( 'edit' ) : $regular;

		return new ResolvedPrice(
			$this->with_vat( $price, $product ),
			$this->with_vat( $regular, $product ),
			$is_sale,
			$is_sale ? $this->sale_name( $product ) : '',
		);
	}

	/**
	 * The moments at which the item's scheduled sale starts and stops applying.
	 *
	 * @return list<DateTimeImmutable>
	 */
	public function sale_transitions( WC_Product $product ): array {
		$transitions = array();
		$from        = $product->get_date_on_sale_from( 'edit' );
		$to          = $product->get_date_on_sale_to( 'edit' );
		if ( $from ) {
			$transitions[] = ( new DateTimeImmutable( '@' . $from->getTimestamp() ) );
		}
		if ( $to ) {
			// WooCommerce keeps the sale on until the end date's last second.
			$transitions[] = ( new DateTimeImmutable( '@' . ( $to->getTimestamp() + 1 ) ) );
		}
		return $transitions;
	}

	private function is_on_sale( WC_Product $product, DateTimeImmutable $moment ): bool {
		$sale    = $product->get_sale_price( 'edit' );
		$regular = (float) $product->get_regular_price( 'edit' );
		if ( '' === $sale || ! is_numeric( $sale ) || (float) $sale >= $regular ) {
			return false;
		}
		$from = $product->get_date_on_sale_from( 'edit' );
		$to   = $product->get_date_on_sale_to( 'edit' );
		if ( $from && $from->getTimestamp() > $moment->getTimestamp() ) {
			return false;
		}
		if ( $to && $to->getTimestamp() < $moment->getTimestamp() ) {
			return false;
		}
		return true;
	}

	/**
	 * Adds Croatian VAT for the item's tax class, unless prices are entered with VAT.
	 */
	private function with_vat( float $amount, WC_Product $product ): float {
		if ( ! wc_tax_enabled() || 'taxable' !== $product->get_tax_status() || wc_prices_include_tax() ) {
			return round( $amount, 2 );
		}
		$rates = WC_Tax::find_rates(
			array(
				'country'   => 'HR',
				'state'     => '',
				'postcode'  => '',
				'city'      => '',
				'tax_class' => $product->get_tax_class(),
			)
		);
		$taxes = WC_Tax::calc_tax( $amount, $rates, false );
		return round( $amount + array_sum( $taxes ), 2 );
	}

	private function sale_name( WC_Product $product ): string {
		$name = (string) $product->get_meta( self::SALE_NAME_META );
		if ( '' === $name && $product->get_parent_id() ) {
			$name = (string) get_post_meta( $product->get_parent_id(), self::SALE_NAME_META, true );
		}
		return '' !== $name ? $name : (string) $this->settings->get( 'default_sale_name' );
	}
}

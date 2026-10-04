<?php
/**
 * @package Cjenik
 */

namespace Cjenik\Storefront;

use Cjenik\Anchors\AnchorRegistry;
use Cjenik\Clock;
use Cjenik\Prices\PriceHistory;
use Cjenik\Prices\PriceResolver;
use Cjenik\Settings;
use WC_Product;

/**
 * Shows the Anchor Price, labelled with its date, next to the product price
 * wherever WooCommerce renders it: product pages, shop archives, product
 * blocks and variation selection. During a Special Sale it also shows the
 * Lowest 30-day Price.
 */
final class PriceDisplay {
	public function __construct(
		private Settings $settings,
		private PriceResolver $resolver,
		private PriceHistory $history,
		private AnchorRegistry $anchors,
		private Clock $clock,
	) {}

	public static function register(): void {
		add_filter( 'woocommerce_get_price_html', static fn( $html, $product ) => cjenik()->price_display()->filter_price_html( (string) $html, $product ), 20, 2 );
		// Each variation has its own anchor, so show its price block on selection
		// even when all variations cost the same.
		add_filter( 'woocommerce_show_variation_price', static fn( $show ) => cjenik()->settings()->get( 'display_enabled' ) ? true : $show );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_style' ) );
	}

	public static function enqueue_style(): void {
		if ( ! cjenik()->settings()->get( 'display_enabled' ) ) {
			return;
		}
		wp_register_style( 'cjenik-cijene', false, array(), CJENIK_VERSION );
		wp_enqueue_style( 'cjenik-cijene' );
		wp_add_inline_style( 'cjenik-cijene', '.cjenik-cijene{display:block;font-size:.85em;font-weight:normal}.cjenik-cijene>span{display:block}' );
	}

	public function filter_price_html( string $html, mixed $product ): string {
		if ( ! $product instanceof WC_Product || '' === $html || ! $this->settings->get( 'display_enabled' ) ) {
			return $html;
		}
		$lines = $product->is_type( 'variable' ) ? $this->variable_lines( $product ) : $this->item_lines( $product );
		if ( ! $lines ) {
			return $html;
		}
		return $html . ' <span class="cjenik-cijene">' . implode( '', $lines ) . '</span>';
	}

	/**
	 * @return list<string>
	 */
	private function item_lines( WC_Product $item ): array {
		$anchor = $this->anchors->for( $item );
		if ( ! $anchor->has_price() ) {
			return array();
		}
		$lines = array();
		$now   = $this->clock->now();
		$price = $this->resolver->resolve( $item, $now );
		if ( $price->is_sale ) {
			$lowest = $this->history->lowest_30_day_price( $item, $price, $now );
			if ( null !== $lowest ) {
				$lines[] = $this->line( 'cjenik-najniza', (string) $this->settings->get( 'lowest_label' ), wc_price( $lowest ) );
			}
		}
		$lines[] = $this->line( 'cjenik-sidrena', $this->anchor_label( (string) $anchor->date ), wc_price( (float) $anchor->price ) );
		return $lines;
	}

	/**
	 * A variable product in a list shows its variations' anchors as a range,
	 * if they share an Anchor Date; each variation shows its own on selection.
	 *
	 * @return list<string>
	 */
	private function variable_lines( WC_Product $product ): array {
		$prices = array();
		$dates  = array();
		foreach ( $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( ! $child || 'publish' !== $child->get_status() ) {
				continue;
			}
			$anchor = $this->anchors->for( $child );
			if ( $anchor->has_price() ) {
				$prices[]                        = (float) $anchor->price;
				$dates[ (string) $anchor->date ] = true;
			}
		}
		if ( ! $prices || count( $dates ) > 1 ) {
			return array();
		}
		$min    = min( $prices );
		$max    = max( $prices );
		$amount = $min === $max ? wc_price( $min ) : wc_price( $min ) . ' &ndash; ' . wc_price( $max );
		return array( $this->line( 'cjenik-sidrena', $this->anchor_label( (string) array_key_first( $dates ) ), $amount ) );
	}

	private function anchor_label( string $date ): string {
		$formatted = ( new \DateTimeImmutable( $date ) )->format( 'j.n.Y.' );
		return str_replace( '{datum}', $formatted, (string) $this->settings->get( 'anchor_label' ) );
	}

	private function line( string $css_class, string $label, string $amount_html ): string {
		return '<span class="' . esc_attr( $css_class ) . '">' . esc_html( $label ) . ' ' . $amount_html . '</span>';
	}
}

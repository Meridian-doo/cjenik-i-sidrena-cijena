<?php
/**
 * @package Cjenik
 */

namespace Cjenik\Admin;

use Cjenik\Anchors\Anchor;
use Cjenik\PriceList\FieldMapping;
use Cjenik\Prices\PriceResolver;
use Cjenik\Import\CsvImporter;
use WC_Product;

/**
 * Anchor Price, Anchor Date, Special Sale name and unit fields on the product
 * and variation edit screens.
 */
final class ProductFields {
	private const NONCE = 'cjenik_product_fields';

	/** Units of measure offered for the unit price. */
	public const UNITS = array( 'kg', 'g', 'l', 'ml', 'm', 'm2', 'm3', 'kom' );

	public static function register(): void {
		add_action( 'woocommerce_product_options_pricing', array( self::class, 'product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( self::class, 'save_product' ) );
		add_action( 'woocommerce_variation_options_pricing', array( self::class, 'variation_fields' ), 10, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( self::class, 'save_variation' ), 10, 2 );
	}

	public static function product_fields(): void {
		global $product_object;
		if ( ! $product_object instanceof WC_Product ) {
			return;
		}
		wp_nonce_field( self::NONCE, self::NONCE );
		echo '<div class="options_group cjenik-fields">';
		self::anchor_fields( $product_object, '', '' );
		woocommerce_wp_text_input(
			array(
				'id'          => 'cjenik_sale_name',
				'label'       => __( 'Special sale name', 'meridian-digital-cjenik-i-sidrena-cijena' ),
				'value'       => (string) $product_object->get_meta( PriceResolver::SALE_NAME_META ),
				'placeholder' => (string) cjenik()->settings()->get( 'default_sale_name' ),
				'desc_tip'    => true,
				'description' => __( 'Shown in the price list while this product is on sale, e.g. "Akcija" or "Rasprodaja". Leave empty for the shop default.', 'meridian-digital-cjenik-i-sidrena-cijena' ),
			)
		);
		if ( 'plugin' === cjenik()->settings()->get( 'unit_source' ) ) {
			self::unit_fields( $product_object, '', '' );
		}
		echo '</div>';
	}

	/**
	 * @param int                  $loop
	 * @param array<string, mixed> $variation_data
	 * @param \WP_Post             $variation_post
	 */
	public static function variation_fields( $loop, $variation_data, $variation_post ): void {
		$variation = wc_get_product( $variation_post->ID );
		if ( ! $variation ) {
			return;
		}
		wp_nonce_field( self::NONCE, self::NONCE . "_{$loop}" );
		echo '<div class="cjenik-fields">';
		self::anchor_fields( $variation, "[{$loop}]", "_{$loop}" );
		if ( 'plugin' === cjenik()->settings()->get( 'unit_source' ) ) {
			self::unit_fields( $variation, "[{$loop}]", "_{$loop}" );
		}
		echo '</div>';
	}

	public static function save_product( WC_Product $product ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE ] ), self::NONCE ) ) {
			return;
		}
		self::save_anchor( $product, self::posted( 'cjenik_anchor_price' ), self::posted( 'cjenik_anchor_date' ) );
		$product->update_meta_data( PriceResolver::SALE_NAME_META, self::posted( 'cjenik_sale_name' ) );
		if ( 'plugin' === cjenik()->settings()->get( 'unit_source' ) ) {
			self::save_units( $product, self::posted( 'cjenik_unit' ), self::posted( 'cjenik_unit_quantity' ) );
		}
	}

	public static function save_variation( WC_Product $variation, int $loop ): void {
		$nonce = self::NONCE . "_{$loop}";
		if ( ! isset( $_POST[ $nonce ] ) || ! wp_verify_nonce( sanitize_key( $_POST[ $nonce ] ), self::NONCE ) ) {
			return;
		}
		self::save_anchor( $variation, self::posted( 'cjenik_anchor_price', $loop ), self::posted( 'cjenik_anchor_date', $loop ) );
		if ( 'plugin' === cjenik()->settings()->get( 'unit_source' ) ) {
			self::save_units( $variation, self::posted( 'cjenik_unit', $loop ), self::posted( 'cjenik_unit_quantity', $loop ) );
		}
	}

	private static function anchor_fields( WC_Product $item, string $name_suffix, string $id_suffix ): void {
		$anchor  = cjenik()->anchors()->for( $item );
		$stored  = Anchor::SOURCE_AUTO !== $anchor->source;
		$wrapper = $name_suffix ? 'form-row form-row-first' : '';

		woocommerce_wp_text_input(
			array(
				'id'            => 'cjenik_anchor_price' . $id_suffix,
				'name'          => 'cjenik_anchor_price' . $name_suffix,
				'label'         => __( 'Anchor price incl. VAT', 'meridian-digital-cjenik-i-sidrena-cijena' ) . ' (' . get_woocommerce_currency_symbol() . ')',
				'value'         => $stored ? wc_format_localized_price( (string) $anchor->price ) : '',
				'placeholder'   => $anchor->has_price() ? wc_format_localized_price( (string) $anchor->price ) : '',
				'data_type'     => 'price',
				'wrapper_class' => $wrapper,
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => 'cjenik_anchor_date' . $id_suffix,
				'name'          => 'cjenik_anchor_date' . $name_suffix,
				'label'         => __( 'Anchor date', 'meridian-digital-cjenik-i-sidrena-cijena' ),
				'type'          => 'date',
				'value'         => $stored ? (string) $anchor->date : '',
				'placeholder'   => (string) $anchor->date,
				'wrapper_class' => $name_suffix ? 'form-row form-row-last' : '',
			)
		);
		echo '<p class="form-field cjenik-anchor-source' . ( $name_suffix ? ' form-row form-row-full' : '' ) . '"><span class="description">' . esc_html( self::describe( $anchor ) ) . '</span></p>';
	}

	private static function unit_fields( WC_Product $item, string $name_suffix, string $id_suffix ): void {
		woocommerce_wp_select(
			array(
				'id'            => 'cjenik_unit' . $id_suffix,
				'name'          => 'cjenik_unit' . $name_suffix,
				'label'         => __( 'Unit of measure', 'meridian-digital-cjenik-i-sidrena-cijena' ),
				'value'         => (string) $item->get_meta( FieldMapping::UNIT_META ),
				'options'       => array( '' => __( '(none)', 'meridian-digital-cjenik-i-sidrena-cijena' ) ) + array_combine( self::UNITS, self::UNITS ),
				'wrapper_class' => $name_suffix ? 'form-row form-row-first' : '',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => 'cjenik_unit_quantity' . $id_suffix,
				'name'          => 'cjenik_unit_quantity' . $name_suffix,
				'label'         => __( 'Net quantity in that unit', 'meridian-digital-cjenik-i-sidrena-cijena' ),
				'value'         => (string) $item->get_meta( FieldMapping::UNIT_QUANTITY_META ),
				'placeholder'   => '0,25',
				'desc_tip'      => true,
				'description'   => __( 'E.g. 0,25 for 250 g sold by the kg. The unit price is the retail price divided by this.', 'meridian-digital-cjenik-i-sidrena-cijena' ),
				'wrapper_class' => $name_suffix ? 'form-row form-row-last' : '',
			)
		);
	}

	private static function describe( Anchor $anchor ): string {
		if ( ! $anchor->has_price() ) {
			return __( 'No anchor yet. It is set from the price history once the product is published, or enter it here.', 'meridian-digital-cjenik-i-sidrena-cijena' );
		}
		$date = ( new \DateTimeImmutable( (string) $anchor->date ) )->format( 'j.n.Y.' );
		switch ( $anchor->source ) {
			case Anchor::SOURCE_MANUAL:
				/* translators: %s: date */
				return sprintf( __( 'Entered by hand, for %s. Clear both fields to use the price history.', 'meridian-digital-cjenik-i-sidrena-cijena' ), $date );
			case Anchor::SOURCE_IMPORTED:
				/* translators: %s: date */
				return sprintf( __( 'Imported from CSV, for %s. Clear both fields to use the price history.', 'meridian-digital-cjenik-i-sidrena-cijena' ), $date );
		}
		if ( ! $anchor->confirmed ) {
			/* translators: %s: date */
			return sprintf( __( 'Not confirmed: the price history starts on %s, after the reference date. Enter or import the correct anchor.', 'meridian-digital-cjenik-i-sidrena-cijena' ), $date );
		}
		/* translators: %s: date */
		return sprintf( __( 'Automatic, from the price history on %s.', 'meridian-digital-cjenik-i-sidrena-cijena' ), $date );
	}

	private static function save_anchor( WC_Product $item, string $price, string $date ): void {
		if ( ! $item->get_id() ) {
			return;
		}
		if ( '' === $price && '' === $date ) {
			$stored = get_post_meta( $item->get_id(), \Cjenik\Anchors\AnchorRegistry::META_SOURCE, true );
			if ( Anchor::SOURCE_AUTO !== $stored ) {
				cjenik()->anchors()->clear( $item->get_id() );
			}
			return;
		}
		$amount = CsvImporter::parse_amount( $price );
		$day    = CsvImporter::parse_date( $date );
		if ( null === $amount || ! $day ) {
			\WC_Admin_Meta_Boxes::add_error( __( 'The anchor needs both a price and a date. It was not saved.', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
			return;
		}
		$current = cjenik()->anchors()->for( $item );
		$date    = \Cjenik\Zagreb::date( $day );
		if ( Anchor::SOURCE_AUTO !== $current->source && $current->price === $amount && $current->date === $date ) {
			return; // Unchanged: keep it marked as imported.
		}
		cjenik()->anchors()->set( $item->get_id(), $amount, $date, Anchor::SOURCE_MANUAL );
	}

	private static function save_units( WC_Product $item, string $unit, string $quantity ): void {
		$item->update_meta_data( FieldMapping::UNIT_META, in_array( $unit, self::UNITS, true ) ? $unit : '' );
		$item->update_meta_data( FieldMapping::UNIT_QUANTITY_META, $quantity );
	}

	private static function posted( string $key, ?int $loop = null ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- Nonce verified by the callers; unslashed and sanitized below.
		$value = null === $loop ? ( $_POST[ $key ] ?? '' ) : ( $_POST[ $key ][ $loop ] ?? '' );
		// phpcs:enable
		return is_string( $value ) ? trim( sanitize_text_field( wp_unslash( $value ) ) ) : '';
	}
}

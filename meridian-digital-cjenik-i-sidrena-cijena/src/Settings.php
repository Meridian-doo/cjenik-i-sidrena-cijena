<?php
/**
 * @package Cjenik
 */

namespace Cjenik;

/**
 * All plugin settings, stored in one option.
 */
final class Settings {
	public const OPTION = 'cjenik_settings';

	public const MIN_RETENTION_DAYS = 31;

	/** @var array<string, mixed>|null */
	private ?array $cache = null;

	public function __construct() {
		// Settings are read for every Price List row; re-read only after a change.
		$forget = function (): void {
			$this->cache = null;
		};
		add_action( 'add_option_' . self::OPTION, $forget );
		add_action( 'update_option_' . self::OPTION, $forget );
		add_action( 'delete_option_' . self::OPTION, $forget );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'outlet_type'              => 'webshop',
			'outlet_address'           => self::store_address(),
			'outlet_code'              => 'P-01',
			'publish_time'             => '05:00',
			'file_time_separator'      => '-',
			'format'                   => 'csv',
			'csv_delimiter'            => ';',
			'csv_decimal'              => ',',
			'csv_bom'                  => false,
			'csv_eol'                  => 'crlf',
			'columns'                  => PriceList\Columns::default_selection(),
			'brand_source'             => taxonomy_exists( 'product_brand' ) ? 'taxonomy:product_brand' : 'none',
			'barcode_source'           => 'gtin',
			'unit_source'              => 'none',
			'unit_meta_key'            => '',
			'unit_price_meta_key'      => '',
			'backorder_availability'   => 'nedostupno',
			'default_sale_name'        => 'Akcija',
			'fmcg_2025_categories'     => array(),
			'display_enabled'          => true,
			'anchor_label'             => 'Cijena na {datum}:',
			'lowest_label'             => 'Najniža cijena u zadnjih 30 dana:',
			'retention_days'           => 35,
			'json_index'               => false,
			'alert_email'              => true,
			'alert_time'               => '07:30',
			'delete_data_on_uninstall' => false,
			'wizard_done'              => false,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, array() );
			$this->cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return $this->cache;
	}

	public function get( string $key ): mixed {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * Merges and saves settings. Values are sanitised against the defaults' types.
	 *
	 * @param array<string, mixed> $values
	 */
	public function update( array $values ): void {
		$current = $this->all();
		foreach ( $values as $key => $value ) {
			if ( array_key_exists( $key, $current ) ) {
				$current[ $key ] = self::sanitize( $key, $value );
			}
		}
		update_option( self::OPTION, $current );
		$this->cache = null;
		do_action( 'cjenik_settings_updated', $current );
	}

	public function retention_days(): int {
		return max( self::MIN_RETENTION_DAYS, (int) $this->get( 'retention_days' ) );
	}

	private static function sanitize( string $key, mixed $value ): mixed {
		switch ( $key ) {
			case 'csv_bom':
			case 'display_enabled':
			case 'json_index':
			case 'alert_email':
			case 'delete_data_on_uninstall':
			case 'wizard_done':
				return (bool) $value;
			case 'retention_days':
				return max( self::MIN_RETENTION_DAYS, (int) $value );
			case 'publish_time':
			case 'alert_time':
				return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $value ) ? (string) $value : self::defaults()[ $key ];
			case 'format':
				return in_array( $value, array( 'csv', 'xml' ), true ) ? $value : 'csv';
			case 'csv_delimiter':
				return in_array( $value, array( ';', ',' ), true ) ? $value : ';';
			case 'csv_eol':
				return in_array( $value, array( 'crlf', 'lf' ), true ) ? $value : 'crlf';
			case 'csv_decimal':
				return in_array( $value, array( ',', '.' ), true ) ? $value : ',';
			case 'file_time_separator':
				return in_array( $value, array( '-', ':' ), true ) ? $value : '-';
			case 'backorder_availability':
				return in_array( $value, array( 'dostupno', 'nedostupno' ), true ) ? $value : 'nedostupno';
			case 'unit_source':
				return in_array( $value, array( 'none', 'plugin', 'meta' ), true ) ? $value : 'none';
			case 'fmcg_2025_categories':
				return array_values( array_filter( array_map( 'absint', (array) $value ) ) );
			case 'columns':
				return PriceList\Columns::sanitize_selection( (array) $value );
			case 'outlet_address':
			case 'outlet_type':
			case 'outlet_code':
			case 'anchor_label':
			case 'lowest_label':
			case 'default_sale_name':
			case 'brand_source':
			case 'barcode_source':
			case 'unit_meta_key':
			case 'unit_price_meta_key':
				return trim( sanitize_text_field( (string) $value ) );
		}
		return $value;
	}

	/**
	 * The WooCommerce store address as one line, e.g. "Ilica 150 Zagreb".
	 */
	private static function store_address(): string {
		$parts = array_filter(
			array(
				trim( (string) get_option( 'woocommerce_store_address', '' ) ),
				trim( (string) get_option( 'woocommerce_store_city', '' ) ),
			)
		);
		return implode( ' ', $parts );
	}
}

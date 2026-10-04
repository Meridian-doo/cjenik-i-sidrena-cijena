<?php
/**
 * @package Cjenik
 */

namespace Cjenik\PriceList;

/**
 * The Price List columns: which exist, their default header, and how the
 * serializers treat their values.
 */
final class Columns {
	/** Free text: guarded against spreadsheet formula injection. */
	public const TEXT = 'text';
	/** A code that must stay text (barcodes, SKUs): never reformatted as a number. */
	public const CODE = 'code';
	/** A EUR amount. */
	public const PRICE = 'price';
	/** A number that isn't money. */
	public const NUMBER = 'number';

	/** The columns NN 101/2026 t. III requires, in the order of the decision and the HOK template. */
	private const REQUIRED = array(
		'naziv',
		'sifra',
		'marka',
		'jedinica_mjere',
		'cijena_za_jedinicu_mjere',
		'maloprodajna_cijena',
		'poseban_oblik_prodaje',
		'naziv_posebnog_oblika_prodaje',
		'sidrena_cijena',
		'sidreni_datum',
		'barkod',
		'dostupnost',
	);

	/**
	 * Every available column: key => [default header, type].
	 * Keys are also the XML element names.
	 *
	 * @return array<string, array{label: string, type: string}>
	 */
	public static function definitions(): array {
		$columns = array(
			'naziv'                         => array(
				'label' => 'naziv',
				'type'  => self::TEXT,
			),
			'sifra'                         => array(
				'label' => 'šifra',
				'type'  => self::CODE,
			),
			'marka'                         => array(
				'label' => 'marka',
				'type'  => self::TEXT,
			),
			'jedinica_mjere'                => array(
				'label' => 'jedinica mjere',
				'type'  => self::TEXT,
			),
			'cijena_za_jedinicu_mjere'      => array(
				'label' => 'cijena za jedinicu mjere',
				'type'  => self::PRICE,
			),
			'maloprodajna_cijena'           => array(
				'label' => 'maloprodajna cijena',
				'type'  => self::PRICE,
			),
			'poseban_oblik_prodaje'         => array(
				'label' => 'poseban oblik prodaje',
				'type'  => self::TEXT,
			),
			'naziv_posebnog_oblika_prodaje' => array(
				'label' => 'naziv posebnog oblika prodaje',
				'type'  => self::TEXT,
			),
			'sidrena_cijena'                => array(
				'label' => 'sidrena cijena',
				'type'  => self::PRICE,
			),
			'sidreni_datum'                 => array(
				'label' => 'sidreni datum',
				'type'  => self::TEXT,
			),
			'barkod'                        => array(
				'label' => 'barkod',
				'type'  => self::CODE,
			),
			'dostupnost'                    => array(
				'label' => 'dostupnost',
				'type'  => self::TEXT,
			),
			// Optional.
			'najniza_cijena_30_dana'        => array(
				'label' => 'najniža cijena u posljednjih 30 dana',
				'type'  => self::PRICE,
			),
			'redovna_cijena'                => array(
				'label' => 'redovna cijena',
				'type'  => self::PRICE,
			),
			'neto_kolicina'                 => array(
				'label' => 'neto količina',
				'type'  => self::NUMBER,
			),
			'kategorija'                    => array(
				'label' => 'kategorija',
				'type'  => self::TEXT,
			),
		);
		/**
		 * Adds Price List columns. Fill their values with `cjenik_price_list_row`.
		 *
		 * @param array<string, array{label: string, type: string}> $columns
		 */
		return (array) apply_filters( 'cjenik_columns', $columns );
	}

	/**
	 * The default column selection: the required columns with their default headers.
	 *
	 * @return list<array{key: string, label: string}>
	 */
	public static function default_selection(): array {
		$definitions = self::definitions();
		return array_map(
			static fn( string $key ) => array(
				'key'   => $key,
				'label' => $definitions[ $key ]['label'],
			),
			self::REQUIRED
		);
	}

	/**
	 * @param array<mixed> $selection
	 * @return list<array{key: string, label: string}>
	 */
	public static function sanitize_selection( array $selection ): array {
		$definitions = self::definitions();
		$clean       = array();
		$seen        = array();
		foreach ( $selection as $column ) {
			$key = is_array( $column ) ? (string) ( $column['key'] ?? '' ) : '';
			if ( ! isset( $definitions[ $key ] ) || isset( $seen[ $key ] ) ) {
				continue;
			}
			$label        = trim( sanitize_text_field( (string) ( $column['label'] ?? '' ) ) );
			$clean[]      = array(
				'key'   => $key,
				'label' => '' !== $label ? $label : $definitions[ $key ]['label'],
			);
			$seen[ $key ] = true;
		}
		return $clean ? $clean : self::default_selection();
	}

	/** Whether a column is one NN 101/2026 requires. */
	public static function is_required( string $key ): bool {
		return in_array( $key, self::REQUIRED, true );
	}
}

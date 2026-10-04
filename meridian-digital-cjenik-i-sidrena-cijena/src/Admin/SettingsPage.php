<?php
/**
 * @package Cjenik
 */

namespace Cjenik\Admin;

use Cjenik\PriceList\Columns;

/**
 * The Settings tab: one card per section, fields with the label above and
 * the help below, and the save button in a bar that stays in view. Posts to
 * AdminPages::save_settings(), which reads the fields by name.
 */
final class SettingsPage {
	public static function render(): void {
		$s = cjenik()->settings()->all();
		echo '<form class="cjenik-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'cjenik_save_settings' );
		echo '<input type="hidden" name="action" value="cjenik_save_settings">';

		self::open_fields( 'outlet', __( 'Outlet', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
		AdminPages::timezone_notice();
		self::text_field( 'outlet_type', __( 'Outlet type', 'meridian-digital-cjenik-i-sidrena-cijena' ), $s['outlet_type'], __( 'First part of the file name, e.g. "webshop".', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
		self::text_field( 'outlet_address', __( 'Outlet address', 'meridian-digital-cjenik-i-sidrena-cijena' ), $s['outlet_address'], __( 'Second part of the file name. For an online-only shop, the store address from the WooCommerce settings is used by default.', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
		self::text_field( 'outlet_code', __( 'Outlet code', 'meridian-digital-cjenik-i-sidrena-cijena' ), $s['outlet_code'], __( 'Third part of the file name, e.g. "P-01".', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
		self::close_section();

		self::open_fields( 'publication', __( 'Publication', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
		self::text_field( 'publish_time', __( 'Publish daily at', 'meridian-digital-cjenik-i-sidrena-cijena' ), $s['publish_time'], __( 'Europe/Zagreb time, every day including weekends and holidays. The list must be online by 08:00.', 'meridian-digital-cjenik-i-sidrena-cijena' ), 'time' );
		self::select_field(
			'file_time_separator',
			__( 'Time in the file name', 'meridian-digital-cjenik-i-sidrena-cijena' ),
			$s['file_time_separator'],
			array(
				'-' => '05-00',
				':' => __( '05:00 (as in the Ministry example; not allowed in Windows file names)', 'meridian-digital-cjenik-i-sidrena-cijena' ),
			)
		);
		self::text_field( 'retention_days', __( 'Keep files for (days)', 'meridian-digital-cjenik-i-sidrena-cijena' ), (string) $s['retention_days'], __( 'At least 31. The newest file is never deleted.', 'meridian-digital-cjenik-i-sidrena-cijena' ), 'number' );
		self::radio_field(
			'format',
			__( 'Format', 'meridian-digital-cjenik-i-sidrena-cijena' ),
			$s['format'],
			array(
				'csv' => 'CSV',
				'xml' => 'XML',
			)
		);
		self::select_field(
			'csv_delimiter',
			__( 'CSV separator', 'meridian-digital-cjenik-i-sidrena-cijena' ),
			$s['csv_delimiter'],
			array(
				';' => __( 'Semicolon (;)', 'meridian-digital-cjenik-i-sidrena-cijena' ),
				',' => __( 'Comma (,)', 'meridian-digital-cjenik-i-sidrena-cijena' ),
			)
		);
		self::select_field(
			'csv_decimal',
			__( 'CSV decimal separator', 'meridian-digital-cjenik-i-sidrena-cijena' ),
			$s['csv_decimal'],
			array(
				',' => '12,50',
				'.' => '12.50',
			)
		);
		self::select_field(
			'csv_eol',
			__( 'CSV line endings', 'meridian-digital-cjenik-i-sidrena-cijena' ),
			$s['csv_eol'],
			array(
				'crlf' => 'CRLF (Windows, RFC 4180)',
				'lf'   => 'LF',
			)
		);
		self::switch_field( 'csv_bom', __( 'CSV byte order mark', 'meridian-digital-cjenik-i-sidrena-cijena' ), $s['csv_bom'], __( 'Add a UTF-8 BOM. Helps old Excel versions, can confuse simple readers.', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
		self::switch_field( 'json_index', __( 'Archive index', 'meridian-digital-cjenik-i-sidrena-cijena' ), $s['json_index'], __( 'Publish a JSON list of archived files for price aggregators.', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
		self::close_section();

		self::open_section( 'columns', __( 'Columns', 'meridian-digital-cjenik-i-sidrena-cijena' ), __( 'Required columns can be renamed and reordered but not removed. Lower numbers come first.', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
		self::columns_table( $s['columns'] );
		echo '</section>';

		self::open_fields( 'data', __( 'Where the data comes from', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
		self::select_field( 'brand_source', __( 'Brand (marka)', 'meridian-digital-cjenik-i-sidrena-cijena' ), $s['brand_source'], AdminPages::brand_sources() );
		self::text_field( 'barcode_source', __( 'Barcode (barkod)', 'meridian-digital-cjenik-i-sidrena-cijena' ), $s['barcode_source'], __( '"gtin" for the WooCommerce GTIN, UPC, EAN or ISBN field, or "meta:" and the meta key of your EAN plugin, e.g. "meta:_ean".', 'meridian-digital-cjenik-i-sidrena-cijena' ), 'code' );
		self::select_field(
			'unit_source',
			__( 'Unit price', 'meridian-digital-cjenik-i-sidrena-cijena' ),
			$s['unit_source'],
			array(
				'none'   => __( 'Not used', 'meridian-digital-cjenik-i-sidrena-cijena' ),
				'plugin' => __( 'Unit and net quantity fields on each product', 'meridian-digital-cjenik-i-sidrena-cijena' ),
				'meta'   => __( 'Meta keys from another plugin', 'meridian-digital-cjenik-i-sidrena-cijena' ),
			)
		);
		self::text_field( 'unit_meta_key', __( 'Unit meta key', 'meridian-digital-cjenik-i-sidrena-cijena' ), $s['unit_meta_key'], '', 'code' );
		self::text_field( 'unit_price_meta_key', __( 'Unit price meta key', 'meridian-digital-cjenik-i-sidrena-cijena' ), $s['unit_price_meta_key'], __( 'The value must include VAT.', 'meridian-digital-cjenik-i-sidrena-cijena' ), 'code' );
		self::select_field(
			'backorder_availability',
			__( 'Products on backorder', 'meridian-digital-cjenik-i-sidrena-cijena' ),
			$s['backorder_availability'],
			array(
				'nedostupno' => __( 'Unavailable (nedostupno)', 'meridian-digital-cjenik-i-sidrena-cijena' ),
				'dostupno'   => __( 'Available (dostupno)', 'meridian-digital-cjenik-i-sidrena-cijena' ),
			)
		);
		self::text_field( 'default_sale_name', __( 'Default special sale name', 'meridian-digital-cjenik-i-sidrena-cijena' ), $s['default_sale_name'], __( 'Used for products on sale without their own sale name.', 'meridian-digital-cjenik-i-sidrena-cijena' ), 'text', true );
		self::category_checklist( array_map( 'intval', (array) $s['fmcg_2025_categories'] ) );
		self::close_section();

		self::open_fields( 'display', __( 'Shop display', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
		self::switch_field( 'display_enabled', __( 'Show next to the price', 'meridian-digital-cjenik-i-sidrena-cijena' ), $s['display_enabled'], __( 'Show the anchor price, and during a sale the lowest price in the last 30 days, next to the product price.', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
		self::text_field( 'anchor_label', __( 'Anchor price label', 'meridian-digital-cjenik-i-sidrena-cijena' ), $s['anchor_label'], __( '{datum} is replaced by the anchor date, e.g. 10.9.2026.', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
		self::text_field( 'lowest_label', __( 'Lowest 30-day price label', 'meridian-digital-cjenik-i-sidrena-cijena' ), $s['lowest_label'], '' );
		self::close_section();

		self::open_fields( 'alerts', __( 'Alerts', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
		$alert_help = sprintf(
			/* translators: %s: email address */
			__( 'When publishing fails, or today\'s list is still missing at the check time. Sent to %s.', 'meridian-digital-cjenik-i-sidrena-cijena' ),
			get_option( 'admin_email' )
		);
		self::switch_field( 'alert_email', __( 'Email the site admin', 'meridian-digital-cjenik-i-sidrena-cijena' ), $s['alert_email'], $alert_help );
		self::text_field( 'alert_time', __( 'Check time', 'meridian-digital-cjenik-i-sidrena-cijena' ), $s['alert_time'], __( 'Europe/Zagreb time.', 'meridian-digital-cjenik-i-sidrena-cijena' ), 'time' );
		self::close_section();

		// The Plugins screen links here, to #cjenik-uninstall.
		self::open_fields( 'uninstall', __( 'When the plugin is deleted', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
		self::radio_field(
			'delete_data_on_uninstall',
			__( 'Data', 'meridian-digital-cjenik-i-sidrena-cijena' ),
			$s['delete_data_on_uninstall'] ? '1' : '0',
			array(
				'0' => __( 'Keep the price history, anchors, publication log and files (recommended)', 'meridian-digital-cjenik-i-sidrena-cijena' ),
				'1' => __( 'Delete everything', 'meridian-digital-cjenik-i-sidrena-cijena' ),
			),
			true
		);
		self::close_section();

		printf(
			'<div class="cjenik-actions"><button type="submit" class="cjenik-button" data-cjenik-busy>%s</button><a class="cjenik-button is-secondary" href="%s">%s</a></div>',
			esc_html__( 'Save settings', 'meridian-digital-cjenik-i-sidrena-cijena' ),
			esc_url( AdminPages::url( 'settings' ) ),
			esc_html__( 'Cancel', 'meridian-digital-cjenik-i-sidrena-cijena' )
		);
		echo '</form>';

		echo '<p class="cjenik-pro">' . wp_kses(
			sprintf(
				/* translators: %s: URL */
				__( 'Need several outlets, a service price list, monitoring from outside your site or an evidence pack for inspections? <a href="%s">Cjenik Pro</a> adds them.', 'meridian-digital-cjenik-i-sidrena-cijena' ),
				'https://cjenik.dev/#cjenik-pro'
			),
			array( 'a' => array( 'href' => array() ) )
		) . '</p>';
	}

	private static function open_section( string $id, string $title, string $lede = '' ): void {
		printf(
			'<section class="cjenik-card" id="cjenik-%1$s" aria-labelledby="cjenik-%1$s-title"><header class="cjenik-card__header"><h2 class="cjenik-card__title" id="cjenik-%1$s-title">%2$s</h2></header>%3$s',
			esc_attr( $id ),
			esc_html( $title ),
			'' !== $lede ? '<p class="cjenik-card__lede">' . esc_html( $lede ) . '</p>' : ''
		);
	}

	/** A section whose fields sit in the two-column grid; close_section() ends it. */
	private static function open_fields( string $id, string $title ): void {
		self::open_section( $id, $title );
		echo '<div class="cjenik-fields">';
	}

	/** Closes a section's field grid and the section. */
	private static function close_section(): void {
		echo '</div></section>';
	}

	private static function help( string $key, string $text ): string {
		return '' === $text ? '' : sprintf( '<p class="cjenik-field__help" id="cjenik-%s-help">%s</p>', esc_attr( $key ), esc_html( $text ) );
	}

	/** The help paragraph's id for aria-describedby, or "" when the field has no help. */
	private static function described_by( string $key, string $text ): string {
		return '' === $text ? '' : 'cjenik-' . $key . '-help';
	}

	/**
	 * @param string $type "text", "number", "time" or "code" (text in mono, for keys).
	 */
	private static function text_field( string $key, string $label, string $value, string $help, string $type = 'text', bool $wide = false ): void {
		$icon = 'time' === $type ? '<span class="cjenik-input__icon">' . AdminPages::icon( 'clock', 16 ) . '</span>' : '';
		printf(
			'<div class="cjenik-field%1$s"><label class="cjenik-field__label" for="cjenik-%2$s">%3$s</label><div class="cjenik-input-wrap">%4$s<input class="cjenik-input%5$s" type="%6$s" id="cjenik-%2$s" name="%2$s" value="%7$s" aria-describedby="%8$s"></div>%9$s</div>',
			$wide ? ' is-wide' : '',
			esc_attr( $key ),
			esc_html( $label ),
			wp_kses( $icon, AdminPages::ALLOWED_HTML ),
			'text' === $type ? '' : ' is-mono',
			esc_attr( 'code' === $type ? 'text' : $type ),
			esc_attr( $value ),
			esc_attr( self::described_by( $key, $help ) ),
			wp_kses( self::help( $key, $help ), AdminPages::ALLOWED_HTML )
		);
	}

	/**
	 * @param array<int|string, string> $options
	 */
	private static function select_field( string $key, string $label, string $value, array $options ): void {
		printf( '<div class="cjenik-field"><label class="cjenik-field__label" for="cjenik-%1$s">%2$s</label><select class="cjenik-input cjenik-select" id="cjenik-%1$s" name="%1$s">', esc_attr( $key ), esc_html( $label ) );
		foreach ( $options as $option => $text ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( (string) $option ), selected( $value, (string) $option, false ), esc_html( $text ) );
		}
		echo '</select></div>';
	}

	/**
	 * One choice out of a few, all in view.
	 *
	 * @param array<int|string, string> $options
	 */
	private static function radio_field( string $key, string $label, string $value, array $options, bool $wide = false ): void {
		printf( '<fieldset class="cjenik-field%s"><legend class="cjenik-field__label">%s</legend><div class="cjenik-choices">', $wide ? ' is-wide' : '', esc_html( $label ) );
		foreach ( $options as $option => $text ) {
			printf(
				'<label class="cjenik-choice"><input class="cjenik-radio" type="radio" name="%s" value="%s" %s><span>%s</span></label>',
				esc_attr( $key ),
				esc_attr( (string) $option ),
				checked( $value, (string) $option, false ),
				esc_html( $text )
			);
		}
		echo '</div></fieldset>';
	}

	/** An on/off setting: the label and what it does on the left, the switch on the right. */
	private static function switch_field( string $key, string $label, bool $checked, string $help ): void {
		printf(
			'<div class="cjenik-switch-row"><div><label class="cjenik-switch-row__label" for="cjenik-%1$s">%2$s</label>%3$s</div><input class="cjenik-switch" type="checkbox" role="switch" id="cjenik-%1$s" name="%1$s" value="1" %4$s aria-describedby="%5$s"></div>',
			esc_attr( $key ),
			esc_html( $label ),
			wp_kses( self::help( $key, $help ), AdminPages::ALLOWED_HTML ),
			checked( $checked, true, false ),
			esc_attr( self::described_by( $key, $help ) )
		);
	}

	/**
	 * @param list<array{key: string, label: string}> $selection
	 */
	private static function columns_table( array $selection ): void {
		$definitions = Columns::definitions();
		$positions   = array();
		foreach ( $selection as $index => $column ) {
			$positions[ $column['key'] ] = array( $index + 1, $column['label'] );
		}
		$include     = __( 'Include', 'meridian-digital-cjenik-i-sidrena-cijena' );
		$order_label = __( 'Order', 'meridian-digital-cjenik-i-sidrena-cijena' );
		$header      = __( 'Header', 'meridian-digital-cjenik-i-sidrena-cijena' );
		printf(
			'<div class="cjenik-table-scroll"><table class="cjenik-table is-form"><thead><tr><th scope="col">%s</th><th scope="col">%s</th><th scope="col" class="is-wide">%s</th></tr></thead><tbody>',
			esc_html( $include ),
			esc_html( $order_label ),
			esc_html( $header )
		);
		$order = count( $definitions );
		foreach ( $definitions as $key => $definition ) {
			$required = Columns::is_required( $key );
			$included = $required || isset( $positions[ $key ] );
			$position = $positions[ $key ][0] ?? ++$order;
			printf(
				'<tr><td><input class="cjenik-checkbox" type="checkbox" name="columns[%1$s][include]" value="1" aria-label="%7$s" %2$s %3$s></td><td><input class="cjenik-input is-small is-mono" type="number" min="1" name="columns[%1$s][order]" value="%4$d" aria-label="%8$s"></td><td class="is-wide"><input class="cjenik-input is-small is-mono" type="text" name="columns[%1$s][label]" value="%5$s" placeholder="%6$s" aria-label="%9$s"></td></tr>',
				esc_attr( $key ),
				checked( $included, true, false ),
				disabled( $required, true, false ),
				(int) $position,
				esc_attr( $positions[ $key ][1] ?? $definition['label'] ),
				esc_attr( $definition['label'] ),
				// Each input named for its column and what it sets: "Include: naziv".
				esc_attr( $include . ': ' . $definition['label'] ),
				esc_attr( $order_label . ': ' . $definition['label'] ),
				esc_attr( $header . ': ' . $definition['label'] )
			);
		}
		echo '</tbody></table></div>';
	}

	/**
	 * @param list<int> $selected
	 */
	private static function category_checklist( array $selected ): void {
		$help = __( 'Only if you already showed an anchor price for food, drink, cosmetics, cleaning, toiletry or household products under the 2025 decision. Subcategories are included.', 'meridian-digital-cjenik-i-sidrena-cijena' );
		printf( '<fieldset class="cjenik-field is-wide" aria-describedby="%s"><legend class="cjenik-field__label">%s</legend>', esc_attr( self::described_by( 'fmcg_2025_categories', $help ) ), esc_html__( 'Categories that keep the 2 May 2025 anchor', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'parent'     => 0,
			)
		);
		if ( ! is_array( $terms ) || ! $terms ) {
			echo '<p class="cjenik-field__help">' . esc_html__( 'No product categories.', 'meridian-digital-cjenik-i-sidrena-cijena' ) . '</p>';
		} else {
			echo '<div class="cjenik-choices is-columns">';
			foreach ( $terms as $term ) {
				printf(
					'<label class="cjenik-choice"><input class="cjenik-checkbox" type="checkbox" name="fmcg_2025_categories[]" value="%d" %s><span>%s</span></label>',
					(int) $term->term_id,
					checked( in_array( (int) $term->term_id, $selected, true ), true, false ),
					esc_html( $term->name )
				);
			}
			echo '</div>';
		}
		echo wp_kses( self::help( 'fmcg_2025_categories', $help ), AdminPages::ALLOWED_HTML ) . '</fieldset>';
	}
}

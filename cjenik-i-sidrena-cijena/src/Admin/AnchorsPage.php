<?php
/**
 * @package Cjenik
 */

namespace Cjenik\Admin;

/**
 * The Anchor prices tab: the items whose anchor the plugin can't vouch for,
 * and the two CSV imports that fix them.
 */
final class AnchorsPage {
	public static function render(): void {
		self::unconfirmed();
		echo '<div class="cjenik-imports">';
		self::import(
			'import_anchors',
			__( 'Import anchor prices', 'cjenik-i-sidrena-cijena' ),
			__( 'CSV with a "šifra" (SKU) or "barkod" column, a "sidrena cijena" column (incl. VAT) and optionally "sidreni datum". A HOK template with a "Cijena 10.9.2026." column works too.', 'cjenik-i-sidrena-cijena' )
		);
		self::import(
			'import_history',
			__( 'Import past prices', 'cjenik-i-sidrena-cijena' ),
			__( 'CSV with "šifra" or "barkod", "cijena" (incl. VAT), "od" (from) and optionally "redovna cijena" and "do" (until). Use it to fill the 30 days before you installed the plugin. Recorded history is never overwritten.', 'cjenik-i-sidrena-cijena' )
		);
		echo '</div>';
	}

	private static function unconfirmed(): void {
		$unconfirmed = cjenik()->anchors()->unconfirmed();
		$count       = $unconfirmed['count'];
		printf(
			'<section class="cjenik-card"><header class="cjenik-card__header"><h2 class="cjenik-card__title">%s</h2>%s</header><p class="cjenik-card__lede">%s</p>',
			esc_html__( 'Items without a confirmed anchor', 'cjenik-i-sidrena-cijena' ),
			$count ? '<span class="cjenik-pill is-warning">' . esc_html(
				/* translators: %d: number of items */
				sprintf( _n( '%d item', '%d items', $count, 'cjenik-i-sidrena-cijena' ), $count )
			) . '</span>' : '',
			esc_html__( 'The anchor is the regular price on 10 Sep 2026 (or 2 May 2025 for the FMCG categories you chose), or the first price of products listed later. The plugin knows it only from its own price history. For these items, enter it on the product screen or import it below.', 'cjenik-i-sidrena-cijena' )
		);
		if ( ! $count ) {
			echo '<p class="cjenik-card__message"><span class="cjenik-pill is-success">' . esc_html__( 'Every item has a confirmed anchor.', 'cjenik-i-sidrena-cijena' ) . '</span></p></section>';
			return;
		}
		printf(
			'<div class="cjenik-table-scroll"><table class="cjenik-table"><thead><tr><th scope="col">%s</th><th scope="col">%s</th><th scope="col" class="is-wide">%s</th></tr></thead><tbody>',
			esc_html__( 'Product', 'cjenik-i-sidrena-cijena' ),
			esc_html__( 'SKU', 'cjenik-i-sidrena-cijena' ),
			esc_html__( 'Published now as', 'cjenik-i-sidrena-cijena' )
		);
		foreach ( $unconfirmed['items'] as $entry ) {
			$item   = $entry['item'];
			$anchor = $entry['anchor'];
			$edit   = get_edit_post_link( $item->get_parent_id() ? $item->get_parent_id() : $item->get_id() );
			printf(
				'<tr><td class="is-text"><a class="cjenik-link" href="%s">%s</a></td><td>%s</td><td class="is-wide is-text">%s</td></tr>',
				esc_url( (string) $edit ),
				esc_html( wp_strip_all_tags( $item->get_name() ) ),
				'' === $item->get_sku() ? '<span class="cjenik-muted">—</span>' : esc_html( $item->get_sku() ),
				$anchor->has_price()
					? wp_kses_post( wc_price( (float) $anchor->price ) ) . ' <span class="cjenik-muted">' . esc_html( ( new \DateTimeImmutable( (string) $anchor->date ) )->format( 'j. n. Y.' ) ) . '</span>'
					: '<span class="cjenik-muted">' . esc_html__( 'current regular price, today\'s date', 'cjenik-i-sidrena-cijena' ) . '</span>'
			);
		}
		echo '</tbody></table></div></section>';
	}

	/**
	 * A CSV upload: drop the file on the zone or click it to choose one. The
	 * file input covers the zone, so both work without JavaScript; admin.js
	 * only shows the chosen file.
	 */
	private static function import( string $action, string $title, string $help ): void {
		printf(
			'<section class="cjenik-card"><header class="cjenik-card__header"><h2 class="cjenik-card__title">%s</h2></header><p class="cjenik-card__lede">%s</p>',
			esc_html( $title ),
			esc_html( $help )
		);
		printf(
			'<form class="cjenik-upload" method="post" enctype="multipart/form-data" action="%s">%s<input type="hidden" name="action" value="cjenik_%s">',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'cjenik_' . $action, '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core markup.
			esc_attr( $action )
		);
		printf(
			'<label class="cjenik-dropzone"><input class="cjenik-dropzone__input" type="file" name="cjenik_file" accept=".csv,text/csv" required>%s<span class="cjenik-dropzone__title">%s</span><span class="cjenik-dropzone__hint">%s</span></label>',
			AdminPages::icon( 'upload', 24 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
			esc_html__( 'Drop a CSV here or choose a file', 'cjenik-i-sidrena-cijena' ),
			esc_html(
				/* translators: %s: largest upload size, e.g. 10 MB */
				sprintf( __( 'CSV, up to %s.', 'cjenik-i-sidrena-cijena' ), size_format( wp_max_upload_size() ) )
			)
		);
		printf(
			'<div class="cjenik-file" hidden>%s<span class="cjenik-file__name"></span><span class="cjenik-file__size"></span><button type="button" class="cjenik-link is-muted" data-cjenik-remove>%s</button></div>',
			AdminPages::icon( 'file-small', 16 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
			esc_html__( 'Remove', 'cjenik-i-sidrena-cijena' )
		);
		printf( '<div class="cjenik-upload__actions"><button type="submit" class="cjenik-button" data-cjenik-busy>%s</button></div></form></section>', esc_html__( 'Import', 'cjenik-i-sidrena-cijena' ) );
	}
}

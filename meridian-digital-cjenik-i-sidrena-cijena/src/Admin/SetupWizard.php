<?php
/**
 * @package Cjenik
 */

namespace Cjenik\Admin;

use Cjenik\Publishing\Publication;

/**
 * A three-step setup after activation: the Outlet for the file name, where
 * brand, barcode and unit come from, then a first real publication with
 * links to check it.
 */
final class SetupWizard {
	public const SLUG = 'cjenik-setup';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_init', array( self::class, 'redirect_after_activation' ) );
		add_action( 'admin_post_cjenik_setup', array( self::class, 'save' ) );
	}

	public static function menu(): void {
		// Hidden page: reachable by URL, not in the menu.
		add_submenu_page( 'options.php', __( 'Price list setup', 'meridian-digital-cjenik-i-sidrena-cijena' ), '', AdminPages::CAPABILITY, self::SLUG, array( self::class, 'render' ) );
	}

	public static function url( int $step = 1 ): string {
		return add_query_arg(
			array(
				'page' => self::SLUG,
				'step' => $step,
			),
			admin_url( 'admin.php' )
		);
	}

	public static function redirect_after_activation(): void {
		if ( ! get_option( 'cjenik_needs_setup' ) || wp_doing_ajax() || ! current_user_can( AdminPages::CAPABILITY ) ) {
			return;
		}
		delete_option( 'cjenik_needs_setup' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads WordPress's bulk-activation flag.
		if ( cjenik()->settings()->get( 'wizard_done' ) || isset( $_GET['activate-multi'] ) ) {
			return;
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function render(): void {
		$step     = max( 1, min( 4, absint( $_GET['step'] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$settings = cjenik()->settings()->all();
		$titles   = array(
			1 => __( 'Your webshop', 'meridian-digital-cjenik-i-sidrena-cijena' ),
			2 => __( 'Your product data', 'meridian-digital-cjenik-i-sidrena-cijena' ),
			3 => __( 'First price list', 'meridian-digital-cjenik-i-sidrena-cijena' ),
			4 => __( 'Done', 'meridian-digital-cjenik-i-sidrena-cijena' ),
		);

		echo '<div class="wrap" style="max-width:48em"><h1>' . esc_html__( 'Set up the price list', 'meridian-digital-cjenik-i-sidrena-cijena' ) . '</h1>';
		/* translators: 1: step number, 2: step title */
		echo '<p>' . esc_html( sprintf( __( 'Step %1$d of 4: %2$s', 'meridian-digital-cjenik-i-sidrena-cijena' ), $step, $titles[ $step ] ) ) . '</p>';

		if ( 4 === $step ) {
			self::render_done();
			echo '</div>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'cjenik_setup' );
		echo '<input type="hidden" name="action" value="cjenik_setup"><input type="hidden" name="step" value="' . esc_attr( (string) $step ) . '">';

		if ( 1 === $step ) {
			echo '<p>' . esc_html__( 'The price list file name must name the outlet it belongs to. For a webshop these defaults are usually right.', 'meridian-digital-cjenik-i-sidrena-cijena' ) . '</p>';
			AdminPages::timezone_notice();
			echo '<table class="form-table"><tbody>';
			self::field( 'outlet_type', __( 'Outlet type', 'meridian-digital-cjenik-i-sidrena-cijena' ), $settings['outlet_type'] );
			self::field( 'outlet_address', __( 'Outlet address', 'meridian-digital-cjenik-i-sidrena-cijena' ), $settings['outlet_address'] );
			self::field( 'outlet_code', __( 'Outlet code', 'meridian-digital-cjenik-i-sidrena-cijena' ), $settings['outlet_code'] );
			echo '</tbody></table>';
			/* translators: %s: example file name */
			echo '<p class="description">' . esc_html( sprintf( __( 'The file will be named like: %s', 'meridian-digital-cjenik-i-sidrena-cijena' ), $settings['outlet_type'] . '_' . $settings['outlet_address'] . '_' . $settings['outlet_code'] . '_1_01.10.2026_05-00.csv' ) ) . '</p>';
		} elseif ( 2 === $step ) {
			echo '<table class="form-table"><tbody>';
			self::select( 'brand_source', __( 'Brand comes from', 'meridian-digital-cjenik-i-sidrena-cijena' ), $settings['brand_source'], AdminPages::brand_sources() );
			self::field( 'barcode_source', __( 'Barcode comes from', 'meridian-digital-cjenik-i-sidrena-cijena' ), $settings['barcode_source'], __( '"gtin" for the WooCommerce GTIN, UPC, EAN or ISBN field, or "meta:" and the meta key of your EAN plugin, e.g. "meta:_ean".', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
			self::select(
				'unit_source',
				__( 'Unit price', 'meridian-digital-cjenik-i-sidrena-cijena' ),
				$settings['unit_source'],
				array(
					'none'   => __( 'Not used', 'meridian-digital-cjenik-i-sidrena-cijena' ),
					'plugin' => __( 'Unit and net quantity fields on each product', 'meridian-digital-cjenik-i-sidrena-cijena' ),
					'meta'   => __( 'Meta keys from another plugin (set them in Settings)', 'meridian-digital-cjenik-i-sidrena-cijena' ),
				)
			);
			echo '</tbody></table>';
		} else {
			/* translators: %s: time, e.g. 05:00 */
			echo '<p>' . esc_html( sprintf( __( 'The price list will be published every day at %s Croatian time. Publish the first one now and check it.', 'meridian-digital-cjenik-i-sidrena-cijena' ), $settings['publish_time'] ) ) . '</p>';
		}

		echo '<p>';
		if ( $step > 1 ) {
			echo '<a class="button" href="' . esc_url( self::url( $step - 1 ) ) . '">' . esc_html__( 'Back', 'meridian-digital-cjenik-i-sidrena-cijena' ) . '</a> ';
		}
		echo '<button type="submit" class="button button-primary">' . esc_html( 3 === $step ? __( 'Publish now', 'meridian-digital-cjenik-i-sidrena-cijena' ) : __( 'Continue', 'meridian-digital-cjenik-i-sidrena-cijena' ) ) . '</button>';
		echo ' <a href="' . esc_url( AdminPages::url( 'settings' ) ) . '">' . esc_html__( 'Skip the setup', 'meridian-digital-cjenik-i-sidrena-cijena' ) . '</a></p>';
		echo '</form></div>';
	}

	public static function save(): void {
		if ( ! current_user_can( AdminPages::CAPABILITY ) ) {
			wp_die( '', 403 );
		}
		check_admin_referer( 'cjenik_setup' );
		$step   = absint( $_POST['step'] ?? 1 );
		$fields = array(
			1 => array( 'outlet_type', 'outlet_address', 'outlet_code' ),
			2 => array( 'brand_source', 'barcode_source', 'unit_source' ),
		);
		$values = array();
		foreach ( $fields[ $step ] ?? array() as $key ) {
			$values[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) );
		}
		if ( 3 === $step ) {
			$values['wizard_done'] = true;
			cjenik()->scheduler()->publish_all();
		}
		if ( $values ) {
			cjenik()->settings()->update( $values );
		}
		wp_safe_redirect( self::url( $step + 1 ) );
		exit;
	}

	private static function render_done(): void {
		$archive = cjenik()->archive();
		foreach ( cjenik()->outlets()->all() as $outlet ) {
			$latest = cjenik()->log()->last_attempt( $outlet->id );
			if ( $latest && ! $latest->succeeded() ) {
				/* translators: %s: error message */
				echo '<div class="notice notice-error inline"><p>' . esc_html( sprintf( __( 'Publishing failed: %s', 'meridian-digital-cjenik-i-sidrena-cijena' ), $latest->error ) ) . '</p></div>';
				continue;
			}
			if ( $latest instanceof Publication ) {
				/* translators: 1: file name, 2: number of rows */
				echo '<p>' . esc_html( sprintf( __( 'Published %1$s with %2$d rows.', 'meridian-digital-cjenik-i-sidrena-cijena' ), $latest->file_name, $latest->row_count ) ) . '</p>';
			}
			echo '<ul class="ul-disc">';
			echo '<li>' . esc_html__( 'Latest file, always:', 'meridian-digital-cjenik-i-sidrena-cijena' ) . ' <a href="' . esc_url( $archive->latest_url( $outlet ) ) . '" target="_blank">' . esc_html( $archive->latest_url( $outlet ) ) . '</a></li>';
			if ( $archive->page_url() ) {
				echo '<li>' . esc_html__( 'Archive page:', 'meridian-digital-cjenik-i-sidrena-cijena' ) . ' <a href="' . esc_url( $archive->page_url() ) . '" target="_blank">' . esc_html( $archive->page_url() ) . '</a></li>';
			}
			echo '</ul>';
		}
		echo '<p>' . esc_html__( 'Link the archive page from your site footer, next to your terms, so inspectors and price comparison sites find it.', 'meridian-digital-cjenik-i-sidrena-cijena' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( AdminPages::url( 'anchors' ) ) . '">' . esc_html__( 'Check the anchor prices', 'meridian-digital-cjenik-i-sidrena-cijena' ) . '</a> <a class="button" href="' . esc_url( AdminPages::url( 'status' ) ) . '">' . esc_html__( 'Go to the status page', 'meridian-digital-cjenik-i-sidrena-cijena' ) . '</a></p>';
	}

	private static function field( string $key, string $label, string $value, string $description = '' ): void {
		printf(
			'<tr><th scope="row"><label for="cjenik-%1$s">%2$s</label></th><td><input type="text" class="regular-text" id="cjenik-%1$s" name="%1$s" value="%3$s">%4$s</td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( $value ),
			'' !== $description ? '<p class="description">' . esc_html( $description ) . '</p>' : ''
		);
	}

	/**
	 * @param array<string, string> $options
	 */
	private static function select( string $key, string $label, string $value, array $options ): void {
		printf( '<tr><th scope="row"><label for="cjenik-%1$s">%2$s</label></th><td><select id="cjenik-%1$s" name="%1$s">', esc_attr( $key ), esc_html( $label ) );
		foreach ( $options as $option => $text ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $option ), selected( $value, $option, false ), esc_html( $text ) );
		}
		echo '</select></td></tr>';
	}
}

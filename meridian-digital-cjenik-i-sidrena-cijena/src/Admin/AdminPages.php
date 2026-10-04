<?php
/**
 * @package Cjenik
 */

namespace Cjenik\Admin;

use Cjenik\Import\ImportResult;
use Cjenik\PriceList\BuildReport;
use Cjenik\PriceList\Columns;
use Cjenik\Publishing\Publication;
use Cjenik\Settings;
use Cjenik\Zagreb;

/**
 * WooCommerce → Cjenik: status, settings, anchors and imports, and the
 * Publication Log.
 */
final class AdminPages {
	public const SLUG       = 'cjenik';
	public const CAPABILITY = 'manage_woocommerce';

	/** The markup the helpers below build, for wp_kses() where it is output. */
	public const ALLOWED_HTML = array(
		'a'      => array(
			'class'      => true,
			'href'       => true,
			'aria-label' => true,
		),
		'button' => array(
			'type'  => true,
			'class' => true,
		),
		'code'   => array(),
		'form'   => array(
			'method' => true,
			'action' => true,
			'style'  => true,
		),
		'img'    => array(
			'src'    => true,
			'width'  => true,
			'height' => true,
			'alt'    => true,
		),
		'input'  => array(
			'type'  => true,
			'id'    => true,
			'name'  => true,
			'value' => true,
		),
		'p'      => array(
			'class' => true,
			'id'    => true,
		),
		'span'   => array(
			'class'       => true,
			'title'       => true,
			'aria-hidden' => true,
		),
		'wbr'    => array(),
	);

	/** The screen's hook suffix, for loading the admin styles on it alone. */
	private static string $hook = '';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		foreach ( array( 'publish_now', 'self_check', 'save_settings', 'import_anchors', 'import_history' ) as $action ) {
			add_action( 'admin_post_cjenik_' . $action, array( self::class, $action ) );
		}
		add_filter( 'plugin_action_links_' . plugin_basename( CJENIK_FILE ), array( self::class, 'action_links' ) );
		add_action( 'after_plugin_row_' . plugin_basename( CJENIK_FILE ), array( self::class, 'uninstall_note' ), 10, 0 );
	}

	public static function menu(): void {
		self::$hook = (string) add_submenu_page(
			'woocommerce',
			__( 'Price list', 'meridian-digital-cjenik-i-sidrena-cijena' ),
			__( 'Price list (cjenik)', 'meridian-digital-cjenik-i-sidrena-cijena' ),
			self::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	public static function enqueue( string $hook ): void {
		if ( $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'cjenik-admin', plugins_url( 'assets/admin.css', CJENIK_FILE ), array(), CJENIK_VERSION );
		wp_enqueue_script( 'cjenik-admin', plugins_url( 'assets/admin.js', CJENIK_FILE ), array(), CJENIK_VERSION, array( 'in_footer' => true ) );
	}

	/** One of the design system's icons from assets/icons, decorative. */
	public static function icon( string $name, int $size ): string {
		return sprintf( '<img src="%1$s" width="%2$d" height="%2$d" alt="">', esc_url( plugins_url( 'assets/icons/' . $name . '.svg', CJENIK_FILE ) ), $size );
	}

	public static function url( string $tab = 'status' ): string {
		return add_query_arg(
			array(
				'page' => self::SLUG,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * @param array<string, string> $links
	 * @return array<string, string>
	 */
	public static function action_links( array $links ): array {
		return array_merge( array( 'settings' => '<a href="' . esc_url( self::url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'meridian-digital-cjenik-i-sidrena-cijena' ) . '</a>' ), $links );
	}

	/** Says on the Plugins screen what deleting the plugin does with the data. */
	public static function uninstall_note(): void {
		$delete = cjenik()->settings()->get( 'delete_data_on_uninstall' );
		$text   = $delete
			? __( 'Deleting this plugin will also delete the price history, anchors, publication log and published price lists.', 'meridian-digital-cjenik-i-sidrena-cijena' )
			: __( 'Deleting this plugin keeps the price history, anchors, publication log and published price lists.', 'meridian-digital-cjenik-i-sidrena-cijena' );
		printf(
			'<tr class="plugin-update-tr active"><td colspan="4" class="plugin-update colspanchange"><div class="notice inline notice-%1$s notice-alt"><p>%2$s <a href="%3$s">%4$s</a></p></div></td></tr>',
			$delete ? 'warning' : 'info',
			esc_html( $text ),
			esc_url( self::url( 'settings' ) . '#cjenik-uninstall' ),
			esc_html__( 'Change', 'meridian-digital-cjenik-i-sidrena-cijena' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$tab  = sanitize_key( $_GET['tab'] ?? 'status' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs = array(
			'status'   => array( __( 'Status', 'meridian-digital-cjenik-i-sidrena-cijena' ), array( self::class, 'render_status' ) ),
			'settings' => array( __( 'Settings', 'meridian-digital-cjenik-i-sidrena-cijena' ), array( SettingsPage::class, 'render' ) ),
			'anchors'  => array( __( 'Anchor prices', 'meridian-digital-cjenik-i-sidrena-cijena' ), array( AnchorsPage::class, 'render' ) ),
			'log'      => array( __( 'Publication log', 'meridian-digital-cjenik-i-sidrena-cijena' ), array( LogPage::class, 'render' ) ),
		);
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'status';

		echo '<div class="wrap cjenik-admin"><h1>' . esc_html__( 'Price list and anchor price', 'meridian-digital-cjenik-i-sidrena-cijena' ) . '</h1>';
		self::flash();
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => list( $label ) ) {
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( self::url( $key ) ), $key === $tab ? ' nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav>';
		call_user_func( $tabs[ $tab ][1] );
		echo '</div>';
	}

	private static function render_status(): void {
		$status  = cjenik()->status();
		$archive = cjenik()->archive();
		foreach ( cjenik()->outlets()->all() as $outlet ) {
			$today   = $status->today( $outlet );
			$problem = $status->problem( $outlet );
			echo '<h2>' . esc_html( sprintf( '%s — %s, %s', $outlet->type, $outlet->address, $outlet->code ) ) . '</h2>';
			if ( $problem ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( $problem ) . '</p></div>';
			}
			echo '<table class="widefat striped" style="max-width:60em"><tbody>';
			self::row(
				__( 'Today\'s price list', 'meridian-digital-cjenik-i-sidrena-cijena' ),
				$today
					? sprintf(
						/* translators: 1: time, 2: number of rows */
						esc_html__( 'Published at %1$s with %2$d rows.', 'meridian-digital-cjenik-i-sidrena-cijena' ),
						esc_html( Zagreb::local( $today->published_at )->format( 'H:i' ) ),
						$today->row_count
					) . ' <a href="' . esc_url( $today->url() ) . '">' . esc_html( $today->file_name ) . '</a>'
					: esc_html__( 'Not published yet today.', 'meridian-digital-cjenik-i-sidrena-cijena' )
			);
			if ( $today && $today->warnings ) {
				self::row( __( 'Needs attention', 'meridian-digital-cjenik-i-sidrena-cijena' ), self::warnings_html( $today ) );
			}
			self::row( __( 'Next scheduled run', 'meridian-digital-cjenik-i-sidrena-cijena' ), esc_html( Zagreb::local( $status->next_run() )->format( 'd.m.Y. H:i' ) ) . ' (Europe/Zagreb)' );
			self::row( __( 'Latest file, always', 'meridian-digital-cjenik-i-sidrena-cijena' ), self::link( $archive->latest_url( $outlet ) ) );
			$page = $archive->page_url();
			self::row( __( 'Archive page', 'meridian-digital-cjenik-i-sidrena-cijena' ), $page ? self::link( $page ) : esc_html__( 'None. Put the [cjenik_arhiva] shortcode on a published page.', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
			if ( cjenik()->settings()->get( 'json_index' ) ) {
				self::row( __( 'Archive index (JSON)', 'meridian-digital-cjenik-i-sidrena-cijena' ), self::link( $archive->index_url( $outlet ) ) );
			}
			echo '</tbody></table>';
		}

		echo '<p>';
		self::action_button( 'publish_now', __( 'Publish now', 'meridian-digital-cjenik-i-sidrena-cijena' ), 'primary' );
		echo ' ';
		self::action_button( 'self_check', __( 'Check public access', 'meridian-digital-cjenik-i-sidrena-cijena' ), 'secondary' );
		echo '</p>';

		$check = cjenik()->self_check()->last();
		if ( $check ) {
			echo '<h2>' . esc_html__( 'Public access check', 'meridian-digital-cjenik-i-sidrena-cijena' ) . '</h2>';
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: date and time */
					__( 'Last checked %s, as an anonymous visitor.', 'meridian-digital-cjenik-i-sidrena-cijena' ),
					Zagreb::local( Zagreb::from_db( $check['checked_at'] ) )->format( 'd.m.Y. H:i' )
				)
			) . '</p>';
			if ( $check['problems'] ) {
				echo '<ul class="ul-disc">';
				foreach ( $check['problems'] as $problem ) {
					echo '<li>' . esc_html( $problem ) . '</li>';
				}
				echo '</ul>';
			} else {
				echo '<p>' . esc_html__( 'Everything could be fetched without a login, redirect or bot challenge.', 'meridian-digital-cjenik-i-sidrena-cijena' ) . '</p>';
			}
		}

		echo '<h2>' . esc_html__( 'Publishing without site traffic', 'meridian-digital-cjenik-i-sidrena-cijena' ) . '</h2>';
		echo '<p>' . esc_html__( 'WordPress runs scheduled tasks only when someone visits the site. To publish on time on a quiet site, ask your host to add one of these system cron jobs:', 'meridian-digital-cjenik-i-sidrena-cijena' ) . '</p>';
		echo '<pre style="white-space:pre-wrap">';
		echo esc_html( '0,15,30,45 * * * * cd ' . untrailingslashit( ABSPATH ) . ' && wp cjenik watchdog --quiet' ) . "\n";
		echo esc_html( '* * * * * curl -s ' . site_url( 'wp-cron.php?doing_wp_cron' ) . ' > /dev/null' );
		echo '</pre>';
	}

	public static function publish_now(): void {
		self::verify( 'cjenik_publish_now' );
		$failed = array();
		foreach ( cjenik()->scheduler()->publish_all() as $publication ) {
			if ( ! $publication->succeeded() ) {
				$failed[] = $publication->error;
			}
		}
		self::redirect( 'status', $failed ? 'error' : 'success', $failed ? implode( ' ', $failed ) : __( 'The price list is published.', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
	}

	public static function self_check(): void {
		self::verify( 'cjenik_self_check' );
		cjenik()->self_check()->run();
		self::redirect( 'status', 'success', __( 'Public access checked.', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
	}

	public static function save_settings(): void {
		self::verify( 'cjenik_save_settings' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		$posted = wp_unslash( $_POST );
		$values = array();
		foreach ( array_keys( Settings::defaults() ) as $key ) {
			if ( in_array( $key, array( 'wizard_done', 'columns', 'fmcg_2025_categories' ), true ) ) {
				continue;
			}
			$values[ $key ] = $posted[ $key ] ?? '';
		}
		$values['fmcg_2025_categories'] = (array) ( $posted['fmcg_2025_categories'] ?? array() );
		$values['columns']              = self::posted_columns( (array) ( $posted['columns'] ?? array() ) );
		// phpcs:enable
		cjenik()->settings()->update( $values );
		self::redirect( 'settings', 'success', __( 'Settings saved.', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
	}

	public static function import_anchors(): void {
		self::verify( 'cjenik_import_anchors' );
		self::import_redirect( cjenik()->importer()->import_anchors( self::uploaded_file() ) );
	}

	public static function import_history(): void {
		self::verify( 'cjenik_import_history' );
		self::import_redirect( cjenik()->importer()->import_history( self::uploaded_file() ) );
	}

	public static function timezone_notice(): void {
		$zone = (string) get_option( 'timezone_string' );
		if ( '' !== $zone && 'UTC' !== $zone ) {
			return;
		}
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Your site timezone is a fixed UTC offset, not a city, so it doesn\'t follow summer time. That\'s fine: the plugin always uses Europe/Zagreb time for the 08:00 deadline and the file name. You can set the site timezone to "Zagreb" under Settings → General.', 'meridian-digital-cjenik-i-sidrena-cijena' ) . '</p></div>';
	}

	/**
	 * Brand sources: the brand taxonomy, other product taxonomies, and global attributes.
	 *
	 * @return array<string, string>
	 */
	public static function brand_sources(): array {
		$sources = array( 'none' => __( 'None', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
		foreach ( get_object_taxonomies( 'product', 'objects' ) as $taxonomy ) {
			if ( in_array( $taxonomy->name, array( 'product_type', 'product_visibility', 'product_shipping_class', 'product_cat' ), true ) || str_starts_with( $taxonomy->name, 'pa_' ) ) {
				continue;
			}
			/* translators: %s: taxonomy name */
			$sources[ 'taxonomy:' . $taxonomy->name ] = sprintf( __( 'Taxonomy: %s', 'meridian-digital-cjenik-i-sidrena-cijena' ), $taxonomy->label );
		}
		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			/* translators: %s: attribute name */
			$sources[ 'attribute:pa_' . $attribute->attribute_name ] = sprintf( __( 'Attribute: %s', 'meridian-digital-cjenik-i-sidrena-cijena' ), $attribute->attribute_label );
		}
		return $sources;
	}

	/**
	 * @param array<string, mixed> $posted
	 * @return list<array{key: string, label: string}>
	 */
	private static function posted_columns( array $posted ): array {
		$columns = array();
		foreach ( Columns::definitions() as $key => $definition ) {
			$row = (array) ( $posted[ $key ] ?? array() );
			if ( ! Columns::is_required( $key ) && empty( $row['include'] ) ) {
				continue;
			}
			$columns[] = array(
				'key'   => $key,
				'label' => (string) ( $row['label'] ?? '' ),
				'order' => (int) ( $row['order'] ?? 999 ),
			);
		}
		usort( $columns, static fn( $a, $b ) => $a['order'] <=> $b['order'] );
		return array_map(
			static fn( $column ) => array(
				'key'   => $column['key'],
				'label' => $column['label'],
			),
			$columns
		);
	}

	public static function warnings_html( Publication $publication ): string {
		$labels = array(
			BuildReport::NO_PRICE           => __( 'left out, no price', 'meridian-digital-cjenik-i-sidrena-cijena' ),
			BuildReport::NO_ANCHOR          => __( 'no anchor, today\'s regular price used', 'meridian-digital-cjenik-i-sidrena-cijena' ),
			BuildReport::UNCONFIRMED_ANCHOR => __( 'anchor not confirmed', 'meridian-digital-cjenik-i-sidrena-cijena' ),
			BuildReport::NO_BARCODE         => __( 'no barcode', 'meridian-digital-cjenik-i-sidrena-cijena' ),
			BuildReport::NO_SKU             => __( 'no SKU, product ID used', 'meridian-digital-cjenik-i-sidrena-cijena' ),
		);
		$parts  = array();
		foreach ( $publication->warnings as $kind => $warning ) {
			/* translators: 1: number of items, 2: what is wrong */
			$parts[] = esc_html( sprintf( _n( '%1$d item: %2$s', '%1$d items: %2$s', $warning['count'], 'meridian-digital-cjenik-i-sidrena-cijena' ), $warning['count'], $labels[ $kind ] ?? $kind ) );
		}
		$link = isset( $publication->warnings[ BuildReport::UNCONFIRMED_ANCHOR ] ) || isset( $publication->warnings[ BuildReport::NO_ANCHOR ] )
			? ' <a href="' . esc_url( self::url( 'anchors' ) ) . '">' . esc_html__( 'Fix anchors', 'meridian-digital-cjenik-i-sidrena-cijena' ) . '</a>'
			: '';
		return implode( '; ', $parts ) . $link;
	}

	private static function row( string $label, string $html ): void {
		echo '<tr><th scope="row" style="width:14em">' . esc_html( $label ) . '</th><td>' . wp_kses( $html, self::ALLOWED_HTML ) . '</td></tr>';
	}

	private static function link( string $url ): string {
		return '<a href="' . esc_url( $url ) . '"><code>' . esc_html( $url ) . '</code></a>';
	}

	private static function action_button( string $action, string $label, string $type ): void {
		echo wp_kses( self::action_form( $action, $label, 'button button-' . $type ), self::ALLOWED_HTML );
	}

	/** A button that posts one of the admin actions, with its nonce. */
	public static function action_form( string $action, string $label, string $css_class ): string {
		return sprintf(
			'<form method="post" action="%s" style="display:inline">%s<input type="hidden" name="action" value="cjenik_%s"><button type="submit" class="%s">%s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'cjenik_' . $action, '_wpnonce', true, false ),
			esc_attr( $action ),
			esc_attr( $css_class ),
			esc_html( $label )
		);
	}

	private static function uploaded_file(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by the callers; only tmp_name is used, after is_uploaded_file().
		$file = $_FILES['cjenik_file'] ?? null;
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			self::redirect( 'anchors', 'error', __( 'The file could not be uploaded.', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
		}
		return (string) $file['tmp_name'];
	}

	private static function import_redirect( ImportResult $result ): void {
		$message = sprintf(
			/* translators: %d: number of rows */
			_n( 'Imported %d row.', 'Imported %d rows.', $result->imported, 'meridian-digital-cjenik-i-sidrena-cijena' ),
			$result->imported
		);
		foreach ( array_slice( $result->errors, 0, 20, true ) as $line => $error ) {
			/* translators: 1: line number, 2: error */
			$message .= ' ' . sprintf( __( 'Line %1$d: %2$s', 'meridian-digital-cjenik-i-sidrena-cijena' ), $line, $error );
		}
		self::redirect( 'anchors', $result->errors ? 'warning' : 'success', $message );
	}

	private static function verify( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'meridian-digital-cjenik-i-sidrena-cijena' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * @return never
	 */
	private static function redirect( string $tab, string $type, string $message ) {
		set_transient( 'cjenik_flash_' . get_current_user_id(), array( $type, $message ), 60 );
		wp_safe_redirect( self::url( $tab ) );
		exit;
	}

	/**
	 * The message an action left for this user: a confirmation as a toast that
	 * fades on its own, a problem as a notice that stays.
	 */
	private static function flash(): void {
		$key   = 'cjenik_flash_' . get_current_user_id();
		$flash = get_transient( $key );
		if ( ! is_array( $flash ) ) {
			return;
		}
		delete_transient( $key );
		if ( 'success' === $flash[0] ) {
			printf(
				'<div class="cjenik-toast" role="status"><p>%s</p><button type="button" class="cjenik-toast__close" aria-label="%s">×</button></div>',
				esc_html( (string) $flash[1] ),
				esc_attr__( 'Dismiss', 'meridian-digital-cjenik-i-sidrena-cijena' )
			);
			return;
		}
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( (string) $flash[0] ), esc_html( (string) $flash[1] ) );
	}
}

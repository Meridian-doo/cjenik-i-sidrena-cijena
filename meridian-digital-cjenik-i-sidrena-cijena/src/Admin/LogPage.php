<?php
/**
 * @package Cjenik
 */

namespace Cjenik\Admin;

use Cjenik\Publishing\Publication;
use Cjenik\Zagreb;

/**
 * The Publication Log tab: every attempt as a row, newest first, with search,
 * a period filter and pagination. Styled by assets/admin.css. The Storage
 * Number has no column of its own: it is part of the file name.
 */
final class LogPage {
	private const PAGE_SIZE = 50;

	/** The period filter's choices other than "all", in days. */
	private const PERIOD_DAYS = array(
		'7d'  => 7,
		'30d' => 30,
	);

	public static function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$search = sanitize_text_field( wp_unslash( (string) ( $_GET['s'] ?? '' ) ) );
		$period = sanitize_key( (string) ( $_GET['period'] ?? '' ) );
		// phpcs:enable
		$periods = self::periods();
		$period  = isset( $periods[ $period ] ) ? $period : 'all';
		$since   = 'all' === $period ? null : cjenik()->clock()->now()->modify( '-' . self::PERIOD_DAYS[ $period ] . ' days' );
		$log     = cjenik()->log();
		$total   = $log->count( null, $search, $since );
		$paged   = min( $paged, max( 1, (int) ceil( $total / self::PAGE_SIZE ) ) );
		$entries = $log->entries( self::PAGE_SIZE, ( $paged - 1 ) * self::PAGE_SIZE, null, $search, $since );

		echo '<p>' . esc_html__( 'Every price list published, with its SHA-256 fingerprint. The log is kept after the files are removed.', 'meridian-digital-cjenik-i-sidrena-cijena' ) . '</p>';
		if ( 0 === $total && '' === $search && 'all' === $period ) {
			self::empty_state();
			return;
		}

		echo '<section class="cjenik-card cjenik-log">';
		self::header( $search, $period, $periods );
		if ( ! $entries ) {
			printf(
				'<p class="cjenik-card__message">%s <a href="%s">%s</a></p>',
				esc_html__( 'No publications match these filters.', 'meridian-digital-cjenik-i-sidrena-cijena' ),
				esc_url( AdminPages::url( 'log' ) ),
				esc_html__( 'Clear filters', 'meridian-digital-cjenik-i-sidrena-cijena' )
			);
		} else {
			self::table( $entries );
			self::pagination(
				$paged,
				$total,
				count( $entries ),
				array_filter(
					array(
						's'      => $search,
						'period' => 'all' === $period ? '' : $period,
					)
				)
			);
		}
		echo '</section>';
	}

	/**
	 * @return array<string, string>
	 */
	private static function periods(): array {
		return array(
			'all' => __( 'All publications', 'meridian-digital-cjenik-i-sidrena-cijena' ),
			'7d'  => __( 'Last 7 days', 'meridian-digital-cjenik-i-sidrena-cijena' ),
			'30d' => __( 'Last 30 days', 'meridian-digital-cjenik-i-sidrena-cijena' ),
		);
	}

	/**
	 * @param array<string, string> $periods
	 */
	private static function header( string $search, string $period, array $periods ): void {
		echo '<header class="cjenik-card__header"><h2 class="cjenik-card__title">' . esc_html__( 'Publication log', 'meridian-digital-cjenik-i-sidrena-cijena' ) . '</h2>';
		printf(
			'<form class="cjenik-filters" method="get" action="%s" role="search"><input type="hidden" name="page" value="%s"><input type="hidden" name="tab" value="log">',
			esc_url( admin_url( 'admin.php' ) ),
			esc_attr( AdminPages::SLUG )
		);
		printf(
			'<label class="cjenik-search">%s<span class="screen-reader-text">%s</span><input type="search" name="s" value="%s" placeholder="%s"></label>',
			wp_kses( AdminPages::icon( 'search', 16 ), AdminPages::ALLOWED_HTML ),
			esc_html__( 'Search files', 'meridian-digital-cjenik-i-sidrena-cijena' ),
			esc_attr( $search ),
			esc_attr__( 'Search files', 'meridian-digital-cjenik-i-sidrena-cijena' )
		);
		printf( '<label class="cjenik-chip"><span class="screen-reader-text">%s</span><select name="period" data-cjenik-autosubmit>', esc_html__( 'Period', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
		foreach ( $periods as $value => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $period, $value, false ), esc_html( $label ) );
		}
		printf( '</select></label><button type="submit" class="screen-reader-text">%s</button></form></header>', esc_html__( 'Filter', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
	}

	/**
	 * @param list<Publication> $entries
	 */
	private static function table( array $entries ): void {
		$show_outlet = count( cjenik()->outlets()->all() ) > 1;
		$headings    = array( array( __( 'Date', 'meridian-digital-cjenik-i-sidrena-cijena' ), '' ) );
		if ( $show_outlet ) {
			$headings[] = array( __( 'Outlet', 'meridian-digital-cjenik-i-sidrena-cijena' ), '' );
		}
		array_push(
			$headings,
			array( __( 'File', 'meridian-digital-cjenik-i-sidrena-cijena' ), 'is-file' ),
			array( __( 'Rows', 'meridian-digital-cjenik-i-sidrena-cijena' ), 'is-number' ),
			array( 'SHA-256', 'is-number' ),
			array( __( 'Status', 'meridian-digital-cjenik-i-sidrena-cijena' ), 'is-status' )
		);
		echo '<div class="cjenik-table-scroll"><table class="cjenik-table"><thead><tr>';
		foreach ( $headings as list( $label, $class ) ) {
			printf( '<th scope="col" class="%s">%s</th>', esc_attr( $class ), esc_html( $label ) );
		}
		echo '<th scope="col" class="is-action"><span class="screen-reader-text">' . esc_html__( 'Action', 'meridian-digital-cjenik-i-sidrena-cijena' ) . '</span></th></tr></thead><tbody>';
		$latest = array();
		foreach ( $entries as $publication ) {
			$latest[ $publication->outlet_id ] ??= cjenik()->log()->last_attempt( $publication->outlet_id )?->id;
			self::row( $publication, $show_outlet, $latest[ $publication->outlet_id ] === $publication->id );
		}
		echo '</tbody></table></div>';
	}

	private static function row( Publication $publication, bool $show_outlet, bool $latest_attempt ): void {
		$dash      = '<span class="cjenik-muted">—</span>';
		$published = Zagreb::local( $publication->published_at );
		$warned    = $publication->succeeded() && $publication->warnings;
		$note      = '';
		if ( ! $publication->succeeded() ) {
			$note = self::note( esc_html( $publication->error ), 'is-danger' );
		} elseif ( $warned ) {
			$note = self::note( AdminPages::warnings_html( $publication ), '' );
		}
		echo '<tr' . ( $warned ? ' class="is-warning"' : '' ) . '>';
		printf( '<td class="is-date">%s <span class="cjenik-muted">%s</span></td>', esc_html( $published->format( 'j. n. Y.' ) ), esc_html( $published->format( 'H:i' ) ) );
		if ( $show_outlet ) {
			printf( '<td>%s</td>', esc_html( $publication->outlet_id ) );
		}
		// The name may break after an underscore when the screen is narrow, never mid-part.
		$file = str_replace( '_', '_<wbr>', esc_html( $publication->file_name ) );
		printf( '<td class="is-file">%s%s</td>', wp_kses( '' === $publication->file_name ? $dash : $file, AdminPages::ALLOWED_HTML ), wp_kses( $note, AdminPages::ALLOWED_HTML ) );
		if ( $publication->succeeded() ) {
			// The design groups thousands with a space in every locale: 1 284.
			printf( '<td class="is-number">%s</td>', esc_html( number_format( $publication->row_count, 0, ',', "\u{a0}" ) ) );
			printf( '<td class="is-number cjenik-muted"><abbr title="%s">%s…%s</abbr></td>', esc_attr( $publication->sha256 ), esc_html( substr( $publication->sha256, 0, 4 ) ), esc_html( substr( $publication->sha256, -4 ) ) );
		} else {
			echo '<td class="is-number">' . wp_kses( $dash, AdminPages::ALLOWED_HTML ) . '</td><td class="is-number">' . wp_kses( $dash, AdminPages::ALLOWED_HTML ) . '</td>';
		}
		echo '<td class="is-status">' . wp_kses( self::status( $publication ), AdminPages::ALLOWED_HTML ) . '</td>';
		echo '<td class="is-action">' . wp_kses( self::action( $publication, $latest_attempt, $dash ), AdminPages::ALLOWED_HTML ) . '</td></tr>';
	}

	/** What went wrong, on one line under the file name, whole on hover. */
	private static function note( string $html, string $css_class ): string {
		return sprintf( ' <span class="cjenik-note %s" title="%s">%s</span>', esc_attr( $css_class ), esc_attr( wp_strip_all_tags( $html ) ), $html );
	}

	private static function status( Publication $publication ): string {
		if ( ! $publication->succeeded() ) {
			list( $tone, $label ) = array( 'danger', __( 'Failed', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
		} elseif ( ! $publication->is_available() ) {
			list( $tone, $label ) = array( 'neutral', __( 'Removed', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
		} elseif ( $publication->warnings ) {
			list( $tone, $label ) = array( 'warning', __( 'With warnings', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
		} else {
			list( $tone, $label ) = array( 'success', __( 'Published', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
		}
		return sprintf( '<span class="cjenik-pill is-%s">%s</span>', esc_attr( $tone ), esc_html( $label ) );
	}

	/** One action per row: download the file, or publish again after the latest attempt failed. */
	private static function action( Publication $publication, bool $latest_attempt, string $dash ): string {
		if ( $publication->is_available() ) {
			return sprintf( '<a class="cjenik-link" href="%s">%s</a>', esc_url( $publication->url() ), esc_html__( 'Download', 'meridian-digital-cjenik-i-sidrena-cijena' ) );
		}
		if ( ! $publication->succeeded() && $latest_attempt ) {
			return AdminPages::action_form( 'publish_now', __( 'Publish now', 'meridian-digital-cjenik-i-sidrena-cijena' ), 'cjenik-link is-accent' );
		}
		return $dash;
	}

	/**
	 * @param array<string, string> $filters
	 */
	private static function pagination( int $paged, int $total, int $shown, array $filters ): void {
		$first = ( $paged - 1 ) * self::PAGE_SIZE + 1;
		$last  = $first + $shown - 1;
		$base  = add_query_arg( $filters, AdminPages::url( 'log' ) );
		echo '<footer class="cjenik-card__footer"><p class="cjenik-muted">' . esc_html(
			/* translators: 1: first row shown, 2: last row shown, 3: number of rows */
			sprintf( __( '%1$s–%2$s of %3$s', 'meridian-digital-cjenik-i-sidrena-cijena' ), number_format_i18n( $first ), number_format_i18n( $last ), number_format_i18n( $total ) )
		) . '</p><nav class="cjenik-pager" aria-label="' . esc_attr__( 'Pages', 'meridian-digital-cjenik-i-sidrena-cijena' ) . '">';
		$links = array(
			array( $paged - 1, __( 'Previous page', 'meridian-digital-cjenik-i-sidrena-cijena' ), 'chevron-left', $paged > 1 ),
			array( $paged + 1, __( 'Next page', 'meridian-digital-cjenik-i-sidrena-cijena' ), 'chevron-right', $last < $total ),
		);
		foreach ( $links as list( $page, $label, $icon, $enabled ) ) {
			if ( $enabled ) {
				printf( '<a class="cjenik-icon-button" href="%s" aria-label="%s">%s</a>', esc_url( add_query_arg( 'paged', $page, $base ) ), esc_attr( $label ), wp_kses( AdminPages::icon( $icon, 16 ), AdminPages::ALLOWED_HTML ) );
			} else {
				printf( '<span class="cjenik-icon-button is-disabled" aria-hidden="true">%s</span>', wp_kses( AdminPages::icon( $icon, 16 ), AdminPages::ALLOWED_HTML ) );
			}
		}
		echo '</nav></footer>';
	}

	private static function empty_state(): void {
		echo '<section class="cjenik-empty"><span class="cjenik-empty__icon">' . wp_kses( AdminPages::icon( 'file', 24 ), AdminPages::ALLOWED_HTML ) . '</span>';
		echo '<h2 class="cjenik-empty__title">' . esc_html__( 'No price list published yet', 'meridian-digital-cjenik-i-sidrena-cijena' ) . '</h2>';
		echo '<p class="cjenik-empty__text">' . esc_html(
			sprintf(
				/* translators: %s: time of day, e.g. 05:00 */
				__( 'The price list is published every day at %s. You can also publish it now.', 'meridian-digital-cjenik-i-sidrena-cijena' ),
				(string) cjenik()->settings()->get( 'publish_time' )
			)
		) . '</p>';
		echo wp_kses( AdminPages::action_form( 'publish_now', __( 'Publish now', 'meridian-digital-cjenik-i-sidrena-cijena' ), 'cjenik-button' ), AdminPages::ALLOWED_HTML ) . '</section>';
	}
}

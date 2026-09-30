<?php
/**
 * @package Cjenik
 */

namespace Cjenik\Admin;

use Cjenik\Zagreb;

/**
 * A dismissible warning across the admin when today's Price List is missing
 * after its time, or the last run failed. Dismissing hides that problem for
 * the rest of the day.
 */
final class Notices {
	private const DISMISSED_META = 'cjenik_dismissed_notice';

	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
		add_action( 'admin_post_cjenik_dismiss_notice', array( self::class, 'dismiss' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( AdminPages::CAPABILITY ) ) {
			return;
		}
		$dismissed = (string) get_user_meta( get_current_user_id(), self::DISMISSED_META, true );
		foreach ( cjenik()->outlets()->all() as $outlet ) {
			$problem = cjenik()->status()->problem( $outlet );
			if ( null === $problem ) {
				continue;
			}
			$key = self::key( $problem );
			if ( $dismissed === $key ) {
				continue;
			}
			$dismiss = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'cjenik_dismiss_notice',
						'key'    => $key,
					),
					admin_url( 'admin-post.php' )
				),
				'cjenik_dismiss_notice'
			);
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p><p><a class="button button-primary" href="%s">%s</a> <a href="%s">%s</a></p></div>',
				esc_html__( 'Price list:', 'cjenik-i-sidrena-cijena' ),
				esc_html( $problem ),
				esc_url( AdminPages::url( 'status' ) ),
				esc_html__( 'See status', 'cjenik-i-sidrena-cijena' ),
				esc_url( $dismiss ),
				esc_html__( 'Dismiss for today', 'cjenik-i-sidrena-cijena' )
			);
		}
	}

	public static function dismiss(): void {
		if ( ! current_user_can( AdminPages::CAPABILITY ) ) {
			wp_die( '', 403 );
		}
		check_admin_referer( 'cjenik_dismiss_notice' );
		update_user_meta( get_current_user_id(), self::DISMISSED_META, sanitize_key( wp_unslash( $_GET['key'] ?? '' ) ) );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	private static function key( string $problem ): string {
		return Zagreb::date( cjenik()->clock()->now() ) . '-' . substr( md5( $problem ), 0, 8 );
	}
}

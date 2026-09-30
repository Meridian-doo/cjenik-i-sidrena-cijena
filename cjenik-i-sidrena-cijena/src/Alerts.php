<?php
/**
 * @package Cjenik
 */

namespace Cjenik;

use Cjenik\Publishing\Publication;
use DateTimeImmutable;

/**
 * Emails the site admin when a publication fails, and when today's file is
 * still missing at the check time. Each kind of alert goes out at most once
 * a day per Outlet.
 */
final class Alerts {
	private const SENT_OPTION = 'cjenik_alerts_sent';

	public function __construct(
		private Settings $settings,
		private Outlets $outlets,
		private Status $status,
		private Clock $clock,
	) {}

	public static function register(): void {
		add_action( 'cjenik_publication_failed', static fn( $publication, $outlet ) => cjenik()->alerts()->publication_failed( $publication, $outlet ), 10, 2 );
		add_action( 'cjenik_watchdog_checked', static fn( $now ) => cjenik()->alerts()->check_missing( $now ) );
	}

	public function publication_failed( Publication $publication, Outlet $outlet ): void {
		$this->send_once(
			$outlet,
			'failed',
			/* translators: %s: site name */
			sprintf( __( '[%s] The price list could not be published', 'cjenik-i-sidrena-cijena' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			/* translators: %s: error message */
			sprintf( __( 'Publishing the price list failed: %s', 'cjenik-i-sidrena-cijena' ), $publication->error ) . "\n\n"
				. __( 'The plugin will keep trying every 15 minutes.', 'cjenik-i-sidrena-cijena' )
		);
	}

	public function check_missing( DateTimeImmutable $now ): void {
		$today = Zagreb::date( $now );
		if ( $now < Zagreb::at( $today, (string) $this->settings->get( 'alert_time' ) ) ) {
			return;
		}
		foreach ( $this->outlets->all() as $outlet ) {
			if ( $this->status->today( $outlet ) ) {
				continue;
			}
			$this->send_once(
				$outlet,
				'missing',
				/* translators: %s: site name */
				sprintf( __( '[%s] Today\'s price list is missing', 'cjenik-i-sidrena-cijena' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				__( 'No price list has been published today yet. It must be online by 08:00.', 'cjenik-i-sidrena-cijena' ) . "\n\n"
					. ( $this->status->problem( $outlet ) ?? '' )
			);
		}
	}

	private function send_once( Outlet $outlet, string $kind, string $subject, string $message ): void {
		if ( ! $this->settings->get( 'alert_email' ) ) {
			return;
		}
		$today = Zagreb::date( $this->clock->now() );
		$sent  = (array) get_option( self::SENT_OPTION, array() );
		$key   = $outlet->id . ':' . $kind;
		if ( ( $sent[ $key ] ?? '' ) === $today ) {
			return;
		}
		$sent[ $key ] = $today;
		update_option( self::SENT_OPTION, $sent, false );

		$message .= "\n\n" . __( 'Status and publication log:', 'cjenik-i-sidrena-cijena' ) . ' ' . admin_url( 'admin.php?page=cjenik' );
		wp_mail( (string) get_option( 'admin_email' ), $subject, trim( $message ) );
	}
}

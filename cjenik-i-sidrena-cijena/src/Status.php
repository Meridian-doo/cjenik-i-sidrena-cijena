<?php
/**
 * @package Cjenik
 */

namespace Cjenik;

use Cjenik\Publishing\Publication;
use Cjenik\Publishing\PublicationLog;
use Cjenik\Publishing\Scheduler;
use DateTimeImmutable;

/**
 * Whether each Outlet's Price List is in order today, for the status panel,
 * admin notices and alerts.
 */
final class Status {
	public function __construct(
		private PublicationLog $log,
		private Scheduler $scheduler,
		private Clock $clock,
	) {}

	/** The Outlet's newest file published today (Zagreb date). */
	public function today( Outlet $outlet ): ?Publication {
		return $this->log->published_since( $outlet->id, Zagreb::start_of_day( Zagreb::date( $this->clock->now() ) ) );
	}

	public function next_run(): DateTimeImmutable {
		return $this->scheduler->next_run( $this->clock->now() );
	}

	/**
	 * What is wrong with the Outlet's publication right now, if anything.
	 */
	public function problem( Outlet $outlet ): ?string {
		$last = $this->log->last_attempt( $outlet->id );
		if ( $last && ! $last->succeeded() ) {
			/* translators: %s: error message */
			return sprintf( __( 'The last publication failed: %s', 'cjenik-i-sidrena-cijena' ), $last->error );
		}
		if ( $this->scheduler->is_missing_today( $outlet, $this->clock->now() ) ) {
			return sprintf(
				/* translators: %s: time, e.g. 05:00 */
				__( 'Today\'s price list has not been published yet. It was due at %s.', 'cjenik-i-sidrena-cijena' ),
				Zagreb::local( $this->scheduler->run_time_on( Zagreb::date( $this->clock->now() ) ) )->format( 'H:i' )
			);
		}
		return null;
	}
}

<?php
/**
 * @package Cjenik
 */

namespace Cjenik\Publishing;

use Cjenik\Clock;
use Cjenik\Outlet;
use Cjenik\Outlets;
use Cjenik\Prices\PriceHistory;
use Cjenik\Settings;
use Cjenik\Zagreb;
use DateTimeImmutable;

/**
 * Publishes every Outlet's Price List daily at a Europe/Zagreb wall-clock
 * time, and runs a watchdog that publishes whenever today's file is missing.
 *
 * The next run is computed as a Zagreb time and scheduled as one single
 * Action Scheduler action, re-scheduled after each run. A UTC cron expression
 * or a fixed offset would be an hour late in summer time.
 */
final class Scheduler {
	public const PUBLISH_HOOK  = 'cjenik_publish';
	public const WATCHDOG_HOOK = 'cjenik_watchdog';
	public const SNAPSHOT_HOOK = 'cjenik_snapshot';
	public const GROUP         = 'cjenik';

	public const WATCHDOG_INTERVAL = 15 * MINUTE_IN_SECONDS;

	/** The daily Price History snapshot runs just after midnight. */
	private const SNAPSHOT_TIME = '00:15';

	public function __construct(
		private Publisher $publisher,
		private PublicationLog $log,
		private Outlets $outlets,
		private PriceHistory $history,
		private Settings $settings,
		private Clock $clock,
	) {}

	public static function register(): void {
		add_action( self::PUBLISH_HOOK, static fn() => cjenik()->scheduler()->run_scheduled() );
		add_action( self::WATCHDOG_HOOK, static fn() => cjenik()->scheduler()->watchdog() );
		add_action( self::SNAPSHOT_HOOK, static fn() => cjenik()->scheduler()->run_snapshot() );
		add_action( 'cjenik_settings_updated', static fn() => cjenik()->scheduler()->reschedule() );
		add_action(
			'action_scheduler_init',
			static function (): void {
				if ( ! get_transient( 'cjenik_schedule_checked' ) ) {
					cjenik()->scheduler()->ensure_scheduled();
					set_transient( 'cjenik_schedule_checked', 1, HOUR_IN_SECONDS );
				}
			}
		);
	}

	/** Today's publication time for a Zagreb date, as a UTC moment. */
	public function run_time_on( string $zagreb_date ): DateTimeImmutable {
		return Zagreb::at( $zagreb_date, (string) $this->settings->get( 'publish_time' ) );
	}

	/** The first publication time after a moment. */
	public function next_run( ?DateTimeImmutable $after = null ): DateTimeImmutable {
		return $this->next_daily( (string) $this->settings->get( 'publish_time' ), $after ?? $this->clock->now() );
	}

	/** Makes sure the daily run, the snapshot and the watchdog are scheduled. */
	public function ensure_scheduled(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$now = $this->clock->now();
		if ( ! as_has_scheduled_action( self::PUBLISH_HOOK, array(), self::GROUP ) ) {
			as_schedule_single_action( $this->next_run( $now )->getTimestamp(), self::PUBLISH_HOOK, array(), self::GROUP, true );
		}
		if ( ! as_has_scheduled_action( self::SNAPSHOT_HOOK, array(), self::GROUP ) ) {
			as_schedule_single_action( $this->next_daily( self::SNAPSHOT_TIME, $now )->getTimestamp(), self::SNAPSHOT_HOOK, array(), self::GROUP, true );
		}
		if ( ! as_has_scheduled_action( self::WATCHDOG_HOOK, array(), self::GROUP ) ) {
			as_schedule_recurring_action( $now->getTimestamp() + self::WATCHDOG_INTERVAL, self::WATCHDOG_INTERVAL, self::WATCHDOG_HOOK, array(), self::GROUP, true );
		}
	}

	/** Moves the daily run after its time changes. */
	public function reschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::PUBLISH_HOOK, array(), self::GROUP );
		}
		$this->ensure_scheduled();
	}

	/** The daily run: publishes every Outlet, then schedules tomorrow's run. */
	public function run_scheduled(): void {
		try {
			$this->publish_all();
		} finally {
			$this->schedule_next( self::PUBLISH_HOOK, $this->next_run() );
		}
	}

	/**
	 * Publishes any Outlet whose file for today is missing once the
	 * publication time has passed, including after a failed run.
	 */
	public function watchdog(): void {
		$now = $this->clock->now();
		foreach ( $this->outlets->all() as $outlet ) {
			if ( $this->is_missing_today( $outlet, $now ) ) {
				$this->publisher->publish( $outlet, $now, Zagreb::start_of_day( Zagreb::date( $now ) ) );
			}
		}
		$this->ensure_scheduled();
		/**
		 * After each watchdog check.
		 */
		do_action( 'cjenik_watchdog_checked', $now );
	}

	/** Whether the publication time has passed today and no file was published today. */
	public function is_missing_today( Outlet $outlet, DateTimeImmutable $now ): bool {
		$today = Zagreb::date( $now );
		return $now >= $this->run_time_on( $today )
			&& null === $this->log->published_since( $outlet->id, Zagreb::start_of_day( $today ) );
	}

	public function run_snapshot(): void {
		try {
			$this->history->snapshot();
		} finally {
			$this->schedule_next( self::SNAPSHOT_HOOK, $this->next_daily( self::SNAPSHOT_TIME, $this->clock->now() ) );
		}
	}

	/**
	 * Publishes every Outlet now.
	 *
	 * @return list<Publication>
	 */
	public function publish_all(): array {
		$now          = $this->clock->now();
		$publications = array();
		foreach ( $this->outlets->all() as $outlet ) {
			$publications[] = $this->publisher->publish( $outlet, $now );
		}
		return $publications;
	}

	private function next_daily( string $time, DateTimeImmutable $after ): DateTimeImmutable {
		$today = Zagreb::date( $after );
		$next  = Zagreb::at( $today, $time );
		if ( $next <= $after ) {
			$next = Zagreb::at( Zagreb::local( $after )->modify( '+1 day' )->format( 'Y-m-d' ), $time );
		}
		return $next;
	}

	private function schedule_next( string $hook, DateTimeImmutable $at ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_unschedule_all_actions( $hook, array(), self::GROUP );
			// Not unique: the action that is running now would count as a duplicate.
			as_schedule_single_action( $at->getTimestamp(), $hook, array(), self::GROUP );
		}
	}
}

<?php
/**
 * @package Cjenik\Tests
 */

namespace Cjenik\Tests;

use Cjenik\Publishing\Scheduler;
use Cjenik\Zagreb;

/**
 * When the Price List gets published without anyone pressing a button.
 */
final class SchedulerTest extends PublicationTestCase {

	public function set_up(): void {
		parent::set_up();
		as_unschedule_all_actions( Scheduler::PUBLISH_HOOK );
		as_unschedule_all_actions( Scheduler::WATCHDOG_HOOK );
	}

	/**
	 * @dataProvider summer_time_changes
	 */
	public function test_the_daily_run_stays_at_05_00_zagreb_time_across_summer_time_changes( string $day_before, string $expected ): void {
		$this->clock->set( $day_before . ' 06:00' );

		cjenik()->scheduler()->ensure_scheduled();

		$this->assertSame( $expected, $this->next_publish_run() );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function summer_time_changes(): array {
		return array(
			'summer time ends'   => array( '2026-10-24', '2026-10-25 05:00 CET' ),
			'summer time starts' => array( '2027-03-28', '2027-03-29 05:00 CEST' ),
		);
	}

	public function test_the_scheduled_run_publishes_and_schedules_the_next_day(): void {
		$this->clock->set( '2026-10-25 05:00' );

		do_action( Scheduler::PUBLISH_HOOK );

		$this->assertSame( '2026-10-25 05:00', $this->latest_published_at() );
		$this->assertSame( '2026-10-26 05:00 CET', $this->next_publish_run() );
	}

	public function test_changing_the_publication_time_moves_the_next_run(): void {
		$this->clock->set( '2026-10-01 03:00' );
		cjenik()->scheduler()->ensure_scheduled();

		cjenik()->settings()->update( array( 'publish_time' => '04:30' ) );

		$this->assertSame( '2026-10-01 04:30 CEST', $this->next_publish_run() );
	}

	public function test_the_watchdog_publishes_when_todays_file_is_missing_after_the_scheduled_time(): void {
		$this->publish_at( '2026-09-30 05:00' );
		$this->clock->set( '2026-10-01 05:15' );

		do_action( Scheduler::WATCHDOG_HOOK );

		$this->assertSame( '2026-10-01 05:15', $this->latest_published_at() );
	}

	public function test_the_watchdog_waits_for_the_scheduled_time_and_does_nothing_once_todays_file_exists(): void {
		$this->publish_at( '2026-09-30 05:00' );
		$this->clock->set( '2026-10-01 04:45' );
		do_action( Scheduler::WATCHDOG_HOOK );
		$before_time = $this->latest_published_at();

		$this->publish_at( '2026-10-01 05:00' );
		$this->clock->set( '2026-10-01 05:15' );
		do_action( Scheduler::WATCHDOG_HOOK );

		$this->assertSame( array( '2026-09-30 05:00', '2026-10-01 05:00' ), array( $before_time, $this->latest_published_at() ) );
	}

	public function test_the_watchdog_retries_after_a_failed_run(): void {
		$this->clock->set( '2026-10-01 05:00' );
		$fail = static function () {
			throw new \RuntimeException( 'Disk full' );
		};
		add_filter( 'cjenik_price_list_row', $fail );
		\Cjenik\Tests\Support\Shop::simple(
			array(
				'name'    => 'Sok',
				'sku'     => 'HR-SOK',
				'regular' => '1.00',
			)
		);
		do_action( Scheduler::PUBLISH_HOOK );
		remove_filter( 'cjenik_price_list_row', $fail );

		$this->clock->set( '2026-10-01 05:15' );
		do_action( Scheduler::WATCHDOG_HOOK );

		$this->assertSame( 'Disk full', cjenik()->log()->entries()[1]->error );
		$this->assertSame( '2026-10-01 05:15', $this->latest_published_at() );
	}

	public function test_a_publication_still_running_elsewhere_is_not_logged_as_a_failure(): void {
		global $wpdb;
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->get_var( "SELECT GET_LOCK('cjenik_publish_webshop', 0)" );
		add_filter( 'cjenik_publish_lock_timeout', '__return_zero' );
		reset_phpmailer_instance();

		$attempt = $this->publish_at( '2026-10-01 05:15' );
		$other->get_var( "SELECT RELEASE_LOCK('cjenik_publish_webshop')" );

		$this->assertFalse( $attempt->succeeded() );
		$this->assertSame( array(), cjenik()->log()->entries() );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );
	}

	public function test_the_watchdog_runs_every_15_minutes(): void {
		cjenik()->scheduler()->ensure_scheduled();

		$actions = as_get_scheduled_actions( array( 'hook' => Scheduler::WATCHDOG_HOOK ), OBJECT );

		$this->assertSame( 900, reset( $actions )->get_schedule()->get_recurrence() );
	}

	public function test_action_scheduler_runs_the_due_publication(): void {
		$this->clock->set( '2026-09-01 04:00' ); // Before the real time, so the run is due.
		cjenik()->scheduler()->ensure_scheduled();
		$this->clock->set( '2026-09-01 05:00' );

		// Run what's due once. The whole queue would loop, as the frozen Clock
		// keeps the next run in the real past.
		$due = as_get_scheduled_actions(
			array(
				'hook'         => Scheduler::PUBLISH_HOOK,
				'status'       => \ActionScheduler_Store::STATUS_PENDING,
				'date'         => gmdate( 'Y-m-d H:i:s' ),
				'date_compare' => '<=',
			),
			'ids'
		);
		$this->assertCount( 1, $due );
		\ActionScheduler_QueueRunner::instance()->process_action( $due[0], 'Tests' );

		$this->assertSame( '2026-09-01 05:00', $this->latest_published_at() );
	}

	private function next_publish_run(): string {
		$timestamp = as_next_scheduled_action( Scheduler::PUBLISH_HOOK );
		$this->assertIsInt( $timestamp );
		return Zagreb::local( new \DateTimeImmutable( '@' . $timestamp ) )->format( 'Y-m-d H:i T' );
	}

	private function latest_published_at(): string {
		$latest = cjenik()->log()->latest( 'webshop' );
		return $latest ? Zagreb::local( $latest->published_at )->format( 'Y-m-d H:i' ) : '';
	}
}

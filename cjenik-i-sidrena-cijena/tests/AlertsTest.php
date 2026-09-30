<?php
/**
 * @package Cjenik\Tests
 */

namespace Cjenik\Tests;

use Cjenik\Publishing\Scheduler;
use Cjenik\Tests\Support\Shop;

/**
 * The site admin hears about a missing or failed Price List without logging in.
 */
final class AlertsTest extends PublicationTestCase {

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
	}

	public function test_a_failed_publication_emails_the_admin_once_a_day_with_the_reason(): void {
		Shop::simple(
			array(
				'name'    => 'Sok',
				'sku'     => 'HR-SOK',
				'regular' => '1.00',
			)
		);
		add_filter(
			'cjenik_price_list_row',
			static function () {
				throw new \RuntimeException( 'Disk full' );
			}
		);

		$this->publish_at( '2026-10-01 05:00' );
		$this->publish_at( '2026-10-01 05:15' );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertSame( 'admin@example.org', $mailer->get_recipient( 'to' )->address );
		$this->assertStringContainsString( 'Disk full', $mailer->get_sent()->body );
		$this->assertFalse( $mailer->get_sent( 1 ) );
	}

	public function test_the_admin_is_emailed_when_todays_file_is_still_missing_at_the_check_time(): void {
		Shop::simple(
			array(
				'name'    => 'Sok',
				'sku'     => 'HR-SOK',
				'regular' => '1.00',
			)
		);
		add_filter(
			'cjenik_price_list_row',
			static function () {
				throw new \RuntimeException( 'Disk full' );
			}
		);
		$this->clock->set( '2026-10-01 07:15' );
		do_action( Scheduler::WATCHDOG_HOOK );
		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertFalse( $mailer->get_sent( 1 ) );

		$this->clock->set( '2026-10-01 07:30' );
		do_action( Scheduler::WATCHDOG_HOOK );
		do_action( Scheduler::WATCHDOG_HOOK );

		$this->assertStringContainsString( 'http://example.org/wp-admin/admin.php?page=cjenik', $mailer->get_sent( 1 )->body );
		$this->assertFalse( $mailer->get_sent( 2 ) );
	}

	public function test_no_email_when_todays_file_exists(): void {
		$this->publish_at( '2026-10-01 05:00' );
		$this->clock->set( '2026-10-01 07:30' );

		do_action( Scheduler::WATCHDOG_HOOK );

		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );
	}

	public function test_emails_can_be_turned_off(): void {
		cjenik()->settings()->update( array( 'alert_email' => false ) );
		add_filter(
			'cjenik_price_list_row',
			static function () {
				throw new \RuntimeException( 'Disk full' );
			}
		);
		Shop::simple(
			array(
				'name'    => 'Sok',
				'sku'     => 'HR-SOK',
				'regular' => '1.00',
			)
		);

		$this->publish_at( '2026-10-01 05:00' );

		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );
	}
}

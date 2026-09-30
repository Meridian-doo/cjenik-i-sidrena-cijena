<?php
/**
 * @package Cjenik\Tests
 */

namespace Cjenik\Tests;

use Cjenik\Admin\AdminPages;
use Cjenik\Outlet;
use Cjenik\PriceList\BuildReport;
use Cjenik\Publishing\Publication;
use Cjenik\Tests\Support\AdminScreen;
use Cjenik\Zagreb;
use DateTimeImmutable;

/**
 * What a shop manager sees on WooCommerce → Price list → Publication log.
 */
final class PublicationLogPageTest extends ShopTestCase {
	use AdminScreen;

	private const SHA = 'a91f5b0c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e3c07';

	private int $storage_number = 0;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	public function test_each_publication_shows_its_date_file_rows_fingerprint_and_a_download(): void {
		$this->record(
			'2026-09-30 05:00',
			array(
				'file_name' => 'webshop_ilica-150_P-01_1_30092026_05-00.csv',
				'rows'      => 1284,
			)
		);

		$page = $this->admin_page( 'log' );

		$this->assertSame(
			array( '30. 9. 2026. 05:00', 'webshop_ilica-150_P-01_1_30092026_05-00.csv', "1\u{a0}284", 'a91f…3c07', 'Published', 'Download' ),
			$this->table_rows( $page )[0]
		);
		$this->assertSame( self::SHA, $page->evaluate( 'string(//tbody//abbr/@title)' ) );
		$this->assertStringEndsWith( '/webshop_ilica-150_P-01_1_30092026_05-00.csv', $page->evaluate( 'string(//tbody//a[.="Download"]/@href)' ) );
	}

	public function test_the_newest_publication_comes_first(): void {
		$this->record( '2026-09-29 05:00', array( 'file_name' => 'older.csv' ) );
		$this->record( '2026-09-30 05:00', array( 'file_name' => 'newer.csv' ) );

		$this->assertSame( array( 'newer.csv', 'older.csv' ), array_column( $this->table_rows( $this->admin_page( 'log' ) ), 1 ) );
	}

	public function test_a_failed_attempt_shows_its_error_and_dashes_for_what_it_never_produced(): void {
		$this->record(
			'2026-09-29 05:00',
			array(
				'status' => Publication::FAILED,
				'error'  => 'Disk full',
			)
		);
		$this->record(
			'2026-09-30 05:00',
			array(
				'status' => Publication::FAILED,
				'error'  => 'Disk still full',
			)
		);

		$rows = $this->table_rows( $this->admin_page( 'log' ) );

		$this->assertSame( array( '30. 9. 2026. 05:00', '— Disk still full', '—', '—', 'Failed', 'Publish now' ), $rows[0] );
		$this->assertSame( array( '29. 9. 2026. 05:00', '— Disk full', '—', '—', 'Failed', '—' ), $rows[1], 'Only the latest failure offers to publish again.' );
	}

	public function test_publishing_again_from_the_log_posts_the_publish_now_action(): void {
		$this->record(
			'2026-09-30 05:00',
			array(
				'status' => Publication::FAILED,
				'error'  => 'Disk full',
			)
		);

		$page = $this->admin_page( 'log' );

		$this->assertSame( 'cjenik_publish_now', $page->evaluate( 'string(//tbody//form[.//button[.="Publish now"]]/input[@name="action"]/@value)' ) );
		$this->assertNotSame( '', $page->evaluate( 'string(//tbody//form[.//button[.="Publish now"]]/input[@name="_wpnonce"]/@value)' ) );
	}

	public function test_a_file_removed_after_the_retention_period_stays_in_the_log_without_a_download(): void {
		$publication = $this->record( '2026-08-01 05:00', array( 'file_name' => 'old.csv' ) );
		cjenik()->log()->mark_deleted( $publication, new DateTimeImmutable( '2026-09-01', Zagreb::zone() ) );

		$row = $this->table_rows( $this->admin_page( 'log' ) )[0];

		$this->assertSame( array( 'old.csv', 'Removed', '—' ), array( $row[1], $row[4], $row[5] ) );
	}

	public function test_a_publication_with_warnings_is_highlighted_and_says_what_is_wrong(): void {
		$this->record(
			'2026-09-30 05:00',
			array(
				'file_name' => 'warned.csv',
				'warnings'  => array(
					BuildReport::NO_BARCODE => array(
						'count' => 2,
						'ids'   => array( 1, 2 ),
					),
				),
			)
		);

		$page = $this->admin_page( 'log' );
		$row  = $this->table_rows( $page )[0];

		$this->assertSame( array( 'warned.csv 2 items: no barcode', 'With warnings', 'Download' ), array( $row[1], $row[4], $row[5] ) );
		$this->assertSame( 1.0, $page->evaluate( 'count(//tbody/tr[contains(@class, "is-warning")])' ) );
	}

	public function test_the_outlet_column_appears_only_when_there_is_more_than_one_outlet(): void {
		$this->record( '2026-09-30 05:00' );
		$this->assertSame( 0.0, $this->admin_page( 'log' )->evaluate( 'count(//thead//th[.="Outlet"])' ) );

		add_filter( 'cjenik_outlets', static fn( array $outlets ) => $outlets + array( 'ilica' => new Outlet( 'ilica', 'prodavaonica', 'Ilica 1', 'P-02' ) ) );

		$this->assertSame( 1.0, $this->admin_page( 'log' )->evaluate( 'count(//thead//th[.="Outlet"])' ) );
	}

	public function test_searching_finds_publications_by_file_name(): void {
		$this->record( '2026-09-29 05:00', array( 'file_name' => 'webshop_29092026.csv' ) );
		$this->record( '2026-09-30 05:00', array( 'file_name' => 'webshop_30092026.csv' ) );

		$page = $this->admin_page( 'log', array( 's' => '2909' ) );

		$this->assertSame( array( 'webshop_29092026.csv' ), array_column( $this->table_rows( $page ), 1 ) );
		$this->assertSame( '2909', $page->evaluate( 'string(//input[@name="s"]/@value)' ) );
		$this->assertSame( '1–1 of 1', $this->summary( $page ) );
	}

	public function test_a_search_term_is_matched_literally(): void {
		$this->record( '2026-09-30 05:00', array( 'file_name' => 'webshop_30092026.csv' ) );

		$this->assertSame( array(), $this->table_rows( $this->admin_page( 'log', array( 's' => '%' ) ) ) );
	}

	public function test_the_period_filter_keeps_only_recent_publications(): void {
		$this->clock->set( '2026-09-30 12:00' );
		$this->record( '2026-09-20 05:00', array( 'file_name' => 'ten-days-ago.csv' ) );
		$this->record( '2026-09-28 05:00', array( 'file_name' => 'two-days-ago.csv' ) );

		$page = $this->admin_page( 'log', array( 'period' => '7d' ) );

		$this->assertSame( array( 'two-days-ago.csv' ), array_column( $this->table_rows( $page ), 1 ) );
		$this->assertSame( '7d', $page->evaluate( 'string(//select[@name="period"]/option[@selected]/@value)' ) );
	}

	public function test_when_nothing_matches_the_filters_can_be_cleared(): void {
		$this->record( '2026-09-30 05:00' );

		$page = $this->admin_page( 'log', array( 's' => 'nothing-like-this' ) );

		$this->assertSame( array(), $this->table_rows( $page ) );
		$this->assertStringContainsString( 'No publications match these filters.', $this->text( $page->document ) );
		$this->assertSame( AdminPages::url( 'log' ), $page->evaluate( 'string(//a[.="Clear filters"]/@href)' ) );
	}

	public function test_a_long_log_is_paged_fifty_at_a_time(): void {
		$this->record_daily( 60 );

		$first  = $this->admin_page( 'log' );
		$second = $this->admin_page( 'log', array( 'paged' => '2' ) );

		$this->assertCount( 50, $this->table_rows( $first ) );
		$this->assertSame( '1–50 of 60', $this->summary( $first ) );
		$this->assertStringContainsString( 'paged=2', $first->evaluate( 'string(//a[@aria-label="Next page"]/@href)' ) );
		$this->assertSame( 0.0, $first->evaluate( 'count(//a[@aria-label="Previous page"])' ) );
		$this->assertCount( 10, $this->table_rows( $second ) );
		$this->assertSame( '51–60 of 60', $this->summary( $second ) );
		$this->assertStringContainsString( 'paged=1', $second->evaluate( 'string(//a[@aria-label="Previous page"]/@href)' ) );
		$this->assertSame( 0.0, $second->evaluate( 'count(//a[@aria-label="Next page"])' ) );
	}

	public function test_a_page_past_the_end_shows_the_last_page(): void {
		$this->record_daily( 60 );

		$page = $this->admin_page( 'log', array( 'paged' => '9' ) );

		$this->assertSame( '51–60 of 60', $this->summary( $page ) );
	}

	public function test_paging_keeps_the_filters(): void {
		$this->record_daily( 60 );
		$this->clock->set( '2026-08-30 12:00' );

		$searched = $this->admin_page( 'log', array( 's' => 'webshop' ) );

		$this->assertStringContainsString( 's=webshop', $searched->evaluate( 'string(//a[@aria-label="Next page"]/@href)' ) );
		$this->assertSame( '1–30 of 30', $this->summary( $this->admin_page( 'log', array( 'period' => '30d' ) ) ) );
	}

	public function test_before_the_first_publication_the_log_offers_to_publish_now(): void {
		$page = $this->admin_page( 'log' );

		$this->assertSame( array(), $this->table_rows( $page ) );
		$this->assertSame( 'No price list published yet', $this->text( $page->query( '//*[contains(@class, "cjenik-empty")]//h2' )->item( 0 ) ) );
		$this->assertSame( 'cjenik_publish_now', $page->evaluate( 'string(//*[contains(@class, "cjenik-empty")]//form/input[@name="action"]/@value)' ) );
	}

	public function test_the_admin_styles_load_on_the_price_list_screen_only(): void {
		AdminPages::menu();

		AdminPages::enqueue( 'edit.php' );
		$this->assertFalse( wp_style_is( 'cjenik-admin' ) );

		AdminPages::enqueue( get_plugin_page_hookname( AdminPages::SLUG, 'woocommerce' ) );
		$this->assertTrue( wp_style_is( 'cjenik-admin' ) );
		$this->assertTrue( wp_script_is( 'cjenik-admin' ) );
	}

	/** One successful publication a day at 05:00, from 2 July 2026. */
	private function record_daily( int $days ): void {
		for ( $day = 1; $day <= $days; $day++ ) {
			$this->record( '2026-07-01 05:00 +' . $day . ' days' );
		}
	}

	private function summary( \DOMXPath $page ): string {
		return $this->text( $page->query( '//footer[contains(@class, "cjenik-card__footer")]/p' )->item( 0 ) );
	}

	/**
	 * @param array{file_name?: string, rows?: int, status?: string, error?: string, warnings?: array<string, array{count: int, ids: list<int>}>} $values
	 */
	private function record( string $zagreb_time, array $values = array() ): Publication {
		$storage_number = ++$this->storage_number;
		$status         = $values['status'] ?? Publication::SUCCESS;
		return cjenik()->log()->record(
			'webshop',
			$storage_number,
			new DateTimeImmutable( $zagreb_time, Zagreb::zone() ),
			$status,
			$values['file_name'] ?? ( Publication::SUCCESS === $status ? 'webshop_' . $storage_number . '.csv' : '' ),
			'csv',
			$values['rows'] ?? ( Publication::SUCCESS === $status ? 10 : 0 ),
			Publication::SUCCESS === $status ? self::SHA : '',
			$values['warnings'] ?? array(),
			$values['error'] ?? ''
		);
	}
}

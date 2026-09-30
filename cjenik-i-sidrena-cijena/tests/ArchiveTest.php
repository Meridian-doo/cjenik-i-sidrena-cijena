<?php
/**
 * @package Cjenik\Tests
 */

namespace Cjenik\Tests;

use Cjenik\Archive\Response;
use Cjenik\Publishing\Publication;
use Cjenik\Tests\Support\Shop;

/**
 * What an inspector or aggregator gets from the public URLs.
 */
final class ArchiveTest extends PublicationTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		\Cjenik\Archive\Archive::add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	public function test_the_latest_url_serves_the_newest_file_under_its_legal_name(): void {
		Shop::simple(
			array(
				'name'    => 'Sok',
				'sku'     => 'HR-SOK',
				'regular' => '1.00',
			)
		);
		$this->publish_at( '2026-10-01 05:00' );
		$newest = $this->publish_at( '2026-10-02 05:00' );

		$response = $this->get( cjenik()->archive()->latest_url( cjenik()->outlets()->webshop() ) );

		$this->assertSame( 'http://example.org/cjenik/webshop.csv', cjenik()->archive()->latest_url( cjenik()->outlets()->webshop() ) );
		$this->assertSame( 200, $response->status );
		$this->assertSame( file_get_contents( $newest->path() ), $response->body() );
		$this->assertSame( 'text/csv; charset=utf-8', $response->headers['Content-Type'] );
		$this->assertStringContainsString( "filename*=UTF-8''" . rawurlencode( $newest->file_name ), $response->headers['Content-Disposition'] );
	}

	public function test_the_latest_url_is_a_404_before_the_first_publication(): void {
		$this->assertSame( 404, $this->get( 'http://example.org/cjenik/webshop.csv' )->status );
	}

	public function test_any_archived_file_can_be_downloaded_by_storage_number(): void {
		$first = $this->publish_at( '2026-10-01 05:00' );
		$this->publish_at( '2026-10-02 05:00' );

		$response = $this->get( 'http://example.org/cjenik/webshop/1/' );

		$this->assertSame( file_get_contents( $first->path() ), $response->body() );
	}

	public function test_the_latest_url_never_shows_a_file_that_is_still_being_written(): void {
		Shop::simple(
			array(
				'name'    => 'Sok',
				'sku'     => 'HR-SOK',
				'regular' => '1.00',
			)
		);
		Shop::simple(
			array(
				'name'    => 'Voda',
				'sku'     => 'HR-VODA',
				'regular' => '1.00',
			)
		);
		$previous = $this->publish_at( '2026-10-01 05:00' );
		$seen     = array();
		add_filter(
			'cjenik_price_list_row',
			function ( $row ) use ( &$seen ) {
				$seen[] = $this->get( 'http://example.org/cjenik/webshop.csv' )->body();
				return $row;
			}
		);

		$this->publish_at( '2026-10-02 05:00' );

		$this->assertSame( array( file_get_contents( $previous->path() ), file_get_contents( $previous->path() ) ), $seen );
	}

	public function test_files_older_than_the_retention_period_are_removed_and_the_archive_lists_exactly_what_exists(): void {
		cjenik()->settings()->update( array( 'json_index' => true ) );
		$day_0  = $this->publish_at( '2026-10-01 05:00' );
		$day_1  = $this->publish_at( '2026-10-02 05:00' );
		$day_36 = $this->publish_at( '2026-11-06 05:00' );

		$this->assertFileDoesNotExist( $day_0->path() );
		$this->assertFileExists( $day_1->path() );
		$this->assertFileExists( $day_36->path() );
		$this->assertSame( array( $day_36->url(), $day_1->url() ), $this->archive_links() );
		$this->assertSame( array( $day_36->url(), $day_1->url() ), array_column( $this->index(), 'url' ) );
		$this->assertSame( 404, $this->get( 'http://example.org/cjenik/webshop/1/' )->status );
	}

	public function test_retention_can_be_longer_but_never_shorter_than_31_days(): void {
		cjenik()->settings()->update( array( 'retention_days' => 10 ) );
		$day_0 = $this->publish_at( '2026-10-01 05:00' );
		$this->publish_at( '2026-10-31 05:00' ); // 30 days later.
		$this->publish_at( '2026-11-01 04:59' ); // 31 days less a minute.

		$this->assertSame( 31, cjenik()->settings()->retention_days() );
		$this->assertFileExists( $day_0->path() );
	}

	public function test_the_archive_shows_each_files_time_storage_number_and_link(): void {
		$publication = $this->publish_at( '2026-10-01 05:07' );

		$html = do_shortcode( '[cjenik_arhiva]' );
		$text = wp_strip_all_tags( $html );

		$this->assertStringContainsString( '01.10.2026. 05:07', $text );
		$this->assertStringContainsString( 'Published on 01.10.2026. at 05:07', $text );
		$this->assertMatchesRegularExpression( '~<td[^>]*>1</td>~', $html );
		$this->assertStringContainsString( 'href="' . esc_url( $publication->url() ) . '"', $html );
		$this->assertStringContainsString( $publication->file_name, $text );
		$this->assertStringContainsString( 'http://example.org/cjenik/webshop.csv', $html );
	}

	public function test_an_admin_whose_language_is_croatian_sees_the_archive_in_croatian(): void {
		$admin = self::factory()->user->create(
			array(
				'role'   => 'administrator',
				'locale' => 'hr',
			)
		);
		wp_set_current_user( $admin );
		set_current_screen( 'dashboard' );
		// A real request starts in the admin's language; this one switched mid-way.
		unload_textdomain( 'cjenik-i-sidrena-cijena', true );

		$html = do_shortcode( '[cjenik_arhiva]' );
		wp_set_current_user( 0 );
		set_current_screen( 'front' );
		unload_textdomain( 'cjenik-i-sidrena-cijena', true );

		$this->assertStringContainsString( 'Još nije objavljen nijedan cjenik.', $html );
	}

	public function test_the_json_index_is_off_unless_enabled(): void {
		$this->publish_at( '2026-10-01 05:00' );

		$this->assertSame( 404, $this->get( 'http://example.org/cjenik/webshop/index.json' )->status );
	}

	public function test_the_json_index_describes_each_archived_file(): void {
		cjenik()->settings()->update( array( 'json_index' => true ) );
		$publication = $this->publish_at( '2026-10-01 05:00' );

		$this->assertSame(
			array(
				array(
					'broj_pohrane' => 1,
					'objavljeno'   => '2026-10-01T05:00:00+02:00',
					'naziv'        => $publication->file_name,
					'url'          => $publication->url(),
					'redaka'       => 0,
					'sha256'       => $publication->sha256,
				),
			),
			$this->index()
		);
	}

	private function get( string $url ): Response {
		$this->go_to( $url );
		return cjenik()->archive()->respond( $GLOBALS['wp']->query_vars ) ?? new Response( 0 );
	}

	/**
	 * @return list<string>
	 */
	private function archive_links(): array {
		preg_match_all( '/<tbody>(.*)<\/tbody>/s', do_shortcode( '[cjenik_arhiva]' ), $table );
		preg_match_all( '/<a href="([^"]+)"/', $table[1][0] ?? '', $matches );
		return array_map( 'html_entity_decode', $matches[1] );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function index(): array {
		return json_decode( $this->get( 'http://example.org/cjenik/webshop/index.json' )->body(), true )['datoteke'];
	}
}

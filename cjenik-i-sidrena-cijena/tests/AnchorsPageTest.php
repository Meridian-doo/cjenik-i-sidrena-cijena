<?php
/**
 * @package Cjenik\Tests
 */

namespace Cjenik\Tests;

use Cjenik\Tests\Support\AdminScreen;
use Cjenik\Tests\Support\Shop;

/**
 * What a shop manager sees on WooCommerce → Price list → Anchor prices.
 */
final class AnchorsPageTest extends ShopTestCase {
	use AdminScreen;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	public function test_items_without_a_confirmed_anchor_are_listed_with_a_link_to_fix_them(): void {
		$product = Shop::simple(
			array(
				'name'    => 'Sok',
				'sku'     => 'HR-SOK',
				'regular' => '2.00',
				'created' => '2025-01-01 09:00',
			)
		);
		cjenik()->history()->forget( $product->get_id() );

		$page = $this->admin_page( 'anchors' );

		$this->assertSame( array( 'Sok', 'HR-SOK' ), array_slice( $this->table_rows( $page )[0], 0, 2 ) );
		$this->assertSame( get_edit_post_link( $product->get_id(), 'raw' ), $page->evaluate( 'string(//table[contains(@class, "cjenik-table")]//a[.="Sok"]/@href)' ) );
		$this->assertSame( '1 item', $this->text( $page->query( '//*[contains(@class, "cjenik-card__header")]//*[contains(@class, "cjenik-pill")]' )->item( 0 ) ) );
	}

	public function test_when_every_anchor_is_confirmed_the_page_says_so(): void {
		$product = Shop::simple(
			array(
				'name'    => 'Sok',
				'sku'     => 'HR-SOK',
				'regular' => '2.00',
			)
		);
		cjenik()->anchors()->set( $product->get_id(), 2.00, '2026-09-10', 'manual' );

		$page = $this->admin_page( 'anchors' );

		$this->assertSame( array(), $this->table_rows( $page ) );
		$this->assertStringContainsString( 'Every item has a confirmed anchor.', $this->text( $page->document ) );
	}

	/**
	 * @dataProvider imports
	 */
	public function test_each_import_uploads_a_csv_to_its_action( string $action ): void {
		$page = $this->admin_page( 'anchors' );
		$form = '//form[input[@name="action"][@value="' . $action . '"]]';

		$this->assertSame( 'multipart/form-data', $page->evaluate( "string($form/@enctype)" ) );
		$this->assertSame( '.csv,text/csv', $page->evaluate( "string($form//input[@type=\"file\"][@name=\"cjenik_file\"]/@accept)" ) );
		$this->assertNotSame( '', $page->evaluate( "string($form/input[@name=\"_wpnonce\"]/@value)" ) );
		$this->assertSame( 1.0, $page->evaluate( "count($form//label[contains(@class, \"cjenik-dropzone\")][.//input[@type=\"file\"]])" ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function imports(): array {
		return array(
			'anchor prices' => array( 'cjenik_import_anchors' ),
			'past prices'   => array( 'cjenik_import_history' ),
		);
	}
}

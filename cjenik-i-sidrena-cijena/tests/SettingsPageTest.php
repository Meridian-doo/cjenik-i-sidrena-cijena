<?php
/**
 * @package Cjenik\Tests
 */

namespace Cjenik\Tests;

use Cjenik\Settings;
use Cjenik\Tests\Support\AdminScreen;

/**
 * What a shop manager sees on WooCommerce → Price list → Settings.
 */
final class SettingsPageTest extends ShopTestCase {
	use AdminScreen;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	public function test_every_setting_has_a_field_in_the_form_that_saves_them(): void {
		wp_insert_term( 'Hrana', 'product_cat' );

		$page = $this->admin_page( 'settings' );

		$names = array();
		foreach ( $page->query( '//form[input[@name="action"][@value="cjenik_save_settings"]]//*[@name]' ) as $field ) {
			$names[] = (string) preg_replace( '/\[.*$/', '', $field->getAttribute( 'name' ) );
		}
		$settings = array_diff( array_keys( Settings::defaults() ), array( 'wizard_done' ) );

		$this->assertSame( array(), array_values( array_diff( $settings, $names ) ) );
		$this->assertNotSame( '', $page->evaluate( 'string(//form[input[@name="action"][@value="cjenik_save_settings"]]/input[@name="_wpnonce"]/@value)' ) );
	}

	public function test_each_section_is_a_card_with_its_heading(): void {
		$page = $this->admin_page( 'settings' );

		$headings = array();
		foreach ( $page->query( '//section[contains(@class, "cjenik-card")]//h2' ) as $heading ) {
			$headings[] = $this->text( $heading );
		}

		$this->assertSame( array( 'Outlet', 'Publication', 'Columns', 'Where the data comes from', 'Shop display', 'Alerts', 'When the plugin is deleted' ), $headings );
	}

	public function test_a_field_has_its_label_above_and_its_help_linked_to_it(): void {
		$page = $this->admin_page( 'settings' );

		$input = $page->query( '//input[@name="outlet_code"]' )->item( 0 );
		$this->assertSame( 'Outlet code', $this->text( $page->query( '//label[@for="' . $input->getAttribute( 'id' ) . '"]' )->item( 0 ) ) );
		$this->assertSame( 'Third part of the file name, e.g. "P-01".', $this->text( $page->query( '//*[@id="' . $input->getAttribute( 'aria-describedby' ) . '"]' )->item( 0 ) ) );
	}

	public function test_on_off_settings_are_switches_showing_the_saved_value(): void {
		cjenik()->settings()->update(
			array(
				'csv_bom'    => '1',
				'json_index' => '',
			)
		);

		$page = $this->admin_page( 'settings' );

		$this->assertSame( 'switch', $page->evaluate( 'string(//input[@name="csv_bom"]/@role)' ) );
		$this->assertSame( 1.0, $page->evaluate( 'count(//input[@name="csv_bom"][@checked])' ) );
		$this->assertSame( 0.0, $page->evaluate( 'count(//input[@name="json_index"][@checked])' ) );
	}

	public function test_single_choice_settings_are_radio_buttons(): void {
		cjenik()->settings()->update( array( 'format' => 'xml' ) );

		$page = $this->admin_page( 'settings' );

		$this->assertSame( 'xml', $page->evaluate( 'string(//input[@type="radio"][@name="format"][@checked]/@value)' ) );
		$this->assertSame( 2.0, $page->evaluate( 'count(//input[@type="radio"][@name="format"])' ) );
		$this->assertSame( '0', $page->evaluate( 'string(//input[@type="radio"][@name="delete_data_on_uninstall"][@checked]/@value)' ) );
	}

	public function test_required_columns_cannot_be_left_out(): void {
		$page = $this->admin_page( 'settings' );

		$this->assertSame( 1.0, $page->evaluate( 'count(//input[@name="columns[naziv][include]"][@disabled][@checked])' ) );
		$this->assertSame( 0.0, $page->evaluate( 'count(//input[@name="columns[kategorija][include]"][@disabled])' ) );
	}

	public function test_a_confirmation_appears_as_a_toast_and_a_problem_as_a_notice(): void {
		set_transient( 'cjenik_flash_' . get_current_user_id(), array( 'success', 'Settings saved.' ), 60 );
		$saved = $this->admin_page( 'settings' );
		set_transient( 'cjenik_flash_' . get_current_user_id(), array( 'error', 'The file could not be uploaded.' ), 60 );
		$failed = $this->admin_page( 'settings' );

		$this->assertSame( 'Settings saved.', $this->text( $saved->query( '//*[contains(@class, "cjenik-toast")][@role="status"]/p' )->item( 0 ) ) );
		$this->assertSame( 'The file could not be uploaded.', $this->text( $failed->query( '//div[contains(@class, "notice-error")]' )->item( 0 ) ) );
		$this->assertSame( 0.0, $failed->evaluate( 'count(//*[contains(@class, "cjenik-toast")])' ) );
	}
}

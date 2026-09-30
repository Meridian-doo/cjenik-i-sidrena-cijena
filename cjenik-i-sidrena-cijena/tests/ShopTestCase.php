<?php
/**
 * @package Cjenik\Tests
 */

namespace Cjenik\Tests;

use Cjenik\SystemClock;
use Cjenik\Tests\Support\FixedClock;
use Cjenik\Tests\Support\Shop;

/**
 * A Croatian shop with a Clock the test controls. Published files go to a
 * throwaway uploads folder.
 */
abstract class ShopTestCase extends \WP_UnitTestCase {
	protected FixedClock $clock;

	public function set_up(): void {
		parent::set_up();
		$this->clock = new FixedClock( '2026-09-01 12:00' );
		cjenik()->use_clock( $this->clock );
		Shop::configure();
		self::remove_uploads();
	}

	public function tear_down(): void {
		cjenik()->use_clock( new SystemClock() );
		self::remove_uploads();
		parent::tear_down();
	}

	private static function remove_uploads(): void {
		$dir = sys_get_temp_dir() . '/cjenik-tests-uploads';
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
	}
}

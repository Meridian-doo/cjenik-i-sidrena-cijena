<?php
/**
 * @package Cjenik\Tests
 */

namespace Cjenik\Tests\Support;

use Cjenik\Clock;
use Cjenik\Zagreb;
use DateTimeImmutable;

/**
 * A clock the tests set and advance. Times are given as Europe/Zagreb wall-clock.
 */
final class FixedClock implements Clock {
	private DateTimeImmutable $now;

	public function __construct( string $zagreb_time ) {
		$this->set( $zagreb_time );
	}

	public function now(): DateTimeImmutable {
		return $this->now;
	}

	public function set( string $zagreb_time ): void {
		$this->now = ( new DateTimeImmutable( $zagreb_time, Zagreb::zone() ) )->setTimezone( Zagreb::utc() );
	}

	public function advance( string $modifier ): void {
		$this->now = $this->now->modify( $modifier );
	}
}

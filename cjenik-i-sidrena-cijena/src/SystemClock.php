<?php
/**
 * @package Cjenik
 */

namespace Cjenik;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The real Clock, in UTC.
 */
final class SystemClock implements Clock {
	public function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}
}

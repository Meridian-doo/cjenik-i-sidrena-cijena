<?php
/**
 * @package Cjenik
 */

namespace Cjenik;

use DateTimeImmutable;

/**
 * Where the plugin gets the current time. Everything time-dependent (history
 * timestamps, schedules, retention) asks the Clock, so tests can move time.
 */
interface Clock {
	/** The current moment, in UTC. */
	public function now(): DateTimeImmutable;
}

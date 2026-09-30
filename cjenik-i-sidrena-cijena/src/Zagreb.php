<?php
/**
 * @package Cjenik
 */

namespace Cjenik;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Legal deadlines and file names use Croatian local time, whatever the site
 * timezone is. The site timezone is only for display.
 */
final class Zagreb {
	public static function zone(): DateTimeZone {
		return new DateTimeZone( 'Europe/Zagreb' );
	}

	public static function utc(): DateTimeZone {
		return new DateTimeZone( 'UTC' );
	}

	public static function local( DateTimeInterface $moment ): DateTimeImmutable {
		return DateTimeImmutable::createFromInterface( $moment )->setTimezone( self::zone() );
	}

	/** The Zagreb calendar date of a moment, as Y-m-d. */
	public static function date( DateTimeInterface $moment ): string {
		return self::local( $moment )->format( 'Y-m-d' );
	}

	/**
	 * A Zagreb wall-clock date and time ("2026-10-25", "05:00") as a UTC moment.
	 */
	public static function at( string $date, string $time ): DateTimeImmutable {
		return ( new DateTimeImmutable( $date . ' ' . $time, self::zone() ) )->setTimezone( self::utc() );
	}

	/** The first moment of a Zagreb calendar day, in UTC. */
	public static function start_of_day( string $date ): DateTimeImmutable {
		return self::at( $date, '00:00:00' );
	}

	/** The last second of a Zagreb calendar day, in UTC. */
	public static function end_of_day( string $date ): DateTimeImmutable {
		return self::at( $date, '23:59:59' );
	}

	/** Parses a DB datetime stored in UTC. */
	public static function from_db( string $value ): DateTimeImmutable {
		return new DateTimeImmutable( $value, self::utc() );
	}

	/** Formats a moment for a DB datetime column in UTC. */
	public static function to_db( DateTimeInterface $moment ): string {
		return DateTimeImmutable::createFromInterface( $moment )->setTimezone( self::utc() )->format( 'Y-m-d H:i:s' );
	}
}

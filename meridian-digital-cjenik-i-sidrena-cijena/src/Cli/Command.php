<?php
/**
 * @package Cjenik
 */

namespace Cjenik\Cli;

use Cjenik\Import\ImportResult;
use Cjenik\Publishing\Publication;
use Cjenik\Zagreb;
use WP_CLI;

/**
 * Publishes and checks the price list (cjenik) from the command line or a
 * system cron job, so publication doesn't depend on site traffic.
 *
 * ## EXAMPLES
 *
 *     # Publish now
 *     wp cjenik publish
 *
 *     # System cron: run the watchdog every 15 minutes
 *     0,15,30,45 * * * * cd /path/to/site && wp cjenik watchdog --quiet
 */
final class Command {
	/**
	 * Publishes the price list now.
	 *
	 * ## OPTIONS
	 *
	 * [--outlet=<id>]
	 * : Only this outlet. Default: every outlet.
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc_args
	 */
	public function publish( array $args, array $assoc_args ): void {
		$outlets = cjenik()->outlets()->all();
		if ( isset( $assoc_args['outlet'] ) ) {
			$outlet = cjenik()->outlets()->get( $assoc_args['outlet'] );
			if ( ! $outlet ) {
				WP_CLI::error( sprintf( 'Unknown outlet "%s".', $assoc_args['outlet'] ) );
			}
			$outlets = array( $outlet );
		}
		$failed = false;
		foreach ( $outlets as $outlet ) {
			$publication = cjenik()->publisher()->publish( $outlet, cjenik()->clock()->now() );
			$failed      = $failed || ! $publication->succeeded();
			$this->report( $publication );
		}
		if ( $failed ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Publishes any price list that is missing today once its time has passed,
	 * and sends alerts. Run it from a system cron job every 15 minutes.
	 */
	public function watchdog(): void {
		cjenik()->scheduler()->watchdog();
		$this->status();
	}

	/**
	 * Shows whether today's price list is published.
	 */
	public function status(): void {
		foreach ( cjenik()->outlets()->all() as $outlet ) {
			$today   = cjenik()->status()->today( $outlet );
			$problem = cjenik()->status()->problem( $outlet );
			WP_CLI::log(
				sprintf(
					'%s: %s',
					$outlet->id,
					$today
						? sprintf( 'published %s, %d rows, %s', Zagreb::local( $today->published_at )->format( 'd.m.Y. H:i' ), $today->row_count, $today->file_name )
						: 'not published today'
				)
			);
			if ( $problem ) {
				WP_CLI::warning( $problem );
			}
		}
		WP_CLI::log( 'Next run: ' . Zagreb::local( cjenik()->status()->next_run() )->format( 'd.m.Y. H:i T' ) );
	}

	/**
	 * Records every item's current price in the Price History.
	 */
	public function snapshot(): void {
		$count = cjenik()->history()->snapshot();
		WP_CLI::success( sprintf( 'Recorded %d items.', $count ) );
	}

	/**
	 * Imports anchor prices from a CSV file, by SKU or barcode.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : CSV with columns "šifra" or "barkod", "sidrena cijena" and optionally "sidreni datum".
	 *
	 * @subcommand import-anchors
	 * @param list<string> $args
	 */
	public function import_anchors( array $args ): void {
		$this->import_report( cjenik()->importer()->import_anchors( $this->readable( $args[0] ) ) );
	}

	/**
	 * Imports past prices from a CSV file, by SKU or barcode.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : CSV with columns "šifra" or "barkod", "cijena", "od", and optionally "redovna cijena" and "do".
	 *
	 * @subcommand import-history
	 * @param list<string> $args
	 */
	public function import_history( array $args ): void {
		$this->import_report( cjenik()->importer()->import_history( $this->readable( $args[0] ) ) );
	}

	/**
	 * Fetches the public URLs anonymously and reports problems.
	 *
	 * @subcommand self-check
	 */
	public function self_check(): void {
		$result = cjenik()->self_check()->run();
		foreach ( $result['problems'] as $problem ) {
			WP_CLI::warning( $problem );
		}
		if ( ! $result['problems'] ) {
			WP_CLI::success( 'All public URLs are reachable: ' . implode( ', ', $result['checked'] ) );
		}
	}

	/**
	 * Lists the Publication Log.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : How many entries. Default: 20.
	 *
	 * [--format=<format>]
	 * : table, csv or json. Default: table.
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc_args
	 */
	public function log( array $args, array $assoc_args ): void {
		$rows = array_map(
			static fn( Publication $publication ) => array(
				'broj_pohrane' => $publication->storage_number,
				'objavljeno'   => Zagreb::local( $publication->published_at )->format( 'd.m.Y. H:i:s' ),
				'objekt'       => $publication->outlet_id,
				'status'       => $publication->status,
				'redaka'       => $publication->row_count,
				'datoteka'     => $publication->is_available() ? $publication->file_name : $publication->error,
				'sha256'       => $publication->sha256,
			),
			cjenik()->log()->entries( (int) ( $assoc_args['limit'] ?? 20 ) )
		);
		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'broj_pohrane', 'objavljeno', 'objekt', 'status', 'redaka', 'datoteka', 'sha256' ) );
	}

	private function report( Publication $publication ): void {
		if ( ! $publication->succeeded() ) {
			WP_CLI::warning( sprintf( '%s: publication failed: %s', $publication->outlet_id, $publication->error ) );
			return;
		}
		foreach ( $publication->warnings as $kind => $warning ) {
			WP_CLI::warning( sprintf( '%s: %d items (%s)', $kind, $warning['count'], implode( ', ', $warning['ids'] ) ) );
		}
		WP_CLI::success( sprintf( '%s: published %s (%d rows, sha256 %s)', $publication->outlet_id, $publication->file_name, $publication->row_count, $publication->sha256 ) );
	}

	private function import_report( ImportResult $result ): void {
		foreach ( $result->errors as $line => $message ) {
			WP_CLI::warning( sprintf( 'Line %d: %s', $line, $message ) );
		}
		WP_CLI::success( sprintf( 'Imported %d rows.', $result->imported ) );
	}

	private function readable( string $path ): string {
		if ( ! is_readable( $path ) ) {
			WP_CLI::error( sprintf( 'Cannot read %s', $path ) );
		}
		return $path;
	}
}

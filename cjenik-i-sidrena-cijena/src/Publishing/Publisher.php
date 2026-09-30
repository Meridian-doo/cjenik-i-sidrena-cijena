<?php
/**
 * @package Cjenik
 */

namespace Cjenik\Publishing;

use Cjenik\Outlet;
use Cjenik\PriceList\BuildReport;
use Cjenik\PriceList\CsvRowWriter;
use Cjenik\PriceList\FileName;
use Cjenik\PriceList\PriceListBuilder;
use Cjenik\PriceList\RowWriter;
use Cjenik\PriceList\XmlRowWriter;
use Cjenik\Settings;
use Cjenik\Zagreb;
use DateTimeImmutable;
use Throwable;

/**
 * The single entry point for publishing a Price List. The scheduler, the
 * watchdog, the admin "publish now" button and WP-CLI all call publish().
 */
final class Publisher {
	private const LOCK_TIMEOUT = 60;

	public function __construct(
		private PriceListBuilder $builder,
		private PublicationLog $log,
		private Settings $settings,
	) {}

	/**
	 * Builds the Outlet's Price List as it is at $moment and publishes it:
	 * writes a temporary file, renames it into place so no one sees half a
	 * file, logs it (which makes it the "latest"), then removes expired files.
	 *
	 * With $unless_published_since, publishes only if no file was published
	 * since then, checked once the Outlet's lock is held, so two watchdogs
	 * never publish twice.
	 */
	public function publish( Outlet $outlet, DateTimeImmutable $moment, ?DateTimeImmutable $unless_published_since = null ): Publication {
		$lock = 'cjenik_publish_' . $outlet->id;
		if ( ! $this->lock( $lock ) ) {
			// Not a failure: the other run publishes. Nothing is logged.
			return new Publication( 0, $outlet->id, null, $moment, Publication::FAILED, '', '', 0, '', array(), __( 'Another publication for this outlet is still running.', 'cjenik-i-sidrena-cijena' ), null );
		}
		if ( null !== $unless_published_since && $this->log->published_since( $outlet->id, $unless_published_since ) ) {
			$this->unlock( $lock );
			return $this->log->published_since( $outlet->id, $unless_published_since );
		}

		$writer         = $this->writer();
		$temp           = null;
		$storage_number = null;
		try {
			$dir    = Storage::ensure();
			$temp   = $dir . '/.' . wp_generate_password( 12, false ) . '.tmp';
			$report = new BuildReport();
			$writer->open( $temp, $this->builder->columns( $outlet ), $outlet->describe() );
			foreach ( $this->builder->rows( $outlet, $moment, $report ) as $row ) {
				$writer->write( $row );
			}
			$writer->close();
			$sha256 = (string) hash_file( 'sha256', $temp );

			// Only a complete file takes a Storage Number.
			$storage_number = $this->log->reserve_storage_number( $outlet->id );
			$file_name      = FileName::build( $outlet, $storage_number, $moment, (string) $this->settings->get( 'file_time_separator' ), $writer->extension() );
			if ( ! rename( $temp, $dir . '/' . $file_name ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				/* translators: %s: file name */
				throw new \RuntimeException( sprintf( __( 'Cannot move the file into place as %s.', 'cjenik-i-sidrena-cijena' ), $file_name ) );
			}
			chmod( $dir . '/' . $file_name, 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod

			$publication = $this->log->record( $outlet->id, $storage_number, $moment, Publication::SUCCESS, $file_name, $writer->extension(), $report->rows, $sha256, $report->warnings(), '' );
		} catch ( Throwable $error ) {
			$writer->close();
			if ( $temp && file_exists( $temp ) ) {
				wp_delete_file( $temp );
			}
			return $this->fail( $outlet, $storage_number, $moment, $writer->extension(), $error->getMessage() );
		} finally {
			$this->unlock( $lock );
		}

		try {
			$this->remove_expired( $outlet, $moment );
		} catch ( Throwable $error ) {
			// The file is published; old files go on the next run.
			wc_get_logger()->warning( 'Removing expired price lists failed: ' . $error->getMessage(), array( 'source' => 'cjenik' ) );
		}

		/**
		 * After a Price List is published.
		 */
		do_action( 'cjenik_published', $publication, $outlet );
		return $publication;
	}

	private function fail( Outlet $outlet, ?int $storage_number, DateTimeImmutable $moment, string $format, string $error ): Publication {
		$publication = $this->log->record( $outlet->id, $storage_number, $moment, Publication::FAILED, '', $format, 0, '', array(), $error );
		/**
		 * After a publication attempt fails.
		 */
		do_action( 'cjenik_publication_failed', $publication, $outlet );
		return $publication;
	}

	/**
	 * Deletes files older than the retention period, by their recorded
	 * publication time. The newest file always stays. Days are counted in
	 * Zagreb wall-clock time, so a summer-time change doesn't cut an hour off.
	 */
	private function remove_expired( Outlet $outlet, DateTimeImmutable $moment ): void {
		$cutoff = Zagreb::local( $moment )->modify( '-' . $this->settings->retention_days() . ' days' );
		foreach ( $this->log->expired( $outlet->id, $cutoff ) as $publication ) {
			if ( file_exists( $publication->path() ) ) {
				wp_delete_file( $publication->path() );
			}
			$this->log->mark_deleted( $publication, $moment );
		}
	}

	private function writer(): RowWriter {
		if ( 'xml' === $this->settings->get( 'format' ) ) {
			return new XmlRowWriter();
		}
		return new CsvRowWriter(
			(string) $this->settings->get( 'csv_delimiter' ),
			(string) $this->settings->get( 'csv_decimal' ),
			(bool) $this->settings->get( 'csv_bom' ),
			'lf' === $this->settings->get( 'csv_eol' ) ? "\n" : "\r\n",
		);
	}

	private function lock( string $name ): bool {
		global $wpdb;
		/**
		 * Seconds to wait for another publication of the same Outlet to finish.
		 */
		$timeout = (int) apply_filters( 'cjenik_publish_lock_timeout', self::LOCK_TIMEOUT );
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $timeout ) );
	}

	private function unlock( string $name ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}
}

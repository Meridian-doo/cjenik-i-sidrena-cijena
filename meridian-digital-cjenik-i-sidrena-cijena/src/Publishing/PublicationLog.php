<?php
/**
 * @package Cjenik
 */

namespace Cjenik\Publishing;

use Cjenik\Zagreb;
use DateTimeImmutable;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The Publication Log is the plugin's own table, read through $wpdb->prepare().

/**
 * The Publication Log: evidence of every file published, and the source of
 * truth for the "latest" URL, the archive and retention.
 */
final class PublicationLog {
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'cjenik_publications';
	}

	/**
	 * Takes the Outlet's next Storage Number. A number is used once, even if
	 * the publication then fails. Call while holding the Outlet's publish lock.
	 */
	public function reserve_storage_number( string $outlet_id ): int {
		global $wpdb;
		$option = 'cjenik_storage_number_' . $outlet_id;
		$logged = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(storage_number) FROM {$this->table()} WHERE outlet_id = %s", $outlet_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$next   = max( (int) get_option( $option, 0 ), $logged ) + 1;
		update_option( $option, $next, false );
		return $next;
	}

	/**
	 * @param array<string, array{count: int, ids: list<int>}> $warnings
	 */
	public function record( string $outlet_id, ?int $storage_number, DateTimeImmutable $published_at, string $status, string $file_name, string $format, int $row_count, string $sha256, array $warnings, string $error ): Publication {
		global $wpdb;
		$wpdb->insert(
			$this->table(),
			array(
				'outlet_id'      => $outlet_id,
				'storage_number' => $storage_number,
				'published_at'   => Zagreb::to_db( $published_at ),
				'status'         => $status,
				'file_name'      => $file_name,
				'format'         => $format,
				'row_count'      => $row_count,
				'sha256'         => $sha256,
				'warnings'       => wp_json_encode( $warnings ),
				'error'          => $error,
			)
		);
		$publication = $this->get( (int) $wpdb->insert_id );
		if ( ! $publication ) {
			throw new \RuntimeException( esc_html( 'Cannot write the Publication Log: ' . $wpdb->last_error ) );
		}
		return $publication;
	}

	public function get( int $id ): ?Publication {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? Publication::from_row( $row ) : null;
	}

	/** The Outlet's newest published file. */
	public function latest( string $outlet_id ): ?Publication {
		global $wpdb;
		return self::one( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE outlet_id = %s AND status = %s AND deleted_at IS NULL ORDER BY published_at DESC, id DESC LIMIT 1", $outlet_id, Publication::SUCCESS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** The Outlet's newest attempt, successful or not. */
	public function last_attempt( string $outlet_id ): ?Publication {
		global $wpdb;
		return self::one( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE outlet_id = %s ORDER BY published_at DESC, id DESC LIMIT 1", $outlet_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** The Outlet's newest successful file published on or after a moment. */
	public function published_since( string $outlet_id, DateTimeImmutable $since ): ?Publication {
		global $wpdb;
		return self::one( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE outlet_id = %s AND status = %s AND published_at >= %s ORDER BY published_at DESC, id DESC LIMIT 1", $outlet_id, Publication::SUCCESS, Zagreb::to_db( $since ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function by_storage_number( string $outlet_id, int $storage_number ): ?Publication {
		global $wpdb;
		return self::one( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE outlet_id = %s AND storage_number = %d ORDER BY published_at DESC, id DESC LIMIT 1", $outlet_id, $storage_number ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * The Outlet's files still published, newest first.
	 *
	 * @return list<Publication>
	 */
	public function available( string $outlet_id ): array {
		global $wpdb;
		return self::many( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE outlet_id = %s AND status = %s AND deleted_at IS NULL ORDER BY published_at DESC, id DESC", $outlet_id, Publication::SUCCESS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Files published before a cutoff that are still on disk, except the
	 * Outlet's newest file, which is never removed.
	 *
	 * @return list<Publication>
	 */
	public function expired( string $outlet_id, DateTimeImmutable $cutoff ): array {
		global $wpdb;
		$latest = $this->latest( $outlet_id );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE outlet_id = %s AND status = %s AND deleted_at IS NULL AND published_at < %s ORDER BY published_at DESC, id DESC", $outlet_id, Publication::SUCCESS, Zagreb::to_db( $cutoff ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_values(
			array_filter(
				self::many( $rows ),
				static fn( Publication $publication ) => ! $latest || $publication->id !== $latest->id
			)
		);
	}

	public function mark_deleted( Publication $publication, DateTimeImmutable $moment ): void {
		global $wpdb;
		$wpdb->update( $this->table(), array( 'deleted_at' => Zagreb::to_db( $moment ) ), array( 'id' => $publication->id ) );
	}

	/**
	 * Every entry, newest first, for the admin log and the Pro add-on.
	 * Optionally only one Outlet's, those whose file name contains a search
	 * term, or those published since a moment.
	 *
	 * @return list<Publication>
	 */
	public function entries( int $limit = 50, int $offset = 0, ?string $outlet_id = null, string $search = '', ?DateTimeImmutable $since = null ): array {
		global $wpdb;
		$args = array_merge( $this->filter( $outlet_id, $search, $since ), array( $limit, $offset ) );
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $args has one value per placeholder.
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE ( %s = '' OR outlet_id = %s ) AND file_name LIKE %s AND ( %s = '' OR published_at >= %s ) ORDER BY published_at DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$args
			)
		);
		return self::many( $rows );
	}

	public function count( ?string $outlet_id = null, string $search = '', ?DateTimeImmutable $since = null ): int {
		global $wpdb;
		$args = $this->filter( $outlet_id, $search, $since );
		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $args has one value per placeholder.
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table()} WHERE ( %s = '' OR outlet_id = %s ) AND file_name LIKE %s AND ( %s = '' OR published_at >= %s )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$args
			)
		);
	}

	/**
	 * The values for the filter in entries() and count(). An empty string
	 * turns its condition off.
	 *
	 * @return list<string>
	 */
	private function filter( ?string $outlet_id, string $search, ?DateTimeImmutable $since ): array {
		global $wpdb;
		$outlet_id = $outlet_id ?? '';
		$since     = $since ? Zagreb::to_db( $since ) : '';
		return array( $outlet_id, $outlet_id, '%' . $wpdb->esc_like( $search ) . '%', $since, $since );
	}

	/** @param object|null $row */
	private static function one( $row ): ?Publication {
		return $row ? Publication::from_row( $row ) : null;
	}

	/**
	 * @param array<object>|null $rows
	 * @return list<Publication>
	 */
	private static function many( $rows ): array {
		return array_map( array( Publication::class, 'from_row' ), $rows ?? array() );
	}
}

<?php
/**
 * @package Cjenik
 */

namespace Cjenik\Publishing;

use Cjenik\Zagreb;
use DateTimeImmutable;

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
			throw new \RuntimeException( 'Cannot write the Publication Log: ' . $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text: logged, emailed and escaped where shown.
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
		return $this->first_where( 'outlet_id = %s AND status = %s AND deleted_at IS NULL', array( $outlet_id, Publication::SUCCESS ) );
	}

	/** The Outlet's newest attempt, successful or not. */
	public function last_attempt( string $outlet_id ): ?Publication {
		return $this->first_where( 'outlet_id = %s', array( $outlet_id ) );
	}

	/** The Outlet's newest successful file published on or after a moment. */
	public function published_since( string $outlet_id, DateTimeImmutable $since ): ?Publication {
		return $this->first_where(
			'outlet_id = %s AND status = %s AND published_at >= %s',
			array( $outlet_id, Publication::SUCCESS, Zagreb::to_db( $since ) )
		);
	}

	public function by_storage_number( string $outlet_id, int $storage_number ): ?Publication {
		return $this->first_where( 'outlet_id = %s AND storage_number = %d', array( $outlet_id, $storage_number ) );
	}

	/**
	 * The Outlet's files still published, newest first.
	 *
	 * @return list<Publication>
	 */
	public function available( string $outlet_id ): array {
		return $this->where( 'outlet_id = %s AND status = %s AND deleted_at IS NULL', array( $outlet_id, Publication::SUCCESS ) );
	}

	/**
	 * Files published before a cutoff that are still on disk, except the
	 * Outlet's newest file, which is never removed.
	 *
	 * @return list<Publication>
	 */
	public function expired( string $outlet_id, DateTimeImmutable $cutoff ): array {
		$latest = $this->latest( $outlet_id );
		return array_values(
			array_filter(
				$this->where(
					'outlet_id = %s AND status = %s AND deleted_at IS NULL AND published_at < %s',
					array( $outlet_id, Publication::SUCCESS, Zagreb::to_db( $cutoff ) )
				),
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
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE {$this->conditions( $outlet_id, $search, $since )} ORDER BY published_at DESC, id DESC LIMIT %d OFFSET %d", $limit, $offset ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return array_map( array( Publication::class, 'from_row' ), $rows );
	}

	public function count( ?string $outlet_id = null, string $search = '', ?DateTimeImmutable $since = null ): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()} WHERE {$this->conditions( $outlet_id, $search, $since )}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** The prepared WHERE conditions for entries() and count(). */
	private function conditions( ?string $outlet_id, string $search, ?DateTimeImmutable $since ): string {
		global $wpdb;
		$where = array( '1 = 1' );
		if ( null !== $outlet_id ) {
			$where[] = $wpdb->prepare( 'outlet_id = %s', $outlet_id );
		}
		if ( '' !== $search ) {
			$where[] = $wpdb->prepare( 'file_name LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' );
		}
		if ( null !== $since ) {
			$where[] = $wpdb->prepare( 'published_at >= %s', Zagreb::to_db( $since ) );
		}
		return implode( ' AND ', $where );
	}

	/**
	 * @param list<string|int> $args
	 */
	private function first_where( string $where, array $args ): ?Publication {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE {$where} ORDER BY published_at DESC, id DESC LIMIT 1", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $where holds the placeholders for $args.
		return $row ? Publication::from_row( $row ) : null;
	}

	/**
	 * @param list<string|int> $args
	 * @return list<Publication>
	 */
	private function where( string $where, array $args ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE {$where} ORDER BY published_at DESC, id DESC", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $where holds the placeholders for $args.
		return array_map( array( Publication::class, 'from_row' ), $rows );
	}
}

<?php
/**
 * @package Cjenik
 */

namespace Cjenik\Publishing;

/**
 * The fixed folder under uploads that holds published files.
 */
final class Storage {
	public const FOLDER = 'cjenik';

	public static function dir(): string {
		return wp_upload_dir( null, false )['basedir'] . '/' . self::FOLDER;
	}

	public static function url(): string {
		return set_url_scheme( wp_upload_dir( null, false )['baseurl'] . '/' . self::FOLDER );
	}

	/** Creates the folder, with an index file so the server doesn't list it. */
	public static function ensure(): string {
		$dir = self::dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new \RuntimeException( esc_html( sprintf( 'Cannot create %s', $dir ) ) );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}
}

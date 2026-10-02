<?php
/**
 * @package Cjenik
 */

namespace Cjenik\Archive;

/**
 * An HTTP response from the archive endpoints: a file, JSON, or an error.
 */
final class Response {
	/**
	 * @param array<string, string> $headers
	 */
	public function __construct(
		public readonly int $status,
		public readonly array $headers = array(),
		private ?string $body = null,
		private ?string $file = null,
	) {}

	public function body(): string {
		if ( null !== $this->file ) {
			return (string) file_get_contents( $this->file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		return (string) $this->body;
	}

	public function send(): void {
		status_header( $this->status );
		foreach ( $this->headers as $name => $value ) {
			header( $name . ': ' . $value );
		}
		if ( null !== $this->file ) {
			readfile( $this->file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		} else {
			echo $this->body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Not HTML: the archive's JSON index or a plain-text error, sent with that Content-Type.
		}
		exit;
	}
}

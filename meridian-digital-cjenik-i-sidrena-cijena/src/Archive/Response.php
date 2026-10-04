<?php
/**
 * @package Cjenik
 */

namespace Cjenik\Archive;

/**
 * An HTTP response from the archive endpoints: a file, JSON, or an error.
 */
final class Response {
	private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;

	/**
	 * @param array<string, string> $headers
	 * @param string|null           $body    Plain text.
	 * @param array<mixed>|null     $json    Data sent as JSON.
	 */
	public function __construct(
		public readonly int $status,
		public readonly array $headers = array(),
		private ?string $body = null,
		private ?string $file = null,
		private ?array $json = null,
	) {}

	public function body(): string {
		if ( null !== $this->file ) {
			return (string) file_get_contents( $this->file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		if ( null !== $this->json ) {
			return (string) wp_json_encode( $this->json, self::JSON_FLAGS );
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
		} elseif ( null !== $this->json ) {
			echo wp_json_encode( $this->json, self::JSON_FLAGS );
		} else {
			echo esc_html( (string) $this->body );
		}
		exit;
	}
}

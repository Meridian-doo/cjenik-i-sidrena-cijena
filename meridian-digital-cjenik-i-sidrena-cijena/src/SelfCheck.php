<?php
/**
 * @package Cjenik
 */

namespace Cjenik;

use Cjenik\Archive\Archive;
use Cjenik\Publishing\PublicationLog;
use Cjenik\Publishing\Scheduler;

/**
 * Fetches the "latest" URLs and the archive page like an anonymous crawler
 * would, and reports anything that would stop an inspector or aggregator:
 * robots.txt blocks, redirects to a login, error responses, bot challenges.
 *
 * The requests go to this site only.
 */
final class SelfCheck {
	public const OPTION = 'cjenik_self_check';
	public const HOOK   = 'cjenik_self_check';

	/** Text that bot-challenge pages contain (Cloudflare, SiteGround, Sucuri). */
	private const CHALLENGE_MARKERS = array( 'cf-chl', 'challenge-platform', 'cf-browser-verification', 'Just a moment...', 'sgcaptcha', 'sucuri_cloudproxy_js' );

	public function __construct(
		private Archive $archive,
		private PublicationLog $log,
		private Outlets $outlets,
		private Clock $clock,
	) {}

	/** Runs the check in the background after each daily run. */
	public static function register(): void {
		add_action(
			self::HOOK,
			static function (): void {
				cjenik()->self_check()->run();
			}
		);
		add_action(
			Scheduler::PUBLISH_HOOK,
			static function (): void {
				if ( function_exists( 'as_enqueue_async_action' ) ) {
					as_enqueue_async_action( self::HOOK, array(), Scheduler::GROUP, true );
				}
			},
			20
		);
	}

	/**
	 * @return array{checked_at: string, problems: list<string>, checked: list<string>}
	 */
	public function run(): array {
		$problems = array();
		$checked  = array();
		$targets  = array();
		foreach ( $this->outlets->all() as $outlet ) {
			$targets[] = $this->archive->latest_url( $outlet );
			$latest    = $this->log->latest( $outlet->id );
			if ( $latest ) {
				$targets[] = $latest->url();
			}
		}
		$page = $this->archive->page_url();
		if ( '' !== $page ) {
			$targets[] = $page;
		} else {
			$problems[] = __( 'There is no public archive page. Add the [cjenik_arhiva] shortcode to a published page.', 'meridian-digital-cjenik-i-sidrena-cijena' );
		}

		$robots = $this->robots_rules();
		foreach ( $targets as $url ) {
			$checked[] = $url;
			$path      = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( self::disallowed( $robots, $path ) ) {
				/* translators: %s: URL */
				$problems[] = sprintf( __( 'robots.txt blocks crawlers from %s.', 'meridian-digital-cjenik-i-sidrena-cijena' ), $url );
			}
			$problem = $this->fetch_problem( $url );
			if ( null !== $problem ) {
				$problems[] = $problem;
			}
		}

		$result = array(
			'checked_at' => Zagreb::to_db( $this->clock->now() ),
			'problems'   => $problems,
			'checked'    => $checked,
		);
		update_option( self::OPTION, $result, false );
		return $result;
	}

	/**
	 * @return array{checked_at: string, problems: list<string>, checked: list<string>}|null
	 */
	public function last(): ?array {
		$result = get_option( self::OPTION );
		return is_array( $result ) ? $result : null;
	}

	private function fetch_problem( string $url ): ?string {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'cookies'     => array(),
				'user-agent'  => 'Mozilla/5.0 (compatible; CjenikSelfCheck/1.0; +' . home_url( '/' ) . ')',
			)
		);
		if ( is_wp_error( $response ) ) {
			/* translators: 1: URL, 2: error message */
			return sprintf( __( '%1$s could not be fetched: %2$s', 'meridian-digital-cjenik-i-sidrena-cijena' ), $url, $response->get_error_message() );
		}
		$code     = (int) wp_remote_retrieve_response_code( $response );
		$location = (string) wp_remote_retrieve_header( $response, 'location' );
		if ( $code >= 300 && $code < 400 ) {
			if ( str_contains( $location, 'wp-login.php' ) || str_contains( $location, 'login' ) ) {
				/* translators: %s: URL */
				return sprintf( __( '%s redirects anonymous visitors to a login page.', 'meridian-digital-cjenik-i-sidrena-cijena' ), $url );
			}
			/* translators: 1: URL, 2: redirect target */
			return sprintf( __( '%1$s redirects to %2$s. Crawlers may not follow it.', 'meridian-digital-cjenik-i-sidrena-cijena' ), $url, $location );
		}
		$body = (string) wp_remote_retrieve_body( $response );
		$html = str_contains( (string) wp_remote_retrieve_header( $response, 'content-type' ), 'html' );
		foreach ( self::CHALLENGE_MARKERS as $marker ) {
			if ( $html && false !== stripos( $body, $marker ) ) {
				/* translators: %s: URL */
				return sprintf( __( '%s shows a bot challenge or captcha instead of the content.', 'meridian-digital-cjenik-i-sidrena-cijena' ), $url );
			}
		}
		if ( 200 !== $code ) {
			/* translators: 1: URL, 2: HTTP status code */
			return sprintf( __( '%1$s answers with HTTP status %2$d instead of 200.', 'meridian-digital-cjenik-i-sidrena-cijena' ), $url, $code );
		}
		return null;
	}

	/**
	 * The Allow and Disallow rules for all user agents in robots.txt.
	 *
	 * @return list<array{0: bool, 1: string}> [allowed, path prefix]
	 */
	private function robots_rules(): array {
		$response = wp_remote_get( home_url( '/robots.txt' ), array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		$rules      = array();
		$in_group   = false;
		$group_open = false;
		foreach ( preg_split( '/\R/', (string) wp_remote_retrieve_body( $response ) ) as $line ) {
			$line = trim( (string) preg_replace( '/#.*/', '', (string) $line ) );
			if ( ! str_contains( $line, ':' ) ) {
				continue;
			}
			[ $field, $value ] = array_map( 'trim', explode( ':', $line, 2 ) );
			$field             = strtolower( $field );
			if ( 'user-agent' === $field ) {
				// Consecutive User-agent lines share one group.
				$in_group   = $group_open ? ( $in_group || '*' === $value ) : '*' === $value;
				$group_open = true;
				continue;
			}
			$group_open = false;
			if ( $in_group && in_array( $field, array( 'allow', 'disallow' ), true ) && '' !== $value ) {
				$rules[] = array( 'allow' === $field, $value );
			}
		}
		return $rules;
	}

	/**
	 * Whether the longest matching rule for a path is a Disallow.
	 *
	 * @param list<array{0: bool, 1: string}> $rules
	 */
	private static function disallowed( array $rules, string $path ): bool {
		$match = null;
		foreach ( $rules as [ $allowed, $prefix ] ) {
			$pattern = '#^' . str_replace( array( '\*', '\$' ), array( '.*', '$' ), preg_quote( $prefix, '#' ) ) . '#';
			if ( preg_match( $pattern, $path ) && ( null === $match || strlen( $prefix ) > strlen( $match[1] ) ) ) {
				$match = array( $allowed, $prefix );
			}
		}
		return null !== $match && ! $match[0];
	}
}

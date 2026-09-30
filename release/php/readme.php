<?php
/**
 * Lists the strings translate.wordpress.org imports from a readme.txt, parsed
 * with WordPress.org's own parser (vendored in wporg/), in the order and with
 * the comments of cli/i18n/class-readme-import.php. Validating the readme is
 * Plugin Check's job.
 *
 * Usage: wp eval-file readme.php <readme.txt>
 * Prints JSON: { strings: [ { text, comment } ] }.
 *
 * @package Cjenik
 */

use WordPressdotorg\Plugin_Directory\Readme\Parser;

if ( ! defined( 'WP_CORE_STABLE_BRANCH' ) ) {
	define( 'WP_CORE_STABLE_BRANCH', implode( '.', array_slice( explode( '.', get_bloginfo( 'version' ) ), 0, 2 ) ) );
}
require_once __DIR__ . '/wporg/class-markdown.php';
require_once __DIR__ . '/wporg/readme/class-parser.php';

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
$cjenik_contents = (string) file_get_contents( $args[0] );
$cjenik_readme   = new Parser( 'data:text/plain,' . rawurlencode( $cjenik_contents ) );

$cjenik_strings = array();
$cjenik_add     = static function ( string $text, string $comment ) use ( &$cjenik_strings ): void {
	if ( ! isset( $cjenik_strings[ $text ] ) ) {
		$cjenik_strings[ $text ] = array();
	}
	if ( ! in_array( $comment, $cjenik_strings[ $text ], true ) ) {
		$cjenik_strings[ $text ][] = $comment;
	}
};

if ( $cjenik_readme->name ) {
	$cjenik_add( $cjenik_readme->name, 'Plugin name.' );
}
if ( $cjenik_readme->short_description ) {
	$cjenik_add( $cjenik_readme->short_description, 'Short description.' );
}
foreach ( (array) $cjenik_readme->screenshots as $cjenik_caption ) {
	$cjenik_add( $cjenik_caption, 'Screenshot description.' );
}
foreach ( $cjenik_readme->sections as $cjenik_key => $cjenik_html ) {
	$cjenik_where = trim( (string) preg_replace( '/[^a-z0-9]/i', ' ', $cjenik_key ) );
	if ( preg_match_all( '~<(h[1-6]|dt)[^>]*>([^<].+)</\1>~', $cjenik_html, $cjenik_m ) ) {
		foreach ( $cjenik_m[2] as $cjenik_text ) {
			if ( 'changelog' === $cjenik_key && preg_match( '!^v?\d+(\.\d+)*$!i', trim( $cjenik_text ) ) ) {
				continue;
			}
			$cjenik_add( trim( $cjenik_text ), "Found in {$cjenik_where} header." );
		}
	}
	if ( preg_match_all( '~<li>(?!<p>)([\s\S]*?)(</li>|\s*<ul>)~', $cjenik_html, $cjenik_m ) ) {
		foreach ( $cjenik_m[1] as $cjenik_text ) {
			$cjenik_add( trim( $cjenik_text ), "Found in {$cjenik_where} list item." );
		}
	}
	if ( preg_match_all( '|<p>([\s\S]*?)</p>|', $cjenik_html, $cjenik_m ) ) {
		foreach ( $cjenik_m[1] as $cjenik_text ) {
			$cjenik_add( trim( $cjenik_text ), "Found in {$cjenik_where} paragraph." );
		}
	}
}

echo wp_json_encode(
	array(
		'strings' => array_map(
			static fn( $text, $comments ) => array(
				'text'    => (string) $text,
				'comment' => implode( ' ', $comments ),
			),
			array_keys( $cjenik_strings ),
			array_values( $cjenik_strings )
		),
	),
	JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);

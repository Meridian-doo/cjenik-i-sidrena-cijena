<?php
/**
 * WordPress core test-suite config for the wp-env `cli` container.
 *
 * The suite installs WordPress into its own database, so it never touches the
 * dev site's `wordpress` database.
 *
 * @package Cjenik\Tests
 */

define( 'ABSPATH', '/var/www/html/' );

define( 'DB_NAME', getenv( 'CJENIK_TESTS_DB_NAME' ) ?: 'wordpress_tests' );
define( 'DB_USER', getenv( 'WORDPRESS_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WORDPRESS_DB_PASSWORD' ) ?: 'password' );
define( 'DB_HOST', getenv( 'WORDPRESS_DB_HOST' ) ?: 'mysql' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Testna trgovina' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );

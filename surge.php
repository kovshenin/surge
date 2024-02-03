<?php
/**
 * Plugin Name: Surge
 * Plugin URI: https://github.com/kovshenin/surge
 * Description: A fast and simple page caching plugin for WordPress
 * Author: Konstantin Kovshenin
 * Author URI: https://konstantin.blog
 * Text Domain: surge
 * Domain Path: /languages
 * Version: 1.1.0
 *
 * @package Surge
 */

namespace Surge;

// Attempt to cache this request if cache is on.
if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
	include_once( __DIR__ . '/include/cache.php' );
}

// Load more files later when necessary.
add_action( 'plugins_loaded', function() {
	if ( false === get_option( 'surge_installed', false ) ) {
		if ( add_option( 'surge_installed', 0 ) ) {
			require_once( __DIR__ . '/include/install.php' );
		}
	}

	if ( wp_doing_cron() ) {
		include_once( __DIR__ . '/include/cron.php' );
	}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		include_once( __DIR__ . '/include/cli.php' );
	}

	include_once( __DIR__ . '/include/invalidate.php' );
} );

// Site Health events
add_filter( 'site_status_tests', function( $tests ) {
	include_once( __DIR__ . '/include/health.php' );

	$tests['direct']['surge'] = [
		'label' => 'Caching Test',
		'test' => '\Surge\health_test',
	];

	return $tests;
} );

// Support for 6.1+ cache headers check.
add_filter( 'site_status_page_cache_supported_cache_headers', function( $headers ) {
	$headers['x-cache'] = static function( $value ) {
		return false !== strpos( strtolower( $value ), 'hit' );
	};
	return $headers;
} );

// Schedule cron events.
add_action( 'shutdown', function() {
	if ( ! wp_next_scheduled( 'surge_delete_expired' ) ) {
		wp_schedule_event( time(), 'hourly', 'surge_delete_expired' );
	}
} );

// Re-install on activation
register_activation_hook( __FILE__, function() {
	delete_option( 'surge_installed' );
} );

// Remove advanced-cache.php on deactivation
register_deactivation_hook( __FILE__, function() {
	delete_option( 'surge_installed' );

	// Remove advanced-cache.php only if its ours.
	if ( file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
		$contents = file_get_contents( WP_CONTENT_DIR . '/advanced-cache.php' );
		if ( strpos( $contents, 'namespace Surge;' ) !== false ) {
			unlink( WP_CONTENT_DIR . '/advanced-cache.php' );
		}
	}
} );

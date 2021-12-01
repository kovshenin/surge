<?php
/**
 * Plugin Name: Surge
 * Plugin URI: https://github.com/kovshenin/surge
 * Description: A fast and simple page caching plugin for WordPress
 * Author: Konstantin Kovshenin
 * Author URI: https://konstantil.blog
 * Text Domain: surge
 * Domain Path: /languages
 * Version: 0.1.0
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
	// delete_option( 'surge_installed' );
	if ( false === get_option( 'surge_installed', false ) ) {
		if ( add_option( 'surge_installed', 0 ) ) {
			require_once( __DIR__ . '/include/install.php' );
		}
	}

	if ( wp_doing_cron() ) {
		include_once( __DIR__ . '/include/cron.php' );
	}

	include_once( __DIR__ . '/include/invalidate.php' );
} );

// Schedule cron events.
add_action( 'shutdown', function() {
	if ( ! wp_next_scheduled( 'surge_delete_expired' ) ) {
		wp_schedule_event( time(), 'hourly', 'surge_delete_expired' );
	}
} );

<?php
/**
 * Cron-related tasks for Surge.
 *
 * @package Surge
 */

namespace Surge;

include_once( __DIR__ . '/common.php' );

// Runs in a CLI/Cron context, deletes expired cache entries.
add_action( 'surge_delete_expired', function() {
	$cache_dir = CACHE_DIR;
	$start = microtime( true );
	$files = [];
	$deleted = 0;
	$time = time();

	$levels = scandir( $cache_dir );
	foreach ( $levels as $level ) {
		if ( $level == '.' || $level == '..' ) {
			continue;
		}

		if ( $level == 'flags.json.php' ) {
			continue;
		}

		if ( ! is_dir( "{$cache_dir}/{$level}" ) ) {
			continue;
		}

		$items = scandir( "{$cache_dir}/{$level}" );
		foreach ( $items as $item ) {
			if ( $item == '.' || $item == '..' ) {
				continue;
			}

			if ( substr( $item, -4 ) != '.php' ) {
				continue;
			}

			$files[] = "{$cache_dir}/{$level}/{$item}";
		}
	}

	foreach ( $files as $filename ) {
		// Some files after scandir may already be gone/renamed.
		if ( ! file_exists( $filename ) ) {
			continue;
		}

		$stat = @stat( $filename );
		if ( ! $stat ) {
			continue;
		}

		// Skip files modified in the last minute.
		if ( $stat['mtime'] + MINUTE_IN_SECONDS > $time ) {
			continue;
		}

		// Empty file.
		if ( $stat['size'] < 1 ) {
			unlink( $filename );
			$deleted++;
			continue;
		}

		$f = fopen( $filename, 'rb' );
		$meta = read_metadata( $f );
		fclose( $f );

		// This cache entry is still valid.
		if ( $meta && ! empty( $meta['expires'] ) && $meta['expires'] > $time ) {
			continue;
		}

		// Delete the cache entry
		unlink( $filename );
		$deleted++;
	}

	$end = microtime( true );
	$elapsed = $end - $start;

	if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
		\WP_CLI::success( sprintf( 'Deleted %d/%d files in %.4f seconds',
			$deleted, count( $files ), $elapsed ) );
	}
} );

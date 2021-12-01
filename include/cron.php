<?php
/**
 * Cron-related tasks for Surge.
 *
 * @package Surge
 */

namespace Surge;

// Runs in a CLI/Cron context, deletes expired cache entries.
add_action( 'surge_delete_expired', function() {
	$cache_dir = CACHE_DIR;
	$start = microtime( true );
	$keys = [];
	$deleted = 0;
	$time = time();

	$levels = scandir( $cache_dir );
	foreach ( $levels as $level ) {
		if ( $level == '.' || $level == '..' ) {
			continue;
		}

		if ( $level == 'flags.json' ) {
			continue;
		}

		$items = scandir( "{$cache_dir}/{$level}" );
		foreach ( $items as $item ) {
			if ( $item == '.' || $item == '..' ) {
				continue;
			}

			if ( substr( $item, -5 ) != '.meta' ) {
				continue;
			}

			$cache_key = substr( $item, 0, -5 );
			$keys[] = $cache_key;
		}
	}

	foreach ( $keys as $cache_key ) {
		$level = substr( $cache_key, -2 );

		$f = fopen( "{$cache_dir}/{$level}/{$cache_key}.meta", 'r' );
		if ( ! flock( $f, LOCK_EX ) ) {
			// Could not acquire a lock.
			fclose( $f );
			continue;
		}

		$contents = '';
		while ( ! feof( $f ) ) {
			$contents .= fread( $f, 8192 );
		}

		$meta = json_decode( $contents, true );

		// This cache entry is still valid.
		if ( $meta && ! empty( $meta['expries'] ) && $meta['expires'] > $time ) {
			fclose( $f );
			continue;
		}

		// Delete the cache entry and release the lock.
		unlink( "{$cache_dir}/{$level}/{$cache_key}.data" );
		unlink( "{$cache_dir}/{$level}/{$cache_key}.meta" );
		fclose( $f );
		$deleted++;
	}

	$end = microtime( true );
	$elapsed = $end - $start;

	if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
		\WP_CLI::success( sprintf( 'Deleted %d/%d keys in %.4f seconds',
			$deleted, count( $keys ), $elapsed ) );
	}
} );

<?php
/**
 * All things CLI
 *
 * @package Surge
 */

namespace Surge;

use WP_CLI;

include_once( __DIR__ . '/common.php' );

class CLI_Commands {

	/**
	 * Flush all cached data.
	 *
	 * ## OPTIONS
	 *
	 * [--delete]
	 * : By default flushing cache will invalidate all existing entries. Using the --delete flag will also delete these entries from disk, which is slower.
	 * ---
	 * default: false
	 */
	public function flush( $args, $assoc_args ) {
		$assoc_args = wp_parse_args( $assoc_args, [
			'delete' => false,
		] );

		$result = flush_cache_entries( (bool) $assoc_args['delete'] );
		if ( ! $result['ok'] ) {
			WP_CLI::error( $result['message'] );
		}

		WP_CLI::success( $result['message'] );
	}

	/**
	 * Get page cache status.
	 */
	public function status( $args, $assoc_args ) {
		list( $size, $count ) = cache_stats();
		WP_CLI::line( sprintf( 'Cache size: %s', size_format( $size ) ) );
		WP_CLI::line( sprintf( 'Cached items: %d', $count ) );
	}
}

WP_CLI::add_command( 'surge', __NAMESPACE__ . '\\CLI_Commands', [
	'shortdesc' => 'Control Surge page caching.',
] );

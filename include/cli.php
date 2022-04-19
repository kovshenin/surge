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

		if ( ! $assoc_args['delete'] ) {
			expire( '/' );
			WP_CLI::success( 'Set all existing page cache entries as expired.' );
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

		$fs = new \WP_Filesystem_Direct( false );
		$r = $fs->rmdir( CACHE_DIR, true );
		if ( ! $r ) {
			WP_CLI::error( sprintf( 'Could not recursively delete %s. Please check permissions.', CACHE_DIR ) );
		}

		WP_CLI::success( 'All page cache deleted successfully.' );
	}
}

WP_CLI::add_command( 'surge', __NAMESPACE__ . '\\CLI_Commands', [
	'shortdesc' => 'Control Surge page caching.',
] );

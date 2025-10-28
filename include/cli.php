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

		$fs = $this->fs();
		$r = $fs->rmdir( CACHE_DIR, true );
		if ( ! $r ) {
			WP_CLI::error( sprintf( 'Could not recursively delete %s. Please check permissions.', CACHE_DIR ) );
		}

		WP_CLI::success( 'All page cache deleted successfully.' );
	}

	/**
	 * Get page cache status.
	 */
	public function status( $args, $assoc_args ) {
		$fs = $this->fs();

		list( $size, $count ) = $this->get_size( $fs, CACHE_DIR );
		WP_CLI::line( sprintf( 'Cache size: %s', size_format( $size ) ) );
		WP_CLI::line( sprintf( 'Cached items: %d', $count ) );
	}

	private function get_size( $fs, $path ) {
		$size = 0;
		$count = 0;

		if ( ! $fs->is_dir( $path ) ) {
			return [ $size, $count ];
		}

		$entries = $fs->dirlist( $path );

		if ( ! is_array( $entries ) ) {
			return [ $size, $count ];
		}

		foreach ( $entries as $name => $info ) {
			if ( 'flags.json.php' === $name ) {
				continue;
			}

			if ( 'f' === $info['type'] ) {
				$size += $info['size'];
				$count += 1;
				continue;
			}

			if ( 'd' === $info['type'] ) {
				$subdir = $this->get_size( $fs, trailingslashit( $path ) . $name );
				$size += $subdir[0];
				$count += $subdir[1];
				continue;
			}
		}

		return [ $size, $count ];
	}

	private function fs() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

		return new \WP_Filesystem_Direct( false );
	}
}

WP_CLI::add_command( 'surge', __NAMESPACE__ . '\\CLI_Commands', [
	'shortdesc' => 'Control Surge page caching.',
] );

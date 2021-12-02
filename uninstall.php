<?php
/**
 * Uninstall Surge
 *
 * @package Surge
 */

namespace Surge;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

include_once( __DIR__ . '/include/common.php' );

// Remove advanced-cache.php only if its ours.
if ( file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
	$contents = file_get_contents( WP_CONTENT_DIR . '/advanced-cache.php' );
	if ( strpos( $contents, 'namespace Surge;' ) !== false ) {
		unlink( WP_CONTENT_DIR . '/advanced-cache.php' );
	}
}

// Delete the cache directory
function delete( $path ) {
	if ( is_file( $path ) ) {
		unlink( $path );
		return;
	}

	if ( ! is_dir( $path ) ) {
		return;
	}

	$entries = scandir( $path );
	foreach ( $entries as $entry ) {
		if ( $entry == '.' || $entry == '..' ) {
			continue;
		}

		delete( $path . '/' . $entry );
	}

	rmdir( $path );
}

delete( CACHE_DIR );

delete_option( 'surge_installed' );

<?php
/**
 * Surge Installer
 *
 * This file runs when Surge needs to be installed. Its main purpose is to copy
 * the advanced-cache.php loader and add the WP_CACHE constant to wp-config.php.
 *
 * @package Surge
 */

namespace Surge;

include_once( __DIR__ . '/common.php' );

// Remove old advanced-cache.php.
if ( file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
	unlink( WP_CONTENT_DIR . '/advanced-cache.php' );
}

// Copy our own advanced-cache.php.
$ret = copy( __DIR__ . '/advanced-cache.php', WP_CONTENT_DIR . '/advanced-cache.php' );
if ( ! $ret ) {
	update_option( 'surge_installed', 3 );
	return;
}

// Create the cache directory
wp_mkdir_p( CACHE_DIR );

// Nothing to do if WP_CACHE is already on or forced skip.
if ( defined( 'WP_CACHE' ) && WP_CACHE || apply_filters( 'surge_skip_config_update', false ) ) {
	update_option( 'surge_installed', 1 );
	return;
}

// Fetch wp-config.php contents.
$config_path = ABSPATH . 'wp-config.php';
if ( ! file_exists( ABSPATH . 'wp-config.php' )
	&& @file_exists( dirname( ABSPATH ) . '/wp-config.php' )
	&& ! @file_exists( dirname( ABSPATH ) . '/wp-settings.php' )
) {
	$config_path = dirname( ABSPATH ) . '/wp-config.php';
}

$config = file_get_contents( $config_path );

// Remove existing WP_CACHE definitions.
// Some regex inherited from https://github.com/wp-cli/wp-config-transformer/
$pattern = '#(?<=^|;|<\?php\s|<\?\s)(\s*?)(\h*define\s*\(\s*[\'"](WP_CACHE)[\'"]\s*)'
	. '(,\s*([\'"].*?[\'"]|.*?)\s*)((?:,\s*(?:true|false)\s*)?\)\s*;\s)#ms';

$config = preg_replace( $pattern, '', $config );

// Add a WP_CACHE to wp-config.php.
$anchor = "/* That's all, stop editing!";
if ( false !== strpos( $config, $anchor ) ) {
	$config = str_replace( $anchor, "define( 'WP_CACHE', true );\n\n" . $anchor, $config );
} elseif ( false !== strpos( $config, '<?php' ) ) {
	$config = preg_replace( '#^<\?php\s.*#', "$0\ndefine( 'WP_CACHE', true );\n", $config );
}

// Write modified wp-config.php.
$bytes = file_put_contents( $config_path, $config );
update_option( 'surge_installed', $bytes ? 1 : 2 );

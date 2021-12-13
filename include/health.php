<?php
/**
 * Surge Health
 *
 * Various checks for the Site Health dashboard.
 *
 * @package Surge
 */

namespace Surge;

include_once( __DIR__ . '/common.php' );

function health_test() {
	$result = array(
		'label' => __( 'Page caching is enabled', 'surge' ),
		'status' => 'good',
		'badge' => [
			'label' => __( 'Performance' ),
			'color' => 'blue',
		],
		'description' => '<p>' . __( 'Page caching loads your site faster for visitors, and allows your site to handle more traffic without overloading.', 'surge' ) . '</p>',
		'actions' => '',
		'test' => 'surge_cache',
	);

	$installed = get_option( 'surge_installed', false );

	$actions = sprintf(
		'<p><a href="%s">%s</a></p>',
		esc_url( admin_url( 'plugins.php' ) ),
		__( 'Manage your plugins', 'surge' )
	);

	if ( $installed === false || $installed > 1 ) {
		$result['status'] = 'critical';
		$result['label'] = __( 'Page caching is not installed correctly', 'surge' );
		$result['description'] = '<p>' . __( 'Looks like the Surge page caching plugin is not installed correctly. Please try to deactivate and activate it again in the Plugins screen. If that does not help, please visit the WordPress.org support forums.', 'surge' ) . '</p>';
		$result['actions'] = $actions;
		$result['badge']['color'] = 'red';
		return $result;
	}

	if ( $installed === 0 ) {
		$result['status'] = 'critical';
		$result['label'] = __( 'Page caching is being installed', 'surge' );
		$result['description'] = '<p>' . __( 'The Surge page caching plugin is being installed. This should only take a few seconds. If this message does not disappear, please try to deactivate and activate the plugin again from the Plugins screen. If that does not help, please visit the WordPress.org support forums.', 'surge' ) . '</p>';
		$result['actions'] = $actions;
		$result['badge']['color'] = 'orange';
		return $result;
	}

	if ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) {
		$result['status'] = 'critical';
		$result['label'] = __( 'Page caching is disabled in wp-config.php', 'surge' );
		$result['description'] = '<p>' . __( 'The Surge page caching plugin is installed, but caching is disabled because the WP_CACHE directive is not defined in wp-config.php. Please try to deactivate and activate the Surge plugin, or define WP_CACHE manually in wp-config.php', 'surge' ) . '</p>';
		$result['actions'] = $actions;
		$result['badge']['color'] = 'red';
		return $result;
	}

	if ( ! file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
		$result['status'] = 'critical';
		$result['label'] = __( 'Page caching is not installed correctly', 'surge' );
		$result['description'] = '<p>' . __( 'Looks like the Surge page caching plugin is not installed correctly, advanced-cache.php is missing. Please try to deactivate and activate it again in the Plugins screen. If that does not help, please visit the WordPress.org support forums.', 'surge' ) . '</p>';
		$result['actions'] = $actions;
		$result['badge']['color'] = 'red';
		return $result;
	}

	$contents = file_get_contents( WP_CONTENT_DIR . '/advanced-cache.php' );
	if ( strpos( $contents, 'namespace Surge;' ) === false ) {
		$result['status'] = 'critical';
		$result['label'] = __( 'Page caching is not installed correctly', 'surge' );
		$result['description'] = '<p>' . __( 'Looks like the Surge page caching plugin is not installed correctly, invalid advanced-cache.php contents. Please try to deactivate and activate it again in the Plugins screen. If that does not help, please visit the WordPress.org support forums.', 'surge' ) . '</p>';
		$result['actions'] = $actions;
		$result['badge']['color'] = 'red';
		return $result;
	}

	if ( ! is_writable( CACHE_DIR ) ) {
		$result['status'] = 'critical';
		$result['label'] = __( 'Page caching directory is missing or not writable', 'surge' );
		$result['description'] = '<p>' . __( 'The Surge plugin is installed, but the cache directory is missing or not writable. Please check the wp-content/cache directory permissions in your hosting environment, then toggle the Surge plugin activation. Visit the WordPress.org support forums for help.', 'surge' ) . '</p>';
		$result['actions'] = $actions;
		$result['badge']['color'] = 'red';
		return $result;
	}

	return $result;
}

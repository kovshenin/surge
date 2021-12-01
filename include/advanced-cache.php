<?php
/**
 * Surge advanced-cache.php dropin
 *
 * @package Surge
 */

namespace Surge;

$filename = WP_CONTENT_DIR . '/plugins/surge/include/serve.php';
if ( defined( 'WP_PLUGIN_DIR' ) ) {
	$filename = WP_PLUGIN_DIR . '/surge/include/serve.php';
}

include_once( $filename );

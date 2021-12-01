<?php
/**
 * Surge Common
 *
 * Common functions used by various Surge components.
 *
 * @package Surge
 */

namespace Surge;

const CACHE_DIR = WP_CONTENT_DIR . '/cache/surge';

/**
 * Caching configuration settings.
 *
 * @param string $key Configuration key
 *
 * @return mixed The config value for the supplied key.
 */
function config( $key ) {
	return [
		'ttl' => 600,
		'ignore_cookies' => [ 'wordpress_test_cookie' ],
		'ignore_query_vars' => [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ],
	][ $key ];
}

/**
 * Generate a cache key array.
 *
 * @return array
 */
function key() {
	static $cache_key = null;

	if ( isset( $cache_key ) ) {
		return $cache_key;
	}

	$cookies = [];
	$headers = [];

	// Clean up and normalize cookies.
	foreach ( $_COOKIE as $key => $value ) {

		// Ignore cookies that begin with a _, assume they're JS-only.
		if ( substr( $key, 0, 1 ) == '_' ) {
			unset( $_COOKIE[ $key ] );
			continue;
		}

		if ( ! in_array( $key, config( 'ignore_cookies' ) ) ) {
			$cookies[ $key ] = $value;
		}
	}

	// Clean the URL/query vars
	$parsed = parse_url( 'http://example.org' . $_SERVER['REQUEST_URI'] );
	$path = $parsed['path'];
	$query = $parsed['query'] ?? '';

	parse_str( $query, $query_vars );
	foreach ( $query_vars as $key => $value ) {
		if ( in_array( $key, config( 'ignore_query_vars' ) ) ) {
			unset( $query_vars[ $key ] );
		}
	}

	$cache_key = [
		'https' => $_SERVER['HTTPS'] ?? '',
		'method' => $_SERVER['REQUEST_METHOD'] ?? '',
		'host' => strtolower( $_SERVER['HTTP_HOST'] ?? '' ),
		'path' => $path,
		'query_vars' => $query_vars,
		'cookies' => $cookies,
		'headers' => $headers,
	];

	return $cache_key;
}

function flag( $flag = null ) {
	static $flags;

	if ( ! isset( $flags ) ) {
		$flags = [];
	}

	if ( $flag ) {
		$flags[] = $flag;
	}

	return $flags;
}

function expire( $flag = null ) {
	static $expire;

	if ( ! isset( $expire ) ) {
		$expire = [];
	}

	if ( $flag ) {
		$expire[] = $flag;
	}

	return $expire;
}

/**
 * Read metadata from a file resource.
 *
 * @param resource $f A file resource opened with fopen().
 *
 * @return null|array The decoded cache metadata or null.
 */
function read_metadata( $f ) {
	// Skip security header.
	fread( $f, strlen( '<?php exit; ?>' ) );

	// Read the metadata length.
	$bytes = fread( $f, 4 );
	if ( ! $bytes ) {
		return;
	}

	$data = unpack( 'Llength', $bytes );
	if ( empty( $data['length'] ) ) {
		return;
	}

	$bytes = fread( $f, $data['length'] );
	$meta = json_decode( $bytes, true );
	return $meta;
}

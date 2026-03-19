<?php
/**
 * Cache Content
 *
 * This file is loaded when there's a chance the request content should be
 * saved to cache.
 *
 * @package Surge
 */

namespace Surge;

if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
	return;
}

include_once( __DIR__ . '/common.php' );

/**
 * The main output buffer callback.
 *
 * @param string $contents The buffer contents.
 *
 * @return string Contents.
 */
$ob_callback = function( $contents ) {
	$ttl = config( 'ttl' );
	$reason = null;

	if ( $ttl < 1 ) {
		header( 'X-Cache: bypass' );
		status( 'bypass' );
		observability_request_reason( 'ttl_disabled' );
		observability_log_current_request_sample();
		return $contents;
	}

	$skip = false;
	$headers = [];

	foreach ( headers_list() as $header ) {
		list( $name, $value ) = array_map( 'trim', explode( ':', $header, 2 ) );

		// Do not store or vary on these headers.
		if ( in_array( strtolower( $name ), ['x-cache', 'x-powered-by'] ) ) {
			continue;
		}

		$headers[ $name ][] = $value;

		if ( strtolower( $name ) == 'set-cookie' ) {
			$skip = true;
			$reason = 'set_cookie';
			break;
		}

		if ( strtolower( $name ) == 'cache-control' ) {
			if ( stripos( $value, 'no-cache' ) !== false || stripos( $value, 'max-age=0' ) !== false ) {
				$skip = true;
				$reason = 'cache_control_no_cache';
				break;
			}
		}
	}

	if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
		$skip = true;
		$reason = 'auth_header';
	}

	if ( ! in_array( strtoupper( $_SERVER['REQUEST_METHOD'] ), [ 'GET', 'HEAD' ] ) ) {
		$skip = true;
		$reason = 'method_not_cacheable';
	}

	if ( ! in_array( http_response_code(), [ 200, 301, 302, 404 ] ) ) {
		$skip = true;
		$reason = 'status_not_cacheable';
	}

	if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
		$skip = true;
		$reason = 'donotcachepage';
	}

	if ( $skip ) {
		header( 'X-Cache: bypass' );
		status( 'bypass' );
		observability_request_reason( $reason ?: 'status_not_cacheable' );
		observability_log_current_request_sample();
		return $contents;
	}

	$key = key();

	$meta = [
		'code' => http_response_code(),
		'headers' => $headers,
		'created' => time(),
		'expires' => time() + $ttl,
		'flags' => array_unique( flag() ),
		'path' => $key['path'],
	];

	$meta_json = json_encode( $meta );
	$cache_key = md5( json_encode( $key ) );
	$level = substr( $cache_key, -2 );

	if ( ! wp_mkdir_p( CACHE_DIR . "/{$level}/" ) ) {
		header( 'X-Cache: bypass' );
		status( 'bypass' );
		observability_request_reason( 'cache_write_open_failed' );
		observability_log_current_request_sample( [
			'path' => $key['path'],
			'cacheKey' => $cache_key,
		] );
		return $contents;
	}

	// Open a new cache file.
	$hash = wp_generate_password( 6, false );
	$f = fopen( CACHE_DIR . "/{$level}/{$cache_key}.{$hash}.php", 'xb' );

	// Could not create file.
	if ( false === $f ) {
		header( 'X-Cache: bypass' );
		status( 'bypass' );
		observability_request_reason( 'cache_write_open_failed' );
		observability_log_current_request_sample( [
			'path' => $key['path'],
			'cacheKey' => $cache_key,
		] );
		return $contents;
	}

	fwrite( $f, '<?php exit; ?>' );
	fwrite( $f, pack( 'L', strlen( $meta_json ) ) );
	fwrite( $f, $meta_json );
	fwrite( $f, $contents );

	// Close the file.
	fclose( $f );

	// Atomic (hopefully) rename.
	if ( ! rename( CACHE_DIR . "/{$level}/{$cache_key}.{$hash}.php",
		CACHE_DIR . "/{$level}/{$cache_key}.php" )
	) {
		unlink( CACHE_DIR . "/{$level}/{$cache_key}.{$hash}.php" );
		header( 'X-Cache: bypass' );
		status( 'bypass' );
		observability_request_reason( 'cache_write_open_failed' );
		observability_log_current_request_sample( [
			'path' => $key['path'],
			'cacheKey' => $cache_key,
		] );
		return $contents;
	}

	event( 'request', [ 'meta' => $meta, 'status' => status() ] );
	observability_log_current_request_sample( [
		'path' => $key['path'],
		'cacheKey' => $cache_key,
	] );
	return $contents;
};

// Attach to main output buffer.
ob_start( $ob_callback );

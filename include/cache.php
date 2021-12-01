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

include_once( __DIR__ . '/common.php' );

/**
 * The main output buffer callback.
 *
 * @param string $contents The buffer contents.
 *
 * @return string Contents.
 */
$ob_callback = function( $contents ) {
	$skip = false;

	foreach ( headers_list() as $header ) {
		list( $name, $value ) = array_map( 'trim', explode( ':', $header, 2 ) );
		$headers[ $name ] = $value;

		if ( strtolower( $name ) == 'set-cookie' ) {
			$skip = true;
			break;
		}

		if ( strtolower( $name ) == 'cache-control' ) {
			if ( stripos( $value, 'no-cache' ) !== false || stripos( $value, 'max-age=0' ) !== false ) {
				$skip = true;
				break;
			}
		}
	}

	if ( ! in_array( strtoupper( $_SERVER['REQUEST_METHOD'] ), [ 'GET', 'HEAD' ] ) ) {
		$skip = true;
	}

	if ( ! in_array( http_response_code(), [ 200, 301, 302, 404 ] ) ) {
		$skip = true;
	}

	if ( $skip ) {
		return $contents;
	}

	$meta = [
		'code' => http_response_code(),
		'headers' => $headers,
		'created' => time(),
		'expires' => time() + config( 'ttl' ),
		'flags' => flag(),
	];

	$meta = json_encode( $meta );
	$cache_key = md5( json_encode( key() ) );
	$level = substr( $cache_key, -2 );

	if ( ! wp_mkdir_p( CACHE_DIR . "/{$level}/" ) ) {
		return $contents;
	}

	// Open the meta file and acquire a lock.
	$f = fopen( CACHE_DIR . "/{$level}/{$cache_key}.meta", 'w' );
	if ( ! flock( $f, LOCK_EX ) ) {
		fclose( $f );
		return $contents;
	}

	// TODO: Might not need LOCK_EX here since we're already holding an exclusive lock to .meta
	file_put_contents( CACHE_DIR . "/{$level}/{$cache_key}.data", $contents, LOCK_EX );

	// Write the metadata and release the lock.
	fwrite( $f, $meta );
	fclose( $f ); // Releases the lock.

	return $contents;
};

// Attach to main output buffer.
ob_start( $ob_callback );

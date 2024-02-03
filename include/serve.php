<?php
/**
 * Serve Cached Content
 *
 * This file is loaded during advanced-cache.php and its main purpose is to
 * attempt to serve a cached version of the request.
 *
 * @package Surge
 */

namespace Surge;

if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
	return;
}

include_once( __DIR__ . '/common.php' );

header( 'X-Cache: miss' );
$cache_key = md5( json_encode( key() ) );
$level = substr( $cache_key, -2 );

$filename = CACHE_DIR . "/{$level}/{$cache_key}.php";
if ( ! file_exists( $filename ) ) {
	return;
}

$f = fopen( $filename, 'rb' );
$meta = read_metadata( $f );
if ( ! $meta ) {
	fclose( $f );
	return;
}

if ( $meta['expires'] < time() ) {
	header( 'X-Cache: expired' );
	fclose( $f );
	return;
}

$flags = null;
if ( file_exists( CACHE_DIR . '/flags.json.php' ) ) {
	$flags = substr( file_get_contents( CACHE_DIR . '/flags.json.php' ), strlen( '<?php exit; ?>' ) );
	$flags = json_decode( $flags, true );
}

if ( $flags && ! empty( $meta['flags'] ) ) {
	foreach ( $flags as $flag => $timestamp ) {
		if ( $timestamp <= $meta['created'] ) {
			continue;
		}

		// Invalidate by path.
		if ( substr( $flag, 0, 1 ) == '/' ) {
			if ( substr( $meta['path'], 0, strlen( $flag ) ) === $flag ) {
				header( 'X-Cache: expired' );
				fclose( $f );
				return;
			}

			// This is a path flag, no futher comparison required.
			continue;
		}

		if ( in_array( $flag, $meta['flags'] ) ) {
			header( 'X-Cache: expired' );
			fclose( $f );
			return;
		}
	}
}

// Set the HTTP response code and send headers.
http_response_code( $meta['code'] );

foreach ( $meta['headers'] as $name => $values ) {
	foreach( (array) $values as $value ) {
		header( "{$name}: {$value}", false );
	}
}

header( 'X-Cache: hit' );
event( 'request', [ 'meta' => $meta ] );
fpassthru( $f ); // Pass the remaining bytes to the output.
fclose( $f );
die();

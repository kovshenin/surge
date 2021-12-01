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

$meta_filename = CACHE_DIR . "/{$level}/{$cache_key}.meta";
if ( ! file_exists( $meta_filename ) ) {
	return;
}

$meta = json_decode( file_get_contents( $meta_filename ), true );
if ( ! $meta ) {
	return;
}

if ( $meta['expires'] < time() ) {
	header( 'X-Cache: expired' );
	return;
}

$flags = null;
if ( file_exists( CACHE_DIR . '/flags.json' ) ) {
	$flags = json_decode( file_get_contents( CACHE_DIR . '/flags.json' ), true );
}

if ( ! empty( $flags['*'] ) && $flags['*'] > $meta['created'] ) {
	header( 'X-Cache: expired' );
	return;
}

if ( $flags && ! empty( $meta['flags'] ) ) {
	foreach ( $flags as $flag => $timestamp ) {
		if ( in_array( $flag, $meta['flags'] ) && $timestamp > $meta['created'] ) {
			header( 'X-Cache: expired' );
			return;
		}
	}
}

// Set the HTTP response code and send headers.
http_response_code( $meta['code'] );

foreach ( $meta['headers'] as $name => $value ) {
	header( "{$name}: {$value}" );
}

header( 'X-Cache: hit' );
// header( 'X-Flags: ' . implode( ', ', $meta['flags'] ) );
readfile( CACHE_DIR . "/{$level}/{$cache_key}.data" );
die();

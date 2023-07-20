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
	static $config = null;

	if ( isset( $config ) ) {
		return $config[ $key ];
	}

	$config = [
		'ttl' => 600,
		'ignore_cookies' => [ 'wordpress_test_cookie' ],

		// https://github.com/mpchadwick/tracking-query-params-registry/blob/master/_data/params.csv
		'ignore_query_vars' => [
			'fbclid', 'gclid', 'gclsrc', 'utm_content', 'utm_term', 'utm_campaign',
			'utm_medium', 'utm_source', 'utm_id', '_ga', 'mc_cid', 'mc_eid',
			'_bta_tid', '_bta_c', 'trk_contact', 'trk_msg', 'trk_module', 'trk_sid',
			'gdfms', 'gdftrk', 'gdffi', '_ke', 'redirect_log_mongo_id',
			'redirect_mongo_id', 'sb_referer_host', 'mkwid', 'pcrid', 'ef_id',
			's_kwcid', 'msclkid', 'dm_i', 'epik', 'pk_campaign', 'pk_kwd',
			'pk_keyword', 'piwik_campaign', 'piwik_kwd', 'piwik_keyword', 'mtm_campaign',
			'mtm_keyword', 'mtm_source', 'mtm_medium', 'mtm_content', 'mtm_cid',
			'mtm_group', 'mtm_placement', 'matomo_campaign', 'matomo_keyword',
			'matomo_source', 'matomo_medium', 'matomo_content', 'matomo_cid',
			'matomo_group', 'matomo_placement', 'hsa_cam', 'hsa_grp', 'hsa_mt',
			'hsa_src', 'hsa_ad', 'hsa_acc', 'hsa_net', 'hsa_kw', 'hsa_tgt',
			'hsa_ver', '_branch_match_id',
		],

		// Add items to this array to add a unique cache variant.
		'variants' => [],

		// Add callbacks to events early to do crazy stuff.
		'events' => [],
	];

	// Run a custom configuration file.
	if ( defined( 'WP_CACHE_CONFIG' ) ) {
		$_config = ( function( $config ) {
			$_config = (array) include( WP_CACHE_CONFIG );
			return $_config;
		} ) ( $config );

		$config = array_merge( $config, $_config );
	}

	return $config[ $key ];
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

	// Break the URL down.
	$parsed = parse_url( 'http://example.org' . $_SERVER['REQUEST_URI'] );
	$path = $parsed['path'];
	$query = $parsed['query'] ?? '';
	$query_vars = [];

	// Simplified parse_str without urldecoding
	foreach ( explode( '&', $query ) as $pair ) {
		$parts = explode( '=', $pair, 2 );
		$key = $parts[0];
		$value = $parts[1] ?? '';

		if ( ! array_key_exists( $key, $query_vars ) ) {
			$query_vars[ $key ] = $value;
		} else {
			if ( ! is_array( $query_vars[ $key ] ) ) {
				$query_vars[ $key ] = [ $query_vars[ $key ] ];
			}
			$query_vars[ $key ][] = $value;
		}
	}

	$unset_vars = [];

	// Ignore some query vars.
	foreach ( $query_vars as $key => $value ) {
		if ( in_array( $key, config( 'ignore_query_vars' ) ) ) {
			$unset_vars[] = $key;
			unset( $query_vars[ $key ] );
			unset( $_REQUEST[ $key ] );
			unset( $_GET[ $key ] );
		}
	}

	// Clean REQUEST_URI
	if ( ! empty( $unset_vars ) ) {
		$unset_vars_regex = implode( '|', array_map( 'preg_quote', $unset_vars ) );
		$_SERVER['REQUEST_URI'] = preg_replace( "#(\?)?&?({$unset_vars_regex})=[^&]+#", '\\1', $_SERVER['REQUEST_URI'] );
		$_SERVER['REQUEST_URI'] = str_replace( '?&', '?', $_SERVER['REQUEST_URI'] );
		if ( $_SERVER['REQUEST_URI'] == '/?' ) {
			$_SERVER['REQUEST_URI'] = '/';
		}
	}

	$cache_key = [
		'https' => is_ssl(),
		'method' => strtoupper( $_SERVER['REQUEST_METHOD'] ) ?? '',
		'host' => strtolower( $_SERVER['HTTP_HOST'] ?? '' ),
		'path' => $path,
		'query_vars' => $query_vars,
		'cookies' => [],
		'variants' => config( 'variants' ),
	];

	// Return early if this request is anonymized.
	if ( anonymize( $cache_key ) ) {
		return $cache_key;
	}

	// Clean up and normalize cookies.
	$cookies = [];
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

	$cache_key['cookies'] = $cookies;

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

/**
 * Anonymize a request
 *
 * This function checks whether this request should be anonymized, and alters
 * the cache key to reflect that. Also touches certain super-globals, such
 * as $_COOKIE to make sure the request is truly anonymous.
 *
 * @param string $cache_key The cache key, passed by reference
 *
 * @return bool True if the request was anonymized.
 */
function anonymize( &$cache_key ) {

	// Don't anonymize POST and other requests that may alter data.
	if ( $cache_key['method'] !== 'GET' && $cache_key['method'] !== 'HEAD' ) {
		return false;
	}

	// TODO: Maybe increase the TTL on these paths.
	if ( ! in_array( $cache_key['path'], [
		'/robots.txt',
		'/favicon.ico',
	] ) ) {
		return false;
	}

	// Very anonymous.
	// TODO: Clean php://input too.
	$_COOKIE = [];
	$_GET = [];
	$_REQUEST = [];
	$_POST = [];

	$cache_key['query_vars'] = [];
	return true;
}

/**
 * Execute an event.
 *
 * @param string $event The event name.
 * @param array $args An array for arguments to pass to callbacks.
 */
function event( $event, $args ) {
	$events = config( 'events' );

	if ( empty( $events[ $event ] ) ) {
		return;
	}

	foreach ( $events[ $event ] as $key => $callback ) {
		$callback( $args );
	}
}
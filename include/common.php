<?php
/**
 * Surge Common
 *
 * Common functions used by various Surge components.
 *
 * @package Surge
 */

namespace Surge;

include_once( __DIR__ . '/options.php' );

const CACHE_DIR = WP_CONTENT_DIR . '/cache/surge';

/**
 * Get the default Surge configuration.
 *
 * @return array
 */
function config_defaults() {
	return [
		'ttl' => 600,
		'ignore_cookies' => [ 'wordpress_test_cookie' ],
		'fpassthru_alt' => false,

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
}

/**
 * Resolve the effective Surge configuration and source metadata.
 *
 * @param bool $refresh Whether to rebuild the cached snapshot.
 *
 * @return array{values: array<string, mixed>, sources: array<string, string>}
 */
function config_snapshot( $refresh = false ) {
	static $snapshot = null;

	if ( $refresh ) {
		$snapshot = null;
	}

	if (
		isset( $snapshot )
		&& function_exists( 'get_option' )
		&& empty( $snapshot['ui_ready'] )
	) {
		$snapshot = null;
	}

	if ( isset( $snapshot ) ) {
		return $snapshot;
	}

	$values = config_defaults();
	$sources = array_fill_keys( array_keys( $values ), 'default' );

	$ui_settings = ui_settings();

	foreach ( $ui_settings as $key => $value ) {
		if ( 'ttl' === $key ) {
			$values[ $key ] = $value;
			$sources[ $key ] = 'ui';
			continue;
		}
	}

	if ( ! empty( $ui_settings['extra_ignore_query_vars'] ) ) {
		$values['ignore_query_vars'] = array_values(
			array_unique(
				array_merge( $values['ignore_query_vars'], $ui_settings['extra_ignore_query_vars'] )
			)
		);
		$sources['ignore_query_vars'] = 'ui';
	}

	if ( ! empty( $ui_settings['extra_ignore_cookies'] ) ) {
		$values['ignore_cookies'] = array_values(
			array_unique(
				array_merge( $values['ignore_cookies'], $ui_settings['extra_ignore_cookies'] )
			)
		);
		$sources['ignore_cookies'] = 'ui';
	}

	// Run a custom configuration file.
	if ( defined( 'WP_CACHE_CONFIG' ) ) {
		$_config = ( function( $config ) {
			$_config = (array) include( WP_CACHE_CONFIG );
			return $_config;
		} ) ( $values );

		foreach ( $_config as $key => $value ) {
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}

			$values[ $key ] = $value;
			$sources[ $key ] = 'wp_cache_config';
		}
	}

	foreach ( $values as $key => $value ) {
		$const = 'SURGE_' . strtoupper( $key );
		if ( defined( $const ) ) {
			$values[ $key ] = constant( $const );
			$sources[ $key ] = 'constant';
		}
	}

	$snapshot = [
		'values' => $values,
		'sources' => $sources,
		'ui_ready' => function_exists( 'get_option' ),
	];

	return $snapshot;
}

/**
 * Caching configuration settings.
 *
 * @param string $key Configuration key
 *
 * @return mixed The config value for the supplied key.
 */
function config( $key ) {
	$snapshot = config_snapshot();
	return $snapshot['values'][ $key ];
}

/**
 * Get the source for an effective configuration value.
 *
 * @param string $key Configuration key.
 *
 * @return string
 */
function config_source( $key ) {
	$snapshot = config_snapshot();
	return $snapshot['sources'][ $key ] ?? 'default';
}

/**
 * Clear the cached config snapshot.
 *
 * @return void
 */
function reset_config_snapshot() {
	config_snapshot( true );
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

/**
 * Get or set the status.
 *
 * @param string $new_status The status to set.
 */
function status( $new_status = null ) {
	static $status = 'undefined';

	if ( ! $new_status ) {
		return $status;
	}

	$status = $new_status;
	return $status;
}

/**
 * Check whether the active advanced-cache drop-in belongs to Surge.
 *
 * @return bool
 */
function advanced_cache_is_owned() {
	if ( ! file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
		return false;
	}

	$contents = file_get_contents( WP_CONTENT_DIR . '/advanced-cache.php' );
	return false !== strpos( $contents, 'namespace Surge;' );
}

/**
 * Get a direct filesystem handler.
 *
 * @return \WP_Filesystem_Direct
 */
function cache_filesystem() {
	require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

	return new \WP_Filesystem_Direct( false );
}

/**
 * Get recursive cache size and item count.
 *
 * @param string $path Cache path.
 *
 * @return array{0: int, 1: int}
 */
function cache_stats( $path = CACHE_DIR ) {
	$fs = cache_filesystem();
	return cache_stats_for_path( $fs, $path );
}

/**
 * Recursive helper for cache_stats().
 *
 * @param \WP_Filesystem_Direct $fs Filesystem object.
 * @param string               $path Cache path.
 *
 * @return array{0: int, 1: int}
 */
function cache_stats_for_path( $fs, $path ) {
	$size = 0;
	$count = 0;

	if ( ! $fs->is_dir( $path ) ) {
		return [ $size, $count ];
	}

	$entries = $fs->dirlist( $path );
	if ( ! is_array( $entries ) ) {
		return [ $size, $count ];
	}

	foreach ( $entries as $name => $info ) {
		if ( 'flags.json.php' === $name ) {
			continue;
		}

		if ( 'f' === $info['type'] ) {
			$size += (int) $info['size'];
			$count += 1;
			continue;
		}

		if ( 'd' === $info['type'] ) {
			$subdir = cache_stats_for_path( $fs, trailingslashit( $path ) . $name );
			$size += $subdir[0];
			$count += $subdir[1];
		}
	}

	return [ $size, $count ];
}

/**
 * Persist invalidation flags immediately.
 *
 * @param array $expire_flags Flags to expire.
 *
 * @return bool
 */
function persist_expire_flags( array $expire_flags ) {
	if ( empty( $expire_flags ) ) {
		return true;
	}

	$path = CACHE_DIR . '/flags.json.php';
	$exists = file_exists( $path );
	$mode = $exists ? 'r+' : 'w+';

	if ( ! $exists && ! wp_mkdir_p( CACHE_DIR ) ) {
		return false;
	}

	$f = fopen( $path, $mode );
	if ( ! $f ) {
		return false;
	}

	$length = $exists ? filesize( $path ) : 0;
	$flags = [];

	flock( $f, LOCK_EX );

	if ( $length ) {
		$raw = fread( $f, $length );
		$raw = substr( $raw, strlen( '<?php exit; ?>' ) );
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			$flags = $decoded;
		}
	}

	foreach ( $expire_flags as $flag ) {
		$flags[ $flag ] = time();
	}

	if ( $length ) {
		ftruncate( $f, 0 );
		rewind( $f );
	}

	fwrite( $f, '<?php exit; ?>' . wp_json_encode( $flags ) );
	fclose( $f );

	event( 'expire', [ 'flags' => array_values( $expire_flags ) ] );
	return true;
}

/**
 * Flush the cache safely for admin and CLI actions.
 *
 * @param bool $delete Whether to delete cache files from disk.
 *
 * @return array{ok: bool, message: string, mode: string}
 */
function flush_cache_entries( $delete = false ) {
	if ( ! $delete ) {
		$persisted = persist_expire_flags( [ '/' ] );
		return [
			'ok' => $persisted,
			'mode' => 'expire',
			'message' => $persisted
				? __( 'Marked existing cache entries as expired.', 'surge' )
				: __( 'Could not mark cache entries as expired.', 'surge' ),
		];
	}

	$fs = cache_filesystem();
	if ( $fs->exists( CACHE_DIR ) && ! $fs->rmdir( CACHE_DIR, true ) ) {
		return [
			'ok' => false,
			'mode' => 'delete',
			'message' => sprintf(
				/* translators: %s cache directory path */
				__( 'Could not delete cache directory %s. Please check permissions.', 'surge' ),
				CACHE_DIR
			),
		];
	}

	if ( ! wp_mkdir_p( CACHE_DIR ) ) {
		return [
			'ok' => false,
			'mode' => 'delete',
			'message' => __( 'Cache files were deleted, but the cache directory could not be recreated.', 'surge' ),
		];
	}

	return [
		'ok' => true,
		'mode' => 'delete',
		'message' => __( 'Deleted cache files and recreated the cache directory.', 'surge' ),
	];
}

/**
 * Get install and health diagnostics for the admin UI.
 *
 * @return array<string, mixed>
 */
function install_diagnostics() {
	$installed = get_option( 'surge_installed', false );
	$wp_cache_enabled = defined( 'WP_CACHE' ) && WP_CACHE;
	$advanced_cache_present = file_exists( WP_CONTENT_DIR . '/advanced-cache.php' );
	$advanced_cache_owned = advanced_cache_is_owned();
	$cache_dir_exists = file_exists( CACHE_DIR );
	$cache_dir_writable = $cache_dir_exists && is_writable( CACHE_DIR );

	$diagnostics = [
		'code' => $installed,
		'state' => 'good',
		'title' => __( 'Page caching is enabled', 'surge' ),
		'description' => __( 'Surge is installed correctly and page caching is available.', 'surge' ),
		'wpCacheEnabled' => $wp_cache_enabled,
		'advancedCachePresent' => $advanced_cache_present,
		'advancedCacheOwned' => $advanced_cache_owned,
		'cacheDirExists' => $cache_dir_exists,
		'cacheDirWritable' => $cache_dir_writable,
	];

	if ( false === $installed || $installed > 1 ) {
		$diagnostics['state'] = 'critical';
		$diagnostics['title'] = __( 'Page caching is not installed correctly', 'surge' );
		$diagnostics['description'] = __( 'Surge does not appear to be installed correctly. Try deactivating and activating the plugin again.', 'surge' );
		return $diagnostics;
	}

	if ( 0 === (int) $installed ) {
		$diagnostics['state'] = 'warning';
		$diagnostics['title'] = __( 'Page caching is still being installed', 'surge' );
		$diagnostics['description'] = __( 'Surge is still installing. If this does not resolve quickly, try toggling the plugin activation.', 'surge' );
		return $diagnostics;
	}

	if ( ! $wp_cache_enabled ) {
		$diagnostics['state'] = 'critical';
		$diagnostics['title'] = __( 'WP_CACHE is disabled', 'surge' );
		$diagnostics['description'] = __( 'Surge is installed, but caching is disabled because WP_CACHE is not enabled.', 'surge' );
		return $diagnostics;
	}

	if ( ! $advanced_cache_present ) {
		$diagnostics['state'] = 'critical';
		$diagnostics['title'] = __( 'advanced-cache.php is missing', 'surge' );
		$diagnostics['description'] = __( 'The advanced-cache drop-in is missing, so Surge cannot serve cached pages early.', 'surge' );
		return $diagnostics;
	}

	if ( ! $advanced_cache_owned ) {
		$diagnostics['state'] = 'critical';
		$diagnostics['title'] = __( 'advanced-cache.php is not owned by Surge', 'surge' );
		$diagnostics['description'] = __( 'The current advanced-cache drop-in does not appear to belong to Surge.', 'surge' );
		return $diagnostics;
	}

	if ( ! $cache_dir_writable ) {
		$diagnostics['state'] = 'critical';
		$diagnostics['title'] = __( 'Cache directory is not writable', 'surge' );
		$diagnostics['description'] = __( 'Surge cannot write cache files because the cache directory is missing or not writable.', 'surge' );
	}

	return $diagnostics;
}

/**
 * Build a small config summary for the admin dashboard.
 *
 * @return array<int, array<string, mixed>>
 */
function config_summary_items() {
	$items = [];
	$keys = [ 'ttl', 'ignore_cookies', 'ignore_query_vars', 'variants', 'events', 'fpassthru_alt' ];

	foreach ( $keys as $key ) {
		$value = config( $key );
		$source = config_source( $key );
		$label = strtoupper( $key );
		$display = '';

		switch ( $key ) {
			case 'ttl':
				$label = __( 'TTL', 'surge' );
				$display = sprintf(
					/* translators: %d number of seconds */
					__( '%d seconds', 'surge' ),
					(int) $value
				);
				break;
			case 'ignore_cookies':
				$label = __( 'Ignored cookies', 'surge' );
				$display = empty( $value ) ? __( 'None', 'surge' ) : implode( ', ', $value );
				break;
			case 'ignore_query_vars':
				$label = __( 'Ignored query vars', 'surge' );
				$display = sprintf(
					/* translators: %d number of query vars */
					__( '%d entries', 'surge' ),
					count( $value )
				);
				break;
			case 'variants':
				$label = __( 'Cache variants', 'surge' );
				$display = empty( $value ) ? __( 'None', 'surge' ) : sprintf(
					/* translators: %d number of variants */
					__( '%d configured', 'surge' ),
					count( $value )
				);
				break;
			case 'events':
				$label = __( 'Event callbacks', 'surge' );
				$display = empty( $value ) ? __( 'None', 'surge' ) : sprintf(
					/* translators: %d number of event types */
					__( '%d configured', 'surge' ),
					count( $value )
				);
				break;
			case 'fpassthru_alt':
				$label = __( 'Alternative passthru', 'surge' );
				$display = $value ? __( 'Enabled', 'surge' ) : __( 'Disabled', 'surge' );
				break;
		}

		$items[] = [
			'key' => $key,
			'label' => $label,
			'value' => $value,
			'displayValue' => $display,
			'source' => $source,
			'locked' => in_array( $source, [ 'constant', 'wp_cache_config' ], true ),
		];
	}

	return $items;
}

/**
 * Build the admin dashboard payload.
 *
 * @return array<string, mixed>
 */
function admin_dashboard_data() {
	$diagnostics = install_diagnostics();
	list( $size, $count ) = cache_stats();
	$health_state = function( $value ) {
		return $value ? 'success' : 'danger';
	};
	$ttl = (int) config( 'ttl' );
	$ui_settings = ui_settings();
	$ttl_source = config_source( 'ttl' );
	$ttl_locked = in_array( $ttl_source, [ 'constant', 'wp_cache_config' ], true );
	$ignore_query_vars = (array) config( 'ignore_query_vars' );
	$ignore_query_vars_source = config_source( 'ignore_query_vars' );
	$ignore_query_vars_locked = in_array( $ignore_query_vars_source, [ 'constant', 'wp_cache_config' ], true );
	$ignore_cookies = (array) config( 'ignore_cookies' );
	$ignore_cookies_source = config_source( 'ignore_cookies' );
	$ignore_cookies_locked = in_array( $ignore_cookies_source, [ 'constant', 'wp_cache_config' ], true );

	return [
		'status' => [
			'state' => $diagnostics['state'],
			'title' => $diagnostics['title'],
			'description' => $diagnostics['description'],
		],
		'summary' => [
			'install' => $diagnostics['title'],
			'cacheSize' => size_format( $size ),
			'cacheCount' => $count,
			'ttl' => sprintf(
				/* translators: %d number of seconds */
				__( '%d seconds', 'surge' ),
				$ttl
			),
		],
		'health' => [
			[
				'key' => 'wp_cache',
				'label' => __( 'WP_CACHE enabled', 'surge' ),
				'status' => $health_state( $diagnostics['wpCacheEnabled'] ),
				'details' => '',
			],
			[
				'key' => 'advanced_cache',
				'label' => __( 'advanced-cache.php present', 'surge' ),
				'status' => $health_state( $diagnostics['advancedCachePresent'] ),
				'details' => '',
			],
			[
				'key' => 'advanced_cache_owner',
				'label' => __( 'advanced-cache.php owned by Surge', 'surge' ),
				'status' => $health_state( $diagnostics['advancedCacheOwned'] ),
				'details' => '',
			],
			[
				'key' => 'cache_dir',
				'label' => __( 'Cache directory writable', 'surge' ),
				'status' => $health_state( $diagnostics['cacheDirWritable'] ),
				'details' => '',
			],
		],
		'cache' => [
			'count' => $count,
			'sizeBytes' => $size,
			'sizeLabel' => size_format( $size ),
			'ttl' => $ttl,
		],
		'config' => [
			'items' => config_summary_items(),
		],
		'settings' => [
			'fields' => [
				[
					'key' => 'ttl',
					'label' => __( 'Cache TTL', 'surge' ),
					'type' => 'number',
					'min' => 1,
					'max' => DAY_IN_SECONDS,
					'step' => 1,
					'description' => __( 'How long cached pages stay fresh before they expire.', 'surge' ),
					'uiValue' => isset( $ui_settings['ttl'] ) ? (int) $ui_settings['ttl'] : null,
					'effectiveValue' => $ttl,
					'effectiveLabel' => sprintf(
						/* translators: %d number of seconds */
						__( '%d seconds', 'surge' ),
						$ttl
					),
					'source' => $ttl_source,
					'locked' => $ttl_locked,
					'lockedMessage' => $ttl_locked
						? __( 'This value is overridden by code-level configuration.', 'surge' )
						: '',
				],
				[
					'key' => 'extra_ignore_query_vars',
					'label' => __( 'Additional ignored query vars', 'surge' ),
					'type' => 'textarea',
					'rows' => 5,
					'description' => __( 'Add one query var per line to ignore extra tracking parameters beyond the built-in defaults.', 'surge' ),
					'draftValue' => $ui_settings['extra_ignore_query_vars'] ?? [],
					'uiValue' => $ui_settings['extra_ignore_query_vars'] ?? [],
					'effectiveValue' => $ignore_query_vars,
					'effectiveLabel' => sprintf(
						/* translators: %d number of query vars */
						__( '%d entries', 'surge' ),
						count( $ignore_query_vars )
					),
					'source' => $ignore_query_vars_source,
					'locked' => $ignore_query_vars_locked,
					'lockedMessage' => $ignore_query_vars_locked
						? __( 'This value is overridden by code-level configuration.', 'surge' )
						: '',
				],
				[
					'key' => 'extra_ignore_cookies',
					'label' => __( 'Additional ignored cookies', 'surge' ),
					'type' => 'textarea',
					'rows' => 4,
					'description' => __( 'Add one cookie name per line to extend the default anonymous-cookie rules.', 'surge' ),
					'draftValue' => $ui_settings['extra_ignore_cookies'] ?? [],
					'uiValue' => $ui_settings['extra_ignore_cookies'] ?? [],
					'effectiveValue' => $ignore_cookies,
					'effectiveLabel' => empty( $ignore_cookies )
						? __( 'None', 'surge' )
						: implode( ', ', $ignore_cookies ),
					'source' => $ignore_cookies_source,
					'locked' => $ignore_cookies_locked,
					'lockedMessage' => $ignore_cookies_locked
						? __( 'This value is overridden by code-level configuration.', 'surge' )
						: '',
				],
			],
		],
		'endpoints' => [
			'dashboard' => '/surge/v1/admin',
			'flush' => '/surge/v1/admin/flush',
			'flushDelete' => '/surge/v1/admin/flush?delete=1',
			'reinstall' => '/surge/v1/admin/reinstall',
			'settings' => '/surge/v1/admin/settings',
		],
	];
}

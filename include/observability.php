<?php
/**
 * Surge observability helpers.
 *
 * @package Surge
 */

namespace Surge;

const OBSERVABILITY_DEBUG_SESSION_OPTION = 'surge_observability_debug_session';
const OBSERVABILITY_LOG_HEADER = "<?php exit; ?>\n";

/**
 * Return the observability directory.
 *
 * @return string
 */
function observability_dir() {
	return CACHE_DIR . '/observability';
}

/**
 * Return the debug session mirror file path.
 *
 * @return string
 */
function observability_debug_session_file() {
	return observability_dir() . '/debug-session.json.php';
}

/**
 * Return a log file path for a channel.
 *
 * @param string $channel Log channel.
 *
 * @return string
 */
function observability_log_path( $channel ) {
	$files = [
		'admin' => 'admin.log.php',
		'invalidations' => 'invalidations.log.php',
		'requests' => 'requests.log.php',
	];

	return observability_dir() . '/' . ( $files[ $channel ] ?? "{$channel}.log.php" );
}

/**
 * Ensure the observability directory exists.
 *
 * @return bool
 */
function observability_ensure_dir() {
	if ( is_dir( observability_dir() ) ) {
		return true;
	}

	if ( function_exists( 'wp_mkdir_p' ) ) {
		return wp_mkdir_p( observability_dir() );
	}

	return mkdir( observability_dir(), 0777, true );
}

/**
 * Encode data as JSON without relying on WordPress bootstrap timing.
 *
 * @param mixed $value Value to encode.
 *
 * @return string|false
 */
function observability_json_encode( $value ) {
	if ( function_exists( 'wp_json_encode' ) ) {
		return wp_json_encode( $value );
	}

	return json_encode( $value );
}

/**
 * Return the default channel limits.
 *
 * @param string $channel Log channel.
 *
 * @return array{max_entries: int, max_bytes: int}
 */
function observability_log_limits( $channel ) {
	$limits = [
		'admin' => [
			'max_entries' => 25,
			'max_bytes' => 32768,
		],
		'invalidations' => [
			'max_entries' => 25,
			'max_bytes' => 32768,
		],
		'requests' => [
			'max_entries' => 200,
			'max_bytes' => 262144,
		],
	];

	return $limits[ $channel ] ?? [
		'max_entries' => 25,
		'max_bytes' => 32768,
	];
}

/**
 * Read raw log lines from a bounded log file.
 *
 * @param string $path Log path.
 *
 * @return array<int, string>
 */
function observability_read_log_lines( $path ) {
	if ( ! file_exists( $path ) ) {
		return [];
	}

	$raw = file_get_contents( $path );
	if ( false === $raw ) {
		return [];
	}

	if ( 0 === strpos( $raw, OBSERVABILITY_LOG_HEADER ) ) {
		$raw = substr( $raw, strlen( OBSERVABILITY_LOG_HEADER ) );
	}

	$raw = trim( $raw );
	if ( '' === $raw ) {
		return [];
	}

	return array_values(
		array_filter(
			explode( "\n", $raw ),
			static function( $line ) {
				return '' !== trim( $line );
			}
		)
	);
}

/**
 * Trim log lines to the configured entry and size limits.
 *
 * @param array<int, string> $lines Raw log lines.
 * @param int                $max_entries Max entries to keep.
 * @param int                $max_bytes Max serialized bytes to keep.
 *
 * @return array<int, string>
 */
function observability_trim_log_lines( array $lines, $max_entries, $max_bytes ) {
	if ( $max_entries > 0 && count( $lines ) > $max_entries ) {
		$lines = array_slice( $lines, -1 * $max_entries );
	}

	if ( $max_bytes < 1 ) {
		return $lines;
	}

	while ( ! empty( $lines ) ) {
		$serialized = OBSERVABILITY_LOG_HEADER . implode( "\n", $lines ) . "\n";
		if ( strlen( $serialized ) <= $max_bytes ) {
			break;
		}

		array_shift( $lines );
	}

	return $lines;
}

/**
 * Write raw lines back to a log file.
 *
 * @param string             $path Log path.
 * @param array<int, string> $lines Raw log lines.
 *
 * @return bool
 */
function observability_write_log_lines( $path, array $lines ) {
	if ( ! observability_ensure_dir() ) {
		return false;
	}

	$contents = OBSERVABILITY_LOG_HEADER;
	if ( ! empty( $lines ) ) {
		$contents .= implode( "\n", $lines ) . "\n";
	}

	return false !== file_put_contents( $path, $contents, LOCK_EX );
}

/**
 * Append an entry to a bounded channel log.
 *
 * @param string $channel Log channel.
 * @param array  $entry Entry payload.
 * @param array  $limits Optional per-write limits.
 *
 * @return bool
 */
function observability_append_log( $channel, array $entry, array $limits = [] ) {
	$defaults = observability_log_limits( $channel );
	$max_entries = isset( $limits['max_entries'] ) ? (int) $limits['max_entries'] : $defaults['max_entries'];
	$max_bytes = isset( $limits['max_bytes'] ) ? (int) $limits['max_bytes'] : $defaults['max_bytes'];
	$encoded = observability_json_encode( $entry );

	if ( false === $encoded ) {
		return false;
	}

	$path = observability_log_path( $channel );
	$lines = observability_read_log_lines( $path );
	$lines[] = $encoded;
	$lines = observability_trim_log_lines( $lines, $max_entries, $max_bytes );

	return observability_write_log_lines( $path, $lines );
}

/**
 * Read recent decoded entries for a channel.
 *
 * @param string $channel Log channel.
 * @param int    $limit Max entries to return.
 *
 * @return array<int, array<string, mixed>>
 */
function observability_read_recent_entries( $channel, $limit = 10 ) {
	$lines = observability_read_log_lines( observability_log_path( $channel ) );
	$entries = [];

	foreach ( array_reverse( $lines ) as $line ) {
		$decoded = json_decode( $line, true );
		if ( ! is_array( $decoded ) ) {
			continue;
		}

		$entries[] = $decoded;
		if ( count( $entries ) >= $limit ) {
			break;
		}
	}

	return $entries;
}

/**
 * Return the allowed debug session durations.
 *
 * @return array<int, string>
 */
function observability_allowed_debug_durations() {
	return [ '1h', '3h', '12h', '24h', '3d' ];
}

/**
 * Normalize a selected debug duration to seconds.
 *
 * @param string $duration Duration token.
 *
 * @return int
 */
function observability_debug_duration_seconds( $duration ) {
	$hour = defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600;
	$day = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
	$durations = [
		'1h' => $hour,
		'3h' => 3 * $hour,
		'12h' => 12 * $hour,
		'24h' => $day,
		'3d' => 3 * $day,
	];

	return (int) ( $durations[ $duration ] ?? 0 );
}

/**
 * Format a Unix timestamp as UTC ISO-8601.
 *
 * @param int|null $timestamp Unix timestamp.
 *
 * @return string|null
 */
function observability_format_timestamp( $timestamp ) {
	if ( empty( $timestamp ) ) {
		return null;
	}

	return gmdate( 'Y-m-d\TH:i:s\Z', (int) $timestamp );
}

/**
 * Return the empty debug session payload.
 *
 * @return array<string, mixed>
 */
function observability_empty_debug_session() {
	return [
		'active' => false,
		'duration' => null,
		'enabledAt' => null,
		'enabledAtIso' => null,
		'expiresAt' => null,
		'expiresAtIso' => null,
		'remainingSeconds' => 0,
	];
}

/**
 * Read the raw debug session mirror file.
 *
 * @return array<string, mixed>
 */
function observability_read_debug_session_file() {
	$path = observability_debug_session_file();
	if ( ! file_exists( $path ) ) {
		return [];
	}

	$raw = file_get_contents( $path );
	if ( false === $raw ) {
		return [];
	}

	if ( 0 === strpos( $raw, OBSERVABILITY_LOG_HEADER ) ) {
		$raw = substr( $raw, strlen( OBSERVABILITY_LOG_HEADER ) );
	}

	$decoded = json_decode( trim( $raw ), true );
	return is_array( $decoded ) ? $decoded : [];
}

/**
 * Persist the raw debug session payload for both admin and early cache paths.
 *
 * @param array<string, mixed> $session Raw session payload.
 *
 * @return bool
 */
function observability_write_debug_session( array $session ) {
	if ( ! observability_ensure_dir() ) {
		return false;
	}

	$encoded = observability_json_encode( $session );
	if ( false === $encoded ) {
		return false;
	}

	$persisted = false !== file_put_contents(
		observability_debug_session_file(),
		OBSERVABILITY_LOG_HEADER . $encoded . "\n",
		LOCK_EX
	);

	if ( function_exists( 'update_option' ) ) {
		update_option( OBSERVABILITY_DEBUG_SESSION_OPTION, $session, false );
	}

	return $persisted;
}

/**
 * Clear the debug session state.
 *
 * @return void
 */
function observability_clear_debug_session() {
	if ( function_exists( 'delete_option' ) ) {
		delete_option( OBSERVABILITY_DEBUG_SESSION_OPTION );
	}

	$path = observability_debug_session_file();
	if ( file_exists( $path ) ) {
		unlink( $path );
	}
}

/**
 * Normalize a raw debug session payload.
 *
 * @param mixed $raw Raw payload.
 * @param bool  $persist_expiry Whether to clear stale session state.
 *
 * @return array<string, mixed>
 */
function observability_normalize_debug_session( $raw, $persist_expiry = true ) {
	if ( ! is_array( $raw ) ) {
		return observability_empty_debug_session();
	}

	$duration = isset( $raw['duration'] ) ? (string) $raw['duration'] : null;
	$enabled_at = isset( $raw['enabledAt'] ) ? (int) $raw['enabledAt'] : 0;
	$expires_at = isset( $raw['expiresAt'] ) ? (int) $raw['expiresAt'] : 0;
	$remaining = max( 0, $expires_at - time() );
	$active = ! empty( $duration )
		&& in_array( $duration, observability_allowed_debug_durations(), true )
		&& $enabled_at > 0
		&& $expires_at > time();

	if ( ! $active && $expires_at > 0 && $expires_at <= time() && $persist_expiry ) {
		observability_clear_debug_session();
	}

	if ( ! $active ) {
		return observability_empty_debug_session();
	}

	return [
		'active' => true,
		'duration' => $duration,
		'enabledAt' => $enabled_at,
		'enabledAtIso' => observability_format_timestamp( $enabled_at ),
		'expiresAt' => $expires_at,
		'expiresAtIso' => observability_format_timestamp( $expires_at ),
		'remainingSeconds' => $remaining,
	];
}

/**
 * Read the current debug session.
 *
 * @return array<string, mixed>
 */
function observability_read_debug_session() {
	$raw = function_exists( 'get_option' )
		? get_option( OBSERVABILITY_DEBUG_SESSION_OPTION, [] )
		: [];

	if ( empty( $raw ) ) {
		$raw = observability_read_debug_session_file();
	}

	return observability_normalize_debug_session( $raw );
}

/**
 * Start a debug session.
 *
 * @param string $duration Duration token.
 *
 * @return array<string, mixed>
 */
function observability_start_debug_session( $duration ) {
	$seconds = observability_debug_duration_seconds( $duration );
	if ( $seconds < 1 ) {
		return observability_empty_debug_session();
	}

	$session = [
		'duration' => $duration,
		'enabledAt' => time(),
		'expiresAt' => time() + $seconds,
	];

	observability_write_debug_session( $session );
	return observability_read_debug_session();
}

/**
 * Stop the active debug session.
 *
 * @return array<string, mixed>
 */
function observability_stop_debug_session() {
	observability_clear_debug_session();
	return observability_empty_debug_session();
}

/**
 * Return whether debug capture is active.
 *
 * @return bool
 */
function observability_is_debug_capture_active() {
	$session = observability_read_debug_session();
	return ! empty( $session['active'] );
}

/**
 * Get or set the current request reason code.
 *
 * @param string|null $new_reason Reason code to persist.
 *
 * @return string
 */
function observability_request_reason( $new_reason = null ) {
	static $reason = 'cache_file_missing';

	if ( null === $new_reason ) {
		return $reason;
	}

	$reason = $new_reason;
	return $reason;
}

/**
 * Return a short cache key fingerprint.
 *
 * @param mixed $cache_key Raw cache key or hash.
 *
 * @return string
 */
function observability_cache_key_fingerprint( $cache_key = null ) {
	if ( is_array( $cache_key ) || null === $cache_key ) {
		$cache_key = md5( json_encode( null === $cache_key ? key() : $cache_key ) );
	}

	return substr( (string) $cache_key, 0, 12 );
}

/**
 * Build a request sample payload.
 *
 * @param string $outcome Outcome badge.
 * @param string $reason Reason code.
 * @param array  $context Optional context.
 *
 * @return array<string, mixed>
 */
function observability_build_request_sample( $outcome, $reason, array $context = [] ) {
	$path = isset( $context['path'] ) ? (string) $context['path'] : '';
	if ( '' === $path && isset( $_SERVER['REQUEST_URI'] ) ) {
		$path = (string) parse_url( 'http://example.org' . $_SERVER['REQUEST_URI'], PHP_URL_PATH );
	}

	$cache_key = $context['cacheKey'] ?? null;

	return [
		'type' => 'surge.observability.request_sample.v1',
		'logged_at' => observability_format_timestamp( time() ),
		'outcome' => $outcome,
		'reason' => $reason,
		'path' => $path,
		'cacheKey' => observability_cache_key_fingerprint( $cache_key ),
	];
}

/**
 * Append a request sample if debug capture is active.
 *
 * @param array<string, mixed> $entry Request sample.
 *
 * @return bool
 */
function observability_log_request_sample( array $entry ) {
	if ( ! observability_is_debug_capture_active() ) {
		return false;
	}

	return observability_append_log( 'requests', $entry );
}

/**
 * Append a sample for the current request state.
 *
 * @param array $context Optional context overrides.
 *
 * @return bool
 */
function observability_log_current_request_sample( array $context = [] ) {
	return observability_log_request_sample(
		observability_build_request_sample(
			status(),
			observability_request_reason(),
			$context
		)
	);
}

/**
 * Append an admin audit entry.
 *
 * @param string $action Action key.
 * @param string $summary Result summary.
 * @param array  $context Optional context fields.
 *
 * @return bool
 */
function observability_log_admin_action( $action, $summary, array $context = [] ) {
	$entry = [
		'type' => 'surge.observability.admin_audit.v1',
		'logged_at' => observability_format_timestamp( time() ),
		'action' => $action,
		'userId' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
		'summary' => $summary,
	];

	return observability_append_log( 'admin', array_merge( $entry, $context ) );
}

/**
 * Append an invalidation summary entry.
 *
 * @param array $flags Expired flags.
 * @param array $context Optional context.
 *
 * @return bool
 */
function observability_log_invalidation_summary( array $flags, array $context = [] ) {
	$flags = array_values( array_unique( array_map( 'strval', $flags ) ) );
	sort( $flags );

	$scope = 'semantic';
	if ( ! empty( $flags ) ) {
		$path_flags = array_filter( $flags, static function( $flag ) {
			return '/' === substr( $flag, 0, 1 );
		} );

		if ( count( $path_flags ) === count( $flags ) ) {
			$scope = 'path';
		} elseif ( ! empty( $path_flags ) ) {
			$scope = 'mixed';
		}
	}

	$entry = [
		'type' => 'surge.observability.invalidation_summary.v1',
		'logged_at' => observability_format_timestamp( time() ),
		'flags' => array_slice( $flags, 0, 12 ),
		'flagCount' => count( $flags ),
		'scope' => $context['scope'] ?? $scope,
		'trigger' => isset( $context['trigger'] ) ? (string) $context['trigger'] : 'runtime',
	];

	return observability_append_log( 'invalidations', $entry );
}

/**
 * Return the dashboard observability payload.
 *
 * @return array<string, mixed>
 */
function observability_dashboard_payload() {
	$admin_actions = observability_read_recent_entries( 'admin', 5 );
	$invalidations = observability_read_recent_entries( 'invalidations', 5 );
	$session = observability_read_debug_session();
	$request_samples = $session['active']
		? observability_read_recent_entries( 'requests', 12 )
		: [];

	return [
		'summary' => [
			[
				'key' => 'adminActions',
				'label' => __( 'Recent admin actions', 'surge' ),
				'value' => count( $admin_actions ),
			],
			[
				'key' => 'invalidations',
				'label' => __( 'Recent invalidations', 'surge' ),
				'value' => count( $invalidations ),
			],
			[
				'key' => 'debugMode',
				'label' => __( 'Debug capture', 'surge' ),
				'value' => $session['active'] ? __( 'Active', 'surge' ) : __( 'Inactive', 'surge' ),
			],
		],
		'adminActions' => [
			'items' => $admin_actions,
			'emptyTitle' => __( 'No admin actions yet', 'surge' ),
			'emptyDescription' => __( 'Flush, reinstall, and settings saves will appear here.', 'surge' ),
		],
		'invalidations' => [
			'items' => $invalidations,
			'emptyTitle' => __( 'No invalidations yet', 'surge' ),
			'emptyDescription' => __( 'Expired flags are summarized here after they are written.', 'surge' ),
		],
		'debugSession' => array_merge( $session, [
			'availableDurations' => observability_allowed_debug_durations(),
		] ),
		'requestSamples' => [
			'active' => $session['active'],
			'items' => $request_samples,
			'emptyTitle' => __( 'Debug capture is inactive', 'surge' ),
			'emptyDescription' => __( 'Start a timed debug session to capture recent request outcomes.', 'surge' ),
		],
	];
}

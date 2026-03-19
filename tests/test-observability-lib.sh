#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

php <<'PHP'
<?php
declare(strict_types=1);

$rootDir = getcwd();
$tmpDir = sys_get_temp_dir() . '/surge-observability-' . bin2hex(random_bytes(4));
if (!mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
	fwrite(STDERR, "Could not create temp dir.\n");
	exit(1);
}

register_shutdown_function(static function () use ($tmpDir): void {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ($iterator as $item) {
		$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
	}

	@rmdir($tmpDir);
});

define('WP_CONTENT_DIR', $tmpDir);

$options = [];

function get_option($key, $default = false) {
	global $options;
	return array_key_exists($key, $options) ? $options[$key] : $default;
}

function update_option($key, $value, $autoload = false) {
	global $options;
	$options[$key] = $value;
	return true;
}

function delete_option($key) {
	global $options;
	unset($options[$key]);
	return true;
}

function wp_json_encode($value) {
	return json_encode($value);
}

function wp_mkdir_p($path) {
	if (is_dir($path)) {
		return true;
	}

	return mkdir($path, 0777, true);
}

function trailingslashit($path) {
	return rtrim($path, '/\\') . '/';
}

function __($text, $domain = null) {
	return $text;
}

require $rootDir . '/include/common.php';

function assert_true($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "Assertion failed: {$message}\n");
		exit(1);
	}
}

function assert_same($expected, $actual, $message) {
	if ($expected !== $actual) {
		fwrite(STDERR, "Assertion failed: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
		exit(1);
	}
}

assert_same(['1h', '3h', '12h', '24h', '3d'], \Surge\observability_allowed_debug_durations(), 'allowed durations should match the contract');
assert_same(10800, \Surge\observability_debug_duration_seconds('3h'), '3h should normalize to seconds');

\Surge\observability_append_log('admin', [
	'type' => 'surge.observability.admin_audit.v1',
	'logged_at' => '2026-03-19T12:00:00Z',
	'action' => 'flush',
], [
	'max_entries' => 2,
	'max_bytes' => 2048,
]);

\Surge\observability_append_log('admin', [
	'type' => 'surge.observability.admin_audit.v1',
	'logged_at' => '2026-03-19T12:05:00Z',
	'action' => 'save-settings',
], [
	'max_entries' => 2,
	'max_bytes' => 2048,
]);

\Surge\observability_append_log('admin', [
	'type' => 'surge.observability.admin_audit.v1',
	'logged_at' => '2026-03-19T12:10:00Z',
	'action' => 'reinstall',
], [
	'max_entries' => 2,
	'max_bytes' => 2048,
]);

$recent = \Surge\observability_read_recent_entries('admin', 5);

assert_same(2, count($recent), 'bounded append should keep only the newest entries');
assert_same('reinstall', $recent[0]['action'], 'most recent entry should be first');
assert_same('save-settings', $recent[1]['action'], 'second most recent entry should be retained');

$session = \Surge\observability_start_debug_session('1h');

assert_true($session['active'], 'debug session should become active after start');
assert_same('1h', $session['duration'], 'debug session should preserve the selected duration');
assert_true($session['expiresAt'] > $session['enabledAt'], 'debug session should record a future expiry');
assert_true(\Surge\observability_is_debug_capture_active(), 'active session should enable request capture');

\Surge\observability_stop_debug_session();

assert_true(!\Surge\observability_is_debug_capture_active(), 'stopping the session should disable request capture');
PHP

<?php
/**
 * Surge UI-owned settings.
 *
 * @package Surge
 */

namespace Surge;

const OPTIONS_KEY = 'surge_ui_settings';

/**
 * Return the UI-owned settings schema.
 *
 * @return array<string, array<string, mixed>>
 */
function ui_settings_schema() {
	$max_ttl = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;

	return [
		'ttl' => [
			'type' => 'integer',
			'min' => 1,
			'max' => $max_ttl,
		],
		'extra_ignore_query_vars' => [
			'type' => 'list',
		],
		'extra_ignore_cookies' => [
			'type' => 'list',
		],
	];
}

/**
 * Sanitize a list-style setting from textarea or array input.
 *
 * @param mixed $value Raw setting value.
 *
 * @return array<int, string>
 */
function sanitize_ui_setting_list( $value ) {
	if ( is_array( $value ) ) {
		$items = $value;
	} else {
		$items = preg_split( '/[\r\n,]+/', (string) $value );
	}

	if ( ! is_array( $items ) ) {
		return [];
	}

	$items = array_map( 'trim', $items );
	$items = array_filter( $items, static function( $item ) {
		return '' !== $item;
	} );

	return array_values( array_unique( $items ) );
}

/**
 * Return the raw UI settings array from the database.
 *
 * @return array<string, mixed>
 */
function ui_settings_raw() {
	if ( ! function_exists( 'get_option' ) ) {
		return [];
	}

	$settings = get_option( OPTIONS_KEY, [] );
	return is_array( $settings ) ? $settings : [];
}

/**
 * Sanitize UI-owned settings input.
 *
 * @param array $input Raw settings input.
 *
 * @return array<string, mixed>
 */
function sanitize_ui_settings( array $input ) {
	$settings = [];
	$schema = ui_settings_schema();

	foreach ( $schema as $key => $field ) {
		if ( ! array_key_exists( $key, $input ) ) {
			continue;
		}

		$value = $input[ $key ];
		if ( '' === $value || null === $value ) {
			continue;
		}

		if ( 'integer' === $field['type'] ) {
			$value = (int) $value;
			$value = max( (int) $field['min'], $value );
			$value = min( (int) $field['max'], $value );
		} elseif ( 'list' === $field['type'] ) {
			$value = sanitize_ui_setting_list( $value );
		}

		if ( [] === $value ) {
			continue;
		}

		$settings[ $key ] = $value;
	}

	return $settings;
}

/**
 * Return the sanitized UI settings.
 *
 * @return array<string, mixed>
 */
function ui_settings() {
	return sanitize_ui_settings( ui_settings_raw() );
}

/**
 * Persist UI-owned settings.
 *
 * @param array $input Raw settings input.
 *
 * @return array<string, mixed>
 */
function save_ui_settings( array $input ) {
	if ( ! function_exists( 'update_option' ) || ! function_exists( 'delete_option' ) ) {
		return [];
	}

	$settings = sanitize_ui_settings( $input );

	if ( empty( $settings ) ) {
		delete_option( OPTIONS_KEY );
		return [];
	}

	update_option( OPTIONS_KEY, $settings, false );
	return $settings;
}

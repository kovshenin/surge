<?php
/**
 * Surge REST API for the admin UI.
 *
 * @package Surge
 */

namespace Surge;

include_once( __DIR__ . '/common.php' );
include_once( __DIR__ . '/options.php' );

add_action( 'rest_api_init', function() {
	register_rest_route( 'surge/v1', '/admin', [
		'methods' => \WP_REST_Server::READABLE,
		'callback' => __NAMESPACE__ . '\\rest_get_admin_dashboard',
		'permission_callback' => __NAMESPACE__ . '\\rest_admin_permissions',
	] );

	register_rest_route( 'surge/v1', '/admin/flush', [
		'methods' => \WP_REST_Server::CREATABLE,
		'callback' => __NAMESPACE__ . '\\rest_flush_cache',
		'permission_callback' => __NAMESPACE__ . '\\rest_admin_permissions',
		'args' => [
			'delete' => [
				'type' => 'boolean',
				'required' => false,
				'default' => false,
			],
		],
	] );

	register_rest_route( 'surge/v1', '/admin/reinstall', [
		'methods' => \WP_REST_Server::CREATABLE,
		'callback' => __NAMESPACE__ . '\\rest_reinstall_cache',
		'permission_callback' => __NAMESPACE__ . '\\rest_admin_permissions',
	] );

	register_rest_route( 'surge/v1', '/admin/settings', [
		'methods' => \WP_REST_Server::CREATABLE,
		'callback' => __NAMESPACE__ . '\\rest_update_settings',
		'permission_callback' => __NAMESPACE__ . '\\rest_admin_permissions',
	] );
} );

/**
 * Permission callback for admin UI routes.
 *
 * @return bool
 */
function rest_admin_permissions() {
	return current_user_can( 'manage_options' );
}

/**
 * Build a consistent admin UI response.
 *
 * @param array       $extra Additional payload data.
 * @param string|null $notice_type Optional notice type.
 * @param string|null $notice_message Optional notice message.
 *
 * @return \WP_REST_Response
 */
function rest_admin_response( array $extra = [], $notice_type = null, $notice_message = null ) {
	$payload = array_merge( [
		'data' => admin_dashboard_data(),
	], $extra );

	if ( $notice_type && $notice_message ) {
		$payload['notice'] = [
			'type' => $notice_type,
			'message' => $notice_message,
		];
	}

	return rest_ensure_response( $payload );
}

/**
 * Return the admin dashboard data.
 *
 * @return \WP_REST_Response
 */
function rest_get_admin_dashboard() {
	return rest_admin_response();
}

/**
 * Flush cache entries.
 *
 * @param \WP_REST_Request $request Request instance.
 *
 * @return \WP_REST_Response
 */
function rest_flush_cache( $request ) {
	$delete = (bool) $request->get_param( 'delete' );
	$result = flush_cache_entries( $delete );

	if ( ! $result['ok'] ) {
		return new \WP_REST_Response( [
			'data' => admin_dashboard_data(),
			'notice' => [
				'type' => 'error',
				'message' => $result['message'],
			],
		], 500 );
	}

	return rest_admin_response(
		[
			'action' => [
				'name' => $delete ? 'flush-delete' : 'flush',
				'mode' => $result['mode'],
			],
		],
		'success',
		$result['message']
	);
}

/**
 * Re-run the install routine to repair Surge.
 *
 * @return \WP_REST_Response
 */
function rest_reinstall_cache() {
	include( __DIR__ . '/install.php' );

	$diagnostics = install_diagnostics();
	if ( 'good' !== $diagnostics['state'] && 'warning' !== $diagnostics['state'] ) {
		return new \WP_REST_Response( [
			'data' => admin_dashboard_data(),
			'notice' => [
				'type' => 'error',
				'message' => __( 'Surge could not be reinstalled successfully.', 'surge' ),
			],
		], 500 );
	}

	return rest_admin_response(
		[
			'action' => [
				'name' => 'reinstall',
			],
		],
		'success',
		__( 'Surge install files were refreshed.', 'surge' )
	);
}

/**
 * Save UI-owned admin settings.
 *
 * @param \WP_REST_Request $request Request instance.
 *
 * @return \WP_REST_Response
 */
function rest_update_settings( $request ) {
	$payload = $request->get_json_params();
	$settings = [];

	if ( is_array( $payload ) && isset( $payload['settings'] ) && is_array( $payload['settings'] ) ) {
		$settings = $payload['settings'];
	}

	save_ui_settings( $settings );
	reset_config_snapshot();

	return rest_admin_response(
		[
			'action' => [
				'name' => 'save-settings',
			],
		],
		'success',
		__( 'Surge settings were saved.', 'surge' )
	);
}

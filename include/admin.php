<?php
/**
 * Surge admin UI integration.
 *
 * @package Surge
 */

namespace Surge;

include_once( __DIR__ . '/common.php' );

/**
 * Get the admin page hook suffix.
 *
 * @param string|null $new_hook Optional hook suffix to persist.
 *
 * @return string
 */
function admin_page_hook( $new_hook = null ) {
	static $hook = '';

	if ( null !== $new_hook ) {
		$hook = $new_hook;
	}

	return $hook;
}

add_action( 'admin_menu', function() {
	$hook = add_options_page(
		__( 'Surge', 'surge' ),
		__( 'Surge', 'surge' ),
		'manage_options',
		'surge',
		__NAMESPACE__ . '\\render_admin_page'
	);

	admin_page_hook( $hook );
} );

add_action( 'admin_enqueue_scripts', function( $hook ) {
	if ( $hook !== admin_page_hook() ) {
		return;
	}

	$asset_file = dirname( __DIR__ ) . '/build/admin.asset.php';
	if ( ! file_exists( $asset_file ) ) {
		return;
	}

	$asset = require $asset_file;

	wp_enqueue_script(
		'surge-admin',
		plugins_url( 'build/admin.js', dirname( __DIR__ ) . '/surge.php' ),
		$asset['dependencies'],
		$asset['version'],
		true
	);

	$css_file = dirname( __DIR__ ) . '/build/admin.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'surge-admin',
			plugins_url( 'build/admin.css', dirname( __DIR__ ) . '/surge.php' ),
			[],
			$asset['version']
		);
	}

	wp_localize_script( 'surge-admin', 'surgeAdmin', [
		'apiBase' => untrailingslashit( rest_url() ),
		'nonce' => wp_create_nonce( 'wp_rest' ),
		'initialData' => admin_dashboard_data(),
	] );

	wp_set_script_translations( 'surge-admin', 'surge' );
} );

/**
 * Render the admin page container.
 *
 * @return void
 */
function render_admin_page() {
	$asset_file = dirname( __DIR__ ) . '/build/admin.asset.php';
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Surge', 'surge' ); ?></h1>
		<div id="surge-admin-root"></div>
		<?php if ( ! file_exists( $asset_file ) ) : ?>
			<div class="notice notice-warning">
				<p><?php esc_html_e( 'The Surge admin app is not built yet. Run the JavaScript build to load the dashboard UI.', 'surge' ); ?></p>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

<?php
/**
 * Cache invalidation routines
 *
 * This is where we flag and expire various requests to invalidate updated
 * content.
 *
 * @package Surge
 */

namespace Surge;

include_once( __DIR__ . '/common.php' );

// WooCommerce has some internal WP_Query extensions with transient caching,
// so the_posts and other core filters will often not run. Getting the product
// title however is a good indication that a product appears on some page.
add_filter( 'woocommerce_product_title', function( $title, $product ) {
	flag( sprintf( 'post:%d:%d', get_current_blog_id(), $product->get_id() ) );
	return $title;
}, 10, 2 );

// When a post is published, or unpublished, we need to invalidate various
// different pages featuring that specific post type.
add_action( 'transition_post_status', function( $status, $old_status, $post ) {
	if ( $status == $old_status ) {
		return;
	}

	// Only if the post type is public.
	$obj = get_post_type_object( $post->post_type );
	if ( ! $obj || ! $obj->public ) {
		return;
	}

	$status = get_post_status_object( $status );
	$old_status = get_post_status_object( $old_status );

	// To or from a public post status.
	if ( ( $status && $status->public ) || ( $old_status && $old_status->public ) ) {
		expire( 'post_type:' . $post->post_type );
	}
}, 10, 3 );

// Filter WP_Query at the stage where the query was completed, the results have
// been fetched and sorted, as well as accounted and offset for sticky posts.
// Here we attempt to guess which posts appear on this requests and set flags
// accordingly. We also attempt to set more generic flags based on the query.
add_filter( 'the_posts', function( $posts, $query ) {
	$post_ids = wp_list_pluck( $posts, 'ID' );
	$blog_id = get_current_blog_id();

	foreach ( $post_ids as $id ) {
		flag( sprintf( 'post:%d:%d', $blog_id, $id ) );
	}

	// Nothing else to do if it's a singular query.
	if ( $query->is_singular ) {
		return $posts;
	}

	// If it's a query for multiple posts, then flag it with the post types.
	// TODO: Add proper support for post_type => any
	$post_types = $query->get( 'post_type' );
	if ( empty( $post_types ) ) {
		$post_types = [ 'post' ];
	} elseif ( is_string( $post_types ) ) {
		$post_types = [ $post_types ];
	}

	// Add flags for public post types.
	foreach ( $post_types as $post_type ) {
		$obj = get_post_type_object( $post_type );
		if ( is_null( $obj ) || ! $obj->public ) {
			continue;
		}

		flag( 'post_type:' . $post_type );
	}

	return $posts;
}, 10, 2 );

// Flag feeds
$flag_feed = function() { flag( 'feed:' . get_current_blog_id() ); };
add_action( 'do_feed_rdf', $flag_feed );
add_action( 'do_feed_rss', $flag_feed );
add_action( 'do_feed_rss2', $flag_feed );
add_action( 'do_feed_atom', $flag_feed );

// Expire flags when post cache is cleaned.
add_action( 'clean_post_cache', function( $post_id, $post ) {
	if ( wp_is_post_revision( $post ) ) {
		return;
	}

	$blog_id = get_current_blog_id();
	expire( sprintf( 'post:%d:%d', $blog_id, $post_id ) );
}, 10, 2 );

// Multisite network/blog flags.
add_action( 'init', function() {
	if ( is_multisite() ) {
		flag( sprintf( 'network:%d:%d', get_current_network_id(), get_current_blog_id() ) );
	}
} );

// Last-minute expirations, save flags.
add_action( 'shutdown', function() {
	$flush_actions = [
		'activate_plugin',
		'deactivate_plugin',
		'switch_theme',
		'customize_save',
		'update_option_permalink_structure',
		'update_option_tag_base',
		'update_option_category_base',
		'update_option_WPLANG',
		'update_option_blogname',
		'update_option_blogdescription',
		'update_option_blog_public',
		'update_option_show_on_front',
		'update_option_page_on_front',
		'update_option_page_for_posts',
		'update_option_posts_per_page',
		'update_option_woocommerce_permalinks',
	];

	$flush_actions = apply_filters( 'surge_flush_actions', $flush_actions );

	$ms_flush_actions = [
		'_core_updated_successfully',
		'automatic_updates_complete',
	];

	$expire_flag = is_multisite()
		? sprintf( 'network:%d:%d', get_current_network_id(), get_current_blog_id() )
		: '/';

	foreach ( $flush_actions as $action ) {
		if ( did_action( $action ) ) {
			expire( $expire_flag );
			break;
		}
	}

	// Multisite flush actions expire the entire network.
	foreach ( $ms_flush_actions as $action ) {
		if ( did_action( $action ) ) {
			expire( '/' );
			break;
		}
	}

	$expire = expire();
	if ( empty( $expire ) ) {
		return;
	}

	$flags = null;
	$path = CACHE_DIR . '/flags.json.php';
	$exists = file_exists( $path );
	$mode = $exists ? 'r+' : 'w+';

	// Make sure cache dir exists.
	if ( ! $exists && ! wp_mkdir_p( CACHE_DIR ) ) {
		return;
	}

	$f = fopen( $path, $mode );
	$length = filesize( $path );

	flock( $f, LOCK_EX );

	if ( $length ) {
		$flags = fread( $f, $length );
		$flags = substr( $flags, strlen( '<?php exit; ?>' ) );
		$flags = json_decode( $flags, true );
	}

	if ( ! $flags ) {
		$flags = [];
	}

	foreach ( $expire as $flag ) {
		$flags[ $flag ] = time();
	}

	if ( ! wp_mkdir_p( CACHE_DIR ) ) {
		return $contents;
	}

	if ( $length ) {
		ftruncate( $f, 0 );
		rewind( $f );
	}

	fwrite( $f, '<?php exit; ?>' . json_encode( $flags ) );
	fclose( $f );

	event( 'expire', [ 'flags' => $expire ] );
} );

$expire_feeds = function() { expire( 'feed:' . get_current_blog_id() ); };
add_action( 'update_option_rss_use_excerpt', $expire_feeds );
add_action( 'update_option_posts_per_rss', $expire_feeds );

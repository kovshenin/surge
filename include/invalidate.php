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

// Flag posts in loops.
add_filter( 'the_posts', function( $posts ) {
	$post_ids = wp_list_pluck( $posts, 'ID' );
	$blog_id = get_current_blog_id();

	foreach ( $post_ids as $id ) {
		flag( sprintf( 'post:%d:%d', $blog_id, $id ) );
	}

	return $posts;
} );

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
		'automatic_updates_complete',
		'_core_updated_successfully',
	];

	foreach ( $flush_actions as $action ) {
		if ( did_action( $action ) ) {
			expire('*');
			break;
		}
	}

	$expire = expire();
	if ( empty( $expire ) ) {
		return;
	}

	$flags = null;
	$path = CACHE_DIR . '/flags.json';
	if ( file_exists( $path ) ) {
		$flags = json_decode( file_get_contents( $path ), true );
	}

	if ( ! $flags ) {
		$flags = [];
	}

	foreach ( $expire as $flag ) {
		$flags[ $flag ] = time();
	}

	file_put_contents( $path, json_encode( $flags ), LOCK_EX );
} );

$expire_feeds = function() { expire( 'feed:' . get_current_blog_id() ); };
add_action( 'update_option_rss_use_excerpt', $expire_feeds );
add_action( 'update_option_posts_per_rss', $expire_feeds );

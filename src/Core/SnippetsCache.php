<?php
/**
 * Snippet cache helpers.
 *
 * Provides a thin wp_cache_get / wp_cache_set wrapper around the
 * get_active_snippets() query so the database is only hit once per
 * page load (or once per cache lifetime when a persistent object
 * cache is available).
 *
 * Drop-in replacement: swap every direct call to get_active_snippets()
 * for Admin_Buddy_Snippets::get_active() - no other changes needed.
 *
 * Cache is invalidated automatically on snippet save or delete via the
 * admbud_snippets_cache_bust() helper called from the AJAX handlers.
 *
 * @package Admbud
 * @since   1.1.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns all active snippets, reading from the object cache when possible.
 *
 * @return array<int, array<string, mixed>>
 */
function admbud_get_active_snippets(): array { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- admbud_ is the plugin prefix.
	$cache_key   = 'admbud_active_snippets';
	$cache_group = 'admin_buddy';

	$cached = wp_cache_get( $cache_key, $cache_group );

	if ( false !== $cached ) {
		return $cached;
	}

	// Cache miss - run the query.
	// get_active_snippets() is the legacy function this wraps.
	$snippets = get_active_snippets();

	wp_cache_set( $cache_key, $snippets, $cache_group, HOUR_IN_SECONDS );

	return $snippets;
}

/**
 * Invalidate the active snippets cache.
 *
 * Call this from any AJAX handler that creates, updates, or deletes a snippet.
 *
 * Example:
 *   add_action( 'wp_ajax_admbud_snippets_save',   'admbud_snippets_cache_bust' );
 *   add_action( 'wp_ajax_admbud_snippets_delete',  'admbud_snippets_cache_bust' );
 *   add_action( 'wp_ajax_admbud_snippets_toggle',  'admbud_snippets_cache_bust' );
 */
function admbud_snippets_cache_bust(): void { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- admbud_ is the plugin prefix.
	wp_cache_delete( 'admbud_active_snippets', 'admin_buddy' );
}

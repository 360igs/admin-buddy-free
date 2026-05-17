<?php
/**
 * Admin Buddy - Options Abstraction Layer
 *
 * Single source of truth for all option reads and writes.
 * Every module MUST use admbud_get_option() / admbud_update_option() /
 * admbud_delete_option() instead of get_option() / update_option() / delete_option().
 *
 * Currently a pure pass-through - zero overhead, zero behaviour change
 * on both single-site and multisite (per-site mode).
 *
 * -- ADDING A NEW SETTING -----------------------------------------------------
 *
 * 1. Use admbud_get_option() / admbud_update_option() in your module. Done.
 *
 * -- FUTURE: admin-buddy-network plugin ---------------------------------------
 *
 * When the network plugin is built, it extends this class or hooks into WP
 * filters to implement three-tier inheritance (locked → site → default → fallback).
 * See dev/MULTISITE.md and dev/class-network-admin.php for the full design.
 *
 * @package Admbud
 */

namespace Admbud;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Options {

    /**
     * In-memory cache for the current request.
     * Dedupes repeated get() calls for the same key within a single page load.
     * Not persistent - rebuilt on every request.
     *
     * @var array<string, mixed>
     */
    private static $cache = [];

    /**
     * Sentinel used to distinguish "cached value is null" from "never cached".
     */
    private const UNCACHED = "\0__ab_uncached__\0";

    /**
     * Get the effective value for an option.
     * Request-level cached - first get() per key hits the DB,
     * subsequent get()s return from memory.
     */
    public static function get( string $key, $default = false ) {
        $cached = self::$cache[ $key ] ?? self::UNCACHED;
        if ( $cached !== self::UNCACHED ) {
            // Value was cached. If it was false (the WP-default for missing)
            // and a non-false default was requested, return that default.
            return ( $cached === false && $default !== false ) ? $default : $cached;
        }
        $value = get_option( $key, false );
        self::$cache[ $key ] = $value;
        return ( $value === false && $default !== false ) ? $default : $value;
    }

    /**
     * Update an option for the current site and refresh the runtime cache.
     */
    public static function update( string $key, $value, bool $autoload = true ): bool {
        $result = update_option( $key, $value, $autoload );
        if ( $result ) {
            self::$cache[ $key ] = $value;
        }
        return $result;
    }

    /**
     * Delete an option for the current site and clear its runtime cache entry.
     */
    public static function delete( string $key ): bool {
        unset( self::$cache[ $key ] );
        return delete_option( $key );
    }

    /**
     * Clear the runtime cache (useful for testing or after bulk imports).
     */
    public static function flush_cache(): void {
        self::$cache = [];
    }
}

// -- Global helper functions ---------------------------------------------------
// admbud_get_option(), admbud_update_option(), admbud_delete_option() are defined in
// admin-buddy.php immediately after this file is loaded. They delegate to
// Options::get/update/delete when the class is available, falling back to
// raw WP functions if not. Defining them there (not here) avoids any
// redeclaration conflict if this file is re-included.

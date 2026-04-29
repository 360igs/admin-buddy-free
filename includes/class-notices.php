<?php
/**
 * Notices - suppresses plugin promotional and upsell admin notices.
 *
 * Strategy:
 *  - Buffers the 'admin_notices' and 'all_admin_notices' output.
 *  - Parses the buffer with DOMDocument.
 *  - Removes any notice <div> that does NOT originate from WordPress core
 *    (i.e. registered by a class/function outside of wp-includes/).
 *  - Core security, update, and site-health notices are always preserved.
 *
 * This approach is more reliable than trying to deregister individual
 * plugin callbacks (which change constantly) and avoids blanket removal
 * of all notices (which would hide important WP core messages).
 *
 * @package Admbud
 */

namespace Admbud;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Notices {

    // -- Singleton -------------------------------------------------------------

    private static ?Notices $instance = null;

    public static function get_instance(): Notices {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if ( admbud_get_option( 'admbud_notices_suppress', '1' ) !== '1' ) {
            return;
        }
        // Use a very late priority so all plugins have registered their callbacks.
        add_action( 'admin_init', [ $this, 'intercept_notice_hooks' ], 9999 );

        // Bust the callback cache whenever the active plugin set changes.
        add_action( 'activated_plugin',   [ $this, 'bust_callback_cache' ] );
        add_action( 'deactivated_plugin', [ $this, 'bust_callback_cache' ] );
    }

    // -- Cache management ------------------------------------------------------

    /** Transient key for the resolved plugin-callback list. */
    const CACHE_KEY = 'admbud_notices_plugin_callbacks';

    /** Clear the resolved-callback cache. Called when plugins change. */
    public function bust_callback_cache(): void {
        delete_transient( self::CACHE_KEY );
    }

    // -- Hook interception -----------------------------------------------------

    /**
     * Walk through every callback registered on admin_notices and
     * all_admin_notices, and remove any that originate from plugins
     * (i.e. outside wp-admin/ and wp-includes/).
     *
     * Results are cached in a transient keyed by a hash of the current
     * callback fingerprints, so Reflection is only called when the
     * plugin set actually changes (activation/deactivation busts the cache).
     *
     * IMPORTANT: We cache only (hook, priority, callback-ID) tuples - never
     * the raw callable itself - because callables can be object instances
     * whose __sleep() methods may throw warnings when serialized.
     *
     * This is safer than output buffering because it never touches
     * the DOM and preserves the native WP notice rendering pipeline.
     */
    public function intercept_notice_hooks(): void {
        global $wp_filter;

        $hooks = [ 'admin_notices', 'all_admin_notices', 'user_admin_notices', 'network_admin_notices' ];

        // Build a fingerprint of all registered callbacks across notice hooks.
        // If it matches the cached fingerprint, we can skip Reflection entirely.
        $fingerprint_parts = [];
        foreach ( $hooks as $hook ) {
            if ( empty( $wp_filter[ $hook ] ) ) continue;
            foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
                foreach ( array_keys( $callbacks ) as $id ) {
                    $fingerprint_parts[] = $hook . ':' . $priority . ':' . $id;
                }
            }
        }
        $fingerprint = md5( implode( '|', $fingerprint_parts ) );

        // Try cached resolution list.
        // Stored as [ hook => [ priority => [ id, id, … ] ] ] - no callables.
        $cached = get_transient( self::CACHE_KEY );
        if ( is_array( $cached ) && isset( $cached['fingerprint'] ) && $cached['fingerprint'] === $fingerprint ) {
            // Cache hit - look up each ID in the live $wp_filter and remove it.
            foreach ( $cached['remove'] as [ $hook, $priority, $id ] ) {
                if ( isset( $wp_filter[ $hook ]->callbacks[ $priority ][ $id ] ) ) {
                    $fn = $wp_filter[ $hook ]->callbacks[ $priority ][ $id ]['function'];
                    remove_action( $hook, $fn, $priority );
                }
            }
            return;
        }

        // Cache miss - walk all callbacks with Reflection and build removal list.
        // Store only primitive-safe [ hook, priority, id ] tuples - no objects.
        $to_remove = [];

        foreach ( $hooks as $hook ) {
            if ( empty( $wp_filter[ $hook ] ) ) {
                continue;
            }

            foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
                foreach ( $callbacks as $id => $callback ) {
                    if ( $this->is_plugin_callback( $callback['function'] ) ) {
                        remove_action( $hook, $callback['function'], $priority );
                        // Store only the ID string (safe to serialize), not the callable.
                        $to_remove[] = [ $hook, $priority, $id ];
                    }
                }
            }
        }

        // Cache for next request - 12 hours; busted on plugin activate/deactivate.
        // Only strings and ints in the payload - no objects, no closures.
        set_transient( self::CACHE_KEY, [
            'fingerprint' => $fingerprint,
            'remove'      => $to_remove,
        ], 12 * HOUR_IN_SECONDS );
    }

    // -- Helpers ---------------------------------------------------------------

    /**
     * Determine whether a callable originates from a plugin (as opposed to
     * WordPress core or mu-plugins we want to keep).
     *
     * @param  mixed $callable The callback value from $wp_filter.
     * @return bool            True if it looks like a plugin callback.
     */
    private function is_plugin_callback( $callable ): bool {
        $file = $this->resolve_file( $callable );

        if ( ! $file ) {
            return false;
        }

        // Path matching against a debug_backtrace() result to categorise where
        // a notice callback was defined. WordPress exposes WPINC for the
        // wp-includes folder and WP_PLUGIN_DIR / get_theme_root() for plugins
        // and themes, but provides no constant for the wp-admin folder, so
        // ABSPATH . 'wp-admin/' is the canonical pattern for this comparison.
        $wp_includes = wp_normalize_path( ABSPATH . WPINC . '/' );
        $wp_admin    = wp_normalize_path( ABSPATH . 'wp-admin/' );
        $file        = wp_normalize_path( $file );

        // Keep anything from wp-includes or wp-admin (core).
        if ( str_starts_with( $file, $wp_includes ) || str_starts_with( $file, $wp_admin ) ) {
            return false;
        }

        // Keep callbacks from the active theme (parent + child) and mu-plugins.
        $theme_dir   = wp_normalize_path( get_theme_root() . '/' );
        $mu_dir      = wp_normalize_path( WPMU_PLUGIN_DIR . '/' );

        if ( str_starts_with( $file, $theme_dir ) || str_starts_with( $file, $mu_dir ) ) {
            return false;
        }

        // Keep Admin Buddy's own notices (e.g. custom policy message).
        $admbud_dir = wp_normalize_path( ADMBUD_DIR );
        if ( str_starts_with( $file, $admbud_dir ) ) {
            return false;
        }

        // Anything else (regular plugins) is fair game to suppress.
        $plugins_dir = wp_normalize_path( WP_PLUGIN_DIR . '/' );
        return str_starts_with( $file, $plugins_dir );
    }

    /**
     * Resolve a callable to the file it is defined in.
     *
     * @param  mixed $callable
     * @return string|null Absolute file path, or null if unresolvable.
     */
    private function resolve_file( $callable ): ?string {
        try {
            if ( is_string( $callable ) && function_exists( $callable ) ) {
                $ref  = new \ReflectionFunction( $callable );
                return $ref->getFileName() ?: null;
            }

            if ( is_array( $callable ) && count( $callable ) === 2 ) {
                [ $object_or_class, $method ] = $callable;

                // If the class isn't loaded yet, reflecting on a method would
                // trigger the autoloader - which can cause third-party plugins
                // (e.g. SureCart) to load translations before init.
                // Resolve via ReflectionClass::getFileName() instead, which
                // doesn't require the method to be inspected.
                $class_name = is_object( $object_or_class )
                    ? get_class( $object_or_class )
                    : $object_or_class;

                // Only reflect if the class is already loaded - never autoload.
                if ( ! class_exists( $class_name, false ) ) {
                    return null;
                }

                $ref = new \ReflectionMethod( $object_or_class, $method );
                return $ref->getFileName() ?: null;
            }

            if ( is_object( $callable ) && $callable instanceof \Closure ) {
                $ref = new \ReflectionFunction( $callable );
                return $ref->getFileName() ?: null;
            }

            // Handle __invoke objects.
            if ( is_object( $callable ) && method_exists( $callable, '__invoke' ) ) {
                $ref = new \ReflectionMethod( $callable, '__invoke' );
                return $ref->getFileName() ?: null;
            }
        } catch ( \ReflectionException $e ) {
            // If reflection fails, play it safe and keep the callback.
            return null;
        }

        return null;
    }
}

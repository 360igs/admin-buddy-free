<?php
/**
 * Settings sanitizer methods.
 * Extracted from class-settings.php to reduce file size.
 *
 * @package Admbud
 */

namespace Admbud;

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait Settings_Sanitizers {

    public function sanitize_checkbox( $v ): string  { return ( '1' === $v || true === $v ) ? '1' : '0'; }
    public function sanitize_bg_type( $v ): string   { return in_array( $v, [ 'solid', 'gradient', 'image' ], true ) ? $v : 'solid'; }
    public function sanitize_setup_tabs( $v ): string {
        // Stored as comma-separated list of enabled tab slugs.
        if ( ! is_string( $v ) ) { return ''; }
        $valid = array_keys( $this->get_manageable_tabs() );
        $parts = array_filter( array_map( 'sanitize_key', explode( ',', $v ) ), fn( $s ) => in_array( $s, $valid, true ) );
        return implode( ',', $parts );
    }
    public function sanitize_opacity( $v ): string   { $n = (int) $v; return (string) max( 0, min( 100, $n ) ); }
    public function sanitize_card_position( $v ): string { return in_array( $v, [ 'left', 'center', 'right' ], true ) ? $v : 'center'; }
    public function sanitize_maintenance_mode( $v ): string { return in_array( $v, [ 'off', 'coming_soon', 'maintenance' ], true ) ? $v : 'off'; }
    public function sanitize_logo_width( $v ): int   { $w = absint( $v ); return ( $w >= 40 && $w <= 320 ) ? $w : 84; }

    public function sanitize_logo_height( $v ): int  { $h = absint( $v ); return ( $h === 0 || ( $h >= 30 && $h <= 200 ) ) ? $h : 0; }
    public function sanitize_overlay_opacity( $v ): int { $o = absint( $v ); return $o <= 90 ? $o : 30; }

    /**
     * Sanitize a CSS colour value: accepts #rrggbb, #rgb, rgba(), and hsla().
     * Returns '' for invalid/empty values; the WP settings API uses the registered
     * default when an empty string is saved.
     */
    public function sanitize_color( $v ): string {
        $v = trim( (string) $v );
        if ( ! $v ) { return ''; }
        if ( preg_match( '/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $v ) ) { return $v; }
        if ( preg_match( '/^rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(?:,\s*[\d.]+\s*)?\)$/i', $v ) ) { return $v; }
        if ( preg_match( '/^hsla?\(\s*[\d.]+\s*,\s*[\d.]+%?\s*,\s*[\d.]+%?\s*(?:,\s*[\d.]+\s*)?\)$/i', $v ) ) { return $v; }
        return '';
    }

    /**
     * Hex-only sanitizer for colours used in darken/lighten/RGB calculations.
     * Returns the value if valid hex, otherwise ''.
     */
    public function sanitize_hex_color( $v ): string {
        $v = trim( (string) $v );
        return preg_match( '/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $v ) ? $v : '';
    }

    public function sanitize_grad_direction( $v ): string {
        $allowed = [ 'to top', 'to top right', 'to right', 'to bottom right', 'to bottom', 'to bottom left', 'to left', 'to top left' ];
        return in_array( $v, $allowed, true ) ? $v : Colours::DEFAULT_SIDEBAR_GRAD_DIR;
    }

    public function sanitize_bypass_urls( $v ): string {
        $lines = explode( "\n", (string) $v );
        $clean = [];
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( $line === '' ) continue;
            $parsed = wp_parse_url( $line );
            $path   = isset( $parsed['path'] ) ? '/' . ltrim( $parsed['path'], '/' ) : '';
            if ( $path && $path !== '/' ) $clean[] = sanitize_text_field( $path );
        }
        return implode( "\n", $clean );
    }

    public function sanitize_page_id( $v ): int {
        $id   = absint( $v );
        if ( $id === 0 ) return 0;
        $post = get_post( $id );
        if ( ! $post || $post->post_type !== 'page' || $post->post_status !== 'publish' ) {
            add_settings_error( 'admbud_dashboard_page_id', 'admbud_invalid_page', __( 'Please select a valid published page.', 'admin-buddy' ) );
            return 0;
        }
        return $id;
    }

    /**
     * Sanitize the dashboard role→page mapping JSON.
     * Accepts: {"_default": 42, "editor": 58, "subscriber": "wp_default"}
     * Values: integer page ID, "wp_default", or 0 (inherit default).
     */
    public function sanitize_dashboard_role_pages( $v ): string {
        $decoded = json_decode( wp_unslash( (string) $v ), true );
        if ( ! is_array( $decoded ) ) { return '{}'; }
        $clean = [];
        foreach ( $decoded as $role => $page ) {
            $role = sanitize_key( $role );
            if ( $role === '' ) { continue; }
            // _per_role is a boolean flag, not a page reference.
            if ( $role === '_per_role' ) {
                $clean['_per_role'] = (bool) $page;
                continue;
            }
            if ( $page === 'wp_default' ) {
                $clean[ $role ] = 'wp_default';
            } else {
                $id = absint( $page );
                if ( $id > 0 ) {
                    $post = get_post( $id );
                    if ( $post && $post->post_type === 'page' && in_array( $post->post_status, [ 'publish', 'draft', 'private', 'pending' ], true ) ) {
                        $clean[ $role ] = $id;
                    }
                }
                // 0 or invalid = omit from map (inherits default)
            }
        }
        return wp_json_encode( $clean );
    }

    public function sanitize_smtp_port( $v ): int {
        $p = (int) $v;
        return ( $p >= 1 && $p <= 65535 ) ? $p : 587;
    }

    public function sanitize_smtp_enc( $v ): string {
        return in_array( $v, [ 'tls', 'ssl', 'none' ], true ) ? $v : 'tls';
    }

    public function sanitize_smtp_mailer( $v ): string {
        return in_array( $v, [ 'smtp', 'phpmail' ], true ) ? $v : 'smtp';
    }

    /**
     * Sanitize CSS exclusions - one page slug per line.
     * Strips empty lines, trims whitespace, removes anything that's not
     * a valid slug character.
     */
    public function sanitize_css_exclusions( $v ): string {
        $lines = explode( "\n", (string) $v );
        $clean = [];
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( $line === '' ) { continue; }
            // Allow slug characters: alphanumeric, hyphens, underscores, forward slash
            $line = preg_replace( '/[^a-zA-Z0-9\-_\/]/', '', $line );
            if ( $line !== '' ) { $clean[] = $line; }
        }
        return implode( "\n", array_unique( $clean ) );
    }

    /**
     * Intercept the SMTP form submission to handle password encryption.
     * The password field is never stored in the admbud_smtp_group option blob;
     * it is extracted, encrypted, and saved separately under PASS_OPTION.
     */
    public function maybe_encrypt_smtp_password( $value ): mixed {
        // This filter fires on the serialised group - not useful here.
        // Password is handled in handle_advanced() which reads $_POST directly.
        return $value;
    }

    /**
     * Sanitize the UI rounding option.
     */
    public function sanitize_ui_radius( $value ): string {
        return in_array( $value, [ 'off', 'small', 'medium', 'large' ], true ) ? $value : 'off';
    }
}

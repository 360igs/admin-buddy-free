<?php
/**
 * Colours - Custom admin UI colour scheme.
 *
 * Injects a small <style> block into wp_admin_head that overrides the key
 * WordPress admin CSS custom properties and selectors, applying the user's
 * chosen primary/secondary colours across the entire admin interface.
 *
 * Design decisions:
 *  - Global scope: one colour scheme for all users (simplest, most useful for agencies).
 *  - CSS custom property override: targets --wp-admin-theme-color and friends so
 *    it works with any WP version that uses the variable system (5.3+).
 *  - Hard selector fallbacks: for older WP and themes that don't use vars.
 *  - Does NOT disable WP's built-in colour scheme selector - it simply wins via
 *    higher specificity / later cascade order.
 *  - WCAG luminance: luminance is computed server-side and passed to JS for
 *    the auto-contrast suggestion, keeping PHP as source of truth.
 *
 * Option keys (all in admbud_colours_group):
 *  admbud_colours_enabled       - '0'|'1' master toggle
 *  admbud_colours_primary       - hex e.g. #2563eb
 *  admbud_colours_secondary     - hex e.g. #1d4ed8 (hover / active)
 *  admbud_colours_menu_text     - hex e.g. #ffffff
 *  admbud_colours_menu_bg       - hex e.g. #1e293b (sidebar background)
 *
 * @package Admbud
 */

namespace Admbud;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Colours {

    // -- Defaults -------------------------------------------------------------
    // Single source of truth for all default colours.
    // Referenced by class-settings.php (register_settings, activation hook)
    // so changing a value here is the only edit needed across the entire plugin.

    const DEFAULT_PRIMARY           = '#7c3aed'; // Violet 600 - primary accent
    const DEFAULT_SECONDARY         = '#6d28d9'; // Violet 700 - hover / active
    const DEFAULT_HOVER_BG          = '#6d28d9'; // Violet 700 - menu item hover background
    const DEFAULT_ACTIVE_BG         = '#7c3aed'; // Primary - active menu item background
    const DEFAULT_MENU_TEXT         = '#ede9fe'; // Lavender 100 - sidebar text
    const DEFAULT_ACTIVE_TEXT       = '#ffffff'; // Pure white - active item text (max contrast)
    const DEFAULT_MENU_BG           = '#1e1b2e'; // Deep purple-slate - sidebar bg
    const DEFAULT_SEP_COLOR         = '#5600ed'; // Vivid violet - separator line
    const DEFAULT_SIDEBAR_GRAD_FROM = '#2e1065'; // Deep violet - gradient start
    const DEFAULT_SIDEBAR_GRAD_TO   = '#1e1b2e'; // Deep purple-slate - gradient end
    const DEFAULT_SIDEBAR_GRAD_DIR  = 'to top';  // Sidebar gradient direction

    // Submenu / flyout defaults (sidebar)
    const DEFAULT_SUBMENU_BG           = '#7c3aed'; // Primary - flyout background
    const DEFAULT_SUBMENU_HOVER_BG     = '#7c3aed'; // Violet 600 - flyout hover bg
    const DEFAULT_SUBMENU_HOVER_TEXT   = '#ede9fe'; // Lavender 100 - flyout hover text
    const DEFAULT_SUBMENU_ACTIVE_BG    = '#7c3aed'; // Violet 700 - submenu active bg
    const DEFAULT_SUBMENU_ACTIVE_TEXT  = '#ffffff'; // White - submenu active text

    // Page defaults - Login / Coming Soon / Maintenance share the same palette
    // so the whole plugin feels like one coherent product out of the box.
    const DEFAULT_PAGE_BG           = '#1e1b2e'; // Same as menu bg - dark, branded
    const DEFAULT_PAGE_TEXT         = '#ede9fe'; // Same as menu text - high contrast
    const DEFAULT_PAGE_MESSAGE      = '#c4b5fd'; // Violet 300 - softer than heading

    // Login / maintenance page background type defaults
    const DEFAULT_LOGIN_BG_TYPE     = 'solid';    // Login: solid colour
    const DEFAULT_CS_BG_TYPE        = 'gradient'; // Coming Soon: gradient
    const DEFAULT_MAINT_BG_TYPE     = 'gradient'; // Maintenance: gradient

    // -- Status / semantic colours ---------------------------------------------
    // Used for admin bar pills, badges, and notice elements.
    // Changing a value here updates every usage across the plugin automatically.

    // Coming Soon - amber (warning / pending)
    const COLOR_COMING_SOON         = '#f59e0b'; // Amber 400
    const COLOR_COMING_SOON_HOVER   = '#d97706'; // Amber 500

    // Maintenance - red (danger / offline)
    const COLOR_MAINTENANCE         = '#ef4444'; // Red 500
    const COLOR_MAINTENANCE_HOVER   = '#dc2626'; // Red 600

    // Noindex / Search engines blocked - violet (matches brand, informational)
    const COLOR_NOINDEX             = '#8b5cf6'; // Violet 500
    const COLOR_NOINDEX_HOVER       = '#7c3aed'; // Violet 600

    // -- WP default colours used as fallbacks for Content tokens --------------
    const WP_CONTENT_BG             = '#f0f0f1'; // #wpbody-content default
    const WP_HEADING_TEXT           = '#1d2327'; // WP h1/h2 colour
    const WP_BODY_TEXT              = '#3c434a'; // WP body text
    const WP_LINK                   = '#2271b1'; // WP link blue
    const WP_TABLE_ROW_BG           = '#ffffff'; // WP table row
    const WP_TABLE_ROW_ALT_BG       = '#f6f7f7'; // WP alternating row
    const WP_TABLE_BORDER           = '#c3c4c7'; // WP table borders
    const WP_INPUT_BG               = '#ffffff'; // WP input background
    const WP_INPUT_BORDER           = '#8c8f94'; // WP input border
    const WP_BTN_SECONDARY_BG       = '#f6f7f7'; // WP button-secondary bg
    const WP_POSTBOX_BG             = '#ffffff'; // WP postbox/card bg
    const WP_POSTBOX_HEADER_BG      = '#f6f7f7'; // WP postbox header bg
    const WP_POSTBOX_BORDER         = '#c3c4c7'; // WP postbox border
    const WP_TABLE_ROW_TEXT         = '#3c434a'; // WP table body text
    const WP_TABLE_ROW_SEPARATOR    = '#e1e1e1'; // WP table row separator line

    // Success - green
    const COLOR_SUCCESS             = '#16a34a'; // Green 600

    // Error - red
    const COLOR_ERROR               = '#dc2626'; // Red 600

    // Warning - amber
    const COLOR_WARNING             = '#f59e0b'; // Amber 400

    // -- Singleton -------------------------------------------------------------

    private static ?Colours $instance = null;

    public static function get_instance(): Colours {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_enqueue_scripts', [ $this, 'inject_css'       ] );
        add_action( 'login_enqueue_scripts', [ $this, 'inject_login_css' ] );
        // Front-end admin bar: hook via wp_enqueue_scripts (only fires on front-end, never in wp-admin).
        add_action( 'wp_enqueue_scripts',    [ $this, 'inject_frontend_css' ] );

        // Bust the compiled CSS cache whenever any admbud_colours_* option is updated.
        add_action( 'updated_option', [ __CLASS__, 'maybe_bust_cache' ] );

        // Flush stale colour CSS transients on plugin version change.
        // Covers updates (no activation hook) and fixes corrupted caches from
        // PHP deprecation warnings that may have been captured inside ob_start().
        $last_ver = get_option( 'admbud_colours_css_version', '' );
        if ( $last_ver !== ADMBUD_VERSION ) {
            // Delete current + any legacy transient keys.
            delete_transient( self::cache_key() );
            if ( $last_ver ) { delete_transient( self::CSS_CACHE_PREFIX . $last_ver ); }
            // Repair opacity values zeroed by beta302-309 Settings API bug.
            // The opacity keys were registered in register_settings() but had no
            // form inputs, so WP sanitized absent values to 0 on every form save.
            if ( (int) get_option( 'admbud_colours_menu_bg_opacity', '100' ) === 0 ) {
                update_option( 'admbud_colours_menu_bg_opacity', '100' );
            }
            if ( (int) get_option( 'admbud_colours_menu_text_opacity', '100' ) === 0 ) {
                update_option( 'admbud_colours_menu_text_opacity', '100' );
            }
            update_option( 'admbud_colours_css_version', ADMBUD_VERSION, false );
        }

        // Keep Admin Bar Flyout Bg in sync with Admin Bar Bg whenever it's saved.
        add_action( 'updated_option', function ( string $option, $old, $new ) {
            if ( $option === 'admbud_colours_adminbar_bg' && $new ) {
                admbud_update_option( 'admbud_colours_adminbar_submenu_bg', $new );
            }
        }, 10, 3 );
    }

    /**
     * Transient key for the compiled admin CSS.
     */
    const CSS_CACHE_PREFIX = 'admbud_colours_css_';

    /**
     * Delete the cached CSS when any colour option changes.
     *
     * Hooked to `updated_option` (fires for every update_option call).
     * Also called directly by the preset/palette AJAX handlers.
     */
    private static function cache_key(): string {
        return self::CSS_CACHE_PREFIX . ADMBUD_VERSION;
    }

    public static function maybe_bust_cache( string $option = '' ): void {
        if ( $option === '' || str_starts_with( $option, 'admbud_colours_' ) || $option === 'admbud_css_exclusions' ) {
            delete_transient( self::cache_key() );
        }
    }

    // ============================================================================
    // CSS INJECTION - ADMIN
    // ============================================================================

    public function inject_css(): void {
        // Check if current page is in the CSS exclusion list.
        $exclusions_raw = admbud_get_option( 'admbud_css_exclusions', '' );
        $excluded       = false;
        if ( $exclusions_raw !== '' ) {
            // Read-only display logic: these $_GET values determine which admin
            // screen is being viewed so the CSS exclusion list can match against
            // it. No state change, no database writes, no nonce required. Each
            // read is sanitize_key()'d at the source.
            $params_to_check = array_filter( array_unique( [
                isset( $_GET['page'] )      ? sanitize_key( $_GET['page'] )      : '', // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
                isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
                isset( $_GET['taxonomy'] )  ? sanitize_key( $_GET['taxonomy'] )  : '', // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
            ] ) );

            // Also check via get_current_screen() for reliability at admin_head time
            $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
            if ( $screen ) {
                if ( $screen->id )        { $params_to_check[] = sanitize_key( $screen->id ); }
                if ( $screen->post_type ) { $params_to_check[] = sanitize_key( $screen->post_type ); }
                if ( $screen->taxonomy )  { $params_to_check[] = sanitize_key( $screen->taxonomy ); }
                if ( $screen->base )      { $params_to_check[] = sanitize_key( $screen->base ); }
                $params_to_check = array_unique( $params_to_check );
            }

            $exclusion_list = array_filter( array_map( 'trim', explode( "\n", $exclusions_raw ) ) );

            foreach ( $exclusion_list as $entry ) {
                $entry = sanitize_key( $entry );
                if ( $entry === '' ) { continue; }
                foreach ( $params_to_check as $param ) {
                    if ( $param === '' ) { continue; }
                    // Exact match OR prefix match (e.g. "acf" matches "acf-field-group")
                    if ( $param === $entry || str_starts_with( $param, $entry . '-' ) || str_starts_with( $param, $entry . '_' ) ) {
                        $excluded = true;
                        break 2;
                    }
                }
            }
        }

        // Serve from transient cache when not on an excluded page.
        if ( ! $excluded ) {
            $cached = get_transient( self::cache_key() );
            if ( false !== $cached ) {
                wp_add_inline_style( 'admin-buddy-icon-inject', self::strip_style_tags( $cached ) );
                return;
            }
        }

        // Cache miss or excluded - generate output.
        ob_start();
        $this->build_admin_css( $excluded );
        $css = ob_get_clean();

        // Only persist cache when not excluded (excluded pages skip content colours).
        if ( ! $excluded ) {
            set_transient( self::cache_key(), $css, DAY_IN_SECONDS );
        }

        wp_add_inline_style( 'admin-buddy-icon-inject', self::strip_style_tags( $css ) );
    }

    /**
     * Strip <style id="..."> and </style> wrappers from a captured CSS blob.
     * The cache predates the move to wp_add_inline_style() — historically the
     * blob included <style> tags because it was echoed verbatim into <head>.
     * Now that we route through the styles handle, the wrappers must be removed.
     */
    private static function strip_style_tags( string $css ): string {
        $css = preg_replace( '#<style\b[^>]*>#i', '', $css );
        return (string) preg_replace( '#</style>#i', '', $css );
    }

    /**
     * Build the full admin colour CSS output.
     *
     * Extracted from inject_css() so the output can be captured and cached.
     * All get_option() calls and CSS generation live here.
     */
    private function build_admin_css( bool $excluded = false ): void {
        $primary   = $this->colour( 'admbud_colours_primary',   self::DEFAULT_PRIMARY   );
        $secondary = $this->colour( 'admbud_colours_secondary', self::DEFAULT_SECONDARY );
        $menu_text = $this->colour( 'admbud_colours_menu_text', self::DEFAULT_MENU_TEXT );
        $menu_bg   = $this->colour( 'admbud_colours_menu_bg',   self::DEFAULT_MENU_BG   );

        // Apply opacity to menu colours (0-100 int stored as string).
        $menu_bg_opacity   = (int) admbud_get_option( 'admbud_colours_menu_bg_opacity',   '100' );
        $menu_text_opacity = (int) admbud_get_option( 'admbud_colours_menu_text_opacity', '100' );
        if ( $menu_bg_opacity < 100 ) {
            $menu_bg = $this->hex_to_rgba( $menu_bg, round( $menu_bg_opacity / 100, 2 ) );
        }
        if ( $menu_text_opacity < 100 ) {
            $menu_text = $this->hex_to_rgba( $menu_text, round( $menu_text_opacity / 100, 2 ) );
        }

        $primary_tint  = $this->lighten( $primary, 0.88 );  // very light - table row bg
        $menu_bg_dark  = $this->darken( $menu_bg, 0.12 );   // admin bar bg
        $menu_bg_light = $this->lighten( $menu_bg, 0.08 );  // hover state bg
        $menu_text_dim = $this->dim_colour( $menu_text, 0.75 ); // dimmed label text
        $neutral_gray  = '#646970';                          // WP standard neutral for inputs/buttons

        // Sidebar gradient.
        $sidebar_gradient = admbud_get_option( 'admbud_colours_sidebar_gradient', '0' ) === '1';
        $sidebar_grad_from = $this->colour( 'admbud_colours_sidebar_grad_from', self::DEFAULT_SIDEBAR_GRAD_FROM );
        $sidebar_grad_to   = $this->colour( 'admbud_colours_sidebar_grad_to',   self::DEFAULT_SIDEBAR_GRAD_TO   );

        // New options: active item text, separator colour, body background, hover, SVG tint.
        $active_text_raw = admbud_get_option( 'admbud_colours_active_text', self::DEFAULT_ACTIVE_TEXT );
        $active_text     = $this->colour( 'admbud_colours_active_text', self::DEFAULT_ACTIVE_TEXT );
        $sep_color_raw   = admbud_get_option( 'admbud_colours_sep_color', '' );
        $sep_color       = $sep_color_raw ? $this->colour( 'admbud_colours_sep_color', $primary_tint ) : $primary_tint;
        $body_bg_raw     = admbud_get_option( 'admbud_colours_body_bg', '' );

        // -- Content area tokens (all optional - empty = WP default) ----------
        $ct_heading_raw      = admbud_get_option( 'admbud_colours_content_heading',   '' );
        $ct_text_raw         = admbud_get_option( 'admbud_colours_content_text',      '' );
        $ct_link_raw         = admbud_get_option( 'admbud_colours_content_link',      '' );
        $ct_link_hover_raw   = admbud_get_option( 'admbud_colours_content_link_hover','');
        $ct_tbl_hdr_bg_raw   = admbud_get_option( 'admbud_colours_table_header_bg',   '' );
        $ct_tbl_hdr_txt_raw  = admbud_get_option( 'admbud_colours_table_header_text', '' );
        $ct_tbl_hdr_link_raw = admbud_get_option( 'admbud_colours_table_header_link', '' );
        $ct_tbl_row_bg_raw   = admbud_get_option( 'admbud_colours_table_row_bg',      '' );
        $ct_tbl_row_alt_raw  = admbud_get_option( 'admbud_colours_table_row_alt_bg',  '' );
        $ct_tbl_row_hover_raw= admbud_get_option( 'admbud_colours_table_row_hover',   '' );
        $ct_tbl_border_raw   = admbud_get_option( 'admbud_colours_table_border',      '' );
        $ct_tbl_action_raw   = admbud_get_option( 'admbud_colours_table_action_link', '' );
        $ct_tbl_title_raw    = admbud_get_option( 'admbud_colours_table_title_link',  '' );
        $ct_input_bg_raw     = admbud_get_option( 'admbud_colours_input_bg',          '' );
        $ct_input_border_raw = admbud_get_option( 'admbud_colours_input_border',      '' );
        $ct_input_focus_raw  = admbud_get_option( 'admbud_colours_input_focus',       '' );
        $ct_btn_sec_bg_raw   = admbud_get_option( 'admbud_colours_btn_secondary_bg',  '' );
        $ct_postbox_bg_raw   = admbud_get_option( 'admbud_colours_postbox_bg',        '' );
        $ct_postbox_hdr_raw  = admbud_get_option( 'admbud_colours_postbox_header_bg', '' );
        $ct_postbox_bdr_raw  = admbud_get_option( 'admbud_colours_postbox_border',    '' );
        $ct_postbox_txt_raw  = admbud_get_option( 'admbud_colours_postbox_text',      '' );
        $ct_notice_bg_raw    = admbud_get_option( 'admbud_colours_notice_bg',         '' );
        $ct_tbl_row_txt_raw  = admbud_get_option( 'admbud_colours_table_row_text',      '' );
        $ct_tbl_alt_txt_raw  = admbud_get_option( 'admbud_colours_table_row_alt_text',  '' );
        $ct_tbl_sep_raw      = admbud_get_option( 'admbud_colours_table_row_separator', '' );
        $ct_btn_primary_raw  = admbud_get_option( 'admbud_colours_btn_primary_bg',      '' );
        $ct_btn_pri_txt_raw  = admbud_get_option( 'admbud_colours_btn_primary_text',    '' );
        $ct_btn_pri_hvr_raw  = admbud_get_option( 'admbud_colours_btn_primary_hover',   '' );

        // Admin bar text - explicit override. Empty = auto-derive from menu_text at 75% opacity.
        $adminbar_text_raw   = admbud_get_option( 'admbud_colours_adminbar_text', '' );
        // Admin bar background - explicit override. Empty = inherit menu_bg_dark (p5).
        $adminbar_bg_raw     = admbud_get_option( 'admbud_colours_adminbar_bg', '' );
        // Admin bar hover bg - explicit override. Empty = inherit primary (p1).
        $adminbar_hover_raw  = admbud_get_option( 'admbud_colours_adminbar_hover_bg', '' );
        // Admin bar submenu bg - explicit override. Empty = inherit menu_bg_dark (p5).
        $adminbar_sub_raw    = admbud_get_option( 'admbud_colours_adminbar_submenu_bg', '' );
        // Admin bar hover text - text colour on hover. Empty = inherit adminbar_text (p8).
        $adminbar_hover_text_raw = admbud_get_option( 'admbud_colours_adminbar_hover_text', '' );
        // Admin bar submenu text - text in flyout panels. Empty = inherit adminbar_text (p8).
        $adminbar_sub_text_raw   = admbud_get_option( 'admbud_colours_adminbar_sub_text', '' );
        // Admin bar submenu hover text. Empty = inherit adminbar_text (p8).
        $adminbar_sub_hover_text_raw = admbud_get_option( 'admbud_colours_adminbar_sub_hover_text', '' );
        // Submenu / flyout text - explicit override. Empty = inherit menu_text (p6).
        $submenu_text_raw    = admbud_get_option( 'admbud_colours_submenu_text', '' );
        // Hover text - text colour for top-level items on hover. Empty = inherit menu_text.
        $hover_text_raw      = admbud_get_option( 'admbud_colours_hover_text', '' );
        // Active parent text - text colour for the parent item whose submenu is open. Empty = inherit active_text.
        $active_parent_text_raw = admbud_get_option( 'admbud_colours_active_parent_text', '' );

        // Sidebar submenu / flyout background - explicit override. Empty = inherit primary (p1).
        $submenu_bg_raw      = admbud_get_option( 'admbud_colours_submenu_bg', '' );
        // Sidebar submenu / flyout hover background. Empty = inherit secondary (p3).
        $submenu_hover_bg_raw = admbud_get_option( 'admbud_colours_submenu_hover_bg', '' );
        // Sidebar submenu / flyout hover text. Empty = inherit submenu text (p14).
        $submenu_hover_text_raw = admbud_get_option( 'admbud_colours_submenu_hover_text', '' );
        // Admin bar flyout hover background. Empty = inherit adminbar_hover_bg (p17).
        $adminbar_sub_hover_bg_raw = admbud_get_option( 'admbud_colours_adminbar_sub_hover_bg', '' );
        // Drop shadow colour. Empty = rgba(0,0,0,0.35).
        $shadow_colour_raw   = admbud_get_option( 'admbud_colours_shadow_colour', '' );

        $p1  = esc_attr( $primary );
        $p2  = esc_attr( $this->hex_to_rgb_triplet( $primary ) );
        $hover_bg_raw = admbud_get_option( 'admbud_colours_hover_bg', '' );
        $p3  = $hover_bg_raw ? esc_attr( $hover_bg_raw ) : esc_attr( $secondary );
        $active_bg_raw = admbud_get_option( 'admbud_colours_active_bg', '' );
        $p_active_bg = $active_bg_raw ? esc_attr( $active_bg_raw ) : esc_attr( $primary );
        $sub_active_bg_raw   = admbud_get_option( 'admbud_colours_submenu_active_bg', '' );
        $sub_active_text_raw = admbud_get_option( 'admbud_colours_submenu_active_text', '' );
        // Defaults: bg falls back to submenu hover bg, text falls back to active text (white).
        $sub_hover_bg_default   = $this->colour( 'admbud_colours_submenu_hover_bg', $secondary );
        $sub_hover_text_default = $this->colour( 'admbud_colours_submenu_hover_text', $menu_text );
        $p_sub_active_bg   = $sub_active_bg_raw   ? esc_attr( $sub_active_bg_raw )   : esc_attr( $sub_hover_bg_default );
        $p_sub_active_text = $sub_active_text_raw ? esc_attr( $sub_active_text_raw ) : esc_attr( $sub_hover_text_default );
        $p4  = esc_attr( $menu_bg );
        $p5  = esc_attr( $menu_bg_dark );
        $p6  = esc_attr( $menu_text );
        $p7  = esc_attr( $menu_bg_light );
        $p8  = $adminbar_text_raw ? esc_attr( $this->colour( 'admbud_colours_adminbar_text', $menu_text_dim ) ) : esc_attr( $menu_text_dim );
        $p9  = esc_attr( $primary_tint );
        $p10 = esc_attr( $neutral_gray );
        $p11 = esc_attr( $active_text );   // active item text colour
        $p12 = esc_attr( $sep_color );     // separator border colour
        $p14 = $submenu_text_raw ? esc_attr( $this->colour( 'admbud_colours_submenu_text', $menu_text ) ) : esc_attr( $menu_text ); // submenu/flyout text
        $p15 = $hover_text_raw ? esc_attr( $this->colour( 'admbud_colours_hover_text', $menu_text ) ) : esc_attr( $menu_text ); // hover text
        $p16 = $active_parent_text_raw ? esc_attr( $this->colour( 'admbud_colours_active_parent_text', $active_text ) ) : esc_attr( $active_text ); // active parent text
        $p17 = $adminbar_hover_raw ? esc_attr( $this->colour( 'admbud_colours_adminbar_hover_bg', $primary ) ) : esc_attr( $primary ); // admin bar hover bg
        $p18 = $adminbar_sub_raw ? esc_attr( $this->colour( 'admbud_colours_adminbar_submenu_bg', $menu_bg_dark ) ) : esc_attr( $menu_bg_dark ); // admin bar submenu bg
        $p19 = $adminbar_bg_raw ? esc_attr( $this->colour( 'admbud_colours_adminbar_bg', $menu_bg_dark ) ) : esc_attr( $menu_bg_dark ); // admin bar bg
        $p20 = $adminbar_hover_text_raw ? esc_attr( $this->colour( 'admbud_colours_adminbar_hover_text', $menu_text ) ) : esc_attr( $menu_text ); // admin bar hover text
        $p21 = $adminbar_sub_text_raw ? esc_attr( $this->colour( 'admbud_colours_adminbar_sub_text', $menu_text_dim ) ) : esc_attr( $menu_text_dim ); // admin bar submenu text
        $p22 = $adminbar_sub_hover_text_raw ? esc_attr( $this->colour( 'admbud_colours_adminbar_sub_hover_text', $menu_text ) ) : esc_attr( $menu_text ); // admin bar submenu hover text
        $p23 = $submenu_bg_raw ? esc_attr( $this->colour( 'admbud_colours_submenu_bg', $primary ) ) : esc_attr( $primary ); // sidebar submenu/flyout bg
        $p24 = $submenu_hover_bg_raw ? esc_attr( $this->colour( 'admbud_colours_submenu_hover_bg', $secondary ) ) : esc_attr( $secondary ); // sidebar submenu hover bg
        $p25 = $submenu_hover_text_raw ? esc_attr( $this->colour( 'admbud_colours_submenu_hover_text', $menu_text ) ) : esc_attr( $menu_text ); // sidebar submenu hover text
        $p26 = $adminbar_sub_hover_bg_raw ? esc_attr( $this->colour( 'admbud_colours_adminbar_sub_hover_bg', $primary ) ) : esc_attr( $primary ); // admin bar flyout hover bg
        $p27 = $shadow_colour_raw ? esc_attr( $this->colour( 'admbud_colours_shadow_colour', '#000000' ) ) : ''; // drop shadow colour (empty = use default rgba)
        // Build box-shadow string.
        if ( $shadow_colour_raw ) {
            // If the value is already an rgba/rgb string, use it directly with the shadow offset.
            if ( strpos( $p27, 'rgb' ) === 0 ) {
                $box_shadow = "0 4px 12px {$p27}";
            } elseif ( preg_match( '/^#?([0-9a-f]{6})$/i', $p27, $m ) ) {
                $r = hexdec( substr( $m[1], 0, 2 ) );
                $g = hexdec( substr( $m[1], 2, 2 ) );
                $b = hexdec( substr( $m[1], 4, 2 ) );
                $box_shadow = "0 4px 12px rgba({$r},{$g},{$b},0.4)";
            } else {
                $box_shadow = '0 4px 12px rgba(0,0,0,0.35)';
            }
        } else {
            $box_shadow = '0 4px 12px rgba(0,0,0,0.35)';
        }
        $p28 = $box_shadow; // drop shadow for flyout panels
        $p1_enc = str_replace( '#', '%23', $p1 ); // URL-encoded primary for inline SVG
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- All colour vars pre-sanitised via esc_attr(). CSS context only.
        echo '<style id="ab-admin-colours">';
        echo ':root {';
        echo '--wp-admin-theme-color:           ' . esc_attr( $p1 ) . ' !important;';
        echo '--wp-admin-theme-color--rgb:      ' . esc_attr( $p2 ) . ' !important;';
        echo '--wp-admin-theme-color-darker-10: ' . esc_attr( $p3 ) . ' !important;';
        echo '--wp-admin-theme-color-darker-20: ' . esc_attr( $p3 ) . ' !important;';
        echo '--ab-primary:      ' . esc_attr( $p1 ) . ';';
        echo '--ab-secondary:    ' . esc_attr( $p3 ) . ';';
        echo '--ab-tint:         ' . esc_attr( $p9 ) . ';';
        echo '--ab-menu-bg:      ' . esc_attr( $p4 ) . ';';
        echo '--ab-menu-bar:     ' . esc_attr( $p5 ) . ';';
        echo '--ab-menu-hover:   ' . esc_attr( $p3 ) . ';  /* hover bg = secondary colour */';
        echo '--ab-menu-text:    ' . esc_attr( $p6 ) . ';';
        echo '--ab-text-dim:     ' . esc_attr( $p8 ) . ';';
        echo '--ab-neutral:      ' . esc_attr( $p10 ) . ';';
        echo '--ab-menu-sep:     ' . esc_attr( $p12 ) . ';  /* menu item separator colour (user-configured) */';
        echo '--ab-active-text:  ' . esc_attr( $p11 ) . ';  /* active/current menu item text colour */';
        echo '--wp-blue-50:  ' . esc_attr( $p1 ) . ';   /* #2271b1 - primary links, buttons        */';
        echo '--wp-blue-60:  ' . esc_attr( $p3 ) . ';   /* #135e96 - hover state for buttons        */';
        echo '--wp-blue-70:  ' . esc_attr( $p3 ) . ';   /* #0a4b78 - active state                   */';
        echo '--wp-blue-40:  ' . esc_attr( $p1 ) . ';   /* #3582c4 - focus rings, borders           */';
        echo '--wp-blue-5:   ' . esc_attr( $p9 ) . ';   /* #d9ebf5 - very light bg/hover tint       */';
        echo '--ab-radius-sm: 4px;';
        echo '--ab-radius-md: 6px;';
        echo '--ab-radius-lg: 8px;';
        echo '--ab-radius-xl: 12px;';
        echo '--ab-radius-2xl: 16px;';
        echo '--ab-radius-3xl: 20px;';
        echo '}';
        echo '#adminmenu,';
        echo '#adminmenuback,';
        echo '#adminmenuwrap {';
        echo 'background: ' . esc_attr( $p4 ) . ' !important;';
        echo '}';
        echo '#adminmenu .wp-submenu,';
        echo '#adminmenu li.wp-has-submenu.wp-not-current-submenu.opensub > .wp-submenu,';
        echo '#adminmenu li.wp-has-submenu.wp-not-current-submenu:focus-within > .wp-submenu {';
        echo 'background: ' . esc_attr( $p23 ) . ' !important;';
        echo '}';
        echo '#adminmenu a,';
        echo '#adminmenu .wp-menu-name,';
        echo '#adminmenu .wp-menu-image:before {';
        echo 'color: ' . esc_attr( $p6 ) . ' !important;';
        echo '}';
        echo '#adminmenu li a .wp-menu-image.svg:before,';
        echo '#adminmenu li a .wp-menu-image:not(.svg):before {';
        echo 'color: ' . esc_attr( $p6 ) . ' !important;';
        echo '}';
        echo '#adminmenu li.wp-has-current-submenu a.wp-has-current-submenu,';
        echo '#adminmenu li.current a.menu-top,';
        echo '#adminmenu .wp-menu-arrow,';
        echo '.folded #adminmenu li.current.menu-top,';
        echo '.folded #adminmenu li.wp-has-current-submenu a.wp-has-current-submenu {';
        echo 'background: ' . esc_attr( $p_active_bg ) . ' !important;';
        echo 'color: ' . esc_attr( $p16 ) . ' !important;';
        echo '}';
        echo '#adminmenu li.wp-has-current-submenu .wp-submenu-wrap {';
        echo 'background: ' . esc_attr( $p23 ) . ' !important;';
        echo '}';
        echo '#adminmenu li.wp-has-current-submenu a.wp-has-current-submenu .wp-menu-image:before,';
        echo '#adminmenu li.wp-has-current-submenu a.wp-has-current-submenu .wp-menu-name,';
        echo '.folded #adminmenu li.wp-has-current-submenu a.wp-has-current-submenu .wp-menu-image:before {';
        echo 'color: ' . esc_attr( $p16 ) . ' !important;';
        echo '}';
        echo '#adminmenu li.current:not(.wp-has-current-submenu) a.menu-top .wp-menu-image:before,';
        echo '#adminmenu li.current:not(.wp-has-current-submenu) a.menu-top .wp-menu-name {';
        echo 'color: ' . esc_attr( $p16 ) . ' !important;';
        echo '}';
        echo '#adminmenu .wp-has-current-submenu .wp-submenu .wp-submenu-head,';
        echo '#adminmenu li.wp-has-current-submenu .wp-submenu .wp-submenu-head,';
        echo '#adminmenu li.wp-has-submenu.wp-not-current-submenu.opensub .wp-submenu .wp-submenu-head {';
        echo 'background: ' . esc_attr( $p23 ) . ' !important;';
        echo 'color: ' . esc_attr( $p14 ) . ' !important;';
        echo '}';
        echo '#adminmenu li a:hover,';
        echo '#adminmenu li a:focus {';
        echo 'background: ' . esc_attr( $p3 ) . ' !important;';
        echo 'color: ' . esc_attr( $p15 ) . ' !important;';
        echo '}';
        echo '#adminmenu li a:hover .wp-menu-image:before,';
        echo '#adminmenu li a:focus .wp-menu-image:before,';
        echo '#adminmenu li a:hover .wp-menu-name,';
        echo '#adminmenu li a:focus .wp-menu-name {';
        echo 'color: ' . esc_attr( $p15 ) . ' !important;';
        echo 'opacity: 1 !important;';
        echo '}';
        echo '#adminmenu .wp-submenu a {';
        echo 'color: ' . esc_attr( $p14 ) . ' !important;';
        echo 'opacity: 0.85;';
        echo '}';
        echo '#adminmenu .wp-submenu a:hover,';
        echo '#adminmenu .wp-submenu a:focus,';
        echo '#adminmenu li.wp-has-submenu.wp-not-current-submenu.opensub .wp-submenu a:hover,';
        echo '#adminmenu li.wp-has-submenu.wp-not-current-submenu.opensub .wp-submenu a:focus {';
        echo 'background: ' . esc_attr( $p24 ) . ' !important;';
        echo 'color: ' . esc_attr( $p25 ) . ' !important;';
        echo 'opacity: 1;';
        echo '}';
        echo '#adminmenu .wp-submenu li.current a,';
        echo '#adminmenu .wp-submenu a.current,';
        echo '#adminmenu .wp-has-current-submenu .wp-submenu li.current a {';
        echo 'background: ' . esc_attr( $p_sub_active_bg ) . ' !important;';
        echo 'color: ' . esc_attr( $p_sub_active_text ) . ' !important;';
        echo 'opacity: 1;';
        echo '}';
        echo '#adminmenu .wp-submenu li.current a:hover,';
        echo '#adminmenu .wp-submenu li.current a:focus,';
        echo '#adminmenu .wp-submenu a.current:hover,';
        echo '#adminmenu .wp-submenu a.current:focus,';
        echo '#adminmenu .wp-has-current-submenu .wp-submenu li.current a:hover,';
        echo '#adminmenu .wp-has-current-submenu .wp-submenu li.current a:focus {';
        echo 'background: ' . esc_attr( $p24 ) . ' !important;';
        echo 'color: ' . esc_attr( $p25 ) . ' !important;';
        echo 'opacity: 1;';
        echo '}';
        echo '#adminmenu li.wp-has-submenu.wp-not-current-submenu.opensub:hover:after,';
        echo '#adminmenu li.wp-has-submenu.wp-not-current-submenu:focus-within:after {';
        echo 'border-right-color: ' . esc_attr( $p23 ) . ' !important;';
        echo '}';
        echo 'html body #wpadminbar {';
        echo 'background: ' . esc_attr( $p19 ) . ' !important;';
        echo '}';
        echo 'html body #wpadminbar .ab-item,';
        echo 'html body #wpadminbar a.ab-item,';
        echo 'html body #wpadminbar .ab-label,';
        echo 'html body #wpadminbar .ab-icon:before,';
        echo 'html body #wpadminbar > #wp-toolbar span.ab-label,';
        echo 'html body #wpadminbar > #wp-toolbar .ab-icon:before,';
        echo 'html body #wpadminbar #adminbarsearch:before,';
        echo 'html body #wpadminbar .ab-item:before,';
        echo 'html body #wpadminbar #wp-admin-bar-wp-logo .ab-icon:before,';
        echo 'html body #wpadminbar #wp-admin-bar-wp-logo > a .ab-icon:before {';
        echo 'color: ' . esc_attr( $p8 ) . ' !important;';
        echo '}';
        echo '#collapse-button { color: ' . esc_attr( $p6 ) . ' !important; }';
        echo '#collapse-button:hover { background: ' . esc_attr( $p3 ) . ' !important; color: ' . esc_attr( $p15 ) . ' !important; }';
        echo '#collapse-button .collapse-button-icon:before { color: ' . esc_attr( $p6 ) . ' !important; }';
        echo '#collapse-button:hover .collapse-button-icon:before { color: ' . esc_attr( $p15 ) . ' !important; }';
        echo 'html body #wpadminbar .ab-top-menu > li:hover > a,';
        echo 'html body #wpadminbar .ab-top-menu > li.hover > a,';
        echo 'html body #wpadminbar .ab-top-menu > li > a:focus {';
        echo 'background: ' . esc_attr( $p17 ) . ' !important;';
        echo 'color: ' . esc_attr( $p20 ) . ' !important;';
        echo '}';
        echo 'html body #wpadminbar .ab-top-menu > li.current-menu-ancestor > a,';
        echo 'html body #wpadminbar .ab-top-menu > li.current-menu-item > a,';
        echo 'html body #wpadminbar .ab-top-menu > li.selected > a {';
        echo 'background: ' . esc_attr( $p17 ) . ' !important;';
        echo 'color: ' . esc_attr( $p20 ) . ' !important;';
        echo '}';
        echo 'html body #wpadminbar #wp-admin-bar-my-account > a,';
        echo 'html body #wpadminbar #wp-admin-bar-my-account > a:hover {';
        echo 'background: transparent !important;';
        echo '}';
        echo 'html body #wpadminbar .ab-top-menu > li:hover > a > .ab-label,';
        echo 'html body #wpadminbar .ab-top-menu > li.hover > a > .ab-label,';
        echo 'html body #wpadminbar .ab-top-menu > li > a:focus > .ab-label,';
        echo 'html body #wpadminbar .ab-top-menu > li:hover > a > .ab-icon:before,';
        echo 'html body #wpadminbar .ab-top-menu > li.hover > a > .ab-icon:before,';
        echo 'html body #wpadminbar .ab-top-menu > li > a:focus > .ab-icon:before {';
        echo 'background: transparent !important;';
        echo 'color: ' . esc_attr( $p20 ) . ' !important;';
        echo '}';
        echo 'html body #wpadminbar .ab-top-menu > li:hover > a .ab-item:before,';
        echo 'html body #wpadminbar .ab-top-menu > li:hover > a .ab-icon,';
        echo 'html body #wpadminbar .ab-top-menu > li:hover .ab-item,';
        echo 'html body #wpadminbar .ab-top-menu > li.hover .ab-item {';
        echo 'color: ' . esc_attr( $p20 ) . ' !important;';
        echo '}';
        echo 'html body #wpadminbar .menupop .ab-sub-wrapper,';
        echo 'html body #wpadminbar .ab-top-menu > li > .ab-sub-wrapper,';
        echo 'html body #wpadminbar #wp-admin-bar-user-actions .ab-sub-wrapper,';
        echo 'html body #wpadminbar .quicklinks .menupop ul,';
        echo 'html body #wpadminbar #wp-admin-bar-new-content .ab-sub-wrapper,';
        echo 'html body #wpadminbar #wp-admin-bar-site-name > .ab-sub-wrapper,';
        echo 'html body #wpadminbar #wp-admin-bar-my-account > .ab-sub-wrapper,';
        echo 'html body #wpadminbar #wp-admin-bar-comments .ab-sub-wrapper {';
        echo 'background: ' . esc_attr( $p18 ) . ' !important;';
        echo 'border: none !important;';
        echo 'box-shadow: ' . esc_attr( $p28 ) . ' !important;';
        echo '}';
        echo 'html body #wpadminbar .ab-submenu .ab-item,';
        echo 'html body #wpadminbar .ab-submenu a,';
        echo 'html body #wpadminbar .ab-submenu a.ab-item,';
        echo 'html body #wpadminbar .ab-submenu .ab-icon:before,';
        echo 'html body #wpadminbar #wp-admin-bar-user-actions a,';
        echo 'html body #wpadminbar #wp-admin-bar-user-actions .ab-item,';
        echo 'html body #wpadminbar .quicklinks .menupop ul li a,';
        echo 'html body #wpadminbar .quicklinks .menupop .ab-sub-wrapper .ab-submenu > li > a,';
        echo 'html body #wpadminbar .quicklinks .menupop .ab-sub-wrapper .ab-submenu > li > a .ab-item {';
        echo 'color: ' . esc_attr( $p21 ) . ' !important;';
        echo 'background: transparent !important;';
        echo '}';
        echo 'html body #wpadminbar .ab-submenu > li > a:hover,';
        echo 'html body #wpadminbar .ab-submenu > li:hover > a,';
        echo 'html body #wpadminbar .ab-submenu > li > a:focus,';
        echo 'html body #wpadminbar #wp-admin-bar-user-actions li:hover > a,';
        echo 'html body #wpadminbar #wp-admin-bar-user-actions li > a:hover,';
        echo 'html body #wpadminbar .quicklinks .menupop ul li:hover > a,';
        echo 'html body #wpadminbar .quicklinks .menupop ul li a:hover,';
        echo 'html body #wpadminbar .quicklinks .menupop .ab-sub-wrapper .ab-submenu > li:hover > a,';
        echo 'html body #wpadminbar .quicklinks .menupop .ab-sub-wrapper .ab-submenu > li > a:hover {';
        echo 'background: ' . esc_attr( $p26 ) . ' !important;';
        echo 'color: ' . esc_attr( $p22 ) . ' !important;';
        echo '}';
        echo 'html body #wpadminbar .ab-submenu > li:hover > a .ab-icon:before,';
        echo 'html body #wpadminbar .ab-submenu > li > a:hover .ab-icon:before,';
        echo 'html body #wpadminbar .ab-submenu > li > a:focus .ab-icon:before,';
        echo 'html body #wpadminbar .quicklinks .menupop .ab-sub-wrapper li:hover > a .ab-icon:before {';
        echo 'color: ' . esc_attr( $p22 ) . ' !important;';
        echo '}';
        echo 'html body #wpadminbar .ab-submenu li,';
        echo 'html body #wpadminbar .ab-submenu li a,';
        echo 'html body #wpadminbar .quicklinks .menupop ul li,';
        echo 'html body #wpadminbar #wp-admin-bar-themes > .ab-item,';
        echo 'html body #wpadminbar #wp-admin-bar-themes,';
        echo 'html body #wpadminbar .menupop .ab-submenu > li:last-child,';
        echo 'html body #wpadminbar .menupop .ab-submenu > li {';
        echo 'box-shadow: none !important;';
        echo 'border-bottom: none !important;';
        echo '}';
        echo 'html body #wpadminbar #wp-admin-bar-my-account > a,';
        echo 'html body #wpadminbar #wp-admin-bar-my-account > a .display-name,';
        echo 'html body #wpadminbar #wp-admin-bar-my-account > a .ab-label,';
        echo 'html body #wpadminbar #wp-admin-bar-my-account > a .ab-item {';
        echo 'color: ' . esc_attr( $p8 ) . ' !important;';
        echo 'background: transparent !important;';
        echo '}';
        echo 'html body #wpadminbar #wp-admin-bar-my-account:hover > a,';
        echo 'html body #wpadminbar #wp-admin-bar-my-account.hover > a,';
        echo 'html body #wpadminbar #wp-admin-bar-my-account:hover > a .display-name,';
        echo 'html body #wpadminbar #wp-admin-bar-my-account.hover > a .display-name,';
        echo 'html body #wpadminbar #wp-admin-bar-my-account:hover > a .ab-label,';
        echo 'html body #wpadminbar #wp-admin-bar-my-account.hover > a .ab-label {';
        echo 'background: ' . esc_attr( $p17 ) . ' !important;';
        echo 'color: ' . esc_attr( $p20 ) . ' !important;';
        echo '}';
        echo 'html body #wpadminbar #wp-admin-bar-site-name > a,';
        echo 'html body #wpadminbar #wp-admin-bar-site-name > a .ab-label {';
        echo 'color: ' . esc_attr( $p8 ) . ' !important;';
        echo '}';
        echo 'html body #wpadminbar #wp-admin-bar-site-name:hover > a,';
        echo 'html body #wpadminbar #wp-admin-bar-site-name.hover > a {';
        echo 'background: ' . esc_attr( $p17 ) . ' !important;';
        echo 'color: ' . esc_attr( $p20 ) . ' !important;';
        echo '}';
        echo 'html body #wpadminbar #wp-admin-bar-site-name .ab-submenu .ab-item {';
        echo 'color: ' . esc_attr( $p21 ) . ' !important;';
        echo '}';
        echo 'html body #wpadminbar #wp-admin-bar-site-name .ab-submenu li:hover > a,';
        echo 'html body #wpadminbar #wp-admin-bar-site-name .ab-submenu li a:hover {';
        echo 'background: ' . esc_attr( $p26 ) . ' !important;';
        echo 'color: ' . esc_attr( $p22 ) . ' !important;';
        echo '}';
        echo '</style>';
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

        // -- UI Rounding (optional) -------------------------------------------
        $ui_radius = admbud_get_option( 'admbud_colours_ui_radius', 'off' );
        if ( $ui_radius !== 'off' ) {
            $radius_map = [
                'small'  => [ 'card' => 'var(--ab-radius-md)',  'flyout' => 'var(--ab-radius-sm)' ],
                'medium' => [ 'card' => 'var(--ab-radius-lg)',  'flyout' => 'var(--ab-radius-md)' ],
                'large'  => [ 'card' => 'var(--ab-radius-2xl)', 'flyout' => 'var(--ab-radius-lg)' ],
            ];
            $r = $radius_map[ $ui_radius ];
            // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $r values are hardcoded CSS custom property references from $radius_map above.
            // Sidebar flyout radius (chrome, always applied)
            echo '<style id="ab-ui-radius-chrome">';
            // Flyout submenus (collapsed sidebar or hover) - all corners rounded.
            echo '#adminmenu li.wp-has-submenu.wp-not-current-submenu.opensub>.wp-submenu,'
               . '#adminmenu li.wp-has-submenu.wp-not-current-submenu:focus-within>.wp-submenu{'
               . 'border-radius:' . $r['flyout'] . '!important;overflow:hidden}';
            // Expanded inline submenu (current menu open) - top-left square so it
            // sits flush against the parent menu item, other corners rounded.
            echo '#adminmenu li.wp-has-current-submenu .wp-submenu-wrap{'
               . 'border-radius:0 0 ' . $r['flyout'] . ' ' . $r['flyout'] . '!important;overflow:hidden}';
            echo '#adminmenu li.wp-has-current-submenu a.wp-has-current-submenu,#adminmenu li.current a.menu-top,#adminmenu .wp-menu-arrow,.folded #adminmenu li.current.menu-top,.folded #adminmenu li.wp-has-current-submenu a.wp-has-current-submenu{border-radius:0}';
            echo '</style>';
            // Content area radius (gated by $excluded)
            if ( ! $excluded ) {
                echo '<style id="ab-ui-radius-content">';
                echo '#wpcontent .postbox{border-radius:' . $r['card'] . '!important;overflow:hidden}';
                echo '#wpcontent .plugin-card{border-radius:' . $r['card'] . '!important;overflow:hidden}';
                echo '#wpcontent .wp-filter{border-radius:' . $r['flyout'] . '!important}';
                echo '#wpcontent .theme-browser .theme{border-radius:' . $r['card'] . '!important;overflow:hidden}';
                echo '#wpcontent .theme-browser .theme .theme-screenshot{border-radius:' . $r['card'] . ' ' . $r['card'] . ' 0 0!important}';
                echo '#wpcontent table.widefat,#wpcontent .wp-list-table{border-radius:' . $r['card'] . '!important;overflow:hidden;border-collapse:separate;border-spacing:0}';
                echo '#wpcontent .tablenav{border-radius:' . $r['flyout'] . '}';
                echo '#wpcontent .tablenav .tablenav-pages{border-radius:' . $r['flyout'] . '}';
                echo '</style>';
            }
            // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        // Everything below targets #wpcontent / .wrap / .wp-list-table etc.
        // and must NOT render on third-party plugin pages the user excluded.
        // ========================================================================
        if ( ! $excluded ) {
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- All colour vars pre-sanitised via esc_attr(). CSS context only.
        echo '<style id="ab-admin-content">';
        echo '#wpcontent a{color:' . esc_attr( $p1 ) . '}';
        echo '#wpcontent a:hover,#wpcontent a:active{color:' . esc_attr( $p3 ) . '}';
        echo '#wpcontent a:focus,#wpcontent .button:focus,#wpcontent .button-secondary:focus{box-shadow:0 0 0 1px ' . esc_attr( $p1 ) . '!important;outline:1px solid transparent!important}';
        echo '#wpcontent .button-primary:focus{box-shadow:inset 0 0 0 1px #fff,0 0 0 1px ' . esc_attr( $p1 ) . '!important;outline:1px solid transparent!important}';
        echo '#wpcontent .view-switch a.current:before{color:' . esc_attr( $p1 ) . '!important}';
        echo '#wpcontent .filter-links a.current,#wpcontent .filter-links li>a.current{color:' . esc_attr( $p1 ) . '!important}';
        echo '#wpcontent select option:checked{color:' . esc_attr( $p1 ) . '}';
        echo '#wpcontent .media-frame .attachment.selected .check .media-modal-icon{background-color:' . esc_attr( $p1 ) . '!important}';
        echo '#wpcontent .media-frame .attachment.selected .check{border-color:' . esc_attr( $p1 ) . '!important}';
        echo '#wpcontent .wp-list-table thead th,';
        echo '#wpcontent .widefat thead th,';
        echo '#wpcontent .wp-list-table tfoot th,';
        echo '#wpcontent .widefat tfoot th {';
        echo 'background-color: #f6f7f7 !important;';
        echo 'color: #1d2327 !important;';
        echo '}';
        echo '#wpcontent .plugins tr.inactive td,';
        echo '#wpcontent .plugins tr.inactive th {';
        echo 'background-color: #ffffff !important;';
        echo '}';
        echo '#wpcontent .plugins tr.active > td,';
        echo '#wpcontent .plugins tr.active > th,';
        echo '#wpcontent #the-list .active > td,';
        echo '#wpcontent #the-list .active > th {';
        echo 'box-shadow: inset 4px 0 0 transparent !important;';
        echo 'background-color: rgba(' . esc_attr( $p2 ) . ',0.08) !important;';
        echo '}';
        echo '#wpcontent .plugin-update-tr.active td,';
        echo '#wpcontent .plugins .active th.check-column {';
        echo 'border-left: 4px solid ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '#wpcontent .wp-list-table td a.row-title,';
        echo '#wpcontent #the-list .column-primary > a:not(.button):not([class*="button"]),';
        echo '#wpcontent .wp-list-table td strong > a:not(.button) {';
        echo 'color: var(--ab-ct-tbl-title, ' . esc_attr( $p1 ) . ') !important;';
        echo '}';
        echo '#wpcontent .wp-list-table td a.row-title:hover,';
        echo '#wpcontent #the-list .column-primary > a:not(.button):not([class*="button"]):hover,';
        echo '#wpcontent .wp-list-table td strong > a:not(.button):hover {';
        echo 'color: var(--ab-ct-tbl-title, ' . esc_attr( $p3 ) . ') !important; opacity: 0.8;';
        echo '}';
        echo '#wpcontent .wp-list-table .row-actions a {';
        echo 'color: var(--ab-ct-tbl-action, ' . esc_attr( $p1 ) . ') !important;';
        echo '}';
        echo '#wpcontent .wp-list-table .row-actions a:hover {';
        echo 'color: var(--ab-ct-tbl-action, ' . esc_attr( $p3 ) . ') !important; opacity: 0.8;';
        echo '}';
        echo '#wpcontent .wp-list-table td a:not(.button):not([class*="button"]):not(.row-title),';
        echo '#wpcontent .wp-list-table th a:not(.button):not([class*="button"]) {';
        echo 'color: var(--ab-ct-tbl-hdr-link, ' . esc_attr( $p1 ) . ') !important;';
        echo '}';
        echo '#wpcontent .wp-list-table td a:not(.button):not([class*="button"]):not(.row-title):hover,';
        echo '#wpcontent .wp-list-table th a:not(.button):not([class*="button"]):hover {';
        echo 'color: var(--ab-ct-tbl-hdr-link, ' . esc_attr( $p3 ) . ') !important;';
        echo '}';
        echo '#wpcontent .subsubsub a { color: ' . esc_attr( $p1 ) . ' !important; }';
        echo '#wpcontent .subsubsub a:hover,';
        echo '#wpcontent .subsubsub a.current { color: ' . esc_attr( $p3 ) . ' !important; }';
        echo '#wpcontent .form-table a { color: ' . esc_attr( $p1 ) . '; }';
        echo '#wpcontent .form-table a:hover { color: ' . esc_attr( $p3 ) . '; }';
        echo '#wpcontent .submit .button-primary,';
        echo '#wpcontent p.submit .button-primary {';
        echo 'background: ' . esc_attr( $p1 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p3 ) . ' !important;';
        echo 'color: ' . esc_attr( $p11 ) . ' !important;';
        echo 'box-shadow: none !important;';
        echo '}';
        echo '#wpcontent .submit .button-primary:hover,';
        echo '#wpcontent p.submit .button-primary:hover {';
        echo 'background: ' . esc_attr( $p3 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p3 ) . ' !important;';
        echo '}';
        echo '#wpcontent .wrap .button-primary,';
        echo '#wpcontent .wrap input[type="submit"].button-primary {';
        echo 'background: ' . esc_attr( $p1 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p3 ) . ' !important;';
        echo 'color: ' . esc_attr( $p11 ) . ' !important;';
        echo 'box-shadow: none !important;';
        echo '}';
        echo '#wpcontent .wrap .button-primary:hover,';
        echo '#wpcontent .wrap input[type="submit"].button-primary:hover {';
        echo 'background: ' . esc_attr( $p3 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p3 ) . ' !important;';
        echo 'color: ' . esc_attr( $p11 ) . ' !important;';
        echo '}';
        echo '#wpcontent .wrap .button:not(.button-primary) {';
        echo 'color: ' . esc_attr( $p1 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '#wpcontent .wrap .button:not(.button-primary):hover {';
        echo 'color: ' . esc_attr( $p3 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p3 ) . ' !important;';
        echo '}';
        echo '#wpcontent .theme-browser .theme .theme-actions .button-primary {';
        echo 'background: ' . esc_attr( $p1 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p1 ) . ' !important;';
        echo 'color: ' . esc_attr( $p11 ) . ' !important;';
        echo '}';
        echo '#wpcontent .theme-browser .theme .theme-actions .button-primary:hover {';
        echo 'background: ' . esc_attr( $p3 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p3 ) . ' !important;';
        echo '}';
        echo '#wpcontent .theme-browser .theme .theme-actions .button:not(.button-primary) {';
        echo 'color: ' . esc_attr( $p1 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p1 ) . ' !important;';
        echo 'background: transparent !important;';
        echo '}';
        echo '#wpcontent .theme-browser .theme .theme-actions .button:not(.button-primary):hover {';
        echo 'color: ' . esc_attr( $p3 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p3 ) . ' !important;';
        echo '}';
        echo '#wpcontent .theme-browser .theme.active .theme-name {';
        echo 'box-shadow: none !important;';
        echo 'border-top: 1px solid #c3c4c7 !important;';
        echo '}';
        echo '#wpcontent .theme-browser .theme.add-new-theme a:focus:after,';
        echo '#wpcontent .theme-browser .theme.add-new-theme a:hover:after {';
        echo 'background: ' . esc_attr( $p1 ) . ' !important;';
        echo 'border-color: transparent !important;';
        echo 'color: #fff !important;';
        echo '}';
        echo '#wpcontent .theme-browser .theme.add-new-theme a:focus span:after,';
        echo '#wpcontent .theme-browser .theme.add-new-theme a:hover span:after {';
        echo 'background: #fff !important;';
        echo 'color: ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '.wp-core-ui #wpcontent .button-link,';
        echo '.wp-core-ui #wpcontent .row-actions a {';
        echo 'color: var(--ab-ct-tbl-action, ' . esc_attr( $p1 ) . ') !important;';
        echo '}';
        echo '.wp-core-ui #wpcontent .button-link:hover,';
        echo '.wp-core-ui #wpcontent .button-link:active,';
        echo '.wp-core-ui #wpcontent .row-actions a:hover {';
        echo 'color: var(--ab-ct-tbl-action, ' . esc_attr( $p3 ) . ') !important;';
        echo '}';
        echo '.wp-core-ui #wpcontent .button-link:focus {';
        echo 'color: ' . esc_attr( $p1 ) . ' !important;';
        echo 'box-shadow: 0 0 0 2px ' . esc_attr( $p1 ) . ' !important;';
        echo 'outline: 2px solid transparent !important;';
        echo '}';
        echo '.wp-core-ui #wpcontent .button-primary {';
        echo 'background: ' . esc_attr( $p1 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p3 ) . ' !important;';
        echo 'color: #fff !important;';
        echo 'box-shadow: none !important;';
        echo '}';
        echo '.wp-core-ui #wpcontent .button-primary:hover,';
        echo '.wp-core-ui #wpcontent .button-primary.hover {';
        echo 'background: ' . esc_attr( $p3 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p3 ) . ' !important;';
        echo 'color: #fff !important;';
        echo '}';
        echo '.wp-core-ui #wpcontent .button-primary:focus,';
        echo '.wp-core-ui #wpcontent .button-primary.focus {';
        echo 'background: ' . esc_attr( $p1 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p3 ) . ' !important;';
        echo 'color: #fff !important;';
        echo 'box-shadow: 0 0 0 1px #fff, 0 0 0 3px ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '.wp-core-ui #wpcontent .button:not(.button-primary),';
        echo '.wp-core-ui #wpcontent .button-secondary {';
        echo 'color: ' . esc_attr( $p1 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '.wp-core-ui #wpcontent .button:not(.button-primary):hover,';
        echo '.wp-core-ui #wpcontent .button-secondary:hover {';
        echo 'color: ' . esc_attr( $p3 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p3 ) . ' !important;';
        echo 'background: ' . esc_attr( $p9 ) . ' !important;';
        echo '}';
        echo '.wp-core-ui #wpcontent .button:focus,';
        echo '.wp-core-ui #wpcontent .button-secondary:focus,';
        echo '.wp-core-ui #wpcontent .button.focus,';
        echo '#wpcontent .wp-core-ui .button:focus,';
        echo '#wpcontent .wp-core-ui .button-secondary:focus,';
        echo '#wpcontent .wp-core-ui .button.focus {';
        echo 'border-color: ' . esc_attr( $p1 ) . ' !important;';
        echo 'box-shadow: 0 0 0 1px ' . esc_attr( $p1 ) . ' !important;';
        echo 'color: ' . esc_attr( $p1 ) . ' !important;';
        echo 'background: ' . esc_attr( $p9 ) . ' !important;';
        echo 'outline: 2px solid transparent !important;';
        echo 'outline-offset: 0 !important;';
        echo '}';
        echo '#wpcontent .media-router .media-menu-item:hover,';
        echo '#wpcontent .media-router .media-menu-item:active {';
        echo 'color: ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '#wpcontent .media-router .media-menu-item:focus {';
        echo 'box-shadow: 0 0 0 2px ' . esc_attr( $p1 ) . ' !important;';
        echo 'color: ' . esc_attr( $p1 ) . ' !important;';
        echo 'outline: 2px solid transparent !important;';
        echo '}';
        echo '.wp-core-ui #wpcontent .button:not(.button-primary),';
        echo '.wp-core-ui #wpcontent .button-secondary {';
        echo 'color: ' . esc_attr( $p1 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '.wp-core-ui #wpcontent .button:not(.button-primary):hover,';
        echo '.wp-core-ui #wpcontent .button-secondary:hover {';
        echo 'color: ' . esc_attr( $p3 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p3 ) . ' !important;';
        echo '}';
        echo '.wp-core-ui #wpcontent .button-primary:hover,';
        echo '.wp-core-ui #wpcontent .button-primary:focus,';
        echo '.wp-core-ui #wpcontent .button-primary.hover,';
        echo '.wp-core-ui #wpcontent .button-primary.focus {';
        echo 'background: ' . esc_attr( $p3 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p3 ) . ' !important;';
        echo 'color: ' . esc_attr( $p11 ) . ' !important;';
        echo '}';
        echo '#wpcontent .health-check-tab.active,';
        echo '#wpcontent .privacy-settings-tab.active {';
        echo 'box-shadow: inset 0 -3px ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '#wpcontent .privacy-settings-accordion-panel a,';
        echo '#wpcontent #privacy-settings-content a,';
        echo '#wpcontent .health-check-accordion-panel a,';
        echo '#wpcontent .site-health-issues-wrapper a,';
        echo '#wpcontent #health-check-body a,';
        echo '#wpcontent .health-check-body a {';
        echo 'color: ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '#wpcontent .privacy-settings-accordion-panel a:hover,';
        echo '#wpcontent #privacy-settings-content a:hover,';
        echo '#wpcontent .health-check-accordion-panel a:hover,';
        echo '#wpcontent .site-health-issues-wrapper a:hover,';
        echo '#wpcontent #health-check-body a:hover,';
        echo '#wpcontent .health-check-body a:hover {';
        echo 'color: ' . esc_attr( $p3 ) . ' !important;';
        echo '}';
        echo '#wpcontent .health-check-accordion-trigger:focus,';
        echo '#wpcontent .privacy-settings-accordion-trigger:focus {';
        echo 'outline-color: ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '#wpcontent .health-check-accordion-trigger .badge.blue,';
        echo '#wpcontent .privacy-settings-accordion-trigger .badge.blue {';
        echo 'border-color: ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '#dashboard-widgets .community-events li.event-none,';
        echo '#wpcontent .community-events li.event-none {';
        echo 'border-left: 4px solid ' . esc_attr( $p1 ) . ' !important;';
        echo 'background: rgba(' . esc_attr( $p2 ) . ', 0.15) !important;';
        echo 'border-top: 1px solid rgba(' . esc_attr( $p2 ) . ', 0.25) !important;';
        echo 'border-bottom: 1px solid rgba(' . esc_attr( $p2 ) . ', 0.25) !important;';
        echo '}';
        echo '#dashboard_primary .community-events-footer a,';
        echo '#dashboard_primary .community-events li a,';
        echo '#dashboard_primary .inside a {';
        echo 'color: ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '#dashboard_primary .community-events-footer a:hover,';
        echo '#dashboard_primary .community-events li a:hover,';
        echo '#dashboard_primary .inside a:hover {';
        echo 'color: ' . esc_attr( $p3 ) . ' !important;';
        echo '}';
        echo '#dashboard_primary .community-events .event-info,';
        echo '#dashboard_primary .community-events .event-location,';
        echo '#dashboard_primary .community-events p,';
        echo '#dashboard_primary .inside p,';
        echo '#dashboard_primary .inside li {';
        echo 'color: inherit !important;';
        echo '}';
        echo '#dashboard_primary .community-events-content,';
        echo '#dashboard_primary .community-events ul,';
        echo '#dashboard_primary .community-events {';
        echo 'background: transparent !important;';
        echo '}';
        echo '#dashboard-widgets .community-events li,';
        echo '#dashboard-widgets .community-events-footer,';
        echo '#dashboard-widgets .community-events ul {';
        echo 'border-color: rgba(' . esc_attr( $p2 ) . ', 0.25) !important;';
        echo '}';
        echo '#dashboard-widgets .postbox .postbox-header,';
        echo '#dashboard-widgets .postbox .inside {';
        echo 'border-color: rgba(' . esc_attr( $p2 ) . ', 0.25) !important;';
        echo '}';
        echo '.contextual-help-tabs .active {';
        echo 'border-left-color: ' . esc_attr( $p1 ) . ' !important;';
        echo 'background: ' . esc_attr( $p9 ) . ' !important;';
        echo '}';
        echo '.contextual-help-tabs a {';
        echo 'color: ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '.contextual-help-tabs a:hover {';
        echo 'color: ' . esc_attr( $p3 ) . ' !important;';
        echo '}';
        echo '#contextual-help-back {';
        echo 'border-color: ' . esc_attr( $p9 ) . ' !important;';
        echo 'background: ' . esc_attr( $p9 ) . ' !important;';
        echo '}';
        echo '#contextual-help-link {';
        echo 'color: ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '#contextual-help-link:focus {';
        echo 'box-shadow: 0 0 0 2px ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '#wpcontent a:focus,';
        echo '#wpbody a:focus,';
        echo '#adminmenu a:focus {';
        echo 'color: inherit !important;';
        echo 'box-shadow: 0 0 0 2px ' . esc_attr( $p1 ) . ' !important;';
        echo 'outline: 2px solid transparent !important;';
        echo '}';
        echo '#wpcontent a:focus .gravatar,';
        echo '#wpcontent a:focus .media-icon img,';
        echo '#wpcontent a:focus .plugin-icon {';
        echo 'box-shadow: 0 0 0 2px ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '#wpcontent .uploader-inline input[type="text"]:focus,';
        echo '#wpcontent .uploader-inline input[type="search"]:focus,';
        echo '#wpcontent .uploader-inline select:focus,';
        echo '#wpcontent .uploader-inline textarea:focus {';
        echo 'border-color: ' . esc_attr( $p1 ) . ' !important;';
        echo 'box-shadow: 0 0 0 1px ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '.media-modal .media-router .media-menu-item:hover,';
        echo '.media-modal .media-router .media-menu-item:active {';
        echo 'color: ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '.media-modal .media-router .media-menu-item.active {';
        echo 'border-bottom-color: ' . esc_attr( $p1 ) . ' !important;';
        echo 'color: ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '.media-modal .media-router {';
        echo 'background: transparent !important;';
        echo '}';
        echo '.media-modal .media-router .media-menu-item {';
        echo 'background: transparent !important;';
        echo '}';
        echo '.media-modal .media-router .media-menu-item:focus {';
        echo 'box-shadow: 0 0 0 2px ' . esc_attr( $p1 ) . ' !important;';
        echo 'color: ' . esc_attr( $p1 ) . ' !important;';
        echo 'outline: 2px solid transparent !important;';
        echo '}';
        echo '.media-frame input[type="text"]:focus,';
        echo '.media-frame input[type="search"]:focus,';
        echo '.media-frame input[type="email"]:focus,';
        echo '.media-frame input[type="url"]:focus,';
        echo '.media-frame input[type="password"]:focus,';
        echo '.media-frame input[type="number"]:focus,';
        echo '.media-frame select:focus,';
        echo '.media-frame textarea:focus {';
        echo 'border-color: ' . esc_attr( $p1 ) . ' !important;';
        echo 'box-shadow: 0 0 0 1px ' . esc_attr( $p1 ) . ' !important;';
        echo 'outline: 2px solid transparent !important;';
        echo '}';
        echo '.media-frame .attachment.selected,';
        echo '.media-frame .attachment.details {';
        echo 'box-shadow: inset 0 0 0 3px ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '.media-frame .attachment .check {';
        echo 'background: ' . esc_attr( $p1 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '.media-modal .uploader-inline .button:not(.button-primary),';
        echo '.media-modal .upload-ui .button:not(.button-primary) {';
        echo 'color: ' . esc_attr( $p1 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '.media-modal .uploader-inline .button:not(.button-primary):hover,';
        echo '.media-modal .upload-ui .button:not(.button-primary):hover {';
        echo 'color: ' . esc_attr( $p3 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p3 ) . ' !important;';
        echo '}';
        echo '.media-modal .uploader-inline .button:focus,';
        echo '.media-modal .uploader-inline .button.focus {';
        echo 'border-color: ' . esc_attr( $p1 ) . ' !important;';
        echo 'box-shadow: 0 0 0 1px ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '.media-modal .button-primary {';
        echo 'background: ' . esc_attr( $p1 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p1 ) . ' !important;';
        echo 'color: #fff !important;';
        echo '}';
        echo '.media-modal .button-primary:hover,';
        echo '.media-modal .button-primary:focus {';
        echo 'background: ' . esc_attr( $p3 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p3 ) . ' !important;';
        echo '}';
        echo '.media-modal .media-modal-close:focus {';
        echo 'color: ' . esc_attr( $p1 ) . ' !important;';
        echo 'outline-color: ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '.media-modal input[type="checkbox"],';
        echo '.media-modal input[type="radio"] {';
        echo 'accent-color: ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '.media-modal a { color: ' . esc_attr( $p1 ) . ' !important; }';
        echo '.media-modal a:hover { color: ' . esc_attr( $p3 ) . ' !important; }';
        echo '.media-modal h1,';
        echo '.media-modal h2,';
        echo '.media-modal .media-frame-title h1 {';
        echo 'color: ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '.media-modal .attachments-browser .media-toolbar {';
        echo 'background: transparent !important;';
        echo '}';
        echo '.attachments-browser .media-toolbar {';
        echo 'background: transparent !important;';
        echo '}';
        echo 'body.privacy-php #wpcontent a:not(.button):not([class*="button"]),';
        echo 'body.tools-php #wpcontent a:not(.button):not([class*="button"]),';
        echo 'body.update-core-php #wpcontent a:not(.button):not([class*="button"]),';
        echo 'body.upload-php #wpcontent a:not(.button):not([class*="button"]),';
        echo 'body.import-php #wpcontent a:not(.button):not([class*="button"]),';
        echo 'body.export-php #wpcontent a:not(.button):not([class*="button"]),';
        echo 'body.site-health-php #wpcontent a:not(.button):not([class*="button"]) {';
        echo 'color: ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo 'body.privacy-php #wpcontent a:not(.button):not([class*="button"]):hover,';
        echo 'body.tools-php #wpcontent a:not(.button):not([class*="button"]):hover,';
        echo 'body.update-core-php #wpcontent a:not(.button):not([class*="button"]):hover,';
        echo 'body.upload-php #wpcontent a:not(.button):not([class*="button"]):hover,';
        echo 'body.import-php #wpcontent a:not(.button):not([class*="button"]):hover,';
        echo 'body.export-php #wpcontent a:not(.button):not([class*="button"]):hover,';
        echo 'body.site-health-php #wpcontent a:not(.button):not([class*="button"]):hover {';
        echo 'color: ' . esc_attr( $p3 ) . ' !important;';
        echo '}';
        echo '#wpcontent .page-title-action {';
        echo 'color: ' . esc_attr( $p1 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '#wpcontent .page-title-action:hover {';
        echo 'background: ' . esc_attr( $p1 ) . ' !important;';
        echo 'color: #fff !important;';
        echo '}';
        echo '#wpcontent .tablenav-pages .current-page { border-color: ' . esc_attr( $p1 ) . ' !important; }';
        echo '#wpcontent .tablenav-pages a:hover { border-color: ' . esc_attr( $p1 ) . ' !important; color: ' . esc_attr( $p1 ) . ' !important; }';
        echo '#wpcontent .tablenav .actions .button,';
        echo '#wpcontent .tablenav .button.action {';
        echo 'color: ' . esc_attr( $p1 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '#wpcontent .tablenav .actions .button:hover,';
        echo '#wpcontent .tablenav .button.action:hover {';
        echo 'color: ' . esc_attr( $p3 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p3 ) . ' !important;';
        echo 'background: ' . esc_attr( $p9 ) . ' !important;';
        echo '}';
        echo '#wpcontent .row-actions a,';
        echo '#wpcontent .row-actions span a,';
        echo '#wpcontent .inline-edit-row a,';
        echo '#wpcontent .inline-edit-col a,';
        echo '#wpcontent #bulk-edit a,';
        echo '#wpcontent .quick-edit-row a {';
        echo 'color: ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '#wpcontent .row-actions a:hover,';
        echo '#wpcontent .inline-edit-row a:hover { color: ' . esc_attr( $p3 ) . ' !important; }';
        echo '#wpcontent .inline-edit-row .button-primary,';
        echo '#wpcontent .bulk-edit-row .button-primary {';
        echo 'background: ' . esc_attr( $p1 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p3 ) . ' !important;';
        echo 'color: ' . esc_attr( $p11 ) . ' !important;';
        echo 'box-shadow: none !important;';
        echo '}';
        echo '#wpcontent .inline-edit-row .button-primary:hover,';
        echo '#wpcontent .bulk-edit-row .button-primary:hover {';
        echo 'background: ' . esc_attr( $p3 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p3 ) . ' !important;';
        echo '}';
        echo '#wpcontent .inline-edit-row .button,';
        echo '#wpcontent .bulk-edit-row .button {';
        echo 'color: ' . esc_attr( $p1 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '#wpcontent .inline-edit-row .button:hover,';
        echo '#wpcontent .bulk-edit-row .button:hover {';
        echo 'color: ' . esc_attr( $p3 ) . ' !important;';
        echo 'border-color: ' . esc_attr( $p3 ) . ' !important;';
        echo '}';
        echo '#wpfooter a { color: ' . esc_attr( $p1 ) . '; }';
        echo '#wpfooter a:hover { color: ' . esc_attr( $p3 ) . '; }';
        echo '#adminmenu :focus-visible,';
        echo '#wpadminbar :focus-visible { outline-color: var(--ab-primary) !important; }';
        echo '#adminmenu .wp-submenu li.current > a,';
        echo '#adminmenu .wp-submenu li.current > a:hover {';
        echo 'color: ' . esc_attr( $p11 ) . ' !important;';
        echo 'background: ' . esc_attr( $p1 ) . ' !important;';
        echo 'font-weight: 600;';
        echo '}';
        echo '#adminmenu li.menu-top:hover,';
        echo '#adminmenu li.opensub > a.menu-top,';
        echo '#adminmenu li > a.menu-top:focus {';
        echo 'background-color: ' . esc_attr( $p3 ) . ' !important;';
        echo 'color: ' . esc_attr( $p15 ) . ' !important;';
        echo '}';
        echo '#adminmenu li.menu-top:hover .wp-menu-image:before,';
        echo '#adminmenu li.menu-top:hover .wp-menu-name,';
        echo '#adminmenu li.opensub > a.menu-top .wp-menu-image:before,';
        echo '#adminmenu li.opensub > a.menu-top .wp-menu-name,';
        echo '#adminmenu li > a.menu-top:focus .wp-menu-image:before,';
        echo '#adminmenu li > a.menu-top:focus .wp-menu-name {';
        echo 'color: ' . esc_attr( $p15 ) . ' !important;';
        echo '}';
        echo '#adminmenu .wp-menu-image img {';
        echo 'filter: none !important;';
        echo 'opacity: 0.7 !important;';
        echo 'transition: opacity 0.1s !important;';
        echo '}';
        echo '#adminmenu li:hover .wp-menu-image img,';
        echo '#adminmenu li.wp-has-current-submenu .wp-menu-image img,';
        echo '#adminmenu li.current .wp-menu-image img,';
        echo '#adminmenu li.menu-top:hover .wp-menu-image img,';
        echo '#adminmenu li.opensub > a.menu-top .wp-menu-image img {';
        echo 'filter: none !important;';
        echo 'opacity: 1 !important;';
        echo '}';
        echo '#adminmenu .wp-menu-image.svg {';
        echo 'transition: filter 0.1s, opacity 0.1s !important;';
        echo '}';
        echo '#adminmenu li:hover .wp-menu-image.svg,';
        echo '#adminmenu li.wp-has-current-submenu .wp-menu-image.svg,';
        echo '#adminmenu li.current .wp-menu-image.svg,';
        echo '#adminmenu li.menu-top:hover .wp-menu-image.svg,';
        echo '#adminmenu li.opensub > a.menu-top .wp-menu-image.svg {';
        echo 'filter: brightness(0) saturate(100%) invert(100%) !important;';
        echo 'opacity: 1 !important;';
        echo '}';
        echo '#wpcontent .wrap .nav-tab-active,';
        echo '#wpcontent .wrap .nav-tab-active:focus,';
        echo '#wpcontent .wrap .nav-tab-active:hover {';
        echo 'border-bottom-color: ' . esc_attr( $p4 ) . ' !important;';
        echo 'background: ' . esc_attr( $p4 ) . ' !important;';
        echo 'color: ' . esc_attr( $p6 ) . ' !important;';
        echo '}';
        echo '#wpcontent .wrap .nav-tab:hover { color: ' . esc_attr( $p1 ) . ' !important; }';
        echo '#wpadminbar .quicklinks .ab-sub-wrapper .menupop.hover > a,';
        echo '#wpadminbar .quicklinks .menupop ul li a:focus,';
        echo '#wpadminbar .quicklinks .menupop ul li a:focus strong,';
        echo '#wpadminbar .quicklinks .menupop ul li a:hover,';
        echo '#wpadminbar .quicklinks .menupop ul li a:hover strong,';
        echo '#wpadminbar .quicklinks .menupop.hover ul li a:focus,';
        echo '#wpadminbar .quicklinks .menupop.hover ul li a:hover,';
        echo '#wpadminbar .quicklinks .menupop.hover ul li div[tabindex]:focus,';
        echo '#wpadminbar .quicklinks .menupop.hover ul li div[tabindex]:hover,';
        echo '#wpadminbar li #adminbarsearch.adminbar-focused:before,';
        echo '#wpadminbar li .ab-item:focus .ab-icon:before,';
        echo '#wpadminbar li .ab-item:focus:before,';
        echo '#wpadminbar li a:focus .ab-icon:before,';
        echo '#wpadminbar li.hover .ab-icon:before,';
        echo '#wpadminbar li.hover .ab-item:before,';
        echo '#wpadminbar li:hover #adminbarsearch:before,';
        echo '#wpadminbar li:hover .ab-icon:before,';
        echo '#wpadminbar li:hover .ab-item:before,';
        echo '#wpadminbar.nojs .quicklinks .menupop:hover ul li a:focus,';
        echo '#wpadminbar.nojs .quicklinks .menupop:hover ul li a:hover {';
        echo 'color: ' . esc_attr( $p6 ) . ' !important;';
        echo '}';
        echo '#wpadminbar .ab-top-menu > li.hover > a .ab-icon:before,';
        echo '#wpadminbar .ab-top-menu > li:hover > a .ab-icon:before {';
        echo 'color: ' . esc_attr( $p6 ) . ' !important;';
        echo '}';
        echo '#wpcontent input[type="text"]:focus,';
        echo '#wpcontent input[type="email"]:focus,';
        echo '#wpcontent input[type="url"]:focus,';
        echo '#wpcontent input[type="password"]:focus,';
        echo '#wpcontent input[type="number"]:focus,';
        echo '#wpcontent input[type="search"]:focus,';
        echo '#wpcontent input[type="tel"]:focus,';
        echo '#wpcontent input[type="date"]:focus,';
        echo '#wpcontent input[type="time"]:focus,';
        echo '#wpcontent input[type="week"]:focus,';
        echo '#wpcontent input[type="month"]:focus,';
        echo '#wpcontent input[type="color"]:focus,';
        echo '#wpcontent input[type="checkbox"]:focus,';
        echo '#wpcontent input[type="radio"]:focus,';
        echo '#wpcontent textarea:focus,';
        echo '#wpcontent select:focus {';
        echo 'border-color: ' . esc_attr( $p1 ) . ' !important;';
        echo 'box-shadow: 0 0 0 1px ' . esc_attr( $p1 ) . ' !important;';
        echo 'outline: 2px solid transparent !important;';
        echo '}';
        echo '.wp-core-ui #wpcontent select:hover {';
        echo 'color: ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '.ab-toggle input:checked ~ .ab-toggle__track { background: ' . esc_attr( $p1 ) . ' !important; }';
        echo '#wpcontent .wp-picker-active .wp-color-result,';
        echo '#wpcontent .iris-picker .iris-palette:focus,';
        echo '#wpcontent .iris-picker .iris-palette:hover { outline-color: ' . esc_attr( $p1 ) . ' !important; }';
        echo '#wpcontent .view-switch a.current:before {';
        echo 'color: var(--ab-primary) !important;';
        echo '}';
        echo '#wpcontent input[type="checkbox"],';
        echo '#wpcontent input[type="radio"],';
        echo '#wpcontent input[type="range"],';
        echo '.ab-wrap input[type="checkbox"],';
        echo '.ab-wrap input[type="radio"],';
        echo '.wp-core-ui input[type="checkbox"],';
        echo '.wp-core-ui input[type="radio"],';
        echo '.wp-core-ui input[type="range"] {';
        echo 'accent-color: ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '#wpcontent input[type="radio"]:checked::before,';
        echo '.wp-core-ui input[type="radio"]:checked::before {';
        echo 'background-color: ' . esc_attr( $p1 ) . ' !important;';
        echo '}';
        echo '#wpcontent input[type="checkbox"]:checked::before,';
        echo '.wp-core-ui input[type="checkbox"]:checked::before {';
        echo 'content: url("data:image/svg+xml;utf8,%3Csvg%20xmlns%3D%27http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%27%20viewBox%3D%270%200%2020%2020%27%3E%3Cpath%20d%3D%27M14.83%204.89l1.34.94-5.81%208.38H9.02L5.78%209.67l1.34-1.25%202.57%202.4z%27%20fill%3D%27' . esc_attr( $p1_enc ) . '%27%2F%3E%3C%2Fsvg%3E") !important;';
        echo '}';
        echo '#wpcontent select:not([multiple]):not([size]),';
        echo '.ab-wrap select:not([multiple]):not([size]) {';
        echo 'appearance: none;';
        echo '-webkit-appearance: none;';
        echo 'background-image: url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'16\' height=\'16\' viewBox=\'0 0 16 16\'%3E%3Cpath fill=\'' . esc_attr( $p1_enc ) . '\' d=\'M4 6l4 4 4-4\'/%3E%3C/svg%3E");';
        echo 'background-repeat: no-repeat;';
        echo 'background-position: right 8px center;';
        echo 'background-size: 16px 16px;';
        echo 'padding-right: 28px !important;';
        echo '}';
        echo '#wpcontent select[multiple],';
        echo '#wpcontent select[size],';
        echo '.ab-wrap select[multiple],';
        echo '.ab-wrap select[size] {';
        echo 'appearance: auto;';
        echo '-webkit-appearance: auto;';
        echo 'background-image: none;';
        echo 'padding-right: inherit !important;';
        echo '}';
        echo '</style>';
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
        } // end if ( ! $excluded ) - content CSS block

        // -- Global button overrides (always applied, even on excluded pages) --
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- All $p1/$p3/$p9 vars are sanitised hex colours from $this->colour() which runs sanitize_hex_color().
        echo '<style id="ab-global-buttons">';
        echo ".wp-core-ui .button-primary{background:{$p1}!important;border-color:{$p3}!important;color:#fff!important;box-shadow:none!important}";
        echo ".wp-core-ui .button-primary:hover,.wp-core-ui .button-primary.hover{background:{$p3}!important;border-color:{$p3}!important;color:#fff!important}";
        echo ".wp-core-ui .button-primary:focus,.wp-core-ui .button-primary.focus{background:{$p1}!important;border-color:{$p3}!important;color:#fff!important;box-shadow:0 0 0 1px #fff,0 0 0 3px {$p1}!important}";
        echo ".wp-core-ui .button:not(.button-primary),.wp-core-ui .button-secondary{color:{$p1}!important;border-color:{$p1}!important}";
        echo ".wp-core-ui .button:not(.button-primary):hover,.wp-core-ui .button-secondary:hover{color:{$p3}!important;border-color:{$p3}!important}";
        echo ".wp-core-ui .button-link{color:{$p1}!important}";
        echo ".wp-core-ui .button-link:hover,.wp-core-ui .button-link:active{color:{$p3}!important}";
        echo ".wp-core-ui .button-link:focus{color:{$p1}!important;box-shadow:0 0 0 2px {$p1}!important;outline:2px solid transparent!important}";
        echo ".wp-core-ui .button:focus,.wp-core-ui .button-secondary:focus{border-color:{$p1}!important;box-shadow:0 0 0 1px {$p1}!important;outline:2px solid transparent!important}";

        // Gutenberg / Block Editor: contrast fixes for primary-themed elements.
        // Primary buttons: white text on primary bg.
        echo ".components-button.is-primary{background:{$p1}!important;color:#fff!important}";
        echo ".components-button.is-primary:hover{background:{$p3}!important;color:#fff!important}";
        echo ".components-button.is-primary:focus:not(:disabled){box-shadow:inset 0 0 0 1px #fff,0 0 0 2px {$p1}!important;color:#fff!important}";
        // Publish/update button
        echo ".editor-post-publish-button,.editor-post-publish-button__button{background:{$p1}!important;color:#fff!important}";
        echo ".editor-post-publish-button:hover,.editor-post-publish-button__button:hover{background:{$p3}!important;color:#fff!important}";
        // Post-publish panel
        echo ".post-publish-panel__postpublish-buttons .components-button.is-primary{background:{$p1}!important;color:#fff!important}";
        echo ".post-publish-panel__postpublish-buttons a.components-button{color:{$p1}!important}";
        // Toggle controls
        echo ".components-form-toggle.is-checked .components-form-toggle__track{background:{$p1}!important}";
        // Link-style buttons
        echo ".components-button.is-link{color:{$p1}!important}";
        echo ".components-button.is-link:hover{color:{$p3}!important}";

        // Active/selected states: override --wp-admin-theme-color at element level
        // so Gutenberg/WP components that use the variable for bg get the tint instead.
        // Also set explicit bg/color as fallback for elements not using the variable.

        // Gutenberg List View: selected block row
        echo ".block-editor-list-view-leaf.is-selected,.block-editor-list-view-leaf.is-selected td{--wp-admin-theme-color:{$p9}!important;background:{$p9}!important;color:{$p1}!important}";
        echo ".block-editor-list-view-leaf.is-selected .block-editor-list-view-block-select-button,.block-editor-list-view-leaf.is-selected .components-button{color:{$p1}!important}";
        echo ".block-editor-list-view-leaf.is-selected .block-editor-list-view-block-select-button__anchor{color:{$p1}!important}";
        echo "table.block-editor-list-view-tree tr.is-selected td{background:{$p9}!important;color:{$p1}!important}";

        // Gutenberg tabs (List View / Outline, sidebar tabs)
        echo ".components-tab-panel__tabs .components-button.is-active,.components-tab-panel__tabs .components-button[aria-selected='true']{background:{$p9}!important;color:{$p1}!important;box-shadow:inset 0 -3px 0 {$p1}!important}";
        echo ".edit-post-sidebar__panel-tab.is-active{background:{$p9}!important;color:{$p1}!important}";

        // Editor document bar, inserter, navigator
        echo ".edit-post-header__center .components-button.is-active{background:{$p9}!important;color:{$p1}!important}";
        echo ".block-editor-inserter__panel-content .components-button.is-active{background:{$p9}!important;color:{$p1}!important}";

        // Generic pressed/toggled buttons (toolbar toggles)
        echo ".components-button.is-pressed:not(.is-primary){background:{$p9}!important;color:{$p1}!important}";
        echo ".components-button.is-toggled:not(.is-primary){background:{$p9}!important;color:{$p1}!important}";

        // Any element where WP uses --wp-admin-theme-color as both bg and text
        echo "[style*='--wp-admin-theme-color']:not(.button-primary):not(.is-primary):not(.editor-post-publish-button){--wp-admin-theme-color:{$p1}}";

        // Bricks Builder: active states use tint bg
        echo ".bricks-panel .active,.brx-body .active,.bricks-panel [aria-selected='true']{background:{$p9}!important;color:{$p1}!important}";
        echo ".bricks-panel .bricks-button.primary,.brx-body .bricks-button.primary{background:{$p1}!important;color:#fff!important}";

        echo '</style>';
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

        // -- Body background (optional) -----------------------------------------
        // Overrides common.min.css default (#f0f0f1) when user sets a custom colour.
        // Gated by $excluded - body bg is a content-area override.
        if ( ! $excluded && $body_bg_raw ) {
            $bg = esc_attr( $this->colour( 'admbud_colours_body_bg', '#f0f0f1' ) );
            echo '<style id="ab-body-bg">';
            echo ':root{--ab-ct-body-bg:' . $bg . '}'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $bg escaped via esc_attr() above.
            echo '#wpwrap,#wpcontent,#wpbody,#wpbody-content{background-color:var(--ab-ct-body-bg)!important;}';
            echo '</style>';
        }

        // -- Sidebar gradient (optional) --------------------------------------
        // When gradient is active we apply it to the menu wrapper but give the
        // submenu its own solid dark background so fly-out panels are readable.
        if ( $sidebar_gradient ) {
        $sg1 = esc_attr( $sidebar_grad_from );
        $sg2 = esc_attr( $sidebar_grad_to );
        $sg3 = esc_attr( admbud_get_option( 'admbud_colours_sidebar_grad_dir', self::DEFAULT_SIDEBAR_GRAD_DIR ) );
        // Submenu uses menu_bg_dark (derived from menu_bg) for solid contrast.
        $sg4 = esc_attr( $menu_bg_dark );
        echo '<style id="ab-sidebar-gradient">';
        echo '#adminmenu,#adminmenuback,#adminmenuwrap{'
           . 'background:linear-gradient(' . esc_attr( $sg3 ) . ',' . esc_attr( $sg1 ) . ',' . esc_attr( $sg2 ) . ')!important}';
        echo '#adminmenu .wp-submenu{background:' . esc_attr( $sg4 ) . '!important}';
        echo '</style>';
        }

        // NOTE: Menu item border CSS is output by MenuCustomiser::output_border_css()

        // -- Menu item separator border (optional) ---------------------------
        $menu_item_sep = admbud_get_option( 'admbud_colours_menu_item_sep', '1' ) === '1';
        echo '<style id="ab-menu-item-sep">';
        if ( $menu_item_sep ) {
            echo '#adminmenu .wp-menu-name { border-bottom: 1px solid color-mix(in srgb, var(--ab-menu-sep) 35%, transparent) !important; display:block; }';
        } else {
            echo '#adminmenu .wp-menu-name { border-bottom: none !important; box-shadow: none !important; }';
            echo '#adminmenu li.menu-top { border-bottom: none !important; }';
            echo '#adminmenu li.menu-top > a { border-bottom: none !important; }';
        }
        echo '</style>';

        // -- Content area tokens -------------------------------------------------
        // Always resolve values - saved option if set, otherwise derive from existing tokens.
        // This ensures presets remain coherent: changing one colour doesn't snap
        // unset colours back to WP defaults.
        $body_bg_val = $body_bg_raw ? $this->colour( 'admbud_colours_body_bg', self::WP_CONTENT_BG ) : self::WP_CONTENT_BG;

        $ct_heading    = $ct_heading_raw    ? $this->colour( 'admbud_colours_content_heading',   self::WP_HEADING_TEXT )     : self::WP_HEADING_TEXT;
        $ct_text       = $ct_text_raw       ? $this->colour( 'admbud_colours_content_text',      self::WP_BODY_TEXT )        : self::WP_BODY_TEXT;
        $ct_link       = $ct_link_raw       ? $this->colour( 'admbud_colours_content_link',      $primary )                  : $primary;
        $ct_link_hover = $ct_link_hover_raw ? $this->colour( 'admbud_colours_content_link_hover',$secondary )                : $secondary;

        $ct_tbl_hdr_bg  = $ct_tbl_hdr_bg_raw   ? $this->colour( 'admbud_colours_table_header_bg',   $primary_tint )             : $primary_tint;
        $ct_tbl_hdr_txt = $ct_tbl_hdr_txt_raw   ? $this->colour( 'admbud_colours_table_header_text', self::WP_HEADING_TEXT )     : self::WP_HEADING_TEXT;
        $ct_tbl_hdr_link= $ct_tbl_hdr_link_raw  ? $this->colour( 'admbud_colours_table_header_link', self::WP_HEADING_TEXT )     : self::WP_HEADING_TEXT;
        $ct_tbl_row_bg  = $ct_tbl_row_bg_raw    ? $this->colour( 'admbud_colours_table_row_bg',      self::WP_TABLE_ROW_BG )     : self::WP_TABLE_ROW_BG;
        $ct_tbl_row_alt = $ct_tbl_row_alt_raw   ? $this->colour( 'admbud_colours_table_row_alt_bg',  self::WP_TABLE_ROW_ALT_BG ) : $this->lighten_mix( $ct_tbl_row_bg, $body_bg_val, 0.5 );
        $ct_tbl_hover   = $ct_tbl_row_hover_raw
            ? $this->colour( 'admbud_colours_table_row_hover', $primary_tint )
            : ( $body_bg_raw ? 'rgba(' . $this->hex_to_rgb_triplet( $primary ) . ',0.12)' : $primary_tint );
        $ct_tbl_border  = $ct_tbl_border_raw    ? $this->colour( 'admbud_colours_table_border',      self::WP_TABLE_BORDER )     : self::WP_TABLE_BORDER;
        $ct_tbl_action  = $ct_tbl_action_raw    ? $this->colour( 'admbud_colours_table_action_link', $primary )                  : $primary;
        $ct_tbl_title   = $ct_tbl_title_raw     ? $this->colour( 'admbud_colours_table_title_link',  $primary )                  : $primary;

        $ct_input_bg    = $ct_input_bg_raw     ? $this->colour( 'admbud_colours_input_bg',          self::WP_INPUT_BG )         : self::WP_INPUT_BG;
        $ct_input_bdr   = $ct_input_border_raw  ? $this->colour( 'admbud_colours_input_border',      self::WP_INPUT_BORDER )     : self::WP_INPUT_BORDER;
        $ct_input_focus = $ct_input_focus_raw   ? $this->colour( 'admbud_colours_input_focus',       $primary )                  : $primary;
        $ct_btn_sec     = $ct_btn_sec_bg_raw    ? $this->colour( 'admbud_colours_btn_secondary_bg',  self::WP_BTN_SECONDARY_BG ) : self::WP_BTN_SECONDARY_BG;

        $ct_pbox_bg    = $ct_postbox_bg_raw   ? $this->colour( 'admbud_colours_postbox_bg',         self::WP_POSTBOX_BG )       : self::WP_POSTBOX_BG;
        $ct_pbox_hdr   = $ct_postbox_hdr_raw  ? $this->colour( 'admbud_colours_postbox_header_bg',  self::WP_POSTBOX_HEADER_BG ): self::WP_POSTBOX_HEADER_BG;
        $ct_pbox_bdr   = $ct_postbox_bdr_raw  ? $this->colour( 'admbud_colours_postbox_border',     self::WP_POSTBOX_BORDER )   : self::WP_POSTBOX_BORDER;
        $ct_pbox_txt   = $ct_postbox_txt_raw  ? $this->colour( 'admbud_colours_postbox_text',       $ct_text )                  : $ct_text;
        $ct_notice_bg  = $ct_notice_bg_raw    ? $this->colour( 'admbud_colours_notice_bg',          $body_bg_val )              : $body_bg_val;
        $ct_tbl_row_txt = $ct_tbl_row_txt_raw ? $this->colour( 'admbud_colours_table_row_text',     self::WP_TABLE_ROW_TEXT )   : self::WP_TABLE_ROW_TEXT;
        $ct_tbl_alt_txt = $ct_tbl_alt_txt_raw ? $this->colour( 'admbud_colours_table_row_alt_text', self::WP_TABLE_ROW_TEXT )   : self::WP_TABLE_ROW_TEXT;
        $ct_tbl_sep     = $ct_tbl_sep_raw     ? $this->colour( 'admbud_colours_table_row_separator',self::WP_TABLE_ROW_SEPARATOR): self::WP_TABLE_ROW_SEPARATOR;
        $ct_btn_primary = $ct_btn_primary_raw ? $this->colour( 'admbud_colours_btn_primary_bg',     $primary )                  : $primary;
        $ct_btn_pri_txt = $ct_btn_pri_txt_raw ? $this->colour( 'admbud_colours_btn_primary_text',   '#ffffff' )                 : '#ffffff';
        $ct_btn_pri_hvr = $ct_btn_pri_hvr_raw ? $this->colour( 'admbud_colours_btn_primary_hover',  $secondary )                : $secondary;

        // Only output the style block if ANY content option is set OR body_bg is set.
        // This keeps clean installs zero-overhead while ensuring preset coherence.
        $ct_any = $body_bg_raw || $ct_heading_raw || $ct_text_raw || $ct_link_raw || $ct_link_hover_raw ||
                  $ct_tbl_hdr_bg_raw || $ct_tbl_hdr_txt_raw || $ct_tbl_hdr_link_raw || $ct_tbl_row_bg_raw || $ct_tbl_row_alt_raw ||
                  $ct_tbl_row_hover_raw || $ct_tbl_border_raw || $ct_tbl_action_raw || $ct_tbl_title_raw ||
                  $ct_tbl_row_txt_raw || $ct_tbl_alt_txt_raw || $ct_tbl_sep_raw ||
                  $ct_btn_primary_raw || $ct_btn_pri_txt_raw || $ct_btn_pri_hvr_raw ||
                  $ct_input_bg_raw || $ct_input_border_raw || $ct_input_focus_raw || $ct_btn_sec_bg_raw ||
                  $ct_postbox_bg_raw || $ct_postbox_hdr_raw || $ct_postbox_bdr_raw || $ct_postbox_txt_raw || $ct_notice_bg_raw;

        if ( $ct_any ) {
            $out = '<style id="ab-content-colours">';

            // Emit CSS custom properties for all content tokens so presets stay coherent
            $out .= ':root{';
            $out .= '--ab-ct-body-bg:' . esc_attr($body_bg_val) . ';';
            $out .= '--ab-ct-heading:' . esc_attr($ct_heading) . ';';
            $out .= '--ab-ct-text:' . esc_attr($ct_text) . ';';
            $out .= '--ab-ct-link:' . esc_attr($ct_link) . ';';
            $out .= '--ab-ct-link-hover:' . esc_attr($ct_link_hover) . ';';
            $out .= '--ab-ct-tbl-hdr-bg:' . esc_attr($ct_tbl_hdr_bg) . ';';
            $out .= '--ab-ct-tbl-hdr-txt:' . esc_attr($ct_tbl_hdr_txt) . ';';
            $out .= '--ab-ct-tbl-hdr-link:' . esc_attr($ct_tbl_hdr_link) . ';';
            $out .= '--ab-ct-tbl-row:' . esc_attr($ct_tbl_row_bg) . ';';
            $out .= '--ab-ct-tbl-alt:' . esc_attr($ct_tbl_row_alt) . ';';
            $out .= '--ab-ct-tbl-hover:' . esc_attr($ct_tbl_hover) . ';';
            $out .= '--ab-ct-tbl-border:' . esc_attr($ct_tbl_border) . ';';
            $out .= '--ab-ct-tbl-action:' . esc_attr($ct_tbl_action) . ';';
            $out .= '--ab-ct-tbl-title:' . esc_attr($ct_tbl_title) . ';';
            $out .= '--ab-ct-input-bg:' . esc_attr($ct_input_bg) . ';';
            $out .= '--ab-ct-input-bdr:' . esc_attr($ct_input_bdr) . ';';
            $out .= '--ab-ct-input-focus:' . esc_attr($ct_input_focus) . ';';
            $out .= '--ab-ct-btn-sec:' . esc_attr($ct_btn_sec) . ';';
            $out .= '--ab-ct-pbox-bg:' . esc_attr($ct_pbox_bg) . ';';
            $out .= '--ab-ct-pbox-hdr:' . esc_attr($ct_pbox_hdr) . ';';
            $out .= '--ab-ct-pbox-bdr:' . esc_attr($ct_pbox_bdr) . ';';
            $out .= '--ab-ct-pbox-txt:' . esc_attr($ct_pbox_txt) . ';';
            $out .= '--ab-ct-notice-bg:' . esc_attr($ct_notice_bg) . ';';
            $out .= '--ab-ct-tbl-row-txt:' . esc_attr($ct_tbl_row_txt) . ';';
            $out .= '--ab-ct-tbl-alt-txt:' . esc_attr($ct_tbl_alt_txt) . ';';
            $out .= '--ab-ct-tbl-sep:' . esc_attr($ct_tbl_sep) . ';';
            $out .= '--ab-ct-btn-primary:' . esc_attr($ct_btn_primary) . ';';
            $out .= '--ab-ct-btn-pri-txt:' . esc_attr($ct_btn_pri_txt) . ';';
            $out .= '--ab-ct-btn-pri-hvr:' . esc_attr($ct_btn_pri_hvr) . ';';
            $out .= '}';

            // Page - headings and text scoped to WP's own .wrap to avoid
            // colouring third-party plugin headings/paragraphs/labels.
            // Headings - broad coverage including pages without .wrap
            $out .= '#wpcontent h1:not(.ab-wrap *),#wpcontent h2:not(.ab-wrap *),#wpcontent h3:not(.ab-wrap *),#wpcontent h4:not(.ab-wrap *),#wpcontent h5:not(.ab-wrap *){color:var(--ab-ct-heading)!important}';
            $out .= '#wpcontent .wrap h1:not(.ab-wrap *),#wpcontent .wrap h2:not(.ab-wrap *),#wpcontent .wrap h3:not(.ab-wrap *),#wpcontent .wrap h4:not(.ab-wrap *){color:var(--ab-ct-heading)!important}';
            $out .= '#wpcontent .form-table td,#wpcontent .form-table th,#wpcontent .form-table label{color:var(--ab-ct-text)}';
            // Links - only WP structural containers; never bare #wpcontent a
            $out .= '#wpcontent .wp-list-table td a:not(.button):not([class*="button"]):not(.row-title),#wpcontent .widefat td a:not(.button):not([class*="button"]):not(.row-title),#wpcontent .form-table a{color:var(--ab-ct-link)!important}';
            $out .= '#wpcontent .wp-list-table td a:not(.button):not([class*="button"]):not(.row-title):hover,#wpcontent .widefat td a:not(.button):not([class*="button"]):not(.row-title):hover,#wpcontent .form-table a:hover{color:var(--ab-ct-link-hover)!important}';

            // Tables - include tfoot, reset inactive rows, add row text + separator
            $out .= '#wpcontent .wp-list-table thead th,#wpcontent .widefat thead th,#wpcontent .wp-list-table tfoot th,#wpcontent .widefat tfoot th{background:var(--ab-ct-tbl-hdr-bg)!important;color:var(--ab-ct-tbl-hdr-txt)!important}';
            // Header sort links (Title, Author, Date columns)
            $out .= '#wpcontent .wp-list-table thead th a,#wpcontent .widefat thead th a,#wpcontent .wp-list-table tfoot th a,#wpcontent .widefat tfoot th a{color:var(--ab-ct-tbl-hdr-link)!important}';
            $out .= '#wpcontent .wp-list-table thead th a:hover,#wpcontent .widefat thead th a:hover{color:var(--ab-ct-tbl-hdr-link)!important;opacity:0.8}';
            // Header sort indicator arrows
            $out .= '#wpcontent .wp-list-table thead .sorting-indicators .sorting-indicator-asc span,#wpcontent .wp-list-table thead .sorting-indicators .sorting-indicator-desc span{border-bottom-color:var(--ab-ct-tbl-hdr-link);border-top-color:var(--ab-ct-tbl-hdr-link)}';
            $out .= '#wpcontent .wp-list-table thead th.sorted a,#wpcontent .wp-list-table thead th.sorted .sorting-indicators span{color:var(--ab-ct-tbl-hdr-link)!important}';
            $out .= '#wpcontent .wp-list-table tbody tr,#wpcontent .widefat tbody tr{background:var(--ab-ct-tbl-row)!important}';
            $out .= '#wpcontent .wp-list-table tbody td,#wpcontent .widefat tbody td,#wpcontent .widefat tbody th{color:var(--ab-ct-tbl-row-txt)!important;border-bottom:1px solid var(--ab-ct-tbl-sep)}';
            $out .= '#wpcontent .widefat ol,#wpcontent .widefat p,#wpcontent .widefat ul{color:var(--ab-ct-tbl-row-txt)!important}';
            $out .= '#wpcontent .wp-list-table tbody td p,#wpcontent .wp-list-table tbody td span:not(.ab-wrap *){color:var(--ab-ct-tbl-row-txt)!important}';
            // Updates page - .widefat.updates-table uses unique row structure
            $out .= '#wpcontent .updates-table td,#wpcontent .updates-table th{color:var(--ab-ct-tbl-row-txt)!important;background:var(--ab-ct-tbl-row)!important}';
            $out .= '#wpcontent .updates-table .plugin-title td,#wpcontent .updates-table .plugin-title th{background:var(--ab-ct-tbl-alt)!important}';
            $out .= '#wpcontent .updates-table .check-column{background:var(--ab-ct-tbl-row)!important}';
            // .widefat p/ul/ol hardcodes #2c3338 - override with row text token
            $out .= '#wpcontent .widefat p,#wpcontent .widefat ul,#wpcontent .widefat ol{color:var(--ab-ct-tbl-row-txt)!important}';
            $out .= '#wpcontent .wp-list-table tbody tr.alternate,#wpcontent .widefat tbody tr.alternate,#wpcontent .plugins tr.inactive td,#wpcontent .plugins tr.inactive th{background:var(--ab-ct-tbl-alt)!important}';
            $out .= '#wpcontent .wp-list-table tbody tr.alternate td,#wpcontent .widefat tbody tr.alternate td{color:var(--ab-ct-tbl-alt-txt)}';
            $out .= '#wpcontent .wp-list-table tbody tr:hover td,#wpcontent .widefat tbody tr:hover td,#wpcontent .plugins tr:hover>td,#wpcontent .plugins tr:hover>th{background:var(--ab-ct-tbl-hover)!important;color:var(--ab-ct-tbl-row-txt)!important}';
            $out .= '#wpcontent .wp-list-table td,#wpcontent .wp-list-table th,#wpcontent .widefat td,#wpcontent .widefat th{border-color:var(--ab-ct-tbl-border)!important}';
            // List table misc links (non-title, non-action, non-header)
            $out .= '#wpcontent .wp-list-table td a:not(.button):not([class*="button"]):not(.row-title){color:var(--ab-ct-link)!important}';
            $out .= '#wpcontent .wp-list-table td a:not(.button):not([class*="button"]):not(.row-title):hover{color:var(--ab-ct-link-hover)!important}';
            // Header sort links
            $out .= '#wpcontent .wp-list-table thead th a,#wpcontent .widefat thead th a,#wpcontent .wp-list-table tfoot th a,#wpcontent .widefat tfoot th a{color:var(--ab-ct-tbl-hdr-link)!important}';
            $out .= '#wpcontent .wp-list-table thead th a:hover,#wpcontent .widefat thead th a:hover{color:var(--ab-ct-tbl-hdr-link)!important;opacity:0.8}';
            // Row title links (post titles, plugin names, user names)
            $out .= '#wpcontent .wp-list-table td a.row-title,#wpcontent #the-list .column-primary>a:not(.button):not([class*="button"]),#wpcontent .wp-list-table td strong>a:not(.button){color:var(--ab-ct-tbl-title)!important}';
            $out .= '#wpcontent .wp-list-table td a.row-title:hover,#wpcontent #the-list .column-primary>a:not(.button):not([class*="button"]):hover,#wpcontent .wp-list-table td strong>a:not(.button):hover{opacity:0.8}';
            // Action links (Edit / Trash / View)
            $out .= '#wpcontent .row-actions a{color:var(--ab-ct-tbl-action)!important}';
            // Filter/status links
            $out .= '#wpcontent .subsubsub a{color:var(--ab-ct-link)!important}';
            $out .= '#wpcontent .subsubsub a:hover,#wpcontent .subsubsub a.current{color:var(--ab-ct-link-hover)!important}';
            // Checkbox column hover
            $out .= '#wpcontent .check-column input:hover+label,#wpcontent .check-column label:hover{background:var(--ab-ct-tbl-hdr-bg)!important}';

            // Forms - scoped to WP's .form-table and .tablenav (WP-unique structures)
            // Never bare #wpcontent input/select which hits every third-party plugin form.
            $form_sels = '#wpcontent .form-table input[type="text"],#wpcontent .form-table input[type="email"],#wpcontent .form-table input[type="url"],#wpcontent .form-table input[type="number"],#wpcontent .form-table input[type="password"],#wpcontent .form-table input[type="search"],#wpcontent .form-table textarea,#wpcontent .form-table select';
            $out .= $form_sels . '{background-color:var(--ab-ct-input-bg)!important;border-color:var(--ab-ct-input-bdr)!important;color:var(--ab-ct-text)!important}';
            $out .= '#wpcontent .form-table input:focus,#wpcontent .form-table textarea:focus,#wpcontent .form-table select:focus{border-color:var(--ab-ct-input-focus)!important;box-shadow:0 0 0 1px var(--ab-ct-input-focus)!important}';
            // Tablenav selects (Bulk actions, date filters)
            $out .= '#wpcontent .tablenav select{background-color:var(--ab-ct-input-bg)!important;border-color:var(--ab-ct-input-bdr)!important;color:var(--ab-ct-text)!important}';
            // Save Changes button on WP Settings pages (.form-table context only)
            $out .= '#wpcontent .form-table ~ p .button-primary,#wpcontent .submit .button-primary,#wpcontent p.submit .button-primary{background:var(--ab-ct-btn-primary)!important;border-color:var(--ab-ct-btn-primary)!important;color:var(--ab-ct-btn-pri-txt)!important}';
            $out .= '#wpcontent .form-table ~ p .button-primary:hover,#wpcontent .submit .button-primary:hover,#wpcontent p.submit .button-primary:hover{background:var(--ab-ct-btn-pri-hvr)!important;border-color:var(--ab-ct-btn-pri-hvr)!important}';
            // .wrap primary buttons (plugin upload, tools, privacy "Use This Page" etc.)
            $out .= '#wpcontent .wrap .button-primary,.wp-core-ui #wpcontent .button-primary{background:var(--ab-ct-btn-primary)!important;border-color:var(--ab-ct-btn-primary)!important;color:var(--ab-ct-btn-pri-txt)!important}';
            $out .= '#wpcontent .wrap .button-primary:hover,.wp-core-ui #wpcontent .button-primary:hover{background:var(--ab-ct-btn-pri-hvr)!important;border-color:var(--ab-ct-btn-pri-hvr)!important}';
            // .wrap secondary/plain buttons in dark mode
            $out .= '#wpcontent .wrap .button:not(.button-primary),.wp-core-ui #wpcontent .button:not(.button-primary){background:var(--ab-ct-btn-sec)!important;color:var(--ab-ct-text)!important;border-color:var(--ab-ct-input-bdr)!important}';
            // Tablenav buttons and page-title-action
            $out .= '#wpcontent .tablenav .button,#wpcontent .tablenav .button-secondary{background:var(--ab-ct-btn-sec)!important;color:var(--ab-ct-text)!important;border-color:var(--ab-ct-input-bdr)!important}';
            $out .= '#wpcontent .page-title-action{color:var(--ab-ct-btn-primary)!important;border-color:var(--ab-ct-btn-primary)!important;background:transparent!important}';
            $out .= '#wpcontent .page-title-action:hover{background:var(--ab-ct-btn-primary)!important;border-color:var(--ab-ct-btn-primary)!important;color:var(--ab-ct-btn-pri-txt)!important}';
            // .wrap content area background
            $out .= '#wpcontent .wrap{color:var(--ab-ct-text)}';

            // -- Dark mode: systematic WP white background reset --------------
            // Fires only when body_bg is set (dark palette active).
            // All tokens are set by the palette generator - no guessing needed.
            if ( $body_bg_raw ) {
                // Body + page wrappers (covers white strip above #wpwrap on Privacy/Site Health)
                $out .= 'body.wp-admin,#wpwrap,#wpbody,#wpbody-content,#wpcontent{background:var(--ab-ct-body-bg)!important}';

                // Headings outside #wpcontent - WP renders h1 in a sticky title bar above
                // #wpcontent on Privacy, Site Health etc. with hardcoded color:#1d2327
                $out .= '#wpbody h1,#wpbody h2,#wpbody h3,#wpbody-content h1,#wpbody-content h2{color:var(--ab-ct-heading)!important}';

                // Privacy / Site Health sticky title area - has its own white bg independent of body
                // WP uses .health-check-header and .privacy-settings-header (not -wrapper)
                $out .= '.health-check-header,.privacy-settings-header,.health-check-header-wrapper,.site-health-progress-wrapper{background:var(--ab-ct-pbox-bg)!important;border-color:var(--ab-ct-pbox-bdr)!important;color:var(--ab-ct-heading)!important}';
                $out .= '.health-check-header h1,.health-check-header h2,.privacy-settings-header h1,.privacy-settings-header h2{color:var(--ab-ct-heading)!important}';

                // body { color: #3c434a } - hardcoded dark text on body element
                // Override with content text token so any unscoped text is light in dark mode
                $out .= 'body.wp-admin{color:var(--ab-ct-text)!important}';

                // Inactive plugin rows - remove the separator border-bottom that shows as a bright line
                $out .= '#wpcontent .plugins tr.inactive td,#wpcontent .plugins tr.inactive th{border-bottom-color:var(--ab-ct-pbox-bdr)!important}';
                $out .= '#wpcontent .plugin-update-tr.active td{border-left-color:var(--ab-ct-btn-primary)!important}';

                // General WP admin surfaces
                $out .= '#wpcontent .stuffbox,#wpcontent .widgets-holder-wrap{background:var(--ab-ct-pbox-bg)!important}';

                // Media modal close button focus - hardcoded #135e96
                $out .= '.media-modal-close:focus{color:var(--ab-ct-heading)!important;border-color:var(--ab-ct-pbox-bdr)!important;box-shadow:0 0 3px var(--ab-ct-btn-primary)!important}';

                // Buttons on non-.wrap pages (Privacy, Site Health, Tools)
                $out .= 'body.wp-admin #wpcontent .button:not(.button-primary){background:var(--ab-ct-btn-sec)!important;color:var(--ab-ct-text)!important;border-color:var(--ab-ct-input-bdr)!important}';

                // Site Health
                $out .= '#wpcontent .health-check-accordion-trigger,#wpcontent .privacy-settings-accordion-trigger{background:var(--ab-ct-tbl-alt)!important;color:var(--ab-ct-text)!important;border-color:var(--ab-ct-pbox-bdr)!important}';
                $out .= '#wpcontent .health-check-accordion-trigger:hover,#wpcontent .privacy-settings-accordion-trigger:hover{background:var(--ab-ct-pbox-hdr)!important}';
                $out .= '#wpcontent .health-check-accordion-trigger .badge{background:transparent!important;color:var(--ab-ct-text)!important;border-color:var(--ab-ct-pbox-bdr)!important}';
                $out .= '#wpcontent #health-check-body,#wpcontent .health-check-body,#wpcontent .site-health-issues-wrapper{background:var(--ab-ct-pbox-bg)!important;color:var(--ab-ct-text)!important}';
                $out .= '#wpcontent #health-check-body table{background:var(--ab-ct-pbox-bg)!important}';
                $out .= '#wpcontent #health-check-body td,#wpcontent #health-check-body th{background:var(--ab-ct-tbl-alt)!important;color:var(--ab-ct-text)!important;border-color:var(--ab-ct-pbox-bdr)!important}';
                $out .= '#wpcontent #health-check-body tr:nth-child(even) td,#wpcontent #health-check-body tr:nth-child(even) th{background:var(--ab-ct-pbox-bg)!important}';
                $out .= '#wpcontent #health-check-body .health-check-table th{background:var(--ab-ct-pbox-hdr)!important;color:var(--ab-ct-heading)!important}';
                $out .= '#wpcontent #health-check-body .button{background:var(--ab-ct-btn-sec)!important;color:var(--ab-ct-text)!important;border-color:var(--ab-ct-input-bdr)!important}';

                // Privacy
                $out .= '#wpcontent .privacy-policy-settings,#wpcontent #privacy-settings-content{background:var(--ab-ct-pbox-bg)!important;color:var(--ab-ct-text)!important}';

                // Plugins page active row - transparent primary tint, not solid colour
                $out .= '#wpcontent .plugins tr.active>td,#wpcontent .plugins tr.active>th,#wpcontent #the-list .active>td,#wpcontent #the-list .active>th{background:rgba(' . esc_attr( $this->hex_to_rgb_triplet( $primary ) ) . ',0.12)!important;color:var(--ab-ct-text)!important}';

                // Updates page - theme/plugin thumbnail rows have hardcoded white bg
                $out .= '#wpcontent .updates-table td,#wpcontent .updates-table th{background:var(--ab-ct-tbl-row)!important;color:var(--ab-ct-text)!important;border-color:var(--ab-ct-pbox-bdr)!important}';
                $out .= '#wpcontent .updates-table .plugin-title td,#wpcontent .updates-table .plugin-title th{background:var(--ab-ct-tbl-alt)!important}';

                // Media modal (lives outside #wpwrap - needs full surface mapping)
                $out .= '.media-modal{background:rgba(0,0,0,0.75)!important}';
                $out .= '.media-modal .media-modal-content{background:var(--ab-ct-pbox-bg)!important}';
                $out .= '.media-modal .media-frame-title{background:var(--ab-ct-pbox-bg)!important;color:var(--ab-ct-heading)!important}';
                $out .= '.media-modal .media-router{background:var(--ab-ct-pbox-bg)!important;border-color:var(--ab-ct-pbox-bdr)!important}';
                $out .= '.media-modal .media-router .media-menu-item{color:var(--ab-ct-text)!important}';
                $out .= '.media-modal .media-router .media-menu-item.active,.media-modal .media-router .media-menu-item:focus{background:var(--ab-ct-tbl-alt)!important;color:var(--ab-ct-heading)!important}';
                $out .= '.media-modal .media-frame-content,.media-modal .uploader-inline,.media-modal .uploader-inline-content{background:var(--ab-ct-tbl-alt)!important}';
                $out .= '.media-modal .upload-ui p,.media-modal .upload-instructions{color:var(--ab-ct-text)!important}';
                $out .= '.media-modal .media-frame-toolbar{background:var(--ab-ct-pbox-bg)!important;border-color:var(--ab-ct-pbox-bdr)!important}';
                $out .= '.media-modal .media-sidebar,.media-modal .attachment-details{background:var(--ab-ct-pbox-bg)!important;border-color:var(--ab-ct-pbox-bdr)!important}';
                $out .= '.media-modal .media-sidebar *,.media-modal .attachment-details *{color:var(--ab-ct-text)!important}';
                $out .= '.media-modal .media-sidebar h2,.media-modal .media-sidebar h3,.media-modal .attachment-details h2,.media-modal .attachment-details h3{color:var(--ab-ct-heading)!important}';
                $out .= '.media-modal input[type="text"],.media-modal input[type="search"],.media-modal select,.media-modal textarea{background:var(--ab-ct-input-bg)!important;border-color:var(--ab-ct-input-bdr)!important;color:var(--ab-ct-text)!important}';

                // Plugin editor dialog
                $out .= '.wp-dialog{background:var(--ab-ct-pbox-bg)!important;color:var(--ab-ct-text)!important;border-color:var(--ab-ct-pbox-bdr)!important}';
                $out .= '.wp-dialog h1,.wp-dialog h2,.wp-dialog p{color:var(--ab-ct-text)!important}';

            } else {
                // Light mode - keep existing media modal rules (no dark body bg)
                $out .= '.media-modal .media-modal-content{background:#fff}';
                $out .= '.media-modal .media-frame-content{background:#f0f0f1}';
            }
            // Table outer border
            $out .= '#wpcontent .wp-list-table,#wpcontent .widefat{border-color:var(--ab-ct-tbl-border)!important}';
            // WP footer
            $out .= '#wpfooter{color:var(--ab-ct-text)}';
            $out .= '#wpfooter a{color:var(--ab-ct-link)!important}';
            // Tablenav text (items count, pagination text)
            $out .= '#wpcontent .tablenav .displaying-num,#wpcontent .tablenav-pages{color:var(--ab-ct-text)}';

            // Cards
            $out .= '#wpcontent .postbox{background:var(--ab-ct-pbox-bg)!important;border-color:var(--ab-ct-pbox-bdr)!important;color:var(--ab-ct-pbox-txt)!important}';
            $out .= '#wpcontent .postbox .postbox-header{background:var(--ab-ct-pbox-hdr)!important}';
            $out .= '#wpcontent .postbox .postbox-header h2,#wpcontent .postbox .hndle{color:var(--ab-ct-heading)!important}';
            $out .= '#wpcontent .postbox .inside,#wpcontent .postbox .inside *:not(a):not(h1):not(h2):not(h3):not(h4){color:var(--ab-ct-pbox-txt)}';

            // Notices
            $out .= '#wpcontent .notice,#wpcontent .updated,#wpcontent .error,#wpcontent .update-nag{background:var(--ab-ct-notice-bg)!important;border-color:var(--ab-ct-tbl-border)!important}';

            // Checkboxes/radios: let WP handle rendering, just ensure accent-color is set (done in base CSS)
            // Check-column - use row bg so checkbox area matches table in dark mode
            $out .= '.check-column{background:var(--ab-ct-tbl-row)!important}';
            $out .= 'thead .check-column,tfoot .check-column{background:var(--ab-ct-tbl-hdr-bg)!important}';

            // Active plugin row - transparent primary tint over the row bg
            $out .= '.plugins tr.active>td,.plugins tr.active>th,#the-list .active>td,#the-list .active>th{background-color:rgba(' . esc_attr( $this->hex_to_rgb_triplet( $primary ) ) . ',0.08)!important}';
            $out .= '.plugins tr.inactive td,.plugins tr.inactive th{background-color:var(--ab-ct-tbl-row)!important}';
            // Plugin update count badge
            // Update count - let WP handle default red, just clean box-shadow
            // (removed custom bg/colour overrides that looked weird)
            // Admin menu update count bubble
            // (sidebar update count - using WP default)

            // Plugin cards (Add Plugins page)
            $out .= '#wpcontent .plugin-card{background:var(--ab-ct-pbox-bg)!important;border-color:var(--ab-ct-pbox-bdr)!important}';
            $out .= '#wpcontent .plugin-card .plugin-card-bottom{background:var(--ab-ct-tbl-hdr-bg)!important;border-color:var(--ab-ct-pbox-bdr)!important}';
            $out .= '#wpcontent .plugin-card .column-name h3 a,#wpcontent .plugin-card .column-description p{color:var(--ab-ct-text)!important}';
            $out .= '#wpcontent .plugin-card .plugin-card-bottom .column-rating,.plugin-card .plugin-card-bottom .column-updated,.plugin-card .plugin-card-bottom .column-compatibility{color:var(--ab-ct-text)}';

            // Theme cards (Themes page)
            $out .= '#wpcontent .theme-browser .theme{background:var(--ab-ct-pbox-bg)!important;border-color:var(--ab-ct-pbox-bdr)!important}';
            $out .= '#wpcontent .theme-browser .theme .theme-name{background:var(--ab-ct-tbl-hdr-bg)!important;color:var(--ab-ct-heading)!important}';

            // WP tab bar (Featured | Popular | etc on plugins/themes pages)
            $out .= '#wpcontent .filter-links li a{color:var(--ab-ct-link)!important}';
            $out .= '#wpcontent .filter-links li a:hover,#wpcontent .filter-links li a.current{color:var(--ab-ct-link-hover)!important}';
            $out .= '#wpcontent .wp-filter{background:var(--ab-ct-pbox-bg)!important;border-color:var(--ab-ct-pbox-bdr)!important}';

            // Wrap and general chrome
            $out .= '#wpcontent .wrap{color:var(--ab-ct-text)}';
            $out .= '#wpcontent .screen-reader-text:focus{background:var(--ab-ct-pbox-bg)!important;color:var(--ab-ct-heading)!important}';
            // Description text colour
            $out .= '#wpcontent .description,#wpcontent p.description{color:var(--ab-ct-text)!important;opacity:0.75}';

            // Plugin/theme action links
            $out .= '#wpcontent .plugin-action-buttons a{color:var(--ab-ct-link)!important}';
            $out .= '#wpcontent .theme-actions a{color:var(--ab-ct-btn-pri-txt)!important}';
            $out .= '#wpcontent .plugin-action-buttons .install-now,#wpcontent .plugin-action-buttons .button{background:var(--ab-ct-btn-sec)!important;color:var(--ab-ct-text)!important;border-color:var(--ab-ct-input-bdr)!important}';

            // WP chrome backgrounds that hardcode #fff - make transparent or use token
            $out .= '.popular-tags,p.popular-tags,.comment-ays,.feature-filter,.stuffbox,.widgets-holder-wrap,.wp-editor-container{background:transparent!important}';
            // Widefat table itself (some WP pages add white bg to the table element)
            $out .= '#wpcontent table.widefat,#wpcontent .wp-list-table{background:transparent!important}';

            // Row hover - use tbl-hover token (user-configurable, dark mode derives rgba automatically)
            $out .= '#the-list tr:hover>td,#the-list tr:hover>th,.plugins tr:hover>td,.plugins tr:hover>th{background-color:var(--ab-ct-tbl-hover)!important}';

            // Dashboard widget placeholders - dashed outline
            $out .= '#dashboard-widgets .postbox-container .empty-container{outline-color:var(--ab-ct-tbl-border)!important;background:transparent!important}';
            $out .= '.empty-container{outline-color:var(--ab-ct-tbl-border)!important}';
            // Dashboard welcome panel
            $out .= '#wpcontent .welcome-panel{background:var(--ab-ct-pbox-bg)!important;border-color:var(--ab-ct-pbox-bdr)!important;color:var(--ab-ct-text)!important}';

            // Dashboard widget inputs (Quick Draft, etc)
            $out .= '#dashboard-widgets input[type="text"],#dashboard-widgets textarea,#dashboard-widgets select{background-color:var(--ab-ct-input-bg)!important;border-color:var(--ab-ct-input-bdr)!important;color:var(--ab-ct-text)!important}';
            $out .= '#dashboard-widgets input[type="text"]:focus,#dashboard-widgets textarea:focus{border-color:var(--ab-ct-input-focus)!important;box-shadow:0 0 0 1px var(--ab-ct-input-focus)!important}';
            // Dashboard widget labels and descriptions
            $out .= '#dashboard-widgets .postbox label,#dashboard-widgets .postbox .drafts h2{color:var(--ab-ct-text)!important}';
            // Dashboard widget links
            $out .= '#dashboard-widgets .postbox a:not(.button){color:var(--ab-ct-link)!important}';
            $out .= '#dashboard-widgets .postbox a:not(.button):hover{color:var(--ab-ct-link-hvr)!important}';
            // Dashboard submit buttons
            $out .= '#dashboard-widgets .postbox .button-primary,#dashboard-widgets #publish{background:var(--ab-ct-btn-primary)!important;border-color:var(--ab-ct-btn-primary)!important;color:var(--ab-ct-btn-pri-txt)!important}';
            $out .= '#dashboard-widgets .postbox .button-primary:hover{background:var(--ab-ct-btn-pri-hvr)!important;border-color:var(--ab-ct-btn-pri-hvr)!important}';
            // Dashboard secondary buttons
            $out .= '#dashboard-widgets .postbox .button:not(.button-primary){background:var(--ab-ct-btn-sec)!important;color:var(--ab-ct-text)!important;border-color:var(--ab-ct-input-bdr)!important}';
            // Dashboard description text
            $out .= '#dashboard-widgets .postbox .inside{color:var(--ab-ct-pbox-txt)!important}';
            // Dashboard "Drag boxes here" placeholder text
            $out .= '#dashboard-widgets .empty-container:after{color:var(--ab-ct-pbox-txt)!important}';

            // Tag cloud links in dark mode
            $out .= '#wpcontent .tagcloud a,#wpcontent .popular-tags a{color:var(--ab-ct-link)!important}';

            // -- Admin Buddy UI - self-derived colour scheme --------------
            // AB's own settings UI derives its colours from the primary colour
            // and the detected light/dark context. This isolates AB from whatever
            // the user sets in the Content tab for WP's pages.
            $admbud_bg_hex   = $body_bg_raw ? $this->colour( 'admbud_colours_body_bg', '#f0f0f1' ) : '#f0f0f1';
            $admbud_is_dark  = self::luminance( $admbud_bg_hex ) < 0.4;

            if ( $admbud_is_dark ) {
                $admbud_surface     = $this->darken( $primary, 0.82 );
                $admbud_raised      = $this->darken( $primary, 0.75 );
                $admbud_sunken      = $this->darken( $primary, 0.88 );
                $admbud_border      = $this->darken( $primary, 0.60 );
                $admbud_border_sub  = $this->darken( $primary, 0.70 );
                $admbud_text        = '#e0e0ec';
                $admbud_text_muted  = '#9898a8';
                $admbud_heading     = '#f0f0f8';
                $admbud_input_bg    = $this->darken( $primary, 0.85 );
                $admbud_input_bdr   = $this->darken( $primary, 0.55 );
            } else {
                $admbud_surface     = $this->lighten( $primary, 0.97 );
                $admbud_raised      = '#ffffff';
                $admbud_sunken      = $this->lighten( $primary, 0.91 );
                $admbud_border      = $this->lighten( $primary, 0.82 );
                $admbud_border_sub  = $this->lighten( $primary, 0.88 );
                $admbud_text        = '#3c434a';
                $admbud_text_muted  = '#646970';
                $admbud_heading     = '#1d2327';
                $admbud_input_bg    = '#ffffff';
                $admbud_input_bdr   = $this->lighten( $primary, 0.78 );
            }

            $as  = esc_attr($admbud_surface);
            $ar  = esc_attr($admbud_raised);
            $ask = esc_attr($admbud_sunken);
            $ab  = esc_attr($admbud_border);
            $abs2 = esc_attr($admbud_border_sub);
            $at  = esc_attr($admbud_text);
            $atm = esc_attr($admbud_text_muted);
            $ah  = esc_attr($admbud_heading);
            $aib = esc_attr($admbud_input_bg);
            $aid = esc_attr($admbud_input_bdr);
            $ap  = esc_attr($primary);
            $ap2 = esc_attr($secondary);

            $out .= ".ab-wrap{";
            $out .= "--ab-surface:{$as};--ab-surface-raised:{$ar};--ab-surface-sunken:{$ask};";
            $out .= "--ab-border:{$ab};--ab-border-subtle:{$abs2};";
            $out .= "--ab-text-heading:{$ah};--ab-text-strong:{$ah};--ab-text-body:{$at};--ab-text-secondary:{$at};--ab-text-muted:{$atm};--ab-text-placeholder:{$atm};";
            $out .= "--ab-accent:{$ap};--ab-accent-hover:{$ap2};";
            $out .= "--ab-input-bg:{$aib};--ab-input-border:{$aid};";
            $chevron_color = $admbud_is_dark ? '%23a0a0b0' : '%236b7280';
            $out .= "--ab-select-chevron:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='{$chevron_color}' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E\");";
            $out .= "--ab-success-bg:{$ar};--ab-warning-bg:{$ar};--ab-danger-bg:{$ar};--ab-info-bg:{$ar};";
            $out .= "}";

            // AB chrome
            $out .= ".ab-wrap .ab-topbar{background:{$as};border-color:{$ab}}";
            $out .= ".ab-wrap .ab-nav{background:{$as};border-color:{$ab}}";
            $out .= ".ab-wrap .ab-frame{background:{$ask}}";
            // AB cards and sections (same surface as nav)
            $out .= ".ab-wrap .ab-section,.ab-wrap .ab-card{background:{$as};border-color:{$ab}}";
            $out .= ".ab-wrap .ab-section__header{border-color:{$abs2}}";
            $out .= ".ab-wrap h1,.ab-wrap h2,.ab-wrap h3,.ab-wrap h4,.ab-wrap h5{color:{$ah}!important}";
            $out .= ".ab-wrap .form-table th,.ab-wrap .form-table td,.ab-wrap label{color:{$at}}";
            // AB inputs
            $out .= ".ab-wrap input[type=\"text\"],.ab-wrap input[type=\"url\"],.ab-wrap input[type=\"number\"],.ab-wrap input[type=\"email\"],.ab-wrap input[type=\"password\"],.ab-wrap textarea,.ab-wrap select{background-color:{$aib}!important;border-color:{$aid}!important;color:{$at}!important}";
            // AB buttons
            $out .= ".ab-wrap .ab-btn--primary{background:{$ap};color:#fff;border-color:{$ap}}";
            $out .= ".ab-wrap .ab-btn--primary:hover{background:{$ap2};border-color:{$ap2}}";
            $out .= ".ab-wrap .ab-btn--secondary{background:{$ask};color:{$at};border-color:{$aid}}";
            // AB notices
            $out .= ".ab-wrap .ab-notice{background:{$ar};border-color:{$ab};color:{$at}}";
            // AB toggle tracks
            $out .= ".ab-wrap .ab-toggle__track{background:{$aid}}";
            // AB subnav
            $out .= ".ab-wrap .ab-subnav{border-color:{$ab}}";
            $out .= ".ab-wrap .ab-subnav__item{color:{$at};background:transparent}";
            $out .= ".ab-wrap .ab-subnav__item:hover{background:{$ask};color:{$ah}}";
            $out .= ".ab-wrap .ab-subnav__item.is-active,.ab-wrap .ab-subnav__item[aria-selected=\"true\"]{background:{$ar};color:{$ah};box-shadow:0 1px 3px rgba(0,0,0,0.25)}";
            // AB colour picker hex text
            $out .= ".ab-wrap .ab-color-hex{color:{$at}!important}";
            // AB description text
            $out .= ".ab-wrap .description,.ab-wrap p.description{color:{$atm}!important}";
            // AB export grid and preset cards
            $out .= ".ab-wrap .ab-export-group__header{color:{$ah}}";
            $out .= ".ab-wrap .ab-preset-card{background:{$as};border-color:{$ab}}";
            // AB modal
            $out .= "#ab-confirm-modal .ab-modal__box{background:{$ar};color:{$at};border-color:{$ab}}";
            $out .= "#ab-confirm-modal .ab-modal__title{color:{$ah}!important}";
            $out .= "#ab-confirm-modal .ab-modal__header{border-color:{$ab}}";
            $out .= "#ab-confirm-modal .ab-modal__footer{border-color:{$ab}}";
            // AB toast
            $out .= ".ab-toast{background:{$ar};color:{$at};border-color:{$ab}}";
            // AB palette mode options
            $out .= ".ab-palette-mode-option{border-color:{$ab}!important;color:{$at}}";
            $out .= ".ab-palette-mode-option strong{color:{$ah}}";

            // -- AB tab-specific components --
            $s  = $as;     // surface (same as nav/sections)
            $sr = $ask;    // sunken surface (frame bg)
            $b  = $ab;     // border
            $bs = $abs2;   // subtle border
            $t  = $at;     // text
            $h  = $ah;     // heading
            $ib = $aib;    // input bg
            $id = $aid;    // input border

            // Snippets tab
            $out .= ".ab-wrap .ab-snippet-item,.ab-wrap .ab-snippets-empty{background:{$s}!important;border-color:{$b}!important;color:{$t}}";
            $out .= ".ab-wrap .ab-snippet-filter-bar{border-color:{$b}!important;background:{$sr}}";
            $out .= ".ab-wrap .ab-snippet-filter-tab{color:{$t}}";
            // Snippet editor panel — content-area theming only.
            // Panel chrome (outer bg, header, body, footer) is handled by the
            // canonical .ab-slide-panel* rules below. After the 2026 panel
            // consolidation the .ab-snippet-modal class no longer exists on
            // any DOM element — the panel carries #ab-snippet-modal + the
            // canonical .ab-slide-panel class. Ancestor selectors that used
            // .ab-snippet-modal were updated to #ab-snippet-modal.
            $out .= ".ab-snippet-modal__label{color:{$h}!important}";
            $out .= "#ab-snippet-modal .ab-snippet-modal__fields-area{background:{$s}}";
            $out .= "#ab-snippet-modal .ab-snippet-modal__code-area{background:{$s};border-color:{$b}}";
            $out .= ".ab-snippet-editor-wrap,#ab-snippet-modal .CodeMirror{background:{$ib}!important;color:{$t}!important;border-color:{$id}!important}";
            $out .= "#ab-snippet-modal .CodeMirror-gutters{background:{$sr}!important;border-color:{$id}!important}";
            $out .= "#ab-snippet-modal .CodeMirror-linenumber{color:{$t}!important}";
            $out .= ".ab-snippet-modal__meta-row{border-color:{$b}}";

            // Custom pages tab
            $out .= ".ab-wrap .ab-cp-item,.ab-wrap .ab-cp-empty{background:{$s}!important;border-color:{$b}!important;color:{$t}}";
            $out .= ".ab-wrap .ab-cp-list{border-color:{$b}}";
            // (CP edit panel chrome handled by canonical .ab-slide-panel rules below.)

            // Slide panels (shared)
            $out .= ".ab-slide-panel{background:{$s}!important;border-color:{$b}}";
            $out .= ".ab-slide-panel__header{background:{$sr}!important;border-color:{$b}!important}";
            $out .= ".ab-slide-panel__title{color:{$h}!important}";
            $out .= ".ab-slide-panel__body{background:{$s};color:{$t}}";
            $out .= ".ab-slide-panel__footer{background:{$sr}!important;border-color:{$b}!important}";
            $out .= ".ab-slide-panel__close{color:{$t}}";

            // User roles tab
            $out .= ".ab-wrap .ab-roles-toolbar{background:{$s}!important;border-color:{$b}!important}";
            $out .= ".ab-wrap .ab-cap-group{background:{$s}!important;border-color:{$b}!important}";
            $out .= ".ab-wrap .ab-cap-group__header{background:{$sr}!important;border-color:{$b}!important;color:{$h}}";
            $out .= ".ab-wrap .ab-cap-item{color:{$t}}";
            $out .= ".ab-wrap .ab-roles-toolbar select,.ab-wrap .ab-roles-toolbar input{background-color:{$ib}!important;border-color:{$id}!important;color:{$t}!important}";

            // Menu customiser tab
            $out .= ".ab-wrap .ab-menu-table{border-color:{$b}}";
            $out .= ".ab-wrap .ab-menu-table thead th,.ab-wrap .ab-menu-table tfoot th{background:{$sr}!important;border-color:{$b}!important;color:{$h}}";
            $out .= ".ab-wrap .ab-menu-row td{background:{$s};border-color:{$bs};color:{$t}}";
            $out .= ".ab-wrap .ab-menu-row:hover td{background:{$sr}!important}";
            $out .= ".ab-wrap .ab-menu-row .ab-dd__trigger{background:{$ib};border-color:{$id};color:{$t}}";
            $out .= ".ab-wrap .ab-menu-empty{border-color:{$b};color:{$t}}";

            // SVG icon library tab
            $out .= ".ab-wrap .ab-svglib-upload-area{background:{$sr}!important;border-color:{$id}!important;color:{$t}}";
            $out .= ".ab-wrap .ab-svglib-grid-item{background:{$s}!important;border-color:{$b}!important}";
            $out .= ".ab-wrap .ab-svglib-grid-item:hover{background:{$sr}!important}";
            $out .= ".ab-wrap .ab-svglib-grid-item svg{color:{$t}}";

            // (SMTP email preview panel chrome handled by canonical .ab-slide-panel rules below.)

            // Icon picker modal (custom pages + menu)
            $out .= ".ab-icon-modal .ab-modal__box,.ab-cp-icon-modal .ab-modal__box{background:{$s}!important;color:{$t}}";
            $out .= ".ab-icon-modal .ab-modal__header,.ab-cp-icon-modal .ab-modal__header{border-color:{$b}!important}";
            $out .= ".ab-icon-modal .ab-icon-modal-tab,.ab-cp-icon-modal .ab-icon-modal-tab{color:{$t}}";
            $out .= ".ab-icon-modal .ab-icon-svg-option,.ab-cp-icon-modal .ab-icon-svg-option{background:{$sr};border-color:{$b};color:{$t}}";
            $out .= ".ab-icon-modal .ab-icon-svg-option:hover,.ab-cp-icon-modal .ab-icon-svg-option:hover{background:var(--ab-surface-sunken)}";

            // Dropdown component
            $out .= ".ab-wrap .ab-dd__menu{background:{$s}!important;border-color:{$b}!important}";
            $out .= ".ab-wrap .ab-dd__item{color:{$t}}";
            $out .= ".ab-wrap .ab-dd__item:hover{background:{$sr}}";

            // color-mix rules in tab CSS that mix with #fff - override with surface
            $out .= ".ab-wrap .ab-cap-group__header:hover{background:var(--ab-surface-sunken)!important}";

            // (Custom Pages + Email panel chrome handled by canonical .ab-slide-panel* rules.)

            // Snippet row items
            $out .= ".ab-wrap .ab-snippet-row{background:{$s}!important;border-color:{$b}!important;color:{$t}}";

            // Roles - cap group head (different class name from header)
            $out .= ".ab-wrap .ab-cap-group__head{background:{$sr}!important;border-color:{$b}!important;color:{$h}}";

            // Menu customiser toolbar
            $out .= ".ab-wrap .ab-toolbar{background:{$s}!important;border-color:{$b}!important;color:{$t}}";

            // CodeMirror active line (white bg in editor). Scoped by ID —
            // .ab-snippet-modal class was dropped in the panel consolidation.
            $out .= "#ab-snippet-modal .CodeMirror-activeline-background{background:var(--ab-surface-sunken)!important}";
            $out .= "#ab-snippet-modal .CodeMirror-cursor{border-color:{$t}!important}";
            $out .= "#ab-snippet-modal .CodeMirror-selected{background:var(--ab-surface-sunken)!important}";

            // CodeMirror hint dropdown + find dialog. These render at body
            // root (outside .ab-wrap), so the rules are unscoped on purpose
            // and use the per-scheme literals so they match in dark mode.
            $out .= ".CodeMirror-hints{background:{$ar}!important;color:{$at}!important;border-color:{$ab}!important}";
            $out .= "li.CodeMirror-hint-active{background:{$ap}!important;color:#fff!important}";
            $out .= ".CodeMirror-dialog{background:{$ar}!important;color:{$at}!important;border-color:{$ab}!important}";
            $out .= ".CodeMirror-dialog input{background:{$aib}!important;color:{$at}!important;border-color:{$aid}!important}";

            // WP upload areas (plugin upload, media upload)
            $out .= "#wpcontent .upload-plugin-wrap .upload-plugin,.upload-plugin{background:{$sr}!important;border-color:{$b}}";
            $out .= "#wpcontent .upload-plugin .wp-upload-form,.upload-theme .wp-upload-form{background:{$s}!important;border-color:{$b}!important;color:{$t}}";
            $out .= "#wpcontent .media-upload-form .upload-ui,.uploader-inline .upload-ui{color:{$t}}";
            $out .= "#wpcontent .upload-dropzone,.uploader-inline{background:{$sr}!important;border-color:{$id}!important}";
            // Choose File / file input
            $out .= '#wpcontent input[type="file"]{color:' . $t . '}';

            // (Former .ab-cp-panel__header/__footer/__title rules removed —
            //  the Custom Pages panel now uses canonical .ab-slide-panel__*
            //  classes which are themed by the shared rules above.)

            // WP .card component (Tools page, Categories converter, etc)
            $out .= "#wpcontent .card{background:{$s}!important;border-color:{$b}!important;color:{$t}}";
            $out .= "#wpcontent .card h2,#wpcontent .card h3{color:{$h}!important}";

            // Button focus ring - use accent, remove native outline
            $out .= "#wpcontent .button:focus,#wpcontent .button-secondary:focus,#wpcontent .button-primary:focus{box-shadow:0 0 0 1px {$ap}!important;outline:none!important;outline-offset:0!important}";
            // Select Files button on media upload
            $out .= "#wpcontent .browser.button{background:{$s}!important;border-color:{$id}!important;color:{$t}!important}";
            $out .= "#wpcontent .browser.button:hover{background:{$sr}!important}";

            // Theme actions overlay (Activate button bar)
            $out .= '.theme-browser .theme .theme-actions,.theme-browser .theme.active .theme-actions{background:' . $sr . '!important;border-color:' . $b . '!important}';
            $out .= '.theme-browser .theme .theme-actions .button{background:' . $s . '!important;color:' . $t . '!important;border-color:' . $id . '!important}';
            $out .= '.theme-browser .theme .theme-actions .button:hover{background:' . $sr . '!important}';
            $out .= '.theme-browser .theme .theme-actions .button-primary{background:{$ap}!important;color:#fff!important;border-color:{$ap}!important}';

            // Update count bubble - clean up box-shadow bleed
            // Update count - just remove box-shadow bleed, let WP handle shape/colour
            $out .= '#adminmenu .update-plugins .plugin-count,#adminmenu .update-plugins .update-count,.update-plugins .update-count{box-shadow:none!important}';

            $out .= '</style>';
            if ( ! $excluded ) {
                echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
        }

        // -- WP settings nav-tab compat ----------------------------------------
        // nav-tab-active/hover: only target WP's own settings pages.
        // Gated by $excluded - these target #wpcontent .wrap content area.
        if ( ! $excluded ) {
            echo '<style id="ab-navtab-compat">';
            echo '#wpcontent .wrap > .nav-tab-wrapper .nav-tab-active,#wpcontent .wrap > h2.nav-tab-wrapper + .nav-tab-wrapper .nav-tab-active,#wpcontent .wrap .nav-tab-wrapper .nav-tab-active{background:var(--ab-menu-bg)!important;border-bottom-color:var(--ab-menu-bg)!important;color:var(--ab-menu-text)!important;}';
            echo '#wpcontent .wrap .nav-tab-wrapper .nav-tab:hover{color:var(--ab-primary)!important;}';
            echo '</style>';
        }
    }

    // ============================================================================
    // CSS INJECTION - LOGIN PAGE
    // ============================================================================

    /**
     * Apply the admin primary colour to the login page - buttons, links, focus rings.
     * Always applied when Colours module is enabled. Login button/text colour
     * pickers were removed in v1.8.0 (global colours are the single source of truth).
     */
    // ============================================================================
    // LOGIN CSS INJECTION
    // ============================================================================

    public function inject_login_css(): void {
        $primary   = $this->colour( 'admbud_colours_primary',   self::DEFAULT_PRIMARY );
        $secondary = $this->colour( 'admbud_colours_secondary', self::DEFAULT_SECONDARY );

        $p1 = esc_attr( $primary );
        $p2 = esc_attr( $secondary );

        $css = 'body.login #wp-submit,body.login .button-primary{background:' . $p1 . '!important;border-color:' . $p1 . '!important;color:#fff!important;box-shadow:none!important}'
             . 'body.login #wp-submit:hover,body.login .button-primary:hover{background:' . $p2 . '!important;border-color:' . $p2 . '!important}'
             . 'body.login a{color:' . $p1 . '!important}'
             . 'body.login a:hover{color:' . $p2 . '!important}'
             . 'body.login input[type="text"]:focus,body.login input[type="password"]:focus,body.login input[type="email"]:focus{border-color:' . $p1 . '!important;box-shadow:0 0 0 1px ' . $p1 . '!important}'
             . 'body.login .wp-hide-pw:focus{border-color:' . $p1 . '!important;box-shadow:0 0 0 1px ' . $p1 . '!important;color:' . $p1 . '!important}'
             . 'body.login .wp-hide-pw:hover{color:' . $p1 . '!important}'
             . 'body.login .dashicons-visibility:before,body.login .dashicons-hidden:before{color:' . $p1 . '!important}'
             . 'body.login input[type="checkbox"]:focus{border-color:' . $p1 . '!important;box-shadow:0 0 0 1px ' . $p1 . '!important}'
             . 'body.login input[type="checkbox"],body.login input[type="radio"]{accent-color:' . $p1 . '!important}'
             . 'body.login #login_error,body.login .message{border-left-color:' . $p1 . '!important}';

        // login_enqueue_scripts always has 'login' style registered.
        wp_add_inline_style( 'login', $css );
    }

    // ============================================================================
    // CSS INJECTION - FRONT END (admin bar for logged-in visitors)
    // ============================================================================

    /**
     * Inject admin bar colours on the front end.
     * Reuses the same options as the backend inject_css() so colours are
     * always in sync - no separate frontend-only colour calculation.
     */
    public function inject_frontend_css(): void {
        if ( ! is_admin_bar_showing() ) {
            return;
        }

        // Resolve all the same values as inject_css() so frontend = backend.
        $primary       = $this->colour( 'admbud_colours_primary',   self::DEFAULT_PRIMARY   );
        $secondary     = $this->colour( 'admbud_colours_secondary', self::DEFAULT_SECONDARY );
        $menu_text     = $this->colour( 'admbud_colours_menu_text', self::DEFAULT_MENU_TEXT );
        $menu_bg       = $this->colour( 'admbud_colours_menu_bg',   self::DEFAULT_MENU_BG   );
        $menu_bg_dark  = $this->darken( $menu_bg, 0.12 );
        $menu_text_dim = $this->dim_colour( $menu_text, 0.75 );

        $adminbar_text_raw           = admbud_get_option( 'admbud_colours_adminbar_text', '' );
        $adminbar_bg_raw             = admbud_get_option( 'admbud_colours_adminbar_bg', '' );
        $adminbar_hover_raw          = admbud_get_option( 'admbud_colours_adminbar_hover_bg', '' );
        $adminbar_sub_raw            = admbud_get_option( 'admbud_colours_adminbar_submenu_bg', '' );
        $adminbar_hover_text_raw     = admbud_get_option( 'admbud_colours_adminbar_hover_text', '' );
        $adminbar_sub_text_raw       = admbud_get_option( 'admbud_colours_adminbar_sub_text', '' );
        $adminbar_sub_hover_text_raw = admbud_get_option( 'admbud_colours_adminbar_sub_hover_text', '' );
        $adminbar_sub_hover_bg_raw   = admbud_get_option( 'admbud_colours_adminbar_sub_hover_bg', '' );
        $shadow_colour_raw           = admbud_get_option( 'admbud_colours_shadow_colour', '' );

        $p8  = $adminbar_text_raw ? esc_attr( $this->colour( 'admbud_colours_adminbar_text', $menu_text_dim ) ) : esc_attr( $menu_text_dim );
        $p17 = $adminbar_hover_raw ? esc_attr( $this->colour( 'admbud_colours_adminbar_hover_bg', $primary ) ) : esc_attr( $primary );
        $p18 = $adminbar_sub_raw ? esc_attr( $this->colour( 'admbud_colours_adminbar_submenu_bg', $menu_bg_dark ) ) : esc_attr( $menu_bg_dark );
        $p19 = $adminbar_bg_raw ? esc_attr( $this->colour( 'admbud_colours_adminbar_bg', $menu_bg_dark ) ) : esc_attr( $menu_bg_dark );
        $p20 = $adminbar_hover_text_raw ? esc_attr( $this->colour( 'admbud_colours_adminbar_hover_text', $menu_text ) ) : esc_attr( $menu_text );
        $p21 = $adminbar_sub_text_raw ? esc_attr( $this->colour( 'admbud_colours_adminbar_sub_text', $menu_text_dim ) ) : esc_attr( $menu_text_dim );
        $p22 = $adminbar_sub_hover_text_raw ? esc_attr( $this->colour( 'admbud_colours_adminbar_sub_hover_text', $menu_text ) ) : esc_attr( $menu_text );
        $p26 = $adminbar_sub_hover_bg_raw ? esc_attr( $this->colour( 'admbud_colours_adminbar_sub_hover_bg', $primary ) ) : esc_attr( $primary );

        // Build box-shadow string (mirrors inject_css logic).
        if ( $shadow_colour_raw ) {
            $p27_hex = esc_attr( $this->colour( 'admbud_colours_shadow_colour', '#000000' ) );
            if ( strpos( $p27_hex, 'rgb' ) === 0 ) {
                $p28 = "0 4px 12px {$p27_hex}";
            } elseif ( preg_match( '/^#?([0-9a-f]{6})$/i', $p27_hex, $m ) ) {
                $r   = hexdec( substr( $m[1], 0, 2 ) );
                $g   = hexdec( substr( $m[1], 2, 2 ) );
                $b   = hexdec( substr( $m[1], 4, 2 ) );
                $p28 = "0 4px 12px rgba({$r},{$g},{$b},0.4)";
            } else {
                $p28 = '0 4px 12px rgba(0,0,0,0.35)';
            }
        } else {
            $p28 = '0 4px 12px rgba(0,0,0,0.35)';
        }

        $css = 'html body #wpadminbar{background:' . esc_attr( $p19 ) . '!important}'
             . 'html body #wpadminbar .ab-item,'
             . 'html body #wpadminbar a.ab-item,'
             . 'html body #wpadminbar .ab-label,'
             . 'html body #wpadminbar .ab-icon:before,'
             . 'html body #wpadminbar #adminbarsearch:before,'
             . 'html body #wpadminbar .ab-item:before{color:' . esc_attr( $p8 ) . '!important}'
             . 'html body #wpadminbar .ab-top-menu>li:hover>a,'
             . 'html body #wpadminbar .ab-top-menu>li.hover>a,'
             . 'html body #wpadminbar .ab-top-menu>li>a:focus{background:' . esc_attr( $p17 ) . '!important;color:' . esc_attr( $p20 ) . '!important}'
             . 'html body #wpadminbar .ab-top-menu>li:hover>a>.ab-icon:before,'
             . 'html body #wpadminbar .ab-top-menu>li.hover>a>.ab-icon:before,'
             . 'html body #wpadminbar .ab-top-menu>li>a:focus>.ab-icon:before,'
             . 'html body #wpadminbar .ab-top-menu>li:hover>a>.ab-label,'
             . 'html body #wpadminbar .ab-top-menu>li.hover>a>.ab-label{color:' . esc_attr( $p20 ) . '!important}'
             . 'html body #wpadminbar .menupop .ab-sub-wrapper,'
             . 'html body #wpadminbar .ab-top-menu>li>.ab-sub-wrapper,'
             . 'html body #wpadminbar .quicklinks .menupop ul{background:' . esc_attr( $p18 ) . '!important;border:none!important;box-shadow:' . esc_attr( $p28 ) . '!important}'
             . 'html body #wpadminbar .ab-submenu .ab-item,'
             . 'html body #wpadminbar .ab-submenu a,'
             . 'html body #wpadminbar .quicklinks .menupop ul li a{color:' . esc_attr( $p21 ) . '!important;background:transparent!important}'
             . 'html body #wpadminbar .ab-submenu>li>a:hover,'
             . 'html body #wpadminbar .ab-submenu>li:hover>a,'
             . 'html body #wpadminbar .quicklinks .menupop ul li:hover>a,'
             . 'html body #wpadminbar .quicklinks .menupop ul li a:hover{background:' . esc_attr( $p26 ) . '!important;color:' . esc_attr( $p22 ) . '!important}'
             . 'html body #wpadminbar .ab-submenu li,'
             . 'html body #wpadminbar .ab-submenu li a,'
             . 'html body #wpadminbar .quicklinks .menupop ul li{box-shadow:none!important}';

        wp_add_inline_style( 'admin-bar', $css );
    }



    /**
     * Fetch + validate a colour option, returning the default if invalid.
     */
    /**
     * Mix two hex colours at a given ratio (0=all a, 1=all b).
     * Used to auto-derive alternating row colour from row bg + body bg.
     */
    private function lighten_mix( string $a, string $b, float $ratio ): string {
        $a = preg_replace( '/[^A-Fa-f0-9]/', '', $a );
        $b = preg_replace( '/[^A-Fa-f0-9]/', '', $b );
        if ( strlen($a) < 6 ) { $a = '000000'; }
        if ( strlen($b) < 6 ) { $b = '000000'; }
        $r = round( hexdec(substr($a,0,2)) * (1-$ratio) + hexdec(substr($b,0,2)) * $ratio );
        $g = round( hexdec(substr($a,2,2)) * (1-$ratio) + hexdec(substr($b,2,2)) * $ratio );
        $b_ = round( hexdec(substr($a,4,2)) * (1-$ratio) + hexdec(substr($b,4,2)) * $ratio );
        return sprintf( '#%02x%02x%02x', $r, $g, $b_ );
    }

    public function colour( string $option, string $default ): string {
        $val = get_option( $option, $default );
        if ( ! $val ) { return $default; }
        $val = trim( (string) $val );
        // Accept hex, rgba(), hsla() - reject everything else.
        if ( preg_match( '/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $val ) ) { return $val; }
        if ( preg_match( '/^rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(?:,\s*[\d.]+\s*)?\)$/i', $val ) ) { return $val; }
        if ( preg_match( '/^hsla?\(\s*[\d.]+\s*,\s*[\d.]+%?\s*,\s*[\d.]+%?\s*(?:,\s*[\d.]+\s*)?\)$/i', $val ) ) { return $val; }
        return $default;
    }

    /**
     * Convert #rrggbb to "r, g, b" triplet for CSS custom property.
     */
    private function hex_to_rgb_triplet( string $hex ): string {
        $hex = preg_replace( '/[^A-Fa-f0-9]/', '', $hex );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if ( strlen( $hex ) < 6 ) { $hex = '000000'; }
        return sprintf(
            '%d, %d, %d',
            hexdec( substr( $hex, 0, 2 ) ),
            hexdec( substr( $hex, 2, 2 ) ),
            hexdec( substr( $hex, 4, 2 ) )
        );
    }

    /**
     * Convert a hex colour to an rgba() string with the given alpha (0.0–1.0).
     */
    private function hex_to_rgba( string $hex, float $alpha ): string {
        $hex = preg_replace( '/[^A-Fa-f0-9]/', '', $hex );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if ( strlen( $hex ) < 6 ) { $hex = '000000'; }
        return sprintf(
            'rgba(%d,%d,%d,%.2f)',
            hexdec( substr( $hex, 0, 2 ) ),
            hexdec( substr( $hex, 2, 2 ) ),
            hexdec( substr( $hex, 4, 2 ) ),
            $alpha
        );
    }

    /**
     * Lighten a hex colour by blending it toward white.
     *
     * @param string $hex   Source colour.
     * @param float  $ratio 0.0–1.0 how much white to blend in.
     */
    private function lighten( string $hex, float $ratio ): string {
        return $this->blend( $hex, '#ffffff', $ratio );
    }

    /**
     * Darken a hex colour by blending it toward black.
     */
    private function darken( string $hex, float $ratio ): string {
        return $this->blend( $hex, '#000000', $ratio );
    }

    /**
     * Blend two hex colours together at the given ratio (0=all $a, 1=all $b).
     */
    private function blend( string $a, string $b, float $ratio ): string {
        $a = preg_replace( '/[^A-Fa-f0-9]/', '', $a );
        $b = preg_replace( '/[^A-Fa-f0-9]/', '', $b );
        if ( strlen( $a ) === 3 ) { $a = $a[0].$a[0].$a[1].$a[1].$a[2].$a[2]; }
        if ( strlen( $b ) === 3 ) { $b = $b[0].$b[0].$b[1].$b[1].$b[2].$b[2]; }
        if ( strlen( $a ) < 6 ) { $a = '000000'; }
        if ( strlen( $b ) < 6 ) { $b = '000000'; }

        $r = (int) round( hexdec( substr( $a, 0, 2 ) ) * ( 1 - $ratio ) + hexdec( substr( $b, 0, 2 ) ) * $ratio );
        $g = (int) round( hexdec( substr( $a, 2, 2 ) ) * ( 1 - $ratio ) + hexdec( substr( $b, 2, 2 ) ) * $ratio );
        $bl= (int) round( hexdec( substr( $a, 4, 2 ) ) * ( 1 - $ratio ) + hexdec( substr( $b, 4, 2 ) ) * $ratio );

        return sprintf( '#%02x%02x%02x', $r, $g, $bl );
    }

    /**
     * Reduce opacity of a colour by blending toward mid-grey (approximates dim).
     * Used for submenu text which should be slightly muted vs active menu text.
     */
    private function dim_colour( string $hex, float $opacity ): string {
        return $this->blend( $hex, '#888888', 1 - $opacity );
    }

    /**
     * Compute WCAG relative luminance for a hex colour.
     * Returns 0.0 (black) to 1.0 (white).
     *
     * @param string $hex
     * @return float
     */
    public static function luminance( string $hex ): float {
        $hex = preg_replace( '/[^A-Fa-f0-9]/', '', $hex );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if ( strlen( $hex ) < 6 ) { $hex = '000000'; }

        $channels = [
            hexdec( substr( $hex, 0, 2 ) ) / 255,
            hexdec( substr( $hex, 2, 2 ) ) / 255,
            hexdec( substr( $hex, 4, 2 ) ) / 255,
        ];

        $linear = array_map( function( $c ) {
            return $c <= 0.03928
                ? $c / 12.92
                : pow( ( $c + 0.055 ) / 1.055, 2.4 );
        }, $channels );

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }

    /**
     * Return '#ffffff' or '#000000' - whichever has higher contrast ratio
     * against the given background colour. Used for JS auto-suggest hint.
     *
     * @param string $bg_hex Background colour.
     * @return string
     */
    public static function suggest_text_colour( string $bg_hex ): string {
        $lum = self::luminance( $bg_hex );
        // WCAG contrast ratio: (L1 + 0.05) / (L2 + 0.05)
        // White lum=1, black lum=0.
        $white_ratio = ( 1.05 ) / ( $lum + 0.05 );
        $black_ratio = ( $lum + 0.05 ) / ( 0.05 );
        return $white_ratio >= $black_ratio ? '#ffffff' : '#000000';
    }

    /**
     * Compute contrast ratio between two hex colours.
     * Returns value like 4.5 (WCAG AA threshold).
     */
    public static function contrast_ratio( string $hex1, string $hex2 ): float {
        $l1 = self::luminance( $hex1 );
        $l2 = self::luminance( $hex2 );
        $lighter = max( $l1, $l2 );
        $darker  = min( $l1, $l2 );
        return round( ( $lighter + 0.05 ) / ( $darker + 0.05 ), 2 );
    }


}

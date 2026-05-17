<?php
/**
 * Core - white-label tweaks + admin bar status indicators.
 *
 * Handles:
 *  - Removing the WP logo from the admin bar
 *  - Custom admin footer text (or hiding it entirely)
 *  - Hiding the Help tab and Screen Options button
 *  - Stripping the WordPress generator meta / RSS tag
 *  - Admin bar pill: maintenance/coming-soon mode indicator
 *  - Admin bar pill: "search engines discouraged" warning
 *
 * Update-nag hiding and auto-update controls live in class-notices-updates.php
 * (Pro-only module) so the free build does not ship the update transient
 * filters that trigger PluginCheck warnings.
 *
 * @package Admbud
 */

namespace Admbud;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Core {

    private static ?Core $instance = null;

    public static function get_instance(): Core {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->boot();
    }

    // -- Boot ------------------------------------------------------------------

    public function boot(): void {
        if ( $this->is_enabled( 'admbud_core_remove_logo', '0' ) ) {
            add_action( 'wp_before_admin_bar_render', [ $this, 'remove_wp_logo' ] );
        }

        // Sidebar logo - inject CSS to show the custom logo at the top of #adminmenu.
        $sidebar_logo = admbud_get_option( 'admbud_wl_sidebar_logo_url', '' );
        if ( $sidebar_logo ) {
            add_action( 'admin_enqueue_scripts', [ $this, 'inject_sidebar_logo_css' ] );
        }

        // -- Agency Name -------------------------------------------------------
        // Replaces "WordPress" in admin page <title> tags.
        $agency_name = admbud_get_option( 'admbud_wl_agency_name', '' );
        if ( $agency_name !== '' ) {
            add_filter( 'admin_title', function ( $admin_title ) use ( $agency_name ) {
                return str_replace( 'WordPress', esc_html( $agency_name ), $admin_title );
            }, 99 );
        }

        // -- Footer ------------------------------------------------------------
        // Right side: footer version text + optional quote.
        // Only active when custom footer toggle is ON.
        // Toggle OFF = WP defaults show on both sides.
        $footer_enabled = $this->is_enabled( 'admbud_core_custom_footer_enabled', '0' );
        $footer_version = admbud_get_option( 'admbud_wl_footer_version', '' );
        $show_quote     = $this->is_enabled( 'admbud_wl_footer_quote', '0' );

        if ( $footer_enabled ) {
            // Right side: build from quote + custom version text.
            // Both blank = show nothing (empty string).
            add_filter( 'update_footer', function () use ( $footer_version, $show_quote ) {
                $parts = [];

                // Quote comes first - before version text.
                if ( $show_quote ) {
                    $quote = \Admbud\Core::get_random_quote();
                    if ( $quote ) {
                        $parts[] = '<em>' . esc_html( $quote['quote'] ) . '</em> &mdash; ' . esc_html( $quote['author'] );
                    }
                }

                // Custom version text if set.
                if ( $footer_version !== '' ) {
                    $parts[] = esc_html( $footer_version );
                }

                return implode( ' &nbsp;|&nbsp; ', $parts );
            }, 99 );
        } elseif ( $show_quote ) {
            // Quote toggle can work independently even when custom footer is off.
            add_filter( 'update_footer', function () use ( $footer_version ) {
                $quote = \Admbud\Core::get_random_quote();
                if ( ! $quote ) { return ''; }
                return '<em>' . esc_html( $quote['quote'] ) . '</em> &mdash; ' . esc_html( $quote['author'] );
            }, 99 );
        }
        // If admbud_qs_remove_version is on, hide the default WP version footer.
        // Skip if Admin Buddy already replaced it with a quote or custom footer above.
        if ( get_option( 'admbud_qs_remove_version', '0' ) === '1' && ! $show_quote && ! $footer_version ) {
            add_filter( 'update_footer', '__return_empty_string', 100 );
        }

        // Left side: custom footer text, or agency-branded fallback, or WP default.
        if ( $this->is_enabled( 'admbud_core_custom_footer_enabled', '0' ) ) {
            add_filter( 'admin_footer_text', [ $this, 'custom_footer_text' ], 99 );
        } elseif ( $agency_name !== '' ) {
            $agency_url = admbud_get_option( 'admbud_wl_agency_url', '' );
            add_filter( 'admin_footer_text', function () use ( $agency_name, $agency_url ) {
                if ( $agency_url ) {
                    return sprintf( 'Powered by <a href="%s" target="_blank">%s</a>', esc_url( $agency_url ), esc_html( $agency_name ) );
                }
                return sprintf( 'Powered by %s', esc_html( $agency_name ) );
            }, 99 );
        }
        // If neither custom footer nor agency name is set, WP's default footer text shows.

        // -- Admin bar tweaks --------------------------------------------------
        // Custom "Howdy" greeting.
        $greeting = admbud_get_option( 'admbud_wl_greeting', '' );
        if ( $greeting !== '' ) {
            add_action( 'admin_bar_menu', function ( \WP_Admin_Bar $bar ) use ( $greeting ) {
                $user    = wp_get_current_user();
                $account = $bar->get_node( 'my-account' );
                if ( ! $account ) { return; }
                $name      = $user->display_name;
                $text      = str_replace( '{username}', $name, $greeting );
                $avatar    = get_avatar( $user->ID, 26 );
                $new_title = esc_html( $text ) . ', ' . esc_html( $name ) . $avatar;
                $bar->add_node( [
                    'id'    => 'my-account',
                    'title' => $new_title,
                ] );
            }, 9999 );
        }

        // Remove wordpress.org links from admin bar.
        if ( $this->is_enabled( 'admbud_wl_remove_wp_links', '0' ) ) {
            add_action( 'wp_before_admin_bar_render', [ $this, 'remove_wp_links' ] );
        }

        // Agency URL - only change the top-level WP logo link, not its children.
        $agency_url = admbud_get_option( 'admbud_wl_agency_url', '' );
        if ( $agency_url !== '' ) {
            add_action( 'admin_bar_menu', function ( \WP_Admin_Bar $bar ) use ( $agency_url ) {
                $bar->add_node( [
                    'id'   => 'wp-logo',
                    'href' => esc_url( $agency_url ),
                ] );
            }, 25 );
        }

        // -- Help Tab ----------------------------------------------------------
        if ( $this->is_enabled( 'admbud_core_remove_help', '0' ) ) {
            add_action( 'admin_head', function () {
                $screen = get_current_screen();
                if ( $screen ) {
                    $screen->remove_help_tabs();
                }
            } );
            add_action( 'admin_enqueue_scripts', function () {
                wp_add_inline_style( 'admbud-icon-inject', '#contextual-help-link-wrap{display:none!important}' );
            } );
        }

        // -- Screen Options ---------------------------------------------------
        if ( $this->is_enabled( 'admbud_core_remove_screen_options', '0' ) ) {
            add_action( 'admin_enqueue_scripts', function () {
                wp_add_inline_style( 'admbud-icon-inject', '#screen-options-link-wrap{display:none!important}' );
            } );
        }

        // Fix WP's blue filter on SVG menu icons - load on every admin page.
        add_action( 'admin_enqueue_scripts', function () {
            wp_add_inline_style(
                'admbud-icon-inject',
                '#adminmenu #toplevel_page_admin-buddy .wp-menu-image:before{content:"";}'
            );
        } );

        // Override WP media modal blue accent with AB theme colour - sitewide.
        // !important needed: media-views.min.css loads after our inline styles.
        add_action( 'admin_enqueue_scripts', function () {
            wp_add_inline_style(
                'admbud-icon-inject',
                '.media-menu .media-menu-item{color:var(--ab-accent,#7c3aed)!important;}'
                . '.media-menu .media-menu-item:hover{color:var(--ab-accent-hover,#6d28d9)!important;}'
                . '.media-menu .media-menu-item:focus{color:var(--ab-accent,#7c3aed)!important;box-shadow:0 0 0 2px var(--ab-accent,#7c3aed)!important;}'
                . '.media-menu .active{color:var(--ab-accent,#7c3aed)!important;font-weight:600;}'
                . '.media-button-select:enabled,.media-button-insert:enabled{background:var(--ab-accent,#7c3aed)!important;border-color:var(--ab-accent,#7c3aed)!important;}'
                . '.media-button-select:enabled:hover,.media-button-insert:enabled:hover{background:var(--ab-accent-hover,#6d28d9)!important;border-color:var(--ab-accent-hover,#6d28d9)!important;}'
                . '.media-frame select.attachment-filters{min-width:160px!important;max-width:none!important;width:auto!important;}'
                . '.attachments-browser .media-toolbar-secondary{max-width:none!important;}'
            );
        } );

        // TinyMCE editor.min.css loads late. Hang our overrides off the
        // editor-buttons handle so they print after the editor stylesheet.
        add_action( 'admin_enqueue_scripts', function () {
            if ( ! wp_style_is( 'editor-buttons', 'registered' ) ) { return; }
            wp_add_inline_style(
                'editor-buttons',
                '.mce-toolbar .mce-btn-group .mce-btn.mce-listbox:focus,'
                . '.mce-toolbar .mce-btn-group .mce-btn.mce-listbox:hover'
                . '{box-shadow:0 0 0 2px var(--ab-accent,#7c3aed)!important;}'
                . '.mce-toolbar .mce-btn-group .mce-btn:focus,'
                . '.mce-toolbar .mce-btn-group .mce-btn:hover'
                . '{box-shadow:0 0 0 2px var(--ab-accent,#7c3aed)!important;}'
                . '.wp-switch-editor:focus'
                . '{box-shadow:0 0 0 2px var(--ab-accent,#7c3aed)!important;}'
                . '::selection{background:var(--ab-accent,#7c3aed)!important;color:#fff!important;}'
                . '::-moz-selection{background:var(--ab-accent,#7c3aed)!important;color:#fff!important;}'
            );
        } );

        // -- Dashboard cleanup -------------------------------------------------
        if ( $this->is_enabled( 'admbud_wl_hide_wp_news', '0' ) ) {
            add_action( 'wp_dashboard_setup', function () {
                remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );
            } );
        }

        // -- Gutenberg controls (deferred to Phase 2) -------------------------
        // Gutenberg features require careful per-WP-version handling.
        // Stubs retained so options save correctly; hooks added in a future beta.

        // -- Notices & Updates -------------------------------------------------
        // Update-nag hiding and auto-update controls live in the Notices & Updates
        // Pro module (class-notices-updates.php) so the free build does not ship
        // the site_transient_update_* filters that trigger PluginCheck warnings.

        // -- Status pills (always-on for admins) ------------------------------
        // Wrapped in init - calling current_user_can() during plugins_loaded
        // forces early user authentication which fires set_current_user.
        // Third-party plugins (e.g. SureCart) hook set_current_user and call
        // __() before their textdomain is loaded, triggering a WP 6.7 JIT
        // translation notice. Deferring to init ensures user setup has already
        // completed safely before we check capabilities.
        add_action( 'init', function () {
            if ( current_user_can( 'manage_options' ) ) {
                // Note: the Maintenance / Coming Soon / Noindex pill nodes are
                // added by class-adminbar.php at admin_bar_menu:999 — this class
                // used to add them at priority 100 but the IDs collided, so the
                // AdminBar version overwrote the Core version. Removed in the
                // 2026 cleanup. enqueue_pill_styles stays here because it injects
                // colour-customisation CSS (keyed off the Colours tab), which
                // class-adminbar.php's inline_css() doesn't handle.
                add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_pill_styles'  ] );
                add_action( 'wp_enqueue_scripts',    [ $this, 'enqueue_pill_styles'  ] );
            }
        }, 1 );
    }

    /**
     * Inject admin bar pill styles.
     * Hooked on both admin_enqueue_scripts and wp_enqueue_scripts so the
     * pills look identical on the frontend admin bar and the backend.
     */
    public function enqueue_pill_styles(): void {
        // Resolve colour constants here (user override → constant fallback).
        // Read-side: sanitize_hex_field() validates the option value.
        // Output-side: each value is re-validated through sanitize_hex_color()
        // immediately before CSS interpolation to enforce "escape late".
        $coming_soon       = sanitize_hex_field( admbud_get_option( 'admbud_colours_pill_coming_soon', '' ) ) ?: \Admbud\Colours::COLOR_COMING_SOON;
        $coming_soon_hover = \Admbud\Colours::COLOR_COMING_SOON_HOVER;
        $maintenance       = sanitize_hex_field( admbud_get_option( 'admbud_colours_pill_maintenance', '' ) ) ?: \Admbud\Colours::COLOR_MAINTENANCE;
        $maintenance_hover = \Admbud\Colours::COLOR_MAINTENANCE_HOVER;
        $noindex           = sanitize_hex_field( admbud_get_option( 'admbud_colours_pill_noindex', '' ) )     ?: \Admbud\Colours::COLOR_NOINDEX;
        $noindex_hover     = \Admbud\Colours::COLOR_NOINDEX_HOVER;

        // Escape-late: re-run user-overrideable values through sanitize_hex_color().
        $coming_soon = sanitize_hex_color( $coming_soon ) ?: \Admbud\Colours::COLOR_COMING_SOON;
        $maintenance = sanitize_hex_color( $maintenance ) ?: \Admbud\Colours::COLOR_MAINTENANCE;
        $noindex     = sanitize_hex_color( $noindex )     ?: \Admbud\Colours::COLOR_NOINDEX;

        $css = "
            /* Admin Buddy - status pills */
            #wpadminbar .ab-bar-pill > .ab-item {
                border-radius: 4px !important;
                margin: 6px 2px !important;
                padding: 0 10px !important;
                font-size: 0.75rem !important;
                font-weight: 700 !important;
                line-height: 20px !important;
                height: 20px !important;
                letter-spacing: 0.03em !important;
                display: inline-flex !important;
                align-items: center !important;
                text-decoration: none !important;
            }
            #wpadminbar .ab-bar-pill--coming-soon > .ab-item {
                background: {$coming_soon} !important;
                color: #fff !important;
            }
            #wpadminbar .ab-bar-pill--coming-soon > .ab-item:hover {
                background: {$coming_soon_hover} !important;
            }
            #wpadminbar .ab-bar-pill--maintenance > .ab-item {
                background: {$maintenance} !important;
                color: #fff !important;
            }
            #wpadminbar .ab-bar-pill--maintenance > .ab-item:hover {
                background: {$maintenance_hover} !important;
            }
            #wpadminbar .ab-bar-pill--noindex > .ab-item {
                background: {$noindex} !important;
                color: #fff !important;
            }
            #wpadminbar .ab-bar-pill--noindex > .ab-item:hover {
                background: {$noindex_hover} !important;
            }
        ";

        wp_add_inline_style( 'admin-bar', $css );
    }

    // -- Callbacks -------------------------------------------------------------

    public function custom_footer_text(): string {
        return wp_kses_post( admbud_get_option( 'admbud_core_custom_footer_text', '' ) );
    }

    /**
     * Returns a random quote from quotes.json.
     * Returns null if file is missing or unreadable.
     */
    public static function get_random_quote(): ?array {
        static $quotes = null;
        if ( $quotes === null ) {
            $file = ADMBUD_DIR . 'includes/quotes.json';
            if ( ! file_exists( $file ) ) { return null; }
            $json   = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
            $quotes = json_decode( $json, true );
            if ( ! is_array( $quotes ) || empty( $quotes ) ) {
                $quotes = [];
                return null;
            }
        }
        if ( empty( $quotes ) ) { return null; }
        return $quotes[ array_rand( $quotes ) ];
    }

    public function remove_wp_logo(): void {
        global $wp_admin_bar;
        $wp_admin_bar->remove_menu( 'wp-logo' );
    }

    /**
     * Remove WordPress.org links from the admin bar WP logo dropdown.
     */
    public function remove_wp_links(): void {
        global $wp_admin_bar;
        $wp_admin_bar->remove_menu( 'about' );
        $wp_admin_bar->remove_menu( 'wporg' );
        $wp_admin_bar->remove_menu( 'documentation' );
        $wp_admin_bar->remove_menu( 'support-forums' );
        $wp_admin_bar->remove_menu( 'feedback' );
    }

    public function inject_sidebar_logo_css(): void {
        $logo_url = admbud_get_option( 'admbud_wl_sidebar_logo_url', '' );
        if ( ! $logo_url ) {
            return;
        }
        $logo_url = esc_url_raw( $logo_url );
        $width    = absint( admbud_get_option( 'admbud_wl_sidebar_logo_width',  84 ) );
        $height   = absint( admbud_get_option( 'admbud_wl_sidebar_logo_height',  0 ) );

        // Cap at sidebar width - WP default sidebar is 160px.
        $w_val  = min( $width, 160 );
        $h_rule = $height > 0 ? 'height:' . (int) $height . 'px;' : 'height:' . (int) $w_val . 'px;';

        $css = '#adminmenu::before{'
             . 'content:"";display:block;'
             . 'width:' . (int) $w_val . 'px;'
             . 'max-width:calc(100% - 16px);'
             . $h_rule
             . 'margin:12px 8px 6px;'
             . 'background-image:url("' . esc_url( $logo_url ) . '");'
             . 'background-repeat:no-repeat;'
             . 'background-size:contain;'
             . 'background-position:left center;'
             . '}'
             . '.folded #adminmenu::before{display:none;}';

        wp_add_inline_style( 'admbud-icon-inject', $css );
    }

    // -- Helper ----------------------------------------------------------------

    private function is_enabled( string $option_name, string $default = '0' ): bool {
        return get_option( $option_name, $default ) === '1';
    }
}

<?php
/**
 * Dashboard - replaces the WP dashboard with a custom page in a full-screen iframe.
 *
 * Handles:
 *  - Registering and rendering the full-screen iframe widget
 *  - Removing all other dashboard widgets (preserving ours + user-kept ones)
 *  - Recording the full widget catalogue to a transient for the settings UI
 *  - Rendering user-defined custom dashboard widgets
 *  - Injecting CSS/JS to strip widget chrome and position iframe edge-to-edge
 *  - Suppressing the admin bar on pages loaded inside the iframe (?admbud_iframe=1)
 *  - Persisting the ?admbud_iframe=1 flag on all same-origin links via JS
 *
 * @package Admbud
 */

namespace Admbud;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Dashboard {

    /** Query parameter used to signal an iframe context. */
    const IFRAME_PARAM = 'admbud_iframe';

    /** Transient key where we snapshot the full widget catalogue. */
    const CATALOGUE_TRANSIENT = 'admbud_dashboard_widget_catalogue';

    // -- Singleton -------------------------------------------------------------

    private static ?Dashboard $instance = null;

    public static function get_instance(): Dashboard {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Admin-side hooks.
        add_action( 'admin_init',            [ $this, 'maybe_redirect_dashboard'], 999 );
        add_action( 'admin_menu',            [ $this, 'register_iframe_page'    ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'hide_submenu_item'       ] );
        add_action( 'wp_dashboard_setup',    [ $this, 'register_custom_widgets' ], 5   );
        add_action( 'wp_dashboard_setup',    [ $this, 'setup_widget'            ], 10  );
        add_action( 'wp_dashboard_setup',    [ $this, 'snapshot_catalogue'      ], 900 );
        add_action( 'wp_dashboard_setup',    [ $this, 'remove_other_widgets'    ], 999 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets'          ]      );

        // Highlight "Dashboard" in the sidebar when on the iframe page - not "Settings".
        // Must be hooked in constructor (early), not inside admin_menu callback.
        add_filter( 'parent_file',  [ $this, 'fix_active_menu_parent'  ] );
        add_filter( 'submenu_file', [ $this, 'fix_active_menu_submenu' ] );

        // Front-end hooks (iframe context).
        add_action( 'after_setup_theme',  [ $this, 'maybe_hide_admin_bar'      ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_iframe_link_script'] );
    }

    // -- Helpers ---------------------------------------------------------------

    /**
     * Get the role→page mapping from the DB.
     */
    public static function get_role_pages(): array {
        $raw = admbud_get_option( 'admbud_dashboard_role_pages', '{}' );
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * Resolve the dashboard page ID for the current user.
     * Checks role overrides first, then falls back to _default.
     */
    private function page_id(): int {
        $map = self::get_role_pages();
        if ( empty( $map ) ) { return 0; }

        $user = wp_get_current_user();
        if ( ! $user || ! $user->exists() ) { return 0; }

        // Only check role overrides if _per_role is enabled.
        $per_role = ! empty( $map['_per_role'] );
        if ( $per_role ) {
            foreach ( $user->roles as $role ) {
                if ( isset( $map[ $role ] ) ) {
                    if ( $map[ $role ] === 'wp_default' ) { return 0; }
                    return (int) $map[ $role ];
                }
            }
        }

        // Fall back to _default.
        if ( isset( $map['_default'] ) ) {
            if ( $map['_default'] === 'wp_default' ) { return 0; }
            return (int) $map['_default'];
        }

        return 0;
    }

    private function is_iframe_request(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return isset( $_GET[ self::IFRAME_PARAM ] ) && $_GET[ self::IFRAME_PARAM ] === '1'; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
    }

    /**
     * Returns the list of widget IDs the user wants to keep visible.
     * Empty array means "keep all" (no filtering beyond our own widget logic).
     */
    public static function get_keep_ids(): array {
        $raw = admbud_get_option( 'admbud_dashboard_keep_widgets', '' );
        // Empty string = never configured (keep all). Return null sentinel.
        if ( $raw === '' ) { return []; }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? array_map( 'sanitize_key', $decoded ) : [];
    }

    /**
     * Whether the widget keep list has been explicitly configured.
     * Empty string = never configured. Anything else (including '[]') = configured.
     */
    public static function is_keep_list_configured(): bool {
        return admbud_get_option( 'admbud_dashboard_keep_widgets', '' ) !== '';
    }

    /**
     * Returns the user-defined custom widgets as an array of ['title','content'] maps.
     */
    public static function get_custom_widgets(): array {
        $raw     = admbud_get_option( 'admbud_dashboard_custom_widgets', '[]' );
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    // -- Widget catalogue snapshot ---------------------------------------------

    /**
     * After all plugins have registered their widgets (priority 900),
     * save the catalogue to a transient so the settings page can list them.
     * We do this BEFORE the removal pass (priority 999).
     */
    public function snapshot_catalogue(): void {
        global $wp_meta_boxes;

        $boxes = $wp_meta_boxes['dashboard'] ?? [];
        if ( ! is_array( $boxes ) ) { return; }

        $catalogue = [];
        foreach ( $boxes as $context => $priorities ) {
            foreach ( $priorities as $priority => $widgets ) {
                foreach ( $widgets as $id => $widget ) {
                    if ( ! $widget || ! isset( $widget['title'] ) ) { continue; }
                    // Skip our own widgets - we don't let users toggle those off.
                    if ( strpos( $id, 'admbud_' ) === 0 ) { continue; }
                    $catalogue[ $id ] = wp_strip_all_tags( $widget['title'] ?: $id );
                }
            }
        }

        // Store indefinitely - invalidated whenever remove_other_widgets runs
        // (i.e. on every dashboard load, which is when widgets re-register).
        set_transient( self::CATALOGUE_TRANSIENT, $catalogue, DAY_IN_SECONDS );
    }

    /**
     * Returns the last-known widget catalogue from the transient.
     * Falls back to an empty array if no dashboard page has been loaded yet.
     */
    public static function get_catalogue(): array {
        $cat = get_transient( self::CATALOGUE_TRANSIENT );
        return is_array( $cat ) ? $cat : [];
    }

    // -- Custom user-defined widgets -------------------------------------------

    /**
     * Register each user-defined custom widget. Priority 5 so they appear
     * before WP's own widgets and our iframe widget.
     */
    public function register_custom_widgets(): void {
        $widgets = self::get_custom_widgets();
        foreach ( $widgets as $idx => $w ) {
            $title   = sanitize_text_field( $w['title']   ?? '' );
            $content = wp_kses_post(        $w['content'] ?? '' );
            if ( ! $title && ! $content ) { continue; }
            $id = 'admbud_custom_widget_' . $idx;
            wp_add_dashboard_widget(
                $id,
                $title ?: __( '(Untitled Widget)', 'admin-buddy' ),
                static function() use ( $content ) {
                    echo wp_kses_post( do_shortcode( $content ) );
                }
            );
        }
    }

    // -- Iframe widget ---------------------------------------------------------

    public function setup_widget(): void {
        $page_id = $this->page_id();
        if ( $page_id < 1 ) { return; }

        wp_add_dashboard_widget(
            'admbud_custom_dashboard_page',
            esc_html( (string) get_the_title( $page_id ) ),
            [ $this, 'render_widget' ]
        );
    }

    // -- Removal pass ---------------------------------------------------------

    /**
     * Remove dashboard widgets not in the keep list.
     * Always preserves our own (admbud_*) widgets.
     * If keep list is empty, removes everything except admbud_* widgets.
     */
    public function remove_other_widgets(): void {
        global $wp_meta_boxes;

        if ( ! is_array( $wp_meta_boxes['dashboard'] ?? null ) ) { return; }

        // If the keep list was never configured, don't filter anything.
        if ( ! self::is_keep_list_configured() ) { return; }

        $keep_ids = self::get_keep_ids();

        $filtered = [];
        foreach ( $wp_meta_boxes['dashboard'] as $context => $priorities ) {
            foreach ( $priorities as $priority => $widgets ) {
                foreach ( $widgets as $id => $widget ) {
                    // Always keep our own widgets.
                    if ( strpos( $id, 'admbud_' ) === 0 ) {
                        if ( $id === 'admbud_custom_dashboard_page' && $this->page_id() < 1 ) {
                            continue;
                        }
                        $filtered[ $context ][ $priority ][ $id ] = $widget;
                        continue;
                    }
                    // Keep third-party widgets that are in the keep list.
                    if ( in_array( $id, $keep_ids, true ) ) {
                        $filtered[ $context ][ $priority ][ $id ] = $widget;
                    }
                }
            }
        }

        $wp_meta_boxes['dashboard'] = $filtered;
    }

    // -- Bare iframe admin page -----------------------------------------------

    /**
     * Register a hidden admin page that renders just the iframe.
     * Parent: index.php (Dashboard) - keeps Dashboard menu active when page is visited.
     * The submenu item is hidden via CSS in hide_submenu_item, NOT via remove_submenu_page
     * (which would unregister the capability check and cause "not allowed" errors).
     */
    /**
     * Hide the ab-dashboard-iframe submenu item from Settings menu.
     * Fires in admin_head - before body renders, so no flash.
     * Uses both possible href formats WP might generate.
     */
    public function hide_submenu_item(): void {
        if ( $this->page_id() < 1 ) { return; }
        wp_add_inline_style(
            'admbud-icon-inject',
            '#adminmenu .wp-submenu li a[href="admin.php?page=ab-dashboard-iframe"],'
            . '#adminmenu .wp-submenu li a[href="index.php?page=ab-dashboard-iframe"],'
            . '#adminmenu .wp-submenu li a[href="options-general.php?page=ab-dashboard-iframe"]{display:none!important;}'
            . '#adminmenu .wp-submenu li:has(a[href="admin.php?page=ab-dashboard-iframe"]),'
            . '#adminmenu .wp-submenu li:has(a[href="index.php?page=ab-dashboard-iframe"]),'
            . '#adminmenu .wp-submenu li:has(a[href="options-general.php?page=ab-dashboard-iframe"]){display:none!important;}'
        );
    }

    public function register_iframe_page(): void {
        if ( $this->page_id() < 1 ) { return; }
        add_submenu_page(
            'index.php',
            __( 'Admin Buddy Dashboard', 'admin-buddy' ),
            __( 'Admin Buddy Dashboard', 'admin-buddy' ),
            'read',  // Lowest capability - all logged-in users. Page just renders an iframe.
            'ab-dashboard-iframe',
            [ $this, 'render_iframe_page' ]
        );
    }

    /** Force "Dashboard" to be the active parent menu item on our iframe page. */
    public function fix_active_menu_parent( string $parent_file ): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ( $_GET['page'] ?? '' ) === 'ab-dashboard-iframe' ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
            return 'index.php';
        }
        return $parent_file;
    }

    /** Force no submenu item to be highlighted (Dashboard has no submenu). */
    public function fix_active_menu_submenu( ?string $submenu_file ): ?string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ( $_GET['page'] ?? '' ) === 'ab-dashboard-iframe' ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
            return null;
        }
        return $submenu_file;
    }

    /**
     * Redirect /wp-admin/ to our bare iframe page before any output.
     * Fires on load-index.php - guaranteed pre-output.
     */
    public function maybe_redirect_dashboard(): void {
        // Only fire when visiting the dashboard (index.php).
        if ( ( $GLOBALS['pagenow'] ?? '' ) !== 'index.php' ) { return; }

        // If a custom page is configured for this user - redirect there.
        if ( $this->page_id() > 0 ) {
            wp_safe_redirect( admin_url( 'admin.php?page=ab-dashboard-iframe' ) );
            exit;
        }

        // If no custom page but dashboard is hidden via Menu Customiser - redirect to first visible.
        if ( class_exists( '\\Admbud\\MenuCustomiser' ) ) {
            $mc          = \Admbud\MenuCustomiser::get_instance();
            $user        = wp_get_current_user();
            $roles       = (array) ( $user->roles ?? [] );
            $role        = $roles[0] ?? '';
            $sidebar_cfg = $mc->get_role_sidebar_config( $role );
            $items       = $sidebar_cfg['items'] ?? [];
            if ( ! empty( $items['index.php']['hidden'] ) ) {
                // Delegate to MenuCustomiser's get_first_visible_url via its block_hidden flow.
                // Build cap map from live $menu.
                global $menu;
                $cap_map = [];
                foreach ( (array) $menu as $item ) {
                    if ( ! empty( $item[2] ) ) { $cap_map[ $item[2] ] = $item[1] ?? 'read'; }
                }
                $order = array_keys( $items );
                usort( $order, function( $a, $b ) use ( $items ) {
                    return ( $items[ $a ]['order'] ?? 99 ) <=> ( $items[ $b ]['order'] ?? 99 );
                } );
                global $_registered_pages;
                foreach ( $order as $slug ) {
                    if ( ! empty( $items[ $slug ]['hidden'] ) ) { continue; }
                    if ( ! empty( $items[ $slug ]['separator'] ) ) { continue; }
                    if ( str_starts_with( $slug, 'separator' ) || str_starts_with( $slug, 'wp-separator' ) ) { continue; }
                    if ( $slug === 'index.php' ) { continue; }
                    // Must appear in $menu for this user - WP only adds items the user can access.
                    if ( ! isset( $cap_map[ $slug ] ) ) { continue; }
                    // Capability check.
                    $cap = $cap_map[ $slug ];
                    if ( ! current_user_can( $cap ) ) { continue; }
                    // For page= style items, verify truly registered in $_registered_pages.
                    // This catches plugins (e.g. Bricks) with secondary access checks.
                    if ( ! str_ends_with( $slug, '.php' ) ) {
                        $hook = get_plugin_page_hookname( $slug, 'admin.php' );
                        if ( empty( $_registered_pages[ $hook ] ) ) { continue; }
                    }
                    $url = str_ends_with( $slug, '.php' ) ? admin_url( $slug ) : admin_url( 'admin.php?page=' . $slug );
                    wp_safe_redirect( $url );
                    exit;
                }
                wp_safe_redirect( admin_url( 'profile.php' ) );
                exit;
            }
        }
    }

    /** Render the bare iframe page - admin chrome stays, widget pipeline never runs. */
    public function render_iframe_page(): void {
        $page_id = $this->page_id();
        if ( $page_id < 1 ) { wp_safe_redirect( admin_url() ); exit; }

        $permalink = get_permalink( $page_id );
        if ( ! $permalink ) { wp_safe_redirect( admin_url() ); exit; }

        $src   = esc_url( add_query_arg( self::IFRAME_PARAM, '1', (string) $permalink ) );
        $title = esc_attr( (string) get_the_title( $page_id ) );

        $iframe_css = '#wpcontent{padding-left:0!important;}'
            . '#wpbody{padding-top:0!important;}'
            . '#wpbody-content{padding-bottom:0!important;}'
            . '#wpbody-content > .wrap > h1,'
            . '#wpbody-content > .wrap > .notice,'
            . '#wpbody-content > .wrap > .updated,'
            . '#wpbody-content > .wrap > .error,'
            . '#wpfooter{display:none!important;}'
            . '#ab-dashboard-iframe-wrap{display:block;width:100%;height:calc(100vh - 32px);margin:0;padding:0;}'
            . '.no-js #ab-dashboard-iframe-wrap,body:not(.wp-toolbar) #ab-dashboard-iframe-wrap{height:100vh;}'
            . '#ab-dashboard-iframe-wrap iframe{display:block;width:100%;height:100%;border:none;}';

        $iframe_js = '(function(){function setHeight(){var wrap=document.getElementById("ab-dashboard-iframe-wrap");var bar=document.getElementById("wpadminbar");if(!wrap){return;}wrap.style.height="calc(100vh - "+(bar?bar.offsetHeight:32)+"px)";}document.addEventListener("DOMContentLoaded",setHeight);}());';

        wp_add_inline_style(  'admbud-icon-inject', $iframe_css );
        wp_add_inline_script( 'admbud-icon-inject', $iframe_js );
        ?>
        <div id="ab-dashboard-iframe-wrap">
            <iframe src="<?php echo esc_url( $src ); ?>" title="<?php echo esc_attr( $title ); ?>" allowfullscreen></iframe>
        </div>
        <?php
    }

    // -- Render helpers --------------------------------------------------------

    public function render_widget(): void {
        $page_id = $this->page_id();
        if ( $page_id < 1 ) { return; }

        $permalink = get_permalink( $page_id );
        if ( ! $permalink ) {
            echo '<p>' . esc_html__( 'Page not found. Please check Admin Buddy → Dashboard settings.', 'admin-buddy' ) . '</p>';
            return;
        }

        $src = add_query_arg( self::IFRAME_PARAM, '1', (string) $permalink );
        printf(
            '<iframe src="%s" style="width:100%%;height:100vh;border:none;display:block;" loading="lazy" title="%s"></iframe>',
            esc_url( $src ),
            esc_attr( (string) get_the_title( $page_id ) )
        );
    }

    // -- Admin assets ---------------------------------------------------------

    public function enqueue_assets( string $hook ): void {
        if ( $hook !== 'index.php' || $this->page_id() < 1 ) { return; }

        wp_register_style( 'ab-dashboard', false, [], ADMBUD_VERSION );
        wp_enqueue_style( 'ab-dashboard' );
        wp_add_inline_style( 'ab-dashboard', '
            .wrap > h1,
            #wpfooter,
            #welcome-panel,
            #dashboard-widgets h2.screen-reader-text { display: none !important; }

            html, body, #wpwrap, #wpcontent,
            #wpbody, #wpbody-content { overflow: hidden !important; }

            #wpcontent { padding: 0 !important; }
            #wpbody, #wpbody-content { padding: 0 !important; margin: 0 !important; }
            #wpbody-content .wrap { margin: 0 !important; padding: 0 !important; max-width: none !important; }
            #dashboard-widgets-wrap,
            #dashboard-widgets,
            #dashboard-widgets .postbox-container,
            #admbud_custom_dashboard_page,
            #admbud_custom_dashboard_page .inside {
                margin: 0 !important; padding: 0 !important;
                border: none !important; box-shadow: none !important;
                background: transparent !important; width: 100% !important;
            }

            #admbud_custom_dashboard_page .postbox-header { display: none !important; }

            #admbud_custom_dashboard_page iframe {
                display: block !important;
                position: fixed !important;
                top: 32px !important;
                left: 160px !important;
                width: calc(100vw - 160px) !important;
                height: calc(100vh - 32px) !important;
                border: none !important;
                margin: 0 !important;
                padding: 0 !important;
                z-index: 1 !important;
            }

            .folded #admbud_custom_dashboard_page iframe {
                left: 36px !important;
                width: calc(100vw - 36px) !important;
            }
        ' );

        wp_register_script( 'ab-dashboard', false, [], ADMBUD_VERSION, true );
        wp_enqueue_script( 'ab-dashboard' );
        wp_add_inline_script( 'ab-dashboard', '
            ( function () {
                "use strict";
                function fixIframe() {
                    var sidebar  = document.getElementById( "adminmenuwrap" );
                    var adminbar = document.getElementById( "wpadminbar" );
                    var iframe   = document.querySelector( "#admbud_custom_dashboard_page iframe" );
                    if ( ! iframe ) { return; }
                    var sidebarW = sidebar  ? sidebar.offsetWidth  : 160;
                    var barH     = adminbar ? adminbar.offsetHeight : 32;
                    iframe.style.left   = sidebarW + "px";
                    iframe.style.width  = "calc(100vw - " + sidebarW + "px)";
                    iframe.style.top    = barH + "px";
                    iframe.style.height = "calc(100vh - " + barH + "px)";
                }
                document.addEventListener( "DOMContentLoaded", function () {
                    fixIframe();
                    var collapseBtn = document.getElementById( "collapse-button" );
                    if ( collapseBtn ) {
                        collapseBtn.addEventListener( "click", function () {
                            setTimeout( fixIframe, 250 );
                        } );
                    }
                } );
            }() );
        ' );
    }

    // -- Front-end: suppress admin bar in iframe -------------------------------

    public function maybe_hide_admin_bar(): void {
        if ( $this->is_iframe_request() ) {
            add_filter( 'show_admin_bar', '__return_false' );
        }
    }

    // -- Front-end: persist iframe flag on all links ---------------------------

    public function enqueue_iframe_link_script(): void {
        if ( ! $this->is_iframe_request() ) { return; }

        wp_register_script( 'ab-iframe-links', false, [], ADMBUD_VERSION, true );
        wp_enqueue_script( 'ab-iframe-links' );
        wp_localize_script( 'ab-iframe-links', 'admbudIframe', [
            'param' => self::IFRAME_PARAM,
            'value' => '1',
        ] );

        wp_add_inline_script( 'ab-iframe-links', '
            ( function ( config ) {
                "use strict";
                var origin = window.location.origin;
                function addParam( href ) {
                    if ( ! href || /^(#|javascript:|mailto:|tel:|data:)/i.test( href ) ) { return href; }
                    var url;
                    try { url = new URL( href, window.location.href ); } catch ( e ) { return href; }
                    if ( url.origin !== origin ) { return href; }
                    if ( ! url.searchParams.has( config.param ) ) {
                        url.searchParams.set( config.param, config.value );
                    }
                    return url.toString();
                }
                function patchAnchor( a ) {
                    var original = a.getAttribute( "href" );
                    var patched  = addParam( original );
                    if ( patched && patched !== original ) { a.setAttribute( "href", patched ); }
                }
                function patchAll() { document.querySelectorAll( "a[href]" ).forEach( patchAnchor ); }
                document.addEventListener( "DOMContentLoaded", patchAll );
                document.addEventListener( "DOMContentLoaded", function () {
                    var observer = new MutationObserver( function ( mutations ) {
                        mutations.forEach( function ( mutation ) {
                            mutation.addedNodes.forEach( function ( node ) {
                                if ( node.nodeType !== 1 ) { return; }
                                if ( node.tagName === "A" && node.hasAttribute( "href" ) ) { patchAnchor( node ); }
                                node.querySelectorAll( "a[href]" ).forEach( patchAnchor );
                            } );
                        } );
                    } );
                    observer.observe( document.body, { childList: true, subtree: true } );
                } );
            }( window.admbudIframe || { param: "admbud_iframe", value: "1" } ) );
        ' );
    }
}

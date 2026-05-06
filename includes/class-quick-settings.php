<?php
/**
 * Quick Settings - applies single-toggle WordPress housekeeping options.
 *
 * Each setting is a simple boolean option. This class reads them on init
 * and applies the appropriate WordPress hooks. No UI logic here - that
 * lives in render-tab-quick-settings.php.
 *
 * @package Admbud
 */

namespace Admbud;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class QuickSettings {

    private static ?QuickSettings $instance = null;

    public static function get_instance(): QuickSettings {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', [ $this, 'apply' ], 1 );
    }

    public function apply(): void {

        // -- Performance -------------------------------------------------------

        if ( admbud_get_option( 'admbud_qs_disable_emoji', '0' ) === '1' ) {
            remove_action( 'wp_head',             'print_emoji_detection_script', 7 );
            remove_action( 'admin_print_scripts', 'print_emoji_detection_script'    );
            remove_action( 'wp_print_styles',     'print_emoji_styles'               );
            remove_action( 'admin_print_styles',  'print_emoji_styles'               );
            remove_filter( 'the_content_feed',    'wp_staticize_emoji'               );
            remove_filter( 'comment_text_rss',    'wp_staticize_emoji'               );
            remove_filter( 'wp_mail',             'wp_staticize_emoji_for_email'     );
            add_filter( 'tiny_mce_plugins', static function( $plugins ) {
                return array_diff( $plugins, [ 'wpemoji' ] );
            } );
        }

        if ( admbud_get_option( 'admbud_qs_disable_jquery_migrate', '0' ) === '1' ) {
            add_action( 'wp_default_scripts', static function( $scripts ) {
                if ( ! is_admin() && isset( $scripts->registered['jquery'] ) ) {
                    $script = $scripts->registered['jquery'];
                    if ( $script->deps ) {
                        $script->deps = array_diff( $script->deps, [ 'jquery-migrate' ] );
                    }
                }
            } );
        }

        if ( admbud_get_option( 'admbud_qs_remove_feed_links', '0' ) === '1' ) {
            remove_action( 'wp_head', 'feed_links',       2 );
            remove_action( 'wp_head', 'feed_links_extra', 3 );
        }

        if ( admbud_get_option( 'admbud_qs_remove_rsd', '0' ) === '1' ) {
            remove_action( 'wp_head', 'rsd_link' );
        }

        if ( admbud_get_option( 'admbud_qs_remove_wlw', '0' ) === '1' ) {
            remove_action( 'wp_head', 'wlwmanifest_link' );
        }

        if ( admbud_get_option( 'admbud_qs_remove_shortlink', '0' ) === '1' ) {
            remove_action( 'wp_head', 'wp_shortlink_wp_head' );
            remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
        }

        if ( admbud_get_option( 'admbud_qs_remove_restapi_link', '0' ) === '1' ) {
            remove_action( 'wp_head', 'rest_output_link_wp_head' );
            remove_action( 'template_redirect', 'rest_output_link_header', 11 );
        }

        if ( admbud_get_option( 'admbud_qs_disable_embeds', '0' ) === '1' ) {
            remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
            remove_action( 'wp_head', 'wp_oembed_add_host_js' );
            remove_filter( 'pre_oembed_result', 'wp_filter_pre_oembed_result', 10 );
            add_filter( 'embed_oembed_discover', '__return_false' );
            add_filter( 'tiny_mce_plugins', static function ( $plugins ) {
                return array_diff( $plugins, [ 'wpembed' ] );
            } );
            add_action( 'wp_footer', static function () {
                wp_deregister_script( 'wp-embed' );
            } );
        }

        // -- Security ----------------------------------------------------------

        if ( admbud_get_option( 'admbud_qs_disable_xmlrpc', '0' ) === '1' ) {
            add_filter( 'xmlrpc_enabled',        '__return_false' );
            add_filter( 'xmlrpc_methods',        '__return_empty_array' );
            add_filter( 'wp_xmlrpc_server_class','__return_false' );
        }

        if ( admbud_get_option( 'admbud_qs_disable_rest_api', '0' ) === '1' ) {
            add_filter( 'rest_authentication_errors', static function( $result ) {
                if ( ! empty( $result ) ) { return $result; }
                if ( ! is_user_logged_in() ) {
                    return new \WP_Error( 'rest_not_logged_in',
                        __( 'REST API requests require authentication.', 'admin-buddy' ),
                        [ 'status' => 401 ]
                    );
                }
                return $result;
            } );
        }


        if ( admbud_get_option( 'admbud_qs_remove_version', '0' ) === '1' ) {
            remove_action( 'wp_head', 'wp_generator' );
            add_filter( 'the_generator', '__return_empty_string' );
        }

        // -- Content -----------------------------------------------------------

        if ( admbud_get_option( 'admbud_qs_disable_feeds', '0' ) === '1' ) {
            add_action( 'do_feed',       [ $this, 'redirect_feed' ], 1 );
            add_action( 'do_feed_rdf',   [ $this, 'redirect_feed' ], 1 );
            add_action( 'do_feed_rss',   [ $this, 'redirect_feed' ], 1 );
            add_action( 'do_feed_rss2',  [ $this, 'redirect_feed' ], 1 );
            add_action( 'do_feed_atom',  [ $this, 'redirect_feed' ], 1 );
        }

        if ( admbud_get_option( 'admbud_qs_disable_self_ping', '0' ) === '1' ) {
            add_action( 'pre_ping', static function( &$links ) {
                $home = home_url();
                foreach ( $links as $l => $link ) {
                    if ( str_starts_with( $link, $home ) ) {
                        unset( $links[ $l ] );
                    }
                }
            } );
        }

        if ( admbud_get_option( 'admbud_qs_disable_comments_default', '0' ) === '1' ) {
            add_filter( 'wp_insert_post_data', static function( $data ) {
                if ( isset( $data['comment_status'] ) && $data['comment_status'] === 'open' ) {
                    // Only override for new posts (no ID yet).
                    if ( empty( $data['ID'] ) ) {
                        $data['comment_status'] = 'closed';
                    }
                }
                return $data;
            } );
        }

        // -- Admin -------------------------------------------------------------

        if ( admbud_get_option( 'admbud_qs_duplicate_post', '0' ) === '1' ) {
            // Hook into row actions for all public post types.
            add_filter( 'post_row_actions',  [ $this, 'add_duplicate_action' ], 10, 2 );
            add_filter( 'page_row_actions',  [ $this, 'add_duplicate_action' ], 10, 2 );
            add_action( 'admin_action_ab_duplicate_post', [ $this, 'handle_duplicate' ] );
        }

        if ( admbud_get_option( 'admbud_qs_user_last_seen', '0' ) === '1' ) {
            // Record timestamp on every login.
            add_action( 'wp_login', [ $this, 'record_last_seen' ], 10, 2 );
            // Users list table column.
            add_filter( 'manage_users_columns',               [ $this, 'add_last_seen_column'      ]        );
            add_filter( 'manage_users_custom_column',         [ $this, 'render_last_seen_column'   ], 10, 3 );
            add_filter( 'manage_users_sortable_columns',      [ $this, 'sortable_last_seen_column' ]        );
            add_action( 'pre_get_users',                      [ $this, 'sort_by_last_seen'         ]        );
        }

        // -- SVG uploads -------------------------------------------------------
        if ( admbud_get_option( 'admbud_qs_allow_svg', '0' ) === '1' ) {
            $allowed_roles = array_filter( explode( ',', admbud_get_option( 'admbud_qs_allow_svg_roles', admbud_get_option( 'admbud_qs_svg_roles', 'administrator' ) ) ) );

            // Allow SVG mime type - role-gated via the $user param.
            add_filter( 'upload_mimes', function ( $mimes, $user ) use ( $allowed_roles ) {
                $user_obj = $user ? ( is_object( $user ) ? $user : get_user_by( 'id', $user ) ) : wp_get_current_user();
                if ( ! $user_obj ) { return $mimes; }
                foreach ( (array) $user_obj->roles as $role ) {
                    if ( in_array( $role, $allowed_roles, true ) ) {
                        $mimes['svg']  = 'image/svg+xml';
                        $mimes['svgz'] = 'image/svg+xml';
                        return $mimes;
                    }
                }
                return $mimes;
            }, 10, 2 );

            // Sanitise SVG content on upload - reuses SvgLibrary::sanitise_svg().
            add_filter( 'wp_handle_upload_prefilter', [ $this, 'sanitise_svg_on_upload' ] );
        }

        // -- Admin Bar: Frontend ------------------------------------------------
        if ( admbud_get_option( 'admbud_qs_hide_adminbar_frontend', '0' ) === '1' ) {
            $fe_roles = array_filter( explode( ',', get_option( 'admbud_qs_hide_adminbar_frontend_roles', 'administrator' ) ) );
            $fe_hide  = false;
            if ( is_user_logged_in() ) {
                $user = wp_get_current_user();
                foreach ( (array) $user->roles as $role ) {
                    if ( in_array( $role, $fe_roles, true ) ) { $fe_hide = true; break; }
                }
            }
            add_filter( 'show_admin_bar', function ( $show ) use ( $fe_hide ) {
                return $fe_hide ? false : $show;
            } );
            // Show floating "back to admin" icon on frontend so user can return.
            // Skip inside the Bricks builder - its UI is served from a frontend
            // URL but we don't want our floating link overlapping Bricks' own controls.
            if ( $fe_hide && ! is_admin() && ! self::is_bricks_builder_request() ) {
                add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_floating_admin_link_style' ] );
                add_action( 'wp_footer',          [ $this, 'render_floating_admin_link'        ], 999 );
            }
        }

        // -- Admin Bar: hide specific Admin Buddy pills ------------------------
        // Pills are registered with known IDs by other modules:
        //   - ab-checklist-indicator: Checklist (class-checklist.php, priority 1000)
        //   - ab-noindex-indicator:   class-adminbar.php (priority 999)
        //
        // Our removal hook runs at 10000 so it's after every known add-hook but
        // before class-menu-customiser.php's 999999 capture-for-UI pass (so hidden
        // pills don't land in the Menu Customiser's node list either).
        //
        // The AB settings topbar pills (rendered by class-settings.php render())
        // are unaffected — those aren't admin bar nodes.
        $this->maybe_hide_adminbar_pill(
            'admbud_qs_hide_adminbar_checklist',
            'ab-checklist-indicator',
            10000
        );
        $this->maybe_hide_adminbar_pill(
            'admbud_qs_hide_adminbar_noindex',
            'ab-noindex-indicator',
            10000
        );

        // -- Admin Bar: Backend -------------------------------------------------
        if ( admbud_get_option( 'admbud_qs_hide_adminbar_backend', '0' ) === '1' ) {
            $be_roles = array_filter( explode( ',', get_option( 'admbud_qs_hide_adminbar_backend_roles', 'administrator' ) ) );
            $be_hide  = false;
            if ( is_user_logged_in() ) {
                $user = wp_get_current_user();
                foreach ( (array) $user->roles as $role ) {
                    if ( in_array( $role, $be_roles, true ) ) { $be_hide = true; break; }
                }
            }
            if ( $be_hide ) {
                add_action( 'admin_enqueue_scripts', function () {
                    // body.admin-bar offset cancellation: WP keeps the
                    // admin-bar body class when the bar is CSS-hidden, so the
                    // slide-panel would otherwise start 32px below a toolbar
                    // that no longer exists.
                    wp_add_inline_style(
                        'admbud-icon-inject',
                        '#wpadminbar{display:none!important}'
                        . 'html.wp-toolbar{padding-top:0!important;margin-top:0!important}'
                        . '#wpbody{padding-top:0!important}'
                        . '#adminmenuwrap{margin-top:0!important}'
                        . '#adminmenuback,#adminmenuwrap{top:0!important}'
                        . '.admin-bar .ab-topbar{top:0!important}'
                        . '.ab-frame{min-height:calc(100vh - var(--ab-topbar-height))!important}'
                        . 'body.admin-bar .ab-slide-panel,body.admin-bar .ab-backdrop{top:0;height:100%}'
                    );
                } );
            }
        }

        // -- Collapse Sidebar by Default ----------------------------------------
        if ( admbud_get_option( 'admbud_qs_collapse_menu', '0' ) === '1' ) {
            $cm_roles = array_filter( explode( ',', get_option( 'admbud_qs_collapse_menu_roles', 'administrator' ) ) );
            add_filter( 'admin_body_class', function ( $classes ) use ( $cm_roles ) {
                $user = wp_get_current_user();
                if ( ! $user->exists() ) { return $classes; }
                foreach ( (array) $user->roles as $role ) {
                    if ( in_array( $role, $cm_roles, true ) ) {
                        // Only add folded if user hasn't manually expanded (check user setting).
                        // WP stores mfold=o (open) or mfold=f (folded) in user settings.
                        // We force folded as default; user can still toggle.
                        if ( strpos( $classes, 'folded' ) === false ) {
                            $classes .= ' folded';
                        }
                        break;
                    }
                }
                return $classes;
            } );
        }

        // -- Sidebar User Menu --------------------------------------------------
        if ( admbud_get_option( 'admbud_qs_sidebar_user_menu', '0' ) === '1' ) {
            $um_roles = array_filter( explode( ',', get_option( 'admbud_qs_sidebar_user_menu_roles', 'administrator' ) ) );
            $um_show  = false;
            if ( is_user_logged_in() ) {
                $user = wp_get_current_user();
                foreach ( (array) $user->roles as $role ) {
                    if ( in_array( $role, $um_roles, true ) ) { $um_show = true; break; }
                }
            }
            if ( $um_show ) {
                add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_sidebar_user_menu_assets' ] );
                add_action( 'admin_footer',          [ $this, 'render_sidebar_user_menu'         ] );
            }
        }
    }

    /**
     * Inline CSS + JS for the sidebar user-menu widget. Registered on
     * admin_enqueue_scripts so the inline rules attach to the global
     * admbud-icon-inject handle before head/footer print. The HTML
     * itself is rendered later via render_sidebar_user_menu() on admin_footer.
     */
    public function enqueue_sidebar_user_menu_assets(): void {
        $css = 'li#ab-sidebar-account{display:block!important;list-style:none!important;margin:0!important;padding:0!important;background:transparent!important;line-height:1.3!important;}'
             . 'li#ab-sidebar-account::before,li#ab-sidebar-account::after{content:none!important;}'
             . 'li#ab-sidebar-account,li#ab-sidebar-account:hover,li#ab-sidebar-account:focus,li#ab-sidebar-account a,li#ab-sidebar-account a:hover,li#ab-sidebar-account a:focus,li#ab-sidebar-account a:active{background:transparent!important;background-color:transparent!important;box-shadow:none!important;}'
             . '#ab-sidebar-user-menu{display:flex!important;align-items:center!important;flex-direction:row!important;flex-wrap:nowrap!important;gap:6px;padding:8px 10px!important;border-bottom:1px solid rgba(255,255,255,0.08);min-height:44px;}'
             . '.ab-sidebar-user__profile{display:flex!important;flex-direction:row!important;align-items:center!important;flex-wrap:nowrap!important;gap:8px;flex:1 1 auto;min-width:0;padding:2px 0!important;}'
             . '#ab-sidebar-user-menu .ab-sidebar-user__avatar,.ab-sidebar-user__avatar{width:28px!important;height:28px!important;border-radius:50%!important;flex-shrink:0!important;margin:0!important;padding:0!important;max-width:none!important;display:inline-block!important;}'
             . '.ab-sidebar-user__info{display:flex!important;flex-direction:column!important;min-width:0;line-height:1.3;}'
             . '.ab-sidebar-user__name{font-size:12px!important;font-weight:600!important;line-height:1.3!important;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
             . '.ab-sidebar-user__role{font-size:10px!important;font-weight:400!important;line-height:1.3!important;opacity:0.6;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
             . '#ab-sidebar-user-menu .ab-sidebar-user__logout{display:flex!important;align-items:center!important;justify-content:center!important;width:28px!important;height:28px!important;flex-shrink:0!important;padding:0!important;margin:0!important;}'
             . '#ab-sidebar-site-link{position:relative;border-bottom:1px solid rgba(255,255,255,0.08);padding:4px 10px!important;background:transparent!important;}'
             . '.ab-sidebar-site__home{display:flex!important;flex-direction:row!important;align-items:center!important;flex-wrap:nowrap!important;gap:8px;padding:6px 0!important;text-decoration:none!important;background:transparent!important;box-shadow:none!important;min-width:0;}'
             . '.ab-sidebar-site__title{flex:1 1 auto;min-width:0;font-size:13px;font-weight:600;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
             . '.ab-sidebar-site__caret{flex-shrink:0;margin-left:auto;opacity:0.5;}'
             . '.ab-sidebar-site__home:hover .ab-sidebar-site__caret{opacity:1;}'
             . '.ab-sidebar-site__favicon{display:none;width:22px;height:22px;border-radius:3px;flex-shrink:0;}'
             . '.ab-sidebar-site__submenu{list-style:none;margin:0;padding:5px 0;position:absolute;left:100%;top:0;min-width:200px;background:#1d2327;box-shadow:0 2px 8px rgba(0,0,0,0.35);z-index:9999;display:none;}'
             . '#ab-sidebar-site-link:hover .ab-sidebar-site__submenu,#ab-sidebar-site-link:focus-within .ab-sidebar-site__submenu{display:block;}'
             . '.ab-sidebar-site__submenu li{margin:0;}'
             . '.ab-sidebar-site__submenu a{display:block;padding:6px 14px;font-size:13px;line-height:1.4;color:#c3c4c7!important;text-decoration:none;white-space:nowrap;}'
             . '.ab-sidebar-site__submenu a:hover,.ab-sidebar-site__submenu a:focus{background:#2271b1;color:#fff!important;}'
             . '.folded #ab-sidebar-user-menu{flex-direction:column!important;padding:6px 0!important;gap:4px!important;min-height:0!important;}'
             . '.folded .ab-sidebar-user__profile{flex-direction:column!important;justify-content:center!important;padding:0!important;}'
             . '.folded .ab-sidebar-user__info{display:none!important;}'
             . '.folded #ab-sidebar-user-menu .ab-sidebar-user__avatar,.folded .ab-sidebar-user__avatar{width:24px!important;height:24px!important;}'
             . '.folded #ab-sidebar-user-menu .ab-sidebar-user__logout{width:100%!important;justify-content:center!important;}'
             . '.folded #ab-sidebar-site-link.no-favicon{display:none!important;}'
             . '.folded #ab-sidebar-site-link{padding:6px 0!important;}'
             . '.folded .ab-sidebar-site__home{justify-content:center!important;padding:2px 0!important;}'
             . '.folded .ab-sidebar-site__title,.folded .ab-sidebar-site__caret{display:none!important;}'
             . '.folded .ab-sidebar-site__favicon{display:inline-block!important;}';

        wp_add_inline_style( 'admbud-icon-inject', $css );

        // Move the rendered <li> to the top of #adminmenu and inherit menu link colour.
        wp_add_inline_script(
            'admbud-icon-inject',
            '(function(){var el=document.getElementById("ab-sidebar-account"),menu=document.getElementById("adminmenu");if(el&&menu){menu.insertBefore(el,menu.firstChild);el.style.display="";var ref=menu.querySelector("li.menu-top > a, li.menu-top a");if(ref){var c=getComputedStyle(ref).color;el.querySelectorAll("a").forEach(function(a){a.style.color=c;});}}})();'
        );
    }

    /**
     * Conditionally remove an Admin Buddy pill from the WP admin bar for users
     * whose role matches the toggle's role picker. Used by the
     * "Hide Checklist/Noindex pill from admin bar" toggles in Quick Settings.
     *
     * @param string $toggle_key     The base option key (e.g. 'admbud_qs_hide_adminbar_checklist').
     *                               The `_roles` companion option is derived automatically.
     * @param string $node_id        The admin bar node ID to remove.
     * @param int    $after_priority Priority to hook on admin_bar_menu. Must be AFTER the
     *                               priority at which the node is added, or the node
     *                               won't exist yet.
     */
    private function maybe_hide_adminbar_pill( string $toggle_key, string $node_id, int $after_priority ): void {
        if ( admbud_get_option( $toggle_key, '0' ) !== '1' ) { return; }

        $roles = array_filter( explode( ',', (string) get_option( $toggle_key . '_roles', 'administrator' ) ) );
        if ( empty( $roles ) ) { return; }

        add_action( 'admin_bar_menu', static function ( \WP_Admin_Bar $bar ) use ( $roles, $node_id ) {
            if ( ! is_user_logged_in() ) { return; }
            $user = wp_get_current_user();
            foreach ( (array) $user->roles as $role ) {
                if ( in_array( $role, $roles, true ) ) {
                    $bar->remove_node( $node_id );
                    return;
                }
            }
        }, $after_priority );
    }

    /**
     * Render user avatar, name, role, and logout at the bottom of the admin sidebar.
     * Injected via admin_footer and repositioned into #adminmenuwrap via JS.
     * Collapses to icon-only when sidebar is folded.
     *
     * When Hide Admin Bar (Backend) is on for the current user, a site-name +
     * Visit Site / Dashboard / Themes / Widgets / Menus block is rendered above
     * the user card so users keep access to the frontend (in a new tab) and the
     * theme-management shortcuts that the admin bar would otherwise provide.
     */
    public function render_sidebar_user_menu(): void {
        $user       = wp_get_current_user();
        $avatar     = get_avatar( $user->ID, 32, '', '', [ 'class' => 'ab-sidebar-user__avatar' ] );
        $name       = esc_html( $user->display_name );
        $role_names = array_map( function( $r ) { return translate_user_role( wp_roles()->roles[ $r ]['name'] ?? $r ); }, $user->roles );
        $role_label = esc_html( implode( ', ', $role_names ) );
        $logout_url = esc_url( wp_logout_url() );
        $profile_url = esc_url( admin_url( 'profile.php' ) );

        // Site-link block: only when admin bar is hidden in the backend AND
        // the current user is in the hide-backend role list (otherwise the
        // admin bar is still visible to them and the link would be redundant).
        $show_site_link = false;
        if ( admbud_get_option( 'admbud_qs_hide_adminbar_backend', '0' ) === '1' ) {
            $be_roles = array_filter( explode( ',', (string) get_option( 'admbud_qs_hide_adminbar_backend_roles', 'administrator' ) ) );
            foreach ( (array) $user->roles as $role ) {
                if ( in_array( $role, $be_roles, true ) ) { $show_site_link = true; break; }
            }
        }
        $site_name = get_bloginfo( 'name' );
        $home_url  = home_url( '/' );
        ?>
        <?php // Wrapper is a <li> so it's a valid child of <ul#adminmenu>.
              // We inject it as the first child of #adminmenu so the
              // ::before pseudo (used by class-core.php for the optional
              // sidebar logo) still renders above us — giving the natural
              // visual stack: logo (if set) -> site -> user -> menu items. ?>
        <li id="ab-sidebar-account" class="ab-sidebar-account" role="presentation" style="display:none;">
        <?php if ( $show_site_link ) :
            // Pull child nodes from the actual admin bar's `site-name` node.
            // This mirrors WP default behaviour (Visit Site, Themes, Widgets,
            // Menus) and automatically picks up plugin additions like
            // WooCommerce's "Visit Store" without us hardcoding them.
            $sub_nodes = [];
            global $wp_admin_bar;
            if ( is_object( $wp_admin_bar ) ) {
                foreach ( $wp_admin_bar->get_nodes() as $bar_node ) {
                    if ( ! empty( $bar_node->parent ) && $bar_node->parent === 'site-name' && ! empty( $bar_node->href ) ) {
                        $sub_nodes[] = $bar_node;
                    }
                }
            }
            $admin_url_root = admin_url();
            // When the sidebar is collapsed, the configurable logo
            // (`#adminmenu::before`) is hidden by class-core.php, leaving an
            // empty gap. Show the WP site icon (favicon) there instead so the
            // collapsed sidebar still has a recognisable brand mark. If no
            // site icon is configured, the row is removed entirely so we
            // don't leave a blank space.
            $favicon_url = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( 64 ) : '';
            $link_classes = $favicon_url ? 'has-favicon' : 'no-favicon';
        ?>
        <div id="ab-sidebar-site-link" class="<?php echo esc_attr( $link_classes ); ?>">
            <a href="<?php echo esc_url( $home_url ); ?>" target="_blank" rel="noopener" class="ab-sidebar-site__home"
               title="<?php /* translators: %s: site name */ echo esc_attr( sprintf( __( 'Visit %s (opens in a new tab)', 'admin-buddy' ), $site_name ) ); ?>">
                <?php if ( $favicon_url ) : ?>
                <img src="<?php echo esc_url( $favicon_url ); ?>" alt="" class="ab-sidebar-site__favicon" width="22" height="22">
                <?php endif; ?>
                <span class="ab-sidebar-site__title"><?php echo esc_html( $site_name ); ?></span>
                <svg class="ab-sidebar-site__caret" width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="9 6 15 12 9 18"/></svg>
            </a>
            <?php if ( $sub_nodes ) : ?>
            <ul class="ab-sidebar-site__submenu" role="menu">
                <?php foreach ( $sub_nodes as $bar_node ) :
                    // Frontend-bound links (anything outside /wp-admin/) open
                    // in a new tab — matches the "user is here in admin and
                    // wants to peek at the front" intent. Admin links stay
                    // in the same tab.
                    $is_admin_link = is_string( $bar_node->href ) && str_starts_with( $bar_node->href, $admin_url_root );
                    $target_attr   = $is_admin_link ? '' : ' target="_blank" rel="noopener"';
                ?>
                <li role="none"><a role="menuitem" href="<?php echo esc_url( $bar_node->href ); ?>"<?php echo $target_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal, no user input ?>><?php echo wp_kses_post( $bar_node->title ); ?></a></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div id="ab-sidebar-user-menu">
            <a href="<?php echo esc_url( $profile_url ); ?>" class="ab-sidebar-user__profile" title="<?php esc_attr_e( 'Edit Profile', 'admin-buddy' ); ?>">
                <?php echo wp_kses_post( $avatar ); ?>
                <span class="ab-sidebar-user__info">
                    <span class="ab-sidebar-user__name"><?php echo esc_html( $name ); ?></span>
                    <span class="ab-sidebar-user__role"><?php echo esc_html( $role_label ); ?></span>
                </span>
            </a>
            <a href="<?php echo esc_url( $logout_url ); ?>" class="ab-sidebar-user__logout" title="<?php esc_attr_e( 'Log Out', 'admin-buddy' ); ?>">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </a>
        </div>
        </li><?php /* /#ab-sidebar-account */ ?>
        <?php
    }

    public function redirect_feed(): void {
        wp_safe_redirect( home_url(), 301 );
        exit;
    }

    /**
     * True when the current request is Bricks rendering any builder context.
     * The builder has two frontend-URL contexts we need to suppress in:
     *   - main:   the outer builder shell (bricks_is_builder_main)
     *   - iframe: the inner canvas that renders the page being edited
     *             (bricks_is_builder_iframe)
     * bricks_is_builder() covers both in modern Bricks; we still check the
     * individual helpers as a fallback because they predate it. Finally,
     * sniff $_GET['bricks'] as a last-resort guard for any edge context we
     * missed - the builder always carries that query arg on both frames.
     */
    private static function is_bricks_builder_request(): bool {
        if ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) {
            return true;
        }
        if ( function_exists( 'bricks_is_builder_main' ) && bricks_is_builder_main() ) {
            return true;
        }
        if ( function_exists( 'bricks_is_builder_iframe' ) && bricks_is_builder_iframe() ) {
            return true;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only presence check, not processing input.
        return isset( $_GET['bricks'] );
    }

    /**
     * Floating dashboard link shown on the frontend when the admin bar is hidden.
     * Gives logged-in users a way back to the WP admin without the toolbar.
     */
    public function render_floating_admin_link(): void {
        $url = esc_url( admin_url() );
        ?>
        <a href="<?php echo esc_url( $url ); ?>" id="ab-floating-admin-link" title="<?php esc_attr_e( 'Go to Dashboard', 'admin-buddy' ); ?>" aria-label="<?php esc_attr_e( 'Go to Dashboard', 'admin-buddy' ); ?>">
            <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg>
        </a>
        <?php
    }

    /**
     * Inline CSS for the floating admin-back link. Hooked to
     * wp_enqueue_scripts so the styles attach to a registered handle in head
     * rather than being printed inline next to the rendered <a>.
     */
    public function enqueue_floating_admin_link_style(): void {
        $css = '#ab-floating-admin-link{position:fixed;top:20px;left:20px;width:44px;height:44px;border-radius:50%;background:#1d2327;color:#fff;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(0,0,0,0.25);z-index:99999;text-decoration:none;transition:transform 0.2s,box-shadow 0.2s,background 0.2s;opacity:0.85;line-height:1;}'
            . '#ab-floating-admin-link svg{display:block;}'
            . '#ab-floating-admin-link:hover{transform:scale(1.08);opacity:1;box-shadow:0 6px 18px rgba(0,0,0,0.35);background:#2c2f34;}';

        // The icon-inject handle is enqueued globally on admin pages only.
        // For the front-end floating link we register a tiny dedicated handle.
        if ( ! wp_style_is( 'admbud-qs-floating', 'registered' ) ) {
            wp_register_style( 'admbud-qs-floating', false, [], ADMBUD_VERSION );
            wp_enqueue_style( 'admbud-qs-floating' );
        }
        wp_add_inline_style( 'admbud-qs-floating', $css );
    }

    /**
     * Add "Duplicate" row action to all public post types.
     *
     * @param array    $actions Existing row actions.
     * @param \WP_Post $post    Current post object.
     * @return array
     */
    public function add_duplicate_action( array $actions, \WP_Post $post ): array {
        if ( ! current_user_can( 'edit_posts' ) ) {
            return $actions;
        }

        $url = wp_nonce_url(
            add_query_arg( [
                'action'  => 'admbud_duplicate_post',
                'post_id' => $post->ID,
            ], admin_url( 'admin.php' ) ),
            'admbud_duplicate_' . $post->ID
        );

        $actions['admbud_duplicate'] = '<a href="' . esc_url( $url ) . '">'
            . esc_html__( 'Duplicate', 'admin-buddy' ) . '</a>';

        return $actions;
    }

    /**
     * Handle the duplicate action - create a draft copy of the post.
     */
    public function handle_duplicate(): void {
        $post_id = absint( $_GET['post_id'] ?? 0 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

        if ( ! $post_id || ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'You do not have permission to duplicate this post.', 'admin-buddy' ) );
        }

        check_admin_referer( 'admbud_duplicate_' . $post_id );

        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_die( esc_html__( 'Post not found.', 'admin-buddy' ) );
        }

        // Build the duplicate - always a draft, "Copy of" prefix.
        $new_id = wp_insert_post( [
            'post_title'     => __( 'Copy of', 'admin-buddy' ) . ' ' . $post->post_title,
            'post_content'   => $post->post_content,
            'post_excerpt'   => $post->post_excerpt,
            'post_status'    => 'draft',
            'post_type'      => $post->post_type,
            'post_author'    => get_current_user_id(),
            'post_parent'    => $post->post_parent,
            'menu_order'     => $post->menu_order,
            'comment_status' => $post->comment_status,
            'ping_status'    => $post->ping_status,
        ] );

        if ( is_wp_error( $new_id ) || ! $new_id ) {
            wp_die( esc_html__( 'Failed to duplicate post.', 'admin-buddy' ) );
        }

        // Copy all post meta.
        $meta = get_post_meta( $post_id );
        foreach ( $meta as $key => $values ) {
            // Skip internal WP meta that should not be copied.
            if ( in_array( $key, [ '_edit_lock', '_edit_last', '_wp_old_slug' ], true ) ) {
                continue;
            }
            foreach ( $values as $value ) {
                add_post_meta( $new_id, $key, maybe_unserialize( $value ) );
            }
        }

        // Copy taxonomies.
        $taxonomies = get_object_taxonomies( $post->post_type );
        foreach ( $taxonomies as $taxonomy ) {
            $terms = wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                wp_set_object_terms( $new_id, $terms, $taxonomy );
            }
        }

        // Redirect to the edit screen of the new draft.
        wp_safe_redirect( get_edit_post_link( $new_id, 'url' ) );
        exit;
    }

    // -- User Last Seen --------------------------------------------------------

    /** Meta key used to store the last seen timestamp. */
    const LAST_SEEN_META = 'admbud_last_seen';

    /**
     * Record the current timestamp in usermeta on login.
     *
     * @param string   $user_login Username.
     * @param \WP_User $user       User object.
     */
    public function record_last_seen( string $user_login, \WP_User $user ): void {
        update_user_meta( $user->ID, self::LAST_SEEN_META, time() );
    }

    /**
     * Add Last Seen column to the Users list table.
     *
     * @param array $columns Existing columns.
     * @return array
     */
    public function add_last_seen_column( array $columns ): array {
        $columns['admbud_last_seen'] = __( 'Last Seen', 'admin-buddy' );
        return $columns;
    }

    /**
     * Render the Last Seen column value.
     *
     * @param string $output      Current column output.
     * @param string $column_name Current column name.
     * @param int    $user_id     Current user ID.
     * @return string
     */
    public function render_last_seen_column( string $output, string $column_name, int $user_id ): string {
        if ( 'admbud_last_seen' !== $column_name ) {
            return $output;
        }

        $timestamp = get_user_meta( $user_id, self::LAST_SEEN_META, true );

        if ( ! $timestamp ) {
            return '<span style="color:var(--ab-text-muted,#94a3b8);">'
                . esc_html__( 'Never', 'admin-buddy' )
                . '</span>';
        }

        $diff     = human_time_diff( (int) $timestamp, time() );
        $datetime = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $timestamp );

        return '<span title="' . esc_attr( $datetime ) . '">'
            /* translators: %s: human-readable time difference, e.g. "2 hours" */
            /* translators: %s: human-readable time difference, e.g. "2 hours" */
            . sprintf( esc_html__( '%s ago', 'admin-buddy' ), esc_html( $diff ) )
            . '</span>';
    }

    /**
     * Make the Last Seen column sortable.
     *
     * @param array $columns Sortable columns.
     * @return array
     */
    public function sortable_last_seen_column( array $columns ): array {
        $columns['admbud_last_seen'] = 'admbud_last_seen';
        return $columns;
    }

    /**
     * Handle sorting by Last Seen in the Users query.
     *
     * @param \WP_User_Query $query Current user query.
     */
    public function sort_by_last_seen( \WP_User_Query $query ): void {
        if ( ! is_admin() ) { return; }
        if ( 'admbud_last_seen' !== $query->get( 'orderby' ) ) { return; }

        $query->set( 'meta_key',     self::LAST_SEEN_META );
        $query->set( 'orderby',      'meta_value_num' );
        // Users who have never logged in (no meta) go to the end.
        $query->set( 'meta_compare', 'EXISTS' );
    }
    /**
     * Sanitise SVG files on upload - strips scripts and event handlers.
     * Reuses SvgLibrary::sanitise_svg() to stay DRY.
     *
     * @param array $file The uploaded file array.
     * @return array Modified file array, or with error set if invalid.
     */
    public function sanitise_svg_on_upload( array $file ): array {
        if ( ! isset( $file['type'] ) || $file['type'] !== 'image/svg+xml' ) {
            return $file;
        }

        // Only process if user's role is in the allowed list.
        $allowed_roles = array_filter( explode( ',', admbud_get_option( 'admbud_qs_allow_svg_roles', admbud_get_option( 'admbud_qs_svg_roles', 'administrator' ) ) ) );
        $user          = wp_get_current_user();
        $role_allowed  = false;
        foreach ( (array) $user->roles as $role ) {
            if ( in_array( $role, $allowed_roles, true ) ) {
                $role_allowed = true;
                break;
            }
        }
        if ( ! $role_allowed ) {
            $file['error'] = __( 'Your role is not permitted to upload SVG files.', 'admin-buddy' );
            return $file;
        }

        $raw = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        if ( $raw === false ) {
            $file['error'] = __( 'Could not read SVG file for sanitisation.', 'admin-buddy' );
            return $file;
        }

        // Reuse the existing SvgLibrary sanitiser when the Pro Icon Library
        // module is present; otherwise fall back to a minimal inline sanitiser
        // so the free build can still accept SVG uploads safely.
        if ( ! class_exists( '\Admbud\SvgLibrary' )
             && file_exists( ADMBUD_DIR . 'includes/class-svg-library.php' ) ) {
            require_once ADMBUD_DIR . 'includes/class-svg-library.php';
        }
        if ( class_exists( '\Admbud\SvgLibrary' ) ) {
            $library = \Admbud\SvgLibrary::get_instance();
            $clean   = $library->sanitise_svg( $raw );
        } else {
            // Minimal fallback sanitiser: strip <script>, on* event handlers,
            // javascript: URLs, and foreignObject/iframe/embed tags.
            $clean = $raw;
            $clean = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $clean );
            $clean = preg_replace( '#<(foreignObject|iframe|embed|object)\b[^>]*>.*?</\1>#is', '', $clean );
            $clean = preg_replace( '#\son[a-z]+\s*=\s*"[^"]*"#i', '', $clean );
            $clean = preg_replace( "#\son[a-z]+\s*=\s*'[^']*'#i", '', $clean );
            $clean = preg_replace( '#javascript\s*:#i', '', $clean );
            // Basic validity check — must still contain a root <svg> tag.
            if ( ! preg_match( '#<svg\b#i', $clean ) ) {
                $clean = null;
            }
        }

        if ( $clean === null ) {
            $file['error'] = __( 'SVG file could not be sanitised. It may contain unsafe content.', 'admin-buddy' );
            return $file;
        }

        // Write the sanitised content back to the temp file.
        file_put_contents( $file['tmp_name'], $clean ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        return $file;
    }

}

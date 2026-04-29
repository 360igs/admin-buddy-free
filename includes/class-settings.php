<?php
/**
 * Settings - tabbed admin settings page for Admin Buddy.
 *
 * Tabs: UI Tweaks · Login · Maintenance
 * UI pattern: consistent card system across all tabs.
 *
 * @package Admbud
 */

namespace Admbud;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Settings {

    use Settings_Sanitizers;
    use Settings_Render;
    use Settings_Tools;

    private static ?Settings $instance = null;

    public static function get_instance(): Settings {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_page'      ] );
        add_action( 'admin_bar_menu',        [ $this, 'maybe_register_adminbar' ], 999 );
        add_action( 'admin_init',            [ $this, 'maybe_redirect_to_last_tab' ], 1 );
        add_action( 'admin_init',            [ $this, 'register_settings'    ] );
        add_action( 'admin_init',            [ $this, 'handle_smtp_password' ] );
        add_action( 'admin_init',            [ $this, 'dispatch_tools_action'] );
        // Also keep admin_post_ hooks as a fallback for any direct POSTs to admin-post.php.
        add_action( 'admin_post_ab_reset_data',       [ $this, 'handle_reset_data'    ] );
        add_action( 'admin_post_ab_reset_deactivate', [ $this, 'handle_reset_deactivate' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets'        ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_global_assets' ] );
        add_action( 'wp_ajax_admbud_modules_toggle',       [ $this, 'ajax_setup_toggle'      ] );
        add_action( 'wp_ajax_admbud_modules_bulk_toggle',  [ $this, 'ajax_setup_bulk_toggle' ] );
        add_action( 'wp_ajax_admbud_modules_group_toggle', [ $this, 'ajax_setup_group_toggle' ] );
        add_action( 'wp_ajax_admbud_qs_toggle',            [ $this, 'ajax_qs_toggle'          ] );
        add_action( 'wp_ajax_admbud_qs_bulk',              [ $this, 'ajax_qs_bulk'            ] );
        add_action( 'wp_ajax_admbud_qs_svg_roles',         [ $this, 'ajax_qs_save_roles'      ] );
        add_action( 'wp_ajax_admbud_qs_save_roles',        [ $this, 'ajax_qs_save_roles'      ] );
        add_action( 'wp_ajax_admbud_apply_preset', [ $this, 'ajax_apply_preset' ] );
        add_action( 'wp_ajax_admbud_apply_palette', [ $this, 'ajax_apply_palette' ] );
        // Intercept options.php redirect so WP's "Settings saved" notice never
        // fires. We swap the settings-updated param for our own admbud_notice=saved
        // and render it cleanly inside .ab-panel ourselves.
        add_filter( 'wp_redirect',           [ $this, 'intercept_redirect' ], 10, 1 );
        add_filter( 'plugin_action_links_' . ADMBUD_BASENAME, [ $this, 'action_links' ] );
        // I-2: Subresource Integrity for vendor scripts.
        add_filter( 'script_loader_tag',   [ $this, 'add_sri_attributes' ], 10, 2 );


    }

    // -- Canonical defaults for every option ---------------------------------
    // Single source of truth. Used by activation, reset, get_option fallbacks.
    public static function defaults(): array {
        return [
            // -- White Label toggles (all OFF by default) --
            'admbud_core_remove_logo'             => '0',
            'admbud_core_remove_help'             => '0',
            'admbud_core_remove_screen_options'   => '0',
            'admbud_notices_suppress'             => '0',
            'admbud_core_custom_footer_enabled'   => '0',
            'admbud_core_custom_footer_text'      => '',
            'admbud_wl_sidebar_logo_url'          => '',
            'admbud_wl_sidebar_logo_width'        => 84,
            'admbud_wl_sidebar_logo_height'       => 0,
            'admbud_wl_favicon_id'                => '',
            'admbud_wl_agency_name'               => '',
            'admbud_wl_agency_url'                => '',
            'admbud_wl_greeting'                  => '',
            'admbud_wl_remove_wp_links'           => '0',
            'admbud_wl_footer_version'            => '',
            'admbud_wl_footer_quote'              => '0',
            'admbud_wl_hide_wp_news'              => '0',
            // White Label - Cleanup (dissolved - items moved to Quick Settings)
            // White Label - Updates
            'admbud_wl_hide_core_update'          => '0',
            'admbud_wl_hide_plugin_update'        => '0',
            'admbud_wl_hide_theme_update'         => '0',
            'admbud_wl_hide_php_nag'              => '0',
            'admbud_wl_update_policy'             => '',
            'admbud_wl_disable_update_emails'     => '0',
            'admbud_wl_disable_all_updates'       => '0',
            // White Label - Gutenberg
            // -- Admin Bar --
            'admbud_show_in_adminbar'             => '0',
            // -- Dashboard --
            'admbud_dashboard_role_pages'         => '{}',
            'admbud_dashboard_keep_widgets'       => '',
            'admbud_dashboard_custom_widgets'     => '[]',
            // -- Modules --
            'admbud_modules_enabled_tabs'         => '',
            // -- Source --
            'admbud_source_enabled'               => '0',
            'admbud_source_modules'               => '',
            'admbud_source_whitelist'             => '[]',
            'admbud_source_log'                   => '[]',
            'admbud_receiver_sources'             => '[]',
            // -- Activity Log --
            'admbud_activity_log_retention'       => 90,
            'admbud_activity_log_per_page'        => 20,
            // -- Colours (Violety defaults) --
            'admbud_colours_primary'              => Colours::DEFAULT_PRIMARY,
            'admbud_colours_secondary'            => Colours::DEFAULT_SECONDARY,
            'admbud_colours_menu_text'            => Colours::DEFAULT_MENU_TEXT,
            'admbud_colours_menu_bg'              => Colours::DEFAULT_MENU_BG,
            'admbud_colours_submenu_bg'           => Colours::DEFAULT_SUBMENU_BG,
            'admbud_colours_active_text'          => '#ffffff',
            'admbud_colours_sep_color'            => '#5600ed',
            'admbud_colours_body_bg'              => '#ffffff',
            'admbud_colours_shadow_colour'        => '',
            'admbud_colours_menu_item_sep'        => '1',
            'admbud_colours_menu_bg_opacity'      => '100',
            'admbud_colours_menu_text_opacity'    => '100',
            'admbud_colours_sidebar_gradient'     => '1',
            'admbud_colours_sidebar_grad_dir'     => 'to top',
            'admbud_colours_sidebar_grad_from'    => '#2e1065',
            'admbud_colours_sidebar_grad_to'      => '#1e1b2e',
            'admbud_colours_submenu_text'         => '#ede9fe',
            'admbud_colours_hover_text'           => '#ede9fe',
            'admbud_colours_hover_bg'             => '',
            'admbud_colours_active_bg'            => '',
            'admbud_colours_active_parent_text'   => '#ffffff',
            'admbud_colours_submenu_hover_bg'     => Colours::DEFAULT_PRIMARY,
            'admbud_colours_submenu_hover_text'   => '#ede9fe',
            'admbud_colours_submenu_active_bg'    => '',
            'admbud_colours_submenu_active_text'  => '',
            'admbud_colours_adminbar_bg'          => '#1a1828',
            'admbud_colours_adminbar_text'        => '#d4d1e0',
            'admbud_colours_adminbar_hover_bg'    => Colours::DEFAULT_PRIMARY,
            'admbud_colours_adminbar_submenu_bg'  => '#1a1828',
            'admbud_colours_adminbar_hover_text'  => '#ede9fe',
            'admbud_colours_adminbar_sub_text'    => '#d4d1e0',
            'admbud_colours_adminbar_sub_hover_bg'   => '#1e1b2e',
            'admbud_colours_adminbar_sub_hover_text' => '#ffffff',
            'admbud_colours_pill_maintenance'     => '#dd3333',
            'admbud_colours_pill_coming_soon'     => '#dd3333',
            'admbud_colours_pill_noindex'         => '#dd9933',
            'admbud_colours_pill_admin_buddy'     => Colours::DEFAULT_PRIMARY,
            // Content area tokens
            'admbud_colours_content_heading'      => '#1d2327',
            'admbud_colours_content_text'         => '#3c434a',
            'admbud_colours_content_link'         => Colours::DEFAULT_PRIMARY,
            'admbud_colours_content_link_hover'   => Colours::DEFAULT_SECONDARY,
            'admbud_colours_table_header_bg'      => '#f4effd',
            'admbud_colours_table_header_text'    => '#1d2327',
            'admbud_colours_table_header_link'    => '#1d2327',
            'admbud_colours_table_row_bg'         => '#ffffff',
            'admbud_colours_table_row_text'       => '#3c434a',
            'admbud_colours_table_row_alt_bg'     => '#f9f7fe',
            'admbud_colours_table_row_alt_text'   => '#3c434a',
            'admbud_colours_table_row_hover'      => '#efe7fc',
            'admbud_colours_table_border'         => '#decdfa',
            'admbud_colours_table_row_separator'  => '#ebe1fc',
            'admbud_colours_table_action_link'    => Colours::DEFAULT_PRIMARY,
            'admbud_colours_table_title_link'     => Colours::DEFAULT_PRIMARY,
            'admbud_colours_input_bg'             => '#ffffff',
            'admbud_colours_input_border'         => '#d1baf8',
            'admbud_colours_input_focus'          => Colours::DEFAULT_PRIMARY,
            'admbud_colours_btn_primary_bg'       => Colours::DEFAULT_PRIMARY,
            'admbud_colours_btn_primary_text'     => '#ffffff',
            'admbud_colours_btn_primary_hover'    => Colours::DEFAULT_SECONDARY,
            'admbud_colours_btn_secondary_bg'     => '#f7f3fd',
            'admbud_colours_postbox_bg'           => '#ffffff',
            'admbud_colours_postbox_header_bg'    => '#f9f7fe',
            'admbud_colours_postbox_border'       => '#decdfa',
            'admbud_colours_postbox_text'         => '',
            'admbud_colours_notice_bg'            => '#fbf9fe',
            'admbud_colours_ui_radius'            => 'off',
            'admbud_css_exclusions'               => '',
            // -- Login --
            'admbud_login_logo_url'               => '',
            'admbud_login_logo_width'             => 84,
            'admbud_login_logo_height'            => 0,
            'admbud_login_card_position'          => 'center',
            'admbud_login_bg_type'                => Colours::DEFAULT_LOGIN_BG_TYPE,
            'admbud_login_bg_color'               => Colours::DEFAULT_PAGE_BG,
            'admbud_login_grad_from'              => Colours::DEFAULT_SIDEBAR_GRAD_FROM,
            'admbud_login_grad_to'                => Colours::DEFAULT_SIDEBAR_GRAD_TO,
            'admbud_login_grad_direction'         => 'to bottom right',
            'admbud_login_bg_image_url'           => '',
            'admbud_login_bg_overlay_color'       => '#000000',
            'admbud_login_bg_overlay_opacity'     => 30,
            // -- Maintenance --
            'admbud_maintenance_mode'             => 'off',
            'admbud_coming_soon_title'            => 'Coming Soon',
            'admbud_coming_soon_message'          => "We're working on something exciting. Stay tuned!",
            'admbud_maintenance_title'            => 'Under Maintenance',
            'admbud_maintenance_message'          => "We're performing scheduled maintenance. We'll be back shortly!",
            'admbud_maintenance_bypass_urls'      => '',
            'admbud_cs_bg_type'                   => Colours::DEFAULT_CS_BG_TYPE,
            'admbud_cs_bg_color'                  => Colours::DEFAULT_PAGE_BG,
            'admbud_cs_grad_from'                 => Colours::DEFAULT_SIDEBAR_GRAD_FROM,
            'admbud_cs_grad_to'                   => Colours::DEFAULT_SIDEBAR_GRAD_TO,
            'admbud_cs_grad_direction'            => 'to bottom right',
            'admbud_cs_bg_image_url'              => '',
            'admbud_cs_bg_overlay_color'          => '#000000',
            'admbud_cs_bg_overlay_opacity'        => 30,
            'admbud_cs_text_color'                => Colours::DEFAULT_PAGE_TEXT,
            'admbud_cs_message_color'             => Colours::DEFAULT_PAGE_MESSAGE,
            'admbud_maint_bg_type'                => Colours::DEFAULT_MAINT_BG_TYPE,
            'admbud_maint_bg_color'               => Colours::DEFAULT_PAGE_BG,
            'admbud_maint_grad_from'              => Colours::DEFAULT_SIDEBAR_GRAD_FROM,
            'admbud_maint_grad_to'                => Colours::DEFAULT_SIDEBAR_GRAD_TO,
            'admbud_maint_grad_direction'         => 'to bottom right',
            'admbud_maint_bg_image_url'           => '',
            'admbud_maint_bg_overlay_color'       => '#000000',
            'admbud_maint_bg_overlay_opacity'     => 30,
            'admbud_maint_text_color'             => Colours::DEFAULT_PAGE_TEXT,
            'admbud_maint_message_color'          => Colours::DEFAULT_PAGE_MESSAGE,
            // -- SMTP --
            'admbud_smtp_enabled'                 => '0',
            'admbud_smtp_mailer'                  => 'smtp',
            'admbud_smtp_host'                    => '',
            'admbud_smtp_port'                    => 587,
            'admbud_smtp_username'                => '',
            'admbud_smtp_encryption'              => 'tls',
            'admbud_smtp_auth'                    => '1',
            'admbud_smtp_from_name'               => '',
            'admbud_smtp_from_email'              => '',
            'admbud_smtp_fallback'                => '0',
            'admbud_smtp_disable_ssl_verify'      => '0',
            'admbud_smtp_preset'                  => 'custom',
            // -- Menu Customiser --
            'admbud_menu_config'                  => '{}',
            'admbud_menu_show_item_borders'       => '0',
            // -- Bricks --
            'admbud_bricks_enabled'               => '0',
            'admbud_bricks_color_mapping_enabled' => '1',
            'admbud_bricks_custom_logo_url'       => '',
            'admbud_bricks_custom_spinner_url'    => '',
            // -- Bricks BEM Generator --
            'admbud_bricks_bem_enabled'           => '0',
            'admbud_bricks_bem_auto_sync_labels'  => '1',
            'admbud_bricks_bem_show_modifiers'    => '0',
            'admbud_bricks_bem_default_action'    => 'rename',
            'admbud_bricks_bem_blacklist_extra'   => '',
            // -- Bricks Quick Insert --
            'admbud_bricks_quick_enabled'         => '0',
            'admbud_bricks_quick_favourites'      => '["section","container","heading","text-basic","image","button","icon","list"]',
            // -- Option Pages --
            'admbud_option_pages'                 => '{}',
            // -- Collections --
            'admbud_collections'                  => '{}',
        ];
    }

    // -- Multisite notice ------------------------------------------------------

    /**
     * Warn administrators on multisite installs that Admin Buddy does not
     * support network-synced settings. Shows once per admin, dismissible.
     */

    // -- Menu ------------------------------------------------------------------

    public function register_page(): void {
        // Menu icon: AB lettermark v3 - base64 data URI with fill="white" for WP menu rendering.
        $icon_svg = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA4MCA4MCIgZmlsbD0ibm9uZSI+PHBhdGggZD0iTTcyLjY2NzMgNi44MDM4N0M3Ny4wNTg4IDExLjE5NCA3OS40NzEzIDE3LjAyMjEgNzkuNDcxMyAyMy4yMjg0Qzc5LjQ3MDYgMzAuOTE3NSA3NC44MjM4IDQ2LjMyOCA1Ni4yNDE3IDQ2LjQ1OEw0Ni40NTcyIDQ2LjU2NDVMNDYuNDU3MiAzMy40NzI0QzUxLjgyNzMgMzMuNTAwOSA2NS4yMjc1IDMxLjUzNjggNjYuMDAwNCAyMy4yMjg3QzY2LjAwMDQgMjAuNjI2NCA2NC45OTc1IDE4LjE2MDMgNjMuMTUzOSAxNi4yOTA1QzYxLjMxMTEgMTQuNDQ3MSA1OC44NDUxIDEzLjQ0NDIgNTYuMjE1NCAxMy40NDQyQzUxLjAxMTcgMTMuNDQ0MiA0Ni40NTcyIDE3Ljk5ODIgNDYuNDU3MiAyMy4yMjg3TDQ2LjQ1NzIgMzMuNDcyNEwzMy4wMTg4IDMzLjVMMzMuMDE0IDIzLjA5MzZDMzMuMDE0IDE3LjAyMjEgMzUuNDI2OCAxMS4xOTQyIDM5LjgxNzQgNi44MDM4N0M0NC4yMDgzIDIuNDEyNCA1MC4wMzU5IC02LjQxMzUzZS0wNSA1Ni4yNDI1IC02LjQxMzUzZS0wNUM2Mi40NDkxIC02LjQxMzUzZS0wNSA2OC4yNzcgMi40MTI0IDcyLjY2NzMgNi44MDM4N1oiIGZpbGw9IndoaXRlIi8+PHBhdGggZD0iTTcyLjY2NzMgNzMuMTk2MUM3Ny4wNTg4IDY4LjgwNiA3OS40NzEzIDYyLjk3NzkgNzkuNDcxMyA1Ni43NzE2Qzc5LjQ3MDYgNDkuMDgyNSA3NC44MjM4IDMzLjY3MiA1Ni4yNDE3IDMzLjU0Mkw0Ni40NTcyIDMzLjQzNTVMNDYuNDU3MiA0Ni41Mjc2QzUxLjgyNzMgNDYuNDk5MSA2NS4yMjc1IDQ4LjQ2MzIgNjYuMDAwNCA1Ni43NzEzQzY2LjAwMDQgNTkuMzczNiA2NC45OTc1IDYxLjgzOTcgNjMuMTUzOSA2My43MDk1QzYxLjMxMTEgNjUuNTUyOSA1OC44NDUxIDY2LjU1NTggNTYuMjE1NCA2Ni41NTU4QzUxLjAxMTcgNjYuNTU1OCA0Ni40NTcyIDYyLjAwMTggNDYuNDU3MiA1Ni43NzEzTDQ2LjQ1NzIgNDYuNTI3NkwzMy4wMTg4IDQ2LjVMMzMuMDE0IDU2LjkwNjRDMzMuMDE0IDYyLjk3NzkgMzUuNDI2OCA2OC44MDU4IDM5LjgxNzQgNzMuMTk2MUM0NC4yMDgzIDc3LjU4NzYgNTAuMDM1OSA4MC4wMDAxIDU2LjI0MjUgODAuMDAwMUM2Mi40NDkxIDgwLjAwMDEgNjguMjc3IDc3LjU4NzYgNzIuNjY3MyA3My4xOTYxWiIgZmlsbD0id2hpdGUiLz48cGF0aCBkPSJNNi44MDM4OCA3My4xOTYxQzIuNDEyNDEgNjguODA2IC02LjU0NzE5ZS0wNSA2Mi45Nzc5IC02LjU0NzE5ZS0wNSA1Ni43NzE2QzAuMDAwNTgxMTIgNDkuMDgyNSA0LjY0NzQxIDMzLjY3MiAyMy4yMjk1IDMzLjU0MkwzMy4wMTQgMzMuNDM1NUwzMy4wMTQgNDYuNTI3NkMyNy42NDM5IDQ2LjQ5OTEgMTQuMjQzNyA0OC40NjMyIDEzLjQ3MDggNTYuNzcxM0MxMy40NzA4IDU5LjM3MzYgMTQuNDczNyA2MS44Mzk3IDE2LjMxNzMgNjMuNzA5NUMxOC4xNjAxIDY1LjU1MjkgMjAuNjI2MSA2Ni41NTU4IDIzLjI1NTggNjYuNTU1OEMyOC40NTk1IDY2LjU1NTggMzMuMDE0IDYyLjAwMTggMzMuMDE0IDU2Ljc3MTNMMzMuMDE0IDQ2LjUyNzZMNDYuNDUyNCA0Ni41TDQ2LjQ1NzIgNTYuOTA2NEM0Ni40NTcyIDYyLjk3NzkgNDQuMDQ0NCA2OC44MDU4IDM5LjY1MzggNzMuMTk2MUMzNS4yNjI5IDc3LjU4NzYgMjkuNDM1MyA4MC4wMDAxIDIzLjIyODcgODAuMDAwMUMxNy4wMjIxIDgwLjAwMDEgMTEuMTk0MiA3Ny41ODc2IDYuODAzODggNzMuMTk2MVoiIGZpbGw9IndoaXRlIi8+PHBhdGggZmlsbC1ydWxlPSJldmVub2RkIiBjbGlwLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik0tNi41NDcxOWUtMDUgMjMuMjI4NEMtNi41NDcxOWUtMDUgMTcuMDIyMSAyLjQxMjQxIDExLjE5NCA2LjgwMzg4IDYuODAzODdDMTEuMTk0MiAyLjQxMjQgMTcuMDIyMSAtNi40MTM1M2UtMDUgMjMuMjI4NyAtNi40MTM1M2UtMDVDMjkuNDM1MyAtNi40MTM1M2UtMDUgMzUuMjYyOSAyLjQxMjQgMzkuNjUzOCA2LjgwMzg3QzQ0LjA0NDQgMTEuMTk0MiA0Ni40NTcyIDE3LjAyMjEgNDYuNDU3MiAyMy4wOTM2TDQ2LjQ1MjQgMzMuNUwzMy4wMTQgMzMuNDcyNEwzMy4wMTQgMjMuMjI4N0MzMy4wMTQgMTcuOTk4MiAyOC40NTk1IDEzLjQ0NDIgMjMuMjU1OCAxMy40NDQyQzIwLjYyNjEgMTMuNDQ0MiAxOC4xNjAxIDE0LjQ0NzEgMTYuMzE3MyAxNi4yOTA1QzE0LjQ3MzcgMTguMTYwMyAxMy40NzA4IDIwLjYyNjQgMTMuNDcwOCAyMy4yMjg3QzEzLjgyOTIgMjcuMDgxMyAxNi45MDI5IDI5LjU2OTcgMjAuNjMyIDMxLjEyNzlDMTIuMDUxOCAzMS4xMjc5IDYuMzE5NiAzNS4zODE5IDQuNTI2MDEgMzcuNTA4OUMxLjEzNTI1IDMyLjcxMzcgMC4wMDAyNTQwNjMgMjcuMDI4MiAtNi41NDcxOWUtMDUgMjMuMjI4NFoiIGZpbGw9IndoaXRlIi8+PHJlY3QgeD0iMzMuMDEzMiIgeT0iMzMuNDM1NSIgd2lkdGg9IjEzLjQ0NDciIGhlaWdodD0iMTMuMTMwMiIgZmlsbD0id2hpdGUiLz48L3N2Zz4=';
        $page = add_menu_page(
            __( 'Admin Buddy', 'admin-buddy' ),
            __( 'Admin Buddy', 'admin-buddy' ),
            'manage_options',
            'admin-buddy',
            [ $this, 'render_page' ],
            $icon_svg,
            80
        );

        // First submenu: same slug as parent so WP doesn't create a duplicate.
        // Clicking parent "Admin Buddy" goes to last visited tab.
        add_submenu_page(
            'admin-buddy',
            __( 'Admin Buddy', 'admin-buddy' ),
            __( 'Admin Buddy', 'admin-buddy' ),
            'manage_options',
            'admin-buddy',
            [ $this, 'render_page' ]
        );

        // Explicit Modules submenu - always goes to Modules tab.
        add_submenu_page(
            'admin-buddy',
            __( 'Modules', 'admin-buddy' ),
            __( 'Modules', 'admin-buddy' ),
            'manage_options',
            'admin-buddy&tab=modules',
            [ $this, 'render_page' ]
        );

        // Plugin Data submenu.
        add_submenu_page(
            'admin-buddy',
            __( 'Plugin Data', 'admin-buddy' ),
            __( 'Plugin Data', 'admin-buddy' ),
            'manage_options',
            'admin-buddy&tab=plugin-data',
            [ $this, 'render_page' ]
        );
    }

    /** Optionally add Admin Buddy to the WP admin bar with submenu. */
    public function maybe_register_adminbar( \WP_Admin_Bar $bar ): void {
        if ( admbud_get_option( 'admbud_show_in_adminbar', '0' ) !== '1' ) { return; }
        if ( ! current_user_can( 'manage_options' ) ) { return; }

        $base = admin_url( 'admin.php?page=admin-buddy' );
        $bar->add_node( [
            'id'    => 'admin-buddy',
            'title' => '<span class="ab-bar-node ab-bar-node--admin-buddy">' . esc_html__( 'Admin Buddy', 'admin-buddy' ) . '</span>',
            'href'  => $base,
            'meta'  => [ 'title' => __( 'Admin Buddy Settings', 'admin-buddy' ) ],
        ] );

        // Modules link.
        $bar->add_node( [
            'parent' => 'admin-buddy',
            'id'     => 'ab-bar-modules',
            'title'  => __( 'Modules', 'admin-buddy' ),
            'href'   => $base . '&tab=modules',
        ] );

        // Enabled module links grouped with separators.
        $enabled = $this->get_enabled_tabs();
        $tabs    = $this->get_manageable_tabs();
        $groups  = [ 'interface', 'utilities', 'integrations' ];

        foreach ( $groups as $group_key ) {
            $group_tabs = array_filter( $tabs, function ( $t ) use ( $group_key ) {
                return ( $t['group'] ?? '' ) === $group_key;
            } );

            foreach ( $group_tabs as $gslug => $gtab ) {
                if ( ! in_array( $gslug, $enabled, true ) ) { continue; }
                $bar->add_node( [
                    'parent' => 'admin-buddy',
                    'id'     => 'ab-bar-' . $gslug,
                    'title'  => $gtab['label'],
                    'href'   => $base . '&tab=' . $gslug,
                ] );
            }
        }

        // Always-visible manage tabs - same array as sidebar, kept in sync.
        $manage_tabs = [
            'quick-settings' => __( 'Quick Settings', 'admin-buddy' ),
            'source'         => __( 'Remote',         'admin-buddy' ),
            'plugin-data'      => __( 'Plugin Data',     'admin-buddy' ),
        ];
        foreach ( $manage_tabs as $mtab_slug => $mtab_label ) {
            $bar->add_node( [
                'parent' => 'admin-buddy',
                'id'     => 'ab-bar-' . $mtab_slug,
                'title'  => $mtab_label,
                'href'   => $base . '&tab=' . $mtab_slug,
            ] );
        }
    }

    /**
     * Intercept options.php's post-save redirect on our settings pages.
     *
     * When any of our settings groups are saved, options.php redirects back
     * with ?settings-updated=true which triggers WP's settings_errors() system
     * and produces notices we can't control placement of. We swap that param
     * for our own ?admbud_notice=saved so we can render it exactly where we want.
     */
    public function intercept_redirect( string $location ): string {
        if (
            strpos( $location, 'page=admin-buddy' ) !== false &&
            strpos( $location, 'settings-updated=true' ) !== false
        ) {
            // This filter runs on the wp_redirect hook AFTER WordPress's
            // options.php pipeline has already verified the form's nonce
            // ($option_page-options) and saved the registered settings. The
            // 'settings-updated=true' marker in $location confirms that path
            // executed successfully — we only get here on a verified save.
            //
            // The $_POST reads below are read-only sanitisation of two custom
            // hidden fields (admbud_tab, admbud_subtab) that the form sets so
            // we can preserve nav state in the redirect URL. No state change,
            // no database writes — just URL massaging via add_query_arg().

            // Force-clear the CSS cache on every settings save.
            // Belt-and-suspenders: updated_option should bust it per-key,
            // but some toggles (separator, gradient) can be missed if WP
            // skips the update when the value hasn't changed.
            Colours::maybe_bust_cache();

            // Swap WP's settings-updated for our own notice param.
            $location = remove_query_arg( 'settings-updated', $location );
            $location = add_query_arg( 'admbud_notice', 'saved', $location );

            // Restore the main tab. Strip any tab= already in the URL (from the
            // HTTP referer) then add ours cleanly, so there's no duplication.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by WP options.php pipeline upstream (see comment above).
            $tab = sanitize_key( wp_unslash( $_POST['admbud_tab'] ?? '' ) );
            if ( $tab !== '' ) {
                $location = remove_query_arg( 'tab', $location );
                $location = add_query_arg( 'tab', $tab, $location );
            }

            // Restore the active sub-tab.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by WP options.php pipeline upstream (see comment above).
            $subtab = sanitize_key( wp_unslash( $_POST['admbud_subtab'] ?? '' ) );
            if ( $subtab !== '' ) {
                $location = remove_query_arg( 'admbud_subtab', $location );
                $location = add_query_arg( 'admbud_subtab', $subtab, $location );
            }
        }
        return $location;
    }

    /**
     * Render our own inline success/error notice inside .ab-panel.
     * Reads ?admbud_notice= set by intercept_redirect() or handle_advanced().
     * WP's settings_errors() system is never called for our settings pages.
     */
    public function render_notice(): void {
        // phpcs:ignore WordPress.Security.NonceVerification
        if ( empty( $_GET['admbud_notice'] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
        // phpcs:ignore WordPress.Security.NonceVerification
        $key = sanitize_key( $_GET['admbud_notice'] ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput

        // These notices are rendered as JS toasts instead of HTML banners.
        $js_toasted = [ 'saved', 'import_ok', 'reset_ok', 'import_empty', 'import_read_fail', 'import_invalid' ];
        if ( in_array( $key, $js_toasted, true ) ) { return; }

        // Demo mode blocked action notice.
        if ( $key === 'demo_blocked' ) {
            ?>
            <div class="ab-notice ab-notice--warning">
                🔒 <?php esc_html_e( 'This action is disabled in demo mode. Get the full version at wpadminbuddy.com', 'admin-buddy' ); ?>
            </div>
            <?php
            return;
        }
    }

    // ============================================================================
    // SETTINGS REGISTRATION
    // ============================================================================

    public function register_settings(): void {

        // -- UI Tweaks (was: core + dashboard + notices) -----------------------
        foreach ( [ 'remove_logo', 'remove_help', 'remove_screen_options' ] as $key ) {
            register_setting( 'admbud_core_group', 'admbud_core_' . $key, [
                'type' => 'boolean', 'sanitize_callback' => [ $this, 'sanitize_checkbox' ], 'default' => '0',
            ] );
        }
        register_setting( 'admbud_core_group', 'admbud_core_custom_footer_enabled', [
            'type' => 'boolean', 'sanitize_callback' => [ $this, 'sanitize_checkbox' ], 'default' => '0',
        ] );
        register_setting( 'admbud_core_group', 'admbud_core_custom_footer_text', [
            'type' => 'string', 'sanitize_callback' => 'wp_kses_post', 'default' => '',
        ] );
        register_setting( 'admbud_core_group', 'admbud_wl_sidebar_logo_url', [
            'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => '',
        ] );
        register_setting( 'admbud_core_group', 'admbud_wl_sidebar_logo_width', [
            'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 84,
        ] );
        register_setting( 'admbud_core_group', 'admbud_wl_sidebar_logo_height', [
            'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0,
        ] );
        // Favicon: stored in WP's native 'site_icon' option (attachment ID).
        // We register under our group so it saves via our form; WP reads it natively.
        register_setting( 'admbud_core_group', 'admbud_wl_favicon_id', [
            'type'              => 'integer',
            'sanitize_callback' => function( $val ) {
                $id = absint( $val );
                // Sync to WP native option so Settings > General reflects the change.
                update_option( 'site_icon', $id );
                return $id; // stored as admbud_wl_favicon_id but ignored at read - we always read site_icon directly.
            },
            'default' => 0,
        ] );
        register_setting( 'admbud_core_group', 'admbud_dashboard_role_pages', [
            'type' => 'string', 'sanitize_callback' => [ $this, 'sanitize_dashboard_role_pages' ], 'default' => '{}',
        ] );

        // -- Phase 1 White Label options ---------------------------------------
        $wl_toggles = [
            'admbud_wl_remove_wp_links', 'admbud_wl_hide_wp_news',
            'admbud_wl_footer_quote',
        ];
        foreach ( $wl_toggles as $key ) {
            register_setting( 'admbud_core_group', $key, [
                'type' => 'boolean', 'sanitize_callback' => [ $this, 'sanitize_checkbox' ], 'default' => '0',
            ] );
        }
        $wl_text_fields = [
            'admbud_wl_agency_name'     => 'sanitize_text_field',
            'admbud_wl_agency_url'      => 'esc_url_raw',
            'admbud_wl_greeting'        => 'sanitize_text_field',
            'admbud_wl_footer_version'  => 'sanitize_text_field',
        ];
        foreach ( $wl_text_fields as $key => $sanitizer ) {
            register_setting( 'admbud_core_group', $key, [
                'type' => 'string', 'sanitize_callback' => $sanitizer, 'default' => '',
            ] );
        }
        register_setting( 'admbud_core_group', 'admbud_dashboard_keep_widgets', [
            'type'              => 'string',
            'sanitize_callback' => function( $val ) {
                $decoded = json_decode( wp_unslash( $val ), true );
                if ( ! is_array( $decoded ) ) { return ''; }
                return wp_json_encode( array_map( 'sanitize_key', $decoded ) );
            },
            'default' => '',
        ] );
        register_setting( 'admbud_core_group', 'admbud_dashboard_custom_widgets', [
            'type'              => 'string',
            'sanitize_callback' => function( $val ) {
                $decoded = json_decode( wp_unslash( $val ), true );
                if ( ! is_array( $decoded ) ) { return '[]'; }
                $clean = [];
                foreach ( $decoded as $w ) {
                    if ( ! is_array( $w ) ) { continue; }
                    $clean[] = [
                        'title'   => sanitize_text_field( $w['title']   ?? '' ),
                        'content' => wp_kses_post(        $w['content'] ?? '' ),
                    ];
                }
                return wp_json_encode( $clean );
            },
            'default' => '[]',
        ] );
        register_setting( 'admbud_core_group', 'admbud_notices_suppress', [
            'type' => 'boolean', 'sanitize_callback' => [ $this, 'sanitize_checkbox' ], 'default' => '1',
        ] );
        register_setting( 'admbud_core_group', 'admbud_show_in_adminbar', [
            'type' => 'boolean', 'sanitize_callback' => [ $this, 'sanitize_checkbox' ], 'default' => '0',
        ] );


        register_setting( 'admbud_modules_group', 'admbud_modules_enabled_tabs', [
            'type'              => 'string',
            'sanitize_callback' => [ $this, 'sanitize_setup_tabs' ],
            'default'           => '',
        ] );

        // -- Activity Log -------------------------------------------------------
        register_setting( 'admbud_activity_log_group', 'admbud_activity_log_per_page', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 20,
        ] );
        register_setting( 'admbud_activity_log_group', 'admbud_activity_log_retention', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 90,
        ] );

        // -- Quick Settings -----------------------------------------------------
        $qs_keys = [
            'admbud_qs_disable_emoji',
            'admbud_qs_disable_jquery_migrate',
            'admbud_qs_remove_feed_links',
            'admbud_qs_remove_rsd',
            'admbud_qs_remove_wlw',
            'admbud_qs_remove_shortlink',
            'admbud_qs_remove_restapi_link',
            'admbud_qs_disable_embeds',
            'admbud_qs_remove_version',
            'admbud_qs_disable_xmlrpc',
            'admbud_qs_disable_rest_api',
            'admbud_qs_disable_file_edit',
            'admbud_qs_disable_feeds',
            'admbud_qs_disable_self_ping',
            'admbud_qs_disable_comments_default',
            'admbud_qs_duplicate_post',
            'admbud_qs_user_last_seen',
            'admbud_qs_allow_svg',
        ];
        foreach ( $qs_keys as $qs_key ) {
            register_setting( 'admbud_quick_settings', $qs_key, [
                'type'              => 'boolean',
                'sanitize_callback' => [ $this, 'sanitize_checkbox' ],
                'default'           => '0',
            ] );
        }

        register_setting( 'admbud_quick_settings', 'admbud_qs_svg_roles', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'administrator',
        ] );

        // -- Bricks Builder Integration ----------------------------------------
        register_setting( 'admbud_bricks_group', 'admbud_bricks_enabled', [
            'type'              => 'boolean',
            'sanitize_callback' => function( $val ) {
                $enabled = ( $val === '1' || $val === true || $val === 1 );
                // If toggled off, revert Bricks to its default builder mode.
                if ( ! $enabled && class_exists( \Admbud\Bricks::class ) ) {
                    \Admbud\Bricks::revert_bricks_builder_mode();
                }
                return $enabled ? '1' : '0';
            },
            'default' => '0',
        ] );
        // Sub-toggle for the AB-to-Bricks colour mapping. When off, we leave
        // Bricks' --builder-* vars alone and revert builderMode to 'light' so
        // the builder uses Bricks' own native palette - even if other parts of
        // the integration (dynamic data, query loops, logo replacement) stay on.
        register_setting( 'admbud_bricks_group', 'admbud_bricks_color_mapping_enabled', [
            'type'              => 'boolean',
            'sanitize_callback' => function( $val ) {
                $enabled = ( $val === '1' || $val === true || $val === 1 );
                if ( ! $enabled && class_exists( \Admbud\Bricks::class ) ) {
                    \Admbud\Bricks::revert_bricks_builder_mode();
                }
                return $enabled ? '1' : '0';
            },
            'default' => '1',
        ] );
        // Per-variable colour overrides - empty string = use derived value.
        $bricks_var_keys = [
            'builder_bg', 'builder_bg_2', 'builder_bg_3', 'builder_bg_accent',
            'builder_color', 'builder_color_description', 'builder_color_accent',
            'builder_color_accent_inverse', 'builder_color_knob',
            'builder_border_color', 'bricks_tooltip_bg', 'bricks_tooltip_text',
        ];
        foreach ( $bricks_var_keys as $vk ) {
            register_setting( 'admbud_bricks_group', 'admbud_bricks_' . $vk, [
                'type'              => 'string',
                'sanitize_callback' => function( $v ) {
                    // Empty = "use derived". Non-empty must be a hex colour.
                    $v = trim( $v );
                    if ( $v === '' ) { return ''; }
                    return sanitize_hex_color( $v ) ?? '';
                },
                'default' => '',
            ] );
        }
        // -- Bricks Other Tweaks -----------------------------------------------
        foreach ( [ 'admbud_bricks_hide_logo', 'admbud_bricks_hide_spinner' ] as $tweak_key ) {
            register_setting( 'admbud_bricks_group', $tweak_key, [
                'type'              => 'boolean',
                'sanitize_callback' => [ $this, 'sanitize_checkbox' ],
                'default'           => '0',
            ] );
        }
        // Custom logo / spinner URLs - paired with the toggles above. Empty
        // string falls back to AB's default styled logo and CSS ring spinner.
        foreach ( [ 'admbud_bricks_custom_logo_url', 'admbud_bricks_custom_spinner_url' ] as $url_key ) {
            register_setting( 'admbud_bricks_group', $url_key, [
                'type'              => 'string',
                'sanitize_callback' => 'esc_url_raw',
                'default'           => '',
            ] );
        }

        // -- Bricks BEM Generator ----------------------------------------------
        foreach ( [
            'admbud_bricks_bem_enabled',
            'admbud_bricks_bem_auto_sync_labels',
            'admbud_bricks_bem_show_modifiers',
        ] as $bem_bool_key ) {
            register_setting( 'admbud_bricks_group', $bem_bool_key, [
                'type'              => 'boolean',
                'sanitize_callback' => [ $this, 'sanitize_checkbox' ],
                'default'           => ( $bem_bool_key === 'admbud_bricks_bem_auto_sync_labels' ) ? '1' : '0',
            ] );
        }
        register_setting( 'admbud_bricks_group', 'admbud_bricks_bem_default_action', [
            'type'              => 'string',
            'sanitize_callback' => function( $v ) {
                $allowed = [ 'rename', 'remove', 'delete', 'keep', 'copy-id' ];
                return in_array( $v, $allowed, true ) ? $v : 'rename';
            },
            'default'           => 'rename',
        ] );
        register_setting( 'admbud_bricks_group', 'admbud_bricks_bem_blacklist_extra', [
            'type'              => 'string',
            'sanitize_callback' => function( $v ) {
                // Comma-separated list of property keys to treat as content
                // (not CSS styles) during the copy-id action. Bounded at
                // 64 tokens * 32 chars so hostile input can't balloon.
                $raw    = (string) $v;
                $tokens = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
                $tokens = array_slice( $tokens, 0, 64 );
                $tokens = array_map( function ( $t ) {
                    return substr( sanitize_key( $t ), 0, 32 );
                }, $tokens );
                $tokens = array_values( array_filter( $tokens ) );
                return implode( ',', $tokens );
            },
            'default'           => '',
        ] );

        // -- Bricks Quick Insert -----------------------------------------------
        register_setting( 'admbud_bricks_group', 'admbud_bricks_quick_enabled', [
            'type'              => 'boolean',
            'sanitize_callback' => [ $this, 'sanitize_checkbox' ],
            'default'           => '0',
        ] );
        register_setting( 'admbud_bricks_group', 'admbud_bricks_quick_favourites', [
            'type'              => 'string',
            'sanitize_callback' => function( $v ) {
                // Stored as JSON array of slugs. Drop anything not in the
                // canonical catalog on save so the client never sees
                // unknown slugs.
                $catalog = class_exists( '\Admbud\BricksQuick' )
                    ? array_keys( \Admbud\BricksQuick::CATALOG )
                    : [];
                $raw = is_string( $v ) ? $v : wp_json_encode( $v );
                $arr = json_decode( $raw, true );
                if ( ! is_array( $arr ) ) { return '[]'; }
                $arr = array_values( array_filter(
                    array_map( 'sanitize_key', $arr ),
                    function ( $slug ) use ( $catalog ) {
                        return in_array( $slug, $catalog, true );
                    }
                ) );
                $arr = array_slice( $arr, 0, 30 );
                return wp_json_encode( $arr );
            },
            'default'           => '[]',
        ] );

        // -- Admin UI Colours --------------------------------------------------
        $colour_opts = [
            // hex-only: these feed into darken/lighten/hex_to_rgb_triplet calculations.
            'admbud_colours_primary'          => [ 'string',  [ $this, 'sanitize_hex_color' ], Colours::DEFAULT_PRIMARY          ],
            'admbud_colours_menu_bg'          => [ 'string',  [ $this, 'sanitize_hex_color' ], Colours::DEFAULT_MENU_BG          ],
            'admbud_colours_sidebar_grad_from'=> [ 'string',  [ $this, 'sanitize_hex_color' ], Colours::DEFAULT_SIDEBAR_GRAD_FROM ],
            'admbud_colours_sidebar_grad_to'  => [ 'string',  [ $this, 'sanitize_hex_color' ], Colours::DEFAULT_SIDEBAR_GRAD_TO   ],
            // rgba/hsla allowed: used directly in CSS output only.
            'admbud_colours_secondary'        => [ 'string',  [ $this, 'sanitize_color' ], Colours::DEFAULT_SECONDARY        ],
            'admbud_colours_menu_text'        => [ 'string',  [ $this, 'sanitize_color' ], Colours::DEFAULT_MENU_TEXT        ],
            'admbud_colours_active_text'      => [ 'string',  [ $this, 'sanitize_color' ], Colours::DEFAULT_ACTIVE_TEXT      ],
            'admbud_colours_sep_color'        => [ 'string',  [ $this, 'sanitize_color' ], ''                               ],
            'admbud_colours_body_bg'          => [ 'string',  [ $this, 'sanitize_color' ], ''                               ],

            'admbud_colours_adminbar_text'    => [ 'string',  [ $this, 'sanitize_color' ], ''                               ],
            'admbud_colours_adminbar_hover_bg'  => [ 'string', [ $this, 'sanitize_color' ], ''                              ],
            'admbud_colours_adminbar_submenu_bg'=> [ 'string', [ $this, 'sanitize_color' ], ''                              ],
            'admbud_colours_adminbar_bg'             => [ 'string', [ $this, 'sanitize_color' ], ''                         ],
            'admbud_colours_adminbar_hover_text'     => [ 'string', [ $this, 'sanitize_color' ], ''                         ],
            'admbud_colours_adminbar_sub_text'       => [ 'string', [ $this, 'sanitize_color' ], ''                         ],
            'admbud_colours_adminbar_sub_hover_text' => [ 'string', [ $this, 'sanitize_color' ], ''                         ],
            'admbud_colours_pill_maintenance'        => [ 'string', [ $this, 'sanitize_color' ], ''                         ],
            'admbud_colours_pill_coming_soon'        => [ 'string', [ $this, 'sanitize_color' ], ''                         ],
            'admbud_colours_pill_noindex'            => [ 'string', [ $this, 'sanitize_color' ], ''                         ],
            'admbud_colours_pill_admin_buddy'        => [ 'string', [ $this, 'sanitize_color' ], ''                         ],
            'admbud_colours_submenu_text'     => [ 'string',  [ $this, 'sanitize_color' ], ''                               ],
            'admbud_colours_hover_text'       => [ 'string',  [ $this, 'sanitize_color' ], ''                               ],
            'admbud_colours_hover_bg'         => [ 'string',  [ $this, 'sanitize_color' ], ''                               ],
            'admbud_colours_active_bg'        => [ 'string',  [ $this, 'sanitize_color' ], ''                               ],
            'admbud_colours_active_parent_text' => [ 'string', [ $this, 'sanitize_color' ], ''                              ],
            'admbud_colours_submenu_bg'         => [ 'string', [ $this, 'sanitize_color' ], ''                              ],
            'admbud_colours_submenu_hover_bg'   => [ 'string', [ $this, 'sanitize_color' ], ''                              ],
            'admbud_colours_submenu_hover_text' => [ 'string', [ $this, 'sanitize_color' ], ''                              ],
            'admbud_colours_submenu_active_bg'  => [ 'string', [ $this, 'sanitize_color' ], ''                              ],
            'admbud_colours_submenu_active_text' => [ 'string', [ $this, 'sanitize_color' ], ''                             ],
            'admbud_colours_adminbar_sub_hover_bg' => [ 'string', [ $this, 'sanitize_color' ], ''                           ],
            'admbud_colours_shadow_colour'      => [ 'string', [ $this, 'sanitize_color' ], ''                              ],
            // -- Content area tokens ------------------------------------------
            'admbud_colours_content_heading'        => [ 'string', [ $this, 'sanitize_color' ], '' ],
            'admbud_colours_content_text'           => [ 'string', [ $this, 'sanitize_color' ], '' ],
            'admbud_colours_content_link'           => [ 'string', [ $this, 'sanitize_color' ], '' ],
            'admbud_colours_content_link_hover'     => [ 'string', [ $this, 'sanitize_color' ], '' ],
            'admbud_colours_table_header_bg'        => [ 'string', [ $this, 'sanitize_color' ], '' ],
            'admbud_colours_table_header_text'      => [ 'string', [ $this, 'sanitize_color' ], '' ],
            'admbud_colours_table_header_link'      => [ 'string', [ $this, 'sanitize_color' ], '' ],
            'admbud_colours_table_row_bg'           => [ 'string', [ $this, 'sanitize_color' ], '' ],
            'admbud_colours_table_row_text'         => [ 'string', [ $this, 'sanitize_color' ], '' ],
            'admbud_colours_table_row_alt_bg'       => [ 'string', [ $this, 'sanitize_color' ], '' ],
            'admbud_colours_table_row_alt_text'     => [ 'string', [ $this, 'sanitize_color' ], '' ],
            'admbud_colours_table_row_hover'        => [ 'string', [ $this, 'sanitize_color' ], '' ],
            'admbud_colours_table_border'           => [ 'string', [ $this, 'sanitize_color' ], '' ],
            'admbud_colours_table_row_separator'    => [ 'string', [ $this, 'sanitize_color' ], '' ],
            'admbud_colours_table_action_link'      => [ 'string', [ $this, 'sanitize_color' ], '' ],
            'admbud_colours_table_title_link'       => [ 'string', [ $this, 'sanitize_color' ], '' ],
            'admbud_colours_input_bg'               => [ 'string', [ $this, 'sanitize_color' ], '' ],
            'admbud_colours_input_border'           => [ 'string', [ $this, 'sanitize_color' ], '' ],
            'admbud_colours_input_focus'            => [ 'string', [ $this, 'sanitize_color' ], '' ],
            'admbud_colours_btn_primary_bg'         => [ 'string', [ $this, 'sanitize_color' ], '' ],
            'admbud_colours_btn_primary_text'       => [ 'string', [ $this, 'sanitize_color' ], '' ],
            'admbud_colours_btn_primary_hover'      => [ 'string', [ $this, 'sanitize_color' ], '' ],
            'admbud_colours_btn_secondary_bg'       => [ 'string', [ $this, 'sanitize_color' ], '' ],
            'admbud_colours_postbox_bg'             => [ 'string', [ $this, 'sanitize_color' ], '' ],
            'admbud_colours_postbox_header_bg'      => [ 'string', [ $this, 'sanitize_color' ], '' ],
            'admbud_colours_postbox_border'         => [ 'string', [ $this, 'sanitize_color' ], '' ],
            'admbud_colours_postbox_text'           => [ 'string', [ $this, 'sanitize_color' ], '' ],
            'admbud_colours_notice_bg'              => [ 'string', [ $this, 'sanitize_color' ], '' ],
            'admbud_colours_sidebar_gradient' => [ 'boolean', [ $this, 'sanitize_checkbox' ],  '0'                              ],
            'admbud_colours_sidebar_grad_dir' => [ 'string',  [ $this, 'sanitize_grad_direction' ], Colours::DEFAULT_SIDEBAR_GRAD_DIR ],
            'admbud_colours_menu_item_sep'    => [ 'boolean', [ $this, 'sanitize_checkbox' ],  '1'                              ],
            'admbud_colours_ui_radius'        => [ 'string',  [ $this, 'sanitize_ui_radius' ], 'off'                            ],
        ];
        foreach ( $colour_opts as $key => [ $type, $cb, $default ] ) {
            register_setting( 'admbud_colours_group', $key, [
                'type' => $type, 'sanitize_callback' => $cb, 'default' => $default,
            ] );
        }

        // -- CSS Exclusions ----------------------------------------------------
        register_setting( 'admbud_css_exclusions_group', 'admbud_css_exclusions', [
            'type'              => 'string',
            'sanitize_callback' => [ $this, 'sanitize_css_exclusions' ],
            'default'           => '',
        ] );

        // -- Login branding ----------------------------------------------------
        $login_opts = [
            'admbud_login_logo_url'           => [ 'string',  'esc_url_raw',                 ''  ],
            'admbud_login_logo_width'         => [ 'integer', [ $this, 'sanitize_logo_width' ], 84 ],
            'admbud_login_logo_height'        => [ 'integer', [ $this, 'sanitize_logo_height' ], 0 ],
            'admbud_login_card_position'      => [ 'string',  [ $this, 'sanitize_card_position' ], 'center' ],
            'admbud_login_bg_type'            => [ 'string',  [ $this, 'sanitize_bg_type' ], 'solid' ],
            'admbud_login_bg_color'           => [ 'string',  [ $this, 'sanitize_hex_color' ], Colours::DEFAULT_PAGE_BG    ],
            'admbud_login_grad_from'          => [ 'string',  [ $this, 'sanitize_hex_color' ], Colours::DEFAULT_SIDEBAR_GRAD_FROM ],
            'admbud_login_grad_to'            => [ 'string',  [ $this, 'sanitize_hex_color' ], Colours::DEFAULT_SIDEBAR_GRAD_TO   ],
            'admbud_login_grad_direction'     => [ 'string',  [ $this, 'sanitize_grad_direction' ], 'to bottom right' ],
            'admbud_login_bg_image_url'       => [ 'string',  'esc_url_raw', '' ],
            'admbud_login_bg_overlay_color'   => [ 'string',  [ $this, 'sanitize_hex_color' ], '#000000' ],
            'admbud_login_bg_overlay_opacity' => [ 'integer', [ $this, 'sanitize_overlay_opacity' ], 30 ],
        ];
        foreach ( $login_opts as $key => [ $type, $cb, $default ] ) {
            register_setting( 'admbud_login_group', $key, [
                'type' => $type, 'sanitize_callback' => $cb, 'default' => $default,
            ] );
        }

        // -- Maintenance -------------------------------------------------------
        $maint_opts = [
            'admbud_maintenance_mode'              => [ 'string',  [ $this, 'sanitize_maintenance_mode' ], 'off'  ],
            // Coming Soon
            'admbud_coming_soon_title'             => [ 'string',  'sanitize_text_field',  __( 'Coming Soon', 'admin-buddy' ) ],
            'admbud_coming_soon_message'           => [ 'string',  'wp_kses_post',         __( "We're working on something exciting. Stay tuned!", 'admin-buddy' ) ],
            'admbud_cs_bg_type'                    => [ 'string',  [ $this, 'sanitize_bg_type' ], 'solid' ],
            'admbud_cs_bg_color'                   => [ 'string',  [ $this, 'sanitize_hex_color' ], Colours::DEFAULT_PAGE_BG           ],
            'admbud_cs_grad_from'                  => [ 'string',  [ $this, 'sanitize_hex_color' ], Colours::DEFAULT_SIDEBAR_GRAD_FROM ],
            'admbud_cs_grad_to'                    => [ 'string',  [ $this, 'sanitize_hex_color' ], Colours::DEFAULT_SIDEBAR_GRAD_TO   ],
            'admbud_cs_grad_direction'             => [ 'string',  [ $this, 'sanitize_grad_direction' ], 'to bottom right' ],
            'admbud_cs_bg_image_url'               => [ 'string',  'esc_url_raw', '' ],
            'admbud_cs_bg_overlay_color'           => [ 'string',  [ $this, 'sanitize_hex_color' ], '#000000' ],
            'admbud_cs_bg_overlay_opacity'         => [ 'integer', [ $this, 'sanitize_overlay_opacity' ], 30 ],
            // Maintenance
            'admbud_maintenance_title'             => [ 'string',  'sanitize_text_field',  __( 'Under Maintenance', 'admin-buddy' ) ],
            'admbud_maintenance_message'           => [ 'string',  'wp_kses_post',         __( "We're performing scheduled maintenance. We'll be back shortly!", 'admin-buddy' ) ],
            'admbud_maint_bg_type'                 => [ 'string',  [ $this, 'sanitize_bg_type' ], 'solid' ],
            'admbud_maint_bg_color'                => [ 'string',  [ $this, 'sanitize_hex_color' ], Colours::DEFAULT_PAGE_BG           ],
            'admbud_maint_grad_from'               => [ 'string',  [ $this, 'sanitize_hex_color' ], Colours::DEFAULT_SIDEBAR_GRAD_FROM ],
            'admbud_maint_grad_to'                 => [ 'string',  [ $this, 'sanitize_hex_color' ], Colours::DEFAULT_SIDEBAR_GRAD_TO   ],
            'admbud_maint_grad_direction'          => [ 'string',  [ $this, 'sanitize_grad_direction' ], 'to bottom right' ],
            'admbud_maint_bg_image_url'            => [ 'string',  'esc_url_raw', '' ],
            'admbud_maint_bg_overlay_color'        => [ 'string',  [ $this, 'sanitize_hex_color' ], '#000000' ],
            'admbud_maint_bg_overlay_opacity'      => [ 'integer', [ $this, 'sanitize_overlay_opacity' ], 30 ],
            'admbud_maintenance_bypass_urls'       => [ 'string',  [ $this, 'sanitize_bypass_urls' ], '' ],
            // Text colours for intercept pages
            'admbud_cs_text_color'                 => [ 'string',  [ $this, 'sanitize_hex_color' ], Colours::DEFAULT_PAGE_TEXT    ],
            'admbud_cs_message_color'              => [ 'string',  [ $this, 'sanitize_hex_color' ], Colours::DEFAULT_PAGE_MESSAGE ],
            'admbud_maint_text_color'              => [ 'string',  [ $this, 'sanitize_hex_color' ], Colours::DEFAULT_PAGE_TEXT    ],
            'admbud_maint_message_color'           => [ 'string',  [ $this, 'sanitize_hex_color' ], Colours::DEFAULT_PAGE_MESSAGE ],
        ];
        foreach ( $maint_opts as $key => [ $type, $cb, $default ] ) {
            register_setting( 'admbud_maintenance_group', $key, [
                'type' => $type, 'sanitize_callback' => $cb, 'default' => $default,
            ] );
        }

        // -- SMTP --------------------------------------------------------------
        $smtp_opts = [
            'admbud_smtp_enabled'    => [ 'boolean', [ $this, 'sanitize_checkbox' ],   '0'     ],
            'admbud_smtp_mailer'     => [ 'string',  [ $this, 'sanitize_smtp_mailer' ], 'smtp' ],
            'admbud_smtp_host'       => [ 'string',  'sanitize_text_field',             ''     ],
            'admbud_smtp_port'       => [ 'integer', [ $this, 'sanitize_smtp_port' ],   587    ],
            'admbud_smtp_username'   => [ 'string',  'sanitize_text_field',             ''     ],
            'admbud_smtp_encryption' => [ 'string',  [ $this, 'sanitize_smtp_enc' ],    'tls'  ],
            'admbud_smtp_auth'       => [ 'boolean', [ $this, 'sanitize_checkbox' ],    '1'    ],
            'admbud_smtp_from_name'  => [ 'string',  'sanitize_text_field',             ''     ],
            'admbud_smtp_from_email' => [ 'string',  'sanitize_email',                  ''     ],
            'admbud_smtp_fallback'          => [ 'boolean', [ $this, 'sanitize_checkbox' ],    '0'    ], // legacy - kept for back-compat
            'admbud_smtp_disable_ssl_verify'=> [ 'boolean', [ $this, 'sanitize_checkbox' ],    '0'    ],
            'admbud_smtp_preset'     => [ 'string',  'sanitize_key',                    'custom' ],
        ];
        foreach ( $smtp_opts as $key => [ $type, $cb, $default ] ) {
            register_setting( 'admbud_smtp_group', $key, [
                'type' => $type, 'sanitize_callback' => $cb, 'default' => $default,
            ] );
        }
        // Password is handled separately - encrypted before storage.
        add_filter( 'pre_update_option_ab_smtp_group', [ $this, 'maybe_encrypt_smtp_password' ] );
    }



    // ============================================================================
    // ASSETS
    // ============================================================================

    public function enqueue_assets( string $hook ): void {
        if ( ! in_array( $hook, [ 'settings_page_admin-buddy', 'toplevel_page_admin-buddy' ], true ) ) return;

        $v      = ADMBUD_VERSION;
        $url    = ADMBUD_URL . 'assets/';
        $js_url = ADMBUD_URL . 'assets/js/';

        // Phase 1.1.0: minified files removed - all assets are unminified source.
        // WordPress caching / CDN handles production optimisation.
        $min_js  = '';
        $min_css = '';

        // Tokens must load before component CSS.
        wp_enqueue_style( 'admin-buddy-tokens', $url . 'tokens.css', [], $v );
        // Core shared CSS - always loaded.
        wp_enqueue_style( 'admin-buddy-core', $url . 'admin.css', [ 'admin-buddy-tokens' ], $v );
        wp_enqueue_style( 'admin-buddy-dropdown', $url . "ab-dropdown{$min_css}.css", [ 'admin-buddy-core' ], $v );
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
        wp_enqueue_script( 'admin-buddy-dropdown', $js_url . 'ab-dropdown.js', [], $v, true );
        wp_enqueue_media();


        // Core shared JS - Vanilla ES6+, no jQuery dependency.
        wp_enqueue_script( 'admin-buddy-core', $js_url . 'admin.js', [ 'wp-color-picker', 'admin-buddy-dropdown' ], $v, true );

        // Tab-specific assets - only loaded on the relevant tab.
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'adminui'; // phpcs:ignore WordPress.Security.NonceVerification

        switch ( $active_tab ) {
            case 'snippets':
                // Enqueue CodeMirror for all snippet types via WP's code editor API.
                // Each call also enqueues the matching linter (csslint, jshint,
                // htmlhint) and returns a settings array whose codemirror.lint
                // key holds the per-rule severity config for that type. We
                // capture each call so we can ship a per-type lint map to JS
                // — without this, only the LAST call's lint config survives
                // (wp_localize_script overwrites _wpCodeEditorSettings) and
                // CSS/JS would silently inherit HTMLHint's rules.
                $cm_php  = wp_enqueue_code_editor( [ 'type' => 'text/x-php' ] );
                $cm_css  = wp_enqueue_code_editor( [ 'type' => 'text/css' ] );
                $cm_js   = wp_enqueue_code_editor( [ 'type' => 'text/javascript' ] );
                $cm_html = wp_enqueue_code_editor( [ 'type' => 'text/html' ] );
                wp_enqueue_style( 'code-editor' );
                wp_enqueue_script( 'code-editor' );
                wp_enqueue_style(  'admin-buddy-tab-snippets', $url . "tab-snippets{$min_css}.css", [ 'admin-buddy-core' ], $v );
                wp_enqueue_script( 'admin-buddy-tab-snippets', $js_url . 'tab-snippets.js', [ 'admin-buddy-core' ], $v, true );
                // The linter rule maps live at the TOP LEVEL of each settings
                // array (csslint/jshint/htmlhint), not under codemirror.lint.
                // wp.codeEditor.initialize() reads those top-level keys to set
                // up the actual linter. Pass them per-type so the JS side can
                // inject the right one based on the active mode.
                wp_localize_script( 'admin-buddy-tab-snippets', 'admbudSnippetData', [
                    'restUrl'   => esc_url_raw( rest_url( 'admin-buddy/v1/php-functions' ) ),
                    'restNonce' => wp_create_nonce( 'wp_rest' ),
                    'linters'   => [
                        'csslint'  => isset( $cm_css['csslint'] )   ? $cm_css['csslint']   : null,
                        'jshint'   => isset( $cm_js['jshint'] )     ? $cm_js['jshint']     : null,
                        'htmlhint' => isset( $cm_html['htmlhint'] ) ? $cm_html['htmlhint'] : null,
                    ],
                ] );
                break;

            case 'smtp':
                wp_enqueue_style(  'admin-buddy-tab-smtp', $url . "tab-smtp{$min_css}.css", [ 'admin-buddy-core' ], $v );
                wp_enqueue_script( 'admin-buddy-tab-smtp', $js_url . 'tab-smtp.js', [ 'admin-buddy-core' ], $v, true );
                break;

            case 'roles':
                wp_enqueue_style(  'admin-buddy-tab-roles', $url . "tab-roles{$min_css}.css", [ 'admin-buddy-core' ], $v );
                wp_enqueue_script( 'admin-buddy-tab-roles', $js_url . 'tab-roles.js', [ 'admin-buddy-core' ], $v, true );
                break;


            case 'quick-settings':
                wp_enqueue_script( 'admin-buddy-tab-quick-settings', $js_url . 'tab-quick-settings.js', [ 'admin-buddy-core' ], $v, true );
                wp_localize_script( 'admin-buddy-tab-quick-settings', 'admbudQs', [
                    'nonce'   => wp_create_nonce( 'admbud_qs_nonce' ),
                    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                    'i18n'    => [
                        'enabled'             => __( 'Setting enabled.',  'admin-buddy' ),
                        'disabled'            => __( 'Setting disabled.', 'admin-buddy' ),
                        'failed'              => __( 'Failed to save. Please try again.', 'admin-buddy' ),
                        'autoEnabled'         => __( "Sidebar User Menu turned on so you can still navigate. Toggle off below if you don't want it.", 'admin-buddy' ),
                        'rolesSaved'          => __( 'Roles saved.',           'admin-buddy' ),
                        'rolesFailed'         => __( 'Failed to save roles.',  'admin-buddy' ),
                        'allEnabled'          => __( 'All settings enabled.',  'admin-buddy' ),
                        'allDisabled'         => __( 'All settings disabled.', 'admin-buddy' ),
                        'confirmEnableTitle'  => __( 'Enable all Quick Settings?',  'admin-buddy' ),
                        'confirmEnableBody'   => __( 'All 16 settings will be turned on. You can disable individual ones afterwards.', 'admin-buddy' ),
                        'confirmEnableYes'    => __( 'Yes, enable all',  'admin-buddy' ),
                        'confirmDisableTitle' => __( 'Disable all Quick Settings?', 'admin-buddy' ),
                        'confirmDisableBody'  => __( 'All 16 settings will be turned off.', 'admin-buddy' ),
                        'confirmDisableYes'   => __( 'Yes, disable all', 'admin-buddy' ),
                    ],
                ] );
                break;

            case 'modules':
                wp_enqueue_script( 'wp-element' );
                wp_enqueue_script(
                    'admin-buddy-setup-modules',
                    $js_url . 'setup-modules.js',
                    [ 'admin-buddy-core', 'wp-element' ],
                    $v,
                    true
                );
                $manageable = $this->get_manageable_tabs();
                $enabled    = $this->get_enabled_tabs();
                $allowed    = function_exists( 'admbud_allowed_modules' ) ? admbud_allowed_modules() : array_keys( $manageable );
                $is_licensed = function_exists( 'admbud_is_licensed' ) ? admbud_is_licensed() : true;
                $is_paid     = function_exists( 'admbud_is_paid' ) ? admbud_is_paid() : true;
                $modules_data = [];
                foreach ( $manageable as $slug => $tab ) {
                    $modules_data[] = [
                        'slug'      => $slug,
                        'label'     => $tab['label'],
                        'icon'      => $tab['icon'],
                        'group'     => $tab['group'] ?? 'interface',
                        'enabled'   => in_array( $slug, $enabled, true ),
                        'always_on' => false,
                        'pro'       => ! in_array( $slug, $allowed, true ),
                    ];
                }
                // Use wp_add_inline_script + wp_json_encode to preserve boolean types.
                // wp_localize_script stringifies booleans (false→"", true→"1") which
                // breaks strict equality checks in nested data structures.
                $setup_data = [
                    'modules'         => $modules_data,
                    'nonce'           => wp_create_nonce( 'admbud_modules_toggle' ),
                    'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
                    'maintenanceMode' => admbud_get_option( 'admbud_maintenance_mode', 'off' ),
                    'maintenanceUrl'  => admin_url( 'admin.php?page=admin-buddy&tab=maintenance' ),
                    'isLicensed'      => true,
                    'isPaid'          => ! admbud_is_pro(),
                ];
                wp_add_inline_script(
                    'admin-buddy-setup-modules',
                    'var admbudSetupData=' . wp_json_encode( $setup_data ) . ';',
                    'before'
                );
                $icons_map = [];
                foreach ( $manageable as $slug => $tab ) {
                    $icons_map[ $slug ] = $tab['icon'];
                }
                wp_localize_script( 'admin-buddy-setup-modules', 'admbudIcons', $icons_map );
                break;
        }

        // Shared localised strings - passed to admin-buddy-core but also available to tab scripts.
        wp_localize_script( 'admin-buddy-core', 'admbudSettings', [
            'chooseImageTitle'   => __( 'Choose Image',                                              'admin-buddy' ),
            'useImageText'       => __( 'Use this image',                                            'admin-buddy' ),
            'copiedText'         => __( '✓ Copied',                                                  'admin-buddy' ),
            'settingsSaved'      => __( 'Settings saved.',                                           'admin-buddy' ),
            'formSaveConfirmTitle' => __( 'Save changes?',                                           'admin-buddy' ),
            'formSaveConfirmBody'  => __( 'Your settings will be saved.',                            'admin-buddy' ),
            'regenerateConfirm'  => __( 'This will invalidate the current emergency URL. Continue?', 'admin-buddy' ),
            'regeneratingText'   => __( 'Regenerating…',                                             'admin-buddy' ),
            'regenerateText'     => __( 'Regenerate',                                                'admin-buddy' ),
            'regeneratedText'    => __( '✓ Token regenerated',                                       'admin-buddy' ),
            'errorText'          => __( '✗ Something went wrong.',                                   'admin-buddy' ),
            // Primary colour - used by login preview button when #admbud_colours_primary input is not on the page.
            'primaryColour'      => admbud_get_option( 'admbud_colours_primary', \Admbud\Colours::DEFAULT_PRIMARY ),
            // Import confirmation modal strings.
            'importConfirmTitle' => __( 'Import Settings?',                                          'admin-buddy' ),
            'importConfirmBody'  => __( 'This will replace all current settings with the contents of the uploaded file. This cannot be undone.', 'admin-buddy' ),
            // Unsaved changes warning.
            'unsavedTitle'       => __( 'Unsaved changes',                                          'admin-buddy' ),
            'unsavedBody'        => __( 'You have unsaved changes that will be lost if you leave this tab.', 'admin-buddy' ),
            'unsavedLeave'       => __( 'Leave',                                                    'admin-buddy' ),
            // Snippets.
            'snippetSaving'      => __( 'Saving…',                                                   'admin-buddy' ),
            'snippetSaved'       => __( 'Snippet saved.',                                            'admin-buddy' ),
            'snippetDeleteConfirmTitle' => __( 'Delete Snippet?',                                    'admin-buddy' ),
            'snippetDeleteConfirmBody'  => __( 'This action cannot be undone.',                      'admin-buddy' ),
            // SMTP.
            'smtpTesting'        => __( 'Sending…',                                                  'admin-buddy' ),
            'smtpClearConfirmTitle' => __( 'Clear Email Log?',                                       'admin-buddy' ),
            'smtpClearConfirmBody'  => __( 'All logged entries will be permanently deleted.',        'admin-buddy' ),
            // Roles.
            'roleSaving'         => __( 'Saving…',                                                   'admin-buddy' ),
            'roleSaved'          => __( 'Saved.',                                                    'admin-buddy' ),
            'roleSaveConfirmTitle'   => __( 'Save capabilities?',                                    'admin-buddy' ),
            'roleDeleteConfirmTitle' => __( 'Delete Role?',                                          'admin-buddy' ),
            'roleDeleteConfirmBody'  => __( 'Users with this role will be moved to Subscriber. This cannot be undone.', 'admin-buddy' ),
            'roleResetConfirmTitle'  => __( 'Reset Role?',                                           'admin-buddy' ),
            'roleResetConfirmBody'   => __( 'This will restore the default capabilities for this role.', 'admin-buddy' ),
            // Menu.
            'menuSaving'         => __( 'Saving…',                                                   'admin-buddy' ),
            'menuSaved'          => __( 'Saved.',                                                    'admin-buddy' ),
            // Custom Pages.
            'newPage'            => __( 'New Page',                                                  'admin-buddy' ),
            'editPage'           => __( 'Edit Page',                                                 'admin-buddy' ),
            'pageSaved'          => __( 'Page saved.',                                               'admin-buddy' ),
            'pageDeleted'        => __( 'Page deleted.',                                             'admin-buddy' ),
            'pageEnabled'        => __( 'Page enabled.',                                             'admin-buddy' ),
            'pageDisabled'       => __( 'Page disabled.',                                            'admin-buddy' ),
            'titleRequired'      => __( 'Page title is required.',                                   'admin-buddy' ),
            'deletePage'         => __( 'Delete page?',                                              'admin-buddy' ),
            /* translators: %s: page title */
            'deletePageBody'     => __( 'Delete "%s"? This cannot be undone.',                       'admin-buddy' ),
            // Theme URLs - used by Bricks integration modal redirects.
            'themeInstallUrl'    => esc_url( admin_url( 'theme-install.php?browse=popular' ) ),
            'themesUrl'          => esc_url( admin_url( 'themes.php' ) ),
            // -- Toast / notification strings used across all tab JS files --
            'importSuccess'      => __( 'Settings imported successfully.',                           'admin-buddy' ),
            'resetSuccess'       => __( 'Settings reset to defaults.',                               'admin-buddy' ),
            'importEmpty'        => __( 'Import failed: no file selected.',                          'admin-buddy' ),
            'importBadFile'      => __( 'Import failed: invalid file.',                              'admin-buddy' ),
            'saveFailed'         => __( 'Save failed.',                                              'admin-buddy' ),
            'requestFailed'      => __( 'Request failed.',                                           'admin-buddy' ),
            'toggleFailed'       => __( 'Toggle failed.',                                            'admin-buddy' ),
            'uploadFailed'       => __( 'Upload failed.',                                            'admin-buddy' ),
            'iconUploaded'       => __( 'Icon uploaded.',                                             'admin-buddy' ),
            'iconRenamed'        => __( 'Icon renamed.',                                              'admin-buddy' ),
            'colourSettingSaved' => __( 'Colour setting saved.',                                      'admin-buddy' ),
            'slugCopied'         => __( 'Slug copied.',                                               'admin-buddy' ),
            'onlySvgAllowed'     => __( 'Only SVG files are supported.',                              'admin-buddy' ),
            'snippetCreated'     => __( 'Snippet created.',                                           'admin-buddy' ),
            'snippetSavedOk'     => __( 'Snippet saved.',                                             'admin-buddy' ),
            'snippetEnabled'     => __( 'Snippet enabled.',                                           'admin-buddy' ),
            'snippetDisabled'    => __( 'Snippet disabled.',                                          'admin-buddy' ),
            'snippetDeleted'     => __( 'Snippet deleted.',                                           'admin-buddy' ),
            'roleCreated'        => __( 'Role created.',                                              'admin-buddy' ),
            'roleDeleted'        => __( 'Role deleted.',                                              'admin-buddy' ),
            'roleRenamed'        => __( 'Role renamed.',                                              'admin-buddy' ),
            'roleDefaultsRestored' => __( 'Defaults restored.',                                      'admin-buddy' ),
            'menuSaveConfirmTitle' => __( 'Save menu configuration?',                                'admin-buddy' ),
            /* translators: %s: role name */
            'menuSaveConfirmBody'  => __( 'Save settings for "%s".',                                 'admin-buddy' ),
            'menuResetConfirmTitle' => __( 'Reset to defaults?',                                     'admin-buddy' ),
            'paletteGenerated'   => __( 'Palette generated.',                                         'admin-buddy' ),
            'preserveColours'    => __( 'Preserve colours',                                           'admin-buddy' ),
            'copySlug'           => __( 'Copy',                                                       'admin-buddy' ),
            'deleteIcon'         => __( 'Delete',                                                     'admin-buddy' ),
            'clickToRename'      => __( 'Click to rename',                                            'admin-buddy' ),
            'saveChanges'        => __( 'Save Changes',                                               'admin-buddy' ),
            'removeWidget'       => __( 'Remove widget?',                                             'admin-buddy' ),
            'removeWidgetBody'   => __( 'This widget will be removed. Save to apply.',                'admin-buddy' ),
            // Setup modules.
            'enableAllModules'   => __( 'Enable all',                                                 'admin-buddy' ),
            'disableAllModules'  => __( 'Disable all',                                                'admin-buddy' ),
            'enableModulesConfirm' => __( 'All modules in this group will be enabled.',               'admin-buddy' ),
            'disableModulesConfirm' => __( 'All modules will be removed from the navigation.',        'admin-buddy' ),
            'moduleAlwaysOn'     => __( 'This module cannot be disabled',                             'admin-buddy' ),
            'disableModule'      => __( 'Disable',                                                    'admin-buddy' ),
            'enableModule'       => __( 'Enable',                                                     'admin-buddy' ),
            'maintenanceWarning' => __( 'Turn it off in the Maintenance tab first, then you can disable this module.', 'admin-buddy' ),
            'goToMaintenance'    => __( 'Go to Maintenance',                                          'admin-buddy' ),
            // Group labels.
            'groupInterface'     => __( 'Interface',                                               'admin-buddy' ),
            'groupUtilities'     => __( 'Utilities',                                                  'admin-buddy' ),
            'groupIntegrations'  => __( 'Integrations',                                               'admin-buddy' ),
            // Quick Settings AJAX nonce.
            'qsNonce'            => wp_create_nonce( 'admbud_qs_nonce' ),
            'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
            // General-purpose nonce for nav reorder etc.
            'admbudNonce'            => wp_create_nonce( 'admbud_nonce' ),
        ] );
    }

    public function action_links( array $links ): array {
        array_unshift( $links, sprintf(
            '<a href="%s">%s</a>',
            esc_url( admin_url( 'admin.php?page=admin-buddy' ) ),
            esc_html__( 'Settings', 'admin-buddy' )
        ) );
        return $links;
    }

    /**
     * Add Subresource Integrity (SRI) attributes to vendor scripts.
     * Protects against CDN/supply-chain tampering (I-2 hardening).
     *
     * @param string $tag    HTML script tag.
     * @param string $handle Script handle name.
     * @return string        Modified tag with integrity + crossorigin attributes.
     */
    public function add_sri_attributes( string $tag, string $handle ): string {
        $sri_map = [
        ];

        if ( isset( $sri_map[ $handle ] ) && strpos( $tag, 'integrity=' ) === false ) {
            $tag = str_replace(
                ' src=',
                ' integrity="' . esc_attr( $sri_map[ $handle ] ) . '" crossorigin="anonymous" src=',
                $tag
            );
        }

        return $tag;
    }

    // ============================================================================
    // TOOLS ACTIONS (export / import / reset variants)
    // ============================================================================



    // ============================================================================
    // PAGE RENDER
    // ============================================================================

    /**
     * Redirect to the last visited tab+subtab if arriving at admin-buddy without ?tab=.
     * Runs on admin_init (priority 1) - before any output so headers can be sent.
     */
    public function maybe_redirect_to_last_tab(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ( $_GET['page'] ?? '' ) !== 'admin-buddy' ) { return; } // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['tab'] ) ) { return; } // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification
        // Don't redirect if there's a POST action (reset/import/export)
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_POST['admbud_action'] ) || ! empty( $_GET['admbud_action'] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
        // Don't redirect after a save/reset/import (notice in URL)
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_GET['admbud_notice'] ) || ! empty( $_GET['settings-updated'] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
        if ( empty( $_COOKIE['admbud_last_tab'] ) ) { return; }

        $saved_tab = sanitize_key( wp_unslash( $_COOKIE['admbud_last_tab'] ) );
        if ( ! $saved_tab ) { return; }

        $url = admin_url( 'admin.php?page=admin-buddy&tab=' . $saved_tab );
        $sub_cookie = 'admbud_subtab_' . $saved_tab;
        if ( ! empty( $_COOKIE[ $sub_cookie ] ) ) {
            $url = add_query_arg( 'admbud_subtab', sanitize_key( wp_unslash( $_COOKIE[ $sub_cookie ] ) ), $url );
        }
        wp_safe_redirect( $url );
        exit;
    }

    /**
     * Read the active subtab from GET params or cookie (set by JS).
     * Allows PHP to render the correct pane on first paint, eliminating flicker.
     */
    /**
     * Return all registered tab slugs → labels.
     * Used by Search to build the dynamic index without duplicating the tab list.
     */
    public static function get_all_tab_labels(): array {
        return [
            'adminui'        => __( 'White Label',      'admin-buddy' ),
            'colours'        => __( 'Colours',           'admin-buddy' ),
            'login'          => __( 'Login',             'admin-buddy' ),
            'maintenance'    => __( 'Maintenance',       'admin-buddy' ),
            'snippets'       => __( 'Snippets',          'admin-buddy' ),
            'smtp'           => __( 'SMTP',              'admin-buddy' ),
            'roles'          => __( 'User Roles',        'admin-buddy' ),
            'modules'        => __( 'Modules',           'admin-buddy' ),
            'plugin-data'      => __( 'Plugin Data',        'admin-buddy' ),
            'quick-settings' => __( 'Quick Settings',    'admin-buddy' ),
        ];
    }

    public static function get_active_subtab( string $tab_slug, string $default ): string {
        // Read-only navigation state: returns which subtab to render. No state
        // change, no database writes, no nonce required. The value is
        // sanitize_key()'d before returning. Cookie fallback persists the user's
        // last-viewed subtab between requests.
        if ( ! empty( $_GET['admbud_subtab'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
            return sanitize_key( wp_unslash( $_GET['admbud_subtab'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
        }
        $cookie_key = 'admbud_subtab_' . $tab_slug;
        if ( ! empty( $_COOKIE[ $cookie_key ] ) ) {
            return sanitize_key( wp_unslash( $_COOKIE[ $cookie_key ] ) );
        }
        return $default;
    }

    /**
     * Enqueue assets that must be available on EVERY admin page, not just the AB settings page.
     *  - admin-buddy-icon-inject (script): provides window.AdmbudIcon.injectSidebarIcons(),
     *    called by inline scripts that each module appends via wp_add_inline_script().
     *  - admin-buddy-icon-inject (style): empty handle used as the attachment point for
     *    per-module inline CSS that targets the WP admin sidebar icons.
     */
    public function enqueue_global_assets(): void {
        wp_enqueue_script(
            'admin-buddy-icon-inject',
            ADMBUD_URL . 'assets/js/ab-icon-inject.js',
            [],
            ADMBUD_VERSION,
            true // footer
        );
        wp_register_style( 'admin-buddy-icon-inject', false, [], ADMBUD_VERSION );
        wp_enqueue_style( 'admin-buddy-icon-inject' );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'admin-buddy' ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'adminui'; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput

        // Legacy tab slug redirects.
        $legacy_map = [ 'core' => 'adminui', 'dashboard' => 'adminui', 'notices' => 'adminui', 'advanced' => 'plugin-data', 'tools' => 'plugin-data', 'datatools' => 'plugin-data', 'setup' => 'modules', 'tweaks' => 'quick-settings' ];
        if ( isset( $legacy_map[ $active_tab ] ) ) {
            $active_tab = $legacy_map[ $active_tab ];
            // If redirected from tools, pre-select the tools subtab.
            if ( ! isset( $_GET['admbud_subtab'] ) && ( $_GET['tab'] ?? '' ) === 'tools' ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification
                $_GET['admbud_subtab'] = 'tools'; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
            }
        }

        $tabs = [
            'adminui'     => [
                'label' => __( 'White Label',   'admin-buddy' ),
                'icon'  => '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>',
            ],
            'colours'     => [
                'label' => __( 'Colours',     'admin-buddy' ),
                'icon'  => '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="2" y="3" width="20" height="8" rx="2"/><path d="M16 11v2a2 2 0 0 1-2 2h-2"/><path d="M12 15v6"/><path d="M10 21h4"/></svg>',
            ],
            'login'       => [
                'label' => __( 'Login',       'admin-buddy' ),
                'icon'  => '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
            ],
            'maintenance' => [
                'label' => __( 'Maintenance', 'admin-buddy' ),
                'icon'  => '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
            ],
            'snippets'    => [
                'label' => __( 'Snippets',    'admin-buddy' ),
                'icon'  => '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
            ],
            'smtp'        => [
                'label' => __( 'SMTP',        'admin-buddy' ),
                'icon'  => '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
            ],
            'roles'       => [
                'label' => __( 'User Roles',  'admin-buddy' ),
                'icon'  => '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
                'keys'  => [ 'wp_user_roles' ],
                'warn'  => __( 'Importing roles will overwrite ALL role definitions. Use with care.', 'admin-buddy' ),
            ],
            'modules'     => [
                'group' => 'manage',
                'label' => __( 'Modules',     'admin-buddy' ),
                'icon'  => '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>',
            ],
            'plugin-data'        => [
                'group' => 'manage',
                'label' => __( 'Plugin Data',     'admin-buddy' ),
                'icon'  => '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>',
            ],
            'quick-settings'   => [
                'group' => 'manage',
                'label' => __( 'Quick Settings', 'admin-buddy' ),
                'icon'  => '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/><circle cx="8" cy="6" r="2"/><circle cx="16" cy="12" r="2"/><circle cx="10" cy="18" r="2"/></svg>',
            ],
        ];

        // Only show the License tab when the licensing SDK is present.
        if ( file_exists( ADMBUD_DIR . 'licensing/src/Client.php' ) ) {
            $tabs['license'] = [
                'group' => 'standalone',
                'label' => __( 'License', 'admin-buddy' ),
                'icon'  => '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
            ];
        }

        if ( ! array_key_exists( $active_tab, $tabs ) ) $active_tab = 'adminui';

        // Apply module visibility from Modules tab.
        // Standalone tabs (modules, plugin-data, license) and manage-group tabs (quick-settings, source)
        // are always kept; module tabs are filtered by enabled modules.
        $enabled_slugs = $this->get_enabled_tabs();
        $always_keep   = [ 'modules', 'plugin-data', 'license' ];
        foreach ( array_keys( $tabs ) as $slug ) {
            if ( in_array( $slug, $always_keep, true ) ) { continue; }
            if ( ! in_array( $slug, $enabled_slugs, true ) ) {
                unset( $tabs[ $slug ] );
            }
        }
        // Ensure active_tab still exists after filter.
        if ( ! array_key_exists( $active_tab, $tabs ) ) $active_tab = array_key_first( $tabs );

        $maintenance_mode = admbud_get_option( 'admbud_maintenance_mode', 'off' );
        // ab-loading hides the frame until JS confirms the correct tab - prevents flicker.
        $loading_class = empty( $_GET['tab'] ) ? ' ab-loading' : ''; // phpcs:ignore WordPress.Security.NonceVerification
        ?>
        <div class="wrap ab-wrap<?php echo esc_attr( $loading_class ); ?>">

            <div class="ab-topbar">
                <div class="ab-topbar__logo">
                    <svg style="display:block;" aria-label="Admin Buddy" role="img" width="160" height="32" viewBox="0 0 523 104" fill="none" xmlns="http://www.w3.org/2000/svg">
<path d="M72.6673 12.5636C77.0588 16.9537 79.4713 22.7818 79.4713 28.9882C79.4706 36.6772 74.8238 52.0878 56.2417 52.2178L46.4572 52.3242L46.4572 39.2322C51.8273 39.2607 65.2275 37.2966 66.0004 28.9885C66.0004 26.3862 64.9975 23.9201 63.1539 22.0502C61.3111 20.2069 58.8451 19.204 56.2154 19.204C51.0117 19.204 46.4572 23.7579 46.4572 28.9885L46.4572 39.2322L33.0188 39.2598L33.014 28.8534C33.014 22.7818 35.4268 16.954 39.8174 12.5636C44.2083 8.17217 50.0359 5.7597 56.2425 5.7597C62.4491 5.7597 68.277 8.17217 72.6673 12.5636Z" fill="currentColor"/>
<path d="M72.6673 78.9559C77.0588 74.5658 79.4713 68.7377 79.4713 62.5314C79.4706 54.8423 74.8238 39.4317 56.2417 39.3018L46.4572 39.1953L46.4572 52.2874C51.8273 52.2589 65.2275 54.2229 66.0004 62.5311C66.0004 65.1334 64.9975 67.5994 63.1539 69.4693C61.3111 71.3126 58.8451 72.3155 56.2154 72.3155C51.0117 72.3155 46.4572 67.7616 46.4572 62.5311L46.4572 52.2874L33.0188 52.2598L33.014 62.6662C33.014 68.7377 35.4268 74.5655 39.8174 78.9559C44.2083 83.3474 50.0359 85.7598 56.2425 85.7598C62.4491 85.7598 68.277 83.3474 72.6673 78.9559Z" fill="currentColor"/>
<path d="M6.80388 78.9559C2.41241 74.5658 -6.54719e-05 68.7377 -6.54719e-05 62.5314C0.00058112 54.8423 4.64741 39.4317 23.2295 39.3018L33.014 39.1953L33.014 52.2874C27.6439 52.2589 14.2437 54.2229 13.4708 62.5311C13.4708 65.1334 14.4737 67.5994 16.3173 69.4693C18.1601 71.3126 20.6261 72.3155 23.2558 72.3155C28.4595 72.3155 33.014 67.7616 33.014 62.5311L33.014 52.2874L46.4524 52.2598L46.4572 62.6662C46.4572 68.7377 44.0444 74.5655 39.6538 78.9559C35.2629 83.3474 29.4353 85.7598 23.2287 85.7598C17.0221 85.7598 11.1942 83.3474 6.80388 78.9559Z" fill="currentColor"/>
<path fill-rule="evenodd" clip-rule="evenodd" d="M-6.54719e-05 28.9882C-6.54719e-05 22.7818 2.41241 16.9537 6.80388 12.5636C11.1942 8.17217 17.0221 5.7597 23.2287 5.7597C29.4353 5.7597 35.2629 8.17217 39.6538 12.5636C44.0444 16.954 46.4572 22.7818 46.4572 28.8534L46.4524 39.2598L33.014 39.2322L33.014 28.9885C33.014 23.7579 28.4595 19.204 23.2558 19.204C20.6261 19.204 18.1601 20.2069 16.3173 22.0502C14.4737 23.9201 13.4708 26.3862 13.4708 28.9885C13.8292 32.8411 16.9029 35.3295 20.632 36.8876C12.0518 36.8876 6.3196 41.1417 4.52601 43.2687C1.13525 38.4735 0.000254063 32.788 -6.54719e-05 28.9882Z" fill="currentColor"/>
<rect x="33.0132" y="39.1953" width="13.4447" height="13.1302" fill="currentColor"/>
<path d="M135.282 57.9838V65.5358H113.01V57.9838H135.282ZM144.37 85.7598H135.538L123.634 12.2878L113.138 85.7598H104.306L116.722 6.14376H130.674L144.37 85.7598ZM157.653 56.9598H148.821V33.6638C148.821 30.2504 149.631 27.7331 151.253 26.1118C152.959 24.4051 155.519 23.5518 158.933 23.5518H164.053C166.271 23.5518 168.063 24.1491 169.429 25.3438C170.879 26.4531 171.605 28.4584 171.605 31.3598V40.0638H171.093V35.7118C171.093 34.0051 170.751 32.8104 170.069 32.1278C169.386 31.4451 168.277 31.1038 166.741 31.1038H161.621C159.999 31.1038 158.933 31.3598 158.421 31.8718C157.909 32.2984 157.653 33.3651 157.653 35.0718V56.9598ZM171.093 0.127762H179.925V85.7598H171.093V78.9758V78.0798V0.127762ZM148.821 52.2238H157.653V74.2398C157.653 75.8611 157.909 76.9278 158.421 77.4398C158.933 77.9518 159.999 78.2078 161.621 78.2078H166.741C168.277 78.2078 169.386 77.8664 170.069 77.1838C170.751 76.5011 171.093 75.3064 171.093 73.5998V70.6558L171.605 70.7838V77.9518C171.605 80.8531 170.879 82.9011 169.429 84.0958C168.063 85.2051 166.271 85.7598 164.053 85.7598H158.933C155.519 85.7598 152.959 84.9491 151.253 83.3278C149.631 81.6211 148.821 79.0611 148.821 75.6478V52.2238ZM220.005 85.7598H211.173V35.1998C211.173 33.4931 210.917 32.4264 210.405 31.9998C209.978 31.4878 208.911 31.2318 207.205 31.2318H202.085C200.549 31.2318 199.439 31.5731 198.757 32.2558C198.074 32.9384 197.733 34.1331 197.733 35.8398V40.1918H197.221V31.4878C197.221 28.5864 197.903 26.5811 199.269 25.4718C200.719 24.2771 202.554 23.6798 204.773 23.6798H209.893C213.306 23.6798 215.823 24.5331 217.445 26.2398C219.151 27.8611 220.005 30.3784 220.005 33.7918V85.7598ZM197.733 85.7598H188.901V23.6798H197.733V30.3358V31.2318V85.7598ZM242.277 85.7598H233.445V35.1998C233.445 33.4931 233.189 32.4264 232.677 31.9998C232.25 31.4878 231.183 31.2318 229.477 31.2318H224.357C222.821 31.2318 221.711 31.5731 221.029 32.2558C220.346 32.9384 220.005 34.1331 220.005 35.8398V40.1918H218.853V31.6158C218.853 28.7144 219.621 26.6664 221.157 25.4718C222.693 24.2771 224.655 23.6798 227.045 23.6798H232.165C235.578 23.6798 238.095 24.5331 239.717 26.2398C241.423 27.8611 242.277 30.3784 242.277 33.7918V85.7598ZM259.423 85.7598H250.591V23.6798H259.423V85.7598ZM250.335 10.3678V-0.000236511H259.679V10.3678H250.335ZM277.238 85.7598H268.406V23.6798H277.238V30.3358V31.2318V85.7598ZM299.51 85.7598H290.678V35.1998C290.678 33.4931 290.422 32.4264 289.91 31.9998C289.483 31.4878 288.416 31.2318 286.71 31.2318H281.59C280.054 31.2318 278.944 31.5731 278.262 32.2558C277.579 32.9384 277.238 34.1331 277.238 35.8398V40.1918H276.726V31.4878C276.726 28.5864 277.408 26.5811 278.774 25.4718C280.224 24.2771 282.059 23.6798 284.278 23.6798H289.398C292.811 23.6798 295.328 24.5331 296.95 26.2398C298.656 27.8611 299.51 30.3784 299.51 33.7918V85.7598ZM323.816 6.14376H349.032C352.445 6.14376 354.962 6.99709 356.584 8.70376C358.29 10.3251 359.144 12.8424 359.144 16.2558V39.1678C359.144 41.0451 358.717 42.5384 357.864 43.6478C357.01 44.6718 355.858 45.2691 354.408 45.4398C355.858 45.6958 357.01 46.2931 357.864 47.2318C358.717 48.0851 359.144 49.4504 359.144 51.3278V75.6478C359.144 79.0611 358.29 81.6211 356.584 83.3278C354.962 84.9491 352.445 85.7598 349.032 85.7598H323.816V6.14376ZM350.312 74.2398V52.8638C350.312 51.1571 350.056 50.0904 349.544 49.6638C349.117 49.1518 348.05 48.8958 346.344 48.8958H332.648V78.2078H346.344C348.05 78.2078 349.117 77.9518 349.544 77.4398C350.056 76.9278 350.312 75.8611 350.312 74.2398ZM350.312 37.3758V17.6638C350.312 15.9571 350.056 14.8904 349.544 14.4638C349.117 13.9518 348.05 13.6958 346.344 13.6958H332.648V41.3438H346.344C348.05 41.3438 349.117 41.0878 349.544 40.5758C350.056 40.0638 350.312 38.9971 350.312 37.3758ZM368.726 23.6798H377.558V74.2398C377.558 75.8611 377.814 76.9278 378.326 77.4398C378.838 77.9518 379.904 78.2078 381.526 78.2078H386.646C388.182 78.2078 389.291 77.8664 389.974 77.1838C390.656 76.5011 390.998 75.3064 390.998 73.5998V69.2478H391.51V77.9518C391.51 80.8531 390.784 82.9011 389.334 84.0958C387.968 85.2051 386.176 85.7598 383.958 85.7598H378.838C375.424 85.7598 372.864 84.9491 371.158 83.3278C369.536 81.6211 368.726 79.0611 368.726 75.6478V23.6798ZM390.998 23.6798H399.83V85.7598H390.998V79.1038V78.2078V23.6798ZM416.983 56.9598H408.151V33.6638C408.151 30.2504 408.961 27.7331 410.583 26.1118C412.289 24.4051 414.849 23.5518 418.263 23.5518H423.383C425.601 23.5518 427.393 24.1491 428.759 25.3438C430.209 26.4531 430.935 28.4584 430.935 31.3598V40.0638H430.423V35.7118C430.423 34.0051 430.081 32.8104 429.399 32.1278C428.716 31.4451 427.607 31.1038 426.071 31.1038H420.951C419.329 31.1038 418.263 31.3598 417.751 31.8718C417.239 32.2984 416.983 33.3651 416.983 35.0718V56.9598ZM430.423 0.127762H439.255V85.7598H430.423V78.9758V78.0798V0.127762ZM408.151 52.2238H416.983V74.2398C416.983 75.8611 417.239 76.9278 417.751 77.4398C418.263 77.9518 419.329 78.2078 420.951 78.2078H426.071C427.607 78.2078 428.716 77.8664 429.399 77.1838C430.081 76.5011 430.423 75.3064 430.423 73.5998V70.6558L430.935 70.7838V77.9518C430.935 80.8531 430.209 82.9011 428.759 84.0958C427.393 85.2051 425.601 85.7598 423.383 85.7598H418.263C414.849 85.7598 412.289 84.9491 410.583 83.3278C408.961 81.6211 408.151 79.0611 408.151 75.6478V52.2238ZM456.423 56.9598H447.591V33.6638C447.591 30.2504 448.401 27.7331 450.023 26.1118C451.729 24.4051 454.289 23.5518 457.703 23.5518H462.823C465.041 23.5518 466.833 24.1491 468.199 25.3438C469.649 26.4531 470.375 28.4584 470.375 31.3598V40.0638H469.863V35.7118C469.863 34.0051 469.521 32.8104 468.839 32.1278C468.156 31.4451 467.047 31.1038 465.511 31.1038H460.391C458.769 31.1038 457.703 31.3598 457.191 31.8718C456.679 32.2984 456.423 33.3651 456.423 35.0718V56.9598ZM469.863 0.127762H478.695V85.7598H469.863V78.9758V78.0798V0.127762ZM447.591 52.2238H456.423V74.2398C456.423 75.8611 456.679 76.9278 457.191 77.4398C457.703 77.9518 458.769 78.2078 460.391 78.2078H465.511C467.047 78.2078 468.156 77.8664 468.839 77.1838C469.521 76.5011 469.863 75.3064 469.863 73.5998V70.6558L470.375 70.7838V77.9518C470.375 80.8531 469.649 82.9011 468.199 84.0958C466.833 85.2051 465.041 85.7598 462.823 85.7598H457.703C454.289 85.7598 451.729 84.9491 450.023 83.3278C448.401 81.6211 447.591 79.0611 447.591 75.6478V52.2238ZM483.191 23.6798H492.023L502.007 79.6158L511.351 23.6798H520.183L507.767 93.6958C507.169 97.0238 505.847 99.5411 503.799 101.248C501.751 102.954 499.02 103.808 495.607 103.808H488.695V96.2558H492.919C494.625 96.2558 496.033 95.9998 497.143 95.4878C498.252 94.9758 498.977 93.9091 499.319 92.2878L500.855 85.7598H495.095L483.191 23.6798Z" fill="currentColor"/>
</svg>
                </div>
                <div class="ab-topbar__version">v<?php echo esc_html( ADMBUD_VERSION ); ?><?php
                    if ( function_exists( 'admbud_is_paid' ) && admbud_is_paid() ) {
                        echo ' <span class="ab-topbar__pro-label">Pro</span>';
                    }
                ?></div>

                <?php /* -- Updates Centre icon -- */ ?>
                <?php
                // Read update data directly from DB - bypasses any site_transient_update_* filters
                // that may have wiped $value->response (e.g. Hide Plugin/Theme Update Nag toggles).
                $admbud_updates_core    = [];
                $admbud_updates_plugins = [];
                $admbud_updates_themes  = [];

                if ( function_exists( 'get_core_updates' ) ) {
                    // get_core_updates() uses its own option, not affected by transient filters.
                    require_once ABSPATH . 'wp-admin/includes/update.php';
                    $core_updates = get_core_updates( [ 'dismissed' => false ] );
                    if ( is_array( $core_updates ) ) {
                        foreach ( $core_updates as $cu ) {
                            if ( isset( $cu->response ) && $cu->response === 'upgrade' ) {
                                $admbud_updates_core[] = [
                                    'current' => get_bloginfo( 'version' ),
                                    'new'     => $cu->current ?? '',
                                    'url'     => admin_url( 'update-core.php' ),
                                ];
                            }
                        }
                    }
                }

                // Read the plugin and theme update site-transients via the WordPress API.
                // If the transient is missing or stale (>12h), trigger a fresh check first.
                $raw_plugin_transient = get_site_transient( 'update_plugins' );
                $raw_theme_transient  = get_site_transient( 'update_themes' );


                $stale_threshold = time() - ( 12 * HOUR_IN_SECONDS );
                $plugins_stale   = ! $raw_plugin_transient
                                || ! is_object( $raw_plugin_transient )
                                || empty( $raw_plugin_transient->last_checked )
                                || $raw_plugin_transient->last_checked < $stale_threshold;
                $themes_stale    = ! $raw_theme_transient
                                || ! is_object( $raw_theme_transient )
                                || empty( $raw_theme_transient->last_checked )
                                || $raw_theme_transient->last_checked < $stale_threshold;

                if ( $plugins_stale || $themes_stale ) {
                    require_once ABSPATH . WPINC . '/update.php';
                    if ( $plugins_stale ) {
                        wp_update_plugins();
                        $raw_plugin_transient = get_site_transient( 'update_plugins' );
                    }
                    if ( $themes_stale ) {
                        wp_update_themes();
                        $raw_theme_transient = get_site_transient( 'update_themes' );
                    }
                }

                if ( $raw_plugin_transient && ! empty( $raw_plugin_transient->response ) ) {
                    if ( ! function_exists( 'get_plugin_data' ) ) {
                        require_once ABSPATH . 'wp-admin/includes/plugin.php';
                    }
                    foreach ( $raw_plugin_transient->response as $plugin_file => $plugin_data ) {
                        // get_plugin_data() requires an absolute path to ANOTHER plugin's
                        // main file; plugin_dir_path( __FILE__ ) returns Admin Buddy's
                        // own folder, not the plugins root, so WP_PLUGIN_DIR is the
                        // canonical way to resolve $plugin_file (e.g. "akismet/akismet.php").
                        $full_path   = WP_PLUGIN_DIR . '/' . $plugin_file;
                        $plugin_info = file_exists( $full_path ) ? get_plugin_data( $full_path, false, false ) : [];
                        $admbud_updates_plugins[] = [
                            'name'    => $plugin_info['Name'] ?? $plugin_file,
                            'current' => $plugin_info['Version'] ?? '',
                            'new'     => $plugin_data->new_version ?? '',
                            'url'     => admin_url( 'plugins.php' ),
                            'details' => $plugin_data->url ?? '',
                        ];
                    }
                }

                // Themes.
                if ( $raw_theme_transient && ! empty( $raw_theme_transient->response ) ) {
                    foreach ( $raw_theme_transient->response as $theme_slug => $theme_data ) {
                        $theme = wp_get_theme( $theme_slug );
                        $admbud_updates_themes[] = [
                            'name'    => $theme->get( 'Name' ) ?: $theme_slug,
                            'current' => $theme->get( 'Version' ) ?: '',
                            'new'     => $theme_data['new_version'] ?? '',
                            'url'     => admin_url( 'themes.php' ),
                        ];
                    }
                }

                $admbud_update_count = count( $admbud_updates_core ) + count( $admbud_updates_plugins ) + count( $admbud_updates_themes );
                ?>
                <button type="button" id="ab-updates-btn"
                        class="ab-topbar__updates-btn"
                        title="<?php
                            /* translators: %d: number of available updates */
                            echo $admbud_update_count > 0 ? esc_attr( sprintf( __( '%d update(s) available', 'admin-buddy' ), $admbud_update_count ) ) : esc_attr__( 'No updates available', 'admin-buddy' );
                        ?>"
                        aria-label="<?php esc_attr_e( 'Updates Centre', 'admin-buddy' ); ?>">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    <?php if ( $admbud_update_count > 0 ) : ?>
                    <span class="ab-topbar__updates-count"><?php echo (int) $admbud_update_count; ?></span>
                    <?php endif; ?>
                </button>

                <?php /* -- Status indicators -- */ ?>
                <?php
                $status_indicators = [];



                // Maintenance Mode active
                $admbud_maint_mode = admbud_get_option( 'admbud_maintenance_mode', 'off' );
                if ( $admbud_maint_mode === 'maintenance' ) {
                    $status_indicators[] = [
                        'label' => __( 'Maintenance', 'admin-buddy' ),
                        'class' => 'ab-badge--danger',
                        'tab'   => 'maintenance',
                        'title' => __( 'Maintenance Mode is active: visitors see a maintenance page', 'admin-buddy' ),
                        'icon'  => '<svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
                    ];
                }

                // Coming Soon active
                if ( $admbud_maint_mode === 'coming_soon' ) {
                    $status_indicators[] = [
                        'label' => __( 'Coming Soon', 'admin-buddy' ),
                        'class' => 'ab-badge--info',
                        'tab'   => 'maintenance',
                        'title' => __( 'Coming Soon mode is active: visitors see a coming soon page', 'admin-buddy' ),
                        'icon'  => '<svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
                    ];
                }

                // Search Engine Visibility discouraged
                if ( get_option( 'blog_public' ) === '0' ) {
                    $status_indicators[] = [
                        'label' => __( 'Noindex', 'admin-buddy' ),
                        'class' => 'ab-badge--warning',
                        'tab'   => '',
                        'title' => __( 'Search engines are discouraged from indexing this site (Settings → Reading)', 'admin-buddy' ),
                        'icon'  => '<svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="8" y1="11" x2="14" y2="11"/></svg>',
                        'url'   => admin_url( 'options-reading.php' ),
                    ];
                }


                // Checklist pill - always rendered. Opens the same
                // off-canvas panel that the WP admin bar pill opens, via
                // the data-ab-checklist-open hook in checklist-panel.js.
                //
                // Severity-aware rendering MUST mirror Checklist::add_bar_node()
                // exactly so the two pills never disagree. Count shown reflects
                // the worst tier only — critical first, warning only when no
                // critical exists — so "Checklist (3)" means the same thing
                // in both the WP admin bar and the AB topbar.
                $admbud_checklist_counts = [ 'critical' => 0, 'warning' => 0 ];
                if ( class_exists( '\\Admbud\\Checklist' ) ) {
                    $admbud_checklist_counts = \Admbud\Checklist::get_instance()->counts();
                }
                if ( $admbud_checklist_counts['critical'] > 0 ) {
                    $admbud_checklist_class = 'ab-badge--danger';
                    /* translators: %d: number of critical issues */
                    $admbud_checklist_label = sprintf( _n( 'Checklist (%d)', 'Checklist (%d)', $admbud_checklist_counts['critical'], 'admin-buddy' ), $admbud_checklist_counts['critical'] );
                } elseif ( $admbud_checklist_counts['warning'] > 0 ) {
                    $admbud_checklist_class = 'ab-badge--warning';
                    /* translators: %d: number of warnings */
                    $admbud_checklist_label = sprintf( _n( 'Checklist (%d)', 'Checklist (%d)', $admbud_checklist_counts['warning'], 'admin-buddy' ), $admbud_checklist_counts['warning'] );
                } else {
                    $admbud_checklist_class = 'ab-badge--success';
                    $admbud_checklist_label = __( 'Checklist', 'admin-buddy' );
                }
                // Checkmark-in-square icon for the Checklist badge.
                $admbud_checklist_icon = '<svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>';
                ?>
                <div class="ab-topbar__status">
                    <button type="button"
                            class="ab-badge <?php echo esc_attr( $admbud_checklist_class ); ?> ab-topbar__status-badge"
                            data-ab-checklist-open="1"
                            title="<?php esc_attr_e( 'Open Checklist', 'admin-buddy' ); ?>">
                        <?php echo $admbud_checklist_icon; // phpcs:ignore -- trusted internal SVG ?>
                        <?php echo esc_html( $admbud_checklist_label ); ?>
                    </button>
                    <?php foreach ( $status_indicators as $ind ) :
                        $url = ! empty( $ind['url'] ) ? $ind['url'] : ( ! empty( $ind['tab'] ) ? admin_url( 'admin.php?page=admin-buddy&tab=' . $ind['tab'] ) : '' );
                    ?>
                        <?php if ( $url ) : ?>
                        <a href="<?php echo esc_url( $url ); ?>"
                           class="ab-badge <?php echo esc_attr( $ind['class'] ); ?> ab-topbar__status-badge"
                           title="<?php echo esc_attr( $ind['title'] ); ?>">
                            <?php echo $ind['icon']; // phpcs:ignore -- trusted internal SVG ?>
                            <?php echo esc_html( $ind['label'] ); ?>
                        </a>
                        <?php else : ?>
                        <span class="ab-badge <?php echo esc_attr( $ind['class'] ); ?> ab-topbar__status-badge"
                              title="<?php echo esc_attr( $ind['title'] ); ?>">
                            <?php echo $ind['icon']; // phpcs:ignore -- trusted internal SVG ?>
                            <?php echo esc_html( $ind['label'] ); ?>
                        </span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>

                <div class="ab-topbar__actions" id="ab-topbar-actions">
                    <?php /* Populated via JS when the active tab has a saveable form */ ?>
                </div>

            </div>

            <?php /* -- Updates Centre slide panel -- */ ?>
            <div class="ab-backdrop" id="ab-updates-backdrop" style="display:none;" aria-hidden="true"></div>
            <div class="ab-slide-panel ab-slide-panel--sm" id="ab-updates-panel"
                 role="dialog" aria-modal="true" aria-labelledby="ab-updates-panel-title"
                 style="display:none;">
                <div class="ab-slide-panel__header">
                    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    <h3 id="ab-updates-panel-title" class="ab-slide-panel__title"><?php esc_html_e( 'Updates Centre', 'admin-buddy' ); ?></h3>
                    <button type="button" id="ab-updates-panel-close" class="ab-slide-panel__close" aria-label="<?php esc_attr_e( 'Close', 'admin-buddy' ); ?>">
                        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="ab-slide-panel__body" id="ab-updates-panel-body">
                    <?php if ( $admbud_update_count === 0 ) : ?>
                    <div style="text-align:center;padding:var(--ab-space-10) var(--ab-space-5);color:var(--ab-text-muted);">
                        <svg width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.25" style="display:block;margin:0 auto var(--ab-space-4);opacity:0.3;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        <p style="font-size:var(--ab-text-sm);margin:0;"><?php esc_html_e( 'Everything is up to date.', 'admin-buddy' ); ?></p>
                    </div>
                    <?php else : ?>

                    <?php if ( ! empty( $admbud_updates_core ) ) : ?>
                    <div class="ab-updates-section">
                        <p class="ab-updates-section__title"><?php esc_html_e( 'WordPress Core', 'admin-buddy' ); ?></p>
                        <?php foreach ( $admbud_updates_core as $u ) : ?>
                        <div class="ab-updates-item">
                            <div class="ab-updates-item__info">
                                <span class="ab-updates-item__name">WordPress</span>
                                <span class="ab-updates-item__versions"><?php echo esc_html( $u['current'] ); ?> → <strong><?php echo esc_html( $u['new'] ); ?></strong></span>
                            </div>
                            <a href="<?php echo esc_url( $u['url'] ); ?>" class="ab-btn ab-btn--primary ab-btn--sm" target="_blank"><?php esc_html_e( 'Update', 'admin-buddy' ); ?></a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ( ! empty( $admbud_updates_plugins ) ) : ?>
                    <div class="ab-updates-section">
                        <p class="ab-updates-section__title"><?php
                            /* translators: %d: number of plugin updates */
                            printf( esc_html__( 'Plugins (%d)', 'admin-buddy' ), count( $admbud_updates_plugins ) );
                        ?></p>
                        <?php foreach ( $admbud_updates_plugins as $u ) : ?>
                        <div class="ab-updates-item">
                            <div class="ab-updates-item__info">
                                <span class="ab-updates-item__name"><?php echo esc_html( $u['name'] ); ?></span>
                                <span class="ab-updates-item__versions"><?php echo esc_html( $u['current'] ); ?> → <strong><?php echo esc_html( $u['new'] ); ?></strong></span>
                            </div>
                            <div style="display:flex;gap:var(--ab-space-2);flex-shrink:0;">
                                <?php if ( $u['details'] ) : ?>
                                <a href="<?php echo esc_url( $u['details'] ); ?>" class="ab-btn ab-btn--secondary ab-btn--sm" target="_blank"><?php esc_html_e( 'Details', 'admin-buddy' ); ?></a>
                                <?php endif; ?>
                                <a href="<?php echo esc_url( $u['url'] ); ?>" class="ab-btn ab-btn--primary ab-btn--sm" target="_blank"><?php esc_html_e( 'Update', 'admin-buddy' ); ?></a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ( ! empty( $admbud_updates_themes ) ) : ?>
                    <div class="ab-updates-section">
                        <p class="ab-updates-section__title"><?php
                            /* translators: %d: number of theme updates */
                            printf( esc_html__( 'Themes (%d)', 'admin-buddy' ), count( $admbud_updates_themes ) );
                        ?></p>
                        <?php foreach ( $admbud_updates_themes as $u ) : ?>
                        <div class="ab-updates-item">
                            <div class="ab-updates-item__info">
                                <span class="ab-updates-item__name"><?php echo esc_html( $u['name'] ); ?></span>
                                <span class="ab-updates-item__versions"><?php echo esc_html( $u['current'] ); ?> → <strong><?php echo esc_html( $u['new'] ); ?></strong></span>
                            </div>
                            <a href="<?php echo esc_url( $u['url'] ); ?>" class="ab-btn ab-btn--primary ab-btn--sm" target="_blank"><?php esc_html_e( 'Update', 'admin-buddy' ); ?></a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php endif; ?>
                </div>
                <div class="ab-slide-panel__footer">
                    <a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>" class="ab-btn ab-btn--secondary ab-btn--sm" target="_blank">
                        <?php esc_html_e( 'Go to Updates page', 'admin-buddy' ); ?>
                    </a>
                </div>
            </div>

            <?php
            // Updates Centre slide-panel handler — attached to the global
            // admin-buddy-core handle so it's part of the same enqueue cycle
            // as the markup that triggers it.
            wp_add_inline_script(
                'admin-buddy-core',
                '(function(){var btn=document.getElementById("ab-updates-btn");var panel=document.getElementById("ab-updates-panel");var backdrop=document.getElementById("ab-updates-backdrop");var closeBtn=document.getElementById("ab-updates-panel-close");function openPanel(){if(!panel||!backdrop)return;panel.style.display="";backdrop.style.display="";requestAnimationFrame(function(){requestAnimationFrame(function(){panel.classList.add("is-open");backdrop.classList.add("is-open");document.body.classList.add("ab-modal-open");});});}function closePanel(){if(!panel)return;panel.classList.remove("is-open");backdrop.classList.remove("is-open");document.body.classList.remove("ab-modal-open");setTimeout(function(){if(!panel.classList.contains("is-open")){panel.style.display="none";backdrop.style.display="none";}},300);}if(btn)btn.addEventListener("click",openPanel);if(closeBtn)closeBtn.addEventListener("click",closePanel);if(backdrop)backdrop.addEventListener("click",closePanel);document.addEventListener("keydown",function(e){if(e.key==="Escape"&&panel&&panel.classList.contains("is-open"))closePanel();});})();'
            );
            ?>

            <div class="ab-frame">

                <nav class="ab-nav" aria-label="<?php esc_attr_e( 'Settings navigation', 'admin-buddy' ); ?>">
                    <?php
                    $manageable       = $this->get_manageable_tabs();
                    $standalone_slugs = [ 'modules', 'plugin-data', 'license' ];
                    $module_tabs      = array_diff_key( $tabs, array_flip( $standalone_slugs ) );
                    $has_modules      = ! empty( $module_tabs );
                    ?>
                    <?php if ( $has_modules ) : ?>
                    <div class="ab-nav__toggle-all">
                        <button type="button" class="ab-nav__toggle-btn" id="ab-nav-expand-all" title="<?php esc_attr_e( 'Expand all', 'admin-buddy' ); ?>">
                            <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                            <?php esc_html_e( 'Expand', 'admin-buddy' ); ?>
                        </button>
                        <button type="button" class="ab-nav__toggle-btn" id="ab-nav-collapse-all" title="<?php esc_attr_e( 'Collapse all', 'admin-buddy' ); ?>">
                            <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="6 15 12 9 18 15"/></svg>
                            <?php esc_html_e( 'Collapse', 'admin-buddy' ); ?>
                        </button>
                    </div>
                    <?php endif; ?>
                    <?php

                    // Determine which group the active tab belongs to.
                    $active_group = 'standalone';
                    if ( in_array( $active_tab, $standalone_slugs, true ) ) {
                        $active_group = 'standalone';
                    } elseif ( isset( $manageable[ $active_tab ] ) ) {
                        $active_group = $manageable[ $active_tab ]['group'] ?? 'interface';
                    }

                    $nav_groups = [
                        'interface'    => __( 'Interface',    'admin-buddy' ),
                        'utilities'    => __( 'Utilities',    'admin-buddy' ),
                        'integrations' => __( 'Integrations', 'admin-buddy' ),
                        'manage'       => __( 'Manage',       'admin-buddy' ),
                    ];

                    // License state for pro badge visibility in nav.
                    $is_paid_nav = function_exists( 'admbud_is_paid' ) && admbud_is_paid();

                    // -- Collapsible groups (Interface, Utilities, Integrations, Manage) --
                    // All groups follow the same pattern: filter by enabled modules.
                    foreach ( $nav_groups as $group_key => $group_label ) :
                        $group_tabs = array_filter( $module_tabs, function( $slug ) use ( $group_key, $manageable ) {
                            return isset( $manageable[ $slug ] ) && ( $manageable[ $slug ]['group'] ?? '' ) === $group_key;
                        }, ARRAY_FILTER_USE_KEY );
                        if ( empty( $group_tabs ) ) { continue; }
                        $is_open = ( $active_group === $group_key );
                    ?>
                    <details class="ab-nav__group" <?php echo $is_open ? 'open' : ''; ?>
                             data-group="<?php echo esc_attr( $group_key ); ?>">
                        <summary class="ab-nav__group-label">
                            <span class="ab-nav__group-chevron" aria-hidden="true">
                                <svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                            </span>
                            <span><?php echo esc_html( $group_label ); ?></span>
                        </summary>
                        <?php foreach ( $group_tabs as $slug => $tab ) :
                            $is_active = ( $active_tab === $slug );
                            $url       = admin_url( 'admin.php?page=admin-buddy&tab=' . $slug );
                            // Pro-locked sidebar items (Remote for free users).
                            $is_nav_pro_locked = ( ( $slug === 'source' || $slug === 'export-import' ) && ! $is_paid_nav );
                        ?>
                            <?php if ( $is_nav_pro_locked ) : ?>
                            <span class="ab-nav__item" style="opacity:0.5;pointer-events:none;cursor:default;">
                                <span class="ab-nav__icon"><?php echo admbud_kses_svg( $tab['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                <span class="ab-nav__label"><?php echo esc_html( $tab['label'] ); ?></span>
                                <span class="ab-badge ab-badge--pro" style="font-size:9px;padding:1px 5px;margin-left:auto;">Pro</span>
                            </span>
                            <?php else : ?>
                            <a href="<?php echo esc_url( $url ); ?>"
                               class="ab-nav__item<?php echo $is_active ? ' is-active' : ''; ?>"
                               <?php if ( $is_active ) echo 'aria-current="page"'; ?>>
                                <span class="ab-nav__icon"><?php echo admbud_kses_svg( $tab['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                <span class="ab-nav__label"><?php echo esc_html( $tab['label'] ); ?></span>
                            </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </details>
                    <?php endforeach; ?>

                    <?php
                    // -- Standalone items BELOW groups (Modules, Plugin Data, License) --
                    // All three always visible (Plugin Data too - it's the plugin's own data manager).
                    $standalone_visible = $standalone_slugs;
                    // Only show license tab when SDK is present (already filtered by $tabs).
                    ?>
                    <?php if ( $has_modules ) : ?>
                    <div class="ab-nav__divider" aria-hidden="true"></div>
                    <?php endif; ?>
                    <?php foreach ( $standalone_visible as $s_slug ) :
                        if ( ! isset( $tabs[ $s_slug ] ) ) { continue; }
                        $s_tab     = $tabs[ $s_slug ];
                        $s_active  = ( $active_tab === $s_slug );
                        $s_url     = admin_url( 'admin.php?page=admin-buddy&tab=' . $s_slug );
                    ?>
                        <a href="<?php echo esc_url( $s_url ); ?>"
                           class="ab-nav__item ab-nav__item--standalone<?php echo $s_active ? ' is-active' : ''; ?>"
                           <?php if ( $s_active ) echo 'aria-current="page"'; ?>>
                            <span class="ab-nav__icon"><?php echo admbud_kses_svg( $s_tab['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                            <span class="ab-nav__label"><?php echo esc_html( $s_tab['label'] ); ?></span>
                        </a>
                    <?php endforeach; ?>

                </nav>

                <div class="ab-canvas">
                    <?php
                    switch ( $active_tab ) {
                        case 'adminui':     $this->render_tab_adminui();     break;
                        case 'colours':     $this->render_tab_colours();     break;
                        case 'login':       $this->render_tab_login();        break;
                        case 'maintenance': $this->render_tab_maintenance();  break;
                        case 'snippets':    $this->render_tab_snippets();     break;
                        case 'smtp':        $this->render_tab_smtp();         break;
                        case 'roles':       $this->render_tab_roles();        break;
                        case 'modules':       $this->render_tab_modules();        break;
                        case 'plugin-data':     $this->render_tab_plugin_data();      break;
                        case 'quick-settings':     $this->render_tab_quick_settings(); break;
                    }
                    ?>
                </div>

            </div>
        </div>

        <?php /* -- Confirmation modal (shared across all tabs, populated via JS) -- */ ?>
        <div id="ab-confirm-modal" class="ab-modal ab-modal--confirm ab-hidden" role="dialog" aria-modal="true"
             aria-labelledby="ab-modal-title" aria-describedby="ab-modal-body">
            <div class="ab-modal__backdrop"></div>
            <div class="ab-modal__box">
                <div class="ab-modal__header">
                    <h3 id="ab-modal-title" class="ab-modal__title"></h3>
                    <button type="button" id="ab-modal-close-x" class="ab-modal__close" aria-label="<?php esc_attr_e( 'Close', 'admin-buddy' ); ?>">
                        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <p id="ab-modal-body" class="ab-modal__body"></p>
                <div class="ab-modal__actions">
                    <button type="button" id="ab-modal-cancel" class="ab-btn ab-btn--secondary">
                        <?php esc_html_e( 'Cancel', 'admin-buddy' ); ?>
                    </button>
                    <button type="button" id="ab-modal-confirm" class="ab-btn ab-btn--danger"
                            data-default-label="<?php esc_attr_e( 'Yes, proceed', 'admin-buddy' ); ?>">
                        <?php esc_html_e( 'Yes, proceed', 'admin-buddy' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
        // -- Toast container (shared, always rendered) --------------------------
        echo '<div id="ab-toast" aria-live="polite" aria-atomic="false"></div>';
    }



    // ============================================================================
    // TAB: COLOURS
    // ============================================================================

    private function render_tab_colours(): void {
        $settings = $this; // passed to render file as $settings
        require ADMBUD_DIR . 'includes/render-tab-colours.php';
    }

    // ============================================================================
    // TAB: UI TWEAKS
    // ============================================================================
    // TAB: UI TWEAKS
    // ============================================================================

    private function render_tab_adminui(): void {
        $settings = $this; // passed to render file as $settings
        require ADMBUD_DIR . 'includes/render-tab-adminui.php';
    }

    // ============================================================================
    // ============================================================================
    // TAB: LOGIN
    // ============================================================================

    private function render_tab_login(): void {
        $settings = $this; // passed to render file as $settings
        require ADMBUD_DIR . 'includes/render-tab-login.php';
    }
    // ============================================================================
    // TAB: MAINTENANCE
    // ============================================================================

    private function render_tab_maintenance(): void {
        $settings = $this; // passed to render file as $settings
        require ADMBUD_DIR . 'includes/render-tab-maintenance.php';
    }
    // ============================================================================
    // TAB: SNIPPETS
    // ============================================================================

    private function render_tab_snippets(): void {
        require_once ADMBUD_DIR . 'includes/render-tab-snippets.php';
    }

    // ============================================================================
    // TAB: SMTP
    // ============================================================================

    private function render_tab_smtp(): void {
        require_once ADMBUD_DIR . 'includes/render-tab-smtp.php';
    }

    // ============================================================================
    // TAB: USER ROLES
    // ============================================================================

    private function render_tab_roles(): void {
        require_once ADMBUD_DIR . 'includes/render-tab-roles.php';
    }



    /**
     * Returns the list of tabs that the user can toggle on/off in Setup.
     * 'modules' itself is always shown and is excluded from this list.
     */
    private function get_manageable_tabs(): array {
        return [
            // -- Core ---------------------------------------------------------
            'adminui'      => [ 'group' => 'interface',         'label' => __( 'White Label',       'admin-buddy' ), 'icon' => '<svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>' ],
            'colours'      => [ 'group' => 'interface',         'label' => __( 'Colours',         'admin-buddy' ), 'icon' => '<svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="2" y="3" width="20" height="8" rx="2"/><path d="M16 11v2a2 2 0 0 1-2 2h-2"/><path d="M12 15v6"/><path d="M10 21h4"/></svg>' ],
            'login'        => [ 'group' => 'interface',         'label' => __( 'Login',           'admin-buddy' ), 'icon' => '<svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>' ],
            'maintenance'  => [ 'group' => 'interface',         'label' => __( 'Maintenance',     'admin-buddy' ), 'icon' => '<svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>' ],
            'smtp'         => [ 'group' => 'utilities',         'label' => __( 'SMTP',            'admin-buddy' ), 'icon' => '<svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>' ],
            // -- Utilities ----------------------------------------------------
            'snippets'     => [ 'group' => 'utilities',    'label' => __( 'Snippets',        'admin-buddy' ), 'icon' => '<svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>' ],
            'roles'        => [ 'group' => 'utilities',    'label' => __( 'User Roles',      'admin-buddy' ), 'icon' => '<svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>' ],
            // -- Manage -------------------------------------------------------
            'quick-settings' => [ 'group' => 'manage', 'label' => __( 'Quick Settings', 'admin-buddy' ), 'icon' => '<svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/><circle cx="8" cy="6" r="2"/><circle cx="16" cy="12" r="2"/><circle cx="10" cy="18" r="2"/></svg>' ],
        ];
    }

    /**
     * Returns the set of enabled tab slugs.
     * Empty stored value = first launch = nothing enabled (only Setup tab shows).
     * After first save, we store the explicit list (may be empty string for "all off").
     */
    /**
     * AJAX handler - instantly saves the enabled-tab list when a Setup toggle changes.
     * Expects POST: nonce, slug (tab slug), enabled ('1' or '0').
     */
    public function ajax_setup_toggle(): void {
        check_ajax_referer( 'admbud_modules_toggle', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $slug    = sanitize_key( wp_unslash( $_POST['slug']    ?? '' ) );
        $enabled = ( sanitize_text_field( wp_unslash( $_POST['enabled'] ?? '0' ) ) === '1' );
        $valid   = array_keys( $this->get_manageable_tabs() );

        if ( ! in_array( $slug, $valid, true ) ) {
            wp_send_json_error( [ 'message' => 'Invalid slug' ], 400 );
        }

        // Pro tier check: block Pro-only modules for unpaid users.
        // Free modules are always activatable regardless of license state.
        if ( $enabled ) {
            $allowed = function_exists( 'admbud_allowed_modules' ) ? admbud_allowed_modules() : $valid;
            if ( ! in_array( $slug, $allowed, true ) ) {
                wp_send_json_error( [ 'message' => __( 'This module requires a Pro license.', 'admin-buddy' ), 'reason' => 'pro_required' ], 403 );
            }
        }

        // Fetch current list, toggle the slug, save.
        $current = $this->get_enabled_tabs();
        if ( $enabled ) {
            if ( ! in_array( $slug, $current, true ) ) {
                $current[] = $slug;
            }
        } else {
            $current = array_values( array_filter( $current, fn( $s ) => $s !== $slug ) );
        }

        // Store as comma-separated string. Empty string = nothing enabled.
        admbud_update_option( 'admbud_modules_enabled_tabs', implode( ',', $current ) );

        wp_send_json_success( [ 'enabled_tabs' => $current ] );
    }

    /**
     * AJAX handler - enable/disable all modules in a group atomically (single read/write).
     */
    public function ajax_setup_group_toggle(): void {
        check_ajax_referer( 'admbud_modules_toggle', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $group   = sanitize_key( wp_unslash( $_POST['group']   ?? '' ) );
        $enabled = ( sanitize_text_field( wp_unslash( $_POST['enabled'] ?? '0' ) ) === '1' );
        $all     = $this->get_manageable_tabs();
        $allowed = function_exists( 'admbud_allowed_modules' ) ? admbud_allowed_modules() : array_keys( $all );
        $group_slugs = array_keys( array_filter( $all, function ( $tab ) use ( $group ) {
            return ( $tab['group'] ?? '' ) === $group;
        } ) );
        if ( empty( $group_slugs ) ) {
            wp_send_json_error( [ 'message' => 'Unknown group' ], 400 );
        }
        $current = $this->get_enabled_tabs();
        foreach ( $group_slugs as $slug ) {
            // Skip Pro modules for free tier when enabling.
            if ( $enabled && ! in_array( $slug, $allowed, true ) ) { continue; }
            if ( $enabled ) {
                if ( ! in_array( $slug, $current, true ) ) { $current[] = $slug; }
            } else {
                $current = array_values( array_filter( $current, fn( $s ) => $s !== $slug ) );
            }
        }
        admbud_update_option( 'admbud_modules_enabled_tabs', implode( ',', $current ) );
        wp_send_json_success( [ 'enabled_tabs' => $current ] );
    }

    /**
     * AJAX handler - bulk enable/disable all modules at once.
     * Expects POST: nonce, enabled ('1' or '0').
     */
    public function ajax_setup_bulk_toggle(): void {
        check_ajax_referer( 'admbud_modules_toggle', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $enabled = ( sanitize_text_field( wp_unslash( $_POST['enabled'] ?? '0' ) ) === '1' );
        $valid   = array_keys( $this->get_manageable_tabs() );
        $allowed = function_exists( 'admbud_allowed_modules' ) ? admbud_allowed_modules() : $valid;

        // Enable All only enables allowed modules (free users skip Pro modules).
        $new_list = $enabled ? $allowed : [];
        admbud_update_option( 'admbud_modules_enabled_tabs', implode( ',', $new_list ) );

        wp_send_json_success( [ 'enabled_tabs' => $new_list ] );
    }

    /**
     * AJAX: toggle a single Quick Setting on or off.
     * POST: nonce, key (option key), value ('1' or '0').
     */
    public function ajax_qs_toggle(): void {
        check_ajax_referer( 'admbud_qs_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $valid_keys = [
            'admbud_qs_disable_emoji', 'admbud_qs_disable_jquery_migrate',
            'admbud_qs_remove_feed_links', 'admbud_qs_remove_rsd', 'admbud_qs_remove_wlw',
            'admbud_qs_remove_shortlink', 'admbud_qs_remove_restapi_link', 'admbud_qs_disable_embeds',
            'admbud_qs_remove_version',
            'admbud_qs_disable_xmlrpc', 'admbud_qs_disable_rest_api', 'admbud_qs_disable_file_edit',
            'admbud_qs_disable_feeds', 'admbud_qs_disable_self_ping', 'admbud_qs_disable_comments_default',
            'admbud_qs_duplicate_post', 'admbud_qs_user_last_seen', 'admbud_qs_allow_svg',
            'admbud_qs_hide_adminbar_frontend', 'admbud_qs_hide_adminbar_backend', 'admbud_qs_collapse_menu',
            'admbud_qs_hide_adminbar_checklist', 'admbud_qs_hide_adminbar_noindex',
            'admbud_qs_sidebar_user_menu',
            'admbud_notices_suppress',
        ];

        $key   = sanitize_key( wp_unslash( $_POST['key']   ?? '' ) );
        $value = ( sanitize_text_field( wp_unslash( $_POST['value'] ?? '0' ) ) === '1' ) ? '1' : '0';

        if ( ! in_array( $key, $valid_keys, true ) ) {
            wp_send_json_error( [ 'message' => 'Invalid key' ], 400 );
        }

        // When Hide Admin Bar (Backend) flips ON and the sidebar user menu is
        // currently OFF, auto-enable it so the user keeps a way to navigate
        // (the sidebar user menu is also where the Visit Site link lives when
        // the admin bar is hidden). Role list is copied so the same audience
        // who lose the admin bar gain the replacement nav. The user can still
        // toggle it off afterwards — we don't track dissent.
        $auto_enabled_user_menu = false;
        if (
            $key === 'admbud_qs_hide_adminbar_backend'
            && $value === '1'
            && get_option( 'admbud_qs_sidebar_user_menu', '0' ) === '0'
        ) {
            $copy_roles = (string) get_option( 'admbud_qs_hide_adminbar_backend_roles', 'administrator' );
            update_option( 'admbud_qs_sidebar_user_menu', '1' );
            update_option( 'admbud_qs_sidebar_user_menu_roles', $copy_roles );
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- admbud_ is the plugin prefix.
            do_action( 'admbud_qs_toggled', 'admbud_qs_sidebar_user_menu', '1' );
            $auto_enabled_user_menu = true;
        }

        update_option( $key, $value );
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- admbud_ is the plugin prefix.
        do_action( 'admbud_qs_toggled', $key, $value );
        wp_send_json_success( [
            'key'          => $key,
            'value'        => $value,
            'auto_enabled' => $auto_enabled_user_menu ? 'admbud_qs_sidebar_user_menu' : null,
        ] );
    }

    /**
     * AJAX: set all Quick Settings to on or off at once.
     * POST: nonce, value ('1' or '0').
     */
    public function ajax_qs_bulk(): void {
        check_ajax_referer( 'admbud_qs_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $value = ( sanitize_text_field( wp_unslash( $_POST['value'] ?? '0' ) ) === '1' ) ? '1' : '0';
        $keys  = [
            'admbud_qs_disable_emoji', 'admbud_qs_disable_jquery_migrate',
            'admbud_qs_remove_feed_links', 'admbud_qs_remove_rsd', 'admbud_qs_remove_wlw',
            'admbud_qs_remove_shortlink', 'admbud_qs_remove_restapi_link', 'admbud_qs_disable_embeds',
            'admbud_qs_remove_version',
            'admbud_qs_disable_xmlrpc', 'admbud_qs_disable_rest_api', 'admbud_qs_disable_file_edit',
            'admbud_qs_disable_feeds', 'admbud_qs_disable_self_ping', 'admbud_qs_disable_comments_default',
            'admbud_qs_duplicate_post', 'admbud_qs_user_last_seen', 'admbud_qs_allow_svg',
            'admbud_notices_suppress',
        ];

        foreach ( $keys as $key ) {
            update_option( $key, $value );
        }

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- admbud_ is the plugin prefix.
        do_action( 'admbud_qs_bulk_toggled', $value );
        wp_send_json_success( [ 'value' => $value ] );
    }

    /**
     * AJAX: save SVG upload allowed roles.
     * POST: nonce, roles (comma-separated role slugs).
     */
    /**
     * AJAX: save roles for any Quick Settings toggle that has per-role control.
     * POST: nonce, key (option key), roles (comma-separated role slugs).
     */
    public function ajax_qs_save_roles(): void {
        check_ajax_referer( 'admbud_qs_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        // Whitelist of keys that support per-role control.
        $valid_keys = [
            'admbud_qs_allow_svg',
            'admbud_qs_hide_adminbar_frontend',
            'admbud_qs_hide_adminbar_backend',
            'admbud_qs_hide_adminbar_checklist',
            'admbud_qs_hide_adminbar_noindex',
            'admbud_qs_collapse_menu',
            'admbud_qs_sidebar_user_menu',
        ];

        $key = sanitize_key( wp_unslash( $_POST['key'] ?? '' ) );

        // Backward compat: old admbud_qs_svg_roles calls don't send key.
        if ( empty( $key ) ) { $key = 'admbud_qs_allow_svg'; }

        if ( ! in_array( $key, $valid_keys, true ) ) {
            wp_send_json_error( [ 'message' => 'Invalid key' ], 400 );
        }

        $raw   = sanitize_text_field( wp_unslash( $_POST['roles'] ?? '' ) );
        $roles = array_filter( array_map( 'sanitize_key', explode( ',', $raw ) ) );

        // Validate against actual registered roles.
        $valid_roles = array_keys( wp_roles()->roles );
        $roles       = array_values( array_intersect( $roles, $valid_roles ) );

        update_option( $key . '_roles', implode( ',', $roles ) );
        wp_send_json_success( [ 'roles' => $roles ] );
    }

    public function ajax_apply_preset(): void {
        check_ajax_referer( 'admbud_apply_preset', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
        $slug    = sanitize_key( wp_unslash( $_POST['preset'] ?? '' ) );
        $presets = $this->get_colour_presets();
        if ( ! isset( $presets[ $slug ] ) ) {
            wp_send_json_error( [ 'message' => 'Unknown preset' ], 400 );
        }
        $allowed_prefixes = [ 'admbud_colours_', 'admbud_login_bg', 'admbud_login_grad', 'admbud_cs_', 'admbud_maint_' ];
        foreach ( $presets[ $slug ]['values'] as $key => $val ) {
            $ok = false;
            foreach ( $allowed_prefixes as $pfx ) {
                if ( strpos( $key, $pfx ) === 0 ) { $ok = true; break; }
            }
            if ( $ok ) { update_option( $key, sanitize_text_field( $val ) ); }
        }
        $this->clear_bricks_overrides();
        // Ensure Admin Bar Flyout Bg always matches Admin Bar Bg.
        $adminbar_bg = admbud_get_option( 'admbud_colours_adminbar_bg', '' );
        if ( $adminbar_bg ) { admbud_update_option( 'admbud_colours_adminbar_submenu_bg', $adminbar_bg ); }
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- admbud_ is the plugin prefix.
        do_action( 'admbud_colour_preset_applied', $slug, $presets[ $slug ]['label'] ?? $slug );
        wp_send_json_success( [ 'preset' => $slug ] );
    }

    /**
     * Clear Bricks builder colour overrides so they re-derive from the new palette.
     * Called after preset or palette application.
     */
    private function clear_bricks_overrides(): void {
        $bricks_keys = [
            'admbud_bricks_builder_bg', 'admbud_bricks_builder_bg_2', 'admbud_bricks_builder_bg_3',
            'admbud_bricks_builder_bg_accent', 'admbud_bricks_builder_color',
            'admbud_bricks_builder_color_description', 'admbud_bricks_builder_color_accent',
            'admbud_bricks_builder_color_accent_inverse', 'admbud_bricks_builder_color_knob',
            'admbud_bricks_builder_border_color', 'admbud_bricks_bricks_tooltip_bg',
            'admbud_bricks_bricks_tooltip_text',
        ];
        foreach ( $bricks_keys as $key ) {
            delete_option( $key );
        }
    }

    /**
     * AJAX: Apply generated palette values directly to the database.
     * Used by the palette generator to save login/maintenance/coming-soon
     * colours that don't exist on the Colours form.
     */
    public function ajax_apply_palette(): void {
        check_ajax_referer( 'admbud_apply_preset', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
        $raw = wp_unslash( $_POST['values'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            wp_send_json_error( [ 'message' => 'Invalid data' ], 400 );
        }
        $allowed_prefixes = [ 'admbud_colours_', 'admbud_login_', 'admbud_cs_', 'admbud_maint_' ];
        $count = 0;
        foreach ( $data as $key => $val ) {
            $ok = false;
            foreach ( $allowed_prefixes as $pfx ) {
                if ( strpos( $key, $pfx ) === 0 ) { $ok = true; break; }
            }
            if ( $ok ) {
                update_option( sanitize_key( $key ), sanitize_text_field( $val ) );
                $count++;
            }
        }
        $this->clear_bricks_overrides();
        // Ensure Admin Bar Flyout Bg always matches Admin Bar Bg.
        $adminbar_bg = admbud_get_option( 'admbud_colours_adminbar_bg', '' );
        if ( $adminbar_bg ) { admbud_update_option( 'admbud_colours_adminbar_submenu_bg', $adminbar_bg ); }
        Colours::maybe_bust_cache();
        wp_send_json_success( [ 'count' => $count ] );
    }

    public function get_colour_presets(): array {
        return [
            'violety' => [
                'label'       => 'Violety',
                'description' => 'Deep violet sidebar with rich purple gradient. The default Admin Buddy palette.',
                'swatches'    => [ '#7c3aed', '#6d28d9', '#1e1b2e', '#2e1065', '#ede9fe' ],
                'values'      => [
                    'admbud_colours_primary'                 => '#7c3aed',
                    'admbud_colours_secondary'               => '#6d28d9',
                    'admbud_colours_hover_bg'                => '#6d28d9',
                    'admbud_colours_active_bg'               => '#7c3aed',
                    'admbud_colours_menu_text'               => '#ede9fe',
                    'admbud_colours_menu_bg'                 => '#1e1b2e',
                    'admbud_colours_active_text'             => '#ffffff',
                    'admbud_colours_sep_color'               => '#5600ed',
                    'admbud_colours_body_bg'                 => '#ffffff',
                    'admbud_colours_menu_item_sep'           => '1',
                    'admbud_colours_sidebar_gradient'        => '1',
                    'admbud_colours_sidebar_grad_dir'        => 'to top',
                    'admbud_colours_sidebar_grad_from'       => '#2e1065',
                    'admbud_colours_sidebar_grad_to'         => '#1e1b2e',
                    'admbud_colours_submenu_text'            => '#ede9fe',
                    'admbud_colours_hover_text'              => '#ede9fe',
                    'admbud_colours_active_parent_text'      => '#ffffff',
                    'admbud_colours_submenu_bg'              => '#7c3aed',
                    'admbud_colours_submenu_hover_bg'        => '#7c3aed',
                    'admbud_colours_submenu_hover_text'      => '#ede9fe',
                    'admbud_colours_submenu_active_bg'       => '#7c3aed',
                    'admbud_colours_submenu_active_text'     => '#ede9fe',
                    'admbud_colours_adminbar_bg'             => '#1a1828',
                    'admbud_colours_adminbar_text'           => '#d4d1e0',
                    'admbud_colours_adminbar_hover_bg'       => '#7c3aed',
                    'admbud_colours_adminbar_submenu_bg'     => '#1a1828',
                    'admbud_colours_adminbar_hover_text'     => '#ffffff',
                    'admbud_colours_adminbar_sub_text'       => '#d4d1e0',
                    'admbud_colours_adminbar_sub_hover_bg'   => '#7c3aed',
                    'admbud_colours_adminbar_sub_hover_text' => '#ffffff',
                    'admbud_colours_shadow_colour'           => '',
                    'admbud_colours_pill_maintenance'        => '#dd3333',
                    'admbud_colours_pill_coming_soon'        => '#dd3333',
                    'admbud_colours_pill_noindex'            => '#dd9933',
                    'admbud_colours_pill_admin_buddy'        => '#7c3aed',
                    // Login page
                    'admbud_login_bg_type'                   => 'solid',
                    'admbud_login_bg_color'                  => '#1e1b2e',
                    'admbud_login_grad_from'                 => '#2e1065',
                    'admbud_login_grad_to'                   => '#1e1b2e',
                    'admbud_login_grad_direction'            => 'to bottom right',
                    // Coming Soon page
                    'admbud_cs_bg_type'                      => 'gradient',
                    'admbud_cs_bg_color'                     => '#1e1b2e',
                    'admbud_cs_grad_from'                    => '#2e1065',
                    'admbud_cs_grad_to'                      => '#1e1b2e',
                    'admbud_cs_grad_direction'               => 'to bottom right',
                    'admbud_cs_text_color'                   => '#ede9fe',
                    'admbud_cs_message_color'                => '#c4b5fd',
                    // Maintenance page
                    'admbud_maint_bg_type'                   => 'gradient',
                    'admbud_maint_bg_color'                  => '#1e1b2e',
                    'admbud_maint_grad_from'                 => '#2e1065',
                    'admbud_maint_grad_to'                   => '#1e1b2e',
                    'admbud_maint_grad_direction'            => 'to bottom right',
                    'admbud_maint_text_color'                => '#ede9fe',
                    'admbud_maint_message_color'             => '#c4b5fd',
                    // Content area
                    'admbud_colours_content_heading' => '#1d2327',
                    'admbud_colours_content_text' => '#3c434a',
                    'admbud_colours_content_link' => '#7c3aed',
                    'admbud_colours_content_link_hover' => '#6d28d9',
                    'admbud_colours_table_header_bg' => '#f4effd',
                    'admbud_colours_table_header_text' => '#1d2327',
                    'admbud_colours_table_header_link' => '#1d2327',
                    'admbud_colours_table_row_bg' => '#ffffff',
                    'admbud_colours_table_row_text' => '#3c434a',
                    'admbud_colours_table_row_alt_bg' => '#f9f7fe',
                    'admbud_colours_table_row_alt_text' => '#3c434a',
                    'admbud_colours_table_row_hover' => '#efe7fc',
                    'admbud_colours_table_border' => '#decdfa',
                    'admbud_colours_table_row_separator' => '#ebe1fc',
                    'admbud_colours_table_title_link' => '#7c3aed',
                    'admbud_colours_table_action_link' => '#7c3aed',
                    'admbud_colours_input_bg' => '#ffffff',
                    'admbud_colours_input_border' => '#d1baf8',
                    'admbud_colours_input_focus' => '#7c3aed',
                    'admbud_colours_btn_primary_bg' => '#7c3aed',
                    'admbud_colours_btn_primary_text' => '#ffffff',
                    'admbud_colours_btn_primary_hover' => '#6d28d9',
                    'admbud_colours_btn_secondary_bg' => '#f7f3fd',
                    'admbud_colours_postbox_bg' => '#ffffff',
                    'admbud_colours_postbox_header_bg' => '#f9f7fe',
                    'admbud_colours_postbox_border' => '#decdfa',
                    'admbud_colours_notice_bg' => '#fbf9fe',
                ],
            ],
            'tealy' => [
                'label'       => 'Tealy',
                'description' => 'Fresh teal sidebar with deep ocean gradient.',
                'swatches'    => [ '#0d9488', '#0f766e', '#0d1e1e', '#042f2e', '#ccfbf1' ],
                'values'      => [
                    'admbud_colours_primary'                 => '#0d9488',
                    'admbud_colours_secondary'               => '#0f766e',
                    'admbud_colours_hover_bg'                => '#0f766e',
                    'admbud_colours_active_bg'               => '#0d9488',
                    'admbud_colours_menu_text'               => '#ccfbf1',
                    'admbud_colours_menu_bg'                 => '#0d1e1e',
                    'admbud_colours_active_text'             => '#ffffff',
                    'admbud_colours_sep_color'               => '#0d9488',
                    'admbud_colours_body_bg'                 => '#ffffff',
                    'admbud_colours_menu_item_sep'           => '1',
                    'admbud_colours_sidebar_gradient'        => '1',
                    'admbud_colours_sidebar_grad_dir'        => 'to top',
                    'admbud_colours_sidebar_grad_from'       => '#042f2e',
                    'admbud_colours_sidebar_grad_to'         => '#0d1e1e',
                    'admbud_colours_submenu_text'            => '#ccfbf1',
                    'admbud_colours_hover_text'              => '#ccfbf1',
                    'admbud_colours_active_parent_text'      => '#ffffff',
                    'admbud_colours_submenu_bg'              => '#0d9488',
                    'admbud_colours_submenu_hover_bg'        => '#0d9488',
                    'admbud_colours_submenu_hover_text'      => '#ccfbf1',
                    'admbud_colours_submenu_active_bg'       => '#0d9488',
                    'admbud_colours_submenu_active_text'     => '#ccfbf1',
                    'admbud_colours_adminbar_bg'             => '#0b1a1a',
                    'admbud_colours_adminbar_text'           => '#bbded7',
                    'admbud_colours_adminbar_hover_bg'       => '#0d9488',
                    'admbud_colours_adminbar_submenu_bg'     => '#0b1a1a',
                    'admbud_colours_adminbar_hover_text'     => '#ffffff',
                    'admbud_colours_adminbar_sub_text'       => '#bbded7',
                    'admbud_colours_adminbar_sub_hover_bg'   => '#0d9488',
                    'admbud_colours_adminbar_sub_hover_text' => '#ffffff',
                    'admbud_colours_shadow_colour'           => '',
                    'admbud_colours_pill_maintenance'        => '#dd3333',
                    'admbud_colours_pill_coming_soon'        => '#dd3333',
                    'admbud_colours_pill_noindex'            => '#dd9933',
                    'admbud_colours_pill_admin_buddy'        => '#0d9488',
                    // Login page
                    'admbud_login_bg_type'                   => 'solid',
                    'admbud_login_bg_color'                  => '#0d1e1e',
                    'admbud_login_grad_from'                 => '#042f2e',
                    'admbud_login_grad_to'                   => '#0d1e1e',
                    'admbud_login_grad_direction'            => 'to bottom right',
                    // Coming Soon page
                    'admbud_cs_bg_type'                      => 'gradient',
                    'admbud_cs_bg_color'                     => '#0d1e1e',
                    'admbud_cs_grad_from'                    => '#042f2e',
                    'admbud_cs_grad_to'                      => '#0d1e1e',
                    'admbud_cs_grad_direction'               => 'to bottom right',
                    'admbud_cs_text_color'                   => '#ccfbf1',
                    'admbud_cs_message_color'                => '#99f6e4',
                    // Maintenance page
                    'admbud_maint_bg_type'                   => 'gradient',
                    'admbud_maint_bg_color'                  => '#0d1e1e',
                    'admbud_maint_grad_from'                 => '#042f2e',
                    'admbud_maint_grad_to'                   => '#0d1e1e',
                    'admbud_maint_grad_direction'            => 'to bottom right',
                    'admbud_maint_text_color'                => '#ccfbf1',
                    'admbud_maint_message_color'             => '#99f6e4',
                    // Content area
                    'admbud_colours_content_heading' => '#1d2327',
                    'admbud_colours_content_text' => '#3c434a',
                    'admbud_colours_content_link' => '#0d9488',
                    'admbud_colours_content_link_hover' => '#0f766e',
                    'admbud_colours_table_header_bg' => '#ebf6f5',
                    'admbud_colours_table_header_text' => '#1d2327',
                    'admbud_colours_table_header_link' => '#1d2327',
                    'admbud_colours_table_row_bg' => '#ffffff',
                    'admbud_colours_table_row_text' => '#3c434a',
                    'admbud_colours_table_row_alt_bg' => '#f5fafa',
                    'admbud_colours_table_row_alt_text' => '#3c434a',
                    'admbud_colours_table_row_hover' => '#e1f2f0',
                    'admbud_colours_table_border' => '#c2e4e1',
                    'admbud_colours_table_row_separator' => '#daeeed',
                    'admbud_colours_table_title_link' => '#0d9488',
                    'admbud_colours_table_action_link' => '#0d9488',
                    'admbud_colours_input_bg' => '#ffffff',
                    'admbud_colours_input_border' => '#aad9d5',
                    'admbud_colours_input_focus' => '#0d9488',
                    'admbud_colours_btn_primary_bg' => '#0d9488',
                    'admbud_colours_btn_primary_text' => '#ffffff',
                    'admbud_colours_btn_primary_hover' => '#0f766e',
                    'admbud_colours_btn_secondary_bg' => '#f0f8f7',
                    'admbud_colours_postbox_bg' => '#ffffff',
                    'admbud_colours_postbox_header_bg' => '#f5fafa',
                    'admbud_colours_postbox_border' => '#c2e4e1',
                    'admbud_colours_notice_bg' => '#f7fbfb',
                ],
            ],
            'rosy' => [
                'label'       => 'Rosy',
                'description' => 'Bold rose-crimson sidebar with deep warm gradient.',
                'swatches'    => [ '#e11d48', '#be123c', '#1e0d14', '#4c0519', '#ffe4e6' ],
                'values'      => [
                    'admbud_colours_primary'                 => '#e11d48',
                    'admbud_colours_secondary'               => '#be123c',
                    'admbud_colours_hover_bg'                => '#be123c',
                    'admbud_colours_active_bg'               => '#e11d48',
                    'admbud_colours_menu_text'               => '#ffe4e6',
                    'admbud_colours_menu_bg'                 => '#1e0d14',
                    'admbud_colours_active_text'             => '#ffffff',
                    'admbud_colours_sep_color'               => '#e11d48',
                    'admbud_colours_body_bg'                 => '#ffffff',
                    'admbud_colours_menu_item_sep'           => '1',
                    'admbud_colours_sidebar_gradient'        => '1',
                    'admbud_colours_sidebar_grad_dir'        => 'to top',
                    'admbud_colours_sidebar_grad_from'       => '#4c0519',
                    'admbud_colours_sidebar_grad_to'         => '#1e0d14',
                    'admbud_colours_submenu_text'            => '#ffe4e6',
                    'admbud_colours_hover_text'              => '#ffe4e6',
                    'admbud_colours_active_parent_text'      => '#ffffff',
                    'admbud_colours_submenu_bg'              => '#e11d48',
                    'admbud_colours_submenu_hover_bg'        => '#e11d48',
                    'admbud_colours_submenu_hover_text'      => '#ffe4e6',
                    'admbud_colours_submenu_active_bg'       => '#e11d48',
                    'admbud_colours_submenu_active_text'     => '#ffe4e6',
                    'admbud_colours_adminbar_bg'             => '#1a0b12',
                    'admbud_colours_adminbar_text'           => '#e1cdce',
                    'admbud_colours_adminbar_hover_bg'       => '#e11d48',
                    'admbud_colours_adminbar_submenu_bg'     => '#1a0b12',
                    'admbud_colours_adminbar_hover_text'     => '#ffffff',
                    'admbud_colours_adminbar_sub_text'       => '#e1cdce',
                    'admbud_colours_adminbar_sub_hover_bg'   => '#e11d48',
                    'admbud_colours_adminbar_sub_hover_text' => '#ffffff',
                    'admbud_colours_shadow_colour'           => '',
                    'admbud_colours_pill_maintenance'        => '#dd3333',
                    'admbud_colours_pill_coming_soon'        => '#dd3333',
                    'admbud_colours_pill_noindex'            => '#dd9933',
                    'admbud_colours_pill_admin_buddy'        => '#e11d48',
                    // Login page
                    'admbud_login_bg_type'                   => 'solid',
                    'admbud_login_bg_color'                  => '#1e0d14',
                    'admbud_login_grad_from'                 => '#4c0519',
                    'admbud_login_grad_to'                   => '#1e0d14',
                    'admbud_login_grad_direction'            => 'to bottom right',
                    // Coming Soon page
                    'admbud_cs_bg_type'                      => 'gradient',
                    'admbud_cs_bg_color'                     => '#1e0d14',
                    'admbud_cs_grad_from'                    => '#4c0519',
                    'admbud_cs_grad_to'                      => '#1e0d14',
                    'admbud_cs_grad_direction'               => 'to bottom right',
                    'admbud_cs_text_color'                   => '#ffe4e6',
                    'admbud_cs_message_color'                => '#fecdd3',
                    // Maintenance page
                    'admbud_maint_bg_type'                   => 'gradient',
                    'admbud_maint_bg_color'                  => '#1e0d14',
                    'admbud_maint_grad_from'                 => '#4c0519',
                    'admbud_maint_grad_to'                   => '#1e0d14',
                    'admbud_maint_grad_direction'            => 'to bottom right',
                    'admbud_maint_text_color'                => '#ffe4e6',
                    'admbud_maint_message_color'             => '#fecdd3',
                    // Content area
                    'admbud_colours_content_heading' => '#1d2327',
                    'admbud_colours_content_text' => '#3c434a',
                    'admbud_colours_content_link' => '#e11d48',
                    'admbud_colours_content_link_hover' => '#be123c',
                    'admbud_colours_table_header_bg' => '#fcecf0',
                    'admbud_colours_table_header_text' => '#1d2327',
                    'admbud_colours_table_header_link' => '#1d2327',
                    'admbud_colours_table_row_bg' => '#ffffff',
                    'admbud_colours_table_row_text' => '#3c434a',
                    'admbud_colours_table_row_alt_bg' => '#fdf5f7',
                    'admbud_colours_table_row_alt_text' => '#3c434a',
                    'admbud_colours_table_row_hover' => '#fbe3e9',
                    'admbud_colours_table_border' => '#f7c6d1',
                    'admbud_colours_table_row_separator' => '#fadde3',
                    'admbud_colours_table_title_link' => '#e11d48',
                    'admbud_colours_table_action_link' => '#e11d48',
                    'admbud_colours_input_bg' => '#ffffff',
                    'admbud_colours_input_border' => '#f4afbe',
                    'admbud_colours_input_focus' => '#e11d48',
                    'admbud_colours_btn_primary_bg' => '#e11d48',
                    'admbud_colours_btn_primary_text' => '#ffffff',
                    'admbud_colours_btn_primary_hover' => '#be123c',
                    'admbud_colours_btn_secondary_bg' => '#fdf1f4',
                    'admbud_colours_postbox_bg' => '#ffffff',
                    'admbud_colours_postbox_header_bg' => '#fdf5f7',
                    'admbud_colours_postbox_border' => '#f7c6d1',
                    'admbud_colours_notice_bg' => '#fef8f9',
                ],
            ],
            'navy' => [
                'label'       => 'Navy',
                'description' => 'Classic navy-blue sidebar with deep ocean gradient. Professional and timeless.',
                'swatches'    => [ '#2563eb', '#1d4ed8', '#0f172a', '#1e3a5f', '#dbeafe' ],
                'values'      => [
                    'admbud_colours_primary'                 => '#2563eb',
                    'admbud_colours_secondary'               => '#1d4ed8',
                    'admbud_colours_hover_bg'                => '#1d4ed8',
                    'admbud_colours_active_bg'               => '#2563eb',
                    'admbud_colours_menu_text'               => '#dbeafe',
                    'admbud_colours_menu_bg'                 => '#0f172a',
                    'admbud_colours_active_text'             => '#ffffff',
                    'admbud_colours_sep_color'               => '#2563eb',
                    'admbud_colours_body_bg'                 => '#ffffff',
                    'admbud_colours_menu_item_sep'           => '1',
                    'admbud_colours_sidebar_gradient'        => '1',
                    'admbud_colours_sidebar_grad_dir'        => 'to top',
                    'admbud_colours_sidebar_grad_from'       => '#1e3a5f',
                    'admbud_colours_sidebar_grad_to'         => '#0f172a',
                    'admbud_colours_submenu_text'            => '#dbeafe',
                    'admbud_colours_hover_text'              => '#dbeafe',
                    'admbud_colours_active_parent_text'      => '#ffffff',
                    'admbud_colours_submenu_bg'              => '#2563eb',
                    'admbud_colours_submenu_hover_bg'        => '#2563eb',
                    'admbud_colours_submenu_hover_text'      => '#dbeafe',
                    'admbud_colours_submenu_active_bg'       => '#2563eb',
                    'admbud_colours_submenu_active_text'     => '#dbeafe',
                    'admbud_colours_adminbar_bg'             => '#0d1425',
                    'admbud_colours_adminbar_text'           => '#c6d2e0',
                    'admbud_colours_adminbar_hover_bg'       => '#2563eb',
                    'admbud_colours_adminbar_submenu_bg'     => '#0d1425',
                    'admbud_colours_adminbar_hover_text'     => '#ffffff',
                    'admbud_colours_adminbar_sub_text'       => '#c6d2e0',
                    'admbud_colours_adminbar_sub_hover_bg'   => '#2563eb',
                    'admbud_colours_adminbar_sub_hover_text' => '#ffffff',
                    'admbud_colours_shadow_colour'           => '',
                    'admbud_colours_pill_maintenance'        => '#dd3333',
                    'admbud_colours_pill_coming_soon'        => '#dd3333',
                    'admbud_colours_pill_noindex'            => '#dd9933',
                    'admbud_colours_pill_admin_buddy'        => '#2563eb',
                    // Login page
                    'admbud_login_bg_type'                   => 'solid',
                    'admbud_login_bg_color'                  => '#0f172a',
                    'admbud_login_grad_from'                 => '#1e3a5f',
                    'admbud_login_grad_to'                   => '#0f172a',
                    'admbud_login_grad_direction'            => 'to bottom right',
                    // Coming Soon page
                    'admbud_cs_bg_type'                      => 'gradient',
                    'admbud_cs_bg_color'                     => '#0f172a',
                    'admbud_cs_grad_from'                    => '#1e3a5f',
                    'admbud_cs_grad_to'                      => '#0f172a',
                    'admbud_cs_grad_direction'               => 'to bottom right',
                    'admbud_cs_text_color'                   => '#dbeafe',
                    'admbud_cs_message_color'                => '#93c5fd',
                    // Maintenance page
                    'admbud_maint_bg_type'                   => 'gradient',
                    'admbud_maint_bg_color'                  => '#0f172a',
                    'admbud_maint_grad_from'                 => '#1e3a5f',
                    'admbud_maint_grad_to'                   => '#0f172a',
                    'admbud_maint_grad_direction'            => 'to bottom right',
                    'admbud_maint_text_color'                => '#dbeafe',
                    'admbud_maint_message_color'             => '#93c5fd',
                    // Content area
                    'admbud_colours_content_heading' => '#1d2327',
                    'admbud_colours_content_text' => '#3c434a',
                    'admbud_colours_content_link' => '#2563eb',
                    'admbud_colours_content_link_hover' => '#1d4ed8',
                    'admbud_colours_table_header_bg' => '#edf2fd',
                    'admbud_colours_table_header_text' => '#1d2327',
                    'admbud_colours_table_header_link' => '#1d2327',
                    'admbud_colours_table_row_bg' => '#ffffff',
                    'admbud_colours_table_row_text' => '#3c434a',
                    'admbud_colours_table_row_alt_bg' => '#f6f8fe',
                    'admbud_colours_table_row_alt_text' => '#3c434a',
                    'admbud_colours_table_row_hover' => '#e4ecfc',
                    'admbud_colours_table_border' => '#c8d8fa',
                    'admbud_colours_table_row_separator' => '#dee7fc',
                    'admbud_colours_table_title_link' => '#2563eb',
                    'admbud_colours_table_action_link' => '#2563eb',
                    'admbud_colours_input_bg' => '#ffffff',
                    'admbud_colours_input_border' => '#b2c8f8',
                    'admbud_colours_input_focus' => '#2563eb',
                    'admbud_colours_btn_primary_bg' => '#2563eb',
                    'admbud_colours_btn_primary_text' => '#ffffff',
                    'admbud_colours_btn_primary_hover' => '#1d4ed8',
                    'admbud_colours_btn_secondary_bg' => '#f1f5fd',
                    'admbud_colours_postbox_bg' => '#ffffff',
                    'admbud_colours_postbox_header_bg' => '#f6f8fe',
                    'admbud_colours_postbox_border' => '#c8d8fa',
                    'admbud_colours_notice_bg' => '#f8fafe',
                ],
            ],
            'blacky' => [
                'label'       => 'Blacky',
                'description' => 'Dark charcoal sidebar with deep gray gradient. Sleek and minimal.',
                'swatches'    => [ '#555555', '#444444', '#1a1a1a', '#2a2a2a', '#e5e5e5' ],
                'values'      => [
                    'admbud_colours_primary'                 => '#555555',
                    'admbud_colours_secondary'               => '#444444',
                    'admbud_colours_hover_bg'                => '#444444',
                    'admbud_colours_active_bg'               => '#555555',
                    'admbud_colours_menu_text'               => '#e5e5e5',
                    'admbud_colours_menu_bg'                 => '#1a1a1a',
                    'admbud_colours_active_text'             => '#ffffff',
                    'admbud_colours_sep_color'               => '#555555',
                    'admbud_colours_body_bg'                 => '#ffffff',
                    'admbud_colours_menu_item_sep'           => '1',
                    'admbud_colours_sidebar_gradient'        => '1',
                    'admbud_colours_sidebar_grad_dir'        => 'to top',
                    'admbud_colours_sidebar_grad_from'       => '#2a2a2a',
                    'admbud_colours_sidebar_grad_to'         => '#1a1a1a',
                    'admbud_colours_submenu_text'            => '#d0d0d0',
                    'admbud_colours_hover_text'              => '#e5e5e5',
                    'admbud_colours_active_parent_text'      => '#ffffff',
                    'admbud_colours_submenu_bg'              => '#555555',
                    'admbud_colours_submenu_hover_bg'        => '#555555',
                    'admbud_colours_submenu_hover_text'      => '#ffffff',
                    'admbud_colours_submenu_active_bg'       => '#555555',
                    'admbud_colours_submenu_active_text'     => '#ffffff',
                    'admbud_colours_adminbar_bg'             => '#141414',
                    'admbud_colours_adminbar_text'           => '#c8c8c8',
                    'admbud_colours_adminbar_hover_bg'       => '#555555',
                    'admbud_colours_adminbar_submenu_bg'     => '#141414',
                    'admbud_colours_adminbar_hover_text'     => '#ffffff',
                    'admbud_colours_adminbar_sub_text'       => '#c8c8c8',
                    'admbud_colours_adminbar_sub_hover_bg'   => '#555555',
                    'admbud_colours_adminbar_sub_hover_text' => '#ffffff',
                    'admbud_colours_shadow_colour'           => '',
                    'admbud_colours_pill_maintenance'        => '#dd3333',
                    'admbud_colours_pill_coming_soon'        => '#dd3333',
                    'admbud_colours_pill_noindex'            => '#dd9933',
                    'admbud_colours_pill_admin_buddy'        => '#555555',
                    // Login page
                    'admbud_login_bg_type'                   => 'solid',
                    'admbud_login_bg_color'                  => '#1a1a1a',
                    'admbud_login_grad_from'                 => '#2a2a2a',
                    'admbud_login_grad_to'                   => '#1a1a1a',
                    'admbud_login_grad_direction'            => 'to bottom right',
                    // Coming Soon page
                    'admbud_cs_bg_type'                      => 'gradient',
                    'admbud_cs_bg_color'                     => '#1a1a1a',
                    'admbud_cs_grad_from'                    => '#2a2a2a',
                    'admbud_cs_grad_to'                      => '#1a1a1a',
                    'admbud_cs_grad_direction'               => 'to bottom right',
                    'admbud_cs_text_color'                   => '#e5e5e5',
                    'admbud_cs_message_color'                => '#b0b0b0',
                    // Maintenance page
                    'admbud_maint_bg_type'                   => 'gradient',
                    'admbud_maint_bg_color'                  => '#1a1a1a',
                    'admbud_maint_grad_from'                 => '#2a2a2a',
                    'admbud_maint_grad_to'                   => '#1a1a1a',
                    'admbud_maint_grad_direction'            => 'to bottom right',
                    'admbud_maint_text_color'                => '#e5e5e5',
                    'admbud_maint_message_color'             => '#b0b0b0',
                    // Content area
                    'admbud_colours_content_heading' => '#1d2327',
                    'admbud_colours_content_text' => '#3c434a',
                    'admbud_colours_content_link' => '#555555',
                    'admbud_colours_content_link_hover' => '#333333',
                    'admbud_colours_table_header_bg' => '#f0f0f0',
                    'admbud_colours_table_header_text' => '#1d2327',
                    'admbud_colours_table_header_link' => '#1d2327',
                    'admbud_colours_table_row_bg' => '#ffffff',
                    'admbud_colours_table_row_text' => '#3c434a',
                    'admbud_colours_table_row_alt_bg' => '#f7f7f7',
                    'admbud_colours_table_row_alt_text' => '#3c434a',
                    'admbud_colours_table_row_hover' => '#eeeeee',
                    'admbud_colours_table_border' => '#d5d5d5',
                    'admbud_colours_table_row_separator' => '#e5e5e5',
                    'admbud_colours_table_title_link' => '#555555',
                    'admbud_colours_table_action_link' => '#555555',
                    'admbud_colours_input_bg' => '#ffffff',
                    'admbud_colours_input_border' => '#c0c0c0',
                    'admbud_colours_input_focus' => '#555555',
                    'admbud_colours_btn_primary_bg' => '#555555',
                    'admbud_colours_btn_primary_text' => '#ffffff',
                    'admbud_colours_btn_primary_hover' => '#444444',
                    'admbud_colours_btn_secondary_bg' => '#f5f5f5',
                    'admbud_colours_postbox_bg' => '#ffffff',
                    'admbud_colours_postbox_header_bg' => '#f7f7f7',
                    'admbud_colours_postbox_border' => '#d5d5d5',
                    'admbud_colours_notice_bg' => '#fafafa',
                ],
            ],
            'whitey' => [
                'label'       => 'Whitey',
                'description' => 'Clean light sidebar with white and soft gray tones. Airy and modern.',
                'swatches'    => [ '#4b5563', '#374151', '#ffffff', '#f3f4f6', '#1f2937' ],
                'values'      => [
                    'admbud_colours_primary'                 => '#4b5563',
                    'admbud_colours_secondary'               => '#374151',
                    'admbud_colours_hover_bg'                => '#374151',
                    'admbud_colours_active_bg'               => '#4b5563',
                    'admbud_colours_menu_text'               => '#374151',
                    'admbud_colours_menu_bg'                 => '#ffffff',
                    'admbud_colours_active_text'             => '#ffffff',
                    'admbud_colours_sep_color'               => '#e5e7eb',
                    'admbud_colours_body_bg'                 => '#ffffff',
                    'admbud_colours_menu_item_sep'           => '1',
                    'admbud_colours_sidebar_gradient'        => '1',
                    'admbud_colours_sidebar_grad_dir'        => 'to bottom',
                    'admbud_colours_sidebar_grad_from'       => '#f3f4f6',
                    'admbud_colours_sidebar_grad_to'         => '#ffffff',
                    'admbud_colours_submenu_text'            => '#f3f4f6',
                    'admbud_colours_hover_text'              => '#ffffff',
                    'admbud_colours_active_parent_text'      => '#ffffff',
                    'admbud_colours_submenu_bg'              => '#4b5563',
                    'admbud_colours_submenu_hover_bg'        => '#e5e7eb',
                    'admbud_colours_submenu_hover_text'      => '#111827',
                    'admbud_colours_submenu_active_bg'       => '#4b5563',
                    'admbud_colours_submenu_active_text'     => '#ffffff',
                    'admbud_colours_adminbar_bg'             => '#f3f4f6',
                    'admbud_colours_adminbar_text'           => '#4b5563',
                    'admbud_colours_adminbar_hover_bg'       => '#f3f4f6',
                    'admbud_colours_adminbar_submenu_bg'     => '#f3f4f6',
                    'admbud_colours_adminbar_hover_text'     => '#111827',
                    'admbud_colours_adminbar_sub_text'       => '#4b5563',
                    'admbud_colours_adminbar_sub_hover_bg'   => '#f3f4f6',
                    'admbud_colours_adminbar_sub_hover_text' => '#111827',
                    'admbud_colours_shadow_colour'           => '#d1d5db',
                    'admbud_colours_pill_maintenance'        => '#dc2626',
                    'admbud_colours_pill_coming_soon'        => '#dc2626',
                    'admbud_colours_pill_noindex'            => '#d97706',
                    'admbud_colours_pill_admin_buddy'        => '#4b5563',
                    // Login page
                    'admbud_login_bg_type'                   => 'solid',
                    'admbud_login_bg_color'                  => '#f3f4f6',
                    'admbud_login_grad_from'                 => '#f3f4f6',
                    'admbud_login_grad_to'                   => '#ffffff',
                    'admbud_login_grad_direction'            => 'to bottom right',
                    // Coming Soon page
                    'admbud_cs_bg_type'                      => 'solid',
                    'admbud_cs_bg_color'                     => '#f3f4f6',
                    'admbud_cs_grad_from'                    => '#f3f4f6',
                    'admbud_cs_grad_to'                      => '#ffffff',
                    'admbud_cs_grad_direction'               => 'to bottom right',
                    'admbud_cs_text_color'                   => '#1f2937',
                    'admbud_cs_message_color'                => '#4b5563',
                    // Maintenance page
                    'admbud_maint_bg_type'                   => 'solid',
                    'admbud_maint_bg_color'                  => '#f3f4f6',
                    'admbud_maint_grad_from'                 => '#f3f4f6',
                    'admbud_maint_grad_to'                   => '#ffffff',
                    'admbud_maint_grad_direction'            => 'to bottom right',
                    'admbud_maint_text_color'                => '#1f2937',
                    'admbud_maint_message_color'             => '#4b5563',
                    // Content area
                    'admbud_colours_content_heading' => '#111827',
                    'admbud_colours_content_text' => '#374151',
                    'admbud_colours_content_link' => '#4b5563',
                    'admbud_colours_content_link_hover' => '#1f2937',
                    'admbud_colours_table_header_bg' => '#f9fafb',
                    'admbud_colours_table_header_text' => '#111827',
                    'admbud_colours_table_row_bg' => '#ffffff',
                    'admbud_colours_table_row_text' => '#374151',
                    'admbud_colours_table_row_alt_bg' => '#f9fafb',
                    'admbud_colours_table_row_alt_text' => '#374151',
                    'admbud_colours_table_row_hover' => '#f3f4f6',
                    'admbud_colours_table_border' => '#e5e7eb',
                    'admbud_colours_table_row_separator' => '#f3f4f6',
                    'admbud_colours_table_title_link' => '#4b5563',
                    'admbud_colours_table_action_link' => '#4b5563',
                    'admbud_colours_input_bg' => '#ffffff',
                    'admbud_colours_input_border' => '#d1d5db',
                    'admbud_colours_input_focus' => '#4b5563',
                    'admbud_colours_btn_primary_bg' => '#4b5563',
                    'admbud_colours_btn_primary_text' => '#ffffff',
                    'admbud_colours_btn_primary_hover' => '#374151',
                    'admbud_colours_btn_secondary_bg' => '#f9fafb',
                    'admbud_colours_postbox_bg' => '#ffffff',
                    'admbud_colours_postbox_header_bg' => '#f9fafb',
                    'admbud_colours_postbox_border' => '#e5e7eb',
                    'admbud_colours_notice_bg' => '#ffffff',
                ],
            ],
        ];
    }

    public function get_enabled_tabs(): array {
        $stored = admbud_get_option( 'admbud_modules_enabled_tabs', '__not_set__' );
        if ( $stored === '__not_set__' || $stored === false || $stored === '' ) {
            return [];
        }
        return array_filter( explode( ',', (string) $stored ) );
    }


    private function render_tab_modules(): void {
        $settings = $this; // passed to render file as $settings
        require ADMBUD_DIR . 'includes/render-tab-modules.php';
    }

    private function render_tab_plugin_data(): void {
        $settings = $this;
        require ADMBUD_DIR . 'includes/render-tab-plugin-data.php';
    }


    private function render_tab_quick_settings(): void {
        $settings = $this;
        require ADMBUD_DIR . 'includes/render-tab-quick-settings.php';
    }

}

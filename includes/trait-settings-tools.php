<?php
/**
 * Settings data management - export, import, reset, SMTP password, option keys.
 * Extracted from class-settings.php to reduce file size.
 *
 * @package Admbud
 */

namespace Admbud;

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait Settings_Tools {

    public function handle_smtp_password(): void {
        // Only on options.php POST for our SMTP group.
        if (
            ! isset( $_POST['option_page'] ) || // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification
            $_POST['option_page'] !== 'admbud_smtp_group' || // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification
            ! isset( $_POST['_wpnonce'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification
        ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'admbud_smtp_group-options' ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $raw = isset( $_POST['admbud_smtp_password'] ) ? wp_unslash( $_POST['admbud_smtp_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( $raw !== '' ) {
            // Encrypt and store separately - never in the registered option.
            $smtp = \Admbud\SMTP::get_instance();
            update_option( \Admbud\SMTP::PASS_OPTION, $smtp->encrypt_password( $raw ), false );
        }
        // If blank, leave existing encrypted value unchanged.
    }

    // ============================================================================
    // DATA MANAGEMENT HANDLERS (admin_post_ hooks - bulletproof WP form handling)
    // These fire when forms POST to admin-post.php with the corresponding action field.
    // Avoids options.php capability conflicts entirely.
    // ============================================================================

    /**
     * Dispatch Tools-tab actions when the form POSTs back to our own page.
     * WP's options.php pipeline doesn't handle custom actions, so we intercept
     * them here via admin_init (fires before any output) and route to the right handler.
     *
     * The forms POST to: admin.php?page=admbud&admbud_action=<action>
     * and include standard nonces. This method reads the GET param and dispatches.
     */
    public function dispatch_tools_action(): void {
        // Handle multisite notice dismissal.
        if (
            isset( $_GET['admbud_dismiss_ms_notice'] ) && // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification
            wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), 'admbud_dismiss_ms_notice' ) && // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            current_user_can( 'manage_options' )
        ) {
            admbud_update_option( 'admbud_ms_notice_dismissed_' . get_current_user_id(), '1', false );
            wp_safe_redirect( remove_query_arg( [ 'admbud_dismiss_ms_notice', '_wpnonce' ] ) );
            exit;
        }

        // admbud_action lives in the POST body for reset/import, in the URL for export.
        $action = sanitize_key( $_POST['admbud_action'] ?? $_GET['admbud_action'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( ! $action ) { return; }

        // Must be a logged-in user with manage_options reaching our own page.
        if ( ! current_user_can( 'manage_options' ) ) { return; }

        switch ( $action ) {
            case 'export':
                $this->handle_export();
                break;
            case 'import':
                $this->handle_import();
                break;
            case 'reset_data':
                $this->handle_reset_data();
                break;
            case 'reset_deactivate':
                $this->handle_reset_deactivate();
                break;
        }
    }

    private function require_manage_options(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to do this.', 'admin-buddy' ) );
        }
    }


    public function handle_reset_data(): void {
        $this->require_manage_options();
        check_admin_referer( 'admbud_reset_data', 'admbud_reset_nonce' );
        $this->delete_all_options();
        wp_cache_flush();
        wp_safe_redirect( add_query_arg( 'admbud_notice', 'reset_ok',
            admin_url( 'admin.php?page=admbud&tab=modules' ) ) );
        exit;
    }

    public function handle_reset_deactivate(): void {
        $this->require_manage_options();
        check_admin_referer( 'admbud_reset_deactivate', 'admbud_reset_deactivate_nonce' );
        $this->delete_all_options();
        deactivate_plugins( ADMBUD_BASENAME );
        wp_safe_redirect( add_query_arg( 'admbud_notice', 'deactivated', admin_url( 'plugins.php' ) ) );
        exit;
    }

    /**
     * Delete every option key owned by Admin Buddy from the database.
     * Extracted to a private helper so all three reset actions share the same logic.
     */
    /**
     * Hardcoded list of WordPress core options that must NEVER be deleted by this plugin.
     * This is a defence-in-depth guard - none of these should be in all_option_keys(),
     * but this list ensures they are skipped even if they accidentally end up there.
     */
    private const PROTECTED_OPTIONS = [
        'wp_user_roles',      // Role/capability definitions - deleting locks out ALL users
        'siteurl',
        'home',
        'admin_email',
        'blogname',
        'blogdescription',
        'active_plugins',
        'template',
        'stylesheet',
        'upload_path',
        'upload_url_path',
        'auth_key', 'secure_auth_key', 'logged_in_key', 'nonce_key',
        'auth_salt', 'secure_auth_salt', 'logged_in_salt', 'nonce_salt',
    ];

    /**
     * Delete every option key owned by Admin Buddy from the database.
     * Never touches WordPress core options regardless of what all_option_keys() returns.
     */
    private function delete_all_options(): void {
        global $wpdb;
        // Build the full set of protected option names including the prefixed variant.
        $protected = array_merge(
            self::PROTECTED_OPTIONS,
            [ $wpdb->prefix . 'user_roles' ]  // e.g. wp_user_roles
        );
        $protected_index = array_flip( $protected );

        foreach ( $this->all_option_keys() as $key ) {
            // Hard safety net: skip any core WP option that somehow ended up in the list.
            if ( isset( $protected_index[ $key ] ) ) {
                if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log( "Admin Buddy: skipped protected core option '{$key}' during reset." );
                }
                continue;
            }
            delete_option( $key );
        }

        // Delete per-user multisite notice dismissal flags.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query( // phpcs:ignore WordPress.DB
            $wpdb->prepare( // phpcs:ignore WordPress.DB
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( 'admbud_ms_notice_dismissed_' ) . '%'
            )
        );

        // Delete dynamic receiver sync meta (admbud_receiver_sync_*).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query( // phpcs:ignore WordPress.DB
            $wpdb->prepare( // phpcs:ignore WordPress.DB
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( 'admbud_receiver_sync_' ) . '%'
            )
        );

        // Delete Admin Buddy transients.
        delete_transient( 'admbud_notices_plugin_callbacks' );
        delete_transient( 'admbud_dashboard_widget_catalogue' );
        delete_transient( 'admbud_snippets_running' );
        delete_transient( 'admbud_adminbar_nodes' );

        // Remove Admin Buddy custom capabilities from all roles.
        global $wp_roles;
        if ( ! isset( $wp_roles ) ) {
            $wp_roles = new \WP_Roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        }
        foreach ( array_keys( $wp_roles->roles ) as $role_name ) {
            $role = get_role( $role_name );
            if ( $role ) {
                $role->remove_cap( 'admbud_manage_roles' );
                $role->remove_cap( 'admbud_manage_snippets' );
            }
        }

        // Truncate custom tables - snippets, SVG icon library, and activity log.
        // We truncate (not drop) so the schema survives for immediate re-use after reset.
        // Suppress errors in case tables don't exist yet (module never activated).
        // Table names are derived from $wpdb->prefix (trusted) + hardcoded suffixes.
        $admbud_tables = [
            $wpdb->prefix . 'admbud_snippets',
            $wpdb->prefix . 'admbud_svg_icons',
            $wpdb->prefix . 'admbud_activity_log',
        ];
        $wpdb->suppress_errors( true );
        foreach ( $admbud_tables as $admbud_table ) {
            // Table identifiers are plugin-owned constants composed from
            // $wpdb->prefix + a hardcoded suffix — no user input reaches here.
            // wpdb::prepare() does not support identifier placeholders for
            // table names, so we escape via esc_sql() and interpolate. TRUNCATE
            // is the canonical fast-clear operation for these custom tables.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( sprintf( 'TRUNCATE TABLE IF EXISTS `%s`', esc_sql( $admbud_table ) ) ); // phpcs:ignore WordPress.DB
        }
        $wpdb->suppress_errors( false ); // phpcs:ignore WordPress.DB

        $wpdb->delete( $wpdb->usermeta,  [ 'meta_key' => 'admbud_last_seen'  ] ); // phpcs:ignore WordPress.DB

        // Clear scheduled cron events.
        wp_clear_scheduled_hook( 'admbud_activity_log_prune' );

        // Delete Admin Buddy uploads directory (snippets + blueprints).
        $admbud_uploads_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'admin-buddy';
        if ( is_dir( $admbud_uploads_dir ) ) {
            $this->recursive_rmdir( $admbud_uploads_dir );
        }
        // Legacy: remove old ab-snippets directory if it still exists.
        $legacy_snippets_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'ab-snippets';
        if ( is_dir( $legacy_snippets_dir ) ) {
            $this->recursive_rmdir( $legacy_snippets_dir );
        }

        // Clear Admin Buddy cookies (tab/subtab memory).
        $cookie_prefixes = [ 'admbud_last_tab', 'admbud_subtab_' ];
        foreach ( $_COOKIE as $raw_name => $val ) {
            $name = sanitize_key( (string) $raw_name );
            if ( $name === '' ) { continue; }
            foreach ( $cookie_prefixes as $pfx ) {
                if ( strpos( $name, $pfx ) === 0 ) {
                    setcookie( $name, '', time() - 3600, '/' );
                    unset( $_COOKIE[ $raw_name ] );
                }
            }
        }

        // Delete the compiled Colours CSS transient.
        if ( defined( 'ADMBUD_VERSION' ) ) {
            delete_transient( 'admbud_colours_css_' . ADMBUD_VERSION );
        }

        // Nuclear cleanup: delete ANY remaining admbud_ prefixed options not caught
        // by the static list above. Catches dynamic keys, missed keys, or options
        // from older plugin versions.
        if ( ! empty( $protected ) ) {
            $placeholders = implode( ', ', array_fill( 0, count( $protected ), '%s' ) );
            $args         = array_merge( [ 'admbud\_%' ], $protected );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is built from a constant array length, no user input
            $sql          = "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT IN ( $placeholders )";
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB
        }
    }

    // ============================================================================
    // OPTION MANIFEST
    // ============================================================================

    /**
     * Canonical list of every option key owned by this plugin.
     * Used by export, import, and all reset actions to ensure consistency.
     */
    private function all_option_keys(): array {
        // Dynamically derive from option_key_groups() (single source of truth for
        // export/import keys) plus internal-only keys needed for reset/deletion
        // but intentionally excluded from export. Always merged with the
        // never_export_or_import_keys() list so reset/delete touches them.
        $keys = [];
        foreach ( $this->option_key_groups() as $group ) {
            foreach ( $group['keys'] as $key ) {
                $keys[] = $key;
            }
        }

        // admbud_modules_enabled_tabs is internal-but-portable: written by all sites,
        // included in export so import can restore the module enable map. Not in
        // never_export_or_import_keys() because we DO want it copied between sites.
        $internal_portable = [ 'admbud_modules_enabled_tabs' ];

        return array_merge( $keys, $internal_portable, $this->never_export_or_import_keys() );
    }

    /**
     * Keys that must NEVER be exported or imported, even though they live in
     * wp_options and need to be tracked for reset/delete coverage.
     *
     * Three reasons a key belongs here:
     *
     *   1. SECRETS - credentials, encryption keys, API tokens, recovery codes.
     *      Leaking these via export gives anyone with the JSON file the ability
     *      to take over the site (e.g. admbud_emergency_token bypasses maintenance
     *      mode; admbud_source_key authenticates Remote pulls).
     *
     *   2. LOGS - large, site-specific buffers that aren't useful on another
     *      install and inflate export size.
     *
     *   3. PER-SITE INTERNAL TRACKERS - db_version, plugin_version,
     *      activity_log_version, etc. Importing these from another install
     *      mis-fires the upgrade routine (the destination site thinks it's
     *      already migrated when it isn't).
     *
     * Whenever you add a new option that holds a credential, encryption
     * material, recovery token, log buffer, or per-site version tracker,
     * ADD ITS KEY HERE. The list is the single source of truth used by both
     * handle_export() and handle_import() - keep them in sync via this method
     * and you can never forget one.
     *
     * @return string[] Option keys to strip from export and reject on import.
     */
    private function never_export_or_import_keys(): array {
        return [
            // -- Secrets / credentials --------------------------------------
            'admbud_source_slug',          // Public ID half of the Remote pull credentials
            'admbud_source_key',           // Secret half of the Remote pull credentials
            'admbud_emergency_token',      // Maintenance/coming-soon bypass token
            'admbud_maintenance_token',    // Legacy maintenance bypass token
            'admbud_smtp_password',        // Legacy plaintext SMTP password
            'admbud_smtp_password_enc',    // Encrypted SMTP password ciphertext
            'admbud_smtp_key_salt',        // Salt used to derive the SMTP encryption key

            // -- Logs (large, site-specific, not portable) -------------------
            'admbud_source_log',           // Remote pull access log
            'admbud_smtp_log',             // SMTP send log

            // -- Per-site internal trackers (importing breaks upgrade flow) --
            'admbud_db_version',
            'admbud_plugin_version',
            'admbud_activity_log_version',
            'admbud_snippets_table_version',
            'admbud_colours_css_version',
            'admbud_snippets_migrated_to_files',

            // -- Local backup pointers / install-tied state ------------------
            'admbud_roles_last_backup',          // Points to a local backup file that may not exist on the target
            'admbud_license_activation_limit',   // SureCart-issued, tied to this specific install
        ];
    }

    /**
     * Return option keys grouped by section for selective export UI.
     */
    public function option_key_groups(): array {
        global $wpdb;
        return [
            'ui_tweaks'    => [ 'group' => 'interface',         'label' => __( 'White Label',               'admin-buddy' ), 'icon' => '<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>', 'keys' => [
                'admbud_core_remove_logo', 'admbud_core_remove_help', 'admbud_core_remove_screen_options',
                'admbud_core_custom_footer_enabled', 'admbud_core_custom_footer_text',
                'admbud_wl_sidebar_logo_url', 'admbud_wl_sidebar_logo_width', 'admbud_wl_sidebar_logo_height',
                'admbud_wl_favicon_id', 'admbud_wl_agency_name', 'admbud_wl_agency_url', 'admbud_wl_greeting',
                'admbud_wl_remove_wp_links', 'admbud_wl_footer_version', 'admbud_wl_footer_quote', 'admbud_wl_hide_wp_news',
                'admbud_dashboard_role_pages', 'admbud_dashboard_keep_widgets', 'admbud_dashboard_custom_widgets',
                'admbud_notices_suppress', 'admbud_show_in_adminbar',
                'admbud_wl_hide_core_update', 'admbud_wl_hide_plugin_update', 'admbud_wl_hide_theme_update', 'admbud_wl_hide_php_nag',
                'admbud_wl_update_policy', 'admbud_wl_disable_update_emails', 'admbud_wl_disable_all_updates',
            ] ],
            'colours'      => [ 'group' => 'interface',         'label' => __( 'Colours',                 'admin-buddy' ), 'icon' => '<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="2" y="3" width="20" height="8" rx="2"/><path d="M16 11v2a2 2 0 0 1-2 2h-2"/><path d="M12 15v6"/><path d="M10 21h4"/></svg>', 'keys' => [
                'admbud_colours_primary', 'admbud_colours_secondary',
                'admbud_colours_menu_text', 'admbud_colours_menu_bg', 'admbud_colours_menu_item_sep',
                'admbud_colours_sidebar_gradient', 'admbud_colours_sidebar_grad_dir',
                'admbud_colours_sidebar_grad_from', 'admbud_colours_sidebar_grad_to',
                'admbud_colours_active_text', 'admbud_colours_sep_color', 'admbud_colours_body_bg',
            'admbud_colours_adminbar_bg', 'admbud_colours_adminbar_text', 'admbud_colours_adminbar_hover_bg', 'admbud_colours_adminbar_submenu_bg', 'admbud_colours_adminbar_hover_text', 'admbud_colours_adminbar_sub_text', 'admbud_colours_adminbar_sub_hover_text', 'admbud_colours_pill_maintenance', 'admbud_colours_pill_coming_soon', 'admbud_colours_pill_noindex', 'admbud_colours_pill_admin_buddy', 'admbud_colours_submenu_text', 'admbud_colours_hover_text', 'admbud_colours_hover_bg', 'admbud_colours_active_bg', 'admbud_colours_active_parent_text',
            'admbud_colours_submenu_bg', 'admbud_colours_submenu_hover_bg', 'admbud_colours_submenu_hover_text', 'admbud_colours_submenu_active_bg', 'admbud_colours_submenu_active_text', 'admbud_colours_adminbar_sub_hover_bg', 'admbud_colours_shadow_colour',
            'admbud_colours_content_heading', 'admbud_colours_content_text', 'admbud_colours_content_link', 'admbud_colours_content_link_hover',
            'admbud_colours_table_header_bg', 'admbud_colours_table_header_text', 'admbud_colours_table_header_link',
            'admbud_colours_table_row_bg', 'admbud_colours_table_row_text', 'admbud_colours_table_row_alt_bg', 'admbud_colours_table_row_alt_text',
            'admbud_colours_table_row_hover', 'admbud_colours_table_border', 'admbud_colours_table_row_separator', 'admbud_colours_table_title_link', 'admbud_colours_table_action_link',
            'admbud_colours_input_bg', 'admbud_colours_input_border', 'admbud_colours_input_focus',
            'admbud_colours_btn_primary_bg', 'admbud_colours_btn_primary_text', 'admbud_colours_btn_primary_hover', 'admbud_colours_btn_secondary_bg',
            'admbud_colours_postbox_bg', 'admbud_colours_postbox_header_bg', 'admbud_colours_postbox_border', 'admbud_colours_postbox_text', 'admbud_colours_notice_bg',
            'admbud_colours_ui_radius',
            'admbud_colours_menu_bg_opacity', 'admbud_colours_menu_text_opacity',
            'admbud_css_exclusions',
            ] ],
            'login'        => [ 'group' => 'interface',         'label' => __( 'Login',            'admin-buddy' ), 'icon' => '<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>', 'keys' => [
                'admbud_login_logo_url', 'admbud_login_logo_width', 'admbud_login_logo_height',
                'admbud_login_card_position', 'admbud_login_bg_type', 'admbud_login_bg_color',
                'admbud_login_grad_from', 'admbud_login_grad_to', 'admbud_login_grad_direction',
                'admbud_login_bg_image_url', 'admbud_login_bg_overlay_color', 'admbud_login_bg_overlay_opacity',
            ] ],
            'maintenance'  => [ 'group' => 'interface',         'label' => __( 'Maintenance',   'admin-buddy' ), 'icon' => '<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>', 'keys' => [
                'admbud_maintenance_mode',
                'admbud_coming_soon_title', 'admbud_coming_soon_message',
                'admbud_cs_bg_type', 'admbud_cs_bg_color', 'admbud_cs_grad_from', 'admbud_cs_grad_to',
                'admbud_cs_grad_direction', 'admbud_cs_bg_image_url', 'admbud_cs_bg_overlay_color', 'admbud_cs_bg_overlay_opacity',
                'admbud_cs_text_color', 'admbud_cs_message_color',
                'admbud_maintenance_title', 'admbud_maintenance_message',
                'admbud_maint_bg_type', 'admbud_maint_bg_color', 'admbud_maint_grad_from', 'admbud_maint_grad_to',
                'admbud_maint_grad_direction', 'admbud_maint_bg_image_url', 'admbud_maint_bg_overlay_color', 'admbud_maint_bg_overlay_opacity',
                'admbud_maint_text_color', 'admbud_maint_message_color',
                'admbud_maintenance_bypass_urls',
                'admbud_coming_soon_bg_color', 'admbud_maintenance_bg_color',
            ] ],
            'snippets'     => [ 'group' => 'utilities',    'label' => __( 'Snippets',       'admin-buddy' ), 'icon' => '<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>', 'keys' => [ 'admbud_snippets' ] ],
            'smtp'         => [ 'group' => 'utilities',         'label' => __( 'SMTP',                    'admin-buddy' ), 'icon' => '<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>', 'keys' => [
                'admbud_smtp_enabled', 'admbud_smtp_mailer', 'admbud_smtp_host', 'admbud_smtp_port',
                'admbud_smtp_encryption', 'admbud_smtp_auth', 'admbud_smtp_username',
                'admbud_smtp_from_name', 'admbud_smtp_from_email', 'admbud_smtp_from_name',
                'admbud_smtp_fallback', 'admbud_smtp_disable_ssl_verify', 'admbud_smtp_preset',
                // admbud_smtp_password intentionally excluded for security.
            ] ],
            'roles'        => [
                'group' => 'utilities',
                'label' => __( 'User Roles', 'admin-buddy' ),
                'icon'  => '<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
                'keys'  => [ 'wp_user_roles' ],
                'warn'  => __( 'Importing roles will overwrite ALL role/capability definitions. Use with care.', 'admin-buddy' ),
            ],
            'menu'         => [ 'group' => 'interface',    'label' => __( 'Menu Customiser',         'admin-buddy' ), 'icon' => '<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>', 'keys' => [
                'admbud_menu_config', 'admbud_menu_show_item_borders',
            ] ],
            'custom-pages' => [ 'group' => 'interface',    'label' => __( 'Custom Pages',            'admin-buddy' ), 'icon' => '<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>', 'keys' => [ 'admbud_custom_pages' ] ],
            'svg-library'  => [ 'group' => 'interface',    'label' => __( 'Icon Library',             'admin-buddy' ), 'icon' => '<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 17.5h7M17.5 14v7"/></svg>', 'keys' => [ 'admbud_svg_icons' ] ],
            'bricks'       => [ 'group' => 'integrations', 'label' => __( 'Bricks Builder', 'admin-buddy' ), 'icon' => '<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="2" y="3" width="20" height="18" rx="2"/><path d="M2 9h20"/><path d="M9 21V9"/></svg>', 'keys' => [
                // Core integration
                'admbud_bricks_enabled',
                'admbud_bricks_color_mapping_enabled',
                // Per-variable colour overrides
                'admbud_bricks_builder_bg', 'admbud_bricks_builder_bg_2', 'admbud_bricks_builder_bg_3',
                'admbud_bricks_builder_bg_accent', 'admbud_bricks_builder_color',
                'admbud_bricks_builder_color_description', 'admbud_bricks_builder_color_accent',
                'admbud_bricks_builder_color_accent_inverse', 'admbud_bricks_builder_color_knob',
                'admbud_bricks_builder_border_color', 'admbud_bricks_bricks_tooltip_bg', 'admbud_bricks_bricks_tooltip_text',
                // Other tweaks
                'admbud_bricks_hide_logo', 'admbud_bricks_hide_spinner',
                'admbud_bricks_custom_logo_url', 'admbud_bricks_custom_spinner_url',
                // BEM Class Generator (Pro)
                'admbud_bricks_bem_enabled', 'admbud_bricks_bem_auto_sync_labels',
                'admbud_bricks_bem_show_modifiers', 'admbud_bricks_bem_default_action',
                'admbud_bricks_bem_blacklist_extra',
                // Quick Insert (Pro)
                'admbud_bricks_quick_enabled', 'admbud_bricks_quick_favourites',
            ] ],
            'source'         => [ 'group' => 'manage', 'label' => __( 'Remote', 'admin-buddy' ), 'icon' => '<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>', 'keys' => [
                'admbud_source_enabled', 'admbud_source_modules', 'admbud_source_whitelist',
                'admbud_receiver_sources',
                // NOTE: admbud_source_slug and admbud_source_key are credentials - excluded
                // by never_export_or_import_keys() in trait-settings-tools.php.
                // admbud_source_allow_any was removed in the Remote security pass.
            ] ],
            'activity-log'   => [ 'group' => 'utilities', 'label' => __( 'Activity Log', 'admin-buddy' ), 'icon' => '<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>', 'keys' => [
                'admbud_activity_log_retention', 'admbud_activity_log_per_page',
            ] ],
            'quick-settings' => [ 'group' => 'manage', 'label' => __( 'Quick Settings', 'admin-buddy' ), 'icon' => '<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/><circle cx="8" cy="6" r="2"/><circle cx="16" cy="12" r="2"/><circle cx="10" cy="18" r="2"/></svg>', 'keys' => [
                'admbud_qs_disable_emoji', 'admbud_qs_disable_jquery_migrate',
                'admbud_qs_remove_feed_links', 'admbud_qs_remove_rsd', 'admbud_qs_remove_wlw',
                'admbud_qs_remove_shortlink', 'admbud_qs_remove_restapi_link', 'admbud_qs_disable_embeds', 'admbud_qs_remove_version',
                'admbud_qs_disable_xmlrpc', 'admbud_qs_disable_rest_api', 'admbud_qs_disable_file_edit',
                'admbud_qs_disable_feeds', 'admbud_qs_disable_self_ping', 'admbud_qs_disable_comments_default',
                'admbud_qs_duplicate_post', 'admbud_qs_user_last_seen', 'admbud_qs_allow_svg', 'admbud_qs_svg_roles',
                'admbud_qs_allow_svg_roles',
                'admbud_qs_hide_adminbar_frontend', 'admbud_qs_hide_adminbar_frontend_roles',
                'admbud_qs_hide_adminbar_backend', 'admbud_qs_hide_adminbar_backend_roles',
                'admbud_qs_hide_adminbar_checklist', 'admbud_qs_hide_adminbar_checklist_roles',
                'admbud_qs_hide_adminbar_noindex', 'admbud_qs_hide_adminbar_noindex_roles',
                'admbud_qs_collapse_menu', 'admbud_qs_collapse_menu_roles',
                'admbud_qs_sidebar_user_menu', 'admbud_qs_sidebar_user_menu_roles',
            ] ],
            'option-pages' => [ 'group' => 'interface', 'label' => __( 'Option Pages', 'admin-buddy' ), 'icon' => '<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/><line x1="9" y1="10" x2="15" y2="10"/></svg>', 'keys' => [
                'admbud_option_pages',
            ] ],
            'collections' => [ 'group' => 'interface', 'label' => __( 'Collections', 'admin-buddy' ), 'icon' => '<svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>', 'keys' => [
                'admbud_collections',
            ] ],
        ];
    }

    /**
     * Recursively delete a directory and all its contents.
     * Used by reset and uninstall to clean up the admin-buddy uploads folder.
     */
    private function recursive_rmdir( string $dir ): void {
        if ( ! is_dir( $dir ) ) { return; }
        $items = scandir( $dir );
        if ( ! is_array( $items ) ) { return; }
        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) { continue; }
            $path = $dir . '/' . $item;
            if ( is_dir( $path ) ) {
                $this->recursive_rmdir( $path );
            } else {
                wp_delete_file( $path );
            }
        }
        global $wp_filesystem;
        if ( ! $wp_filesystem ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        $wp_filesystem->rmdir( $dir );
    }
}

<?php
/**
 * Uninstall routine for Admin Buddy.
 *
 * WordPress calls this file directly when the user clicks "Delete" in the
 * Plugins screen. No other file is loaded first - this must be entirely
 * self-contained. No autoloader, no class dependencies.
 *
 * @package Admbud
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
defined( 'ABSPATH' )             || exit;

// -------------------------------------------------------------------------
// All option keys owned by this plugin - inline, no class dependency.
// -------------------------------------------------------------------------

$admbud_option_keys = [ // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    // White Label - Branding
    'admbud_core_remove_logo', 'admbud_core_remove_help', 'admbud_core_remove_screen_options',
    'admbud_core_custom_footer_enabled', 'admbud_core_custom_footer_text',
    'admbud_wl_sidebar_logo_url', 'admbud_wl_sidebar_logo_width', 'admbud_wl_sidebar_logo_height',
    'admbud_wl_favicon_id',
    'admbud_wl_agency_name', 'admbud_wl_agency_url', 'admbud_wl_greeting',
    'admbud_wl_remove_wp_links', 'admbud_wl_footer_version', 'admbud_wl_footer_quote',
    // White Label - Dashboard
    'admbud_dashboard_role_pages', 'admbud_dashboard_keep_widgets', 'admbud_dashboard_custom_widgets',
    'admbud_wl_hide_wp_news',
    // White Label - Notices & Updates
    'admbud_notices_suppress', 'admbud_modules_enabled_tabs',
    'admbud_wl_hide_core_update', 'admbud_wl_hide_plugin_update', 'admbud_wl_hide_theme_update', 'admbud_wl_hide_php_nag',
    'admbud_wl_update_policy', 'admbud_wl_disable_update_emails', 'admbud_wl_disable_all_updates',
    // White Label - Cleanup (dissolved - all items moved to Quick Settings)
    // White Label - Gutenberg
    // Colours
    'admbud_colours_primary', 'admbud_colours_secondary',
    'admbud_colours_menu_text', 'admbud_colours_menu_bg', 'admbud_colours_menu_item_sep',
    'admbud_colours_sidebar_gradient', 'admbud_colours_sidebar_grad_dir',
    'admbud_colours_sidebar_grad_from', 'admbud_colours_sidebar_grad_to',
    'admbud_colours_active_text', 'admbud_colours_sep_color', 'admbud_colours_body_bg',
    'admbud_colours_adminbar_bg', 'admbud_colours_adminbar_text',
    'admbud_colours_adminbar_hover_bg', 'admbud_colours_adminbar_submenu_bg',
    'admbud_colours_adminbar_hover_text', 'admbud_colours_adminbar_sub_text',
    'admbud_colours_adminbar_sub_hover_text', 'admbud_colours_adminbar_sub_hover_bg',
    'admbud_colours_pill_maintenance', 'admbud_colours_pill_coming_soon', 'admbud_colours_pill_noindex',
    'admbud_colours_pill_admin_buddy',
    'admbud_colours_submenu_text', 'admbud_colours_hover_text', 'admbud_colours_active_parent_text',
    'admbud_colours_submenu_bg', 'admbud_colours_submenu_hover_bg', 'admbud_colours_submenu_hover_text',
    'admbud_colours_shadow_colour',
    // Content area tokens
    'admbud_colours_content_heading', 'admbud_colours_content_text', 'admbud_colours_content_link', 'admbud_colours_content_link_hover',
    'admbud_colours_table_header_bg', 'admbud_colours_table_header_text',
    'admbud_colours_table_row_bg', 'admbud_colours_table_row_text', 'admbud_colours_table_row_alt_bg', 'admbud_colours_table_row_alt_text',
    'admbud_colours_table_row_hover', 'admbud_colours_table_border', 'admbud_colours_table_row_separator', 'admbud_colours_table_action_link',
    'admbud_colours_input_bg', 'admbud_colours_input_border', 'admbud_colours_input_focus',
    'admbud_colours_btn_primary_bg', 'admbud_colours_btn_primary_text', 'admbud_colours_btn_primary_hover', 'admbud_colours_btn_secondary_bg',
    'admbud_colours_postbox_bg', 'admbud_colours_postbox_header_bg', 'admbud_colours_postbox_border', 'admbud_colours_postbox_text', 'admbud_colours_notice_bg',
    'admbud_colours_ui_radius',
    // CSS Exclusions
    'admbud_css_exclusions',
    // Colours - opacity
    'admbud_colours_menu_bg_opacity', 'admbud_colours_menu_text_opacity',
    // Login
    'admbud_login_logo_url', 'admbud_login_logo_width', 'admbud_login_logo_height',
    'admbud_login_card_position', 'admbud_login_bg_type', 'admbud_login_bg_color',
    'admbud_login_grad_from', 'admbud_login_grad_to', 'admbud_login_grad_direction',
    'admbud_login_bg_image_url', 'admbud_login_bg_overlay_color', 'admbud_login_bg_overlay_opacity',
    // Maintenance
    'admbud_maintenance_mode', 'admbud_maintenance_token', 'admbud_emergency_token',
    'admbud_coming_soon_title', 'admbud_coming_soon_message', 'admbud_coming_soon_bg_color',
    'admbud_cs_bg_type', 'admbud_cs_bg_color', 'admbud_cs_grad_from', 'admbud_cs_grad_to',
    'admbud_cs_grad_direction', 'admbud_cs_bg_image_url',
    'admbud_cs_bg_overlay_color', 'admbud_cs_bg_overlay_opacity',
    'admbud_cs_text_color', 'admbud_cs_message_color',
    'admbud_maintenance_title', 'admbud_maintenance_message', 'admbud_maintenance_bg_color',
    'admbud_maint_bg_type', 'admbud_maint_bg_color', 'admbud_maint_grad_from', 'admbud_maint_grad_to',
    'admbud_maint_grad_direction', 'admbud_maint_bg_image_url',
    'admbud_maint_bg_overlay_color', 'admbud_maint_bg_overlay_opacity',
    'admbud_maint_text_color', 'admbud_maint_message_color',
    'admbud_maintenance_bypass_urls',
    // SMTP (all keys including credentials)
    'admbud_smtp_enabled', 'admbud_smtp_mailer', 'admbud_smtp_host', 'admbud_smtp_port',
    'admbud_smtp_username', 'admbud_smtp_password', 'admbud_smtp_password_enc', 'admbud_smtp_key_salt',
    'admbud_smtp_encryption', 'admbud_smtp_auth',
    'admbud_smtp_from_name', 'admbud_smtp_from_email',
    'admbud_smtp_fallback', 'admbud_smtp_disable_ssl_verify', 'admbud_smtp_preset',
    'admbud_smtp_log',
    // Snippets
    'admbud_snippets',
    // SVG Icon Library (legacy option)
    'admbud_svg_icons',
    // Menu Customiser
    'admbud_menu_config', 'admbud_menu_show_item_borders',
    // Custom Pages
    'admbud_custom_pages',
    // Bricks Builder
    'admbud_bricks_enabled', 'admbud_bricks_hide_logo', 'admbud_bricks_hide_spinner',
    'admbud_bricks_builder_bg', 'admbud_bricks_builder_bg_2', 'admbud_bricks_builder_bg_3',
    'admbud_bricks_builder_bg_accent', 'admbud_bricks_builder_color',
    'admbud_bricks_builder_color_description', 'admbud_bricks_builder_color_accent',
    'admbud_bricks_builder_color_accent_inverse', 'admbud_bricks_builder_color_knob',
    'admbud_bricks_builder_border_color', 'admbud_bricks_bricks_tooltip_bg',
    'admbud_bricks_bricks_tooltip_text',
    // Placement + UI options
    'admbud_show_in_adminbar',
    // Roles
    'admbud_roles_last_backup',
    // Upgrade tracking
    'admbud_db_version', 'admbud_snippets_table_version', 'admbud_snippets_migrated_to_files',
    // Source
    'admbud_source_enabled', 'admbud_source_slug', 'admbud_source_key', 'admbud_source_modules', 'admbud_source_whitelist', 'admbud_source_allow_any', 'admbud_source_log',
    // Receiver
    'admbud_receiver_sources',
    // Activity Log
    'admbud_activity_log_retention', 'admbud_activity_log_per_page', 'admbud_activity_log_version', 'admbud_colours_css_version',
    // Quick Settings
    'admbud_qs_disable_emoji', 'admbud_qs_disable_jquery_migrate',
    'admbud_qs_remove_feed_links', 'admbud_qs_remove_rsd', 'admbud_qs_remove_wlw',
    'admbud_qs_remove_shortlink', 'admbud_qs_remove_restapi_link', 'admbud_qs_disable_embeds', 'admbud_qs_remove_version',
    'admbud_qs_disable_xmlrpc', 'admbud_qs_disable_rest_api', 'admbud_qs_disable_file_edit',
    'admbud_qs_disable_feeds', 'admbud_qs_disable_self_ping', 'admbud_qs_disable_comments_default', 'admbud_qs_duplicate_post', 'admbud_qs_user_last_seen', 'admbud_qs_allow_svg', 'admbud_qs_svg_roles',
    // Colour keys missing from original list
    'admbud_colours_table_header_link', 'admbud_colours_table_title_link',
    // Option Pages - definition store (field values admbud_op_* deleted separately below)
    'admbud_option_pages',
    // Collections - definition store (post meta cleaned via WP core on post delete)
    'admbud_collections',
];

// -------------------------------------------------------------------------
// Cleanup - runs per-site for both single-site and multisite
// -------------------------------------------------------------------------

global $wpdb;

/**
 * Recursively delete a directory and all its contents.
 * Self-contained helper for uninstall.php - no class dependencies.
 */
function _ab_recursive_rmdir( string $dir ): void { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- admbud_ is the plugin prefix.
    if ( ! is_dir( $dir ) ) { return; }
    $items = scandir( $dir );
    if ( ! is_array( $items ) ) { return; }
    foreach ( $items as $item ) {
        if ( $item === '.' || $item === '..' ) { continue; }
        $path = $dir . '/' . $item;
        if ( is_dir( $path ) ) {
            _ab_recursive_rmdir( $path );
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

/**
 * Per-site cleanup: delete options, upload files, and tables.
 * On multisite this runs for every subsite in the network.
 *
 * @param array $option_keys List of option keys to delete.
 */
function _ab_uninstall_site( array $option_keys ): void { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- admbud_ is the plugin prefix.
    global $wpdb;

    // Delete all per-site options.
    foreach ( $option_keys as $key ) {
        delete_option( $key );
    }

    // Delete per-user notice dismissal flags for this site.
    $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'admbud_ms_notice_dismissed_%'"
    );

    // Delete Admin Buddy uploads directory (snippets + blueprints).
    $_admbud_uploads = trailingslashit( wp_upload_dir()['basedir'] ) . 'admin-buddy';
    if ( is_dir( $_admbud_uploads ) ) {
        _ab_recursive_rmdir( $_admbud_uploads );
    }
    // Legacy: remove old ab-snippets directory if it still exists.
    $_legacy_snippets = trailingslashit( wp_upload_dir()['basedir'] ) . 'ab-snippets';
    if ( is_dir( $_legacy_snippets ) ) {
        _ab_recursive_rmdir( $_legacy_snippets );
    }

    // Drop custom tables for this site.
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}admbud_svg_icons" );    // phpcs:ignore
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}admbud_activity_log" ); // phpcs:ignore
}

if ( is_multisite() ) {
    // Loop through every subsite and clean up.
    $sites = get_sites( [ 'number' => 0, 'fields' => 'ids' ] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    foreach ( $sites as $site_id ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        switch_to_blog( $site_id );
        _ab_uninstall_site( $admbud_option_keys );
        restore_current_blog();
    }

    // Remove network-level options (locked keys, network defaults).
    foreach ( $admbud_option_keys as $key ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        delete_site_option( $key );
    }


} else {
    _ab_uninstall_site( $admbud_option_keys );
}

// -------------------------------------------------------------------------
// Delete Admin Buddy transients
// -------------------------------------------------------------------------

delete_transient( 'admbud_notices_plugin_callbacks' );
delete_transient( 'admbud_dashboard_widget_catalogue' );
delete_transient( 'admbud_snippets_safe_mode' );
delete_transient( 'admbud_activation_redirect' );
delete_transient( 'admbud_colours_css_cache' ); // legacy key
// Versioned cache keys (admbud_colours_css_ + version)
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_admbud_colours_css_%' OR option_name LIKE '_transient_timeout_admbud_colours_css_%'" ); // phpcs:ignore WordPress.DB

// -------------------------------------------------------------------------
// Option Pages - delete all field values (admbud_op_{slug}_{key} pattern).
// The admbud_option_pages definition key is handled above in the loop.
// -------------------------------------------------------------------------

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
    $wpdb->esc_like( 'admbud_op_' ) . '%'
) );

// -------------------------------------------------------------------------
// Remove Demo Data post meta, term meta, user meta (belt-and-suspenders -
// the Remove All button in the module handles this during normal use, but
// we clean up here in case someone uninstalls without removing demo data).
// -------------------------------------------------------------------------

$wpdb->delete( $wpdb->usermeta, [ 'meta_key' => 'admbud_last_seen'  ] ); // phpcs:ignore WordPress.DB,- User Last Seen timestamps

// -------------------------------------------------------------------------
// Remove Admin Buddy custom capabilities from all roles
// -------------------------------------------------------------------------

global $wp_roles;
if ( ! isset( $wp_roles ) ) {
    $wp_roles = new WP_Roles();
}

foreach ( array_keys( $wp_roles->roles ) as $role_name ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    $role = get_role( $role_name );
    if ( $role ) {
        $role->remove_cap( 'admbud_manage_roles' );
        $role->remove_cap( 'admbud_manage_snippets' );
    }
}

// -------------------------------------------------------------------------
// Clear scheduled cron events
// -------------------------------------------------------------------------

wp_clear_scheduled_hook( 'admbud_activity_log_prune' );

// -------------------------------------------------------------------------
// Allow add-ons to hook in
// -------------------------------------------------------------------------

do_action( 'admbud_uninstall' );


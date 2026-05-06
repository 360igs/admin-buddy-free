<?php
/**
 * Roles - User Roles Editor.
 *
 * Operations:
 *  - Edit capabilities of any role, grouped by category.
 *  - Create new custom roles.
 *  - Rename roles (display name only - slug is permanent).
 *  - Clone an existing role.
 *  - Delete custom roles (built-in roles are protected).
 *  - Reset a role to WP defaults.
 *
 * Safety guarantees (all enforced server-side, independent of the UI):
 *  - `administrator` can NEVER be deleted - the request is killed with wp_die(403).
 *  - No other BUILTIN role can be deleted - same hard stop.
 *  - `administrator` ALWAYS retains every cap in ADMIN_PROTECTED after any save.
 *    The re-apply happens unconditionally as the last step of every mutation.
 *  - Every write is followed by a post-write integrity check that reads the
 *    option back from the database. If manage_options is missing from
 *    administrator, the write is rolled back (in rename) or an emergency
 *    repair is triggered (in save/reset).
 *  - If wp_user_roles is missing or returns false (e.g. after accidental deletion
 *    by another plugin or manual DB edit), populate_roles() is called
 *    automatically on admin_init to prevent PHP 8.1+ fatal errors.
 *  - No direct update_option() calls on the roles table except in ajax_rename,
 *    where a snapshot is taken first and used to roll back on failure.
 *
 * @package Admbud
 */

namespace Admbud;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Roles {

    // -- Constants ------------------------------------------------------------

    /** Built-in role slugs - can never be deleted by this plugin. */
    const BUILTIN = [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ];

    /**
     * Capabilities the administrator role must always have.
     * Re-applied unconditionally after every mutation.
     * Never touchable via the UI checkboxes.
     */
    const ADMIN_PROTECTED = [
        'administrator',
        'manage_options',
        'activate_plugins',
        'delete_plugins',
        'install_plugins',
        'update_plugins',
        'edit_plugins',
        'update_core',
        'update_themes',
        'install_themes',
        'delete_themes',
    ];

    /** Short descriptions shown under each cap group heading. */
    const GROUP_DESCRIPTIONS = [
        'Posts'    => 'Create, edit, publish and delete posts.',
        'Pages'    => 'Create, edit, publish and delete pages.',
        'Media'    => 'Upload and manage files in the media library.',
        'Comments' => 'Moderate and edit reader comments.',
        'Users'    => 'List, create, edit and remove user accounts.',
        'Themes'   => 'Install, switch and customise themes.',
        'Plugins'  => 'Install, activate and update plugins.',
        'Settings' => 'Manage site options, categories and imports.',
        'Network'  => 'Manage WordPress multisite network settings.',
        'Legacy'   => 'Numeric role levels - kept for back-compat. Avoid assigning these.',
        'Other'    => 'Plugin-specific or custom capabilities not matched to a group.',
    ];

    /** Core WP caps grouped by category. */
    const CORE_GROUPS = [
        'Posts'    => [
            'read', 'edit_posts', 'edit_others_posts', 'edit_published_posts',
            'publish_posts', 'delete_posts', 'delete_others_posts',
            'delete_published_posts', 'delete_private_posts', 'edit_private_posts',
            'read_private_posts',
        ],
        'Pages'    => [
            'edit_pages', 'edit_others_pages', 'edit_published_pages',
            'publish_pages', 'delete_pages', 'delete_others_pages',
            'delete_published_pages', 'delete_private_pages', 'edit_private_pages',
            'read_private_pages',
        ],
        'Media'    => [ 'upload_files', 'edit_files' ],
        'Comments' => [ 'moderate_comments', 'edit_comment' ],
        'Users'    => [
            'list_users', 'edit_users', 'create_users', 'delete_users',
            'promote_users', 'remove_users',
        ],
        'Themes'   => [
            'switch_themes', 'edit_themes', 'install_themes', 'update_themes', 'delete_themes',
            'edit_theme_options', 'customize',
        ],
        'Plugins'  => [
            'activate_plugins', 'edit_plugins', 'install_plugins', 'update_plugins', 'delete_plugins',
        ],
        'Settings' => [
            'manage_options', 'manage_links', 'manage_categories',
            'import', 'export', 'update_core',
        ],
        'Network'  => [
            'manage_network', 'manage_sites', 'manage_network_users',
            'manage_network_plugins', 'manage_network_options', 'manage_network_themes',
            'create_sites', 'delete_sites', 'upgrade_network',
        ],
    ];

    private static ?Roles $instance = null;

    public static function get_instance(): Roles {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Priority 1 so this runs before almost any other admin_init code.
        add_action( 'admin_init', [ $this, 'maybe_repair_roles_option' ], 1 );

        add_action( 'wp_ajax_admbud_roles_save',   [ $this, 'ajax_save'   ] );
        add_action( 'wp_ajax_admbud_roles_create', [ $this, 'ajax_create' ] );
        add_action( 'wp_ajax_admbud_roles_delete', [ $this, 'ajax_delete' ] );
        add_action( 'wp_ajax_admbud_roles_rename', [ $this, 'ajax_rename' ] );
        add_action( 'wp_ajax_admbud_roles_clone',  [ $this, 'ajax_clone'  ] );
        add_action( 'wp_ajax_admbud_roles_reset',  [ $this, 'ajax_reset'  ] );
        add_action( 'wp_ajax_admbud_roles_backup', [ $this, 'ajax_backup' ] );
    }

    // ========================================================================
    // HEALTH CHECK
    // ========================================================================

    /**
     * Runs on admin_init (priority 1) on every admin request.
     *
     * If wp_user_roles is missing, false, empty, or lacks a valid administrator,
     * call populate_roles() to rebuild it from WP defaults. This prevents:
     *   - White Screen of Death caused by a missing option.
     *   - PHP 8.1+ "Automatic conversion of false to array" fatal in WP_Roles.
     *
     * populate_roles() is additive - it only inserts roles/caps that are absent,
     * so any surviving custom roles are left untouched.
     */
    public function maybe_repair_roles_option(): void {
        global $wp_roles;

        // Use the role_key from the WP_Roles object (handles multisite prefixes).
        $wp_roles   = $this->get_wp_roles();
        $option_key = $wp_roles->role_key;
        $raw        = get_option( $option_key );

        // Healthy state: option is a non-empty array with a valid administrator.
        if ( is_array( $raw )
             && isset( $raw['administrator'] )
             && ! empty( $raw['administrator']['capabilities']['manage_options'] )
        ) {
            return;
        }

        // Repair needed.
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( 'Admin Buddy: wp_user_roles missing or corrupt - calling populate_roles().' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }

        if ( ! function_exists( 'populate_roles' ) ) {
            require_once ABSPATH . 'wp-admin/includes/schema.php';
        }
        populate_roles();

        // Reinitialise so the rest of this request sees the repaired data.
        $wp_roles = new \WP_Roles();
    }

    // ========================================================================
    // DATA
    // ========================================================================

    public static function get_group_description( string $group ): string {
        return self::GROUP_DESCRIPTIONS[ $group ] ?? '';
    }

    /**
     * Return all roles as an array keyed by slug with display name + caps.
     * Casts roles to array so it is safe when WP_Roles failed to load.
     */
    public function get_all_roles(): array {
        $wp_roles = $this->get_wp_roles();
        $roles    = [];
        foreach ( (array) $wp_roles->roles as $slug => $data ) {
            $roles[ $slug ] = [
                'name'    => $data['name'] ?? $slug,
                'caps'    => array_keys( array_filter( (array) ( $data['capabilities'] ?? [] ) ) ),
                'builtin' => in_array( $slug, self::BUILTIN, true ),
            ];
        }
        return $roles;
    }

    /**
     * Build merged, grouped cap list for the UI.
     */
    public function get_grouped_caps(): array {
        $wp_roles = $this->get_wp_roles();

        $all_caps = [];
        foreach ( (array) $wp_roles->roles as $data ) {
            foreach ( array_keys( (array) ( $data['capabilities'] ?? [] ) ) as $cap ) {
                $all_caps[ $cap ] = true;
            }
        }

        $core_flat = [];
        foreach ( self::CORE_GROUPS as $caps ) {
            foreach ( $caps as $cap ) { $core_flat[ $cap ] = true; }
        }

        $extra         = array_diff_key( $all_caps, $core_flat );
        $plugin_groups = [];
        $other         = [];

        $known_prefixes = [
            'woocommerce_'   => 'WooCommerce',
            'manage_woocomm' => 'WooCommerce',
            'view_woocomm'   => 'WooCommerce',
            'edit_shop_'     => 'WooCommerce',
            'delete_shop_'   => 'WooCommerce',
            'publish_shop_'  => 'WooCommerce',
            'acf_'           => 'ACF',
            'wpseo_'         => 'Yoast SEO',
            'edd_'           => 'Easy Digital Downloads',
            'give_'          => 'GiveWP',
            'manage_bbpress' => 'bbPress',
            'spectate'       => 'bbPress',
            'participate'    => 'bbPress',
            'read_forum'     => 'bbPress',
            'edit_forum'     => 'bbPress',
            'delete_forum'   => 'bbPress',
            'read_topic'     => 'bbPress',
            'edit_topic'     => 'bbPress',
            'delete_topic'   => 'bbPress',
            'read_reply'     => 'bbPress',
            'edit_reply'     => 'bbPress',
            'delete_reply'   => 'bbPress',
            'bp_'            => 'BuddyPress',
            'elementor_'     => 'Elementor',
            'lifterlms_'     => 'LifterLMS',
            'tutor_'         => 'Tutor LMS',
        ];

        foreach ( array_keys( $extra ) as $cap ) {
            if ( preg_match( '/^level_\d+$/', $cap ) ) {
                $plugin_groups['Legacy'][] = $cap;
                continue;
            }
            $matched = false;
            foreach ( $known_prefixes as $prefix => $group ) {
                if ( strpos( $cap, $prefix ) === 0 ) {
                    $plugin_groups[ $group ][] = $cap;
                    $matched = true;
                    break;
                }
            }
            if ( ! $matched ) { $other[] = $cap; }
        }

        $groups = self::CORE_GROUPS;
        foreach ( $plugin_groups as $group => $caps ) {
            sort( $caps );
            $groups[ $group ] = $caps;
        }
        if ( ! empty( $other ) ) {
            sort( $other );
            $groups['Other'] = $other;
        }
        return $groups;
    }

    /**
     * Default capabilities for built-in roles (used by Reset).
     */
    public static function get_default_caps(): array {
        return [
            'subscriber'    => [ 'read' => true ],
            'contributor'   => [ 'read' => true, 'edit_posts' => true, 'delete_posts' => true ],
            'author'        => [
                'read' => true, 'upload_files' => true,
                'edit_posts' => true, 'edit_published_posts' => true,
                'publish_posts' => true, 'delete_posts' => true, 'delete_published_posts' => true,
            ],
            'editor'        => [
                'read' => true, 'upload_files' => true, 'manage_links' => true,
                'manage_categories' => true, 'moderate_comments' => true, 'unfiltered_html' => true,
                'edit_posts' => true, 'edit_others_posts' => true, 'edit_published_posts' => true,
                'edit_private_posts' => true, 'publish_posts' => true, 'delete_posts' => true,
                'delete_others_posts' => true, 'delete_published_posts' => true,
                'delete_private_posts' => true, 'read_private_posts' => true,
                'edit_pages' => true, 'edit_others_pages' => true, 'edit_published_pages' => true,
                'edit_private_pages' => true, 'publish_pages' => true, 'delete_pages' => true,
                'delete_others_pages' => true, 'delete_published_pages' => true,
                'delete_private_pages' => true, 'read_private_pages' => true,
            ],
            'administrator' => [], // Protected list is the source of truth.
        ];
    }

    // ========================================================================
    // AJAX - SAVE CAPABILITIES
    // ========================================================================

    public function ajax_save(): void {
        check_ajax_referer( 'admbud_roles_nonce', 'nonce' );
        if ( ! current_user_can( 'admbud_manage_roles' ) && ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }

        $slug     = sanitize_key( $_POST['role'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $raw_caps = $_POST['caps'] ?? '[]'; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
        // json_decode does not sanitise; every decoded capability slug is run
        // through sanitize_key() on the next line before any role mutation,
        // and only caps in the known admbud-managed set are touched below.
        $new_caps = json_decode( wp_unslash( $raw_caps ), true );
        if ( ! is_array( $new_caps ) ) { $new_caps = []; }
        $new_caps = array_map( 'sanitize_key', $new_caps );

        // Server-side hard guard: reject any save for administrator that would
        // remove manage_options, even if the UI was somehow bypassed.
        if ( $slug === 'administrator' && ! in_array( 'manage_options', $new_caps, true ) ) {
            wp_send_json_error( [
                'message' => __( 'The administrator role must retain manage_options. Save rejected.', 'admin-buddy' ),
            ] );
        }

        $role = get_role( $slug );
        if ( ! $role ) {
            wp_send_json_error( [ 'message' => __( 'Role not found.', 'admin-buddy' ) ] );
        }

        // Build the set of caps this handler is allowed to touch.
        $all_grouped  = $this->get_grouped_caps();
        $all_possible = [];
        foreach ( $all_grouped as $caps ) {
            foreach ( $caps as $cap ) { $all_possible[] = $cap; }
        }
        // Include caps the role currently holds so we can remove unknown ones.
        $current_caps = array_keys( array_filter( (array) $role->capabilities ) );
        $all_possible = array_unique( array_merge( $all_possible, $current_caps ) );

        foreach ( $all_possible as $cap ) {
            // Skip protected admin caps - they are re-added unconditionally below.
            if ( $slug === 'administrator' && in_array( $cap, self::ADMIN_PROTECTED, true ) ) {
                continue;
            }
            if ( in_array( $cap, $new_caps, true ) ) {
                $role->add_cap( $cap );
            } else {
                $role->remove_cap( $cap );
            }
        }

        // Unconditional final re-apply of all protected admin caps.
        if ( $slug === 'administrator' ) {
            foreach ( self::ADMIN_PROTECTED as $cap ) {
                $role->add_cap( $cap );
            }
        }

        // Post-write integrity check.
        if ( ! $this->administrator_is_intact() ) {
            $this->force_repair_administrator();
            wp_send_json_error( [
                'message' => __( 'Save aborted: post-write integrity check failed. The administrator role has been repaired automatically.', 'admin-buddy' ),
            ] );
        }

        $this->bust_role_cache( $slug );
        wp_send_json_success( [ 'message' => __( 'Role saved.', 'admin-buddy' ) ] );
    }

    // ========================================================================
    // AJAX - CREATE / CLONE
    // ========================================================================

    public function ajax_create(): void {
        check_ajax_referer( 'admbud_roles_nonce', 'nonce' );
        if ( ! current_user_can( 'admbud_manage_roles' ) && ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }

        $name       = sanitize_text_field( $_POST['name'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $clone_from = sanitize_key( $_POST['clone_from'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

        if ( empty( $name ) ) {
            wp_send_json_error( [ 'message' => __( 'Role name is required.', 'admin-buddy' ) ] );
        }

        $slug = preg_replace( '/[^a-z0-9_\-]/', '_', sanitize_title( $name ) );
        if ( get_role( $slug ) ) {
            wp_send_json_error( [ 'message' => __( 'A role with that name already exists.', 'admin-buddy' ) ] );
        }

        $caps = [ 'read' => true ];
        if ( $clone_from && ( $source = get_role( $clone_from ) ) ) {
            $caps = array_filter( (array) $source->capabilities );
            // Never clone admin-protected caps - new roles must not inherit
            // the ability to manage core, plugins, or options.
            if ( $clone_from === 'administrator' ) {
                foreach ( self::ADMIN_PROTECTED as $cap ) {
                    unset( $caps[ $cap ] );
                }
            }
        }

        add_role( $slug, $name, $caps );

        wp_send_json_success( [
            'slug'    => $slug,
            'name'    => $name,
            'caps'    => array_keys( array_filter( $caps ) ),
            'message' => __( 'Role created.', 'admin-buddy' ),
        ] );
    }

    // ========================================================================
    // AJAX - DELETE
    // ========================================================================

    /**
     * Delete a custom role.
     *
     * Uses wp_die() (not wp_send_json_error) for BUILTIN slugs so that a
     * crafted direct AJAX request cannot delete a built-in role even if the
     * JS-level protection is bypassed. The request is terminated entirely.
     */
    public function ajax_delete(): void {
        check_ajax_referer( 'admbud_roles_nonce', 'nonce' );
        if ( ! current_user_can( 'admbud_manage_roles' ) && ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }

        $slug = sanitize_key( $_POST['role'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

        // Hard stop - these slugs are absolutely off-limits.
        if ( $slug === 'administrator' ) {
            wp_die( esc_html__( 'The administrator role cannot be deleted.', 'admin-buddy' ), 403 );
        }
        if ( in_array( $slug, self::BUILTIN, true ) ) {
            wp_die( esc_html__( 'Built-in roles cannot be deleted.', 'admin-buddy' ), 403 );
        }

        if ( ! get_role( $slug ) ) {
            wp_send_json_error( [ 'message' => __( 'Role not found.', 'admin-buddy' ) ] );
        }

        // Migrate users to subscriber before removal.
        $users = get_users( [ 'role' => $slug, 'fields' => 'ID', 'number' => -1 ] );
        foreach ( $users as $user_id ) {
            ( new \WP_User( (int) $user_id ) )->set_role( 'subscriber' );
        }

        remove_role( $slug );

        // Verify administrator survived the removal.
        if ( ! $this->administrator_is_intact() ) {
            $this->force_repair_administrator();
        }

        wp_send_json_success( [
            'message'        => __( 'Role deleted.', 'admin-buddy' ),
            'users_migrated' => count( $users ),
        ] );
    }

    // ========================================================================
    // AJAX - RENAME
    // ========================================================================

    /**
     * Rename a role's display name.
     *
     * This is the only handler that must call update_option() directly (WP has
     * no API for renaming a role's display name). To protect against the
     * corruption that affected users' sites, we:
     *   1. Take a snapshot of the current option value.
     *   2. Update the in-memory WP_Roles object.
     *   3. Write to the database.
     *   4. Re-read the database and verify administrator is still intact.
     *   5. If not, restore the snapshot and return an error.
     */
    public function ajax_rename(): void {
        check_ajax_referer( 'admbud_roles_nonce', 'nonce' );
        if ( ! current_user_can( 'admbud_manage_roles' ) && ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }

        $slug = sanitize_key( $_POST['role'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $name = sanitize_text_field( $_POST['name'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

        if ( empty( $name ) ) {
            wp_send_json_error( [ 'message' => __( 'Name cannot be empty.', 'admin-buddy' ) ] );
        }

        $wp_roles = $this->get_wp_roles();

        if ( ! isset( $wp_roles->roles[ $slug ] ) ) {
            wp_send_json_error( [ 'message' => __( 'Role not found.', 'admin-buddy' ) ] );
        }

        // Snapshot before touching anything.
        $snapshot    = get_option( $wp_roles->role_key );
        $option_key  = $wp_roles->role_key;

        // Verify snapshot is healthy before we overwrite it.
        if ( ! is_array( $snapshot )
             || ! isset( $snapshot['administrator'] )
             || empty( $snapshot['administrator']['capabilities']['manage_options'] )
        ) {
            wp_send_json_error( [
                'message' => __( 'Rename aborted: the current role data is already in an inconsistent state. Please use the repair function.', 'admin-buddy' ),
            ] );
        }

        // Apply the name change to the in-memory object.
        $wp_roles->roles[ $slug ]['name'] = $name;
        $wp_roles->role_names[ $slug ]    = $name;

        // Write to DB.
        update_option( $option_key, $wp_roles->roles );

        // Bust the options cache and re-read.
        wp_cache_delete( $option_key, 'options' );

        // Post-write integrity check.
        if ( ! $this->administrator_is_intact() ) {
            // Roll back.
            update_option( $option_key, $snapshot );
            // Reinitialise from the restored snapshot.
            global $wp_roles;
            $wp_roles = new \WP_Roles();
            wp_send_json_error( [
                'message' => __( 'Rename aborted: the write corrupted role data. The previous state has been restored.', 'admin-buddy' ),
            ] );
        }

        wp_send_json_success( [ 'message' => __( 'Role renamed.', 'admin-buddy' ) ] );
    }

    // ========================================================================
    // AJAX - CLONE
    // ========================================================================

    public function ajax_clone(): void {
        $this->ajax_create();
    }

    // ========================================================================
    // AJAX - RESET
    // ========================================================================

    public function ajax_reset(): void {
        check_ajax_referer( 'admbud_roles_nonce', 'nonce' );
        if ( ! current_user_can( 'admbud_manage_roles' ) && ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }

        $slug = sanitize_key( $_POST['role'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

        if ( ! in_array( $slug, self::BUILTIN, true ) ) {
            wp_send_json_error( [ 'message' => __( 'Only built-in roles can be reset to defaults.', 'admin-buddy' ) ] );
        }

        $role = get_role( $slug );
        if ( ! $role ) {
            wp_send_json_error( [ 'message' => __( 'Role not found.', 'admin-buddy' ) ] );
        }

        if ( $slug === 'administrator' ) {
            // For administrator: ONLY add - never strip anything.
            foreach ( self::ADMIN_PROTECTED as $cap ) {
                $role->add_cap( $cap );
            }
        } else {
            // For other built-ins: wipe then rebuild from canonical defaults.
            foreach ( array_keys( (array) $role->capabilities ) as $cap ) {
                $role->remove_cap( $cap );
            }
            $defaults = self::get_default_caps();
            foreach ( ( $defaults[ $slug ] ?? [] ) as $cap => $grant ) {
                if ( $grant ) { $role->add_cap( $cap ); }
            }
        }

        // Post-write integrity check.
        if ( ! $this->administrator_is_intact() ) {
            $this->force_repair_administrator();
            wp_send_json_error( [
                'message' => __( 'Reset aborted: post-write integrity check failed. The administrator role has been repaired.', 'admin-buddy' ),
            ] );
        }

        $this->bust_role_cache( $slug );
        wp_send_json_success( [ 'message' => __( 'Role reset to defaults.', 'admin-buddy' ) ] );
    }

    // ========================================================================
    // PRIVATE HELPERS
    // ========================================================================

    /**
     * Return the global WP_Roles instance, ensuring it exists and that
     * the roles property is always an array (PHP 8.1+ safety).
     */
    private function get_wp_roles(): \WP_Roles {
        global $wp_roles;
        if ( ! ( $wp_roles instanceof \WP_Roles ) ) {
            $wp_roles = new \WP_Roles();
        }
        if ( ! is_array( $wp_roles->roles ) ) {
            $wp_roles->roles = [];
        }
        return $wp_roles;
    }

    /**
     * Verify the administrator role is intact by reading directly from the DB.
     * Bypasses object cache to catch same-request corruption.
     */
    private function administrator_is_intact(): bool {
        $option_key = $this->get_wp_roles()->role_key;
        wp_cache_delete( $option_key, 'options' );
        $raw = get_option( $option_key );
        return is_array( $raw )
            && isset( $raw['administrator'] )
            && ! empty( $raw['administrator']['capabilities']['manage_options'] );
    }

    /**
     * Emergency repair: forcibly re-add every ADMIN_PROTECTED cap.
     * If the administrator role object itself is gone, call populate_roles().
     */
    private function force_repair_administrator(): void {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( 'Admin Buddy: emergency administrator role repair triggered.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }

        $role = get_role( 'administrator' );
        if ( $role ) {
            foreach ( self::ADMIN_PROTECTED as $cap ) {
                $role->add_cap( $cap );
            }
        } else {
            if ( ! function_exists( 'populate_roles' ) ) {
                require_once ABSPATH . 'wp-admin/includes/schema.php';
            }
            populate_roles();
            global $wp_roles;
            $wp_roles = new \WP_Roles();
        }
    }

    /**
     * Clear WP_User capability caches so changes take effect immediately.
     */
    private function bust_role_cache( string $slug ): void {
        $ids = get_users( [ 'role' => $slug, 'fields' => 'ID', 'number' => -1 ] );
        foreach ( $ids as $id ) {
            clean_user_cache( (int) $id );
        }
    }

    // ========================================================================
    // ROLE BACKUP
    // ========================================================================

    /**
     * AJAX: download a JSON snapshot of all current roles.
     * Triggers a file download in the browser.
     */
    public function ajax_backup(): void {
        check_ajax_referer( 'admbud_roles_nonce', 'nonce' );
        if ( ! current_user_can( 'admbud_manage_roles' ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Access denied.', 'admin-buddy' ) ] );
        }

        $wp_roles = $this->get_wp_roles();
        $roles    = (array) $wp_roles->roles;

        $payload = [
            'version'    => ADMBUD_VERSION,
            'site_url'   => get_site_url(),
            'created_at' => current_time( 'c' ),
            'roles'      => $roles,
        ];

        // Store last-backup timestamp for the toolbar badge.
        admbud_update_option( 'admbud_roles_last_backup', current_time( 'timestamp' ), false );

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- admbud_ is the plugin prefix.
        do_action( 'admbud_roles_backed_up' );
        wp_send_json_success( $payload );
    }
}


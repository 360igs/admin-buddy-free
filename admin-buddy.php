<?php
/**
 * Plugin Name:       Admin Buddy
 * Description:       White-label your WordPress admin - custom branding, dashboard page, login styling, notice suppression, and maintenance mode in one place.
 * Version:           1.1.0
 * Requires at least: 6.4
 * Tested up to:      7.0
 * Requires PHP:      8.1
 * Author:            Admin Buddy
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       admin-buddy
 * Domain Path:       /languages
 * Network:           true
 *
 * @package Admbud
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


// -- Uninstall cleanup ---------------------------------------------------------
// Loaded unconditionally so admbud_uninstall_cleanup() is available both at
// runtime (when WP needs to register the hook) and at uninstall time (when WP
// re-includes this file before firing the hook).
require_once dirname( __FILE__ ) . '/includes/uninstall.php';

if ( ! function_exists( 'admbud_fs' ) ) {
    // Free build (no FS SDK): standard WP uninstall hook.
    register_uninstall_hook( __FILE__, 'admbud_uninstall_cleanup' );
}

// -- Duplicate plugin guard ----------------------------------------------------
// If ADMBUD_FILE is already defined, another copy of Admin Buddy is active.
// Bail immediately and show an admin notice so the conflict is visible.
if ( defined( 'ADMBUD_FILE' ) ) {
    add_action( 'admin_notices', function () {
        $existing = plugin_basename( ADMBUD_FILE );
        $current  = plugin_basename( __FILE__ );
        echo '<div class="notice notice-error"><p>'
            . '<strong>Admin Buddy:</strong> '
            . esc_html__( 'A duplicate copy of Admin Buddy was detected and blocked from loading.', 'admin-buddy' )
            . ' <code>' . esc_html( $current ) . '</code> '
            . esc_html__( 'conflicts with the already-active copy at', 'admin-buddy' )
            . ' <code>' . esc_html( $existing ) . '</code>. '
            . esc_html__( 'Please deactivate and delete the duplicate.', 'admin-buddy' )
            . '</p></div>';
    } );
    return; // Stop loading this copy - don't define constants or hooks.
}

// -- Plugin constants ----------------------------------------------------------

define( 'ADMBUD_VERSION',  '1.1.0' );
define( 'ADMBUD_FILE',     __FILE__ );
define( 'ADMBUD_DIR',      plugin_dir_path( __FILE__ ) );
define( 'ADMBUD_URL',      plugin_dir_url( __FILE__ ) );
define( 'ADMBUD_BASENAME', plugin_basename( __FILE__ ) );
define( 'ADMBUD_SRC',      ADMBUD_DIR . 'src/' );

// -- PSR-4 autoloader for Admbud\ namespace (src/ directory) --------------
//
// `'Admbud\\'` in a single-quoted PHP string is 7 chars at runtime
// (A-d-m-b-u-d-\). The strncmp+substr lengths MUST stay in sync with
// strlen of the prefix — historically this autoloader was hardcoded to
// 11 chars (correct for the old `AdminBuddy\` prefix), and the rename
// to `Admbud\` in commit 2fa710f silently broke autoloading without
// surfacing because Free-only code paths use explicit require_once.
// `strlen()` of a string literal is constant-folded at compile time
// in PHP 8+, so this is zero runtime cost AND rename-proof.
spl_autoload_register( static function ( string $class ): void {
    $prefix     = 'Admbud\\';
    $prefix_len = strlen( $prefix );
    if ( strncmp( $prefix, $class, $prefix_len ) !== 0 ) {
        return;
    }
    $file = ADMBUD_SRC . str_replace( '\\', '/', substr( $class, $prefix_len ) ) . '.php';
    if ( file_exists( $file ) ) {
        require $file;
    }
} );

// -- Autoload includes ---------------------------------------------------------

// Options abstraction layer - loads the Options class then defines the global
// helper functions (admbud_get_option / admbud_update_option / admbud_delete_option).
// Uses require (not require_once) to bypass opcode-cache path mismatches.
// Global functions are defined here (not in class-options.php) so there is
// never a redeclaration conflict regardless of load order.
$_admbud_options_file = ADMBUD_DIR . 'includes/class-options.php'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
if ( file_exists( $_admbud_options_file ) ) {
    require $_admbud_options_file;
}
unset( $_admbud_options_file );

// Define global helper functions that delegate to the Options class.
// Always defined here - class-options.php defines the Options class only,
// not these global functions, so there is no redeclaration conflict.
if ( ! function_exists( 'admbud_get_option' ) ) {
    function admbud_get_option( string $key, $default = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- admbud_ is the plugin prefix.
        if ( class_exists( '\Admbud\Options' ) ) {
            return \Admbud\Options::get( $key, $default );
        }
        return get_option( $key, $default );
    }
}
if ( ! function_exists( 'admbud_update_option' ) ) {
    function admbud_update_option( string $key, $value, bool $autoload = true ): bool { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- admbud_ is the plugin prefix.
        if ( class_exists( '\Admbud\Options' ) ) {
            return \Admbud\Options::update( $key, $value, $autoload );
        }
        return update_option( $key, $value, $autoload );
    }
}
if ( ! function_exists( 'admbud_delete_option' ) ) {
    function admbud_delete_option( string $key ): bool { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- admbud_ is the plugin prefix.
        if ( class_exists( '\Admbud\Options' ) ) {
            return \Admbud\Options::delete( $key );
        }
        return delete_option( $key );
    }
}

if ( ! function_exists( 'admbud_option' ) ) {
    /**
     * Retrieve an Option Pages field value.
     * Usage: $val = admbud_option( 'field_key', 'page-slug' );
     */
    function admbud_option( string $field_key, string $page_slug, $default = '' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- admbud_ is the plugin prefix.
        if ( class_exists( '\Admbud\OptionPages' ) ) {
            return \Admbud\OptionPages::get_value( $field_key, $page_slug, $default );
        }
        $slug_safe = str_replace( '-', '_', sanitize_key( $page_slug ) );
        $key_safe  = sanitize_key( $field_key );
        return get_option( 'admbud_op_' . $slug_safe . '_' . $key_safe, $default );
    }
}

if ( ! function_exists( 'admbud_field' ) ) {
    /**
     * Retrieve a Collections meta field value.
     * Usage: $role = admbud_field( '_ab_coll_team_role' );
     *        $links = json_decode( admbud_field( '_ab_coll_team_social_links', 0, '[]' ), true );
     */
    function admbud_field( string $key, int $post_id = 0, $default = '' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- admbud_ is the plugin prefix.
        if ( ! $post_id ) { $post_id = get_the_ID(); }
        if ( ! $post_id ) { return $default; }
        $value = get_post_meta( $post_id, $key, true );
        return ( $value !== '' && $value !== false ) ? $value : $default;
    }
}

// Pre-load: Settings, Core, Dashboard, Notices are always-on.
// These files must always be required up-front:
// - class-colours.php     : constants used by the activation hook (DEFAULT_* values)
// - class-maintenance.php : TOKEN_OPTION constant + generate_token() called at activation
// - class-adminbar.php    : always boots (status pills); no activation-hook deps but low cost
// - class-settings.php    : Settings::get_instance() always boots
// - class-core.php / class-dashboard.php / class-notices.php : always-on modules
// - class-snippets.php    : Pro-only since 2026-05-11 (file_exists() guard kept on
//                            the pre-load below in case a Pro Snippets file is present)

// Traits must load before the class that uses them.
foreach ( [ 'trait-settings-sanitizers.php', 'trait-settings-render.php', 'trait-settings-tools.php' ] as $_admbud_trait ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    require_once ADMBUD_DIR . 'includes/' . $_admbud_trait;
}
unset( $_admbud_trait );

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$_admbud_preload = [ 'class-colours.php', 'class-maintenance.php', 'class-adminbar.php',
                     'class-settings.php', 'class-core.php', 'class-dashboard.php',
                     'class-notices.php', 'class-checklist.php' ];
foreach ( $_admbud_preload as $_admbud_file ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    $_admbud_path = ADMBUD_DIR . 'includes/' . $_admbud_file; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    if ( file_exists( $_admbud_path ) ) { require_once $_admbud_path; }
}
unset( $_admbud_preload, $_admbud_file, $_admbud_path );

// -- Bootstrap -----------------------------------------------------------------

/**
 * Returns enabled module slugs from the DB.
 * Called early - Settings class may not be instantiated yet.
 * Returns null on first launch (option never saved).
 */
function admbud_enabled_modules(): ?array {
    $stored = admbud_get_option( 'admbud_modules_enabled_tabs', '__not_set__' );
    if ( $stored === '__not_set__' || $stored === false || $stored === '' ) {
        return [];
    }
    return array_filter( explode( ',', (string) $stored ) );
}

/**
 * Initialise Admin Buddy modules.
 * Only loads PHP files and registers hooks for enabled modules.
 * Always-on: Settings, Core, Dashboard, Notices.
 */
function admbud_init() {
    // First-activation redirect to Modules tab.
    add_action( 'admin_init', function () {
        if ( ! get_transient( 'admbud_activation_redirect' ) ) { return; }
        delete_transient( 'admbud_activation_redirect' );
        // Skip on bulk activation, network activation, or AJAX.
        if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
        wp_safe_redirect( admin_url( 'admin.php?page=admbud&tab=modules&admbud_welcome=1' ) );
        exit;
    } );

    // Run DB migrations before anything else boots.
    require_once ADMBUD_DIR . 'includes/class-upgrade.php';
    Admbud\Upgrade::maybe_run_upgrades();

    // Bust CSS cache when plugin version changes (new rules won't show otherwise).
    $admbud_last_ver = get_option( 'admbud_plugin_version', '' );
    if ( $admbud_last_ver !== ADMBUD_VERSION ) {
        delete_transient( 'admbud_css_' . $admbud_last_ver );
        delete_transient( 'admbud_css_' . ADMBUD_VERSION );
        update_option( 'admbud_plugin_version', ADMBUD_VERSION, false );
    }



    $enabled = admbud_enabled_modules();

    // Always-on infrastructure.
    Admbud\Settings::get_instance();
    Admbud\Core::get_instance();
    Admbud\Dashboard::get_instance();
    Admbud\Notices::get_instance();
    Admbud\Checklist::get_instance();


    // Network admin page - multisite only, no-op on single sites.


    $has = static function ( string $slug ) use ( $enabled ): bool {
        return in_array( $slug, $enabled, true );
    };

    // AdminBar always boots (status pills tied to Maintenance).
    // Colours, Maintenance, AdminBar are pre-required at file load - just instantiate.
    Admbud\AdminBar::get_instance();

    if ( $has( 'colours' ) ) {
        Admbud\Colours::get_instance();
    }

    if ( $has( 'login' ) ) {
        require_once ADMBUD_DIR . 'includes/class-login.php';
        Admbud\Login::get_instance();
    }

    // Maintenance always boots - active mode must protect site regardless of Setup toggle.
    Admbud\Maintenance::get_instance();


    if ( $has( 'smtp' ) ) {
        require_once ADMBUD_DIR . 'includes/class-smtp.php';
        Admbud\SMTP::get_instance();
    }


    if ( $has( 'roles' ) ) {
        require_once ADMBUD_DIR . 'includes/class-roles.php';
        Admbud\Roles::get_instance();
    }










    if ( $has( 'media-manager' ) ) {
        // Folder-core is FREE (taxonomy + native-library sidebar + folder CRUD);
        // the tools (Bulk SEO/Replace/scans/rename/gallery), per-role visibility
        // and Trash are Pro, stripped from this class via AB_PRO markers inside it.
        // Boots whenever the module is enabled (not tied to a settings tab).
        require_once ADMBUD_DIR . 'includes/class-media-manager.php';
        Admbud\MediaManager::get_instance();

        // Media Trash (Free): opt-in to WP's native attachment trash. WP gates this
        // on the MEDIA_TRASH constant, only read in admin/ajax delete paths that run
        // AFTER plugins_loaded - so a guarded runtime define here is enough (no
        // wp-config edit). The `! defined` guard respects a user-set MEDIA_TRASH.
        if ( Admbud\MediaManager::trash_enabled() && ! defined( 'MEDIA_TRASH' ) ) {
            define( 'MEDIA_TRASH', true );
        }
    }


    // Quick Settings always boots - applies saved toggles site-wide.
    require_once ADMBUD_DIR . 'includes/class-quick-settings.php';
    Admbud\QuickSettings::get_instance();
}
add_action( 'plugins_loaded', 'admbud_init' );

// -- Activation / deactivation hooks ------------------------------------------

register_activation_hook( __FILE__, 'admbud_activate' );
function admbud_activate(): void {
    admbud_activate_site();
}

/**
 * Per-site activation logic - runs for each site individually.
 * Idempotent: safe to call multiple times on the same site.
 */
function admbud_activate_site(): void {
    // Write all defaults to DB. add_option is a no-op when the key already
    // exists, so re-activation never overwrites a user's saved settings.
    foreach ( \Admbud\Settings::defaults() as $key => $value ) {
        // admbud_maintenance_mode must be autoloaded (checked on every frontend request).
        $autoload = ( $key === 'admbud_maintenance_mode' );
        add_option( $key, $value, '', $autoload );
    }



    // Generate emergency access token on first activation.
    if ( ! get_option( \Admbud\Maintenance::TOKEN_OPTION ) ) {
        \Admbud\Maintenance::generate_token();
    }

    // Grant custom Admin Buddy capabilities to the administrator role.
    admbud_grant_caps();

    // Set a transient to trigger first-activation redirect to Modules tab.
    set_transient( 'admbud_activation_redirect', '1', 60 );
}

/**
 * Check if the plugin has an active license.
 *
 * Returns true if:
 *   - The Freemius SDK is not present (Free build - no license needed)
 *   - The user has connected the plugin via Freemius (free or paid plan)
 *
 * @return bool
 */
function admbud_is_licensed(): bool {
    // Free build: no SDK = no license needed, Pro features are physically absent.
    if ( ! function_exists( 'admbud_fs' ) ) {
        return true;
    }

    return admbud_fs()->can_use_premium_code();
}

/**
 * Check if the license is a paid tier.
 *
 * @return bool
 */
function admbud_is_paid(): bool {
    if ( ! function_exists( 'admbud_fs' ) ) {
        return false;
    }

    return admbud_fs()->is_paying();
}

/**
 * Get the list of modules available for the current license tier.
 *
 * @return array List of module slugs the current license can access.
 */
function admbud_allowed_modules(): array {
    // Free tier modules - available to all licensed users.
    // Per final v1.0 spec: free modules ship fully unlocked (no in-module Pro
    // locks) except where noted.
    $free_modules = [
        'adminui',         // White Label (full in free, including Custom Dashboard Page + Sidebar Logo)
        'colours',         // Colours (full in free; Auto Palette subtab is Pro)
        'login',           // Login (full in free)
        'maintenance',     // Maintenance (full in free)
        'smtp',            // SMTP (full in free)
        'roles',           // User Roles (full in free)
        'quick-settings',  // Quick Settings (full in free)
        'media-manager',   // Media Manager - FREE folder core (folders/tree/drag/upload/filter);
                           // all tools + gallery + per-role visibility + trash are Pro (AB_PRO-stripped in-class).
    ];


    return $free_modules;
}

/**
 * Check if the current user is on the free plan (Pro features should be locked).
 * Short alias used in all render files.
 *
 * @return bool True if Pro features should be locked.
 */
function admbud_is_pro(): bool { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- admbud_ is the plugin prefix.
    static $result = null;
    if ( $result !== null ) { return $result; }

    // Pro features are unlocked ONLY when the licensing SDK is present
    // AND the user has a valid paid license. Everything else = locked.
    $result = ! ( function_exists( 'admbud_is_paid' ) && admbud_is_paid() );
    return $result;
}


/**
 * Allowed elements + attributes for inline-SVG output.
 *
 * Used as the second argument to wp_kses() at every echo site that emits an
 * SVG. Defined as a constant array (not returned from a helper) so reviewers
 * and Plugin Check see a literal wp_kses() call at the output site instead of
 * a wrapper function they have to introspect.
 */
function admbud_kses_svg_allowed(): array { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
    static $allowed = null;
    if ( $allowed === null ) {
        $allowed = [
            'svg'      => [ 'xmlns' => [], 'width' => [], 'height' => [], 'viewbox' => [], 'fill' => [], 'stroke' => [], 'stroke-width' => [], 'stroke-linecap' => [], 'stroke-linejoin' => [], 'class' => [], 'style' => [], 'aria-hidden' => [] ],
            'path'     => [ 'd' => [], 'fill' => [], 'stroke' => [], 'stroke-width' => [], 'stroke-linecap' => [], 'stroke-linejoin' => [], 'fill-rule' => [], 'clip-rule' => [] ],
            'circle'   => [ 'cx' => [], 'cy' => [], 'r' => [], 'fill' => [], 'stroke' => [], 'stroke-width' => [] ],
            'rect'     => [ 'x' => [], 'y' => [], 'width' => [], 'height' => [], 'rx' => [], 'ry' => [], 'fill' => [], 'stroke' => [], 'stroke-width' => [] ],
            'line'     => [ 'x1' => [], 'y1' => [], 'x2' => [], 'y2' => [], 'stroke' => [], 'stroke-width' => [] ],
            'polyline' => [ 'points' => [], 'fill' => [], 'stroke' => [], 'stroke-width' => [] ],
            'polygon'  => [ 'points' => [], 'fill' => [], 'stroke' => [], 'stroke-width' => [] ],
            'ellipse'  => [ 'cx' => [], 'cy' => [], 'rx' => [], 'ry' => [], 'fill' => [], 'stroke' => [] ],
            'g'        => [ 'fill' => [], 'stroke' => [], 'transform' => [], 'class' => [] ],
            'defs'     => [],
            'clippath' => [ 'id' => [] ],
            'mask'     => [ 'id' => [] ],
            'use'      => [ 'href' => [], 'xlink:href' => [] ],
            'text'     => [ 'x' => [], 'y' => [], 'fill' => [], 'font-size' => [], 'text-anchor' => [], 'dominant-baseline' => [] ],
            'tspan'    => [ 'x' => [], 'y' => [], 'fill' => [] ],
            'span'     => [ 'class' => [], 'style' => [] ],
        ];
    }
    return $allowed;
}

/**
 * Back-compat wrapper around wp_kses() with the SVG ruleset.
 *
 * Prefer calling `wp_kses( $svg, admbud_kses_svg_allowed() )` directly at echo
 * sites so Plugin Check / WP.org reviewers see the escape function literally.
 */
function admbud_kses_svg( string $svg ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
    return wp_kses( $svg, admbud_kses_svg_allowed() );
}

/**
 * Grant Admin Buddy custom capabilities to the administrator role.
 *
 * Called on activation and can be called manually if capabilities are lost.
 * Uses `add_cap()` which is idempotent - safe to call multiple times.
 *
 * Custom capabilities:
 *   admbud_manage_roles    - access to the User Roles tab and all its AJAX actions.
 *   admbud_manage_snippets - access to the Snippets tab and all its AJAX actions (Pro).
 */
function admbud_grant_caps(): void {
    $admin = get_role( 'administrator' );
    if ( $admin ) {
        $admin->add_cap( 'admbud_manage_roles' );
    }
}

register_deactivation_hook( __FILE__, 'admbud_deactivate' );
function admbud_deactivate() {
    // Nothing to clean up on deactivation.
    // Options are intentionally preserved so settings survive deactivate/reactivate.
}




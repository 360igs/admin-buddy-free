<?php
/**
 * Plugin Name:       Admin Buddy
 * Description:       White-label your WordPress admin - custom branding, dashboard page, login styling, notice suppression, and maintenance mode in one place.
 * Version:           1.0.1
 * Requires at least: 6.4
 * Tested up to:      6.9
 * Requires PHP:      8.1
 * Author:            Admin Buddy
 * Plugin URI:        https://wpadminbuddy.com
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

define( 'ADMBUD_VERSION',  '1.0.1' );
define( 'ADMBUD_FILE',     __FILE__ );
define( 'ADMBUD_DIR',      plugin_dir_path( __FILE__ ) );
define( 'ADMBUD_URL',      plugin_dir_url( __FILE__ ) );
define( 'ADMBUD_BASENAME', plugin_basename( __FILE__ ) );
define( 'ADMBUD_SRC',      ADMBUD_DIR . 'src/' );

// -- PSR-4 autoloader for Admbud\ namespace (src/ directory) --------------
spl_autoload_register( static function ( string $class ): void {
    if ( strncmp( 'Admbud\\', $class, 11 ) !== 0 ) {
        return;
    }
    $file = ADMBUD_SRC . str_replace( '\\', '/', substr( $class, 11 ) ) . '.php';
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

// Pre-load: Settings, Core, Dashboard, Notices, and Snippets are always-on.
// These files must always be required up-front:
// - class-colours.php     : constants used by the activation hook (DEFAULT_* values)
// - class-maintenance.php : TOKEN_OPTION constant + generate_token() called at activation
// - class-adminbar.php    : always boots (status pills); no activation-hook deps but low cost
// - class-snippets.php    : Snippets::ensure_dir() called during activation (file-based, no table)
// - class-settings.php    : Settings::get_instance() always boots
// - class-core.php / class-dashboard.php / class-notices.php : always-on modules

// Traits must load before the class that uses them.
foreach ( [ 'trait-settings-sanitizers.php', 'trait-settings-render.php', 'trait-settings-tools.php' ] as $_admbud_trait ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    require_once ADMBUD_DIR . 'includes/' . $_admbud_trait;
}
unset( $_admbud_trait );

foreach ( [ 'class-colours.php', 'class-maintenance.php', 'class-adminbar.php',
             'class-settings.php', 'class-core.php', 'class-dashboard.php',
             'class-notices.php', 'class-snippets.php', 'class-checklist.php' ] as $_admbud_file ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    $_admbud_path = ADMBUD_DIR . 'includes/' . $_admbud_file; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    if ( file_exists( $_admbud_path ) ) { require_once $_admbud_path; }
}
unset( $_admbud_file, $_admbud_path );

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
        wp_safe_redirect( admin_url( 'admin.php?page=admin-buddy&tab=modules&admbud_welcome=1' ) );
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


    // Object-cache wrapper for get_active_snippets() - Phase 4 hardening.
    require_once ADMBUD_SRC . 'Core/SnippetsCache.php';

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

    if ( $has( 'snippets' ) ) {
        // class-snippets.php was pre-loaded for activation hook; just boot.
        Admbud\Snippets::get_instance();
    }

    if ( $has( 'smtp' ) ) {
        require_once ADMBUD_DIR . 'includes/class-smtp.php';
        Admbud\SMTP::get_instance();
    }


    if ( $has( 'roles' ) ) {
        require_once ADMBUD_DIR . 'includes/class-roles.php';
        Admbud\Roles::get_instance();
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

    // Snippets are now file-based - no table needed.
    // Ensure the snippet directory exists for this site.
    \Admbud\Snippets::ensure_dir();


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
 *   - The SureCart SDK is not present (Lite version - no license needed)
 *   - Demo mode is active (always unlocked)
 *   - A valid license key is activated via SureCart
 *
 * @return bool
 */
function admbud_is_licensed(): bool {
    // Lite version: no SDK = no license needed, features are just absent.
    if ( ! file_exists( ADMBUD_DIR . 'licensing/src/Client.php' ) ) {
        return true;
    }

    // Check via SureCart SDK client.
    if ( isset( $GLOBALS['admbud_license_client'] ) ) {
        try {
            $license = $GLOBALS['admbud_license_client']->license();
            return $license ? $license->is_valid() : false;
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    return false;
}

/**
 * Check if the license is a paid tier.
 *
 * SureCart license activation_limit values:
 *   null = unlimited activations = free tier
 *   > 0  = paid tier (20, 50, 100, etc)
 *
 * @return bool
 */
function admbud_is_paid(): bool {
    if ( ! admbud_is_licensed() ) {
        return false;
    }

    // activation_limit stored values:
    //   option not set = no license synced yet
    //   'unlimited'    = null from SureCart = free tier
    //   integer > 0    = paid tier (20, 50, 100, etc)
    $limit = get_option( 'admbud_license_activation_limit', '' );
    if ( $limit === '' || $limit === 'unlimited' ) {
        return false;
    }
    return ( (int) $limit > 0 );
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
        'snippets',        // Snippets (full in free; Share toggle hidden)
        'roles',           // User Roles (full in free)
        'quick-settings',  // Quick Settings (full in free)
    ];

    // Pro-only modules - not available at all on free.
    $pro_modules = [
        'notices-updates', // Notices & Updates (hide nags, disable auto-updates)
        'menus',           // Menu Customiser
        'custom-pages',    // Custom Pages
        'collections',     // Collections (CPT/fields)
        'optionpages',     // Option Pages
        'svg-library',     // Icon Library
        'activity-log',    // Activity Log
        'debug',           // Debug (toggle constants + view error log)
        'bricks',          // Bricks Builder integration
        'export-import',   // Export / Import
        'source',          // Remote
        'demo-data',       // Demo Data
        'blueprint',       // Blueprints
    ];

    if ( admbud_is_paid() ) {
        return array_merge( $free_modules, $pro_modules );
    }

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
 * Render a Pro badge + info icon with tooltip.
 * Outputs nothing for paid users.
 *
 * Usage: <th>Sidebar Gradient <?php admbud_pro('This is a Pro feature.'); ?></th>
 *
 * @param string $msg Tooltip text.
 */
function admbud_pro( string $msg ): void { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- admbud_ is the plugin prefix.
    if ( ! admbud_is_pro() ) { return; }
    ?>
    <span class="ab-pro-tag" tabindex="0">
        <span class="ab-badge ab-badge--pro">Pro</span>
        <svg class="ab-pro-tag__icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
        <span class="ab-tip"><?php echo esc_html( $msg ); ?> <a href="https://wpadminbuddy.com" target="_blank"><?php esc_html_e( 'Upgrade', 'admin-buddy' ); ?></a></span>
    </span>
    <?php
}

/**
 * Render a Pro info banner (used on Modules tab).
 *
 * @param string $text Banner text.
 */
function admbud_pro_banner( string $text ): void { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- admbud_ is the plugin prefix.
    if ( ! admbud_is_pro() ) { return; }
    ?>
    <div class="ab-pro-notice" style="background:linear-gradient(135deg, rgba(124,58,237,0.06), rgba(124,58,237,0.02));border:1px solid rgba(124,58,237,0.15);border-radius:var(--ab-radius, 8px);padding:14px 20px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:16px;">
        <div style="display:flex;align-items:center;gap:8px;">
            <span class="ab-badge ab-badge--pro">Pro</span>
            <p style="margin:0;font-size:13px;color:var(--ab-text-secondary, #6b7280);line-height:1.4;"><?php echo esc_html( $text ); ?></p>
        </div>
        <a href="https://wpadminbuddy.com" target="_blank" class="ab-btn ab-btn--sm ab-btn--pro-cta" style="flex-shrink:0;"><?php esc_html_e( 'Upgrade', 'admin-buddy' ); ?></a>
    </div>
    <?php
}

// Keep old names as aliases for backward compat during refactor.
function admbud_is_pro_locked(): bool { return admbud_is_pro(); }
function admbud_pro_tag( string $msg ): void { admbud_pro( $msg ); }
function admbud_pro_notice( string $text ): void { admbud_pro_banner( $text ); }

/**
 * Sanitise an SVG string for safe output.
 *
 * Allows common SVG elements and attributes used in icon rendering.
 *
 * @param string $svg Raw SVG markup.
 * @return string Sanitised SVG.
 */
function admbud_kses_svg( string $svg ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
    return wp_kses( $svg, [
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
    ] );
}

/**
 * Grant Admin Buddy custom capabilities to the administrator role.
 *
 * Called on activation and can be called manually if capabilities are lost.
 * Uses `add_cap()` which is idempotent - safe to call multiple times.
 *
 * Custom capabilities:
 *   admbud_manage_roles    - access to the User Roles tab and all its AJAX actions.
 *   admbud_manage_snippets - access to the Snippets tab and all its AJAX actions.
 */
function admbud_grant_caps(): void {
    $admin = get_role( 'administrator' );
    if ( $admin ) {
        $admin->add_cap( 'admbud_manage_roles' );
        $admin->add_cap( 'admbud_manage_snippets' );
    }
}

register_deactivation_hook( __FILE__, 'admbud_deactivate' );
function admbud_deactivate() {
    // Nothing to clean up on deactivation.
    // Options are intentionally preserved so settings survive deactivate/reactivate.
}




<?php
/**
 * Maintenance - Coming Soon or Maintenance mode for non-logged-in visitors.
 *
 * -- Modes ---------------------------------------------------------------------
 *  off          : disabled - site is fully accessible.
 *  coming_soon  : 200 OK - pre-launch placeholder. Search engines may index it.
 *  maintenance  : 503 + Retry-After - temporary outage. Search engines wait,
 *                 they will NOT deindex the site.
 *
 * -- Emergency Access ---------------------------------------------------------
 *  A secret token URL (yoursite.com/?admbud_access=TOKEN) removes the intercept
 *  page for the visitor's browser session, then redirects to home so they can
 *  reach their site's login page - regardless of which login plugin is active.
 *
 *  How it works:
 *   1. Token validated with hash_equals() - timing-attack safe.
 *   2. Session bypass cookie set immediately (HMAC-signed, HttpOnly, Secure).
 *   3. Visitor redirected to home_url() - can now navigate to any login page.
 *
 *  What it grants: removal of the cosmetic intercept page for this session.
 *  What it does NOT grant: any WordPress capability or authentication.
 *  The visitor still needs valid credentials to access admin or protected areas.
 *
 *  Token is a random_bytes(16) → 32-char hex string. Can be regenerated from
 *  the settings page to immediately invalidate the old URL.
 *
 * -- Bypass priority order -----------------------------------------------------
 *  1. WP cron                          - always let through
 *  2. Valid bypass cookie              - set only after two-stage login
 *  3. Logged-in admin (manage_options) - always let through
 *  4. wp-login.php + /wp-admin/        - auto-bypassed
 *  5. User-defined bypass URLs         - one path per line in settings
 *  6. Everything else                  - intercepted
 *
 * @package Admbud
 */

namespace Admbud;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Maintenance {

    // -- Constants -------------------------------------------------------------

    /** Possible values for the admbud_maintenance_mode option. */
    const MODE_OFF         = 'off';
    const MODE_COMING_SOON = 'coming_soon';
    const MODE_MAINTENANCE = 'maintenance';

    /** Name of the session bypass cookie. */
    const COOKIE_NAME = 'admbud_emergency_access';

    /** DB option key that holds the secret token. */
    const TOKEN_OPTION = 'admbud_emergency_token';

    // -- Singleton -------------------------------------------------------------

    private static ?Maintenance $instance = null;

    public static function get_instance(): Maintenance {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        /*
         * Token URL handler - must run on every request (even when mode is off)
         * at the earliest possible hook so it fires before any plugin redirect.
         */
        add_action( 'init', [ $this, 'handle_token_visit' ], 1 );

        // AJAX: token regeneration (admin only, nonce-protected).
        add_action( 'wp_ajax_admbud_regenerate_token', [ $this, 'ajax_regenerate_token' ] );

        // Only register intercept hooks when a mode is active.
        if ( $this->current_mode() === self::MODE_OFF ) {
            return;
        }

        add_action( 'template_redirect',        [ $this, 'maybe_intercept' ], 1 );
        add_filter( 'rest_authentication_errors', [ $this, 'maybe_block_rest' ]   );
    }

    // ============================================================================
    // TOKEN MANAGEMENT
    // ============================================================================

    /**
     * Generate a cryptographically random 32-char hex token, persist it, and
     * return it. Safe to call on activation and on demand (regenerate).
     *
     * @return string New token.
     */
    public static function generate_token(): string {
        $token = bin2hex( random_bytes( 16 ) ); // 128 bits of entropy → 32 hex chars
        update_option( self::TOKEN_OPTION, $token );
        return $token;
    }

    /**
     * Return the stored token, auto-generating one if it has never been set.
     *
     * @return string Current token.
     */
    public static function get_token(): string {
        $token = get_option( self::TOKEN_OPTION, '' );
        if ( ! $token ) {
            $token = self::generate_token();
        }
        return $token;
    }

    /**
     * Build and return the full emergency access URL shown in settings.
     *
     * @return string Absolute URL with token appended.
     */
    public static function emergency_url(): string {
        return add_query_arg( 'admbud_access', self::get_token(), home_url( '/' ) );
    }

    /**
     * Derive the expected cookie value from the current token.
     * Using wp_salt ensures the value is site-specific and cannot be
     * transferred between WordPress installations.
     *
     * @return string HMAC-SHA256 hex digest.
     */
    private static function expected_cookie_value(): string {
        return hash_hmac( 'sha256', self::get_token(), wp_salt( 'auth' ) );
    }

    // ============================================================================
    // TOKEN VISIT - SINGLE STAGE
    // ============================================================================

    /**
     * Called on `init` priority 1 - before template_redirect and before any
     * plugin (e.g. Bricks) can redirect the login URL.
     *
     * When ?admbud_access=TOKEN is present and valid:
     *  1. Set the session bypass cookie immediately.
     *  2. Redirect to home_url() so the visitor can navigate to whichever
     *     login page the site uses (native, Bricks, WooCommerce, etc.).
     *
     * Security note:
     *  The bypass cookie removes the intercept page only - it does NOT grant
     *  any WordPress capability. The visitor still needs valid credentials to
     *  access the admin or any protected content. The maintenance/coming soon
     *  gate is a cosmetic barrier, not an authentication boundary.
     *  Keep the emergency URL private (password manager) so only trusted
     *  people can use it to reach the login page.
     */
    public function handle_token_visit(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token IS the credential; WP nonce not applicable here.
        if ( empty( $_GET['admbud_access'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $provided = sanitize_text_field( wp_unslash( $_GET['admbud_access'] ) );

        // Constant-time comparison to prevent timing-based token discovery.
        if ( ! hash_equals( self::get_token(), $provided ) ) {
            return; // Invalid - fail silently, do not leak information.
        }

        // Already logged in as admin - skip cookie dance, go straight to admin.
        if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
            wp_safe_redirect( admin_url() );
            exit;
        }

        // Valid token - grant the bypass cookie for this browser session.
        $this->set_bypass_cookie();

        // Log the emergency access event.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- admbud_ is the plugin prefix.
        do_action( 'admbud_emergency_access_granted' );

        /*
         * Redirect to home so the visitor sees the live site and can navigate
         * to whichever login method their site uses. We intentionally do NOT
         * redirect directly to wp-login.php here because custom login plugins
         * (Bricks, etc.) may redirect it away - sending the user back to the
         * intercept page if the cookie hasn't been sent yet. Redirecting to
         * home is safe and works with every login plugin configuration.
         */
        wp_safe_redirect( home_url( '/' ) );
        exit;
    }

    /**
     * Set the HttpOnly session bypass cookie.
     *
     * Session cookie (expires = 0) means it is cleared when the browser closes,
     * which is what the user asked for.
     *
     * Uses COOKIEPATH + COOKIE_DOMAIN so it works correctly in subdirectory
     * WordPress installs (e.g. yoursite.com/wp/).
     */
    private function set_bypass_cookie(): void {
        if ( headers_sent() ) {
            return; // Cannot set cookie - headers already out.
        }

        setcookie(
            self::COOKIE_NAME,
            self::expected_cookie_value(),
            [
                'expires'  => 0,            // Session cookie - cleared on browser close.
                'path'     => COOKIEPATH,
                'domain'   => COOKIE_DOMAIN,
                'secure'   => is_ssl(),     // HTTPS-only flag when applicable.
                'httponly' => true,          // Not accessible via JavaScript.
                'samesite' => 'Lax',        // CSRF mitigation; allows normal navigation.
            ]
        );
    }

    // ============================================================================
    // AJAX - TOKEN REGENERATION
    // ============================================================================

    /**
     * AJAX handler: regenerate the secret token.
     * Protected by nonce + capability check.
     * Returns the new emergency URL as JSON.
     */
    public function ajax_regenerate_token(): void {
        check_ajax_referer( 'admbud_regenerate_token', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error(
                [ 'message' => __( 'Permission denied.', 'admin-buddy' ) ],
                403
            );
        }

        $token = self::generate_token();
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- admbud_ is the plugin prefix.
        do_action( 'admbud_token_regenerated' );
        wp_send_json_success( [
            'url' => add_query_arg( 'admbud_access', $token, home_url( '/' ) ),
        ] );
    }

    // ============================================================================
    // BYPASS LOGIC
    // ============================================================================

    /**
     * Returns the current active mode, guaranteed to be one of the MODE_*
     * constants. Falls back to MODE_OFF for any unexpected stored value.
     */
    public function current_mode(): string {
        $mode = (string) admbud_get_option( 'admbud_maintenance_mode', self::MODE_OFF );
        $allowed = [ self::MODE_OFF, self::MODE_COMING_SOON, self::MODE_MAINTENANCE ];
        return in_array( $mode, $allowed, true ) ? $mode : self::MODE_OFF;
    }

    /**
     * Master bypass check - returns true if this request should NOT be
     * intercepted. Evaluated in priority order so cheaper checks come first.
     */
    private function should_intercept(): bool {
        // 1. WP cron must never be blocked - background tasks depend on it.
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            return false;
        }

        // 2. Valid bypass cookie - set only after two-stage login.
        if ( $this->has_valid_bypass_cookie() ) {
            return false;
        }

        // 3. Already logged in as an admin.
        if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
            return false;
        }

        // 3c. Any logged-in user who can edit content can bypass - this allows
        //     editors, authors, and contributors to preview their own work even
        //     while maintenance / coming soon mode is active.
        if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
            return false;
        }

        // 3b. Logged-in user loading a page inside the Admin Buddy dashboard iframe.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( is_user_logged_in() && isset( $_GET[ Dashboard::IFRAME_PARAM ] ) && $_GET[ Dashboard::IFRAME_PARAM ] === '1' ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
            return false;
        }

        // 4 & 5. Auto-bypassed paths and user-defined bypass list.
        if ( $this->is_bypassed_url() ) {
            return false;
        }

        return true; // Intercept this request.
    }

    /**
     * Check whether the bypass cookie is present and its HMAC value matches
     * what we would have set for the current token. Unforgeable without the
     * WP secret keys.
     */
    private function has_valid_bypass_cookie(): bool {
        if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
            return false;
        }
        return hash_equals(
            self::expected_cookie_value(),
            (string) $_COOKIE[ self::COOKIE_NAME ] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        );
    }

    /**
     * Returns true if the current request path should be allowed through
     * without showing the intercept page.
     *
     * Auto-bypassed (no config needed):
     *  - wp-login.php  - resolved dynamically via wp_login_url() so it works
     *                    even when the login URL has been customised.
     *  - /wp-admin/    - prefix-matched.
     *
     * User-defined:
     *  - One path per line from the admbud_maintenance_bypass_urls option.
     *  - Prefix-matched: /my-account also covers /my-account/orders.
     */
    private function is_bypassed_url(): bool {
        $request = $this->current_request_path();

        // -- Auto-bypassed -----------------------------------------------------

        $login_path = '/' . ltrim(
            (string) wp_parse_url( wp_login_url(), PHP_URL_PATH ),
            '/'
        );
        if ( $this->path_matches( $request, $login_path ) ) {
            return true;
        }

        $admin_path = '/' . ltrim(
            (string) wp_parse_url( admin_url(), PHP_URL_PATH ),
            '/'
        );
        if ( str_starts_with( $request, $admin_path ) ) {
            return true;
        }

        // -- User-defined ------------------------------------------------------

        $raw = (string) admbud_get_option( 'admbud_maintenance_bypass_urls', '' );
        if ( ! $raw ) {
            return false;
        }

        foreach ( $this->parse_bypass_list( $raw ) as $bypass ) {
            if ( $this->path_matches( $request, $bypass ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse the raw bypass URLs textarea value into a clean array of paths.
     *
     * @param  string   $raw Raw option value (newline-separated).
     * @return string[]      Array of normalised path strings.
     */
    private function parse_bypass_list( string $raw ): array {
        return array_values(
            array_filter(
                array_map( 'trim', explode( "\n", $raw ) )
            )
        );
    }

    /**
     * Normalised path comparison with prefix matching.
     *
     * Examples:
     *  path_matches('/my-account',         '/my-account')         → true  (exact)
     *  path_matches('/my-account/orders',  '/my-account')         → true  (prefix)
     *  path_matches('/my-account-extra',   '/my-account')         → false (no slash boundary)
     *
     * @param string $request Current request path (normalised, starts with /).
     * @param string $bypass  Configured bypass path.
     */
    private function path_matches( string $request, string $bypass ): bool {
        $request = '/' . ltrim( $request, '/' );
        $bypass  = '/' . ltrim( $bypass,  '/' );

        return $request === $bypass
            || str_starts_with( $request, rtrim( $bypass, '/' ) . '/' );
    }

    /**
     * Extract and normalise the path portion of the current REQUEST_URI,
     * stripping query string and ensuring a leading slash.
     *
     * @return string Normalised path, e.g. '/my-account'.
     */
    private function current_request_path(): string {
        $uri = isset( $_SERVER['REQUEST_URI'] )
            ? sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) )
            : '/';

        // strtok strips the query string cheaply without a full parse.
        return '/' . ltrim( (string) strtok( $uri, '?' ), '/' );
    }

    // ============================================================================
    // INTERCEPT HOOKS
    // ============================================================================

    /**
     * Template redirect hook - show the intercept page when appropriate.
     */
    public function maybe_intercept(): void {
        if ( $this->should_intercept() ) {
            $this->render_page( $this->current_mode() );
        }
    }

    /**
     * REST API gate - block unauthenticated requests during active modes.
     *
     * @param  \WP_Error|null|true $result Existing auth result.
     * @return \WP_Error|null|true         Modified result.
     */
    public function maybe_block_rest( $result ) {
        // Pass through if auth already failed or user is an admin.
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
            return $result;
        }

        $mode = $this->current_mode();

        return new \WP_Error(
            'admbud_' . $mode,
            $mode === self::MODE_MAINTENANCE
                ? __( 'Site is temporarily under maintenance. Please try again shortly.', 'admin-buddy' )
                : __( 'Site is coming soon. Stay tuned!', 'admin-buddy' ),
            [ 'status' => $mode === self::MODE_MAINTENANCE ? 503 : 200 ]
        );
    }

    // ============================================================================
    // PAGE RENDERER
    // ============================================================================

    /**
     * Output the intercept page, set correct HTTP headers, and exit.
     * All dynamic values are escaped before interpolation.
     *
     * @param string $mode One of the MODE_ constants.
     */
    private function render_page( string $mode ): void {
        $is_maint  = ( $mode === self::MODE_MAINTENANCE );
        $site_name = esc_html( get_bloginfo( 'name' ) );

        // Fetch mode-specific content, falling back to sensible defaults.
        $title    = $is_maint
            ? esc_html( admbud_get_option( 'admbud_maintenance_title',   __( 'Under Maintenance',                                         'admin-buddy' ) ) )
            : esc_html( admbud_get_option( 'admbud_coming_soon_title',   __( 'Coming Soon',                                               'admin-buddy' ) ) );
        $message  = $is_maint
            ? wp_kses_post( admbud_get_option( 'admbud_maintenance_message', __( "We're performing scheduled maintenance. We'll be back shortly!", 'admin-buddy' ) ) )
            : wp_kses_post( admbud_get_option( 'admbud_coming_soon_message', __( "We're working on something exciting. Stay tuned!",               'admin-buddy' ) ) );
        // Fetch background settings (supports solid / gradient / image).
        $prefix   = $is_maint ? 'maint' : 'cs';
        $bg_type  = admbud_get_option( "admbud_{$prefix}_bg_type", 'solid' );
        $def_from = \Admbud\Colours::DEFAULT_SIDEBAR_GRAD_FROM;
        $def_to   = \Admbud\Colours::DEFAULT_SIDEBAR_GRAD_TO;
        $def_bg   = \Admbud\Colours::DEFAULT_PAGE_BG;

        if ( $bg_type === 'gradient' ) {
            $from    = sanitize_hex_field( admbud_get_option( "admbud_{$prefix}_grad_from", $def_from ) ) ?: $def_from;
            $to      = sanitize_hex_field( admbud_get_option( "admbud_{$prefix}_grad_to",   $def_to   ) ) ?: $def_to;
            $dir     = admbud_get_option( "admbud_{$prefix}_grad_direction", 'to bottom right' );
            $bg_css  = "background: linear-gradient({$dir}, {$from}, {$to});";
        } elseif ( $bg_type === 'image' ) {
            $img_url = esc_url( admbud_get_option( "admbud_{$prefix}_bg_image_url", '' ) );
            $ov_c    = sanitize_hex_field( admbud_get_option( "admbud_{$prefix}_bg_overlay_color", '#000000' ) ) ?: '#000000';
            $ov_op   = absint( admbud_get_option( "admbud_{$prefix}_bg_overlay_opacity", 30 ) );
            $ov_rgba = sprintf( 'rgba(%d,%d,%d,%.2f)',
                hexdec( substr( ltrim( $ov_c, '#' ), 0, 2 ) ),
                hexdec( substr( ltrim( $ov_c, '#' ), 2, 2 ) ),
                hexdec( substr( ltrim( $ov_c, '#' ), 4, 2 ) ),
                $ov_op / 100
            );
            $bg_css  = $img_url
                ? "background: linear-gradient({$ov_rgba},{$ov_rgba}), url('{$img_url}') center/cover no-repeat;"
                : "background-color: {$def_bg};";
        } else {
            $bg_color = sanitize_hex_field( admbud_get_option( "admbud_{$prefix}_bg_color", $def_bg ) ) ?: $def_bg;
            $bg_css   = "background-color: {$bg_color};";
        }

        // -- HTTP headers ------------------------------------------------------
        if ( ! headers_sent() ) {
            if ( $is_maint ) {
                header( 'HTTP/1.1 503 Service Unavailable' );
                header( 'Status: 503 Service Unavailable' );
                header( 'Retry-After: 3600' );
            } else {
                header( 'HTTP/1.1 200 OK' );
            }
            header( 'X-Robots-Tag: noindex, nofollow' );
            header( 'Content-Type: text/html; charset=utf-8' );
            // Security headers: lock down the intercept page (I-1 hardening).
            // 'unsafe-inline' for style-src is required because styles are inline.
            header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data: https:; frame-ancestors 'none'" );
            header( 'X-Content-Type-Options: nosniff' );
            header( 'X-Frame-Options: DENY' );
            header( 'Referrer-Policy: no-referrer' );
        }

        // -- Template variables ------------------------------------------------
        $accent       = \Admbud\Colours::DEFAULT_PRIMARY;
        $page_title   = $site_name . ' &mdash; ' . $title;

        $def_text    = \Admbud\Colours::DEFAULT_PAGE_TEXT;
        $def_message = \Admbud\Colours::DEFAULT_PAGE_MESSAGE;

        $heading_color = $is_maint
            ? ( sanitize_hex_field( admbud_get_option( 'admbud_maint_text_color',    $def_text    ) ) ?: $def_text    )
            : ( sanitize_hex_field( admbud_get_option( 'admbud_cs_text_color',       $def_text    ) ) ?: $def_text    );
        $message_color = $is_maint
            ? ( sanitize_hex_field( admbud_get_option( 'admbud_maint_message_color', $def_message ) ) ?: $def_message )
            : ( sanitize_hex_field( admbud_get_option( 'admbud_cs_message_color',    $def_message ) ) ?: $def_message );
        $locale     = esc_attr( str_replace( '_', '-', get_locale() ) );
        $robots     = '<meta name="robots" content="noindex, nofollow">';
        $body_color = esc_attr( \Admbud\Colours::DEFAULT_PAGE_TEXT ); // pre-assigned for heredoc interpolation

        echo '<!DOCTYPE html>';
        echo '<html lang="' . esc_attr( $locale ) . '">';
        echo '<head>';
        echo '<meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<meta name="robots" content="noindex, nofollow">';
        echo '<title>' . esc_html( $page_title ) . '</title>';
        echo '<style>';
        echo '*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }';
        echo 'body {'
           . 'font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, sans-serif;'
           . 'min-height: 100vh; display: flex; align-items: center;'
           . 'justify-content: center; ' . esc_attr( $bg_css )
           . ' color: ' . esc_attr( $body_color ) . '; padding: 2rem;'
           . '}';
        echo '.ab-page { text-align: center; max-width: 520px; width: 100%; }';
        echo '.ab-page__title  { font-size: 2rem; font-weight: 800; margin-bottom: 1rem; color: ' . esc_attr( $heading_color ) . '; line-height: 1.2; }';
        echo '.ab-page__message { font-size: 1.05rem; line-height: 1.75; color: ' . esc_attr( $message_color ) . '; }';
        echo '</style>';
        echo '</head>';
        echo '<body>';
        echo '<div class="ab-page">';
        echo '<h1 class="ab-page__title">' . esc_html( $title ) . '</h1>';
        echo '<div class="ab-page__message">' . wp_kses_post( $message ) . '</div>';
        echo '</div>';
        echo '</body>';
        echo '</html>';
        exit;
    }
}

if ( ! function_exists( 'Admbud\sanitize_hex_field' ) ) {
    /**
     * Minimal hex color sanitizer - no Customizer dependency.
     * Defined here (always-loaded file) so maintenance/login/colours
     * modules can all rely on it regardless of which modules are active.
     *
     * @param  string $color Raw color value.
     * @return string        Validated 3- or 6-char hex with leading #, or ''.
     */
    function sanitize_hex_field( string $color ): string {
        $color = trim( $color );
        return preg_match( '/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color ) ? $color : '';
    }
}

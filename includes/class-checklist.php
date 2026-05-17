<?php
/**
 * Checklist - site-overview panel in the WP admin bar.
 *
 * Surfaces commonly-overlooked WordPress settings (search-engine visibility,
 * permalinks, debug mode, admin email, sample content) in an off-canvas
 * drawer triggered from the admin bar. Each row always shows the CURRENT
 * value, not just a warning - an admin can scan the panel at any time to
 * confirm the site is set up as intended, not just to fix broken things.
 *
 * Detection is all cheap get_option/get_posts reads, evaluated once per
 * request and cached in $results_cache so the adminbar pill and panel
 * render share a single pass.
 *
 * Checks are extensible via the admbud_checklist_checks filter - add, remove,
 * or re-order without touching this file.
 *
 * @package Admbud
 */

namespace Admbud;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Checklist {

    private static ?Checklist $instance = null;

    /** Request-scoped cache of evaluated rows. */
    private ?array $results_cache = null;

    public static function get_instance(): Checklist {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_bar_menu',        [ $this, 'add_bar_node'   ], 1000 );
        add_action( 'admin_footer',          [ $this, 'render_panel'   ]       );
        add_action( 'wp_footer',             [ $this, 'render_panel'   ]       );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ]       );
        add_action( 'wp_enqueue_scripts',    [ $this, 'enqueue_assets' ]       );
    }

    // -- Registry ------------------------------------------------------------

    /**
     * Returns the ordered list of check definitions.
     *
     * Each check shape:
     *   id           - unique slug
     *   severity     - 'critical' (shown), others reserved for future tiers
     *   section      - bucket key for grouping in the panel
     *   label        - human-readable title
     *   why          - shown only when status === 'attention'
     *   detect       - callable returning [ 'status' => 'ok'|'attention', 'value' => string ]
     *   fix_url      - deep link to the relevant settings screen
     *   fix_external - optional bool; opens fix_url in a new tab when true
     *
     * Filterable via 'admbud_checklist_checks' so third-party code (or future
     * Pro extensions) can add entries without modifying this file.
     */
    public function get_checks(): array {
        $checks = [
            [
                'id'       => 'search_engines_blocked',
                'severity' => 'critical',
                'section'  => 'seo',
                'label'    => __( 'Search engine visibility', 'admin-buddy' ),
                'why'      => __( 'With this on, search engines like Google will not index the site.', 'admin-buddy' ),
                'detect'   => static function () {
                    $blocked = get_option( 'blog_public' ) === '0';
                    return [
                        'status' => $blocked ? 'attention' : 'ok',
                        'value'  => $blocked ? __( 'Discouraged', 'admin-buddy' ) : __( 'Indexable', 'admin-buddy' ),
                    ];
                },
                'fix_url'  => admin_url( 'options-reading.php#blog_public' ),
            ],
            [
                'id'       => 'permalinks_plain',
                'severity' => 'critical',
                'section'  => 'seo',
                'label'    => __( 'Permalinks', 'admin-buddy' ),
                'why'      => __( 'Plain permalinks like ?p=123 hurt SEO and break incoming links if changed later.', 'admin-buddy' ),
                'detect'   => static function () {
                    $structure = (string) get_option( 'permalink_structure', '' );
                    $plain = $structure === '';
                    return [
                        'status' => $plain ? 'attention' : 'ok',
                        'value'  => $plain ? __( 'Plain (?p=123)', 'admin-buddy' ) : $structure,
                    ];
                },
                'fix_url'  => admin_url( 'options-permalink.php' ),
            ],
            [
                'id'       => 'default_tagline',
                'severity' => 'warning',
                'section'  => 'seo',
                'label'    => __( 'Site tagline', 'admin-buddy' ),
                'why'      => __( 'The tagline is still the WordPress default. It often appears in search results and browser tabs.', 'admin-buddy' ),
                'detect'   => static function () {
                    $tagline    = trim( (string) get_option( 'blogdescription', '' ) );
                    $is_default = $tagline === 'Just another WordPress site' || $tagline === '';
                    return [
                        'status' => $is_default ? 'attention' : 'ok',
                        'value'  => $tagline === '' ? __( '(empty)', 'admin-buddy' ) : $tagline,
                    ];
                },
                'fix_url'  => admin_url( 'options-general.php#blogdescription' ),
            ],
            [
                'id'       => 'wp_debug_on',
                'severity' => 'critical',
                'section'  => 'debug',
                'label'    => __( 'Debug mode', 'admin-buddy' ),
                'why'      => __( 'WP_DEBUG is on. Fine for development; on a live site it can leak error details to visitors. Set define( \'WP_DEBUG\', false ); in wp-config.php to turn it off.', 'admin-buddy' )
                ,
                'detect'   => static function () {
                    $on = defined( 'WP_DEBUG' ) && WP_DEBUG;
                    return [
                        'status' => $on ? 'attention' : 'ok',
                        'value'  => $on ? __( 'On', 'admin-buddy' ) : __( 'Off', 'admin-buddy' ),
                    ];
                },
            ],
            [
                'id'       => 'wp_debug_display',
                'severity' => 'critical',
                'section'  => 'debug',
                'label'    => __( 'Debug display', 'admin-buddy' ),
                'why'      => __( 'WP_DEBUG is on AND errors render directly in the page, visible to visitors. Log-only is safer: add define( \'WP_DEBUG_DISPLAY\', false ); and define( \'WP_DEBUG_LOG\', true ); to wp-config.php.', 'admin-buddy' )
                ,
                'detect'   => static function () {
                    // Only an issue when WP_DEBUG is on — if debug is off, visitor-visible
                    // errors aren't a risk regardless of WP_DEBUG_DISPLAY's value.
                    $debug   = defined( 'WP_DEBUG' ) && WP_DEBUG;
                    $display = defined( 'WP_DEBUG_DISPLAY' ) ? (bool) WP_DEBUG_DISPLAY : true; // defaults true
                    $risky   = $debug && $display;
                    return [
                        'status' => $risky ? 'attention' : 'ok',
                        'value'  => ! $debug
                            ? __( 'N/A (debug off)', 'admin-buddy' )
                            : ( $display ? __( 'Shown to visitors', 'admin-buddy' ) : __( 'Log only', 'admin-buddy' ) ),
                    ];
                },
            ],
            [
                'id'       => 'admin_user_exists',
                'severity' => 'critical',
                'section'  => 'security',
                'label'    => __( 'Default "admin" username', 'admin-buddy' ),
                'why'      => __( 'A user named "admin" makes brute-force attacks easier — half the work is already done if the attacker knows a valid login. Rename this account or create a new admin and delete it.', 'admin-buddy' ),
                'detect'   => static function () {
                    $exists = (bool) get_user_by( 'login', 'admin' );
                    return [
                        'status' => $exists ? 'attention' : 'ok',
                        'value'  => $exists ? __( 'Exists', 'admin-buddy' ) : __( 'Not present', 'admin-buddy' ),
                    ];
                },
                'fix_url'  => admin_url( 'users.php' ),
            ],
            [
                'id'       => 'site_not_https',
                'severity' => 'critical',
                'section'  => 'security',
                'label'    => __( 'HTTPS on site URL', 'admin-buddy' ),
                'why'      => __( 'The site URL is plain http:// — login credentials and admin traffic are transmitted unencrypted. Install an SSL certificate and switch the WordPress Address (URL) and Site Address (URL) to https://.', 'admin-buddy' ),
                'detect'   => static function () {
                    $site = (string) get_option( 'siteurl', '' );
                    $home = (string) get_option( 'home', '' );
                    $https = str_starts_with( $site, 'https://' ) && str_starts_with( $home, 'https://' );
                    return [
                        'status' => $https ? 'ok' : 'attention',
                        'value'  => $https ? __( 'Enabled', 'admin-buddy' ) : __( 'Plain HTTP', 'admin-buddy' ),
                    ];
                },
                'fix_url'  => admin_url( 'options-general.php' ),
            ],
            [
                'id'       => 'file_edit_allowed',
                'severity' => 'critical',
                'section'  => 'security',
                'label'    => __( 'File editing in admin', 'admin-buddy' ),
                'why'      => __( 'Appearance → Editor and Plugins → Editor let any admin write PHP files live on the server. If one admin account is ever compromised, the attacker can install a backdoor in seconds. Add define( \'DISALLOW_FILE_EDIT\', true ); to your wp-config.php to lock it down.', 'admin-buddy' )
                ,
                'detect'   => static function () {
                    $blocked = defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT;
                    return [
                        'status' => $blocked ? 'ok' : 'attention',
                        'value'  => $blocked ? __( 'Disabled', 'admin-buddy' ) : __( 'Allowed', 'admin-buddy' ),
                    ];
                },
            ],
            [
                'id'       => 'open_registration_admin_role',
                'severity' => 'critical',
                'section'  => 'security',
                'label'    => __( 'Registration role', 'admin-buddy' ),
                'why'      => __( '"Anyone can register" is on AND the default role is Administrator. Every signup instantly becomes an admin — change the default role to Subscriber, or disable open registration entirely.', 'admin-buddy' ),
                'detect'   => static function () {
                    $open = (string) get_option( 'users_can_register' ) === '1';
                    $role = (string) get_option( 'default_role', 'subscriber' );
                    $bad  = $open && $role === 'administrator';
                    if ( $bad ) {
                        $value = __( 'Public signup grants admin', 'admin-buddy' );
                    } elseif ( $open ) {
                        /* translators: %s: default new-user role (e.g. "subscriber") */
                        $value = sprintf( __( 'Open → %s', 'admin-buddy' ), $role );
                    } else {
                        $value = __( 'Closed', 'admin-buddy' );
                    }
                    return [
                        'status' => $bad ? 'attention' : 'ok',
                        'value'  => $value,
                    ];
                },
                'fix_url'  => admin_url( 'options-general.php#default_role' ),
            ],
            [
                'id'       => 'wp_version_exposed',
                'severity' => 'warning',
                'section'  => 'security',
                'label'    => __( 'WordPress version in page source', 'admin-buddy' ),
                'why'      => __( 'WordPress emits a generator meta tag in every page\'s <head> and in RSS feeds, exposing the exact version. Attackers scrape this to build "sites on vulnerable X.Y" target lists. Defense-in-depth only — the version can still be inferred from other signals — but it\'s a one-click hardening win.', 'admin-buddy' ),
                'detect'   => static function () {
                    // has_action returns the priority (int) when hooked, false when not.
                    // wp_generator is the core function that prints the meta tag; removing
                    // it (via our QS toggle, a theme, or another plugin) hides the version.
                    $exposed = (bool) has_action( 'wp_head', 'wp_generator' );
                    return [
                        'status' => $exposed ? 'attention' : 'ok',
                        'value'  => $exposed
                            /* translators: %s: WordPress version number */
                            ? sprintf( __( 'Exposed (%s)', 'admin-buddy' ), get_bloginfo( 'version' ) )
                            : __( 'Hidden', 'admin-buddy' ),
                    ];
                },
                'fix_url'  => add_query_arg(
                    [
                        'page'         => 'admbud',
                        'tab'          => 'quick-settings',
                        'admbud_highlight' => __( 'Remove generator meta tag', 'admin-buddy' ),
                    ],
                    admin_url( 'admin.php' )
                ),
            ],
            [
                'id'       => 'admin_email_placeholder',
                'severity' => 'critical',
                'section'  => 'email',
                'label'    => __( 'Admin email', 'admin-buddy' ),
                'why'      => __( 'The admin email looks like a placeholder. Password resets and update notices are sent to this address.', 'admin-buddy' ),
                'detect'   => static function () {
                    $email = (string) get_option( 'admin_email', '' );
                    $bad   = Checklist::email_looks_placeholder( $email );
                    return [
                        'status' => $bad ? 'attention' : 'ok',
                        'value'  => $email !== '' ? $email : __( '(not set)', 'admin-buddy' ),
                    ];
                },
                'fix_url'  => admin_url( 'options-general.php#admin_email' ),
            ],
            [
                'id'       => 'core_update_pending',
                'severity' => 'critical',
                'section'  => 'updates',
                'label'    => __( 'WordPress core', 'admin-buddy' ),
                'why'      => __( 'A WordPress core update is available. Core updates often ship security patches — apply promptly after a backup.', 'admin-buddy' ),
                'detect'   => static function () {
                    // Read the transient directly rather than calling get_core_updates()
                    // so we don't have to pull in wp-admin/includes/update.php on the
                    // front-end (the Checklist also renders via wp_footer). The transient
                    // is populated by WP's scheduled update check — identical data.
                    $updates = get_site_transient( 'update_core' );
                    $entry   = $updates->updates[0] ?? null;
                    $has     = $entry && ( $entry->response ?? '' ) === 'upgrade';
                    $target  = $has ? (string) ( $entry->current ?? '' ) : '';
                    return [
                        'status' => $has ? 'attention' : 'ok',
                        'value'  => $has
                            /* translators: %s: available WordPress version, e.g. "6.7.2" */
                            ? ( $target !== '' ? sprintf( __( 'Update to %s available', 'admin-buddy' ), $target ) : __( 'Update available', 'admin-buddy' ) )
                            : __( 'Up to date', 'admin-buddy' ),
                    ];
                },
                'fix_url'  => admin_url( 'update-core.php' ),
            ],
            [
                'id'       => 'plugin_updates_pending',
                'severity' => 'critical',
                'section'  => 'updates',
                'label'    => __( 'Plugin updates', 'admin-buddy' ),
                'why'      => __( 'Out-of-date plugins are the #1 attack vector on WordPress sites. Review pending updates, back up, then apply.', 'admin-buddy' ),
                'detect'   => static function () {
                    // Transient read instead of get_plugin_updates() for the same reason
                    // as core_update_pending: frontend-safe, no admin includes needed.
                    $updates = get_site_transient( 'update_plugins' );
                    $count   = isset( $updates->response ) ? count( (array) $updates->response ) : 0;
                    return [
                        'status' => $count > 0 ? 'attention' : 'ok',
                        'value'  => $count > 0
                            /* translators: %d: number of plugins with pending updates */
                            ? sprintf( _n( '%d plugin needs updating', '%d plugins need updating', $count, 'admin-buddy' ), $count )
                            : __( 'All up to date', 'admin-buddy' ),
                    ];
                },
                'fix_url'  => admin_url( 'plugins.php?plugin_status=upgrade' ),
            ],
            [
                'id'       => 'php_version_outdated',
                'severity' => 'critical',
                'section'  => 'updates',
                'label'    => __( 'PHP version', 'admin-buddy' ),
                'why'      => __( 'WordPress recommends PHP 8.1 or newer. Older branches no longer receive security patches from the PHP project — hosts should have upgraded by now.', 'admin-buddy' ),
                'detect'   => static function () {
                    $outdated = PHP_VERSION_ID < 80100;
                    return [
                        'status' => $outdated ? 'attention' : 'ok',
                        'value'  => PHP_VERSION . ( $outdated ? ' ' . __( '(outdated)', 'admin-buddy' ) : '' ),
                    ];
                },
                'fix_url'      => 'https://wordpress.org/documentation/article/update-php/',
                'fix_external' => true,
            ],
            [
                'id'       => 'sample_page_published',
                'severity' => 'warning',
                'section'  => 'content',
                'label'    => __( 'Sample Page', 'admin-buddy' ),
                'why'      => __( 'WordPress ships with a "Sample Page". Delete it before launch.', 'admin-buddy' ),
                'detect'   => static function () {
                    $page = get_page_by_path( 'sample-page', OBJECT, 'page' );
                    $exists = $page && $page->post_status === 'publish';
                    return [
                        'status' => $exists ? 'attention' : 'ok',
                        'value'  => $exists
                            ? __( 'Still published', 'admin-buddy' )
                            : __( 'Removed', 'admin-buddy' ),
                    ];
                },
                'fix_url'  => admin_url( 'edit.php?post_type=page' ),
            ],
            [
                'id'       => 'hello_world_post_published',
                'severity' => 'warning',
                'section'  => 'content',
                'label'    => __( '"Hello world!" post', 'admin-buddy' ),
                'why'      => __( 'WordPress ships with a "Hello world!" post. Delete it before launch.', 'admin-buddy' ),
                'detect'   => static function () {
                    $posts = get_posts( [
                        'name'             => 'hello-world',
                        'post_type'        => 'post',
                        'post_status'      => 'publish',
                        'numberposts'      => 1,
                        'suppress_filters' => false,
                    ] );
                    $exists = ! empty( $posts );
                    return [
                        'status' => $exists ? 'attention' : 'ok',
                        'value'  => $exists
                            ? __( 'Still published', 'admin-buddy' )
                            : __( 'Removed', 'admin-buddy' ),
                    ];
                },
                'fix_url'  => admin_url( 'edit.php' ),
            ],
        ];

        return (array) apply_filters( 'admbud_checklist_checks', $checks );
    }

    // -- Detection helpers --------------------------------------------------

    /**
     * Heuristic for placeholder/dev admin emails. Not exhaustive; covers the
     * common offenders: @example.*, @localhost, *test@*.
     */
    public static function email_looks_placeholder( string $email ): bool {
        if ( $email === '' || ! is_email( $email ) ) { return true; }
        $lower = strtolower( $email );
        if ( str_contains( $lower, '@example.' ) )  { return true; }
        if ( str_contains( $lower, '@localhost' ) ) { return true; }
        if ( str_contains( $lower, 'test@' ) )      { return true; }
        return false;
    }

    // -- Evaluation ---------------------------------------------------------

    /**
     * Run every check once per request. Returns rows merged with status + value.
     */
    public function evaluate(): array {
        if ( $this->results_cache !== null ) {
            return $this->results_cache;
        }
        $rows = [];
        foreach ( $this->get_checks() as $check ) {
            if ( empty( $check['detect'] ) || ! is_callable( $check['detect'] ) ) { continue; }
            $result = call_user_func( $check['detect'] );
            if ( ! is_array( $result ) ) { continue; }
            $rows[] = array_merge( $check, [
                'status' => $result['status'] ?? 'ok',
                'value'  => (string) ( $result['value'] ?? '' ),
            ] );
        }
        $this->results_cache = $rows;
        return $rows;
    }

    /**
     * Count rows in 'attention' state, broken down by severity.
     *
     * Rows without an explicit 'severity' field are treated as 'critical' for
     * backward compatibility with filter-added checks from third-party code.
     *
     * @return array{critical:int, warning:int, info:int, total:int}
     */
    public function counts(): array {
        $counts = [ 'critical' => 0, 'warning' => 0, 'info' => 0, 'total' => 0 ];
        foreach ( $this->evaluate() as $row ) {
            if ( ( $row['status'] ?? '' ) !== 'attention' ) { continue; }
            $sev = $row['severity'] ?? 'critical';
            if ( isset( $counts[ $sev ] ) ) { $counts[ $sev ]++; }
            $counts['total']++;
        }
        return $counts;
    }

    /** Total attention-state rows (all severities). Kept for backward compat. */
    public function attention_count(): int {
        return $this->counts()['total'];
    }

    // -- Admin bar pill ------------------------------------------------------

    public function add_bar_node( \WP_Admin_Bar $bar ): void {
        if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) { return; }

        $counts = $this->counts();

        // Pill signal: red for any critical, amber when only warnings exist,
        // green when clean. Count shown reflects the most urgent tier only,
        // so "3 issues" always means critical and doesn't get diluted by
        // cosmetic warnings. Panel still surfaces warnings in full.
        // Label format matches the AB topbar pill in class-settings.php::render()
        // so users see the same text in both places. Single source of truth for
        // "what does this pill say" when adding pills in the future: use
        // "Checklist (N)" / "Checklist" — don't invent a terser variant here.
        if ( $counts['critical'] > 0 ) {
            $class = 'ab-bar-node ab-bar-node--checklist-alert';
            /* translators: %d: number of critical issues */
            $label = sprintf( _n( 'Checklist (%d)', 'Checklist (%d)', $counts['critical'], 'admin-buddy' ), $counts['critical'] );
        } elseif ( $counts['warning'] > 0 ) {
            $class = 'ab-bar-node ab-bar-node--checklist-warn';
            /* translators: %d: number of warnings */
            $label = sprintf( _n( 'Checklist (%d)', 'Checklist (%d)', $counts['warning'], 'admin-buddy' ), $counts['warning'] );
        } else {
            $class = 'ab-bar-node ab-bar-node--checklist-ok';
            $label = __( 'Checklist', 'admin-buddy' );
        }

        // Checkbox-in-square SVG, inline so no extra request.
        $icon = '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;position:relative;top:-1px;"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>';

        $bar->add_node( [
            'id'    => 'ab-checklist-indicator',
            'title' => '<span class="' . esc_attr( $class ) . '" data-ab-checklist-open="1">' . $icon . esc_html( $label ) . '</span>',
            'href'  => '#ab-checklist-panel',
            'meta'  => [
                'title' => __( 'Admin Buddy Checklist', 'admin-buddy' ),
            ],
        ] );
    }

    // -- Panel markup --------------------------------------------------------

    public function render_panel(): void {
        if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) { return; }

        $rows      = $this->evaluate();
        $total     = count( $rows );
        $attention = $this->attention_count();
        $ok        = $total - $attention;

        // Canonical section order. Sections with no rows are skipped.
        // Security + Updates are placed high because they're the highest-impact
        // issues an admin can overlook at launch or during maintenance.
        $sections = [
            'security' => __( 'Security',         'admin-buddy' ),
            'updates'  => __( 'Updates',          'admin-buddy' ),
            'seo'      => __( 'SEO & Visibility', 'admin-buddy' ),
            'debug'    => __( 'Debug & Errors',   'admin-buddy' ),
            'email'    => __( 'Email',            'admin-buddy' ),
            'content'  => __( 'Content',          'admin-buddy' ),
        ];

        $by_section = [];
        foreach ( $rows as $row ) {
            $sec = $row['section'] ?? 'misc';
            $by_section[ $sec ][] = $row;
        }

        // Use the canonical .ab-slide-panel + .ab-backdrop markup (defined
        // in admin.css) so the animation, width, z-indexes, shadow and
        // tokens all match the rest of the plugin. Checklist-specific
        // classes sit inside __body; the panel chrome (header, close,
        // slide animation) is shared.
        ?>
        <div class="ab-backdrop" id="ab-checklist-backdrop" style="display:none;" aria-hidden="true" data-ab-checklist-close="1"></div>
        <div class="ab-slide-panel ab-slide-panel--sm" id="ab-checklist-panel"
             role="dialog" aria-modal="true" aria-labelledby="ab-checklist-panel-title"
             style="display:none;" aria-hidden="true">
            <div class="ab-slide-panel__header">
                <h2 id="ab-checklist-panel-title" class="ab-slide-panel__title">
                    <?php esc_html_e( 'Checklist', 'admin-buddy' ); ?>
                </h2>
                <div class="ab-checklist-score" aria-live="polite">
                    <?php
                    /* translators: 1: OK count, 2: total count */
                    printf( esc_html__( '%1$d of %2$d OK', 'admin-buddy' ), (int) $ok, (int) $total );
                    ?>
                </div>
                <button type="button" class="ab-slide-panel__close" data-ab-checklist-close="1" aria-label="<?php esc_attr_e( 'Close', 'admin-buddy' ); ?>">
                    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="ab-slide-panel__body">
                <?php foreach ( $sections as $sec_id => $sec_label ) :
                    if ( empty( $by_section[ $sec_id ] ) ) { continue; } ?>
                    <section class="ab-checklist-section">
                        <h3 class="ab-checklist-section__title"><?php echo esc_html( $sec_label ); ?></h3>
                        <ul class="ab-checklist-rows">
                            <?php foreach ( $by_section[ $sec_id ] as $row ) { $this->render_row( $row ); } ?>
                        </ul>
                    </section>
                <?php endforeach; ?>

                <?php if ( ! empty( $by_section['misc'] ) ) : ?>
                    <section class="ab-checklist-section">
                        <h3 class="ab-checklist-section__title"><?php esc_html_e( 'Other', 'admin-buddy' ); ?></h3>
                        <ul class="ab-checklist-rows">
                            <?php foreach ( $by_section['misc'] as $row ) { $this->render_row( $row ); } ?>
                        </ul>
                    </section>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function render_row( array $row ): void {
        $status       = $row['status'] ?? 'ok';
        $severity     = $row['severity'] ?? 'critical';
        $fix_url      = $row['fix_url'] ?? '';
        $fix_external = ! empty( $row['fix_external'] );
        $btn_label    = $status === 'attention'
            ? __( 'Fix', 'admin-buddy' )
            : __( 'Settings', 'admin-buddy' );
        // Modifier picks dot colour: ok=green, critical=red, warning=amber.
        // When a row is OK, severity is irrelevant — the dot is always green.
        $mod = $status === 'ok' ? 'ok' : ( $severity === 'warning' ? 'warning' : 'critical' );
        ?>
        <li class="ab-checklist-row ab-checklist-row--<?php echo esc_attr( $mod ); ?>">
            <span class="ab-checklist-row__dot" aria-hidden="true"></span>
            <div class="ab-checklist-row__main">
                <div class="ab-checklist-row__label"><?php echo esc_html( $row['label'] ); ?></div>
                <div class="ab-checklist-row__value"><?php echo esc_html( $row['value'] ); ?></div>
                <?php if ( $status === 'attention' && ! empty( $row['why'] ) ) : ?>
                    <div class="ab-checklist-row__why"><?php echo esc_html( $row['why'] ); ?></div>
                <?php endif; ?>
            </div>
            <?php if ( $fix_url ) : ?>
                <a class="ab-checklist-row__fix button button-small"
                   href="<?php echo esc_url( $fix_url ); ?>"
                   <?php echo $fix_external ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>>
                    <?php echo esc_html( $btn_label ); ?>
                </a>
            <?php endif; ?>
        </li>
        <?php
    }

    // -- Assets -------------------------------------------------------------

    public function enqueue_assets(): void {
        if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) { return; }

        $url = ADMBUD_URL . 'assets/';
        $dir = ADMBUD_DIR . 'assets/';
        $v   = ADMBUD_VERSION;

        // Ensure the canonical token + slide-panel styles are available
        // wherever the Checklist shows (admin AND front-end admin bar).
        // Register idempotently - class-settings.php may have already
        // registered these on admin pages.
        if ( ! wp_style_is( 'admbud-tokens', 'registered' ) ) {
            wp_register_style( 'admbud-tokens', $url . 'tokens.css', [], $v );
        }
        if ( ! wp_style_is( 'admbud-core', 'registered' ) ) {
            wp_register_style( 'admbud-core', $url . 'admin.css', [ 'admbud-tokens' ], $v );
        }

        // Use filemtime() for the Checklist CSS version so every edit
        // busts the browser cache without requiring a plugin-version bump.
        // The Checklist panel iterates quickly on visual/layout tweaks and
        // stale caches have caused reproducible "fix didn't land" bugs.
        $css_path = $dir . 'checklist-panel.css';
        $css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : $v;

        // Depend on admbud-core so .ab-slide-panel / .ab-backdrop
        // rules (animation, width, z-index, shadow, tokens) are loaded
        // once, from the single canonical source.
        wp_enqueue_style(
            'ab-checklist',
            $url . 'checklist-panel.css',
            [ 'admbud-core' ],
            $css_ver
        );
        wp_enqueue_script(
            'ab-checklist',
            $url . 'checklist-panel.js',
            [],
            $v,
            true
        );
    }
}

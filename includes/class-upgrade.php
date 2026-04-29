<?php
/**
 * Admin Buddy - Database Upgrade / Migration System
 *
 * Single responsibility: track a global schema version in `admbud_db_version`
 * and run any pending upgrade routines when the version lags behind
 * DB_VERSION.
 *
 * HOW TO ADD A NEW MIGRATION
 * --------------------------
 * 1. Increment DB_VERSION.
 * 2. Add a `case DB_VERSION:` block inside maybe_run_upgrades().
 * 3. Document what the migration does.
 *
 * All upgrade methods must be idempotent - they may run more than once if
 * something interrupted a previous run.
 *
 * @package Admbud
 */

namespace Admbud;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Upgrade {

    /**
     * Increment this constant every time the plugin introduces a DB change
     * that requires a migration to run on existing installs.
     *
     * Version map:
     *   1  - baseline: snippets table v2 migration rolled in here.
     *   2  - migrate legacy admbud_smtp_fallback → admbud_smtp_disable_ssl_verify.
     *   3  - create admbud_activity_log custom table.
     *   4  - add is_shared/source columns to admbud_snippets table.
     *   5  - migrate admbud_snippets DB table to file-based storage.
     *   6  - add blog_id column to admbud_activity_log (multisite unified log).
     *   7  - migrate collections post_meta keys from ID-based to slug-based.
     *   8  - flip small AB options to autoload=on, large ones to off.
     *   9  - seed frequently-read options with defaults so autoload cache
     *        primes them instead of every page-load hitting the DB.
     *   10 - delete the legacy admbud_source_allow_any option (removed in the
     *        Remote security hardening pass; whitelist is now mandatory).
     */
    const DB_VERSION = 10;

    /** wp_options key that stores the installed schema version. */
    const VERSION_OPT = 'admbud_db_version';

    /**
     * Run any pending upgrade routines.
     * Called on plugins_loaded, before any module is instantiated.
     */
    public static function maybe_run_upgrades(): void {
        $installed = (int) get_option( self::VERSION_OPT, 0 );

        if ( $installed >= self::DB_VERSION ) {
            return; // Nothing to do - already up to date.
        }

        // On SQLite (WP Playground), skip all DB migrations - custom tables
        // use MySQL-specific syntax (ENUM, AUTO_INCREMENT) that SQLite can't handle.
        // Mark as current so migrations don't re-attempt on every page load.
        if ( ( defined( 'DB_ENGINE' ) && DB_ENGINE === 'sqlite' ) || ( class_exists( 'WP_SQLite_DB' ) ) ) {
            update_option( self::VERSION_OPT, self::DB_VERSION, false );
            return;
        }

        // Run every migration step from $installed+1 up to DB_VERSION.
        for ( $step = $installed + 1; $step <= self::DB_VERSION; $step++ ) {
            switch ( $step ) {

                case 1:
                    self::upgrade_1_snippets_table();
                    break;

                case 2:
                    self::upgrade_2_smtp_ssl_option();
                    break;


                case 4:
                    self::upgrade_4_snippets_source_columns();
                    break;

                case 5:
                    self::upgrade_5_snippets_to_files();
                    break;


                case 8:
                    self::upgrade_8_autoload_flip();
                    break;

                case 9:
                    self::upgrade_9_seed_defaults();
                    break;

                case 10:
                    self::upgrade_10_drop_legacy_source_options();
                    break;
            }

            // Persist progress after each step so a mid-run crash doesn't
            // force already-completed steps to re-run.
            update_option( self::VERSION_OPT, $step, false );
        }
    }

    // -- Migration methods -----------------------------------------------------

    /**
     * v1 - Previously ensured admbud_snippets table was at schema v2.
     * Snippets are now file-based (step 5 migrates DB rows to files).
     * This step is kept as a no-op so existing installs with db_version=0
     * can run through the migration chain without error.
     */
    private static function upgrade_1_snippets_table(): void {
        // No-op: snippets table is no longer used.
        // Step 5 (upgrade_5_snippets_to_files) handles the migration.
    }

    /**
     * v2 - Migrate legacy admbud_smtp_fallback SSL-bypass flag.
     *
     * In beta56 and earlier, `admbud_smtp_fallback` controlled both the
     * PHP mail() fallback description AND the SSL peer-verification bypass.
     * The SSL behaviour is now its own option: `admbud_smtp_disable_ssl_verify`.
     *
     * If the old flag was enabled, carry the value forward to the new option
     * so existing installs don't silently change behaviour.
     */
    /**
     * v4 - Previously added source-related columns to admbud_snippets table.
     * No-op: snippets are now file-based (step 5 handles migration).
     * Columns are irrelevant since the table will be dropped in step 5.
     */
    private static function upgrade_4_snippets_source_columns(): void {
        // No-op: step 5 migrates all DB snippet rows to files and drops the table.
    }


    private static function upgrade_2_smtp_ssl_option(): void {
        $legacy = admbud_get_option( 'admbud_smtp_fallback', '0' );
        if ( $legacy === '1' ) {
            // Only migrate if the new option hasn't been explicitly saved yet.
            if ( admbud_get_option( 'admbud_smtp_disable_ssl_verify', '__unset__' ) === '__unset__' ) {
                add_option( 'admbud_smtp_disable_ssl_verify', '1', '', false );
            }
        } else {
            // Ensure the new option row exists even if never enabled.
            add_option( 'admbud_smtp_disable_ssl_verify', '0', '', false );
        }
    }

    /**
     * v5 - Migrate admbud_snippets DB table to file-based storage.
     * Reads all rows, writes each as a .php file, drops the table.
     * Safe to run multiple times (idempotent via admbud_snippets_migrated_to_files option).
     */
    private static function upgrade_5_snippets_to_files(): void {
        if ( ! class_exists( 'Admbud\\Snippets' ) ) {
            $file = ADMBUD_DIR . 'includes/class-snippets.php';
            if ( file_exists( $file ) ) { require_once $file; }
        }
        if ( class_exists( 'Admbud\\Snippets' ) ) {
            \Admbud\Snippets::maybe_migrate_from_db();
        }
    }



    /**
     * v8 - Flip autoload for AB's many small settings options from 'off' to 'on'.
     *
     * On every admin request, AB reads ~50+ option values. If they're not in
     * the autoload cache, each is a separate DB query. Flipping them to
     * autoload='on' means WP primes them all in one query at request start.
     *
     * A small list of intentionally-heavy options stays off (serialized
     * structures that rarely need to be read).
     *
     * WP 6.6+: autoload column accepts 'on'/'off'/'auto'. On older WP (<6.6)
     * it's 'yes'/'no'. This migration handles both by using update_option()'s
     * autoload arg, which WP translates to the right value for the running
     * WP version.
     */
    private static function upgrade_8_autoload_flip(): void {
        global $wpdb;

        // Options that should stay NOT autoloaded (large, rarely read, or
        // written frequently from write-only paths).
        $keep_off = [
            'admbud_colours_css_version',     // only read on cache-miss
            'admbud_smtp_key_salt',           // read on SMTP send path only
            'admbud_dashboard_custom_widgets',// can grow large
        ];

        // Find every AB option currently not autoloaded.
        // WP 6.6+: autoload='off'; WP <6.6: autoload='no'.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $off_options = $wpdb->get_col( // phpcs:ignore WordPress.DB
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'admbud\\_%' AND autoload IN ('off','no')"
        );

        if ( empty( $off_options ) ) {
            return;
        }

        foreach ( $off_options as $option_name ) {
            // Skip options that should remain off-autoload.
            if ( in_array( $option_name, $keep_off, true ) ) {
                continue;
            }
            // Skip dynamic/transient-style keys that can bloat.
            if ( strpos( $option_name, 'admbud_receiver_sync_' ) === 0 ) { continue; }
            if ( strpos( $option_name, 'admbud_bp_last_'       ) === 0 ) { continue; }

            // wp_set_option_autoload() was added in WP 6.4.
            // It's the cleanest API to flip autoload without touching the value.
            if ( function_exists( 'wp_set_option_autoload' ) ) {
                wp_set_option_autoload( $option_name, true );
            } else {
                // Fallback for WP <6.4: raw UPDATE.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->update( // phpcs:ignore WordPress.DB
                    $wpdb->options,
                    [ 'autoload' => 'yes' ],
                    [ 'option_name' => $option_name ],
                    [ '%s' ],
                    [ '%s' ]
                );
            }
        }

        // Bust the alloptions cache so the change takes effect on next request.
        wp_cache_delete( 'alloptions', 'options' );
    }

    /**
     * v9 - Seed default values for options that are read on every admin
     * page but often don't exist in wp_options (they're code-defaulted).
     *
     * When get_option() is called for a non-existent option, WP issues a
     * separate DB query to confirm absence. The autoload cache can't help
     * because there's no row to preload. Inserting the rows with default
     * values makes them part of the autoload prime - one bulk query at
     * request start covers them all.
     *
     * All seeds are inserted with autoload=on and the value '0' (or empty
     * string for text fields). Uses add_option() which is a no-op if the
     * option already exists, so this is safe to re-run.
     */
    private static function upgrade_9_seed_defaults(): void {
        // Quick Settings toggles - 22 options, all default '0' (off).
        $qs_defaults = [
            'admbud_qs_disable_emoji',
            'admbud_qs_disable_jquery_migrate',
            'admbud_qs_remove_feed_links',
            'admbud_qs_remove_rsd',
            'admbud_qs_remove_wlw',
            'admbud_qs_remove_shortlink',
            'admbud_qs_remove_restapi_link',
            'admbud_qs_disable_embeds',
            'admbud_qs_disable_xmlrpc',
            'admbud_qs_disable_rest_api',
            'admbud_qs_disable_file_edit',
            'admbud_qs_disable_feeds',
            'admbud_qs_disable_self_ping',
            'admbud_qs_disable_comments_default',
            'admbud_qs_duplicate_post',
            'admbud_qs_user_last_seen',
            'admbud_qs_allow_svg',
            'admbud_qs_hide_adminbar_frontend',
            'admbud_qs_hide_adminbar_backend',
            'admbud_qs_hide_adminbar_checklist',
            'admbud_qs_hide_adminbar_noindex',
            'admbud_qs_collapse_menu',
            'admbud_qs_sidebar_user_menu',
            'admbud_qs_remove_version',
        ];

        // Other frequently-read options with sensible defaults.
        $other_defaults = [
            'admbud_license_activation_limit'  => '',
        ];

        foreach ( $qs_defaults as $opt ) {
            add_option( $opt, '0', '', true );
        }
        foreach ( $other_defaults as $opt => $default ) {
            add_option( $opt, $default, '', true );
        }

        // Bust alloptions cache so the new rows are picked up.
        wp_cache_delete( 'alloptions', 'options' );
    }

    /**
     * v10 - Drop legacy options removed by the Remote security hardening
     * pass. Currently just `admbud_source_allow_any` (the "anyone with the key
     * can pull" escape hatch). The Remote code no longer reads it but a
     * stale row in wp_options would still show up in audit tooling and
     * wastes a query slot.
     */
    private static function upgrade_10_drop_legacy_source_options(): void {
        $legacy = [
            // Removed in the Remote security hardening pass.
            'admbud_source_allow_any',
            // Removed when BEM rename became a true in-place rename - the
            // option was always a no-op afterwards.
            'admbud_bricks_bem_preserve_styles',
        ];
        foreach ( $legacy as $key ) {
            delete_option( $key );
        }
        // Bust the alloptions cache so cleared rows don't linger in object cache.
        wp_cache_delete( 'alloptions', 'options' );
    }

}

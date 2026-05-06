<?php
/**
 * Admin Buddy - Snippets (File-Based Storage)
 *
 * Architecture (FluentSnippets-inspired):
 *  - Each snippet = one .php file in wp-content/ab-snippets/
 *  - Metadata stored as PHP docblock at top of each file
 *  - index.php caches all parsed metadata - ZERO DB queries at runtime
 *  - Runtime execution = include calls (PHP) or inline output (CSS/JS/HTML)
 *  - Standalone: snippets keep running even if Admin Buddy is deactivated
 *    (achieved via mu-plugin loader - future enhancement)
 *  - Safe mode: ?admbud_safe=1 bypasses all execution
 *  - Error recovery: shutdown handler detects fatals, marks snippet disabled
 *    via index rebuild (no DB write needed)
 *
 * Multisite:
 *  - Each subsite has its own snippet directory under its upload path
 *  - No custom table = no dbDelta = no per-site table creation
 *  - Network admin can push shared snippets via Remote module
 *
 * File naming:  ab-snippet-{id}.php  (id = timestamp-based integer)
 * Index file:   ab-snippets/index.php (auto-rebuilt on every save/toggle/delete)
 *
 * Migration: existing DB rows are migrated to files on first load
 * (class-upgrade.php calls Snippets::maybe_migrate_from_db()).
 *
 * @package Admbud
 */

namespace Admbud;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Snippets {

    // -- Constants -------------------------------------------------------------
    const DIR_NAME   = 'admin-buddy/snippets';
    const INDEX_FILE = 'index.php';
    const SAFE_KEY   = 'admbud_snippets_safe_mode'; // transient during PHP execution

    // Keep TABLE_OPT for migration detection only
    const TABLE_OPT  = 'admbud_snippets_table_version';

    private static ?Snippets $instance = null;

    /** Cached index - loaded once per request. */
    private ?array $index_cache = null;

    // -- Singleton -------------------------------------------------------------

    public static function get_instance(): Snippets {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_loaded',                      [ $this, 'execute_snippets'   ] );
        add_action( 'wp_ajax_admbud_snippet_save',        [ $this, 'ajax_save'          ] );
        add_action( 'wp_ajax_admbud_snippet_get',         [ $this, 'ajax_get'           ] );
        add_action( 'wp_ajax_admbud_snippet_toggle',      [ $this, 'ajax_toggle'        ] );
        add_action( 'wp_ajax_admbud_snippet_delete',      [ $this, 'ajax_delete'        ] );
        add_action( 'wp_ajax_admbud_snippet_reorder',     [ $this, 'ajax_reorder'       ] );
        add_action( 'rest_api_init',                  [ $this, 'register_rest'      ] );
    }

    // -- REST: PHP function names (autocomplete source) -----------------------
    // Returns names of all user-defined functions in the running install.
    // "user" = WP core + active plugins + active theme + user's own snippet
    // code (excludes PHP internals). The list is cached as a transient keyed
    // by WP version + plugin set, so a single fetch covers a session.

    public function register_rest(): void {
        register_rest_route( 'admin-buddy/v1', '/php-functions', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_php_functions' ],
            'permission_callback' => function () {
                return current_user_can( 'admbud_manage_snippets' ) || current_user_can( 'manage_options' );
            },
        ] );
    }

    public function rest_get_php_functions() {
        $active = (array) get_option( 'active_plugins', [] );
        sort( $active );
        $cache_key = 'admbud_php_fn_' . md5( get_bloginfo( 'version' ) . '|' . implode( '|', $active ) );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return new \WP_REST_Response( $cached, 200 );
        }

        $defined = get_defined_functions();
        $names   = array_filter( $defined['user'] ?? [], function ( $n ) {
            if ( ! is_string( $n ) || strlen( $n ) < 4 ) { return false; }
            // Skip closures, anonymous, namespaced internals.
            if ( strpos( $n, '{closure}' ) !== false ) { return false; }
            if ( strpos( $n, "\0" )        !== false ) { return false; }
            return true;
        } );
        $names = array_values( array_unique( $names ) );
        sort( $names, SORT_NATURAL | SORT_FLAG_CASE );

        set_transient( $cache_key, $names, DAY_IN_SECONDS );
        return new \WP_REST_Response( $names, 200 );
    }

    // -- Filesystem paths ------------------------------------------------------

    /**
     * Absolute path to the snippets directory for the current site.
     * On multisite each site has its own directory under its upload path.
     */
    public static function snippets_dir(): string {
        $upload = wp_upload_dir();
        return trailingslashit( $upload['basedir'] ) . self::DIR_NAME;
    }

    public static function index_path(): string {
        return self::snippets_dir() . '/' . self::INDEX_FILE;
    }

    public static function snippet_path( int $id ): string {
        return self::snippets_dir() . '/ab-snippet-' . $id . '.php';
    }

    /**
     * Ensure the snippets directory exists and is protected.
     */
    public static function ensure_dir(): bool {
        $dir = self::snippets_dir();
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        // Block direct HTTP access - Apache/LiteSpeed.
        $htaccess = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        }

        // Block directory listing and direct access - all servers.
        $index = $dir . '/index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        }

        // Also protect the parent admin-buddy uploads directory.
        $parent = dirname( $dir );
        $parent_index = $parent . '/index.php';
        if ( is_dir( $parent ) && ! file_exists( $parent_index ) ) {
            file_put_contents( $parent_index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        }

        global $wp_filesystem;
        if ( ! $wp_filesystem ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        return is_dir( $dir ) && $wp_filesystem->is_writable( $dir );
    }

    // -- Index management ------------------------------------------------------

    /**
     * Read the cached index. Returns array of snippet metadata objects.
     * The index is a PHP file returning an array - zero parsing overhead.
     */
    public function get_index(): array {
        if ( $this->index_cache !== null ) {
            return $this->index_cache;
        }

        $path = self::index_path();
        if ( ! file_exists( $path ) ) {
            $this->index_cache = [];
            return [];
        }

        // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
        $data = include $path;
        $this->index_cache = is_array( $data ) ? $data : [];
        return $this->index_cache;
    }

    /**
     * Rebuild the index by scanning all snippet files.
     * Called after every save, toggle, delete, or reorder.
     * Cost: one directory scan + N file header reads - acceptable on save.
     */
    public function rebuild_index(): void {
        $dir = self::snippets_dir();
        if ( ! is_dir( $dir ) ) {
            $this->index_cache = [];
            return;
        }

        $snippets = [];
        $files    = glob( $dir . '/ab-snippet-*.php' ) ?: [];

        foreach ( $files as $file ) {
            $meta = $this->parse_file_header( $file );
            if ( $meta ) {
                $snippets[] = $meta;
            }
        }

        // Sort by priority ASC, id ASC
        usort( $snippets, function ( $a, $b ) {
            if ( $a['priority'] !== $b['priority'] ) {
                return $a['priority'] <=> $b['priority'];
            }
            return $a['id'] <=> $b['id'];
        } );

        // Write index as a PHP file returning an array
        $export = var_export( $snippets, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
        $contents = "<?php\n/**\n * Admin Buddy Snippets Index\n"
            . " * Auto-generated - do not edit manually.\n"
            . " * Rebuilt: " . current_time( 'mysql' ) . "\n */\n\n"
            . "return " . $export . ";\n";

        file_put_contents( self::index_path(), $contents );
        $this->index_cache = $snippets;
    }

    /**
     * Parse the metadata header from a snippet file.
     * Returns an associative array or null on failure.
     */
    private function parse_file_header( string $filepath ): ?array {
        if ( ! is_readable( $filepath ) ) { return null; }

        // Read only the first 2048 bytes - headers are always at the top.
        global $wp_filesystem;
        if ( ! $wp_filesystem ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        $contents = $wp_filesystem->get_contents( $filepath );
        if ( ! $contents ) { return null; }
        $header = substr( $contents, 0, 2048 );

        // Extract ID from filename: ab-snippet-{id}.php
        preg_match( '/ab-snippet-(\d+)\.php$/', $filepath, $m );
        $id = isset( $m[1] ) ? (int) $m[1] : 0;
        if ( ! $id ) { return null; }

        $meta = [
            'id'             => $id,
            'title'          => '',
            'type'           => 'php',
            'scope'          => 'global',
            'position'       => 'footer',
            'priority'       => 10,
            'active'         => 1,
            'error'          => null,
            'notes'          => '',
            'source_id'      => '',
            'source_item_id' => 0,
            'sync_hash'      => '',
            'created_at'     => '',
            'updated_at'     => '',
            'file'           => $filepath,
        ];

        // Parse header tags: " * Key: Value"
        $tag_map = [
            'Title'        => 'title',
            'Type'         => 'type',
            'Scope'        => 'scope',
            'Position'     => 'position',
            'Priority'     => 'priority',
            'Active'       => 'active',
            'Error'        => 'error',
            'Notes'        => 'notes',
            'SourceId'     => 'source_id',
            'SourceItemId' => 'source_item_id',
            'SyncHash'     => 'sync_hash',
            'Created'      => 'created_at',
            'Updated'      => 'updated_at',
        ];

        foreach ( $tag_map as $tag => $key ) {
            if ( preg_match( '/ \* ' . $tag . ':[^\S\n]*(.+)/m', $header, $match ) ) {
                $value = trim( $match[1] );
                // Type coerce
                $int_keys = [ 'priority', 'active', 'source_item_id' ];
                if ( in_array( $key, $int_keys, true ) ) {
                    $meta[ $key ] = (int) $value;
                } elseif ( $key === 'error' ) {
                    $meta[ $key ] = $value === 'null' || $value === '' ? null : $value;
                } else {
                    $meta[ $key ] = $value;
                }
            }
        }

        return $meta;
    }

    /**
     * Write a snippet file. Creates or overwrites ab-snippet-{id}.php.
     */
    private function write_snippet_file( int $id, array $data, string $code ): bool {
        self::ensure_dir();

        $error_line = $data['error'] ?? null;
        $error_str  = $error_line ? $error_line : 'null';

        $header = "<?php\n"
            . "/**\n"
            . " * Title: "        . ( $data['title']          ?? '' )           . "\n"
            . " * Type: "         . ( $data['type']           ?? 'php' )        . "\n"
            . " * Scope: "        . ( $data['scope']          ?? 'global' )     . "\n"
            . " * Position: "     . ( $data['position']       ?? 'footer' )     . "\n"
            . " * Priority: "     . ( (int) ( $data['priority']   ?? 10 ) )     . "\n"
            . " * Active: "       . ( (int) ( $data['active']     ?? 1  ) )     . "\n"
            . " * Error: "        . $error_str                                  . "\n"
            . " * Notes: "        . ( $data['notes']          ?? '' )           . "\n"
            . " * SourceId: "     . ( $data['source_id']      ?? '' )           . "\n"
            . " * SourceItemId: " . ( (int) ( $data['source_item_id'] ?? 0 ) )  . "\n"
            . " * SyncHash: "     . ( $data['sync_hash']      ?? '' )           . "\n"
            . " * Created: "      . ( $data['created_at']     ?? current_time( 'mysql' ) ) . "\n"
            . " * Updated: "      . current_time( 'mysql' )                    . "\n"
            . " */\n\n";

        $contents = $header . $code . "\n";
        $path     = self::snippet_path( $id );
        $result   = file_put_contents( $path, $contents );
        return $result !== false;
    }

    /**
     * Generate a unique ID for a new snippet.
     * Uses microtime so IDs are always increasing.
     */
    private function generate_id(): int {
        return (int) ( microtime( true ) * 1000 );
    }

    /**
     * Public wrapper for generate_id - used by settings import.
     */
    public function generate_id_public(): int {
        // Add 1ms offset per call within same request to avoid collisions.
        usleep( 1100 );
        return $this->generate_id();
    }

    // -- EXECUTION ENGINE ------------------------------------------------------

    public function execute_snippets(): void {
        if ( defined( 'WP_CLI' ) && WP_CLI ) { return; }
        if ( wp_doing_ajax() ) { return; }

        // Safe mode: ?admbud_safe=1 - skip ALL snippet execution
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['admbud_safe'] ) && $_GET['admbud_safe'] === '1' ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
            if ( current_user_can( 'manage_options' ) ) {
                add_action( 'admin_notices', static function () {
                    echo '<div class="notice notice-warning"><p>';
                    echo '<strong>' . esc_html__( 'Admin Buddy Safe Mode', 'admin-buddy' ) . ':</strong> ';
                    echo esc_html__( 'All code snippets are bypassed on this page load.', 'admin-buddy' );
                    echo '</p></div>';
                } );
            }
            return;
        }

        $index = $this->get_index();
        if ( empty( $index ) ) { return; }

        $is_admin    = is_admin();
        $is_frontend = ! $is_admin;

        foreach ( $index as $meta ) {
            if ( empty( $meta['active'] ) ) { continue; }

            $scope = $meta['scope'] ?? 'global';
            if ( $scope === 'frontend' && ! $is_frontend ) { continue; }
            if ( $scope === 'admin'    && ! $is_admin    ) { continue; }

            switch ( $meta['type'] ?? 'css' ) {
                case 'css':
                    $this->enqueue_css( $meta );
                    break;
                case 'js':
                    $this->enqueue_js( $meta );
                    break;
                case 'html':
                    $this->inject_html( $meta );
                    break;
            }
        }
    }


    // -- CSS / JS / HTML -------------------------------------------------------

    private function enqueue_css( array $meta ): void {
        $id   = (int) $meta['id'];
        $file = $meta['file'] ?? self::snippet_path( $id );
        if ( ! file_exists( $file ) ) { return; }

        $hook = ( $meta['scope'] === 'admin' || is_admin() ) ? 'admin_enqueue_scripts' : 'wp_enqueue_scripts';
        add_action( $hook, function () use ( $id, $file ) {
            $code   = $this->read_snippet_code( $file );
            $handle = 'ab-snippet-css-' . $id;
            wp_register_style( $handle, false, [], ADMBUD_VERSION );
            wp_enqueue_style( $handle );
            wp_add_inline_style( $handle, $code );
        }, (int) ( $meta['priority'] ?? 10 ) );
    }

    private function enqueue_js( array $meta ): void {
        $id        = (int) $meta['id'];
        $file      = $meta['file'] ?? self::snippet_path( $id );
        $in_footer = ( ( $meta['position'] ?? 'footer' ) !== 'head' );
        if ( ! file_exists( $file ) ) { return; }

        $scope    = $meta['scope'] ?? 'global';
        $is_admin = is_admin();
        $hook     = ( $scope === 'admin' || $is_admin ) ? 'admin_enqueue_scripts' : 'wp_enqueue_scripts';

        add_action( $hook, function () use ( $id, $file, $in_footer ) {
            $code   = $this->read_snippet_code( $file );
            $handle = 'ab-snippet-js-' . $id;
            wp_register_script( $handle, false, [], ADMBUD_VERSION, $in_footer );
            wp_enqueue_script( $handle );
            wp_add_inline_script( $handle, $code );
        }, (int) ( $meta['priority'] ?? 10 ) );
    }

    private function inject_html( array $meta ): void {
        $id        = (int) $meta['id'];
        $file      = $meta['file'] ?? self::snippet_path( $id );
        $in_footer = ( ( $meta['position'] ?? 'footer' ) !== 'head' );
        if ( ! file_exists( $file ) ) { return; }

        $scope    = $meta['scope'] ?? 'global';
        $is_admin = is_admin();
        $hook     = ( $scope === 'admin' || $is_admin )
            ? ( $in_footer ? 'admin_footer' : 'admin_head' )
            : ( $in_footer ? 'wp_footer'    : 'wp_head'    );

        add_action( $hook, function () use ( $file ) {
            // HTML snippets are admin-authored markup (gated by the
            // admbud_manage_snippets / manage_options capability at save time).
            // The user explicitly asks the plugin to inject their HTML into
            // the page; sanitising would corrupt valid markup and defeat the
            // feature's purpose. read_snippet_code() reads the trusted file
            // written by ajax_save() above.
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-authored HTML; cap-gated at save.
            echo $this->read_snippet_code( $file ) . "\n";
        }, (int) ( $meta['priority'] ?? 10 ) );
    }

    /**
     * Extract just the code portion from a snippet file
     * (everything after the closing docblock comment).
     */
    private function read_snippet_code( string $file ): string {
        $contents = file_get_contents( $file );
        if ( $contents === false ) { return ''; }

        // Code starts after the closing */ of the header docblock
        $pos = strpos( $contents, ' */' );
        if ( $pos === false ) { return $contents; }

        // Skip past */ and any trailing whitespace/newlines
        return ltrim( substr( $contents, $pos + 3 ) );
    }

    // -- PUBLIC DATA API -------------------------------------------------------
    // These replace the old $wpdb->get_results() calls and return objects // phpcs:ignore WordPress.DB
    // with the same field names so the UI (render-tab-snippets.php) is unchanged.

    public function get_active_snippets(): array {
        return array_map(
            [ $this, 'array_to_object' ],
            array_filter( $this->get_index(), fn( $m ) => ! empty( $m['active'] ) )
        );
    }

    public function get_all_snippets(): array {
        return array_map( [ $this, 'array_to_object' ], $this->get_index() );
    }


    public function get_snippet( int $id ): ?object {
        foreach ( $this->get_index() as $meta ) {
            if ( (int) $meta['id'] === $id ) {
                return $this->array_to_object( $meta );
            }
        }
        return null;
    }

    /** Convert a metadata array to a stdClass so UI code using ->prop works. */
    private function array_to_object( array $meta ): object {
        return (object) $meta;
    }


    /**
     * Public wrapper for write_snippet_file - used by class-receiver.php.
     */
    public function write_snippet_file_public( int $id, array $data, string $code ): bool {
        return $this->write_snippet_file( $id, $data, $code );
    }

    // -- AJAX HANDLERS --------------------------------------------------------
    // Same external interface as DB version - UI JavaScript is unchanged.

    public function ajax_get(): void {
        check_ajax_referer( 'admbud_snippets_nonce', 'nonce' );
        if ( ! current_user_can( 'admbud_manage_snippets' ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }
        $id      = (int) ( $_POST['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
        $snippet = $this->get_snippet( $id );
        if ( ! $snippet ) {
            wp_send_json_error( [ 'message' => __( 'Snippet not found.', 'admin-buddy' ) ] );
        }

        // Read actual code from file
        $file = self::snippet_path( $id );
        $code = file_exists( $file ) ? $this->read_snippet_code( $file ) : '';

        wp_send_json_success( [
            'id'       => $snippet->id,
            'title'    => $snippet->title,
            'type'     => $snippet->type,
            'scope'    => $snippet->scope,
            'position' => $snippet->position,
            'priority' => $snippet->priority,
            'active'   => $snippet->active,
            'code'     => $code,
            'notes'    => $snippet->notes ?? '',
        ] );
    }

    public function ajax_save(): void {
        check_ajax_referer( 'admbud_snippets_nonce', 'nonce' );
        if ( ! current_user_can( 'admbud_manage_snippets' ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'admin-buddy' ) ] );
        }

        $id       = isset( $_POST['id'] )       ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
        $title    = sanitize_text_field(    $_POST['title']    ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $type     = sanitize_key(           $_POST['type']     ?? 'css' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $scope    = sanitize_key(           $_POST['scope']    ?? 'global' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $position = sanitize_key(           $_POST['position'] ?? 'footer' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $priority = isset( $_POST['priority'] ) ? (int) $_POST['priority'] : 10; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
        $active   = isset( $_POST['active'] )   ? (int) (bool) $_POST['active'] : 1; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
        // $code is user-authored CSS / JS / HTML / (Pro) PHP snippet body —
        // stored verbatim for later output. Sanitising would corrupt valid
        // content (e.g. CSS `>` selectors, JS `<` operators, HTML attributes
        // with entities). Write access is gated by the capability + nonce
        // checks above; only users who can manage snippets can submit code.
        // For type === 'php', the Pro-only check_dangerous_functions() and
        // syntax_check_php() validators run before storage (see below).
        //
        // Defensive: strip NUL bytes (defeat null-byte truncation attacks on
        // any downstream string handler) and reject invalid UTF-8 sequences
        // (which can desync header/body parsing in read_snippet_code()).
        $code     = (string) wp_unslash( $_POST['code'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $code     = str_replace( "\0", '', $code );
        $code     = wp_check_invalid_utf8( $code );
        $notes    = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );

        if ( trim( $code ) === '' ) {
            wp_send_json_error( [ 'message' => __( 'Snippet code cannot be empty.', 'admin-buddy' ) ] );
        }

        // PHP support is Pro-only. In Free, the run_php()/syntax_check_php()
        // method chain is marker-stripped, so reject type=php at the gate.
        $php_supported = method_exists( $this, 'check_dangerous_functions' );
        $allowed_types = $php_supported ? [ 'php', 'css', 'js', 'html' ] : [ 'css', 'js', 'html' ];
        if ( ! in_array( $type,     $allowed_types,                              true ) ) { $type     = 'css';    }
        if ( ! in_array( $scope,    [ 'frontend', 'admin', 'global' ],            true ) ) { $scope    = 'global'; }
        if ( ! in_array( $position, [ 'head', 'footer' ],                         true ) ) { $position = 'footer'; }

        if ( $type === 'php' && ! $php_supported ) {
            wp_send_json_error( [ 'message' => __( 'PHP snippets require Admin Buddy Pro.', 'admin-buddy' ) ] );
        }


        // Preserve existing metadata for updates
        $existing   = $id > 0 ? $this->get_snippet( $id ) : null;
        $created_at = $existing ? $existing->created_at : current_time( 'mysql' );
        $source_id  = $existing ? ( $existing->source_id      ?? '' )  : '';
        $source_iid = $existing ? ( $existing->source_item_id ?? 0  )  : 0;
        $sync_hash  = $existing ? ( $existing->sync_hash       ?? '' ) : '';

        // New snippet gets a fresh ID
        if ( ! $id ) {
            $id = $this->generate_id();
        }

        $data = [
            'title'          => $title,
            'type'           => $type,
            'scope'          => $scope,
            'position'       => $position,
            'priority'       => $priority,
            'active'         => $active,
            'error'          => null,
            'notes'          => $notes,
            'source_id'      => $source_id,
            'source_item_id' => $source_iid,
            'sync_hash'      => $sync_hash,
            'created_at'     => $created_at,
        ];

        $written = $this->write_snippet_file( $id, $data, $code );
        if ( ! $written ) {
            wp_send_json_error( [ 'message' => __( 'Could not write snippet file. Check filesystem permissions.', 'admin-buddy' ) ] );
        }

        $this->rebuild_index();
        $is_new = ! $existing;
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- admbud_ is the plugin prefix.
        do_action( 'admbud_snippet_saved', $id, $title );

        wp_send_json_success( [
            'id'      => $id,
            'message' => $is_new
                ? __( 'Snippet created.', 'admin-buddy' )
                : __( 'Snippet saved.',   'admin-buddy' ),
        ] );
    }

    public function ajax_toggle(): void {
        check_ajax_referer( 'admbud_snippets_nonce', 'nonce' );
        if ( ! current_user_can( 'admbud_manage_snippets' ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }

        $id     = (int) ( $_POST['id']     ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
        $active = (int) (bool) ( $_POST['active'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
        $file   = self::snippet_path( $id );

        if ( ! file_exists( $file ) ) {
            wp_send_json_error( [ 'message' => __( 'Snippet not found.', 'admin-buddy' ) ] );
        }

        $contents = file_get_contents( $file );
        $contents = preg_replace( '/ \* Active: \d+/', ' * Active: ' . $active, $contents );
        // Clear error when re-enabling
        if ( $active ) {
            $contents = preg_replace( '/ \* Error: .*/m', ' * Error: null', $contents );
        }
        file_put_contents( $file, $contents );
        $this->rebuild_index();

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- admbud_ is the plugin prefix.
        do_action( 'admbud_snippet_toggled', $id, (bool) $active );
        wp_send_json_success();
    }


    public function ajax_delete(): void {
        check_ajax_referer( 'admbud_snippets_nonce', 'nonce' );
        if ( ! current_user_can( 'admbud_manage_snippets' ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }

        $id   = (int) ( $_POST['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
        $file = self::snippet_path( $id );

        if ( file_exists( $file ) ) {
            wp_delete_file( $file );
        }

        $this->rebuild_index();
        wp_send_json_success();
    }

    public function ajax_reorder(): void {
        check_ajax_referer( 'admbud_snippets_nonce', 'nonce' );
        if ( ! current_user_can( 'admbud_manage_snippets' ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }

        $order = array_map( 'intval', (array) ( $_POST['order'] ?? [] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        foreach ( $order as $priority => $id ) {
            $file = self::snippet_path( $id );
            if ( ! file_exists( $file ) ) { continue; }
            $contents = file_get_contents( $file );
            $contents = preg_replace(
                '/ \* Priority: \d+/',
                ' * Priority: ' . ( $priority * 10 ),
                $contents
            );
            file_put_contents( $file, $contents );
        }

        $this->rebuild_index();
        wp_send_json_success();
    }

    // -- DB → FILE MIGRATION ---------------------------------------------------

    /**
     * Called from class-upgrade.php on first load after update.
     * Migrates all rows from admbud_snippets table to files, then drops the table.
     */
    public static function maybe_migrate_from_db(): void {
        global $wpdb;

        // If the table doesn't exist, nothing to migrate
        $table = esc_sql( $wpdb->prefix . 'admbud_snippets' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = $wpdb->get_var( // phpcs:ignore WordPress.DB
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) // phpcs:ignore WordPress.DB
        );
        if ( ! $exists ) { return; }

        // Check if we already migrated
        if ( get_option( 'admbud_snippets_migrated_to_files' ) ) { return; }

        if ( ! self::ensure_dir() ) {
            // Can't write to filesystem - leave DB intact
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" ); // phpcs:ignore WordPress.DB
        if ( empty( $rows ) ) {
            update_option( 'admbud_snippets_migrated_to_files', '1' );
            return;
        }

        $instance = self::get_instance();
        $migrated = 0;

        foreach ( $rows as $row ) {
            // Use original DB id as file id for continuity
            $id   = (int) $row->id;
            $code = $row->code ?? '';
            $data = [
                'title'          => $row->title          ?? '',
                'type'           => $row->type            ?? 'php',
                'scope'          => $row->scope           ?? 'global',
                'position'       => $row->position        ?? 'footer',
                'priority'       => (int) ( $row->priority       ?? 10 ),
                'active'         => (int) ( $row->active         ?? 1  ),
                'error'          => $row->error           ?? null,
                'notes'          => $row->notes           ?? '',
                'source_id'      => $row->source_id       ?? '',
                'source_item_id' => (int) ( $row->source_item_id ?? 0  ),
                'sync_hash'      => $row->sync_hash       ?? '',
                'created_at'     => $row->created_at      ?? current_time( 'mysql' ),
            ];

            if ( $instance->write_snippet_file( $id, $data, $code ) ) {
                $migrated++;
            }
        }

        if ( $migrated > 0 ) {
            $instance->rebuild_index();
        }

        // Mark migration complete
        update_option( 'admbud_snippets_migrated_to_files', '1' );

        // Drop old table - data is now in files
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB

        // Clean up the old version option
        delete_option( self::TABLE_OPT );
    }

    /**
     * Legacy stub - called by class-receiver.php import_snippets.
     * The receiver writes directly to files via the public save interface.
     */
    public static function create_table(): void {
        // No-op: file-based storage needs no table
        // Kept for backward compatibility with activation hook references
    }
}

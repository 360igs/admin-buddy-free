<?php
/**
 * Media Manager module - taxonomy-backed media folders + bulk SEO.
 *
 * Storage: a hidden hierarchical taxonomy `admbud_media_folder` on the
 * `attachment` post type. Native + portable: folders are WP terms, so they
 * survive plugin deactivation (no lock-in) and scale via the indexed term
 * tables. NOT a custom table, NOT physical filesystem folders.
 *
 * Two surfaces (hybrid UI):
 *   1. A drag-drop folder-tree sidebar injected into the native Media Library
 *      (list + grid) and every wp.media modal (insert dialog, page builders).
 *   2. A dedicated "Media Manager" settings tab for power ops (bulk SEO,
 *      export-map). The tab is rendered by class-settings.php.
 *
 * All attachment filtering funnels through one stable core filter,
 * `ajax_query_attachments_args`, so grid view, the insert modal and
 * page-builder dialogs are all covered by a single chokepoint.
 *
 * Pro-only: the whole file is removed from the Free build by build-free.sh.
 *
 * @package Admbud
 */

namespace Admbud;

use Admbud\MediaManager\FolderTree;

defined( 'ABSPATH' ) || exit;

class MediaManager {

	const TAXONOMY    = 'admbud_media_folder';
	const META_COLOR  = 'admbud_folder_color';
	const META_ORDER  = 'admbud_folder_order';
	const META_ROLES  = 'admbud_folder_roles'; // term meta: array of role slugs allowed to see the folder. Empty/absent = everyone.
	const META_REPLACED = '_admbud_replaced'; // attachment post-meta: replace timestamp (drives the ?v= cache-buster).
	const NONCE       = 'admbud_mm';
	const VCOUNT_OPT  = 'admbud_mm_vcounts'; // transient: { all, uncategorized }.
	const LOCK_KEY    = 'admbud_mm_lock';    // object-cache lock for order rewrites.
	const PER_PAGE    = 80;

	// Config prefs (set on the MM tab; consumed by the injection sidebar).
	const OPT_DEFAULT_COLOR    = 'admbud_mm_default_color';    // hex applied to new folders.
	const OPT_DEFAULT_EXPANDED = 'admbud_mm_default_expanded'; // '1' = tree starts expanded.
	const OPT_TRASH_ENABLED    = 'admbud_mm_trash_enabled';    // '1' = self-define MEDIA_TRASH + show Trash pseudo-folder.
	const OPT_TOOL_ROLES       = 'admbud_mm_tool_roles';       // map tool_key => [role slugs] granted that tool. Empty/absent = admin-only.
	const OPT_SHOW_COUNT       = 'admbud_mm_show_count';       // '1' = show the per-folder file count in the tree.
	const OPT_RECURSIVE_VIEW   = 'admbud_mm_recursive_view';   // '1' = a folder shows its files PLUS all descendants' (count + grid).
	const OPT_SHOW_SIZE        = 'admbud_mm_show_size';        // '1' = compute + show per-folder size totals (off = skip the heavy scan for 20k+ libraries).

	const TRASH_FOLDER         = '__trash__'; // pseudo-folder selector used by the filter chokepoint.

	// Unused-images scan (Phase 2): batched referenced-set build cached per user.
	const UNUSED_SET_OPT  = 'admbud_mm_unused_set';   // transient base (accumulating set).
	const UNUSED_LIST_OPT = 'admbud_mm_unused_list';  // transient base (final sorted id list).
	const MISSING_LIST_OPT = 'admbud_mm_missing_list'; // transient base (missing-file id list).
	const DUP_MAP_OPT  = 'admbud_mm_dup_map';   // transient base (accumulating hash => [ids]).
	const DUP_LIST_OPT = 'admbud_mm_dup_list';  // transient base (final removable-duplicate list).
	const DUP_SCAN_PER = 40;                    // attachments hashed per chunk (hashing reads every byte).
	const ORPHAN_LIST_OPT = 'admbud_mm_orphan_list'; // transient base (orphan rel-path list).
	const UNUSED_TTL      = 1800;                      // 30 min.
	const UNUSED_SCAN_PER = 100;                       // posts/meta rows per scan chunk.
	const UNUSED_LIST_PER = 60;                        // results per list page.

	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Register the taxonomy + term meta early so queries and REST see it.
		add_action( 'init', [ $this, 'register' ], 5 );

		// Native media-library injection assets + templates.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_injection' ], 100 );
		// Front-end page builders (Bricks, Divi VB, + a generic fallback) load the
		// wp.media modal outside admin.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_injection_frontend' ], 100 );
		// Elementor's editor bypasses both hooks above (admin_action_elementor +
		// die, plus it resets $wp_scripts and removes all wp_enqueue_scripts
		// handlers), so use its own post-enqueue action as the injection point.
		add_action( 'elementor/editor/after_enqueue_scripts', [ $this, 'enqueue_injection_elementor' ] );


		// Page-level folder panel on upload.php (works in BOTH list and grid -
		// it sits beside the whole Media Library, not inside the Backbone grid).
		add_action( 'admin_notices', [ $this, 'render_panel' ] );

		// List-view (upload.php?mode=list) server-side folder filtering.
		add_filter( 'parse_query', [ $this, 'apply_list_view_filter' ] );

		// The chokepoint: filter every wp.media attachment query by folder.
		add_filter( 'ajax_query_attachments_args', [ $this, 'filter_attachment_query' ] );


		// Keep the virtual ("All" / "Uncategorized" / "Trash") count cache fresh.
		// trashed_post / untrashed_post move counts between `inherit` and `trash`
		// without changing the absolute total - hook both so the Trash row updates.
		foreach ( [ 'added_term_relationship', 'deleted_term_relationships', 'add_attachment', 'delete_attachment', 'trashed_post', 'untrashed_post' ] as $hook ) {
			add_action( $hook, [ $this, 'flush_virtual_counts' ] );
		}

		// Upload-to-folder: assign freshly-uploaded attachments to the folder the
		// user is currently viewing. The client injects the active folder id into
		// the Plupload multipart params; we read + validate it here.
		add_action( 'add_attachment', [ $this, 'assign_uploaded_to_folder' ] );

		// AJAX endpoints.
		add_action( 'wp_ajax_admbud_mm_create_folder',  [ $this, 'ajax_create_folder' ] );
		add_action( 'wp_ajax_admbud_mm_rename_folder',  [ $this, 'ajax_rename_folder' ] );
		add_action( 'wp_ajax_admbud_mm_delete_folder',  [ $this, 'ajax_delete_folder' ] );
		add_action( 'wp_ajax_admbud_mm_move_folder',    [ $this, 'ajax_move_folder' ] );
		add_action( 'wp_ajax_admbud_mm_set_color',      [ $this, 'ajax_set_color' ] );
		add_action( 'wp_ajax_admbud_mm_assign',         [ $this, 'ajax_assign' ] );
		add_action( 'wp_ajax_admbud_mm_get_tree',       [ $this, 'ajax_get_tree' ] );
		add_action( 'wp_ajax_admbud_mm_get_contents',   [ $this, 'ajax_get_contents' ] );
		add_action( 'wp_ajax_admbud_mm_save_prefs',     [ $this, 'ajax_save_prefs' ] );
		add_action( 'wp_ajax_admbud_mm_trash',          [ $this, 'ajax_trash' ] );
		add_action( 'wp_ajax_admbud_mm_restore',        [ $this, 'ajax_restore' ] );
		add_action( 'wp_ajax_admbud_mm_restore_all',    [ $this, 'ajax_restore_all' ] );
		add_action( 'wp_ajax_admbud_mm_force_delete',   [ $this, 'ajax_force_delete' ] );
		add_action( 'wp_ajax_admbud_mm_empty_trash',    [ $this, 'ajax_empty_trash' ] );
	}

	/**
	 * Is the Trash feature on? Reads the user-facing toggle (default on). The
	 * actual MEDIA_TRASH define happens in admin-buddy.php's boot block at
	 * plugins_loaded - admin/ajax paths read the constant strictly after that.
	 */
	public static function trash_enabled(): bool {
		return '0' !== (string) admbud_get_option( self::OPT_TRASH_ENABLED, '1' );
	}

	// =========================================================================
	// REGISTRATION
	// =========================================================================

	/**
	 * Register the folder taxonomy and its term meta.
	 *
	 * `update_count_callback => _update_generic_term_count` is mandatory:
	 * attachments are post_status=inherit, so the default publish-only callback
	 * would report every folder count as 0.
	 */
	public function register(): void {
		register_taxonomy( self::TAXONOMY, 'attachment', [
			'labels'                => [
				'name'          => __( 'Media Folders', 'admin-buddy' ),
				'singular_name' => __( 'Media Folder', 'admin-buddy' ),
				'menu_name'     => __( 'Folders', 'admin-buddy' ),
				'all_items'     => __( 'All Folders', 'admin-buddy' ),
				'edit_item'     => __( 'Edit Folder', 'admin-buddy' ),
				'add_new_item'  => __( 'Add Folder', 'admin-buddy' ),
				'search_items'  => __( 'Search Folders', 'admin-buddy' ),
			],
			'hierarchical'          => true,
			'public'                => false,
			'publicly_queryable'    => false,
			'show_ui'               => true,
			'show_in_menu'          => false,
			'show_in_nav_menus'     => false,
			'show_in_rest'          => true,
			'show_admin_column'     => true,
			'show_in_quick_edit'    => false,
			'rewrite'               => false,
			// query_var MUST be false: otherwise WP interprets our list-mode URL
			// param `?admbud_media_folder=<term_id>` as a SLUG match (matching
			// nothing) and overrides our explicit term_id tax_query.
			'query_var'             => false,
			'update_count_callback' => '_update_generic_term_count',
			'capabilities'          => [
				'manage_terms' => 'manage_options',
				'edit_terms'   => 'manage_options',
				'delete_terms' => 'manage_options',
				'assign_terms' => 'upload_files',
			],
		] );

		register_term_meta( self::TAXONOMY, self::META_COLOR, [
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_hex_color',
		] );
		register_term_meta( self::TAXONOMY, self::META_ORDER, [
			'type'              => 'integer',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'absint',
		] );
	}


	// =========================================================================
	// VIRTUAL COUNTS ("All" / "Uncategorized")
	// =========================================================================

	/**
	 * Counts for the pseudo-folders. Cached 12h; busted by attachment /
	 * term-relationship / trash hooks so the tree never runs COUNT() on every render.
	 *
	 * @return array{all:int,uncategorized:int,trash:int}
	 */
	public function virtual_counts(): array {
		$cached = get_transient( self::VCOUNT_OPT );
		if ( is_array( $cached ) && isset( $cached['all'], $cached['uncategorized'], $cached['trash'] ) ) {
			$counts = $cached;
		} else {
			$post_counts = wp_count_posts( 'attachment' );
			$all   = (int) $post_counts->inherit;
			$trash = (int) ( $post_counts->trash ?? 0 );

			$uncat = (int) ( new \WP_Query( [
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'tax_query'      => [ [ // phpcs:ignore WordPress.DB.SlowDBQuery -- folder taxonomy filter is the feature's core query.
					'taxonomy' => self::TAXONOMY,
					'operator' => 'NOT EXISTS',
				] ],
			] ) )->found_posts;

			$counts = [ 'all' => $all, 'uncategorized' => $uncat, 'trash' => $trash ];
			set_transient( self::VCOUNT_OPT, $counts, 12 * HOUR_IN_SECONDS );
		}


		// Per-role visibility: "All Media" must reflect what THIS user can see, not
		// the whole library - otherwise the count/size badge is misleading once a
		// folder is hidden from them. Subtract the hidden folders' files (no-op for
		// admins / unrestricted users, where hidden_totals() returns 0/0). The
		// global transient above stays user-agnostic; the adjustment is per-request.
		return $counts;
	}

	public function flush_virtual_counts(): void {
		// NOTE: the size cache (FolderTree::SIZE_CACHE) is intentionally NOT busted
		// here. Size totals are decoupled from per-mutation invalidation so a large
		// library does not re-scan every attachment's metadata on every upload / move /
		// delete; sizes ride their own TTL (fresh within the hour) and are cleared only
		// when the feature is toggled. Counts stay exact; sizes are approximate.
		delete_transient( self::VCOUNT_OPT );
	}

	// =========================================================================
	// NATIVE LIBRARY INJECTION
	// =========================================================================

	/**
	 * Enqueue the folder-tree sidebar assets wherever the wp.media modal loads.
	 *
	 * Gating: requires upload_files; skipped on AB's own settings page (the tab
	 * has its own tree); skipped when a known competing folder plugin is active
	 * (avoids double sidebars / conflicting tax_queries).
	 *
	 * Priority 100 so core/other plugins have already enqueued `media-views`.
	 */
	public function enqueue_injection( string $hook ): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		// Don't inject on AB's own settings page.
		if ( in_array( $hook, [ 'settings_page_admbud', 'toplevel_page_admbud' ], true ) ) {
			return;
		}
		// Only when the media modal/grid is actually present on this screen.
		// `media-editor` is registered wherever wp.media can open (more reliable
		// than checking `media-views` enqueued on custom pages); upload.php
		// always qualifies.
		if ( 'upload.php' !== $hook && ! wp_script_is( 'media-editor', 'registered' ) ) {
			return;
		}
		// The richer page panel (header/footer chrome + slide-panels) renders only
		// on upload.php; other admin screens get the lean in-modal sidebar.
		$this->enqueue_folder_assets( 'upload.php' === $hook );
	}

	/**
	 * Front-end builders (e.g. Bricks) run on the front end, so
	 * admin_enqueue_scripts never fires - the wp.media insert modal there would
	 * have no folder sidebar. Hook wp_enqueue_scripts and load our assets when
	 * we're inside a supported builder.
	 */
	public function enqueue_injection_frontend(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		// Named front-end builders - reliable even when they enqueue wp.media after
		// this hook fires (we force-load our assets regardless).
		if ( $this->is_builder_context() ) {
			$this->enqueue_folder_assets();
			return;
		}
		// Generic fallback: any OTHER front-end editor that has ALREADY pulled in
		// the wp.media modal by the time we run. This lets builders we don't name
		// explicitly (Oxygen, Beaver Builder, Brizy, ...) get the folder sidebar
		// with no per-builder code - provided they don't rewrite the enqueue
		// pipeline the way Elementor does (handled via its own action).
		if ( wp_script_is( 'media-editor', 'enqueued' ) || wp_script_is( 'media-views', 'enqueued' ) ) {
			$this->enqueue_folder_assets();
		}
	}

	/**
	 * Elementor editor integration.
	 *
	 * Elementor renders its editor on admin_action_elementor and then die()s, so
	 * admin_enqueue_scripts never fires; it also resets the global $wp_scripts and
	 * removes every wp_enqueue_scripts handler, so the front-end hook is wiped too.
	 * Its after-enqueue action is the one reliable injection point - assets
	 * registered here land in Elementor's freshly-rebuilt script registry.
	 */
	public function enqueue_injection_elementor(): void {
		if ( current_user_can( 'upload_files' ) ) {
			$this->enqueue_folder_assets();
		}
	}

	/**
	 * Detect a front-end page-builder editing context that uses the wp.media modal.
	 */
	private function is_builder_context(): bool {
		// Bricks builder (runs on the front end).
		if ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) {
			return true;
		}
		if ( function_exists( 'bricks_is_builder_main' ) && bricks_is_builder_main() ) {
			return true;
		}
		// Divi Visual Builder (front-end builder). et_core_is_fb_enabled() is
		// Divi's own canonical "is the front-end builder active on this request"
		// gate and is already true by wp_enqueue_scripts.
		if ( function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled() ) {
			return true;
		}
		return false;
	}

	/**
	 * Enqueue the folder-tree sidebar assets (shared by admin + builder paths).
	 * Priority 100 so core/other plugins have already enqueued wp.media.
	 *
	 * @param bool $with_panels Load the richer page-panel chrome (AB slide-panels
	 *                          + styled-select dropdowns). True only on upload.php;
	 *                          the lean in-modal / front-end sidebar omits it to
	 *                          keep builder-edit payload minimal.
	 */
	private function enqueue_folder_assets( bool $with_panels = false ): void {
		if ( wp_script_is( 'admbud-media-folders', 'enqueued' ) ) {
			return; // already loaded this request.
		}

		$v   = $this->asset_version();
		$url = ADMBUD_URL . 'assets/';

		// Ensure wp.media exists (page builders / custom screens) and is loaded
		// before our script. Depend on jquery only - depending on `media-views`
		// can silently drop the script if that handle isn't enqueued yet on a
		// given screen. Our JS guards for wp.media at runtime.
		wp_enqueue_media();

		// AB design-system base. Register idempotently - class-settings.php already
		// registers these on AB's own pages, but upload.php / wp.media modals /
		// front-end builders don't, and the sidebar styles are token-driven. Mirror
		// the Checklist front-end precedent (class-checklist.php::enqueue_assets()).
		if ( ! wp_style_is( 'admbud-tokens', 'registered' ) ) {
			wp_register_style( 'admbud-tokens', $url . 'tokens.css', [], $v );
		}

		// Page-panel only: the canonical slide-panel CSS lives in admin.css
		// (admbud-core) and the styled-select widget needs ab-dropdown. The lean
		// in-modal / front-end sidebar doesn't use either, so they load only here.
		$style_deps      = [ 'admbud-tokens' ];
		$sidebar_js_deps = [ 'jquery' ];
		if ( $with_panels ) {
			if ( ! wp_style_is( 'admbud-core', 'registered' ) ) {
				wp_register_style( 'admbud-core', $url . 'admin.css', [ 'admbud-tokens' ], $v );
			}
			wp_enqueue_style( 'admbud-core' );
			if ( ! wp_style_is( 'admbud-dropdown', 'registered' ) ) {
				wp_register_style( 'admbud-dropdown', $url . 'ab-dropdown.css', [ 'admbud-core' ], $v );
			}
			wp_enqueue_style( 'admbud-dropdown' );
			if ( ! wp_script_is( 'admbud-dropdown', 'registered' ) ) {
				wp_register_script( 'admbud-dropdown', $url . 'js/ab-dropdown.js', [], $v, true );
			}
			wp_enqueue_script( 'admbud-dropdown' );
			$sidebar_js_deps[] = 'admbud-dropdown';
			// Depend on admbud-core so OUR stylesheet cascades AFTER admin.css and
			// the .admbud-mm-slidepanel overrides (z-index, no-transform slide) win
			// over the base .ab-slide-panel rules by source order.
			$style_deps[] = 'admbud-core';

			// The Bulk-SEO / export engine lives in tab-media-manager.js. Reuse it
			// to drive the upload.php panels (same ids/classes) - it reads the
			// localized `admbudMM` global, so depend on the sidebar script that
			// prints it + ab-dropdown for the styled-select fields.
			if ( ! wp_script_is( 'admbud-tab-media-manager', 'registered' ) ) {
				wp_register_script( 'admbud-tab-media-manager', $url . 'js/tab-media-manager.js', [ 'admbud-dropdown', 'admbud-media-folders' ], $v, true );
			}
			wp_enqueue_script( 'admbud-tab-media-manager' );
		}

		// Sidebar styles depend on tokens (always) + admbud-core (page panel) so
		// --ab-* props resolve everywhere and our slide-panel overrides cascade last.
		wp_enqueue_style( 'admbud-media-folders', $url . 'media-library-folders.css', $style_deps, $v );

		// Accent colour: AB primary when the Colours module is on, otherwise the
		// user's WP admin colour scheme accent (never a hardcoded brand colour).
		$colours_on = in_array( 'colours', (array) ( admbud_enabled_modules() ?: [] ), true );
		$accent     = $colours_on
			? admbud_get_option( 'admbud_colours_primary', \Admbud\Colours::DEFAULT_PRIMARY )
			: \Admbud\Settings::scheme_accent_hex();
		$accent = sanitize_hex_color( $accent );
		if ( $accent ) {
			wp_add_inline_style( 'admbud-media-folders', ':root{--admbud-mm-accent:' . $accent . ';}' );

			// Front-end builders (Bricks): the Colours module only themes admin,
			// so the wp.media modal's WP buttons fall back to blue. Retint the
			// admin-theme-colour vars - scoped to .media-modal so we don't touch
			// the builder's own UI - to match Gutenberg's AB-coloured buttons.
			if ( ! is_admin() && $colours_on ) {
				wp_add_inline_style( 'admbud-media-folders',
					'.media-modal{'
					. '--wp-admin-theme-color:' . $accent . ';'
					. '--wp-admin-theme-color-darker-10:color-mix(in srgb,' . $accent . ' 90%,#000);'
					. '--wp-admin-theme-color-darker-20:color-mix(in srgb,' . $accent . ' 80%,#000);'
					. '}'
				);
			}
		}

		wp_enqueue_script(
			'admbud-media-folders',
			$url . 'js/media-library-folders.js',
			$sidebar_js_deps,
			$v,
			true
		);
		wp_localize_script( 'admbud-media-folders', 'admbudMM', $this->js_data() );
	}

	/**
	 * Shared JS payload for both the injection script and the settings tab.
	 *
	 * @return array
	 */
	public function js_data(): array {
		$counts = $this->virtual_counts();

		// Adapt the panel to AB's CONTENT colours (not the sidebar): when the user
		// sets a dark content/body background, the panel goes dark to match. The
		// sidebar keeps its own colours regardless.
		$panel_bg = $panel_text = '';
		if ( in_array( 'colours', (array) ( admbud_enabled_modules() ?: [] ), true ) ) {
			$panel_bg   = sanitize_hex_color( admbud_get_option( 'admbud_colours_body_bg', '' ) );
			$panel_text = sanitize_hex_color( admbud_get_option( 'admbud_colours_content_text', '' ) );
		}

		return [
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( self::NONCE ),
			'tree'      => FolderTree::build( 0 ),
			'virtual'   => $counts,
			'taxKey'    => self::TAXONOMY,
			'panelBg'   => $panel_bg ? $panel_bg : '',
			'panelText' => $panel_text ? $panel_text : '',
			// Per-role folder visibility: the "Visibility..." control is admin-only;
			// $roles feeds the role-checkbox popover (administrator excluded - it
			// always bypasses).
			'canManage' => current_user_can( 'manage_options' ),
			// Free: only folder operations exist and are admin-only. The client gates
			// the inline folder controls / context-menu items off this map, so admins
			// get the folder CRUD actions and everyone else gets none. (Pro replaces
			// this with the full per-role tool-access map below.)
			'tools'     => array_fill_keys(
				[ 'folder_create', 'folder_rename', 'folder_delete', 'folder_move', 'folder_color' ],
				current_user_can( 'manage_options' )
			),
			'roles'     => [],
			// Tree starts expanded unless the user turned it off on the MM tab.
			'defaultExpanded' => '0' !== (string) admbud_get_option( self::OPT_DEFAULT_EXPANDED, '1' ),
			// Trash pseudo-folder + drag-to-trash + Empty Trash UI all gate on this.
			'trashEnabled'    => self::trash_enabled(),
			// Per-folder file count visibility (toggled in folder settings).
			'showCount'       => '0' !== (string) admbud_get_option( self::OPT_SHOW_COUNT, '1' ),
			'showSize'        => '0' !== (string) admbud_get_option( self::OPT_SHOW_SIZE, '1' ),
			// Recursive view: a folder's count shows its files + all descendants'.
			'recursiveView'   => self::recursive_view_enabled(),
			'i18n'    => [
				'all'         => __( 'All Media', 'admin-buddy' ),
				'uncategorized' => __( 'Uncategorized', 'admin-buddy' ),
				'trash'       => __( 'Trash', 'admin-buddy' ),
				'emptyTrash'  => __( 'Empty Trash', 'admin-buddy' ),
				/* translators: %d: number of files */
				'confirmEmptyTrash' => __( 'Permanently delete all %d items in Trash? This cannot be undone.', 'admin-buddy' ),
				'trashed'     => __( 'Moved to Trash.', 'admin-buddy' ),
				'emptied'     => __( 'Trash emptied.', 'admin-buddy' ),
				// Unused-images scan.
				'edit'           => __( 'Edit', 'admin-buddy' ),
				'unusedNone'     => __( 'No unused images found - every image has a reference we could detect.', 'admin-buddy' ),
				/* translators: 1: unused count, 2: total images */
				'unusedFoundN'   => __( '%1$d of %2$d images have no reference found.', 'admin-buddy' ),
				'moveToTrash'    => __( 'Move selected to Trash', 'admin-buddy' ),
				/* translators: %d: number of images */
				'moveNToTrash'   => __( 'Move %d to Trash', 'admin-buddy' ),
				/* translators: %d: number of images */
				'confirmUnusedTrash' => __( 'Move %d image(s) to Trash? You can restore them from the Trash folder.', 'admin-buddy' ),
				/* translators: %d: number of images */
				'trashedN'       => __( '%d moved to Trash.', 'admin-buddy' ),
				'unusedCleared'  => __( 'Done - nothing left to review here.', 'admin-buddy' ),
				'enableTrashFirst' => __( 'Turn on Media Trash in folder settings to remove these safely.', 'admin-buddy' ),
				// Missing-file scan.
				'missingNone'    => __( 'No missing files found - every attachment has its file on disk.', 'admin-buddy' ),
				/* translators: 1: missing count, 2: total attachments */
				'missingFoundN'  => __( '%1$d of %2$d attachments have a missing file.', 'admin-buddy' ),
				'deleteRecords'  => __( 'Delete selected records', 'admin-buddy' ),
				/* translators: %d: number of records */
				'deleteNRecords' => __( 'Delete %d record(s)', 'admin-buddy' ),
				/* translators: %d: number of records */
				'confirmMissingDelete' => __( 'Permanently delete %d library record(s)? The files are already gone, so this only removes the broken entries. This cannot be undone.', 'admin-buddy' ),
				/* translators: %d: number of records */
				'deletedN'       => __( '%d record(s) deleted.', 'admin-buddy' ),
				'missingCleared' => __( 'Done - nothing left to review here.', 'admin-buddy' ),
				'restoreFile'    => __( 'Add file back', 'admin-buddy' ),
				'addingFile'     => __( 'Adding…', 'admin-buddy' ),
				'fileRestored'   => __( 'File added back.', 'admin-buddy' ),
				// Duplicate scan.
				'dupNone'        => __( 'No duplicate files found.', 'admin-buddy' ),
				/* translators: 1: number of redundant copies, 2: reclaimable size e.g. "4 MB" */
				'dupFoundN'      => __( '%1$d redundant copies · %2$s reclaimable', 'admin-buddy' ),
				/* translators: %s: the kept original's file name */
				'dupOf'          => __( 'duplicate of %s', 'admin-buddy' ),
				/* translators: %d: number of places the file is used */
				'usedInN'        => __( 'Used in %d place(s)', 'admin-buddy' ),
				'notReferenced'  => __( 'No references found', 'admin-buddy' ),
				/* translators: %d: number of additional places beyond those listed */
				'usedMore'       => __( '+%d more', 'admin-buddy' ),
				/* translators: 1: number trashed, 2: number of references relinked */
				'dupMerged'      => __( '%1$d removed · %2$d references relinked to the kept copy.', 'admin-buddy' ),
				// Orphan scan.
				'orphanNone'     => __( 'No orphaned files found.', 'admin-buddy' ),
				/* translators: 1: number of orphan files, 2: reclaimable size e.g. "4 MB" */
				'orphanFoundN'   => __( '%1$d orphaned files · %2$s reclaimable', 'admin-buddy' ),
				'inContent'      => __( 'Appears in content', 'admin-buddy' ),
				'deleteFiles'    => __( 'Delete selected files', 'admin-buddy' ),
				/* translators: %d: number of files */
				'deleteNFiles'   => __( 'Delete %d file(s)', 'admin-buddy' ),
				/* translators: %d: number of files */
				'confirmOrphanDelete' => __( 'Permanently delete %d file(s) from disk? There is no Library entry to restore from - this cannot be undone.', 'admin-buddy' ),
				/* translators: %d: number of files */
				'orphanDeletedN' => __( '%d file(s) deleted.', 'admin-buddy' ),
				// Bulk rename.
				'renamePreviewEmpty' => __( 'No files would change with this pattern.', 'admin-buddy' ),
				/* translators: %d: number of files that will be renamed */
				'renameWillN'    => __( '%d file(s) will be renamed', 'admin-buddy' ),
				'renameArrow'    => __( '→', 'admin-buddy' ),
				/* translators: 1: renamed count, 2: references relinked, 3: failed count */
				'renameDoneN'    => __( '%1$d renamed · %2$d references relinked · %3$d failed', 'admin-buddy' ),
				'renameApply'    => __( 'Rename files', 'admin-buddy' ),
				/* translators: %d: number of files */
				'renameApplyN'   => __( 'Rename %d file(s)', 'admin-buddy' ),
				'confirmRename'  => __( 'Rename these files and relink their references? This changes file URLs.', 'admin-buddy' ),
				'loadMore'       => __( 'Load more', 'admin-buddy' ),
				'bulkToRestore'  => __( 'to add back', 'admin-buddy' ),
				'bulkPresent'    => __( 'already present', 'admin-buddy' ),
				'bulkRestoredN'  => __( 'added back', 'admin-buddy' ),
				// Bulk-ops floating toolbar.
				/* translators: %d: number of selected files */
				'nSelected'      => __( '%d selected', 'admin-buddy' ),
				'selectAll'      => __( 'Select all', 'admin-buddy' ),
				'moveToFolder'   => __( 'Move to…', 'admin-buddy' ),
				'clearSelection' => __( 'Clear', 'admin-buddy' ),
				/* translators: %d: number of files */
				'confirmDeleteN' => __( 'Permanently delete %d file(s)? This cannot be undone.', 'admin-buddy' ),
				'restore'     => __( 'Restore', 'admin-buddy' ),
				'restoreAll'  => __( 'Restore all', 'admin-buddy' ),
				'restored'    => __( 'Restored.', 'admin-buddy' ),
				'restoredAndMoved' => __( 'Restored and moved.', 'admin-buddy' ),
				'folderDeleted' => __( 'Folder deleted.', 'admin-buddy' ),
				'deletePermanent' => __( 'Delete', 'admin-buddy' ),
				'confirmDeletePermanent' => __( 'Permanently delete this file? This cannot be undone.', 'admin-buddy' ),
				'deleted'     => __( 'File deleted.', 'admin-buddy' ),
				'replace'     => __( 'Replace', 'admin-buddy' ),
				'replaceMedia' => __( 'Replace media', 'admin-buddy' ),
				'replacing'   => __( 'Replacing…', 'admin-buddy' ),
				'replaced'    => __( 'File replaced.', 'admin-buddy' ),
				'replaceHint' => __( 'If you still see the old file, purge your CDN / browser cache.', 'admin-buddy' ),
				'replaceWith' => __( 'Replace with', 'admin-buddy' ),
				'modeKeep'    => __( 'Keep the same filename', 'admin-buddy' ),
				'modeKeepHint' => __( 'Fastest. Same URL, nothing to relink. Cached or page-builder copies may need a cache clear.', 'admin-buddy' ),
				'modeRename'  => __( 'Rename & update links', 'admin-buddy' ),
				'modeRenameHint' => __( 'New URL. Updates every reference across your content. Best for page builders.', 'admin-buddy' ),
				'replacedRenamed' => __( 'File replaced and renamed.', 'admin-buddy' ),
				'relinked'    => __( 'reference(s) updated.', 'admin-buddy' ),
				'noRefs'      => __( 'No references needed updating.', 'admin-buddy' ),
				'uploading'   => __( 'Uploading…', 'admin-buddy' ),
				'bulkToReplace' => __( 'to replace', 'admin-buddy' ),
				'bulkUnmatched' => __( 'unmatched', 'admin-buddy' ),
				'bulkTypeSkip'  => __( 'wrong type', 'admin-buddy' ),
				'bulkAmbiguous' => __( 'ambiguous', 'admin-buddy' ),
				'bulkReplacedN' => __( 'replaced', 'admin-buddy' ),
				'bulkImportedN' => __( 'imported', 'admin-buddy' ),
				'download'      => __( 'Download', 'admin-buddy' ),
				'downloadFolder' => __( 'Download as ZIP', 'admin-buddy' ),
				'downloadSelected' => __( 'Download', 'admin-buddy' ),
				'selectFirst'   => __( 'Select some files first.', 'admin-buddy' ),
				'structIntro'   => __( 'How should the files be organised in the ZIP?', 'admin-buddy' ),
				'structTree'    => __( 'Keep folder structure', 'admin-buddy' ),
				'structTreeHint' => __( 'Recreates your folders as subfolders inside the ZIP.', 'admin-buddy' ),
				'structFlat'    => __( 'Flat', 'admin-buddy' ),
				'structFlatHint' => __( 'All files in one level. Duplicate names get a number.', 'admin-buddy' ),
				'moveToFolder'  => __( 'Move to folder', 'admin-buddy' ),
				/* translators: %d: number of files moved */
				'movedN'        => __( 'Moved %d file(s).', 'admin-buddy' ),
				'collapseAll'   => __( 'Collapse all folders', 'admin-buddy' ),
				'expandAll'     => __( 'Expand all folders', 'admin-buddy' ),
				'noFolders'     => __( 'No folders yet. Create one first.', 'admin-buddy' ),
				'preparingDownload' => __( 'Preparing download…', 'admin-buddy' ),
				'downloadingN'  => __( 'Downloading', 'admin-buddy' ),
				'filesWord'     => __( 'files', 'admin-buddy' ),
				'skippedMissing' => __( 'missing, skipped', 'admin-buddy' ),
				'emptyFolder'   => __( 'This folder has no files to download.', 'admin-buddy' ),
				'newFolder'   => __( 'New Folder', 'admin-buddy' ),
				'rename'      => __( 'Rename', 'admin-buddy' ),
				'setColour'   => __( 'Set colour', 'admin-buddy' ),
				'delete'      => __( 'Delete', 'admin-buddy' ),
				'galleryShortcode' => __( 'Gallery shortcode…', 'admin-buddy' ),
				'copyId'      => __( 'Copy folder ID', 'admin-buddy' ),
				'visibility'  => __( 'Visibility…', 'admin-buddy' ),
				'visibilityTitle' => __( 'Who can see this folder', 'admin-buddy' ),
				'visibilityHint'  => __( 'No roles selected = everyone. Administrators always see every folder.', 'admin-buddy' ),
				'visibilitySaved' => __( 'Folder visibility updated.', 'admin-buddy' ),
				'visibleTo'   => __( 'Visible to', 'admin-buddy' ),
				'save'        => __( 'Save', 'admin-buddy' ),
				'toolAccessSaved' => __( 'Tool access updated. Users see the change on their next page load.', 'admin-buddy' ),
				'idCopied'    => __( 'Folder ID copied.', 'admin-buddy' ),
				'scCopied'    => __( 'Shortcode copied.', 'admin-buddy' ),
				'copyFailed'  => __( 'Could not copy. Select the text and copy manually.', 'admin-buddy' ),
				'folders'     => __( 'Folders', 'admin-buddy' ),
				'confirmDelete' => __( 'Files inside are kept and moved to Uncategorized.', 'admin-buddy' ),
				'promptName'  => __( 'Folder name', 'admin-buddy' ),
				'moved'       => __( 'Moved.', 'admin-buddy' ),
				'folderMoved' => __( 'Folder moved.', 'admin-buddy' ),
				'failed'      => __( 'Action failed. Please try again.', 'admin-buddy' ),
				'cancel'      => __( 'Cancel', 'admin-buddy' ),
				'confirm'     => __( 'Delete', 'admin-buddy' ),
			],
		];
	}

	/**
	 * Render the page-level folder panel on upload.php.
	 *
	 * Hooked on admin_notices so it prints inside #wpbody-content, beside the
	 * whole Media Library - so it works identically in LIST and GRID modes and
	 * never overlaps WP's own grid (CSS shifts .wrap right to make room). The
	 * tree itself is populated by JS from the localized admbudMM.tree so create/
	 * rename/delete re-render without a reload.
	 */
	public function render_panel(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'upload' !== $screen->base ) {
			return;
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$can_manage = current_user_can( 'manage_options' );
		// Per-role tool access. $tools[ key ] = can the current user use that tool.
		// $any_tool gates the whole tool chrome (toolbar/footer/panels); individual
		// launchers below are gated per tool. The "tools" group (the Tools panel +
		// its footer/toolbar launchers) shows if ANY of its scan/export tools are
		// granted. Settings + Tool access stay admin-only.
		// Free: only folder operations exist and are admin-only; every Pro tool is off.
		// (Pro replaces this map with the per-role tool-access matrix below.)
		$tools = [
			'folder_create' => $can_manage, 'folder_rename' => $can_manage,
			'folder_delete' => $can_manage, 'folder_move'   => $can_manage,
			'folder_color'  => $can_manage,
			'bulk_seo'      => false, 'bulk_replace' => false, 'replace' => false,
			'download'      => false, 'rename'       => false, 'unused'  => false,
			'missing'       => false, 'dup'          => false, 'orphan'  => false,
			'export_import' => false,
		];
		// $any_tool gates the toolbar/footer/panels - only the LAUNCHER tools count
		// (folder_* tools are context-menu actions with no launcher, so a folder-only
		// grant must NOT render empty chrome).
		$any_tool  = $can_manage;
		$tools_grp = $can_manage || $tools['unused'] || $tools['missing'] || $tools['dup'] || $tools['orphan'] || $tools['export_import'];
		?>
		<?php
		$sort_opts = [
			'date'     => __( 'Date', 'admin-buddy' ),
			'title'    => __( 'Name', 'admin-buddy' ),
			'modified' => __( 'Last modified', 'admin-buddy' ),
		];
		?>
		<div id="admbud-mm-panel" class="admbud-mm-panel" role="navigation" aria-label="<?php esc_attr_e( 'Media folders', 'admin-buddy' ); ?>">
			<div class="admbud-mm-panel__head">
				<span class="admbud-mm-panel__title"><?php esc_html_e( 'Folders', 'admin-buddy' ); ?></span>
				<span class="admbud-mm-panel__actions">
					<button type="button" id="admbud-mm-toggle-all" class="admbud-mm-iconbtn" aria-label="<?php esc_attr_e( 'Collapse all folders', 'admin-buddy' ); ?>" data-ab-tip="<?php esc_attr_e( 'Collapse all folders', 'admin-buddy' ); ?>"><svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="4 9 12 17 20 9"/></svg></button>
					<?php if ( $tools['folder_create'] ) : ?>
					<button type="button" id="admbud-mm-new" class="admbud-mm-panel__new"><?php esc_html_e( '+ New', 'admin-buddy' ); ?></button>
					<?php endif; ?>
					<button type="button" id="admbud-mm-collapse" class="admbud-mm-iconbtn" aria-label="<?php esc_attr_e( 'Collapse folder panel', 'admin-buddy' ); ?>" data-ab-tip="<?php esc_attr_e( 'Collapse panel', 'admin-buddy' ); ?>"><svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg></button>
				</span>
			</div>
			<div class="admbud-mm-panel__toolbar">
				<div class="admbud-mm-search">
					<svg class="admbud-mm-search__icon" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
					<input type="search" id="admbud-mm-search" class="admbud-mm-search__input" placeholder="<?php esc_attr_e( 'Search folders and files', 'admin-buddy' ); ?>" autocomplete="off" aria-label="<?php esc_attr_e( 'Search folders and files', 'admin-buddy' ); ?>">
					<button type="button" id="admbud-mm-search-clear" class="admbud-mm-search__clear" hidden aria-label="<?php esc_attr_e( 'Clear search', 'admin-buddy' ); ?>">&times;</button>
				</div>
				<div class="admbud-mm-sortrow">
					<?php $this->dropdown_select( 'ab-mm-sort', $sort_opts, 'date' ); ?>
					<button type="button" id="admbud-mm-sort-dir" class="admbud-mm-iconbtn" data-order="DESC" aria-label="<?php esc_attr_e( 'Sort direction', 'admin-buddy' ); ?>" data-ab-tip="<?php esc_attr_e( 'Sort direction', 'admin-buddy' ); ?>">
						<svg width="16" height="16" viewBox="0 0 32 32" fill="currentColor" aria-hidden="true"><rect x="6" y="11" width="3" height="18" rx="1.5"/><path d="M2.5 12H12.5L7.5 4Z"/><rect x="16" y="5.5" width="14" height="3" rx="1.5"/><rect x="16" y="14.5" width="10" height="3" rx="1.5"/><rect x="16" y="23.5" width="6" height="3" rx="1.5"/></svg>
					</button>
				</div>
			</div>
			<nav id="admbud-mm-breadcrumb" class="admbud-mm-breadcrumb" aria-label="<?php esc_attr_e( 'Current folder path', 'admin-buddy' ); ?>" hidden></nav>
			<ul id="admbud-mm-tree" class="admbud-mm-tree" role="tree" aria-busy="true"></ul>
			<?php if ( $any_tool ) : ?>
			<div class="admbud-mm-panel__foot">
				<?php if ( $can_manage ) : ?>
				<button type="button" class="admbud-mm-iconbtn admbud-mm-iconbtn--tip-up admbud-mm-foot-btn" data-mm-open="admbud-mm-panel-settings" data-ab-tip="<?php esc_attr_e( 'Settings', 'admin-buddy' ); ?>" aria-label="<?php esc_attr_e( 'Settings', 'admin-buddy' ); ?>">
					<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
				</button>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
		<button type="button" id="admbud-mm-reopen" class="admbud-mm-iconbtn admbud-mm-iconbtn--tip-right admbud-mm-reopen" aria-label="<?php esc_attr_e( 'Show folder panel', 'admin-buddy' ); ?>" data-ab-tip="<?php esc_attr_e( 'Show panel', 'admin-buddy' ); ?>"><svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg></button>
		<?php
		if ( $any_tool ) {
			$this->render_panels();
		}
	}

	/**
	 * Off-canvas slide-panels launched from the tree-column footer (Bulk SEO +
	 * Tools). Uses the canonical .ab-slide-panel system; styling/z-index tweaks
	 * for the upload.php context live in media-library-folders.css.
	 */
	private function render_panels(): void {
		$close = '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';
		// Per-role tool access (panels render for any tool-granted user; each panel +
		// its inner launchers are gated to the matching tool). Settings + Tool access
		// stay admin-only.
		$can_manage = current_user_can( 'manage_options' );
		?>
		<div class="ab-backdrop admbud-mm-backdrop" id="admbud-mm-backdrop"></div>

		<?php
		// Folder settings, formerly the standalone "Media Manager" tab in Admin Buddy.
		// Moved here so config lives where the folders do. Controls reuse the same IDs
		// the tab-media-manager.js savePref() block wires up, so saving needs no JS
		// change - it auto-saves per control via the admbud_mm_save_prefs endpoint.
		$mm_default_color = sanitize_hex_color( (string) admbud_get_option( self::OPT_DEFAULT_COLOR, '' ) );
		$mm_expanded      = '0' !== (string) admbud_get_option( self::OPT_DEFAULT_EXPANDED, '1' );
		$mm_trash         = self::trash_enabled();
		$mm_show_count    = '0' !== (string) admbud_get_option( self::OPT_SHOW_COUNT, '1' );
		$mm_show_size     = '0' !== (string) admbud_get_option( self::OPT_SHOW_SIZE, '1' );
		$mm_recursive     = self::recursive_view_enabled();
		// Picker fallback = the AB accent (Colours primary when that module is on,
		// else the WP admin scheme accent) so a new folder's colour matches AB chrome.
		$mm_colours_on = in_array( 'colours', (array) ( admbud_enabled_modules() ?: [] ), true );
		$mm_accent     = sanitize_hex_color(
			$mm_colours_on
				? (string) admbud_get_option( 'admbud_colours_primary', \Admbud\Colours::DEFAULT_PRIMARY )
				: \Admbud\Settings::scheme_accent_hex()
		);
		if ( ! $mm_accent ) {
			$mm_accent = '#3858e9';
		}
		?>
		<?php if ( $can_manage ) : ?>
		<aside class="ab-slide-panel ab-slide-panel--sm admbud-mm-slidepanel" id="admbud-mm-panel-settings" role="dialog" aria-modal="true" aria-hidden="true" aria-label="<?php esc_attr_e( 'Folder settings', 'admin-buddy' ); ?>">
			<div class="ab-slide-panel__header">
				<h2 class="ab-slide-panel__title"><?php esc_html_e( 'Folder settings', 'admin-buddy' ); ?></h2>
				<button type="button" class="ab-slide-panel__close" data-mm-close aria-label="<?php esc_attr_e( 'Close', 'admin-buddy' ); ?>"><?php echo $close; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG literal. ?></button>
			</div>
			<div class="ab-slide-panel__body">
				<div class="ab-mm-settings">

					<div class="ab-mm-setting">
						<div class="ab-mm-setting__label">
							<label class="ab-label"><?php esc_html_e( 'Default folder colour', 'admin-buddy' ); ?></label>
							<p class="description"><?php esc_html_e( 'Colour automatically applied to newly created folders.', 'admin-buddy' ); ?></p>
						</div>
						<div class="ab-mm-setting__control">
							<label class="ab-toggle">
								<input type="checkbox" id="ab-mm-color-on" <?php checked( (bool) $mm_default_color ); ?>>
								<span class="ab-toggle__track"></span><span class="ab-toggle__thumb"></span>
							</label>
							<input type="color" id="ab-mm-default-color" value="<?php echo esc_attr( $mm_default_color ? $mm_default_color : $mm_accent ); ?>" <?php disabled( ! $mm_default_color ); ?>>
						</div>
					</div>

					<div class="ab-mm-setting">
						<div class="ab-mm-setting__label">
							<label class="ab-label"><?php esc_html_e( 'Expand folders by default', 'admin-buddy' ); ?></label>
							<p class="description"><?php esc_html_e( 'Show subfolders expanded when the folder tree first loads.', 'admin-buddy' ); ?></p>
						</div>
						<div class="ab-mm-setting__control">
							<label class="ab-toggle">
								<input type="checkbox" id="ab-mm-default-expanded" <?php checked( $mm_expanded ); ?>>
								<span class="ab-toggle__track"></span><span class="ab-toggle__thumb"></span>
							</label>
						</div>
					</div>

					<div class="ab-mm-setting">
						<div class="ab-mm-setting__label">
							<label class="ab-label"><?php esc_html_e( 'Show file counts', 'admin-buddy' ); ?></label>
							<p class="description"><?php esc_html_e( 'Show the number of files beside each folder in the tree.', 'admin-buddy' ); ?></p>
						</div>
						<div class="ab-mm-setting__control">
							<label class="ab-toggle">
								<input type="checkbox" id="ab-mm-show-count" <?php checked( $mm_show_count ); ?>>
								<span class="ab-toggle__track"></span><span class="ab-toggle__thumb"></span>
							</label>
						</div>
					</div>


					<div class="ab-mm-setting">
						<div class="ab-mm-setting__label">
							<label class="ab-label"><?php esc_html_e( 'Show subfolder contents', 'admin-buddy' ); ?></label>
							<p class="description"><?php esc_html_e( 'When on, opening a folder shows its files plus everything in its subfolders, and the count reflects the total. Off shows only the files directly in that folder.', 'admin-buddy' ); ?></p>
						</div>
						<div class="ab-mm-setting__control">
							<label class="ab-toggle">
								<input type="checkbox" id="ab-mm-recursive-view" <?php checked( $mm_recursive ); ?>>
								<span class="ab-toggle__track"></span><span class="ab-toggle__thumb"></span>
							</label>
						</div>
					</div>

					<div class="ab-mm-setting">
						<div class="ab-mm-setting__label">
							<label class="ab-label"><?php esc_html_e( 'Media Trash', 'admin-buddy' ); ?></label>
							<p class="description"><?php esc_html_e( 'Two-step delete for media (the same as posts and pages). Deleted files go to a Trash folder you can restore from. With this off, deleting a file removes it immediately. Already-trashed files remain on disk but become invisible until you turn this back on.', 'admin-buddy' ); ?></p>
						</div>
						<div class="ab-mm-setting__control">
							<label class="ab-toggle">
								<input type="checkbox" id="ab-mm-trash-enabled" <?php checked( $mm_trash ); ?>>
								<span class="ab-toggle__track"></span><span class="ab-toggle__thumb"></span>
							</label>
						</div>
					</div>

				</div>
			</div>
			<div class="ab-slide-panel__footer">
				<span class="admbud-mm-foot-note"><?php esc_html_e( 'Changes save automatically.', 'admin-buddy' ); ?></span>
			</div>
		</aside>

		<?php endif; ?>

		<?php
	}

	/**
	 * Render a styled-select (.ab-dropdown--select) - mirrors the markup contract
	 * ab-dropdown.js expects. $options is value => label.
	 *
	 * @param string $id          id for the hidden input (form value), '' for none.
	 * @param array  $options     value => label.
	 * @param string $current     currently-selected value.
	 * @param string $hidden_attr extra bare attribute(s) for the hidden input (e.g. 'data-mode').
	 */
	private function dropdown_select( string $id, array $options, string $current, string $hidden_attr = '' ): void {
		$current_label = $options[ $current ] ?? (string) reset( $options );
		?>
		<div class="ab-dropdown ab-dropdown--select">
			<input type="hidden" data-ab-dropdown-input<?php echo $id ? ' id="' . esc_attr( $id ) . '"' : ''; ?><?php echo $hidden_attr ? ' ' . esc_attr( $hidden_attr ) : ''; ?> value="<?php echo esc_attr( $current ); ?>">
			<button type="button" class="ab-dropdown__trigger" aria-haspopup="listbox" aria-expanded="false">
				<span class="ab-dropdown__value"><?php echo esc_html( $current_label ); ?></span>
				<span class="ab-dropdown__caret" aria-hidden="true">&#9662;</span>
			</button>
			<ul class="ab-dropdown__menu" role="listbox" hidden>
				<?php foreach ( $options as $val => $label ) : ?>
				<li class="ab-dropdown__option<?php echo ( (string) $val === $current ) ? ' is-selected' : ''; ?>" role="option" data-value="<?php echo esc_attr( (string) $val ); ?>"><?php echo esc_html( $label ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}


	/**
	 * Apply the page-level panel's folder selection to the list-table query
	 * (list mode navigates to upload.php?admbud_media_folder=ID).
	 */
	public function apply_list_view_filter( \WP_Query $query ): void {
		global $pagenow;
		// Gate on $pagenow, not get_current_screen() - the latter can be unset
		// when the list-table main query is parsed, which silently disabled the
		// filter (folders showed 0 files despite a non-zero count).
		if ( ! is_admin() || 'upload.php' !== $pagenow || ! $query->is_main_query() ) {
			return;
		}
		// A folder selection is optional, but a restricted user must still have the
		// hidden-folder filter applied to the unfiltered (All Media) list - so we
		// no longer early-return when no folder is selected.
		$val = isset( $_GET[ self::TAXONOMY ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::TAXONOMY ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$tax = [];
		if ( self::TRASH_FOLDER === $val ) {
			$query->set( 'post_status', 'trash' );
		} elseif ( '' !== $val ) {
			$tax[] = $this->tax_query_for( $val, self::recursive_view_enabled() );
		}
		if ( $tax ) {
			$query->set( 'tax_query', $tax ); // phpcs:ignore WordPress.DB.SlowDBQuery -- folder taxonomy filter is the feature's core query.
		}
	}

	/**
	 * The chokepoint: filter the wp.media (Ajax) attachment query by folder.
	 *
	 * Reads the client-set `query.admbud_media_folder` prop. Core already
	 * nonce-checks and capability-gates query-attachments, so we only sanitise.
	 *
	 * @param array $args WP_Query args assembled by core.
	 * @return array
	 */
	public function filter_attachment_query( array $args ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- core verifies the query-attachments nonce/cap upstream; we only read a filter value.
		$val = isset( $_REQUEST['query'][ self::TAXONOMY ] )
			? sanitize_text_field( wp_unslash( $_REQUEST['query'][ self::TAXONOMY ] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$existing = isset( $args['tax_query'] ) && is_array( $args['tax_query'] ) ? $args['tax_query'] : [];

		// Trash view: override core's forced post_status=inherit. Don't add a
		// folder tax_query - the Trash pseudo-folder shows EVERY trashed
		// attachment regardless of which folder they were in before being trashed.
		if ( self::TRASH_FOLDER === $val ) {
			$args['post_status'] = 'trash';
		} elseif ( '' !== $val && '__all__' !== $val ) {
			$existing[] = $this->tax_query_for( $val, self::recursive_view_enabled() );
		}


		if ( $existing ) {
			$args['tax_query'] = $existing; // phpcs:ignore WordPress.DB.SlowDBQuery -- folder taxonomy filter is the feature's core query.
		}
		return $args;
	}


	/**
	 * Build a single tax_query clause from a folder selector value.
	 *
	 * @param string $val Term ID, or '__uncat__' for the Uncategorized pseudo-folder.
	 * @return array
	 */
	private function tax_query_for( string $val, bool $recursive = false ): array {
		if ( '__uncat__' === $val ) {
			return [
				'taxonomy' => self::TAXONOMY,
				'operator' => 'NOT EXISTS',
			];
		}
		// $recursive (Recursive view setting, grid paths only): include descendant
		// terms so a parent folder shows its files PLUS everything beneath it.
		return [
			'taxonomy'         => self::TAXONOMY,
			'field'            => 'term_id',
			'terms'            => [ absint( $val ) ],
			'include_children' => $recursive,
		];
	}

	/**
	 * Recursive view setting: a folder's grid + count include all descendants.
	 * Drives only the two grid filter paths and the tree count render - download
	 * and bulk-SEO keep their own (independent) recursion logic.
	 */
	public static function recursive_view_enabled(): bool {
		return '1' === (string) admbud_get_option( self::OPT_RECURSIVE_VIEW, '0' );
	}

	// =========================================================================
	// AJAX
	// =========================================================================

	/**
	 * Shared guard for mutating endpoints (folder CRUD, SEO, export).
	 */
	private function guard_manage(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'admin-buddy' ) ], 403 );
		}
	}

	/**
	 * Shared guard for read / assign endpoints (any uploader).
	 */
	private function guard_assign(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'admin-buddy' ) ], 403 );
		}
	}

	/**
	 * Guard for folder CRUD. Free = admin-only (manage_options). Pro replaces this
	 * with per-role tool access (guard_tool) so granted non-admins can organise.
	 */
	private function guard_folder( string $tool ): void {
		$this->guard_manage(); // Free: admin only.
	}


	/**
	 * Record an important Media Manager action to AB's Activity Log, when that
	 * module is active. No-op otherwise (the class only exists in Pro builds with
	 * the module enabled). Keeps MM decoupled - a single guarded call site.
	 *
	 * @param string $action      Machine action key (e.g. 'media_folder_deleted').
	 * @param string $severity    info | warning | critical.
	 * @param string $object_type Grouping/filter dimension ('media_folder' | 'media').
	 * @param int    $object_id   Related id (term id / attachment id / 0).
	 * @param string $object_name Human label (folder/file name or a summary).
	 * @param array  $meta        Extra structured detail.
	 */
	private function log_activity( string $action, string $severity, string $object_type, int $object_id, string $object_name, array $meta = [] ): void {
		if ( class_exists( '\Admbud\ActivityLog' ) ) {
			\Admbud\ActivityLog::record( $action, $severity, $object_type, $object_id, $object_name, $meta );
		}
	}


	public function ajax_create_folder(): void {
		$this->guard_folder( 'folder_create' ); // verifies nonce + capability.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in guard_folder() above.
		$name   = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$parent = absint( $_POST['parent'] ?? 0 );
		$color  = sanitize_hex_color( wp_unslash( $_POST['color'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		// No explicit colour? Fall back to the "Default folder colour" pref.
		if ( ! $color ) {
			$color = sanitize_hex_color( (string) admbud_get_option( self::OPT_DEFAULT_COLOR, '' ) );
		}
		if ( '' === $name ) {
			wp_send_json_error( [ 'message' => __( 'Folder name is required.', 'admin-buddy' ) ], 400 );
		}
		$res = wp_insert_term( $name, self::TAXONOMY, [ 'parent' => $parent ] );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( [ 'message' => $res->get_error_message() ], 400 );
		}
		if ( $color ) {
			update_term_meta( (int) $res['term_id'], self::META_COLOR, $color );
		}
		$this->flush_virtual_counts();
		$this->log_activity( 'media_folder_created', 'info', 'media_folder', (int) $res['term_id'], $name );
		wp_send_json_success( [ 'tree' => FolderTree::build( 0 ), 'virtual' => $this->virtual_counts(), 'id' => (int) $res['term_id'] ] );
	}

	public function ajax_rename_folder(): void {
		$this->guard_folder( 'folder_rename' ); // verifies nonce + capability.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in guard_folder() above.
		$id   = absint( $_POST['id'] ?? 0 );
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! $id || '' === $name ) {
			wp_send_json_error( [ 'message' => __( 'Folder name is required.', 'admin-buddy' ) ], 400 );
		}
		$res = wp_update_term( $id, self::TAXONOMY, [ 'name' => $name ] );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( [ 'message' => $res->get_error_message() ], 400 );
		}
		$this->log_activity( 'media_folder_renamed', 'info', 'media_folder', $id, $name );
		wp_send_json_success( [ 'tree' => FolderTree::build( 0 ), 'virtual' => $this->virtual_counts() ] );
	}

	public function ajax_delete_folder(): void {
		$this->guard_folder( 'folder_delete' ); // verifies nonce + capability.
		$id = absint( $_POST['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard_folder() above.
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid folder.', 'admin-buddy' ) ], 400 );
		}
		// Re-parent children to this folder's parent so they aren't orphaned.
		$term = get_term( $id, self::TAXONOMY );
		$new_parent = ( $term && ! is_wp_error( $term ) ) ? (int) $term->parent : 0;
		$children = get_terms( [
			'taxonomy'   => self::TAXONOMY,
			'hide_empty' => false,
			'parent'     => $id,
		] );
		if ( ! is_wp_error( $children ) ) {
			foreach ( $children as $child ) {
				wp_update_term( $child->term_id, self::TAXONOMY, [ 'parent' => $new_parent ] );
			}
		}
		// With Media Trash enabled, the folder's DIRECT files go to Trash (recoverable)
		// instead of silently falling back to Uncategorized when the term is removed.
		// Subfolders (re-parented above) keep their own files. Per-id delete_post cap,
		// mirroring ajax_trash().
		$trashed = 0;
		if ( self::trash_enabled() ) {
			$att_ids = get_posts( [
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'numberposts'    => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'tax_query'      => [ [ // phpcs:ignore WordPress.DB.SlowDBQuery
					'taxonomy'         => self::TAXONOMY,
					'field'            => 'term_id',
					'terms'            => $id,
					'include_children' => false,
				] ],
			] );
			foreach ( $att_ids as $att_id ) {
				if ( current_user_can( 'delete_post', $att_id ) && wp_trash_post( $att_id ) ) {
					$trashed++;
				}
			}
		}
		$fname = ( $term && ! is_wp_error( $term ) ) ? $term->name : ( '#' . $id );
		$res = wp_delete_term( $id, self::TAXONOMY );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( [ 'message' => $res->get_error_message() ], 400 );
		}
		$this->flush_virtual_counts();
		$this->log_activity( 'media_folder_deleted', 'warning', 'media_folder', $id, $fname, [ 'files_trashed' => $trashed ] );
		wp_send_json_success( [ 'trashed' => $trashed, 'tree' => FolderTree::build( 0 ), 'virtual' => $this->virtual_counts() ] );
	}

	/**
	 * Move / reorder a folder. Rewrites sibling order meta - serialised behind
	 * an object-cache lock so two concurrent drags can't lost-update each other.
	 */
	public function ajax_move_folder(): void {
		$this->guard_folder( 'folder_rename' ); // verifies nonce + capability.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in guard_folder() above.
		$id     = absint( $_POST['id'] ?? 0 );
		$parent = absint( $_POST['parent'] ?? 0 );
		$order  = array_map( 'absint', (array) ( $_POST['order'] ?? [] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid folder.', 'admin-buddy' ) ], 400 );
		}
		// Guard against making a folder its own ancestor.
		if ( $parent && $this->is_descendant( $parent, $id ) ) {
			wp_send_json_error( [ 'message' => __( 'Cannot move a folder into its own subfolder.', 'admin-buddy' ) ], 400 );
		}

		if ( false === wp_cache_add( self::LOCK_KEY, 1, '', 5 ) ) {
			wp_send_json_error( [ 'message' => __( 'Another change is in progress. Please retry.', 'admin-buddy' ), 'retry' => true ], 409 );
		}
		try {
			$res = wp_update_term( $id, self::TAXONOMY, [ 'parent' => $parent ] );
			if ( is_wp_error( $res ) ) {
				wp_send_json_error( [ 'message' => $res->get_error_message() ], 400 );
			}
			// Persist sibling order if the client sent an explicit ordering.
			foreach ( $order as $pos => $sibling_id ) {
				update_term_meta( $sibling_id, self::META_ORDER, $pos );
			}
		} finally {
			wp_cache_delete( self::LOCK_KEY );
		}
		$mterm = get_term( $id, self::TAXONOMY );
		$this->log_activity( 'media_folder_moved', 'info', 'media_folder', $id, ( $mterm && ! is_wp_error( $mterm ) ) ? $mterm->name : ( '#' . $id ), [ 'parent' => $parent ] );
		wp_send_json_success( [ 'tree' => FolderTree::build( 0 ), 'virtual' => $this->virtual_counts() ] );
	}

	public function ajax_set_color(): void {
		$this->guard_folder( 'folder_color' ); // verifies nonce + capability.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in guard_folder() above.
		$id    = absint( $_POST['id'] ?? 0 );
		$color = sanitize_hex_color( wp_unslash( $_POST['color'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid folder.', 'admin-buddy' ) ], 400 );
		}
		if ( $color ) {
			update_term_meta( $id, self::META_COLOR, $color );
		} else {
			delete_term_meta( $id, self::META_COLOR );
		}
		$cterm = get_term( $id, self::TAXONOMY );
		$this->log_activity( 'media_folder_colour', 'info', 'media_folder', $id, ( $cterm && ! is_wp_error( $cterm ) ) ? $cterm->name : ( '#' . $id ), [ 'colour' => $color ? $color : 'cleared' ] );
		wp_send_json_success( [ 'tree' => FolderTree::build( 0 ), 'virtual' => $this->virtual_counts() ] );
	}


	/**
	 * Assign attachments to a folder. Single-folder model (append=false): moving
	 * replaces any existing folder. Target '__uncat__'/0 clears the assignment.
	 *
	 * Auto-restore: dragging a TRASHED file out of the Trash pseudo-folder onto
	 * any real folder (or Uncategorized) must also untrash it - otherwise the
	 * term gets assigned but the file stays in post_status=trash, invisible from
	 * the destination folder's view (which queries post_status=inherit). The
	 * folder count would then increment without the file ever appearing there.
	 * Restore is the obvious user intent for "drag this file out of trash".
	 */
	public function ajax_assign(): void {
		// Free: admin-only (manage_options). Pro: upload_files + per-role folder access.
		if ( empty( $assign_guarded ) ) {
			$this->guard_manage();
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in guard_assign()/guard_manage() above.
		$ids    = array_map( 'absint', (array) ( $_POST['ids'] ?? [] ) );
		$target = sanitize_text_field( wp_unslash( $_POST['target'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$ids    = array_filter( $ids );
		if ( empty( $ids ) ) {
			wp_send_json_error( [ 'message' => __( 'No files selected.', 'admin-buddy' ) ], 400 );
		}
		$terms = ( '' === $target || '__uncat__' === $target || '0' === $target ) ? [] : [ absint( $target ) ];
		$restored = 0;
		foreach ( $ids as $att_id ) {
			if ( 'attachment' !== get_post_type( $att_id ) ) {
				continue;
			}
			if ( 'trash' === get_post_status( $att_id ) ) {
				// Per-id cap check mirrors wp_ajax_untrash_post / list-table Restore.
				if ( current_user_can( 'delete_post', $att_id ) && wp_untrash_post( $att_id ) ) {
					$restored++;
				}
			}
			wp_set_object_terms( $att_id, $terms, self::TAXONOMY, false );
		}
		$this->flush_virtual_counts();
		wp_send_json_success( [ 'tree' => FolderTree::build( 0 ), 'virtual' => $this->virtual_counts(), 'count' => count( $ids ), 'restored' => $restored ] );
	}

	/**
	 * Permanently delete one or more attachments (force-delete, skip the trash
	 * route). Used by the per-card "Delete Permanently" overlay in Trash view.
	 * Same per-id cap check as core's row-action delete + Empty Trash.
	 */
	public function ajax_force_delete(): void {
		$this->guard_assign(); // verifies nonce + upload_files (per-id delete_post checked below).
		$ids = array_filter( array_map( 'absint', (array) ( $_POST['ids'] ?? [] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard_assign() above.
		if ( empty( $ids ) ) {
			wp_send_json_error( [ 'message' => __( 'No files selected.', 'admin-buddy' ) ], 400 );
		}
		$deleted = 0;
		foreach ( $ids as $att_id ) {
			if ( 'attachment' !== get_post_type( $att_id ) ) {
				continue;
			}
			if ( ! current_user_can( 'delete_post', $att_id ) ) {
				continue;
			}
			if ( wp_delete_attachment( $att_id, true ) ) {
				$deleted++;
			}
		}
		$this->flush_virtual_counts();
		if ( $deleted ) {
			$this->log_activity( 'media_files_deleted', 'warning', 'media', 0, sprintf( /* translators: %d: count */ _n( 'Permanently deleted %d file', 'Permanently deleted %d files', $deleted, 'admin-buddy' ), $deleted ), [ 'deleted' => $deleted ] );
		}
		wp_send_json_success( [ 'deleted' => $deleted, 'tree' => FolderTree::build( 0 ), 'virtual' => $this->virtual_counts() ] );
	}

	/**
	 * Restore trashed attachments (untrash, no folder change). Used by the
	 * explicit Restore button rendered on each trashed grid card.
	 */
	public function ajax_restore(): void {
		$this->guard_assign(); // verifies nonce + upload_files (per-id delete_post checked below).
		$ids = array_filter( array_map( 'absint', (array) ( $_POST['ids'] ?? [] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard_assign() above.
		if ( empty( $ids ) ) {
			wp_send_json_error( [ 'message' => __( 'No files selected.', 'admin-buddy' ) ], 400 );
		}
		$restored = 0;
		foreach ( $ids as $att_id ) {
			if ( 'attachment' !== get_post_type( $att_id ) ) {
				continue;
			}
			if ( 'trash' !== get_post_status( $att_id ) ) {
				continue; // not in trash; nothing to do.
			}
			if ( ! current_user_can( 'delete_post', $att_id ) ) {
				continue;
			}
			if ( wp_untrash_post( $att_id ) ) {
				$restored++;
			}
		}
		$this->flush_virtual_counts();
		wp_send_json_success( [ 'restored' => $restored, 'tree' => FolderTree::build( 0 ), 'virtual' => $this->virtual_counts() ] );
	}

	/**
	 * Restore EVERY trashed attachment (the Trash row's "Restore all"). Non-
	 * destructive (untrash), so no type-to-confirm; per-id delete_post cap like
	 * ajax_restore(). Mirrors ajax_empty_trash()'s enumeration, untrash instead.
	 */
	public function ajax_restore_all(): void {
		$this->guard_assign();
		if ( ! self::trash_enabled() ) {
			wp_send_json_error( [ 'message' => __( 'Trash is disabled.', 'admin-buddy' ) ], 400 );
		}
		$ids = get_posts( [
			'post_type'      => 'attachment',
			'post_status'    => 'trash',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );
		$restored = 0;
		foreach ( (array) $ids as $att_id ) {
			if ( current_user_can( 'delete_post', $att_id ) && wp_untrash_post( $att_id ) ) {
				$restored++;
			}
		}
		$this->flush_virtual_counts();
		wp_send_json_success( [ 'restored' => $restored, 'tree' => FolderTree::build( 0 ), 'virtual' => $this->virtual_counts() ] );
	}


	/**
	 * Assign a freshly-uploaded attachment to the folder the user is viewing.
	 *
	 * Fires on `add_attachment`. The client (media-library-folders.js) injects
	 * the active folder term id into the Plupload `multipart_params`, so it
	 * arrives in $_POST alongside the upload. The upload request itself is
	 * already nonce-verified by async-upload.php / wp_ajax_upload_attachment, so
	 * we only read + validate the co-submitted folder id (must exist in OUR
	 * taxonomy). No id (or a pseudo-folder) = leave it Uncategorized.
	 *
	 * @param int $attachment_id Newly created attachment ID.
	 */
	public function assign_uploaded_to_folder( int $attachment_id ): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the enclosing upload request (async-upload.php / wp_ajax_upload_attachment) already verifies the media-form nonce; we only read a co-submitted param here.
		$folder = isset( $_POST['admbud_mm_upload_folder'] ) ? absint( wp_unslash( $_POST['admbud_mm_upload_folder'] ) ) : 0;
		if ( ! $folder || ! term_exists( $folder, self::TAXONOMY ) ) {
			return;
		}
		wp_set_object_terms( $attachment_id, [ $folder ], self::TAXONOMY, false );
		$this->flush_virtual_counts();
	}

	public function ajax_get_tree(): void {
		$this->guard_assign();
		wp_send_json_success( [ 'tree' => FolderTree::build( 0 ), 'virtual' => $this->virtual_counts() ] );
	}

	/**
	 * Paginated folder contents (thumbnails + key meta) for the settings tab.
	 */
	public function ajax_get_contents(): void {
		$this->guard_assign(); // verifies nonce + upload_files.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in guard_assign() above.
		$folder = sanitize_text_field( wp_unslash( $_POST['folder'] ?? '__all__' ) );
		$paged  = max( 1, absint( $_POST['paged'] ?? 1 ) );
		$search = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$args = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		$tax = [];
		if ( '__all__' !== $folder && '' !== $folder ) {
			$tax[] = $this->tax_query_for( $folder );
		}
		if ( $tax ) {
			$args['tax_query'] = $tax; // phpcs:ignore WordPress.DB.SlowDBQuery -- folder taxonomy filter is the feature's core query.
		}

		$q     = new \WP_Query( $args );
		$items = [];
		foreach ( $q->posts as $post ) {
			$thumb = wp_get_attachment_image_url( $post->ID, 'thumbnail' );
			$items[] = [
				'id'      => $post->ID,
				'title'   => $post->post_title,
				'alt'     => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
				'caption' => $post->post_excerpt,
				'thumb'   => $thumb ? $thumb : wp_mime_type_icon( $post->ID ),
				'mime'    => $post->post_mime_type,
			];
		}
		wp_send_json_success( [
			'items'    => $items,
			'paged'    => $paged,
			'maxPages' => (int) $q->max_num_pages,
			'total'    => (int) $q->found_posts,
		] );
	}


	/**
	 * Persist the MM tab config prefs (default folder colour, tree expand state).
	 * Auto-saved from the tab on change - one field per request.
	 */
	public function ajax_save_prefs(): void {
		$this->guard_manage(); // verifies the nonce (check_ajax_referer) + manage_options cap.
		// Nonce is verified in guard_manage() above; phpcs can't trace it across the
		// helper, so the two request reads below carry an explicit ignore.
		$key   = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard_manage().
		$value = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard_manage().

		if ( self::OPT_DEFAULT_COLOR === $key ) {
			$color = sanitize_hex_color( $value );
			if ( $color ) {
				admbud_update_option( self::OPT_DEFAULT_COLOR, $color );
			} else {
				admbud_delete_option( self::OPT_DEFAULT_COLOR );
			}
			wp_send_json_success( [ 'value' => $color ] );
		}

		if ( self::OPT_DEFAULT_EXPANDED === $key ) {
			$on = ( '1' === $value ) ? '1' : '0';
			admbud_update_option( self::OPT_DEFAULT_EXPANDED, $on );
			wp_send_json_success( [ 'value' => $on ] );
		}

		if ( self::OPT_TRASH_ENABLED === $key ) {
			$on = ( '1' === $value ) ? '1' : '0';
			admbud_update_option( self::OPT_TRASH_ENABLED, $on );
			// The MEDIA_TRASH define is read on next request; flag in the response
			// so the tab can hint a reload to surface the Trash pseudo-folder.
			wp_send_json_success( [ 'value' => $on, 'reloadRequired' => true ] );
		}

		if ( self::OPT_SHOW_COUNT === $key ) {
			$on = ( '1' === $value ) ? '1' : '0';
			admbud_update_option( self::OPT_SHOW_COUNT, $on );
			// Display-only - the client toggles a body class live, no reload needed.
			wp_send_json_success( [ 'value' => $on ] );
		}


		if ( self::OPT_RECURSIVE_VIEW === $key ) {
			$on = ( '1' === $value ) ? '1' : '0';
			admbud_update_option( self::OPT_RECURSIVE_VIEW, $on );
			// Reload so the grid (server-side tax_query) and the tree counts switch
			// modes together - avoids a stale-cached-query vs fresh-count mismatch.
			wp_send_json_success( [ 'value' => $on, 'reloadRequired' => true ] );
		}

		wp_send_json_error( [ 'message' => __( 'Unknown setting.', 'admin-buddy' ) ], 400 );
	}

	/**
	 * Move attachments to Trash. Honours the toggle - if the user has disabled
	 * the Trash feature the endpoint is a no-op (the client never offers the
	 * drop target either, but defence-in-depth).
	 */
	public function ajax_trash(): void {
		$this->guard_assign(); // verifies nonce + upload_files (per-id delete_post checked below).
		if ( ! self::trash_enabled() ) {
			wp_send_json_error( [ 'message' => __( 'Trash is disabled.', 'admin-buddy' ) ], 400 );
		}
		$ids = array_filter( array_map( 'absint', (array) ( $_POST['ids'] ?? [] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard_assign() above.
		if ( empty( $ids ) ) {
			wp_send_json_error( [ 'message' => __( 'No files selected.', 'admin-buddy' ) ], 400 );
		}
		$trashed = 0;
		foreach ( $ids as $att_id ) {
			if ( 'attachment' !== get_post_type( $att_id ) ) {
				continue;
			}
			// Cap check per-id - upload_files alone is too loose for delete; mirror
			// what core's wp_ajax_delete_post / list-table Trash row action enforce.
			if ( ! current_user_can( 'delete_post', $att_id ) ) {
				continue;
			}
			if ( wp_trash_post( $att_id ) ) {
				$trashed++;
			}
		}
		$this->flush_virtual_counts();
		// Only log bulk trashing (>= 2) - single drag-to-trash is too frequent/low-value.
		if ( $trashed >= 2 ) {
			$this->log_activity( 'media_files_trashed', 'info', 'media', 0, sprintf( /* translators: %d: count */ _n( 'Moved %d file to Trash', 'Moved %d files to Trash', $trashed, 'admin-buddy' ), $trashed ), [ 'trashed' => $trashed ] );
		}
		wp_send_json_success( [ 'trashed' => $trashed, 'tree' => FolderTree::build( 0 ), 'virtual' => $this->virtual_counts() ] );
	}

	/**
	 * Permanently delete every trashed attachment. Type-to-confirm flow + a
	 * cap check per-id; intentionally unpaginated (matches core's Empty Trash).
	 */
	public function ajax_empty_trash(): void {
		$this->guard_manage();
		if ( ! self::trash_enabled() ) {
			wp_send_json_error( [ 'message' => __( 'Trash is disabled.', 'admin-buddy' ) ], 400 );
		}
		$ids = get_posts( [
			'post_type'      => 'attachment',
			'post_status'    => 'trash',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );
		$deleted = 0;
		foreach ( (array) $ids as $att_id ) {
			if ( ! current_user_can( 'delete_post', $att_id ) ) {
				continue;
			}
			// force = true: skip the trash route, delete the file + metadata for real.
			if ( wp_delete_attachment( $att_id, true ) ) {
				$deleted++;
			}
		}
		$this->flush_virtual_counts();
		if ( $deleted ) {
			$this->log_activity( 'media_trash_emptied', 'warning', 'media', 0, sprintf( /* translators: %d: count */ _n( 'Emptied Trash (%d file)', 'Emptied Trash (%d files)', $deleted, 'admin-buddy' ), $deleted ), [ 'deleted' => $deleted ] );
		}
		wp_send_json_success( [ 'deleted' => $deleted, 'tree' => FolderTree::build( 0 ), 'virtual' => $this->virtual_counts() ] );
	}

	// =========================================================================
	// HELPERS
	// =========================================================================

	/**
	 * Is $maybe_descendant inside the subtree of $ancestor? Prevents cyclic moves.
	 */
	private function is_descendant( int $maybe_descendant, int $ancestor ): bool {
		$ancestors = get_ancestors( $maybe_descendant, self::TAXONOMY, 'taxonomy' );
		return in_array( $ancestor, array_map( 'intval', $ancestors ), true ) || $maybe_descendant === $ancestor;
	}

	/**
	 * Dev cache-buster mirroring class-settings.php: suffix the version with a
	 * timestamp in local/dev so asset edits land without a version bump.
	 */
	private function asset_version(): string {
		$v      = ADMBUD_VERSION;
		$is_dev = ( defined( 'WP_DEBUG' ) && WP_DEBUG );
		if ( ! $is_dev && function_exists( 'wp_get_environment_type' ) ) {
			$env    = wp_get_environment_type();
			$is_dev = ( 'local' === $env || 'development' === $env );
		}
		return $is_dev ? $v . '.' . time() : $v;
	}

}

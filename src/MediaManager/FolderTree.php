<?php
/**
 * Media Manager - folder tree builder.
 *
 * Pure helper that turns the `admbud_media_folder` taxonomy terms into a nested
 * tree array consumed by three surfaces: the settings tab render, the native
 * media-library injection (localized to JS), and the export-map JSON.
 *
 * Reads colour + order from term meta in a single primed query (no per-term
 * get_term_meta() N+1). Counts come straight from the native
 * wp_term_taxonomy.count column (kept accurate by the taxonomy's
 * _update_generic_term_count callback - see MediaManager::register()).
 *
 * @package Admbud
 */

namespace Admbud\MediaManager;

defined( 'ABSPATH' ) || exit;

class FolderTree {

	const TAXONOMY   = 'admbud_media_folder';
	const META_COLOR = 'admbud_folder_color';
	const META_ORDER = 'admbud_folder_order';
	const META_ROLES = 'admbud_folder_roles'; // array of role slugs allowed to see the folder (empty = everyone).
	const SIZE_CACHE = 'admbud_mm_sizes'; // transient: { terms:{id:bytes}, total, uncat }.

	/**
	 * Build the full nested folder tree.
	 *
	 * @param int $parent Parent term ID to build from (0 = roots).
	 * @return array<int,array> Ordered list of nodes, each with a `children` array.
	 */
	public static function build( int $parent = 0 ): array {
		$terms = self::all_terms();
		return self::nest( $terms, $parent );
	}

	/**
	 * Build a flat, depth-prefixed list suitable for a <select> / dropdown.
	 * Each row: [ id, name, depth, count ]. Children follow their parent.
	 *
	 * @return array<int,array>
	 */
	public static function flat(): array {
		$tree = self::build( 0 );
		$out  = [];
		$walk = static function ( array $nodes, int $depth ) use ( &$walk, &$out ): void {
			foreach ( $nodes as $node ) {
				$out[] = [
					'id'    => $node['id'],
					'name'  => $node['name'],
					'depth' => $depth,
					'count' => $node['count'],
				];
				if ( ! empty( $node['children'] ) ) {
					$walk( $node['children'], $depth + 1 );
				}
			}
		};
		$walk( $tree, 0 );
		return $out;
	}

	/**
	 * Fetch every folder term once, with colour + order meta primed.
	 *
	 * @return array<int,array> Map of term_id => flat node (no children yet).
	 */
	private static function all_terms(): array {
		$terms = get_terms( [
			'taxonomy'               => self::TAXONOMY,
			'hide_empty'             => false,
			'update_term_meta_cache' => true, // single primed fetch - no N+1 on meta below.
		] );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return [];
		}

		// Folder counts must reflect what's VISIBLE in the folder (post_status=
		// inherit), not the stored wp_term_taxonomy.count - which uses WP's
		// _update_generic_term_count callback and includes trashed files (they
		// keep their term relationship so Restore can put them back in the same
		// folder). Without this override, a trashed file shows in the folder's
		// count but is filtered out of the grid (post_status=inherit), leaving
		// the user staring at "1" next to an empty folder. One GROUP BY query
		// for every folder at once - no N+1.
		$inherit_counts = self::inherit_counts_by_term();
		$sizes          = []; // Folder sizes are Pro (see sizes()); Free default = empty (renders no size).

		$nodes = [];
		foreach ( $terms as $term ) {
			$color = get_term_meta( $term->term_id, self::META_COLOR, true );
			$order = get_term_meta( $term->term_id, self::META_ORDER, true );
			$nodes[ $term->term_id ] = [
				'id'       => (int) $term->term_id,
				'name'     => $term->name,
				'slug'     => $term->slug,
				'parent'   => (int) $term->parent,
				'color'    => is_string( $color ) ? $color : '',
				'order'    => '' === $order ? 0 : (int) $order,
				'count'    => $inherit_counts[ (int) $term->term_id ] ?? 0,
				'size'     => $sizes[ (int) $term->term_id ] ?? 0,
				'children' => [],
			];
		}
		return $nodes;
	}


	/**
	 * One SQL query that returns the inherit-only attachment count per folder
	 * term. Excludes trashed (and any other non-inherit) attachments so the tree
	 * count matches what `post_status=inherit` queries actually return.
	 *
	 * @return array<int,int> term_id => count
	 */
	private static function inherit_counts_by_term(): array {
		global $wpdb;
		// One GROUP BY per tree render (request-scoped, not in a loop). No core API
		// returns visible-only per-term attachment counts, so a direct query is needed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- request-scoped aggregate, see note above.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT tt.term_id, COUNT(*) AS cnt
			 FROM {$wpdb->term_relationships} tr
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			 INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
			 WHERE tt.taxonomy = %s
			 AND p.post_type = 'attachment'
			 AND p.post_status = 'inherit'
			 GROUP BY tt.term_id",
			self::TAXONOMY
		) );
		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->term_id ] = (int) $r->cnt;
		}
		return $out;
	}

	/**
	 * Recursively nest a flat node map under the given parent, ordered by the
	 * `order` meta then name.
	 *
	 * @param array<int,array> $nodes  Flat node map keyed by term ID.
	 * @param int              $parent Parent term ID.
	 * @return array<int,array>
	 */
	private static function nest( array $nodes, int $parent ): array {
		$branch = [];
		foreach ( $nodes as $id => $node ) {
			if ( $node['parent'] === $parent ) {
				$node['children'] = self::nest( $nodes, $id );
				$branch[]         = $node;
			}
		}

		usort( $branch, static function ( $a, $b ) {
			if ( $a['order'] === $b['order'] ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
			return $a['order'] <=> $b['order'];
		} );

		return $branch;
	}
}

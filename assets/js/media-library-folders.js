/**
 * Admin Buddy - Media Manager native-library injection.
 *
 * Surfaces:
 *   1. Page-level folder panel on upload.php (PHP prints the shell; this script
 *      fills the tree). Works in list AND grid view.
 *   2. An in-frame sidebar inside the wp.media insert MODAL only.
 *
 * Filtering:
 *   - Grid / modal: wrap wp.media.model.Query.prototype.sync to inject our
 *     taxonomy key into the query-attachments request, then force a NON-cached
 *     refetch (_requery(true)) - wp.media caches by known props only, so a plain
 *     prop change returns the stale "all" query.
 *   - List mode: navigate to upload.php?admbud_media_folder=ID (server filters).
 *
 * @global admbudMM
 */
( function () {
	'use strict';

	if ( typeof admbudMM === 'undefined' ) { return; }

	var cfg  = admbudMM;
	var i18n = cfg.i18n || {};
	// Per-user tool access (admins all-true). Gates context-menu items + inline
	// folder controls so users never see actions their role can't perform.
	function tool( k ) { return !! ( cfg.tools && cfg.tools[ k ] ); }
	// wp_localize_script string-casts top-level scalars, so a PHP boolean arrives
	// here as '1' (true) or '' (false), NOT a real boolean. Test those values,
	// never `!== false` (always true for a string => the tree always expanded).
	var defaultExpanded = ( cfg.defaultExpanded === true || cfg.defaultExpanded === '1' || cfg.defaultExpanded === 1 );
	var AB   = { folder: '__all__', tree: cfg.tree || [], virtual: cfg.virtual || { all: 0, uncategorized: 0 }, expanded: defaultExpanded, search: '', treeFilter: '', sortBy: 'date', sortOrder: 'DESC' };
	var lastBrowser = null;

	// Top stacking layer for our transient popups (context menu, colour popover,
	// confirm modal, drag ghost). The WP media modal is z-index:160000, but page
	// builders (Bricks, etc.) bump .media-frame to an inline z-index:999999999 to
	// force the modal above their own UI - so a value just above 160000 is no
	// longer enough. Max 32-bit int beats any plausible inline value.
	var Z_TOP = 2147483647;

	try {
		var qp = new URLSearchParams( window.location.search ).get( cfg.taxKey );
		if ( qp ) { AB.folder = qp; }
	} catch ( e ) {}

	// -- Forward active folder into query-attachments -------------------------
	if ( window.wp && wp.media && wp.media.model && wp.media.model.Query ) {
		var _sync = wp.media.model.Query.prototype.sync;
		wp.media.model.Query.prototype.sync = function ( method, model, options ) {
			if ( 'read' === method && this.args ) {
				if ( AB.search ) {
					// An active search spans every folder; drop the folder constraint.
					if ( this.args[ cfg.taxKey ] ) { delete this.args[ cfg.taxKey ]; }
				} else if ( AB.folder && '__all__' !== AB.folder ) {
					this.args[ cfg.taxKey ] = AB.folder;
				} else if ( this.args[ cfg.taxKey ] ) {
					delete this.args[ cfg.taxKey ];
				}
			}
			return _sync.apply( this, arguments );
		};
	}

	// -- Upload-to-folder -----------------------------------------------------
	// Tag each upload with the active folder so the server (add_attachment) files
	// it there instead of leaving it Uncategorized. Covers the grid, the modal,
	// and the Add-New uploaders - all wp.Uploader/Plupload based. The prototype
	// patch runs at script-eval, before any Uploader is instantiated (which
	// happens on DOM-ready / when the media frame opens), so every instance gets it.
	if ( window.wp && wp.Uploader && wp.Uploader.prototype ) {
		var _uploaderInit = wp.Uploader.prototype.init;
		wp.Uploader.prototype.init = function () {
			if ( _uploaderInit ) { _uploaderInit.apply( this, arguments ); }
			var up = this.uploader;
			if ( ! up || typeof up.bind !== 'function' ) { return; }
			// Set the param fresh on each upload so a mid-session folder switch is honoured.
			up.bind( 'BeforeUpload', function ( uploader ) {
				uploader.settings.multipart_params = uploader.settings.multipart_params || {};
				if ( AB.folder && /^\d+$/.test( String( AB.folder ) ) ) {
					uploader.settings.multipart_params.admbud_mm_upload_folder = AB.folder;
				} else {
					delete uploader.settings.multipart_params.admbud_mm_upload_folder;
				}
			} );
			// Refresh folder counts once the upload + server-side assign lands.
			up.bind( 'FileUploaded', function () { refreshCounts(); } );
		};
	}

	// -- Helpers --------------------------------------------------------------
	function ajax( action, data ) {
		var fd = new FormData();
		fd.append( 'action', action );
		fd.append( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( k ) {
			var v = data[ k ];
			if ( Array.isArray( v ) ) { v.forEach( function ( i ) { fd.append( k + '[]', i ); } ); }
			else { fd.append( k, v ); }
		} );
		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd } ).then( function ( r ) { return r.json(); } );
	}
	function esc( s ) { var d = document.createElement( 'div' ); d.textContent = s == null ? '' : String( s ); return d.innerHTML; }
	// The actual DOM toast. Kept separate from notify()/the window.showToast shim so
	// the two never delegate into each other (that mutual delegation was an infinite
	// recursion -> "Maximum call stack size exceeded" the first time notify() ran on
	// upload.php, where admin.js's showToast is absent so our shim is installed).
	function renderToast( msg, isError ) {
		var t = document.createElement( 'div' );
		t.className = 'admbud-mm-toast' + ( isError ? ' admbud-mm-toast--error' : '' );
		t.setAttribute( 'role', 'status' );
		t.textContent = msg;
		document.body.appendChild( t );
		requestAnimationFrame( function () { t.classList.add( 'is-in' ); } );
		setTimeout( function () {
			t.classList.remove( 'is-in' );
			setTimeout( function () { if ( t.parentNode ) { t.parentNode.removeChild( t ); } }, 250 );
		}, isError ? 5000 : 2800 );
	}
	// True once we've installed our OWN window.showToast shim - so notify() knows the
	// global is ours (delegate straight to renderToast) versus an EXTERNAL one from
	// admin.js / Ecom Buddy (delegate to it).
	var abOwnsShowToast = false;
	function notify( msg, isError ) {
		if ( typeof window.showToast === 'function' && ! abOwnsShowToast ) {
			window.showToast( msg, isError ? 'error' : 'success' );
			return;
		}
		renderToast( msg, isError );
	}
	// Expose our toast as window.showToast when admin.js's isn't present (it's not
	// loaded on upload.php / front-end builders). This lets the shared
	// tab-media-manager.js engine (Bulk SEO, folder-settings auto-save) surface
	// feedback here too - its toast() only knows about window.showToast. The shim
	// calls renderToast directly (NOT notify) so there's no delegation loop.
	if ( typeof window.showToast !== 'function' ) {
		window.showToast = function ( msg, type ) { renderToast( msg, type === 'error' ); };
		abOwnsShowToast = true;
	}
	function renderAllTrees() { document.querySelectorAll( '.admbud-mm-tree' ).forEach( renderTree ); }

	var FOLDER_SVG       = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>';
	var FOLDER_SVG_SOLID = '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>';
	// All Media = "stacked folders" (everything); Uncategorized = "open folder"
	// (loose, unsorted); Trash = trash-can. All stay in a consistent visual family.
	var ALL_SVG    = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round"><path d="M20 17a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3.9a2 2 0 0 1-1.69-.9l-.81-1.2a2 2 0 0 0-1.67-.9H8a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2Z"/><path d="M2 8v11a2 2 0 0 0 2 2h14"/></svg>';
	var UNCAT_SVG  = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round"><path d="m6 14 1.45-2.9A2 2 0 0 1 9.24 10H20a2 2 0 0 1 1.94 2.5l-1.55 6a2 2 0 0 1-1.94 1.5H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3.93a2 2 0 0 1 1.66.9l.82 1.2a2 2 0 0 0 1.66.9H18a2 2 0 0 1 2 2v2"/></svg>';
	var TRASH_SVG  = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/></svg>';
	var CHEVRON_SVG = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>';

	// Row / context-menu action icons. No width/height attrs — sized by CSS so the
	// hover buttons are square and the menu items align. All use currentColor so
	// they inherit the row / menu / danger text colour and theme with the scheme.
	var ACT_NEWFOLDER_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><line x1="12" y1="11" x2="12" y2="16"/><line x1="9.5" y1="13.5" x2="14.5" y2="13.5"/></svg>';
	var ACT_RENAME_SVG    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';
	var ACT_COLOUR_SVG    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3.69 17.66 9.35a8 8 0 1 1-11.31 0z"/></svg>';
	var ACT_DOWNLOAD_SVG  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';
	var ACT_DELETE_SVG    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/></svg>';
	var ACT_SHORTCODE_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>';
	var ACT_ID_SVG        = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="9" x2="20" y2="9"/><line x1="4" y1="15" x2="20" y2="15"/><line x1="10" y1="3" x2="8" y2="21"/><line x1="16" y1="3" x2="14" y2="21"/></svg>';
	var ACT_VISIBILITY_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>';
	var PALETTE = [ '#50575e', '#2271b1', '#3858e9', '#00a32a', '#dba617', '#d63638', '#9b51e0', '#1d9bd1' ];

	function isGrid() { return !! ( lastBrowser || document.querySelector( '.media-frame' ) ); }

	function requeryGrid() {
		if ( lastBrowser && lastBrowser.collection ) {
			var c = lastBrowser.collection;
			if ( typeof c._requery === 'function' ) { c._requery( true ); return; } // true = bypass cache
			if ( c.props ) { c.props.set( 'admbudFolder', AB.folder + ':' + Date.now() ); }
		}
	}

	// -- Tree rendering -------------------------------------------------------
	function renderTree( ulEl ) {
		ulEl.innerHTML = '';
		ulEl.removeAttribute( 'aria-busy' );
		ulEl.appendChild( virtualRow( '__all__', i18n.all || 'All Media', AB.virtual.all, AB.virtual.allSize ) );
		ulEl.appendChild( virtualRow( '__uncat__', i18n.uncategorized || 'Uncategorized', AB.virtual.uncategorized, AB.virtual.uncatSize ) );
		// Trash pseudo-folder: only when the MEDIA_TRASH self-define is in effect
		// (toggle on the MM tab). Always render when enabled - empty Trash is still
		// a real destination users can drag onto, so a 0-count row is correct.
		if ( cfg.trashEnabled ) {
			ulEl.appendChild( virtualRow( '__trash__', i18n.trash || 'Trash', AB.virtual.trash || 0 ) );
		}
		AB.tree.forEach( function ( node ) { var li = treeRow( node ); if ( li ) { ulEl.appendChild( li ); } } );
		updateBreadcrumb();
	}

	// Folder-tree filter (driven by the search box). A node is shown if it OR any
	// descendant matches; self-matches are highlighted.
	function nodeMatches( node, term ) { return ( node.name || '' ).toLowerCase().indexOf( term ) !== -1; }
	function subtreeMatches( node, term ) {
		if ( nodeMatches( node, term ) ) { return true; }
		return !! ( node.children && node.children.some( function ( c ) { return subtreeMatches( c, term ); } ) );
	}

	// Human-readable byte size (1 decimal place under 10 of a unit, else rounded).
	function humanSize( b ) {
		b = parseInt( b, 10 ) || 0;
		if ( b <= 0 ) { return ''; }
		var u = [ 'B', 'KB', 'MB', 'GB', 'TB' ], i = 0, n = b;
		while ( n >= 1024 && i < u.length - 1 ) { n /= 1024; i++; }
		return ( i === 0 ? n : ( n >= 10 ? Math.round( n ) : Math.round( n * 10 ) / 10 ) ) + ' ' + u[ i ];
	}

	function rowBase( id, name, count, color, isVirtual, size ) {
		var row = document.createElement( 'div' );
		row.className = 'admbud-mm-row';
		row.dataset.id = id;
		if ( String( AB.folder ) === String( id ) ) { row.classList.add( 'is-active' ); }
		// Folder icon: pseudo-folders get their own folder-family glyph; real
		// folders are outline + neutral by default, SOLID + tinted when coloured.
		var virtualIcon = ALL_SVG;
		if ( id === '__uncat__' ) { virtualIcon = UNCAT_SVG; }
		else if ( id === '__trash__' ) { virtualIcon = TRASH_SVG; }
		var iconHtml = isVirtual
			? '<span class="admbud-mm-row__icon">' + virtualIcon + '</span>'
			: '<span class="admbud-mm-row__icon"' + ( color ? ' style="color:' + esc( color ) + '"' : '' ) + '>' + ( color ? FOLDER_SVG_SOLID : FOLDER_SVG ) + '</span>';
		row.innerHTML =
			'<span class="admbud-mm-row__toggle admbud-mm-row__toggle--leaf"></span>' +
			iconHtml +
			'<span class="admbud-mm-row__label">' + esc( name ) + '</span>' +
			( cfg.showSize && size ? '<span class="admbud-mm-row__size">' + esc( humanSize( size ) ) + '</span>' : '' ) +
			'<span class="admbud-mm-row__count">' + count + '</span>';
		return row;
	}

	function virtualRow( id, name, count, size ) {
		var li = document.createElement( 'li' );
		var row = rowBase( id, name, count, '', true, size );
		row.addEventListener( 'click', function () { selectFolder( id ); } );
		// Row actions live in the right-click menu only (see showMenu) - keeps the
		// label readable and avoids the hover layout shift.
		row.addEventListener( 'contextmenu', function ( e ) { e.preventDefault(); showMenu( e.clientX, e.clientY, null, { id: id, count: count } ); } );
		li.appendChild( row );
		return li;
	}

	function treeRow( node ) {
		var term = AB.treeFilter;
		if ( term && ! subtreeMatches( node, term ) ) { return null; }
		var li = document.createElement( 'li' );
		// Recursive view: a folder's badge shows its files + all descendants' (must
		// match the recursive grid, server-side). Otherwise the direct term count.
		var rowCount = cfg.recursiveView ? subtreeCount( node ) : node.count;
		var rowSize  = cfg.recursiveView ? subtreeSize( node ) : node.size;
		var row = rowBase( node.id, node.name, rowCount, node.color, false, rowSize );
		if ( term && nodeMatches( node, term ) ) { row.classList.add( 'admbud-mm-row--match' ); }
		// Access-control indicator: an eye on folders whose visibility is restricted
		// to specific roles. Admin-only (only they manage/need it); tooltip lists the
		// allowed roles. node.roles is set server-side from the folder_roles meta.
		if ( cfg.canManage && node.roles && node.roles.length ) {
			var acc = document.createElement( 'span' );
			acc.className = 'admbud-mm-row__access';
			acc.innerHTML = ACT_VISIBILITY_SVG;
			acc.title = ( i18n.visibleTo || 'Visible to' ) + ': ' + node.roles.map( function ( s ) {
				return ( cfg.roles && cfg.roles[ s ] ) || s;
			} ).join( ', ' );
			var countEl = row.querySelector( '.admbud-mm-row__count' );
			if ( countEl ) { row.insertBefore( acc, countEl ); } else { row.appendChild( acc ); }
		}
		var toggle = row.querySelector( '.admbud-mm-row__toggle' );
		var hasKids = node.children && node.children.length;
		if ( hasKids ) { toggle.classList.remove( 'admbud-mm-row__toggle--leaf' ); toggle.innerHTML = CHEVRON_SVG; }

		// Folder actions (rename, colour, download, delete) live in the right-click
		// menu only - see showMenu. No hover icons: they crowded nested labels and
		// caused a row-height jump on hover.
		row.addEventListener( 'click', function ( e ) {
			if ( e.target === toggle && hasKids ) { return; }
			selectFolder( String( node.id ) );
		} );
		row.addEventListener( 'contextmenu', function ( e ) { e.preventDefault(); showMenu( e.clientX, e.clientY, node ); } );
		li.appendChild( row );

		if ( hasKids ) {
			var ul = document.createElement( 'ul' );
			node.children.forEach( function ( child ) { var c = treeRow( child ); if ( c ) { ul.appendChild( c ); } } );
			li.appendChild( ul );
			// Force-expand while filtering so matched descendants are visible.
			if ( term || AB.expanded ) { toggle.classList.add( 'is-open' ); }
			else { ul.style.display = 'none'; }
			toggle.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				var open = ul.style.display !== 'none';
				ul.style.display = open ? 'none' : '';
				toggle.classList.toggle( 'is-open', ! open );
			} );
		}
		return li;
	}

	// -- Selection / filtering ------------------------------------------------
	function selectFolder( id ) {
		AB.folder = id;
		// If a search was active, clicking a folder means "show me this folder" - so
		// clear the search (otherwise the sync-wrap keeps spanning all folders and the
		// folder's own files never load).
		if ( AB.search ) {
			AB.search = '';
			AB.treeFilter = '';
			var _sb = document.getElementById( 'admbud-mm-search' );
			if ( _sb ) { _sb.value = ''; }
			var _sc = document.getElementById( 'admbud-mm-search-clear' );
			if ( _sc ) { _sc.hidden = true; }
			if ( lastBrowser && lastBrowser.collection && lastBrowser.collection.props ) {
				lastBrowser.collection.props.set( 'search', '', { silent: true } );
			}
			renderAllTrees(); // restore the full, unfiltered tree
		}
		setActive( id );
		clearPicks(); // a folder switch rebuilds the grid; drop the stale pick-set.
		updateGridCardActions(); // swap the per-card overlay buttons for this view.
		if ( isGrid() ) {
			requeryGrid();
			// Persist the active folder in the URL so F5 re-enters the same
			// folder instead of falling back to All Media. The script-eval `qp`
			// init at the top of this IIFE reads it back; the Query.sync wrap
			// then injects it into the first wp.media request. replaceState
			// (not pushState) so the back button still leaves upload.php cleanly
			// instead of getting stuck cycling through folder selections.
			try {
				var gurl = new URL( window.location.href );
				if ( id === '__all__' ) { gurl.searchParams.delete( cfg.taxKey ); }
				else { gurl.searchParams.set( cfg.taxKey, id ); }
				window.history.replaceState( {}, '', gurl.toString() );
			} catch ( e ) {}
		} else {
			var url = new URL( window.location.href );
			url.searchParams.set( 'mode', 'list' );
			if ( id === '__all__' ) { url.searchParams.delete( cfg.taxKey ); }
			else { url.searchParams.set( cfg.taxKey, id ); }
			window.location.href = url.toString();
		}
	}
	function setActive( id ) {
		document.querySelectorAll( '.admbud-mm-row.is-active' ).forEach( function ( r ) { r.classList.remove( 'is-active' ); } );
		document.querySelectorAll( '.admbud-mm-row[data-id="' + String( id ).replace( /"/g, '\\"' ) + '"]' ).forEach( function ( r ) { r.classList.add( 'is-active' ); } );
		updateBreadcrumb();
	}

	// -- Move (assign) --------------------------------------------------------
	function assignTo( target, ids, isList ) {
		ids = ( ids || [] ).map( String ).filter( Boolean );
		if ( ! ids.length ) { return; }
		// Drop on the Trash pseudo-folder => move to Trash instead of re-assigning.
		// Different endpoint (caps + wp_trash_post) and different toast wording.
		var isTrash = ( target === '__trash__' );
		var fromTrash = ( AB.folder === '__trash__' ); // source = Trash view
		var action  = isTrash ? 'admbud_mm_trash' : 'admbud_mm_assign';
		var payload = isTrash ? { ids: ids } : { target: target, ids: ids };
		ajax( action, payload ).then( function ( r ) {
			if ( r.success ) {
				clearMediaSelection(); // drop the wp.media selection so moved files
				                       // don't show as still-selected in the folder.
				clearPicks();          // and our own Ctrl/Cmd-click pick-set.
				afterChange( r.data );
				if ( isTrash ) {
					notify( i18n.trashed || ( ids.length + ' file(s) moved to Trash.' ) );
				} else if ( fromTrash || ( r.data && r.data.restored ) ) {
					// Server auto-untrashed the file(s) on assign-out-of-trash;
					// label the toast accordingly so the user sees the restore happen.
					notify( i18n.restoredAndMoved || ( ids.length + ' file(s) restored and moved.' ) );
				} else {
					notify( ids.length + ( ids.length === 1 ? ' file moved.' : ' files moved.' ) );
				}
				// The list table is server-rendered (no live requery), so reload to
				// reflect the move within the current folder filter.
				if ( isList ) { setTimeout( function () { window.location.reload(); }, 650 ); }
			} else { notify( ( r.data && r.data.message ) || i18n.failed, true ); }
		} );
	}

	// -- Restore (explicit, no folder change) ---------------------------------
	function restoreFiles( ids ) {
		ids = ( ids || [] ).map( String ).filter( Boolean );
		if ( ! ids.length ) { return; }
		ajax( 'admbud_mm_restore', { ids: ids } ).then( function ( r ) {
			if ( r.success ) {
				clearMediaSelection();
				clearPicks();
				// afterChange() consumes both tree + virtual so real-folder counts
				// update too (they're inherit-only, so a restore can change them).
				afterChange( r.data );
				notify( i18n.restored || 'Restored.' );
			} else {
				notify( ( r.data && r.data.message ) || i18n.failed, true );
			}
		} );
	}

	// -- Grid card overlay ----------------------------------------------------
	// Hover-revealed action buttons on every grid card. Button mix depends on
	// context:
	//   - Trash view             -> Delete (perma, confirm) + Restore
	//   - Non-trash + trash on   -> Trash (move to trash, no confirm: recoverable)
	//   - Non-trash + trash off  -> Delete (perma, confirm)
	// Injected via Mutation Observer because wp.media renders the grid
	// incrementally (initial load / requery / scroll). WP's own attachment-
	// details modal has the same actions, but two clicks away and easy to miss.
	var trashOverlayObs = null;
	var TRASH_VIEW_CLASS = 'admbud-mm-trashview';
	var RESTORE_ICON_SVG = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 7 8 7 8 2"/><path d="M3.05 11A9 9 0 1 1 5.7 16.5"/></svg>';
	var DELETE_ICON_SVG  = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>';
	var TRASH_ACTION_SVG = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/></svg>';
	var REPLACE_ICON_SVG = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>';

	// Media Replace (Mode A: same URL). Opens a file picker, uploads to the
	// replace endpoint (server enforces same-MIME), then refreshes the thumbnail
	// in place (the new URL carries ?v= via the server-side cache-buster).
	function replaceFile( id ) {
		var input = document.createElement( 'input' );
		input.type = 'file';
		input.style.display = 'none';
		document.body.appendChild( input );
		input.addEventListener( 'change', function () {
			var file = input.files && input.files[0];
			if ( input.parentNode ) { document.body.removeChild( input ); }
			if ( ! file ) { return; }
			replaceChoiceModal( file.name, function ( mode ) { doReplace( id, file, mode ); } );
		} );
		input.click();
	}
	function doReplace( id, file, mode ) {
		notify( i18n.replacing || 'Replacing…' );
		var data = { id: id, file: file };
		if ( 'rename' === mode ) { data.mode = 'rename'; }
		ajax( 'admbud_mm_replace', data ).then( function ( r ) {
			if ( r && r.success ) {
				refreshAfterReplace( id, r.data && r.data.thumb );
				if ( r.data && r.data.renamed ) {
					var n = r.data.relinked || 0;
					notify( ( i18n.replacedRenamed || 'File replaced and renamed.' ) + ' ' + ( n ? ( n + ' ' + ( i18n.relinked || 'reference(s) updated.' ) ) : ( i18n.noRefs || 'No references needed updating.' ) ) );
				} else {
					notify( ( ( i18n.replaced || 'File replaced.' ) + ' ' + ( i18n.replaceHint || '' ) ).trim() );
				}
			} else {
				notify( ( r && r.data && r.data.message ) || i18n.failed, true );
			}
		} ).catch( function () { notify( i18n.failed, true ); } );
	}
	function refreshAfterReplace( id, thumbUrl ) {
		// (a) The currently-open details view won't re-render from a model change,
		// so force its large image to refetch. Mode A keeps the SAME URL (only the
		// content changed), so re-stamping a fresh ?v= on the image's own src pulls
		// the new file at full size (no tiny-thumbnail substitution).
		document.querySelectorAll( '.details-image, .media-sidebar .thumbnail img' ).forEach( function ( img ) {
			var base = ( img.getAttribute( 'src' ) || '' ).split( '?' )[0];
			if ( base ) { img.setAttribute( 'src', base + '?v=' + Date.now() ); }
		} );
		// (b) Re-fetch the wp.media model so grid cards re-render and any later
		// reopen of the details is fresh (the new sizes carry ?v= server-side).
		try {
			if ( window.wp && wp.media && wp.media.attachment ) {
				var att = wp.media.attachment( id );
				if ( att && att.fetch ) { att.fetch(); return; }
			}
		} catch ( e ) {}
		// (c) Fallback when wp.media isn't present: bump grid thumbnails directly.
		if ( thumbUrl ) {
			document.querySelectorAll( '.attachments .attachment' ).forEach( function ( card ) {
				if ( String( attId( card ) ) !== String( id ) ) { return; }
				card.querySelectorAll( 'img' ).forEach( function ( img ) { img.src = thumbUrl; } );
			} );
		}
	}

	function buildCardBtn( modifier, iconSvg, labelText, onClick ) {
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'admbud-mm-card-btn admbud-mm-card-btn--' + modifier;
		btn.title = labelText;           // hover tooltip - replaces the visible label.
		btn.setAttribute( 'aria-label', labelText );
		btn.innerHTML = iconSvg;         // icon-only square button (label lives in the tooltip).
		// Stop mousedown so the drag handler never arms on a button click.
		btn.addEventListener( 'mousedown', function ( e ) { e.stopPropagation(); } );
		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			e.stopPropagation(); // don't open attachment-details
			onClick();
		} );
		return btn;
	}

	function confirmPermaDelete( id ) {
		confirmModal(
			i18n.deletePermanent || 'Delete',
			i18n.confirmDeletePermanent || 'Permanently delete this file? This cannot be undone.',
			function () { forceDeleteFiles( [ id ] ); },
			true
		);
	}

	function addCardActions( card ) {
		if ( ! card || ! card.classList || card.querySelector( '.admbud-mm-card-actions' ) ) { return; }
		var id = attId( card );
		if ( ! id ) { return; }
		var wrap = document.createElement( 'div' );
		wrap.className = 'admbud-mm-card-actions';

		// Same overlay everywhere (Library grid AND the insert modal) for a
		// consistent UI. The destructive control is Trash (recoverable) when the
		// Trash feature is on, otherwise a confirm-gated permanent Delete - so an
		// accidental click mid-insert is recoverable or guarded either way.
		var inTrash = ( AB.folder === '__trash__' );

		if ( inTrash ) {
			// Delete sits LEFT of Restore - dominant action at the corner, the
			// destructive button slightly inset so it's harder to misclick.
			wrap.appendChild( buildCardBtn( 'delete', DELETE_ICON_SVG, i18n.deletePermanent || 'Delete', function () { confirmPermaDelete( id ); } ) );
			wrap.appendChild( buildCardBtn( 'restore', RESTORE_ICON_SVG, i18n.restore || 'Restore', function () { restoreFiles( [ id ] ); } ) );
		} else {
			// Non-trash views: Replace (swap the file, keep the URL - Pro) + the
			// delete/trash control. (Where used lives in the attachment details
			// sidebar - see MediaManager::add_where_used_field + media-where-used.js.)
			if ( cfg.trashEnabled ) {
				// Recoverable: route through the existing Trash drag path so toast/
				// counts/flush all match the drag flow.
				wrap.appendChild( buildCardBtn( 'trash', TRASH_ACTION_SVG, i18n.trash || 'Trash', function () { assignTo( '__trash__', [ id ] ); } ) );
			} else {
				// Trash feature off => permanent delete is the only option. Confirm
				// every time because there's no Trash safety net.
				wrap.appendChild( buildCardBtn( 'delete', DELETE_ICON_SVG, i18n.deletePermanent || 'Delete', function () { confirmPermaDelete( id ); } ) );
			}
		}
		card.appendChild( wrap );
	}

	function injectOverlaysNow() {
		document.querySelectorAll( '.attachments .attachment' ).forEach( addCardActions );
	}

	function forceDeleteFiles( ids ) {
		ids = ( ids || [] ).map( String ).filter( Boolean );
		if ( ! ids.length ) { return; }
		ajax( 'admbud_mm_force_delete', { ids: ids } ).then( function ( r ) {
			if ( r.success ) {
				clearMediaSelection();
				clearPicks();
				afterChange( r.data ); // refresh real-folder counts + virtual + grid
				notify( i18n.deleted || 'File deleted.' );
			} else {
				notify( ( r.data && r.data.message ) || i18n.failed, true );
			}
		} );
	}

	function updateGridCardActions() {
		// Body class is still useful for any Trash-only styling we may add later.
		document.body.classList.toggle( TRASH_VIEW_CLASS, AB.folder === '__trash__' );
		// Always rebuild: the button mix depends on (inTrash, trashEnabled), so a
		// folder switch from a regular folder to Trash (or vice versa) must swap
		// the buttons on every card. Cheaper than diffing per-card.
		if ( trashOverlayObs ) { trashOverlayObs.disconnect(); trashOverlayObs = null; }
		document.querySelectorAll( '.admbud-mm-card-actions' ).forEach( function ( el ) {
			if ( el.parentNode ) { el.parentNode.removeChild( el ); }
		} );
		// List view is server-rendered with its own row actions - no overlay needed.
		if ( ! isGrid() ) { return; }
		injectOverlaysNow();
		var grid = document.querySelector( '.attachments' );
		if ( ! grid || typeof MutationObserver !== 'function' ) { return; }
		trashOverlayObs = new MutationObserver( function ( muts ) {
			muts.forEach( function ( m ) {
				m.addedNodes && m.addedNodes.forEach( function ( n ) {
					if ( n.nodeType !== 1 ) { return; }
					if ( n.classList && n.classList.contains( 'attachment' ) ) { addCardActions( n ); }
					else if ( n.querySelectorAll ) { n.querySelectorAll( '.attachment' ).forEach( addCardActions ); }
				} );
			} );
		} );
		trashOverlayObs.observe( grid, { childList: true, subtree: true } );
	}

	// The first grid mount can happen AFTER initPanel()'s boot-time call (wp.media
	// mounts the grid asynchronously), so that call found no grid/cards and set up
	// nothing - which is why overlays were missing on the initial All Media view
	// until a folder switch. Re-ensure overlays whenever the browser (re)renders.
	var ensureTimer = null;
	function ensureGridOverlays() {
		clearTimeout( ensureTimer );
		ensureTimer = setTimeout( function () {
			if ( ! isGrid() ) { return; }
			if ( ! trashOverlayObs ) { updateGridCardActions(); } // sets up the observer + injects
			else { injectOverlaysNow(); }                          // observer live; just catch missed cards
			setupBulkToolbarObserver(); // adds Download beside native Bulk-select Delete
			ensureBulkDownloadBtn();
			// Apply the persisted sort once, after the grid's first query has mounted.
			if ( ! sortInitDone && lastBrowser ) {
				sortInitDone = true;
				if ( AB.sortBy !== 'date' || AB.sortOrder !== 'DESC' ) { applySort( false ); }
			}
		}, 120 );
	}

	// -- Empty Trash ----------------------------------------------------------
	// Type-to-confirm style overkill for v1; standard danger-confirm is enough.
	// (The "Type ERASE" pattern lives in admin.js, which isn't loaded on upload.php.)
	function emptyTrash( total ) {
		var msg = ( i18n.confirmEmptyTrash || 'Permanently delete all %d items in Trash? This cannot be undone.' ).replace( '%d', total );
		confirmModal( i18n.emptyTrash || 'Empty Trash', msg, function () {
			ajax( 'admbud_mm_empty_trash', {} ).then( function ( r ) {
				if ( r.success ) {
					// Same flow as restore/force-delete: consume tree + virtual.
					if ( r.data && r.data.tree ) { AB.tree = r.data.tree; }
					if ( r.data && r.data.virtual ) { AB.virtual = r.data.virtual; }
					renderAllTrees();
					notify( i18n.emptied || 'Trash emptied.' );
					// If the user was viewing the Trash pseudo-folder, the grid now
					// shows stale items - flip back to All Media so the requery is
					// against a non-empty post_status.
					if ( AB.folder === '__trash__' ) {
						selectFolder( '__all__' );
					} else {
						requeryGrid();
					}
				} else {
					notify( ( r.data && r.data.message ) || i18n.failed, true );
				}
			} );
		}, true );
	}
	// Restore every trashed file back to its folder. Non-destructive, so no confirm
	// modal - just run it and toast. Mirrors emptyTrash's refresh flow.
	function restoreAll() {
		ajax( 'admbud_mm_restore_all', {} ).then( function ( r ) {
			if ( r.success ) {
				if ( r.data && r.data.tree ) { AB.tree = r.data.tree; }
				if ( r.data && r.data.virtual ) { AB.virtual = r.data.virtual; }
				renderAllTrees();
				notify( i18n.restored || 'Restored.' );
				if ( AB.folder === '__trash__' ) { selectFolder( '__all__' ); }
				else { requeryGrid(); }
			} else {
				notify( ( r.data && r.data.message ) || i18n.failed, true );
			}
		} );
	}
	function mediaSelection() {
		try {
			if ( lastBrowser && lastBrowser.controller && lastBrowser.controller.state ) {
				return lastBrowser.controller.state().get( 'selection' ) || null;
			}
		} catch ( err ) {}
		return null;
	}
	function clearMediaSelection() {
		var sel = mediaSelection();
		if ( sel && typeof sel.reset === 'function' ) { sel.reset(); }
	}
	function selectedAttachmentIds() {
		var ids = [];
		try {
			var sel = mediaSelection();
			if ( sel ) { sel.each( function ( m ) { ids.push( m.id ); } ); }
		} catch ( err ) {}
		return ids;
	}

	// -- Pointer-based drag (NOT native HTML5 drag) ---------------------------
	// Native draggable thumbnails trigger WP's "Drop files to upload" dropzone,
	// so we implement dragging with mouse events + a custom ghost instead. This
	// also gives us multi-select drag (drags the whole selection if the grabbed
	// item is part of it).
	var dragState = null, dropHover = null;
	function attId( att ) {
		var nested = att.querySelector( '[data-id]' );
		return att.getAttribute( 'data-id' ) || ( nested && nested.getAttribute( 'data-id' ) ) || ( ( att.id || '' ).match( /\d+/ ) || [] )[0] || '';
	}
	function folderRowAt( x, y ) {
		var el = document.elementFromPoint( x, y );
		var row = el && el.closest ? el.closest( '.admbud-mm-row[data-id]' ) : null;
		if ( ! row || row.dataset.id === '__all__' ) { return null; } // can't drop on All Media
		return row;
	}
	function setDropHover( row ) {
		if ( dropHover && dropHover !== row ) { dropHover.classList.remove( 'is-droptarget' ); }
		if ( row ) { row.classList.add( 'is-droptarget' ); }
		dropHover = row;
	}
	// A draggable FILE source: a grid .attachment, or a list-table row.
	function grabbableFile( target ) {
		if ( ! target || ! target.closest ) { return null; }
		var att = target.closest( '.attachments .attachment' );
		if ( att ) { var gid = attId( att ); return gid ? { id: gid, isList: false } : null; }
		var tr = target.closest( '#the-list tr[id^="post-"]' );
		if ( tr ) { var lid = ( tr.id.match( /\d+/ ) || [] )[0]; return lid ? { id: lid, isList: true } : null; }
		return null;
	}
	function listSelectedIds() {
		var ids = [];
		document.querySelectorAll( '#the-list input[type="checkbox"]:checked' ).forEach( function ( cb ) {
			if ( /^\d+$/.test( cb.value || '' ) ) { ids.push( cb.value ); }
		} );
		return ids;
	}
	document.addEventListener( 'mousedown', function ( e ) {
		if ( e.button !== 0 ) { return; }
		// Exclude interactive controls. Note `a` is NOT excluded: list-row title
		// links must be grabbable, and the 6px threshold + post-drag click-swallow
		// distinguish a drag from a click so links still work on a plain click.
		if ( e.target.closest( 'button, input, .check, .row-actions, .admbud-mm-panel, .admbud-mm-sidebar' ) ) { return; }
		var grab = grabbableFile( e.target );
		if ( ! grab ) { return; }
		dragState = { x: e.clientX, y: e.clientY, id: grab.id, isList: grab.isList, started: false, ids: null, ghost: null };
		document.addEventListener( 'mousemove', onDragMove );
		document.addEventListener( 'mouseup', onDragUp );
	} );
	function onDragMove( e ) {
		if ( ! dragState ) { return; }
		if ( ! dragState.started ) {
			if ( Math.abs( e.clientX - dragState.x ) + Math.abs( e.clientY - dragState.y ) < 6 ) { return; }
			dragState.started = true;
			dragState.ids = dragIdsFor( dragState );
			dragState.ghost = document.createElement( 'div' );
			dragState.ghost.className = 'admbud-mm-drag-ghost';
			dragState.ghost.textContent = dragState.ids.length > 1 ? ( dragState.ids.length + ' items' ) : '1 item';
			document.body.appendChild( dragState.ghost );
			document.body.classList.add( 'admbud-mm-dragging' );
		}
		e.preventDefault();
		dragState.ghost.style.left = ( e.clientX + 14 ) + 'px';
		dragState.ghost.style.top = ( e.clientY + 14 ) + 'px';
		setDropHover( folderRowAt( e.clientX, e.clientY ) );
	}
	function onDragUp( e ) {
		document.removeEventListener( 'mousemove', onDragMove );
		document.removeEventListener( 'mouseup', onDragUp );
		var st = dragState;
		dragState = null;
		document.body.classList.remove( 'admbud-mm-dragging' );
		if ( ! st || ! st.started ) { return; }
		if ( st.ghost && st.ghost.parentNode ) { st.ghost.parentNode.removeChild( st.ghost ); }
		var row = folderRowAt( e.clientX, e.clientY );
		setDropHover( null );
		// Swallow the click that fires right after a drag (don't open the item).
		var sup = function ( ev ) { ev.stopPropagation(); ev.preventDefault(); };
		document.addEventListener( 'click', sup, true );
		setTimeout( function () { document.removeEventListener( 'click', sup, true ); }, 60 );
		if ( row ) { assignTo( row.dataset.id, st.ids, st.isList ); }
	}

	// -- Pick-set: Ctrl/Cmd+click multi-select (upload.php grid only) ----------
	// A self-contained selection layer, independent of wp.media's internals, so
	// users can grab several files for a multi-folder drag WITHOUT entering WP's
	// "Bulk select" mode. Scoped to the upload.php grid: inside a wp.media modal
	// the native multi-select already works, so we leave it alone there.
	var pickSet = Object.create( null ); // id -> true
	function pickedIds() { return Object.keys( pickSet ); }
	function clearPicks() {
		document.querySelectorAll( '.attachment.admbud-mm-picked' ).forEach( function ( el ) { el.classList.remove( 'admbud-mm-picked' ); } );
		pickSet = Object.create( null );
		updateBulkBar();
	}
	// Drag id-set: prefer our pick-set, then WP's native selection, else the one
	// grabbed item. List view keeps using its native row checkboxes.
	function dragIdsFor( st ) {
		if ( st.isList ) {
			var lpick = listSelectedIds();
			return ( lpick.length && lpick.indexOf( String( st.id ) ) !== -1 ) ? lpick : [ st.id ];
		}
		var pset = pickedIds();
		if ( pset.length && pset.indexOf( String( st.id ) ) !== -1 ) { return pset; }
		var picked = selectedAttachmentIds().map( String );
		return ( picked.length && picked.indexOf( String( st.id ) ) !== -1 ) ? picked : [ st.id ];
	}
	document.addEventListener( 'click', function ( e ) {
		var att = e.target.closest ? e.target.closest( '.attachments .attachment' ) : null;
		if ( ! att || att.closest( '.media-modal' ) ) { return; } // modal = native multi-select
		if ( e.ctrlKey || e.metaKey ) {
			// Toggle this item in our pick-set; fully suppress wp.media's own click
			// so it doesn't ALSO select the item natively (which draws WP's own
			// selection bubble on top of ours) or open the single-item details view.
			e.preventDefault();
			e.stopImmediatePropagation();
			var id = attId( att );
			if ( ! id ) { return; }
			if ( pickSet[ id ] ) { delete pickSet[ id ]; att.classList.remove( 'admbud-mm-picked' ); }
			else { pickSet[ id ] = true; att.classList.add( 'admbud-mm-picked' ); }
			updateBulkBar();
		} else {
			// A plain click starts a fresh pick context.
			clearPicks();
		}
	}, true );
	// Block WP's native mousedown selection on a Ctrl/Cmd+click and stop the
	// file-drag handler from arming (picked items are dragged WITHOUT Ctrl held,
	// so suppressing the modified mousedown entirely is safe). The pick toggle
	// still happens on the subsequent click event above.
	document.addEventListener( 'mousedown', function ( e ) {
		if ( ! ( e.ctrlKey || e.metaKey ) ) { return; }
		var att = e.target.closest ? e.target.closest( '.attachments .attachment' ) : null;
		if ( ! att || att.closest( '.media-modal' ) ) { return; }
		e.preventDefault();
		e.stopImmediatePropagation();
	}, true );

	// -- Bulk-ops floating toolbar --------------------------------------------
	// Appears when >=1 grid item is Ctrl/Cmd-picked. Applies the per-card actions
	// to the whole pick-set: Move to folder / Trash / Restore / Delete, plus Select
	// all (loaded cards) + Clear. Reuses assignTo / restoreFiles / forceDeleteFiles,
	// which each clearPicks() on success -> the bar hides itself.
	var bulkBar = null, bulkMenu = null;
	function buildBulkBar() {
		if ( bulkBar ) { return bulkBar; }
		bulkBar = document.createElement( 'div' );
		bulkBar.id = 'admbud-mm-bulkbar';
		bulkBar.className = 'admbud-mm-bulkbar';
		document.body.appendChild( bulkBar );
		return bulkBar;
	}
	function closeBulkMenu() { if ( bulkMenu ) { bulkMenu.remove(); bulkMenu = null; } }
	function openMoveMenu( anchor ) {
		closeBulkMenu();
		if ( ! pickedIds().length ) { return; }
		bulkMenu = document.createElement( 'div' );
		bulkMenu.className = 'admbud-mm-bulkmenu';
		var opts = [ { id: '__uncat__', name: i18n.uncategorized || 'Uncategorized', depth: 0 } ].concat( flattenTree( AB.tree ) );
		opts.forEach( function ( o ) {
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'admbud-mm-bulkmenu__item';
			b.style.paddingLeft = ( 10 + o.depth * 14 ) + 'px';
			b.textContent = o.name;
			b.addEventListener( 'click', function () { closeBulkMenu(); assignTo( o.id, pickedIds(), false ); } );
			bulkMenu.appendChild( b );
		} );
		document.body.appendChild( bulkMenu );
		// Open above the trigger, clamped to the viewport. Measured AFTER append so a
		// long folder list caps its height (scrolls) instead of running off-screen -
		// the previous bottom-anchored version pushed all but the first row off-screen.
		var r = anchor.getBoundingClientRect();
		var avail = r.top - 14; // space above the button
		if ( bulkMenu.offsetHeight > avail ) { bulkMenu.style.maxHeight = Math.max( 140, avail ) + 'px'; }
		var top  = Math.max( 8, r.top - 6 - bulkMenu.offsetHeight );
		var left = Math.max( 8, Math.min( r.left, window.innerWidth - bulkMenu.offsetWidth - 8 ) );
		bulkMenu.style.top  = Math.round( top ) + 'px';
		bulkMenu.style.left = Math.round( left ) + 'px';
	}
	function bulkBtn( label, cls, onClick ) {
		var b = document.createElement( 'button' );
		b.type = 'button';
		b.className = 'admbud-mm-bulkbar__btn' + ( cls ? ' ' + cls : '' );
		b.textContent = label;
		b.addEventListener( 'click', onClick );
		return b;
	}
	function selectAllVisible() {
		document.querySelectorAll( '.attachments .attachment' ).forEach( function ( att ) {
			if ( att.closest( '.media-modal' ) ) { return; }
			var id = attId( att );
			if ( id ) { pickSet[ id ] = true; att.classList.add( 'admbud-mm-picked' ); }
		} );
		updateBulkBar();
	}
	function confirmBulkDelete() {
		var count = pickedIds().length;
		confirmModal(
			i18n.deletePermanent || 'Delete',
			( i18n.confirmDeleteN || 'Permanently delete %d file(s)? This cannot be undone.' ).replace( '%d', count ),
			function () { forceDeleteFiles( pickedIds() ); },
			true
		);
	}
	function updateBulkBar() {
		var ids = pickedIds();
		if ( ! ids.length ) { closeBulkMenu(); if ( bulkBar ) { bulkBar.classList.remove( 'is-open' ); } return; }
		var bar = buildBulkBar();
		closeBulkMenu();
		bar.innerHTML = '';
		var count = ids.length;

		var info = document.createElement( 'span' );
		info.className = 'admbud-mm-bulkbar__count';
		info.textContent = ( i18n.nSelected || '%d selected' ).replace( '%d', count );
		bar.appendChild( info );
		bar.appendChild( bulkBtn( i18n.selectAll || 'Select all', 'admbud-mm-bulkbar__btn--ghost', selectAllVisible ) );

		if ( AB.folder === '__trash__' ) {
			bar.appendChild( bulkBtn( i18n.restore || 'Restore', '', function () { restoreFiles( pickedIds() ); } ) );
			bar.appendChild( bulkBtn( i18n.deletePermanent || 'Delete', 'admbud-mm-bulkbar__btn--danger', confirmBulkDelete ) );
		} else {
			bar.appendChild( bulkBtn( i18n.moveToFolder || 'Move to…', '', function ( e ) { openMoveMenu( e.currentTarget ); } ) );
			if ( cfg.trashEnabled ) {
				bar.appendChild( bulkBtn( i18n.trash || 'Trash', 'admbud-mm-bulkbar__btn--trash', function () { assignTo( '__trash__', pickedIds(), false ); } ) );
			} else {
				bar.appendChild( bulkBtn( i18n.deletePermanent || 'Delete', 'admbud-mm-bulkbar__btn--danger', confirmBulkDelete ) );
			}
		}
		bar.appendChild( bulkBtn( i18n.clearSelection || 'Clear', 'admbud-mm-bulkbar__btn--ghost', clearPicks ) );
		requestAnimationFrame( function () { bar.classList.add( 'is-open' ); } ); // enter animation on first show.
	}
	// Close the move menu on an outside click (the bar/menu live at the body root).
	document.addEventListener( 'mousedown', function ( e ) {
		if ( ! bulkMenu ) { return; }
		if ( e.target.closest( '.admbud-mm-bulkmenu' ) || e.target.closest( '#admbud-mm-bulkbar' ) ) { return; }
		closeBulkMenu();
	} );
	// Esc: close the move menu first; otherwise dismiss the bar (clear the selection).
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key !== 'Escape' ) { return; }
		if ( bulkMenu ) { closeBulkMenu(); return; }
		if ( bulkBar && bulkBar.classList.contains( 'is-open' ) ) { clearPicks(); }
	} );

	// -- Folder drag: re-parent (and move to root) ----------------------------
	// Separate path from the file-drag above (which excludes .admbud-mm-panel /
	// .admbud-mm-sidebar, so it never fires on folder rows). Drag a folder row
	// onto another folder to nest it, or onto "All Media" to move it to root.
	// The server enforces the cycle guard (is_descendant); we also block self/
	// descendant drop targets client-side so the highlight gives honest feedback.
	var folderDrag = null;
	function findNode( nodes, id ) {
		for ( var i = 0; i < nodes.length; i++ ) {
			if ( String( nodes[ i ].id ) === String( id ) ) { return nodes[ i ]; }
			if ( nodes[ i ].children && nodes[ i ].children.length ) {
				var hit = findNode( nodes[ i ].children, id );
				if ( hit ) { return hit; }
			}
		}
		return null;
	}
	function descendantIds( node ) {
		var out = [];
		( function walk( n ) { out.push( String( n.id ) ); ( n.children || [] ).forEach( walk ); } )( node );
		return out;
	}
	// Total file count across a folder AND all its descendants. A folder download
	// is recursive server-side, so the "empty" guard must look at the whole subtree
	// (a parent with 0 direct files but files in subfolders is NOT empty).
	function subtreeCount( node ) {
		var total = 0;
		( function walk( n ) {
			total += parseInt( n.count, 10 ) || 0;
			( n.children || [] ).forEach( walk );
		} )( node );
		return total;
	}
	// Total bytes across a folder AND all its descendants (recursive-view size badge).
	function subtreeSize( node ) {
		var total = 0;
		( function walk( n ) {
			total += parseInt( n.size, 10 ) || 0;
			( n.children || [] ).forEach( walk );
		} )( node );
		return total;
	}
	// Breadcrumb of the active folder's path, shown above the tree. Virtual folders
	// (All / Uncategorized / Trash) render a single crumb; real folders render the
	// full ancestor chain off "All Media". Each crumb (except the current) selects.
	function findNodePath( id ) {
		var path = null;
		( function walk( nodes, trail ) {
			if ( path ) { return; }
			( nodes || [] ).forEach( function ( n ) {
				if ( path ) { return; }
				var t = trail.concat( [ { id: n.id, name: n.name } ] );
				if ( String( n.id ) === String( id ) ) { path = t; return; }
				walk( n.children, t );
			} );
		} )( AB.tree || [], [] );
		return path;
	}
	function updateBreadcrumb() {
		var bc = document.getElementById( 'admbud-mm-breadcrumb' );
		if ( ! bc ) { return; }
		var id = AB.folder;
		if ( id == null || id === '__all__' ) { bc.hidden = true; bc.innerHTML = ''; return; }
		var parts;
		if ( id === '__uncat__' ) { parts = [ { id: '__uncat__', name: i18n.uncategorized || 'Uncategorized' } ]; }
		else if ( id === '__trash__' ) { parts = [ { id: '__trash__', name: i18n.trash || 'Trash' } ]; }
		else {
			parts = findNodePath( id );
			if ( ! parts ) { bc.hidden = true; bc.innerHTML = ''; return; }
		}
		var html = '<button type="button" class="admbud-mm-crumb" data-id="__all__">' + esc( i18n.all || 'All Media' ) + '</button>';
		parts.forEach( function ( seg, i ) {
			html += '<span class="admbud-mm-crumb__sep" aria-hidden="true">›</span>';
			if ( i === parts.length - 1 ) { html += '<span class="admbud-mm-crumb is-current" aria-current="true">' + esc( seg.name ) + '</span>'; }
			else { html += '<button type="button" class="admbud-mm-crumb" data-id="' + esc( seg.id ) + '">' + esc( seg.name ) + '</button>'; }
		} );
		bc.innerHTML = html;
		bc.hidden = false;
		bc.querySelectorAll( '.admbud-mm-crumb[data-id]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () { selectFolder( btn.getAttribute( 'data-id' ) ); } );
		} );
	}
	// A folder drop target: another folder (nest) or All Media (→ root). Rejects
	// Uncategorized + Trash (neither is a real parent for a folder) and the
	// dragged folder's own subtree.
	function folderDropAt( x, y, blocked ) {
		var el  = document.elementFromPoint( x, y );
		var row = el && el.closest ? el.closest( '.admbud-mm-row[data-id]' ) : null;
		if ( ! row ) { return null; }
		var id = row.dataset.id;
		if ( id === '__uncat__' || id === '__trash__' ) { return null; }
		if ( id !== '__all__' && blocked.indexOf( String( id ) ) !== -1 ) { return null; }
		return row;
	}
	document.addEventListener( 'mousedown', function ( e ) {
		if ( e.button !== 0 ) { return; }
		// Re-parenting a folder is a structural edit - gate on the folder_rename tool.
		if ( ! tool( 'folder_rename' ) ) { return; }
		if ( e.target.closest( 'button, input, .admbud-mm-row__toggle, .admbud-mm-row__actions' ) ) { return; }
		var row = e.target.closest( '.admbud-mm-row[data-id]' );
		if ( ! row ) { return; }
		var id = row.dataset.id;
		if ( ! /^\d+$/.test( id ) ) { return; } // only real folders (not __all__ / __uncat__)
		var node = findNode( AB.tree, id );
		if ( ! node ) { return; }
		folderDrag = { x: e.clientX, y: e.clientY, id: id, node: node, started: false, ghost: null, blocked: descendantIds( node ) };
		document.addEventListener( 'mousemove', onFolderMove );
		document.addEventListener( 'mouseup', onFolderUp );
	} );
	function onFolderMove( e ) {
		if ( ! folderDrag ) { return; }
		if ( ! folderDrag.started ) {
			if ( Math.abs( e.clientX - folderDrag.x ) + Math.abs( e.clientY - folderDrag.y ) < 6 ) { return; }
			folderDrag.started = true;
			folderDrag.ghost = document.createElement( 'div' );
			folderDrag.ghost.className = 'admbud-mm-drag-ghost';
			folderDrag.ghost.textContent = folderDrag.node.name;
			document.body.appendChild( folderDrag.ghost );
			document.body.classList.add( 'admbud-mm-dragging' );
		}
		e.preventDefault();
		folderDrag.ghost.style.left = ( e.clientX + 14 ) + 'px';
		folderDrag.ghost.style.top  = ( e.clientY + 14 ) + 'px';
		setDropHover( folderDropAt( e.clientX, e.clientY, folderDrag.blocked ) );
	}
	function onFolderUp( e ) {
		document.removeEventListener( 'mousemove', onFolderMove );
		document.removeEventListener( 'mouseup', onFolderUp );
		var st = folderDrag;
		folderDrag = null;
		document.body.classList.remove( 'admbud-mm-dragging' );
		if ( ! st || ! st.started ) { return; }
		if ( st.ghost && st.ghost.parentNode ) { st.ghost.parentNode.removeChild( st.ghost ); }
		var row = folderDropAt( e.clientX, e.clientY, st.blocked );
		setDropHover( null );
		// Swallow the click that fires right after a drag (don't select the row).
		var sup = function ( ev ) { ev.stopPropagation(); ev.preventDefault(); };
		document.addEventListener( 'click', sup, true );
		setTimeout( function () { document.removeEventListener( 'click', sup, true ); }, 60 );
		if ( ! row ) { return; }
		var parent = ( row.dataset.id === '__all__' ) ? 0 : parseInt( row.dataset.id, 10 );
		if ( parent === ( st.node.parent || 0 ) ) { return; } // no-op: already in this parent
		moveFolder( st.id, parent );
	}
	function moveFolder( id, parent ) {
		ajax( 'admbud_mm_move_folder', { id: id, parent: parent } ).then( function ( r ) {
			if ( r.success ) { afterChange( r.data ); notify( i18n.folderMoved || 'Folder moved.' ); }
			else { notify( ( r.data && r.data.message ) || i18n.failed, true ); }
		} );
	}

	// -- Inline create / rename ----------------------------------------------
	function targetTreeUl() {
		return document.getElementById( 'admbud-mm-tree' ) || document.querySelector( '.admbud-mm-sidebar .admbud-mm-tree' );
	}

	function startCreate( parentId ) {
		var rootUl = targetTreeUl();
		if ( ! rootUl ) { return; }

		// Drop the inline input IN CONTEXT: under the parent folder (expanding it)
		// when creating a subfolder, else at the end of the root list - instead of
		// always pinning it to the top below Uncategorized (which then visibly
		// jumped to its real spot after the post-save tree rebuild).
		var targetUl  = rootUl;
		var parentRow = parentId ? document.querySelector( '.admbud-mm-row[data-id="' + String( parentId ) + '"]' ) : null;
		if ( parentRow ) {
			var parentLi = parentRow.closest( 'li' );
			var childUl  = parentLi ? parentLi.querySelector( ':scope > ul' ) : null;
			if ( parentLi && ! childUl ) {
				childUl = document.createElement( 'ul' );
				parentLi.appendChild( childUl );
			}
			if ( childUl ) {
				targetUl = childUl;
				childUl.style.display = '';   // ensure the parent is expanded
				var ptoggle = parentRow.querySelector( '.admbud-mm-row__toggle' );
				if ( ptoggle ) { ptoggle.classList.remove( 'admbud-mm-row__toggle--leaf' ); ptoggle.classList.add( 'is-open' ); }
			}
		}

		var li = document.createElement( 'li' );
		var row = document.createElement( 'div' );
		row.className = 'admbud-mm-row';
		row.innerHTML = '<span class="admbud-mm-row__toggle admbud-mm-row__toggle--leaf"></span><span class="admbud-mm-row__icon">' + FOLDER_SVG + '</span>';
		var input = document.createElement( 'input' );
		input.type = 'text';
		input.className = 'admbud-mm-edit-input';
		input.value = i18n.newFolder || 'New Folder';
		row.appendChild( input );
		li.appendChild( row );
		targetUl.appendChild( li );
		input.focus();
		input.select();

		var done = false;
		function commit() {
			if ( done ) { return; }
			done = true;
			var name = input.value.trim();
			if ( li.parentNode ) { li.parentNode.removeChild( li ); }
			if ( name ) { doCreate( name, parentId || 0 ); }
		}
		function cancel() { if ( done ) { return; } done = true; if ( li.parentNode ) { li.parentNode.removeChild( li ); } }
		input.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Enter' ) { commit(); } else if ( e.key === 'Escape' ) { cancel(); } } );
		input.addEventListener( 'blur', commit );
	}
	function doCreate( name, parentId ) {
		ajax( 'admbud_mm_create_folder', { name: name, parent: parentId } ).then( function ( r ) {
			if ( r.success ) { afterChange( r.data ); } else { notify( ( r.data && r.data.message ) || i18n.failed, true ); }
		} );
	}

	function startRename( node ) {
		var row = document.querySelector( '.admbud-mm-row[data-id="' + String( node.id ) + '"]' );
		if ( ! row ) { return; }
		row.classList.add( 'is-editing' ); // hide hover actions during rename
		var label = row.querySelector( '.admbud-mm-row__label' );
		if ( ! label ) { return; }
		var input = document.createElement( 'input' );
		input.type = 'text';
		input.className = 'admbud-mm-edit-input';
		input.value = node.name;
		label.replaceWith( input );
		input.focus();
		input.select();

		var done = false;
		function commit() {
			if ( done ) { return; }
			done = true;
			var name = input.value.trim();
			if ( name && name !== node.name ) { doRename( node, name ); } else { renderAllTrees(); }
		}
		input.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Enter' ) { commit(); } else if ( e.key === 'Escape' ) { done = true; renderAllTrees(); } } );
		input.addEventListener( 'blur', commit );
	}
	function doRename( node, name ) {
		ajax( 'admbud_mm_rename_folder', { id: node.id, name: name } ).then( function ( r ) {
			if ( r.success ) { afterChange( r.data ); } else { notify( ( r.data && r.data.message ) || i18n.failed, true ); }
		} );
	}

	function deleteFolder( node ) {
		var n = parseInt( node.count, 10 ) || 0;
		// With Media Trash on, the folder's files go to Trash (recoverable); otherwise
		// they fall back to Uncategorized. Subfolders are kept (re-parented).
		var dest = cfg.trashEnabled ? ( i18n.trash || 'Trash' ) : ( i18n.uncategorized || 'Uncategorized' );
		var msg = n > 0
			? ( n === 1 ? '1 file' : n + ' files' ) + ' will be moved to ' + dest + '. The folder itself is removed.'
			: 'This folder is empty and will be removed.';
		confirmModal( ( i18n.delete || 'Delete' ) + ' “' + node.name + '”?', msg, function () {
			ajax( 'admbud_mm_delete_folder', { id: node.id } ).then( function ( r ) {
				if ( r.success ) {
					if ( String( AB.folder ) === String( node.id ) ) { AB.folder = '__all__'; }
					afterChange( r.data );
					if ( r.data && r.data.trashed > 0 ) { notify( i18n.trashed || ( r.data.trashed + ' file(s) moved to Trash.' ) ); }
					else { notify( i18n.folderDeleted || 'Folder deleted.' ); }
				}
				else { notify( ( r.data && r.data.message ) || i18n.failed, true ); }
			} );
		}, true );
	}

	// Colour swatch popover anchored to the folder row (replaces the native
	// picker, which the browser opens at the top-left corner).
	var colorPop = null;
	function closeColorPop() { if ( colorPop ) { colorPop.remove(); colorPop = null; document.removeEventListener( 'click', closeColorPop ); } }
	function colorFolder( node ) {
		closeColorPop();
		var row = document.querySelector( '.admbud-mm-row[data-id="' + String( node.id ) + '"]' );
		var rect = row ? row.getBoundingClientRect() : { left: 120, bottom: 120 };
		colorPop = document.createElement( 'div' );
		colorPop.className = 'admbud-mm-colorpop';
		PALETTE.forEach( function ( c ) {
			var sw = document.createElement( 'button' );
			sw.type = 'button';
			sw.className = 'admbud-mm-swatch' + ( ( node.color || '' ).toLowerCase() === c.toLowerCase() ? ' is-current' : '' );
			sw.style.background = c;
			sw.title = c;
			sw.addEventListener( 'click', function ( e ) { e.stopPropagation(); closeColorPop(); setColor( node, c ); } );
			colorPop.appendChild( sw );
		} );
		var clear = document.createElement( 'button' );
		clear.type = 'button';
		clear.className = 'admbud-mm-swatch admbud-mm-swatch--clear';
		clear.title = 'Default';
		clear.textContent = '✕';
		clear.addEventListener( 'click', function ( e ) { e.stopPropagation(); closeColorPop(); setColor( node, '' ); } );
		colorPop.appendChild( clear );

		// Custom colour - opens the native picker for anything outside the presets.
		var custom = document.createElement( 'button' );
		custom.type = 'button';
		custom.className = 'admbud-mm-swatch admbud-mm-swatch--custom';
		custom.title = 'Custom colour';
		custom.textContent = '+';
		custom.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			var inp = document.createElement( 'input' );
			inp.type = 'color';
			inp.value = node.color || '#3858e9';
			inp.style.cssText = 'position:fixed;z-index:' + Z_TOP + ';opacity:0;width:24px;height:24px;left:' + rect.left + 'px;top:' + rect.bottom + 'px';
			document.body.appendChild( inp );
			inp.addEventListener( 'change', function () { closeColorPop(); setColor( node, inp.value ); if ( inp.parentNode ) { document.body.removeChild( inp ); } } );
			inp.addEventListener( 'blur', function () { if ( inp.parentNode ) { document.body.removeChild( inp ); } } );
			inp.click();
		} );
		colorPop.appendChild( custom );

		colorPop.style.cssText = 'position:fixed;z-index:' + Z_TOP + ';top:' + rect.bottom + 'px;left:' + rect.left + 'px;';
		document.body.appendChild( colorPop );
		var r = colorPop.getBoundingClientRect();
		if ( r.right > window.innerWidth ) { colorPop.style.left = ( window.innerWidth - r.width - 8 ) + 'px'; }
		setTimeout( function () { document.addEventListener( 'click', closeColorPop ); }, 0 );
	}
	function setColor( node, color ) {
		ajax( 'admbud_mm_set_color', { id: node.id, color: color } ).then( function ( r ) {
			if ( r.success ) { afterChange( r.data ); } else { notify( ( r.data && r.data.message ) || i18n.failed, true ); }
		} );
	}

	// -- Per-role folder visibility popover (admin-only) ----------------------
	// Role checkboxes anchored to the folder row; empty selection = everyone.
	// Mirrors the colour popover's anchoring + outside-click teardown.
	var visPop = null;
	function closeVisPop() { if ( visPop ) { visPop.remove(); visPop = null; document.removeEventListener( 'click', closeVisPop ); document.removeEventListener( 'keydown', onVisKey ); } }
	function onVisKey( e ) { if ( e.key === 'Escape' ) { closeVisPop(); } }
	function visibilityFolder( node ) {
		closeVisPop();
		var roles = cfg.roles || {};
		var current = {};
		( node.roles || [] ).forEach( function ( s ) { current[ s ] = true; } );

		var row  = document.querySelector( '.admbud-mm-row[data-id="' + String( node.id ) + '"]' );
		var rect = row ? row.getBoundingClientRect() : { left: 120, bottom: 120 };

		visPop = document.createElement( 'div' );
		visPop.className = 'admbud-mm-vispop';
		visPop.addEventListener( 'click', function ( e ) { e.stopPropagation(); } );

		var head = document.createElement( 'div' );
		head.className = 'admbud-mm-vispop__head';
		head.textContent = i18n.visibilityTitle || 'Who can see this folder';
		visPop.appendChild( head );

		var hint = document.createElement( 'p' );
		hint.className = 'admbud-mm-vispop__hint';
		hint.textContent = i18n.visibilityHint || 'No roles selected = everyone. Administrators always see every folder.';
		visPop.appendChild( hint );

		var list = document.createElement( 'div' );
		list.className = 'admbud-mm-vispop__list';
		Object.keys( roles ).forEach( function ( slug ) {
			var lab = document.createElement( 'label' );
			lab.className = 'admbud-mm-vispop__row';
			var cb = document.createElement( 'input' );
			cb.type = 'checkbox';
			cb.value = slug;
			if ( current[ slug ] ) { cb.checked = true; }
			lab.appendChild( cb );
			lab.appendChild( document.createTextNode( ' ' + roles[ slug ] ) );
			list.appendChild( lab );
		} );
		visPop.appendChild( list );

		var foot = document.createElement( 'div' );
		foot.className = 'admbud-mm-vispop__foot';
		var save = document.createElement( 'button' );
		save.type = 'button';
		save.className = 'admbud-mm-btn admbud-mm-btn--primary';
		save.textContent = i18n.save || 'Save';
		save.addEventListener( 'click', function () {
			var picked = [];
			list.querySelectorAll( 'input[type="checkbox"]:checked' ).forEach( function ( c ) { picked.push( c.value ); } );
			setVisibility( node, picked );
			closeVisPop();
		} );
		foot.appendChild( save );
		visPop.appendChild( foot );

		visPop.style.cssText = 'position:fixed;z-index:' + Z_TOP + ';top:' + rect.bottom + 'px;left:' + rect.left + 'px;';
		document.body.appendChild( visPop );
		var r = visPop.getBoundingClientRect();
		if ( r.right > window.innerWidth ) { visPop.style.left = ( window.innerWidth - r.width - 8 ) + 'px'; }
		if ( r.bottom > window.innerHeight ) { visPop.style.top = ( window.innerHeight - r.height - 8 ) + 'px'; }
		setTimeout( function () { document.addEventListener( 'click', closeVisPop ); document.addEventListener( 'keydown', onVisKey ); }, 0 );
	}
	function setVisibility( node, roles ) {
		ajax( 'admbud_mm_set_visibility', { id: node.id, roles: roles } ).then( function ( r ) {
			if ( r.success ) { afterChange( r.data ); notify( i18n.visibilitySaved || 'Folder visibility updated.' ); }
			else { notify( ( r.data && r.data.message ) || i18n.failed, true ); }
		} );
	}

	// -- AB-style confirm modal (admin.js isn't loaded on upload.php) ----------
	// Replace mode picker: Keep filename (Mode A) vs Rename + relink (Mode B).
	function replaceChoiceModal( filename, onChoose ) {
		var overlay = document.createElement( 'div' );
		overlay.className = 'admbud-mm-modal-overlay';
		overlay.innerHTML =
			'<div class="admbud-mm-modal" role="dialog" aria-modal="true">' +
				'<div class="admbud-mm-modal__head">' + esc( i18n.replaceMedia || 'Replace media' ) + '</div>' +
				'<div class="admbud-mm-modal__body">' +
					'<p class="admbud-mm-choice-intro">' + esc( ( i18n.replaceWith || 'Replace with' ) + ' ' + filename ) + '</p>' +
					'<label class="admbud-mm-choice"><input type="radio" name="ab-replace-mode" value="keep" checked><span><strong>' + esc( i18n.modeKeep || 'Keep the same filename' ) + '</strong>' + esc( i18n.modeKeepHint || 'Fastest. Same URL, nothing to relink. Cached or page-builder copies may need a cache clear.' ) + '</span></label>' +
					'<label class="admbud-mm-choice"><input type="radio" name="ab-replace-mode" value="rename"><span><strong>' + esc( i18n.modeRename || 'Rename & update links' ) + '</strong>' + esc( i18n.modeRenameHint || 'New URL. Updates every reference across your content. Best for page builders.' ) + '</span></label>' +
				'</div>' +
				'<div class="admbud-mm-modal__foot">' +
					'<button type="button" class="admbud-mm-btn" data-cancel>' + ( i18n.cancel || 'Cancel' ) + '</button>' +
					'<button type="button" class="admbud-mm-btn admbud-mm-btn--primary" data-ok>' + ( i18n.replace || 'Replace' ) + '</button>' +
				'</div>' +
			'</div>';
		function close() { if ( overlay.parentNode ) { overlay.parentNode.removeChild( overlay ); } document.removeEventListener( 'keydown', onKey ); }
		function choose() {
			var sel = overlay.querySelector( 'input[name="ab-replace-mode"]:checked' );
			close();
			onChoose( sel ? sel.value : 'keep' );
		}
		function onKey( e ) { if ( e.key === 'Escape' ) { close(); } if ( e.key === 'Enter' ) { choose(); } }
		overlay.addEventListener( 'click', function ( e ) { if ( e.target === overlay || e.target.hasAttribute( 'data-cancel' ) ) { close(); } } );
		overlay.querySelector( '[data-ok]' ).addEventListener( 'click', choose );
		document.addEventListener( 'keydown', onKey );
		document.body.appendChild( overlay );
		overlay.querySelector( '[data-ok]' ).focus();
	}

	// ZIP layout chooser, shown when a download would actually nest (All Media or a
	// folder with subfolders). onChoose receives 'tree' (default) or 'flat'.
	function structureModal( onChoose ) {
		var overlay = document.createElement( 'div' );
		overlay.className = 'admbud-mm-modal-overlay';
		overlay.innerHTML =
			'<div class="admbud-mm-modal" role="dialog" aria-modal="true">' +
				'<div class="admbud-mm-modal__head">' + esc( i18n.downloadFolder || 'Download as ZIP' ) + '</div>' +
				'<div class="admbud-mm-modal__body">' +
					'<p class="admbud-mm-choice-intro">' + esc( i18n.structIntro || 'How should the files be organised in the ZIP?' ) + '</p>' +
					'<label class="admbud-mm-choice"><input type="radio" name="ab-dl-struct" value="tree" checked><span><strong>' + esc( i18n.structTree || 'Keep folder structure' ) + '</strong>' + esc( i18n.structTreeHint || 'Recreates your folders as subfolders inside the ZIP.' ) + '</span></label>' +
					'<label class="admbud-mm-choice"><input type="radio" name="ab-dl-struct" value="flat"><span><strong>' + esc( i18n.structFlat || 'Flat' ) + '</strong>' + esc( i18n.structFlatHint || 'All files in one level. Duplicate names get a number.' ) + '</span></label>' +
				'</div>' +
				'<div class="admbud-mm-modal__foot">' +
					'<button type="button" class="admbud-mm-btn" data-cancel>' + ( i18n.cancel || 'Cancel' ) + '</button>' +
					'<button type="button" class="admbud-mm-btn admbud-mm-btn--primary" data-ok>' + ( i18n.download || 'Download' ) + '</button>' +
				'</div>' +
			'</div>';
		function close() { if ( overlay.parentNode ) { overlay.parentNode.removeChild( overlay ); } document.removeEventListener( 'keydown', onKey ); }
		function choose() {
			var sel = overlay.querySelector( 'input[name="ab-dl-struct"]:checked' );
			close();
			onChoose( sel ? sel.value : 'tree' );
		}
		function onKey( e ) { if ( e.key === 'Escape' ) { close(); } if ( e.key === 'Enter' ) { choose(); } }
		overlay.addEventListener( 'click', function ( e ) { if ( e.target === overlay || e.target.hasAttribute( 'data-cancel' ) ) { close(); } } );
		overlay.querySelector( '[data-ok]' ).addEventListener( 'click', choose );
		document.addEventListener( 'keydown', onKey );
		document.body.appendChild( overlay );
		overlay.querySelector( '[data-ok]' ).focus();
	}

	function confirmModal( title, message, onConfirm, danger ) {
		var overlay = document.createElement( 'div' );
		overlay.className = 'admbud-mm-modal-overlay';
		overlay.innerHTML =
			'<div class="admbud-mm-modal" role="dialog" aria-modal="true">' +
				'<div class="admbud-mm-modal__head">' + esc( title ) + '</div>' +
				'<div class="admbud-mm-modal__body">' + esc( message ) + '</div>' +
				'<div class="admbud-mm-modal__foot">' +
					'<button type="button" class="admbud-mm-btn" data-cancel>' + ( i18n.cancel || 'Cancel' ) + '</button>' +
					'<button type="button" class="admbud-mm-btn ' + ( danger ? 'admbud-mm-btn--danger' : 'admbud-mm-btn--primary' ) + '" data-ok>' + ( i18n.confirm || 'Confirm' ) + '</button>' +
				'</div>' +
			'</div>';
		function close() { if ( overlay.parentNode ) { overlay.parentNode.removeChild( overlay ); } document.removeEventListener( 'keydown', onKey ); }
		function onKey( e ) { if ( e.key === 'Escape' ) { close(); } if ( e.key === 'Enter' ) { close(); onConfirm(); } }
		overlay.addEventListener( 'click', function ( e ) { if ( e.target === overlay || e.target.hasAttribute( 'data-cancel' ) ) { close(); } } );
		overlay.querySelector( '[data-ok]' ).addEventListener( 'click', function () { close(); onConfirm(); } );
		document.addEventListener( 'keydown', onKey );
		document.body.appendChild( overlay );
		overlay.querySelector( '[data-ok]' ).focus();
	}

	// -- Context menu ---------------------------------------------------------
	var ctxMenu = null;
	function closeMenu() { if ( ctxMenu ) { ctxMenu.remove(); ctxMenu = null; document.removeEventListener( 'click', closeMenu ); document.removeEventListener( 'keydown', onCtxKey ); } }
	function onCtxKey( e ) { if ( e.key === 'Escape' ) { closeMenu(); } }
	function showMenu( x, y, node, virtual ) {
		closeMenu();
		// Each row: [ iconSvg, label, handler, isDanger? ] or [ 'sep' ].
		var isTrashVirtual = !! ( virtual && virtual.id === '__trash__' );
		var rows = [];
		// "New (sub)folder" makes no sense on the Trash pseudo-folder - skip it there.
		// Gated by the folder_create tool (admins always have it).
		if ( ! isTrashVirtual && tool( 'folder_create' ) ) {
			rows.push( [ ACT_NEWFOLDER_SVG, node ? ( i18n.newFolder || 'New subfolder' ) : ( i18n.newFolder || 'New Folder' ), function () { startCreate( node ? node.id : 0 ); } ] );
		}
		if ( node ) {
			// Folder actions gated per tool so a role only sees what it can do.
			if ( tool( 'folder_rename' ) ) {
				rows.push( [ ACT_RENAME_SVG, i18n.rename || 'Rename', function () { startRename( node ); } ] );
			}
			if ( tool( 'folder_color' ) ) {
				rows.push( [ ACT_COLOUR_SVG, i18n.setColour || 'Set colour', function () { colorFolder( node ); } ] );
			}
			rows.push( [ 'sep' ] );
			// Show the folder's term id inline (some users need it directly) AND copy it.
			rows.push( [ ACT_ID_SVG, ( i18n.copyId || 'Copy folder ID' ) + ' (#' + node.id + ')', function () { copyFolderId( node ); } ] );
			if ( tool( 'folder_delete' ) ) {
				rows.push( [ 'sep' ] );
				rows.push( [ ACT_DELETE_SVG, i18n.delete || 'Delete', function () { deleteFolder( node ); }, true ] );
			}
		} else if ( virtual && ( virtual.id === '__all__' || virtual.id === '__uncat__' ) && ( parseInt( virtual.count, 10 ) || 0 ) > 0 ) {
		} else if ( isTrashVirtual && ( parseInt( virtual.count, 10 ) || 0 ) > 0 ) {
			// Trash (non-empty): restore everything, or empty it permanently.
			rows.push( [ RESTORE_ICON_SVG, i18n.restoreAll || 'Restore all', function () { restoreAll(); } ] );
			rows.push( [ ACT_DELETE_SVG, i18n.emptyTrash || 'Empty Trash', function () { emptyTrash( parseInt( virtual.count, 10 ) || 0 ); }, true ] );
		}
		// Nothing applicable (e.g. an empty Trash) - don't pop an empty menu.
		if ( ! rows.length ) { return; }
		ctxMenu = document.createElement( 'div' );
		ctxMenu.className = 'admbud-mm-ctx';
		rows.forEach( function ( it ) {
			if ( it[0] === 'sep' ) { var s = document.createElement( 'div' ); s.className = 'admbud-mm-ctx__sep'; ctxMenu.appendChild( s ); return; }
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'admbud-mm-ctx__item' + ( it[3] ? ' is-danger' : '' );
			b.innerHTML = '<span class="admbud-mm-ctx__icon" aria-hidden="true">' + it[0] + '</span>';
			b.appendChild( document.createTextNode( it[1] ) );
			b.addEventListener( 'click', function ( e ) { e.stopPropagation(); closeMenu(); it[2](); } );
			ctxMenu.appendChild( b );
		} );
		ctxMenu.style.cssText = 'position:fixed;z-index:' + Z_TOP + ';top:' + y + 'px;left:' + x + 'px;';
		document.body.appendChild( ctxMenu );
		// Keep on-screen.
		var r = ctxMenu.getBoundingClientRect();
		if ( r.right > window.innerWidth ) { ctxMenu.style.left = ( window.innerWidth - r.width - 8 ) + 'px'; }
		if ( r.bottom > window.innerHeight ) { ctxMenu.style.top = ( window.innerHeight - r.height - 8 ) + 'px'; }
		setTimeout( function () { document.addEventListener( 'click', closeMenu ); document.addEventListener( 'keydown', onCtxKey ); }, 0 );
	}

	// -- Bulk download (zip the original files behind a folder) ---------------
	// Two steps: prepare (server builds the ZIP, returns a one-time token +
	// count/size, enforces the size cap and reports errors as JSON), then stream
	// the prebuilt file via a GET link so the browser shows a Save dialog.
	var dlBusy = false;
	function streamPrepared( r ) {
		dlBusy = false;
		if ( ! r || ! r.success ) {
			notify( ( r && r.data && r.data.message ) || ( i18n.failed || 'Download failed.' ), true );
			return;
		}
		var d = r.data;
		var url = cfg.ajaxUrl + ( cfg.ajaxUrl.indexOf( '?' ) === -1 ? '?' : '&' ) +
			'action=admbud_mm_download&token=' + encodeURIComponent( d.token ) + '&nonce=' + encodeURIComponent( cfg.nonce );
		var a = document.createElement( 'a' );
		a.href = url;
		a.rel = 'noopener';
		document.body.appendChild( a );
		a.click();
		document.body.removeChild( a );
		var msg = ( i18n.downloadingN || 'Downloading' ) + ' ' + ( d.count || 0 ) + ' ' + ( i18n.filesWord || 'files' ) + ' (' + ( d.size || '' ) + ')';
		if ( d.skipped ) { msg += ' · ' + d.skipped + ' ' + ( i18n.skippedMissing || 'missing, skipped' ); }
		notify( msg );
	}
	function downloadPrepare( params ) {
		if ( dlBusy ) { return; }
		dlBusy = true;
		notify( i18n.preparingDownload || 'Preparing download…' );
		ajax( 'admbud_mm_download_prepare', params ).then( streamPrepared ).catch( function () {
			dlBusy = false;
			notify( i18n.failed || 'Download failed.', true );
		} );
	}
	function downloadScope( folder, label, structure ) { downloadPrepare( { scope: 'folder', folder: String( folder ), label: label || '', structure: structure || 'tree' } ); }
	function downloadFolder( node ) {
		if ( ! node ) { return; }
		if ( subtreeCount( node ) === 0 ) { notify( i18n.emptyFolder || 'This folder has no files to download.', true ); return; }
		// Only offer flat-vs-tree when the folder actually nests (has subfolders).
		if ( node.children && node.children.length ) {
			structureModal( function ( s ) { downloadScope( node.id, node.name, s ); } );
		} else {
			downloadScope( node.id, node.name, 'tree' );
		}
	}
	// All Media: offer the layout choice only if any folders exist (else it's flat anyway).
	function downloadAllMedia() {
		if ( AB.tree && AB.tree.length ) {
			structureModal( function ( s ) { downloadScope( '__all__', 'all-media', s ); } );
		} else {
			downloadScope( '__all__', 'all-media', 'flat' );
		}
	}
	function downloadIds( ids, label ) {
		if ( ! ids || ! ids.length ) { notify( i18n.selectFirst || 'Select some files first.', true ); return; }
		downloadPrepare( { scope: 'ids', ids: ids, label: label || 'selection' } );
	}

	// -- "Download" button in WP's native grid Bulk-select toolbar ------------
	// Native Bulk select offers only "Delete permanently"; we add a Download
	// alongside it that zips the current wp.media selection (scope=ids). Selection
	// state is WP's own collection, read via selectedAttachmentIds().
	// True only while WP's grid "Bulk select" mode is active. The controller is the
	// source of truth; DOM signals (toggle pressed / delete button shown) are a
	// fallback in case the controller API shifts.
	function inSelectMode() {
		try {
			if ( lastBrowser && lastBrowser.controller && typeof lastBrowser.controller.isModeActive === 'function' ) {
				return !! lastBrowser.controller.isModeActive( 'select' );
			}
		} catch ( e ) {}
		var toggle = document.querySelector( '.select-mode-toggle-button' );
		if ( toggle && toggle.getAttribute( 'aria-pressed' ) === 'true' ) { return true; }
		var del = document.querySelector( '.delete-selected-button' );
		return !! ( del && ! del.classList.contains( 'hidden' ) && del.offsetParent !== null );
	}
	function ensureBulkDownloadBtn() {
		if ( ! isGrid() ) { return; }
		var existingDl = document.querySelector( '.admbud-mm-dl-selected' );
		var existingMv = document.querySelector( '.admbud-mm-move-selected' );
		// Only present while Bulk select is on; remove the moment select mode ends.
		if ( ! inSelectMode() ) {
			if ( existingDl && existingDl.parentNode ) { existingDl.parentNode.removeChild( existingDl ); }
			if ( existingMv && existingMv.parentNode ) { existingMv.parentNode.removeChild( existingMv ); }
			return;
		}
		var delBtn = document.querySelector( '.media-frame .delete-selected-button' ) ||
			document.querySelector( '.media-toolbar .delete-selected-button' );
		if ( ! delBtn || ! delBtn.parentNode ) { return; }
		if ( ! existingMv ) {
			var mv = document.createElement( 'button' );
			mv.type = 'button';
			mv.className = 'button media-button button-large admbud-mm-move-selected';
			mv.textContent = i18n.moveToFolder || 'Move to folder';
			mv.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var ids = selectedAttachmentIds();
				if ( ! ids.length ) { notify( i18n.selectFirst || 'Select some files first.', true ); return; }
				if ( ! ( AB.tree && AB.tree.length ) ) { notify( i18n.noFolders || 'No folders yet. Create one first.', true ); return; }
				var r = mv.getBoundingClientRect();
				folderPickerPopover( r.left, r.bottom + 4, function ( target ) { assignTo( target, ids, false ); } );
			} );
			delBtn.parentNode.insertBefore( mv, delBtn );
		}
	}
	var bulkToolbarObs = null, bulkToolbarTimer = null;
	function setupBulkToolbarObserver() {
		if ( bulkToolbarObs || typeof MutationObserver !== 'function' ) { return; }
		var frame = document.querySelector( '.media-frame' );
		if ( ! frame ) { return; }
		bulkToolbarObs = new MutationObserver( function () {
			clearTimeout( bulkToolbarTimer );
			bulkToolbarTimer = setTimeout( ensureBulkDownloadBtn, 80 );
		} );
		bulkToolbarObs.observe( frame, { childList: true, subtree: true } );
	}

	// -- Re-render after a data change ----------------------------------------
	function afterChange( data ) {
		if ( data && data.tree ) { AB.tree = data.tree; }
		if ( data && data.virtual ) { AB.virtual = data.virtual; }
		renderAllTrees();
		requeryGrid();
	}

	// Refetch counts when attachments are deleted in the grid (the count of the
	// current folder / All / Uncategorized changes outside our own AJAX).
	var refreshTimer = null;
	function refreshCounts() {
		clearTimeout( refreshTimer );
		refreshTimer = setTimeout( function () {
			ajax( 'admbud_mm_get_tree', {} ).then( function ( r ) {
				if ( r.success ) {
					if ( r.data.tree ) { AB.tree = r.data.tree; }
					if ( r.data.virtual ) { AB.virtual = r.data.virtual; }
					renderAllTrees();
				}
			} );
		}, 400 );
	}
	function watchCollection( browser ) {
		try {
			if ( browser && browser.collection && ! browser.__admbudWatched ) {
				browser.__admbudWatched = true;
				browser.collection.on( 'remove', refreshCounts ); // fires on delete (mirroring uses reset)
			}
		} catch ( e ) {}
	}


	// Keep the panel sticky: fixed position computed from #wpbody-content so it
	// stays in view while the page scrolls (e.g. long list table) and tracks the
	// admin-menu width (incl. AB's custom width / folded menu).
	function positionPanel() {
		var panel  = document.getElementById( 'admbud-mm-panel' );
		var reopen = document.getElementById( 'admbud-mm-reopen' );
		var host   = document.getElementById( 'wpbody-content' );
		if ( ! host ) { return; }
		// On small screens the panel is static (CSS) - clear inline overrides.
		if ( window.innerWidth <= 782 ) {
			[ panel, reopen ].forEach( function ( el ) { if ( el ) { el.style.position = ''; el.style.left = ''; el.style.top = ''; el.style.bottom = ''; } } );
			return;
		}
		var left = host.getBoundingClientRect().left;
		var bar  = document.getElementById( 'wpadminbar' );
		var top  = bar ? Math.max( 0, bar.getBoundingClientRect().bottom ) : 0;
		if ( panel )  { panel.style.position = 'fixed';  panel.style.left = left + 'px'; panel.style.top = top + 'px'; panel.style.bottom = '0'; }
		if ( reopen ) { reopen.style.position = 'fixed'; reopen.style.left = left + 'px'; reopen.style.top = ( top + 4 ) + 'px'; }
	}

	// Adapt the panel to AB's content colours: go dark only when AB's content/
	// body background is dark. Light/unset content keeps the default light panel.
	function toRgb( s ) {
		s = ( s || '' ).trim();
		var m = /rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec( s );
		if ( m ) { return [ +m[1], +m[2], +m[3] ]; }
		var h = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec( s );
		if ( h ) { var x = h[1]; if ( x.length === 3 ) { x = x[0]+x[0]+x[1]+x[1]+x[2]+x[2]; } return [ parseInt( x.substr(0,2),16 ), parseInt( x.substr(2,2),16 ), parseInt( x.substr(4,2),16 ) ]; }
		return null;
	}
	function themePanel() {
		var panel  = document.getElementById( 'admbud-mm-panel' );
		var reopen = document.getElementById( 'admbud-mm-reopen' );
		if ( ! panel ) { return; }
		var rgb = cfg.panelBg ? toRgb( cfg.panelBg ) : null;
		var lum = rgb ? ( 0.299 * rgb[0] + 0.587 * rgb[1] + 0.114 * rgb[2] ) / 255 : 1;
		var dark = !! rgb && lum < 0.5;
		document.body.classList.toggle( 'admbud-mm-dark', dark );
		[ panel, reopen ].forEach( function ( el ) {
			if ( ! el ) { return; }
			if ( dark ) {
				el.style.setProperty( '--admbud-mm-bg', cfg.panelBg );
				if ( cfg.panelText ) { el.style.setProperty( '--admbud-mm-text', cfg.panelText ); }
			} else {
				el.style.removeProperty( '--admbud-mm-bg' );
				el.style.removeProperty( '--admbud-mm-text' );
			}
		} );
	}

	// -- Footer slide-panels (Bulk SEO / Tools) -------------------------------
	// Uses the canonical .ab-slide-panel system (loaded via admbud-core on
	// upload.php). Panels slide via `right` (see CSS note) and lift above the
	// admin bar. The Bulk SEO / export logic itself lives in tab-media-manager.js.
	function openSlidePanel( id ) {
		var panel = document.getElementById( id );
		if ( ! panel ) { return; }
		var bd = document.getElementById( 'admbud-mm-backdrop' );
		if ( bd ) { bd.classList.add( 'is-open' ); }
		panel.classList.add( 'is-open' );
		panel.setAttribute( 'aria-hidden', 'false' );
		document.body.classList.add( 'ab-modal-open' );
	}
	function closeSlidePanels() {
		document.querySelectorAll( '.admbud-mm-slidepanel.is-open' ).forEach( function ( p ) {
			p.classList.remove( 'is-open' );
			p.setAttribute( 'aria-hidden', 'true' );
		} );
		var bd = document.getElementById( 'admbud-mm-backdrop' );
		if ( bd ) { bd.classList.remove( 'is-open' ); }
		document.body.classList.remove( 'ab-modal-open' );
	}

	// -- Gallery shortcode configurator ---------------------------------------
	// Opened from a folder's right-click menu. Builds a copy-ready
	// [admbud_media_folder] shortcode live from the controls, emitting only
	// non-default attributes so the copied string stays minimal.
	function scStr( id, dflt ) { var e = document.getElementById( id ); return e ? e.value : dflt; }
	function scNum( id, dflt ) { var e = document.getElementById( id ); var n = e ? parseInt( e.value, 10 ) : NaN; return isNaN( n ) ? dflt : n; }
	function scChk( id ) { var e = document.getElementById( id ); return !! ( e && e.checked ); }

	function buildShortcode() {
		var id = scStr( 'ab-mm-sc-id', '' );
		if ( ! id ) { return ''; }
		var parts = [ 'admbud_media_folder', 'id="' + id + '"' ];
		var cols = scNum( 'ab-mm-sc-columns', 4 );
		if ( cols !== 4 ) { parts.push( 'columns="' + Math.max( 1, Math.min( 8, cols ) ) + '"' ); }
		var size = scStr( 'ab-mm-sc-size', 'medium' );
		if ( size && size !== 'medium' ) { parts.push( 'size="' + size + '"' ); }
		var link = scStr( 'ab-mm-sc-link', 'file' );
		if ( link && link !== 'file' ) { parts.push( 'link="' + link + '"' ); }
		if ( scChk( 'ab-mm-sc-recursive' ) ) { parts.push( 'recursive="true"' ); }
		if ( ! scChk( 'ab-mm-sc-lightbox' ) ) { parts.push( 'lightbox="false"' ); }
		var gap = scNum( 'ab-mm-sc-gap', 12 );
		if ( gap !== 12 ) { parts.push( 'gap="' + Math.max( 0, Math.min( 80, gap ) ) + '"' ); }
		var limit = scNum( 'ab-mm-sc-limit', -1 );
		if ( limit !== -1 && limit !== 0 ) { parts.push( 'limit="' + limit + '"' ); }
		var orderby = scStr( 'ab-mm-sc-orderby', 'date' );
		if ( orderby && orderby !== 'date' ) { parts.push( 'orderby="' + orderby + '"' ); }
		var order = scStr( 'ab-mm-sc-order', 'DESC' );
		if ( order && order !== 'DESC' ) { parts.push( 'order="' + order + '"' ); }
		var pager = scStr( 'ab-mm-sc-pagination', 'none' );
		if ( pager === 'numbers' || pager === 'loadmore' ) {
			parts.push( 'per_page="' + Math.max( 1, scNum( 'ab-mm-sc-perpage', 12 ) ) + '"' );
			// 'numbers' is the gallery's default style when per_page is set, so omit it.
			if ( pager === 'loadmore' ) { parts.push( 'pagination="loadmore"' ); }
		}
		return '[' + parts.join( ' ' ) + ']';
	}

	function refreshShortcode() {
		var out = document.getElementById( 'ab-mm-sc-code' );
		if ( out ) { out.value = buildShortcode(); }
	}

	function openShortcodePanel( node ) {
		var idEl = document.getElementById( 'ab-mm-sc-id' );
		if ( ! idEl ) { return; }
		idEl.value = node.id;
		var nameEl = document.querySelector( '[data-mm-sc-folder]' );
		if ( nameEl ) { nameEl.textContent = node.name || ( '#' + node.id ); }
		refreshShortcode();
		openSlidePanel( 'admbud-mm-panel-shortcode' );
	}

	function initShortcodePanel() {
		var form = document.querySelector( '.admbud-mm-sc-form' );
		if ( ! form ) { return; }
		// Any control change rebuilds the string - covers number inputs, toggles,
		// and the ab-dropdown styled-selects (which dispatch a bubbling change).
		form.addEventListener( 'input', refreshShortcode );
		form.addEventListener( 'change', refreshShortcode );
		// Reveal the "Per page" field only when a pagination style is chosen.
		var pagerSel = document.getElementById( 'ab-mm-sc-pagination' );
		var perField = document.querySelector( '[data-mm-sc-perpage]' );
		function syncPerPage() {
			if ( perField ) { perField.hidden = ( ! pagerSel || pagerSel.value === 'none' || ! pagerSel.value ); }
		}
		if ( pagerSel ) { pagerSel.addEventListener( 'change', syncPerPage ); }
		syncPerPage();
		var copyBtn = document.getElementById( 'ab-mm-sc-copy' );
		if ( copyBtn ) {
			copyBtn.addEventListener( 'click', function () {
				var code = document.getElementById( 'ab-mm-sc-code' );
				copyText( code ? code.value : '', i18n.scCopied || 'Shortcode copied.', code );
			} );
		}
	}

	// Copy a string to the clipboard with a graceful fallback + toast.
	function copyText( text, okMsg, selectEl ) {
		if ( ! text ) { return; }
		function fallback() {
			try {
				if ( selectEl && selectEl.select ) { selectEl.focus(); selectEl.select(); }
				var ok = document.execCommand && document.execCommand( 'copy' );
				notify( ok ? okMsg : ( i18n.copyFailed || 'Could not copy.' ), ! ok );
			} catch ( e ) { notify( i18n.copyFailed || 'Could not copy.', true ); }
		}
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( function () { notify( okMsg ); }, fallback );
		} else {
			fallback();
		}
	}

	function copyFolderId( node ) {
		copyText( String( node.id ), ( i18n.idCopied || 'Folder ID copied.' ) + ' #' + node.id );
	}


	function initSlidePanels() {
		document.querySelectorAll( '[data-mm-open]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () { openSlidePanel( btn.getAttribute( 'data-mm-open' ) ); } );
		} );
		document.querySelectorAll( '[data-mm-close]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', closeSlidePanels );
		} );
		var bd = document.getElementById( 'admbud-mm-backdrop' );
		if ( bd ) { bd.addEventListener( 'click', closeSlidePanels ); }
		document.addEventListener( 'keydown', function ( e ) {
			// Let an open ab-dropdown swallow Esc first; only then close the panel.
			if ( e.key === 'Escape' && ! document.querySelector( '.ab-dropdown.is-open' ) && document.querySelector( '.admbud-mm-slidepanel.is-open' ) ) {
				closeSlidePanels();
			}
		} );
	}


	// -- Page-level panel -----------------------------------------------------
	// -- Folder-panel toolbar: search, sort, collapse-all ---------------------
	function setDropdownValue( inputId, value ) {
		var input = document.getElementById( inputId );
		if ( ! input ) { return; }
		input.value = value;
		var dd = input.closest ? input.closest( '.ab-dropdown' ) : null;
		if ( ! dd ) { return; }
		var opt = dd.querySelector( '.ab-dropdown__option[data-value="' + value + '"]' );
		if ( ! opt ) { return; }
		dd.querySelectorAll( '.ab-dropdown__option.is-selected' ).forEach( function ( o ) { o.classList.remove( 'is-selected' ); } );
		opt.classList.add( 'is-selected' );
		var val = dd.querySelector( '.ab-dropdown__value' );
		if ( val ) { val.textContent = opt.textContent; }
	}

	function applyGridSearch() {
		if ( lastBrowser && lastBrowser.collection && lastBrowser.collection.props ) {
			lastBrowser.collection.props.set( 'search', AB.search || '', { silent: true } );
			requeryGrid();
		}
	}
	function setSearch( term ) {
		term = ( term || '' ).trim();
		AB.search = term;
		AB.treeFilter = term.toLowerCase();
		renderAllTrees();
		if ( isGrid() ) { applyGridSearch(); }
	}

	var sortInitDone = false;
	function applySort( save ) {
		if ( lastBrowser && lastBrowser.collection && lastBrowser.collection.props ) {
			lastBrowser.collection.props.set( { orderby: AB.sortBy, order: AB.sortOrder }, { silent: true } );
			requeryGrid();
		}
		if ( save ) {
			try { localStorage.setItem( 'admbudMmSortBy', AB.sortBy ); localStorage.setItem( 'admbudMmSortOrder', AB.sortOrder ); } catch ( e ) {}
		}
	}
	function applySortListMode() {
		try {
			var u = new URL( window.location.href );
			u.searchParams.set( 'orderby', AB.sortBy );
			u.searchParams.set( 'order', AB.sortOrder );
			window.location.href = u.toString();
		} catch ( e ) {}
	}

	function flattenTree( nodes, depth, out ) {
		out = out || []; depth = depth || 0;
		( nodes || [] ).forEach( function ( n ) {
			out.push( { id: n.id, name: n.name, depth: depth } );
			if ( n.children && n.children.length ) { flattenTree( n.children, depth + 1, out ); }
		} );
		return out;
	}
	// Small folder chooser (reuses the context-menu chrome + teardown).
	function folderPickerPopover( x, y, onPick ) {
		closeMenu();
		var menu = document.createElement( 'div' );
		menu.className = 'admbud-mm-ctx admbud-mm-folderpick';
		function addItem( label, value ) {
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'admbud-mm-ctx__item';
			b.textContent = label;
			b.addEventListener( 'click', function ( e ) { e.stopPropagation(); closeMenu(); onPick( value ); } );
			menu.appendChild( b );
		}
		addItem( i18n.uncategorized || 'Uncategorized', '__uncat__' );
		var sep = document.createElement( 'div' ); sep.className = 'admbud-mm-ctx__sep'; menu.appendChild( sep );
		flattenTree( AB.tree ).forEach( function ( f ) {
			addItem( ( f.depth ? '   '.repeat( f.depth ) : '' ) + f.name, String( f.id ) );
		} );
		menu.style.cssText = 'position:fixed;z-index:' + Z_TOP + ';top:' + y + 'px;left:' + x + 'px;max-height:60vh;overflow:auto;';
		document.body.appendChild( menu );
		ctxMenu = menu; // hand to closeMenu()/outside-click teardown
		var r = menu.getBoundingClientRect();
		if ( r.right > window.innerWidth ) { menu.style.left = ( window.innerWidth - r.width - 8 ) + 'px'; }
		if ( r.bottom > window.innerHeight ) { menu.style.top = Math.max( 8, window.innerHeight - r.height - 8 ) + 'px'; }
		setTimeout( function () { document.addEventListener( 'click', closeMenu ); document.addEventListener( 'keydown', onCtxKey ); }, 0 );
	}

	function initToolbar() {
		try {
			AB.sortBy = localStorage.getItem( 'admbudMmSortBy' ) || 'date';
			AB.sortOrder = ( localStorage.getItem( 'admbudMmSortOrder' ) === 'ASC' ) ? 'ASC' : 'DESC';
		} catch ( e ) {}
		if ( [ 'date', 'title', 'modified' ].indexOf( AB.sortBy ) === -1 ) { AB.sortBy = 'date'; }
		setDropdownValue( 'ab-mm-sort', AB.sortBy );

		var search = document.getElementById( 'admbud-mm-search' );
		var clear  = document.getElementById( 'admbud-mm-search-clear' );
		var searchTimer = null;
		if ( search ) {
			search.addEventListener( 'input', function () {
				if ( clear ) { clear.hidden = ! search.value; }
				clearTimeout( searchTimer );
				searchTimer = setTimeout( function () { setSearch( search.value ); }, 220 );
			} );
			search.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' && ! isGrid() && search.value.trim() ) {
					try { var u = new URL( window.location.href ); u.searchParams.set( 's', search.value.trim() ); window.location.href = u.toString(); } catch ( er ) {}
				} else if ( e.key === 'Escape' && search.value ) {
					e.stopPropagation();
					search.value = '';
					if ( clear ) { clear.hidden = true; }
					setSearch( '' );
				}
			} );
		}
		if ( clear ) {
			clear.addEventListener( 'click', function () { if ( search ) { search.value = ''; search.focus(); } clear.hidden = true; setSearch( '' ); } );
		}

		var sortInput = document.getElementById( 'ab-mm-sort' );
		if ( sortInput ) {
			sortInput.addEventListener( 'change', function () {
				AB.sortBy = sortInput.value || 'date';
				if ( paintSortDir ) { paintSortDir(); } // alpha glyph for Name, amount glyph otherwise.
				if ( isGrid() ) { applySort( true ); }
				else { try { localStorage.setItem( 'admbudMmSortBy', AB.sortBy ); } catch ( e ) {} applySortListMode(); }
			} );
		}
		var dir = document.getElementById( 'admbud-mm-sort-dir' );
		if ( dir ) {
			// sort-alpha glyphs (FontAwesome-style filled paths: arrow + stacked A/Z).
			// Swapped by JS - not CSS-rotated - so the letters stay upright. ASC = A over
			// Z with a down arrow; DESC = Z over A with an up arrow.
			var ICON_SORT_ASC = '<svg width="16" height="16" viewBox="0 0 32 32" fill="currentColor" aria-hidden="true"><path d="M30,11.67H29L25.71.71s0,0-.05-.08a.61.61,0,0,0-.09-.18c0-.06-.08-.1-.12-.15L25.31.19a.69.69,0,0,0-.19-.1L25,0h-.58l-.08,0a.69.69,0,0,0-.19.1.69.69,0,0,0-.13.11l-.13.15a1,1,0,0,0-.09.18s0,.05-.05.08l-3.28,11h-1a1,1,0,0,0,0,2H23a1,1,0,0,0,0-2h-.41l.9-3H26l.9,3H26.5a1,1,0,0,0,0,2H30a1,1,0,0,0,0-2Zm-5.91-5,.66-2.19.66,2.19Z"/><path d="M7.25,0a1,1,0,0,0-1,1V28.67l-3.56-3.4a1,1,0,0,0-1.42,0,1,1,0,0,0,0,1.41l5.25,5c0,.05.1.06.15.1a.86.86,0,0,0,.16.1.94.94,0,0,0,.76,0,1.51,1.51,0,0,0,.17-.1s.1-.06.14-.1l5.25-5a1,1,0,0,0,0-1.41,1,1,0,0,0-1.42,0l-3.56,3.4V1A1,1,0,0,0,7.25,0Z"/><path d="M30,28.33a1,1,0,0,0-1,1V30H21.75l9-10a1,1,0,0,0,.17-1.07,1,1,0,0,0-.91-.6H19.5a1,1,0,0,0-1,1V21a1,1,0,0,0,2,0v-.67h7.26l-9,10A1,1,0,0,0,19.5,32H30a1,1,0,0,0,1-1V29.33A1,1,0,0,0,30,28.33Z"/></svg>';
			var ICON_SORT_DESC = '<svg width="16" height="16" viewBox="0 0 32 32" fill="currentColor" aria-hidden="true"><path d="M30,29.88H29L25.71,18.93s0-.06-.05-.09a.76.76,0,0,0-.09-.18l-.12-.14-.14-.12-.19-.1-.08,0H25l-.2,0-.2,0h-.09l-.08,0-.19.1a.74.74,0,0,0-.13.12.64.64,0,0,0-.13.15.91.91,0,0,0-.09.17s0,.05-.05.09l-3.28,11h-1a1,1,0,0,0,0,2H23a1,1,0,0,0,0-2h-.41l.9-3H26l.9,3H26.5a1,1,0,0,0,0,2H30a1,1,0,0,0,0-2Zm-5.91-5,.66-2.18.66,2.18Z"/><path d="M2.69,6.72,6.25,3.33V31a1,1,0,0,0,2,0V3.33l3.56,3.39A1,1,0,0,0,12.5,7a1,1,0,0,0,.73-.31,1,1,0,0,0,0-1.42L7.94.27A1.1,1.1,0,0,0,7.8.18a1.51,1.51,0,0,0-.17-.1,1,1,0,0,0-.76,0,.86.86,0,0,0-.16.1.75.75,0,0,0-.15.09l-5.25,5A1,1,0,1,0,2.69,6.72Z"/><path d="M30,10.12a1,1,0,0,0-1,1v.67H21.75l9-10A1,1,0,0,0,30.91.71,1,1,0,0,0,30,.12H19.5a1,1,0,0,0-1,1V2.79a1,1,0,0,0,2,0V2.12h7.26l-9,10a1,1,0,0,0-.17,1.07,1,1,0,0,0,.91.6H30a1,1,0,0,0,1-1V11.12A1,1,0,0,0,30,10.12Z"/></svg>';
			// Date / Last modified are NOT alphabetical, so an A-Z glyph would mislead
			// (it's date order, not name order). Those fields use a sort-amount glyph
			// (arrow + bars). Only the Name (title) field shows the A-Z / Z-A letters.
			var ICON_AMOUNT_ASC = '<svg width="16" height="16" viewBox="0 0 32 32" fill="currentColor" aria-hidden="true"><rect x="6" y="3" width="3" height="18" rx="1.5"/><path d="M2.5 20H12.5L7.5 28Z"/><rect x="16" y="5.5" width="6" height="3" rx="1.5"/><rect x="16" y="14.5" width="10" height="3" rx="1.5"/><rect x="16" y="23.5" width="14" height="3" rx="1.5"/></svg>';
			var ICON_AMOUNT_DESC = '<svg width="16" height="16" viewBox="0 0 32 32" fill="currentColor" aria-hidden="true"><rect x="6" y="11" width="3" height="18" rx="1.5"/><path d="M2.5 12H12.5L7.5 4Z"/><rect x="16" y="5.5" width="14" height="3" rx="1.5"/><rect x="16" y="14.5" width="10" height="3" rx="1.5"/><rect x="16" y="23.5" width="6" height="3" rx="1.5"/></svg>';
			var paintSortDir = function () {
				var asc = ( AB.sortOrder === 'ASC' );
				if ( AB.sortBy === 'title' ) { dir.innerHTML = asc ? ICON_SORT_ASC : ICON_SORT_DESC; }
				else { dir.innerHTML = asc ? ICON_AMOUNT_ASC : ICON_AMOUNT_DESC; }
			};
			dir.setAttribute( 'data-order', AB.sortOrder );
			dir.classList.toggle( 'is-asc', AB.sortOrder === 'ASC' );
			paintSortDir();
			dir.addEventListener( 'click', function () {
				AB.sortOrder = ( AB.sortOrder === 'ASC' ) ? 'DESC' : 'ASC';
				dir.setAttribute( 'data-order', AB.sortOrder );
				dir.classList.toggle( 'is-asc', AB.sortOrder === 'ASC' );
				paintSortDir();
				if ( isGrid() ) { applySort( true ); }
				else { try { localStorage.setItem( 'admbudMmSortOrder', AB.sortOrder ); } catch ( e ) {} applySortListMode(); }
			} );
		}

		var toggleAll = document.getElementById( 'admbud-mm-toggle-all' );
		if ( toggleAll ) {
			toggleAll.classList.toggle( 'is-collapsed', ! AB.expanded );
			toggleAll.addEventListener( 'click', function () {
				AB.expanded = ! AB.expanded;
				renderAllTrees();
				toggleAll.classList.toggle( 'is-collapsed', ! AB.expanded );
				var lbl = AB.expanded ? ( i18n.collapseAll || 'Collapse all folders' ) : ( i18n.expandAll || 'Expand all folders' );
				toggleAll.setAttribute( 'aria-label', lbl );
				toggleAll.setAttribute( 'title', lbl );
			} );
		}
	}

	function initPanel() {
		var ul = document.getElementById( 'admbud-mm-tree' );
		if ( ul ) { renderTree( ul ); }
		themePanel();
		initToolbar();
		var newBtn = document.getElementById( 'admbud-mm-new' );
		if ( newBtn ) { newBtn.addEventListener( 'click', function () { startCreate( newFolderParent() ); } ); }

		positionPanel();
		window.addEventListener( 'resize', positionPanel );
		window.addEventListener( 'scroll', positionPanel, { passive: true } );
		var collapseMenu = document.getElementById( 'collapse-button' );
		if ( collapseMenu ) { collapseMenu.addEventListener( 'click', function () { setTimeout( positionPanel, 300 ); } ); }

		var collapse = document.getElementById( 'admbud-mm-collapse' );
		var reopen   = document.getElementById( 'admbud-mm-reopen' );
		var KEY = 'admbudMmCollapsed';
		if ( localStorage.getItem( KEY ) === '1' ) { document.body.classList.add( 'admbud-mm-collapsed' ); }
		if ( collapse ) { collapse.addEventListener( 'click', function () { document.body.classList.add( 'admbud-mm-collapsed' ); localStorage.setItem( KEY, '1' ); } ); }
		if ( reopen )   { reopen.addEventListener( 'click', function () { document.body.classList.remove( 'admbud-mm-collapsed' ); localStorage.setItem( KEY, '0' ); } ); }

		// File-count visibility: a body class hides every .admbud-mm-row__count via CSS.
		// Initial state from the saved pref; the settings toggle flips it live (persist
		// is handled by tab-media-manager.js savePref).
		document.body.classList.toggle( 'admbud-mm-hide-count', ! cfg.showCount );
		var countCb = document.getElementById( 'ab-mm-show-count' );
		if ( countCb ) {
			countCb.addEventListener( 'change', function () { document.body.classList.toggle( 'admbud-mm-hide-count', ! countCb.checked ); } );
		}

		initSlidePanels();

		// Prime the grid-overlay observer right away so the contextual Trash/
		// Delete/Restore buttons appear on every card as soon as wp.media mounts
		// the grid - regardless of which folder the URL places us in.
		updateGridCardActions();
	}
	function newFolderParent() {
		return ( AB.folder === '__all__' || AB.folder === '__uncat__' ) ? 0 : AB.folder;
	}

	// -- Modal in-frame sidebar -----------------------------------------------
	function buildModalSidebar() {
		var aside = document.createElement( 'div' );
		aside.className = 'admbud-mm-sidebar';
		var head = document.createElement( 'div' );
		head.className = 'admbud-mm-sidebar__head';
		head.innerHTML = '<span>' + esc( i18n.folders || 'Folders' ) + '</span>';
		var add = document.createElement( 'button' );
		add.type = 'button';
		add.className = 'admbud-mm-sidebar__new';
		add.textContent = '+';
		add.title = i18n.newFolder || 'New Folder';
		add.addEventListener( 'click', function () { startCreate( newFolderParent() ); } );
		head.appendChild( add );
		aside.appendChild( head );
		var ul = document.createElement( 'ul' );
		ul.className = 'admbud-mm-tree';
		renderTree( ul );
		aside.appendChild( ul );
		return aside;
	}
	function injectModal( el, browser ) {
		if ( ! el || ! el.closest( '.media-modal' ) ) { return; }
		lastBrowser = browser || lastBrowser;
		el.classList.add( 'admbud-mm-has-sidebar' );
		if ( el.querySelector( ':scope > .admbud-mm-sidebar' ) ) { return; }
		el.appendChild( buildModalSidebar() );
	}

	// Add a "Replace media" button to the wp.media Attachment Details sidebar
	// (grid-edit modal + insert-modal sidebar). The attachment id is read from the
	// "Edit Image / Edit more details" link's href - a stable source across views.
	// Resolve the ATTACHMENT id for a details view. Use the attachment's own edit
	// link (class edit-attachment) - NOT a generic post=&action=edit match, which
	// also hits the "Uploaded to" parent-post link and yields a non-attachment id.
	// Fall back to the selected/open grid card's data-id.

	if ( window.wp && wp.media && wp.media.view && wp.media.view.AttachmentsBrowser ) {
		var Browser = wp.media.view.AttachmentsBrowser;
		wp.media.view.AttachmentsBrowser = Browser.extend( {
			createToolbar: function () { Browser.prototype.createToolbar.apply( this, arguments ); lastBrowser = this; watchCollection( this ); ensureGridOverlays(); try { injectModal( this.el, this ); } catch ( e ) {} },
			render: function () { var r = Browser.prototype.render.apply( this, arguments ); lastBrowser = this; watchCollection( this ); ensureGridOverlays(); try { injectModal( this.el, this ); } catch ( e ) {} return r; }
		} );
	}

	if ( typeof MutationObserver !== 'undefined' ) {
		var pending = false;
		new MutationObserver( function () {
			if ( pending ) { return; }
			pending = true;
			requestAnimationFrame( function () {
				pending = false;
				document.querySelectorAll( '.media-modal .attachments-browser' ).forEach( function ( el ) {
					if ( ! el.querySelector( ':scope > .admbud-mm-sidebar' ) ) { injectModal( el, lastBrowser ); }
				} );
				// Foolproof modal card overlays: process every attachment card on any
				// DOM change (existing ones early-return), so the modal grid - which
				// the grid-scoped observer doesn't always cover - reliably gets the
				// Replace button as cards render/scroll.
				injectOverlaysNow();
			} );
		} ).observe( document.body || document.documentElement, { childList: true, subtree: true } );
	}

	// -- Boot -----------------------------------------------------------------
	if ( document.readyState !== 'loading' ) { initPanel(); }
	else { document.addEventListener( 'DOMContentLoaded', initPanel ); }
} )();

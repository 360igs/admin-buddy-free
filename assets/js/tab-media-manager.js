/**
 * Admin Buddy - Media Manager panel engine (loaded on the Media Library, upload.php).
 *
 * Drives the folder panel's slide-panels: Bulk SEO, folder-map export/import, and
 * folder settings (the gear panel - default colour, expand-by-default, Media Trash).
 * There is no longer a standalone "Media Manager" settings tab in Admin Buddy; all
 * config moved into the folder panel. Vanilla ES6, no jQuery. Loads after (and
 * depends on) media-library-folders.js, reusing its localized admbudMM global.
 *
 * @global admbudMM (localized) - falls back to hidden inputs if absent.
 */
( function () {
	'use strict';

	var cfg = ( typeof admbudMM !== 'undefined' ) ? admbudMM : {};
	var nonce   = cfg.nonce   || ( document.getElementById( 'ab-mm-nonce' )    || {} ).value;
	var ajaxUrl = cfg.ajaxUrl || ( document.getElementById( 'ab-mm-ajax-url' ) || {} ).value;
	if ( ! nonce || ! ajaxUrl ) { return; }

	function ajax( action, data ) {
		var fd = new FormData();
		fd.append( 'action', action );
		fd.append( 'nonce', nonce );
		Object.keys( data || {} ).forEach( function ( k ) {
			var v = data[ k ];
			if ( Array.isArray( v ) ) {
				v.forEach( function ( i ) { fd.append( k + '[]', i ); } );
			} else if ( v !== null && typeof v === 'object' ) {
				Object.keys( v ).forEach( function ( sub ) {
					Object.keys( v[ sub ] ).forEach( function ( leaf ) {
						fd.append( k + '[' + sub + '][' + leaf + ']', v[ sub ][ leaf ] );
					} );
				} );
			} else {
				fd.append( k, v );
			}
		} );
		return fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd } ).then( function ( r ) { return r.json(); } );
	}

	// Self-contained toast. admin.js's window.showToast is NOT loaded on upload.php
	// (or in the media modal / front-end builders), so we render our own element
	// using the .admbud-mm-toast styles that ship in media-library-folders.css -
	// which IS loaded wherever this script runs. No cross-script dependency.
	function toast( msg, type ) {
		var isError = ( type === 'error' );
		var t = document.createElement( 'div' );
		t.className = 'admbud-mm-toast' + ( isError ? ' admbud-mm-toast--error' : '' );
		t.setAttribute( 'role', isError ? 'alert' : 'status' );
		t.textContent = msg;
		document.body.appendChild( t );
		requestAnimationFrame( function () { t.classList.add( 'is-in' ); } );
		setTimeout( function () {
			t.classList.remove( 'is-in' );
			setTimeout( function () { if ( t.parentNode ) { t.parentNode.removeChild( t ); } }, 250 );
		}, isError ? 5000 : 2800 );
	}


	// -- Folder settings (default colour, expand state, Media Trash) ----------
	// These controls live in the "Folder settings" slide-panel (gear icon) in the
	// upload.php folder panel - formerly the standalone MM settings tab. Each saves
	// on change via admbud_mm_save_prefs. The block no-ops if the elements are absent.
	// Each toggle passes its own confirmation message so the toast tells the user
	// exactly what changed (not a generic "Settings saved.").
	function savePref( key, value, msg ) {
		return ajax( 'admbud_mm_save_prefs', { key: key, value: value } ).then( function ( r ) {
			if ( r && r.success ) {
				toast( msg || 'Settings saved.' );
				// MEDIA_TRASH is read on next request; reload so the Trash
				// pseudo-folder appears/disappears in the native library sidebar.
				// Slightly delayed so the toast is readable before the refresh.
				if ( r.data && r.data.reloadRequired ) {
					setTimeout( function () { window.location.reload(); }, 1100 );
				}
			} else {
				toast( ( r && r.data && r.data.message ) || 'Save failed.', 'error' );
			}
		} );
	}
	var colorOn = document.getElementById( 'ab-mm-color-on' );
	var colorIn = document.getElementById( 'ab-mm-default-color' );
	function saveColor() {
		var on = !! ( colorOn && colorOn.checked );
		savePref( 'admbud_mm_default_color', ( on && colorIn ) ? colorIn.value : '', on ? 'Default folder colour set.' : 'Default folder colour turned off.' );
	}
	if ( colorOn ) {
		colorOn.addEventListener( 'change', function () {
			if ( colorIn ) { colorIn.disabled = ! colorOn.checked; }
			saveColor();
		} );
	}
	if ( colorIn ) { colorIn.addEventListener( 'change', saveColor ); }
	var expandCb = document.getElementById( 'ab-mm-default-expanded' );
	if ( expandCb ) {
		expandCb.addEventListener( 'change', function () {
			savePref( 'admbud_mm_default_expanded', expandCb.checked ? '1' : '0', expandCb.checked ? 'Folders will expand by default.' : 'Folders will stay collapsed by default.' );
		} );
	}
	var trashCb = document.getElementById( 'ab-mm-trash-enabled' );
	if ( trashCb ) {
		trashCb.addEventListener( 'change', function () {
			savePref( 'admbud_mm_trash_enabled', trashCb.checked ? '1' : '0', ( trashCb.checked ? 'Media Trash enabled.' : 'Media Trash disabled.' ) + ' Refreshing…' );
		} );
	}
	// Show file counts: persist here; media-library-folders.js applies it live.
	var countCb = document.getElementById( 'ab-mm-show-count' );
	if ( countCb ) {
		countCb.addEventListener( 'change', function () {
			savePref( 'admbud_mm_show_count', countCb.checked ? '1' : '0', countCb.checked ? 'File counts shown.' : 'File counts hidden.' );
		} );
	}
	// Show subfolder contents (recursive view). Server returns reloadRequired so the
	// grid (server-side query) and the tree counts switch modes together.
	var recurseCb = document.getElementById( 'ab-mm-recursive-view' );
	if ( recurseCb ) {
		recurseCb.addEventListener( 'change', function () {
			savePref( 'admbud_mm_recursive_view', recurseCb.checked ? '1' : '0', ( recurseCb.checked ? 'Showing subfolder contents.' : 'Showing folder contents only.' ) + ' Refreshing…' );
		} );
	}

} )();

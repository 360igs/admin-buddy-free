/**
 * Admin Buddy - ab-dropdown.js
 *
 * Standalone dropdown handler. Included here for tabs that load it
 * independently of admin.js. If admin.js is already loaded this module
 * is a no-op (guard at top).
 *
 * @version 1.1.0-beta3
 * @package Admbud
 */

( function () {
	'use strict';

	// admin.js already registers this; skip if present.
	if ( window._abDropdownInit ) {
		return;
	}

	window._abDropdownInit = true;

	document.addEventListener( 'click', function ( e ) {
		const trigger = e.target.closest( '[data-ab-dropdown]' );

		document.querySelectorAll( '.ab-dropdown.is-open' ).forEach( function ( open ) {
			if ( ! open.contains( trigger ) ) {
				open.classList.remove( 'is-open' );
			}
		} );

		if ( ! trigger ) {
			return;
		}

		e.stopPropagation();

		const dropdown = trigger.closest( '.ab-dropdown' );

		if ( dropdown ) {
			dropdown.classList.toggle( 'is-open' );
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key !== 'Escape' ) {
			return;
		}

		document.querySelectorAll( '.ab-dropdown.is-open' ).forEach( function ( open ) {
			open.classList.remove( 'is-open' );
		} );
	} );

} )();

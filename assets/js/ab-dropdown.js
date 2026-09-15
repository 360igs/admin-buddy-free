/**
 * Admin Buddy - ab-dropdown.js
 *
 * Two patterns share `.ab-dropdown`:
 *
 *   1. Action menu (legacy). Triggered by any element with the
 *      `[data-ab-dropdown]` attribute; clicking toggles `.is-open` on the
 *      ancestor `.ab-dropdown`. The accompanying CSS animates the menu in
 *      via opacity + translateY. Used e.g. for the Activity Log export
 *      menu.
 *
 *   2. Styled-select (ported from Ecom Buddy). A
 *      `<button class="ab-dropdown__trigger">` styled like an input, with
 *      a `.ab-dropdown__caret` chevron, opening a `.ab-dropdown__menu` of
 *      `.ab-dropdown__option`s. The form-submitted value lives in a
 *      sibling `<input data-ab-dropdown-input>` hidden field — option
 *      clicks set the hidden input's value and dispatch a `change` event
 *      so any existing change-delegation handlers (dirty-tracker,
 *      conditional UI, etc.) react the same way they would for a real
 *      `<select>`. Render this markup directly in PHP, mirroring EB's
 *      `render-tab-rfq-settings.php` pattern.
 *
 * @version 1.1.0-beta4
 * @package Admbud
 */

( function () {
	'use strict';

	if ( window._abDropdownInit ) {
		return;
	}
	window._abDropdownInit = true;

	// ----- Pattern 1: Action menu (`[data-ab-dropdown]` toggle) ------------

	document.addEventListener( 'click', function ( e ) {
		var trigger = e.target.closest( '[data-ab-dropdown]' );

		// Close other open action-menu dropdowns when clicking outside them.
		document.querySelectorAll( '.ab-dropdown.is-open' ).forEach( function ( open ) {
			if ( open.contains( trigger ) ) { return; }
			// Don't close styled-select dropdowns here — Pattern 2 handles
			// those below with finer-grained logic (option clicks, etc.).
			if ( open.querySelector( '.ab-dropdown__trigger' ) ) { return; }
			open.classList.remove( 'is-open' );
		} );

		if ( ! trigger ) {
			return;
		}

		e.stopPropagation();
		var dropdown = trigger.closest( '.ab-dropdown' );
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

	// ----- Pattern 2: Styled-select (open/close/position/select) ------------
	//
	// Ported from Ecom Buddy's shell.js. Menu uses fixed positioning anchored
	// to the trigger via getBoundingClientRect() so it escapes any
	// `overflow: hidden` parents (sections, scroll containers, modals).

	function abDropdownSetValue( dd, value, label ) {
		// Mirrors EB's `ecobudDropdownSetValue` 1:1. Storage backend is the
		// `<input data-ab-dropdown-input>` hidden field that lives next to
		// the trigger — the form submits THAT, not the trigger button. We
		// also dispatch a `change` event on the hidden input so any
		// existing change-delegation handlers (dirty-tracker, conditional
		// UI, syncDashboardRolePages, etc.) react the same way they would
		// for a native <select>.
		var hidden  = dd.querySelector( '[data-ab-dropdown-input]' );
		var valueEl = dd.querySelector( '.ab-dropdown__value' );
		if ( hidden ) {
			hidden.value = value;
			hidden.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
		if ( valueEl ) {
			valueEl.textContent = label;
		}
		var opts = dd.querySelectorAll( '.ab-dropdown__option' );
		Array.prototype.forEach.call( opts, function ( opt ) {
			if ( opt.getAttribute( 'data-value' ) === value ) {
				opt.classList.add( 'is-selected' );
				opt.setAttribute( 'aria-selected', 'true' );
			} else {
				opt.classList.remove( 'is-selected' );
				opt.setAttribute( 'aria-selected', 'false' );
			}
		} );
	}

	var abDdIdSeq = 0;

	function abDropdownOptions( dd ) {
		return Array.prototype.slice.call( dd.querySelectorAll( '.ab-dropdown__option' ) );
	}

	function abDropdownSetActive( dd, opt ) {
		// Keyboard "active" option: highlighted but not yet committed.
		// `.is-highlighted` shares the :hover rule in admin.css, and
		// aria-activedescendant on the trigger tells AT which option has
		// the virtual focus (real focus stays on the trigger button).
		var trigger = dd.querySelector( '.ab-dropdown__trigger' );
		abDropdownOptions( dd ).forEach( function ( o ) {
			o.classList.remove( 'is-highlighted' );
		} );
		if ( ! opt ) {
			if ( trigger ) { trigger.removeAttribute( 'aria-activedescendant' ); }
			return;
		}
		opt.classList.add( 'is-highlighted' );
		if ( ! opt.id ) {
			abDdIdSeq += 1;
			opt.id = 'ab-dd-option-' + abDdIdSeq;
		}
		if ( trigger ) { trigger.setAttribute( 'aria-activedescendant', opt.id ); }
		if ( opt.scrollIntoView ) {
			opt.scrollIntoView( { block: 'nearest' } );
		}
	}

	function abDropdownActiveOption( dd ) {
		return dd.querySelector( '.ab-dropdown__option.is-highlighted' );
	}

	function abDropdownClose( dd ) {
		dd.classList.remove( 'is-open' );
		var trigger = dd.querySelector( '.ab-dropdown__trigger' );
		var menu    = dd.querySelector( '.ab-dropdown__menu' );
		if ( trigger ) {
			trigger.setAttribute( 'aria-expanded', 'false' );
		}
		abDropdownSetActive( dd, null );
		if ( menu ) {
			menu.hidden       = true;
			menu.style.top    = '';
			menu.style.bottom = '';
			menu.style.left   = '';
			menu.style.width  = '';
		}
		if ( dd._abReposition ) {
			window.removeEventListener( 'scroll', dd._abReposition, true );
			window.removeEventListener( 'resize', dd._abReposition );
			dd._abReposition = null;
		}
	}

	function abDropdownPositionMenu( dd ) {
		var trigger = dd.querySelector( '.ab-dropdown__trigger' );
		var menu    = dd.querySelector( '.ab-dropdown__menu' );
		if ( ! trigger || ! menu ) { return; }
		var rect       = trigger.getBoundingClientRect();
		var menuMaxH   = 260;
		var spaceBelow = window.innerHeight - rect.bottom;
		var openUp     = spaceBelow < Math.min( menuMaxH, 200 ) && rect.top > spaceBelow;
		menu.style.left  = rect.left + 'px';
		menu.style.width = rect.width + 'px';
		if ( openUp ) {
			menu.style.top    = '';
			menu.style.bottom = ( window.innerHeight - rect.top + 4 ) + 'px';
		} else {
			menu.style.bottom = '';
			menu.style.top    = ( rect.bottom + 4 ) + 'px';
		}
	}

	function abDropdownOpen( dd ) {
		// Close any other open styled-select first — only one popover at a time.
		var others = document.querySelectorAll( '.ab-dropdown.is-open' );
		Array.prototype.forEach.call( others, function ( other ) {
			if ( other === dd ) { return; }
			if ( ! other.querySelector( '.ab-dropdown__trigger' ) ) { return; } // skip action menus
			abDropdownClose( other );
		} );

		dd.classList.add( 'is-open' );
		var trigger = dd.querySelector( '.ab-dropdown__trigger' );
		var menu    = dd.querySelector( '.ab-dropdown__menu' );
		if ( trigger ) {
			trigger.setAttribute( 'aria-expanded', 'true' );
		}
		if ( menu ) {
			menu.hidden = false;
		}

		abDropdownPositionMenu( dd );
		dd._abReposition = function () { abDropdownPositionMenu( dd ); };
		window.addEventListener( 'scroll', dd._abReposition, true );
		window.addEventListener( 'resize', dd._abReposition );

		// Start keyboard navigation from the current selection (or the
		// first option) so ArrowDown/typeahead has an anchor.
		abDropdownSetActive(
			dd,
			dd.querySelector( '.ab-dropdown__option.is-selected' ) || abDropdownOptions( dd )[0] || null
		);
	}

	function abDropdownCommit( dd, opt ) {
		abDropdownSetValue( dd, opt.getAttribute( 'data-value' ) || '', opt.textContent.trim() );
		abDropdownClose( dd );
		var trigger = dd.querySelector( '.ab-dropdown__trigger' );
		if ( trigger ) { trigger.focus(); }
	}

	document.addEventListener( 'click', function ( e ) {
		var trigger = e.target.closest( '.ab-dropdown__trigger' );
		if ( trigger ) {
			var dd = trigger.closest( '.ab-dropdown' );
			if ( ! dd ) { return; }
			if ( dd.classList.contains( 'is-open' ) ) {
				abDropdownClose( dd );
			} else {
				abDropdownOpen( dd );
			}
			return;
		}

		var opt = e.target.closest( '.ab-dropdown__option' );
		if ( opt ) {
			var dd2 = opt.closest( '.ab-dropdown' );
			if ( ! dd2 ) { return; }
			abDropdownCommit( dd2, opt );
			return;
		}

		// Click outside any styled-select — close all open ones.
		if ( ! e.target.closest( '.ab-dropdown' ) ) {
			document.querySelectorAll( '.ab-dropdown.is-open' ).forEach( function ( dd3 ) {
				if ( ! dd3.querySelector( '.ab-dropdown__trigger' ) ) { return; }
				abDropdownClose( dd3 );
			} );
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key !== 'Escape' ) { return; }
		var open = document.querySelector( '.ab-dropdown.is-open' );
		if ( ! open ) { return; }
		if ( ! open.querySelector( '.ab-dropdown__trigger' ) ) { return; }
		abDropdownClose( open );
		var trigger = open.querySelector( '.ab-dropdown__trigger' );
		if ( trigger ) { trigger.focus(); }
	} );

	// Keyboard operation for the styled-select (WAI-ARIA listbox pattern).
	// Focus stays on the trigger button; the "active" option is virtual
	// (is-highlighted + aria-activedescendant). Enter/Space on a CLOSED
	// trigger are left to the browser: a <button> fires click natively,
	// which the click handler above turns into open.
	document.addEventListener( 'keydown', function ( e ) {
		var trigger = e.target.closest( '.ab-dropdown__trigger' );
		if ( ! trigger ) { return; }
		var dd = trigger.closest( '.ab-dropdown' );
		if ( ! dd ) { return; }
		var isOpen = dd.classList.contains( 'is-open' );
		var opts, idx, active;

		if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Home' || e.key === 'End' ) {
			e.preventDefault();
			if ( ! isOpen ) {
				abDropdownOpen( dd );
				if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) { return; } // open at current selection
			}
			opts = abDropdownOptions( dd );
			if ( ! opts.length ) { return; }
			active = abDropdownActiveOption( dd );
			idx    = opts.indexOf( active );
			if ( e.key === 'Home' )           { idx = 0; }
			else if ( e.key === 'End' )       { idx = opts.length - 1; }
			else if ( e.key === 'ArrowDown' ) { idx = Math.min( opts.length - 1, idx + 1 ); }
			else                              { idx = Math.max( 0, idx - 1 ); }
			abDropdownSetActive( dd, opts[ idx ] );
			return;
		}

		if ( ( e.key === 'Enter' || e.key === ' ' ) && isOpen ) {
			e.preventDefault(); // suppress the native button click (would re-toggle)
			active = abDropdownActiveOption( dd );
			if ( active ) { abDropdownCommit( dd, active ); }
			return;
		}

		if ( e.key === 'Tab' && isOpen ) {
			abDropdownClose( dd ); // let focus move on naturally
			return;
		}

		// Typeahead: jump the active option to the first label starting
		// with the typed prefix. Buffer resets after a short pause.
		if ( isOpen && e.key.length === 1 && ! e.ctrlKey && ! e.altKey && ! e.metaKey ) {
			e.preventDefault();
			dd._abTypeBuf = ( dd._abTypeBuf || '' ) + e.key.toLowerCase();
			clearTimeout( dd._abTypeTimer );
			dd._abTypeTimer = setTimeout( function () { dd._abTypeBuf = ''; }, 600 );
			opts = abDropdownOptions( dd );
			for ( idx = 0; idx < opts.length; idx++ ) {
				if ( opts[ idx ].textContent.trim().toLowerCase().indexOf( dd._abTypeBuf ) === 0 ) {
					abDropdownSetActive( dd, opts[ idx ] );
					break;
				}
			}
		}
	} );

} )();

/**
 * Admin Buddy — ab-datepicker.js
 *
 * Brand-themed date picker. Auto-inits any `.ab-datepicker` element on
 * DOMContentLoaded. Companion CSS in assets/ab-datepicker.css. Markup
 * contract is documented at the top of that file.
 *
 * Form integration: the hidden `<input data-ab-datepicker-input>` carries
 * the canonical YYYY-MM-DD value. Selecting a day writes through to the
 * input and dispatches a bubbling `change` event so existing change-
 * delegation handlers (filter listeners, dirty-tracker, conditional UI)
 * work exactly as they do for a native <input type="date">.
 *
 * Locale: weekday + month labels via `Intl.DateTimeFormat` using
 * `<html lang>` or `navigator.language`. First-day-of-week defaults to
 * Monday (most common globally); override per-instance with
 * `data-first-day="0"` for Sunday-first.
 *
 * Public API (window.admbudDatepicker):
 *   attach(el)      — init a single .ab-datepicker (idempotent)
 *   attachAll(root) — scan root (default document) for .ab-datepicker
 *   setValue(el, 'YYYY-MM-DD' | '')
 *   getValue(el)    — returns current YYYY-MM-DD or ''
 *   open(el) / close(el)
 *
 * @package Admbud
 */

( function () {
    'use strict';

    if ( window._admbudDatepickerInit ) { return; }
    window._admbudDatepickerInit = true;

    var ISO_RE = /^\d{4}-\d{2}-\d{2}$/;

    function pad( n )   { return n < 10 ? '0' + n : '' + n; }
    function iso( d )   { return d.getFullYear() + '-' + pad( d.getMonth() + 1 ) + '-' + pad( d.getDate() ); }
    function parseIso( s ) {
        if ( ! s || ! ISO_RE.test( s ) ) { return null; }
        var parts = s.split( '-' );
        var dt = new Date( +parts[0], +parts[1] - 1, +parts[2] );
        return isNaN( dt.getTime() ) ? null : dt;
    }

    function getLocale() {
        return ( document.documentElement.lang || navigator.language || 'en-US' ).replace( '_', '-' );
    }

    function monthLabel( y, m ) {
        try {
            return new Intl.DateTimeFormat( getLocale(), { month: 'long', year: 'numeric' } )
                .format( new Date( y, m, 1 ) );
        } catch ( e ) {
            return ( m + 1 ) + '/' + y;
        }
    }

    function formatTriggerValue( dt ) {
        try {
            return new Intl.DateTimeFormat( getLocale(), { dateStyle: 'medium' } ).format( dt );
        } catch ( e ) {
            return iso( dt );
        }
    }

    function weekdayLabels( firstDay ) {
        var locale = getLocale();
        var labels = [];
        // 2024-01-07 was a Sunday; offset from there.
        var base = new Date( 2024, 0, 7 );
        for ( var i = 0; i < 7; i++ ) {
            var d = new Date( base );
            d.setDate( base.getDate() + ( ( i + firstDay ) % 7 ) );
            try {
                labels.push( new Intl.DateTimeFormat( locale, { weekday: 'narrow' } ).format( d ) );
            } catch ( e ) {
                labels.push( [ 'S', 'M', 'T', 'W', 'T', 'F', 'S' ][ ( i + firstDay ) % 7 ] );
            }
        }
        return labels;
    }

    function readOpts( dp ) {
        return {
            min:      dp.dataset.min      || '',
            max:      dp.dataset.max      || '',
            firstDay: parseInt( dp.dataset.firstDay || '1', 10 ) || 0,
        };
    }

    function getValue( dp ) {
        var hidden = dp.querySelector( '[data-ab-datepicker-input]' );
        return hidden ? hidden.value : '';
    }

    function setValue( dp, value, opts ) {
        opts = opts || readOpts( dp );
        var hidden  = dp.querySelector( '[data-ab-datepicker-input]' );
        var valueEl = dp.querySelector( '.ab-datepicker__value' );
        var newVal  = value || '';
        if ( hidden ) {
            var prev = hidden.value;
            hidden.value = newVal;
            if ( prev !== newVal ) {
                hidden.dispatchEvent( new Event( 'change',  { bubbles: true } ) );
                hidden.dispatchEvent( new Event( 'input',   { bubbles: true } ) );
            }
        }
        if ( valueEl ) {
            var dt          = parseIso( newVal );
            var placeholder = valueEl.dataset.abDatepickerPlaceholder || '';
            if ( dt ) {
                valueEl.textContent = formatTriggerValue( dt );
                dp.classList.add( 'is-filled' );
            } else {
                valueEl.textContent = placeholder;
                dp.classList.remove( 'is-filled' );
            }
        }
    }

    // -- Popover construction -------------------------------------------------

    function buildPopover() {
        var pop = document.createElement( 'div' );
        pop.className = 'ab-datepicker__popover';
        pop.setAttribute( 'role', 'dialog' );
        pop.innerHTML =
            '<div class="ab-datepicker__header">'
            +   '<button type="button" class="ab-datepicker__nav" data-ab-dp-prev aria-label="Previous month">'
            +     '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>'
            +   '</button>'
            +   '<div class="ab-datepicker__month-label" data-ab-dp-month></div>'
            +   '<button type="button" class="ab-datepicker__nav" data-ab-dp-next aria-label="Next month">'
            +     '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>'
            +   '</button>'
            + '</div>'
            + '<div class="ab-datepicker__weekdays" aria-hidden="true"></div>'
            + '<div class="ab-datepicker__grid" role="grid"></div>'
            + '<div class="ab-datepicker__footer">'
            +   '<button type="button" class="ab-datepicker__action" data-ab-dp-today></button>'
            +   '<button type="button" class="ab-datepicker__action" data-ab-dp-clear></button>'
            + '</div>';
        return pop;
    }

    function renderMonth( dp, pop, viewY, viewM, opts ) {
        pop._viewY = viewY;
        pop._viewM = viewM;

        pop.querySelector( '[data-ab-dp-month]' ).textContent = monthLabel( viewY, viewM );

        // Weekday header.
        var wdRow = pop.querySelector( '.ab-datepicker__weekdays' );
        wdRow.innerHTML = '';
        weekdayLabels( opts.firstDay ).forEach( function ( l ) {
            var cell = document.createElement( 'span' );
            cell.className   = 'ab-datepicker__weekday';
            cell.textContent = l;
            wdRow.appendChild( cell );
        } );

        // Day grid — 6 weeks (42 cells), always, so popover height is stable.
        var grid = pop.querySelector( '.ab-datepicker__grid' );
        grid.innerHTML = '';

        var first      = new Date( viewY, viewM, 1 );
        var firstDow   = first.getDay();
        var leadOffset = ( firstDow - opts.firstDay + 7 ) % 7;
        var start      = new Date( viewY, viewM, 1 - leadOffset );

        var selDt    = parseIso( getValue( dp ) );
        var selIso   = selDt ? iso( selDt ) : '';
        var todayIso = iso( new Date() );
        var min      = parseIso( opts.min );
        var max      = parseIso( opts.max );

        for ( var i = 0; i < 42; i++ ) {
            var d = new Date( start );
            d.setDate( start.getDate() + i );

            var cell = document.createElement( 'button' );
            cell.type        = 'button';
            cell.className   = 'ab-datepicker__day';
            cell.textContent = d.getDate();
            cell.dataset.value = iso( d );
            cell.setAttribute( 'role', 'gridcell' );

            if ( d.getMonth() !== viewM ) { cell.classList.add( 'is-outside' ); }
            if ( iso( d ) === todayIso  ) { cell.classList.add( 'is-today'  ); }
            if ( selIso && iso( d ) === selIso ) {
                cell.classList.add( 'is-selected' );
                cell.setAttribute( 'aria-selected', 'true' );
            }
            if ( ( min && d < min ) || ( max && d > max ) ) {
                cell.disabled = true;
                cell.classList.add( 'is-disabled' );
            }
            grid.appendChild( cell );
        }
    }

    function positionPopover( dp, pop ) {
        var trigger = dp.querySelector( '.ab-datepicker__trigger' );
        if ( ! trigger ) { return; }
        var rect       = trigger.getBoundingClientRect();
        var popH       = pop.offsetHeight || 320;
        var spaceBelow = window.innerHeight - rect.bottom;
        var openUp     = spaceBelow < popH + 8 && rect.top > spaceBelow;
        pop.style.left = Math.max( 8, rect.left ) + 'px';
        if ( openUp ) {
            pop.style.top    = '';
            pop.style.bottom = ( window.innerHeight - rect.top + 4 ) + 'px';
        } else {
            pop.style.bottom = '';
            pop.style.top    = ( rect.bottom + 4 ) + 'px';
        }
    }

    // -- Open / close ---------------------------------------------------------

    function closeAll() {
        document.querySelectorAll( '.ab-datepicker.is-open' ).forEach( close );
    }

    function close( dp ) {
        var pop = dp._abPop;
        if ( pop && pop.parentNode ) { pop.parentNode.removeChild( pop ); }
        dp._abPop = null;
        dp.classList.remove( 'is-open' );
        var trigger = dp.querySelector( '.ab-datepicker__trigger' );
        if ( trigger ) { trigger.setAttribute( 'aria-expanded', 'false' ); }
        if ( dp._abReposition ) {
            window.removeEventListener( 'scroll', dp._abReposition, true );
            window.removeEventListener( 'resize', dp._abReposition );
            dp._abReposition = null;
        }
    }

    function open( dp ) {
        if ( dp.classList.contains( 'is-open' ) ) { return; }
        closeAll();

        var opts = readOpts( dp );
        var pop  = buildPopover();
        document.body.appendChild( pop );
        dp._abPop = pop;
        dp.classList.add( 'is-open' );

        var trigger = dp.querySelector( '.ab-datepicker__trigger' );
        if ( trigger ) { trigger.setAttribute( 'aria-expanded', 'true' ); }

        var cur = parseIso( getValue( dp ) ) || new Date();
        renderMonth( dp, pop, cur.getFullYear(), cur.getMonth(), opts );

        // Footer labels — localised via aria/title where possible. Plain
        // text content is hardcoded English here; PHP-side localisation
        // can override by setting data-today / data-clear on the .ab-datepicker.
        pop.querySelector( '[data-ab-dp-today]' ).textContent = dp.dataset.todayLabel || 'Today';
        pop.querySelector( '[data-ab-dp-clear]' ).textContent = dp.dataset.clearLabel || 'Clear';

        // Wire popover-internal events.
        pop.querySelector( '[data-ab-dp-prev]' ).addEventListener( 'click', function () {
            var m = pop._viewM - 1;
            var y = m < 0 ? pop._viewY - 1 : pop._viewY;
            renderMonth( dp, pop, y, ( m + 12 ) % 12, opts );
        } );
        pop.querySelector( '[data-ab-dp-next]' ).addEventListener( 'click', function () {
            var m = pop._viewM + 1;
            var y = m > 11 ? pop._viewY + 1 : pop._viewY;
            renderMonth( dp, pop, y, m % 12, opts );
        } );
        pop.querySelector( '[data-ab-dp-today]' ).addEventListener( 'click', function () {
            setValue( dp, iso( new Date() ), opts );
            close( dp );
            if ( trigger ) { trigger.focus(); }
        } );
        pop.querySelector( '[data-ab-dp-clear]' ).addEventListener( 'click', function () {
            setValue( dp, '', opts );
            close( dp );
            if ( trigger ) { trigger.focus(); }
        } );
        pop.addEventListener( 'click', function ( e ) {
            var day = e.target.closest( '.ab-datepicker__day' );
            if ( ! day || day.disabled ) { return; }
            setValue( dp, day.dataset.value, opts );
            close( dp );
            if ( trigger ) { trigger.focus(); }
        } );

        positionPopover( dp, pop );
        dp._abReposition = function () { positionPopover( dp, pop ); };
        window.addEventListener( 'scroll', dp._abReposition, true );
        window.addEventListener( 'resize', dp._abReposition );
    }

    // -- Init / event delegation ---------------------------------------------

    function attach( dp ) {
        if ( ! dp || dp._abInit ) { return; }
        dp._abInit = true;
        // Render placeholder / formatted value for whatever the hidden
        // input already carries server-side.
        var hidden = dp.querySelector( '[data-ab-datepicker-input]' );
        setValue( dp, hidden ? hidden.value : '', readOpts( dp ) );
    }

    function attachAll( root ) {
        ( root || document ).querySelectorAll( '.ab-datepicker' ).forEach( attach );
    }

    document.addEventListener( 'click', function ( e ) {
        var trigger = e.target.closest( '.ab-datepicker__trigger' );
        if ( trigger ) {
            var dp = trigger.closest( '.ab-datepicker' );
            if ( ! dp ) { return; }
            if ( dp.classList.contains( 'is-open' ) ) { close( dp ); }
            else                                     { open(  dp ); }
            return;
        }
        // Clicks INSIDE a popover are handled by per-popover listeners; ignore them here.
        if ( e.target.closest( '.ab-datepicker__popover' ) ) { return; }
        // Clicks anywhere else close all open popovers.
        closeAll();
    } );

    document.addEventListener( 'keydown', function ( e ) {
        if ( e.key !== 'Escape' ) { return; }
        var openDp = document.querySelector( '.ab-datepicker.is-open' );
        if ( ! openDp ) { return; }
        close( openDp );
        var trigger = openDp.querySelector( '.ab-datepicker__trigger' );
        if ( trigger ) { trigger.focus(); }
    } );

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', function () { attachAll(); } );
    } else {
        attachAll();
    }

    window.admbudDatepicker = {
        attach:    attach,
        attachAll: attachAll,
        getValue:  getValue,
        setValue:  function ( dp, v ) { setValue( dp, v, readOpts( dp ) ); },
        open:      open,
        close:     close,
    };

} )();

/**
 * Admin Buddy - tab-quick-settings.js
 * Quick Settings tab. Vanilla ES6+. No jQuery.
 *
 * Covers:
 *   - Per-toggle AJAX save (serialised on a shared promise chain)
 *   - Per-toggle role flyout
 *   - Bulk Enable/Disable with confirm modal
 *   - TOC highlight via scroll + click
 *   - Auto-enabled-message toast surviving a debounced reload
 */
( function () {
    'use strict';

    var data    = window.admbudQs    || {};
    var nonce   = data.nonce         || '';
    var ajaxUrl = data.ajaxUrl       || '';
    var i18n    = data.i18n          || {};

    var spinner    = document.getElementById( 'ab-qs-spinner' );
    var enableBtn  = document.getElementById( 'ab-qs-enable-all' );
    var disableBtn = document.getElementById( 'ab-qs-disable-all' );

    if ( ! spinner && ! enableBtn && ! disableBtn ) {
        // Not on the QS tab; bail.
        return;
    }

    // After auto-enabling Sidebar User Menu (triggered server-side when the
    // user turns on Hide Admin Bar (Backend)), the page reloads via
    // admbudReloadAfterAll. We set a sessionStorage flag before reload and
    // consume it here so the toast survives the reload.
    ( function showPendingAutoToast() {
        var msg = sessionStorage.getItem( 'admbud_qs_auto_enabled_msg' );
        if ( ! msg ) { return; }
        sessionStorage.removeItem( 'admbud_qs_auto_enabled_msg' );
        function fire() {
            if ( typeof window.showToast === 'function' ) {
                window.showToast( msg, 'success' );
            }
        }
        if ( document.readyState === 'loading' ) {
            document.addEventListener( 'DOMContentLoaded', fire );
        } else {
            fire();
        }
    } )();

    function setSpinner( on ) {
        if ( spinner ) { spinner.classList.toggle( 'ab-hidden', ! on ); }
    }

    function setBusy( on ) {
        setSpinner( on );
        document.querySelectorAll( '.ab-qs-checkbox, #ab-qs-enable-all, #ab-qs-disable-all' ).forEach( function ( el ) {
            el.disabled = on;
        } );
    }

    function updateBulkState() {
        var boxes  = Array.from( document.querySelectorAll( '.ab-qs-checkbox' ) );
        var anyOn  = boxes.some( function ( c ) { return c.checked; } );
        var anyOff = boxes.some( function ( c ) { return ! c.checked; } );
        if ( enableBtn )  { enableBtn.disabled  = ! anyOff; }
        if ( disableBtn ) { disableBtn.disabled = ! anyOn;  }
    }

    function ajaxPost( action, payload, done ) {
        var fd = new FormData();
        fd.append( 'action', action );
        fd.append( 'nonce',  nonce );
        Object.keys( payload ).forEach( function ( k ) { fd.append( k, payload[ k ] ); } );
        return fetch( ajaxUrl, { method: 'POST', body: fd } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( res ) {
                if ( ! res.success ) { console.warn( 'admbud_qs:', res ); }
                if ( done ) { done( res ); }
                return res;
            } )
            .catch( function ( e ) {
                console.error( 'admbud_qs error:', e );
                if ( done ) { done( null ); }
                return null;
            } );
    }

    // Individual toggle - fires on change.
    // Requests are serialised on a shared promise chain so rapid clicks
    // queue in order instead of firing concurrently. Matches the pattern
    // used by Setup -> Modules.
    var qsChain = Promise.resolve();

    document.querySelectorAll( '.ab-qs-checkbox' ).forEach( function ( chk ) {
        chk.addEventListener( 'change', function () {
            var key = this.dataset.key;
            var val = this.checked ? '1' : '0';
            var msg = val === '1' ? ( i18n.enabled || 'Setting enabled.' ) : ( i18n.disabled || 'Setting disabled.' );

            var rolesEl = document.querySelector( '.ab-qs-roles[data-roles-for="' + key + '"]' );
            if ( rolesEl ) { rolesEl.classList.toggle( 'ab-hidden', val !== '1' ); }

            var myPromise = qsChain.then( function () {
                return ajaxPost( 'admbud_qs_toggle', { key: key, value: val } );
            } );
            qsChain = myPromise.catch( function () {} );

            if ( window.admbudReloadAfterAll ) { window.admbudReloadAfterAll.wait( myPromise ); }

            myPromise.then( function ( res ) {
                if ( res && res.success ) {
                    if ( res.data && res.data.auto_enabled === 'admbud_qs_sidebar_user_menu' ) {
                        sessionStorage.setItem(
                            'admbud_qs_auto_enabled_msg',
                            i18n.autoEnabled || 'Sidebar User Menu turned on so you can still navigate.'
                        );
                    }
                    if ( typeof window.showToast === 'function' ) { window.showToast( msg, 'success' ); }
                    if ( window.admbudReloadAfterAll ) { window.admbudReloadAfterAll.arm(); }
                } else {
                    if ( typeof window.showToast === 'function' ) {
                        window.showToast( i18n.failed || 'Failed to save. Please try again.', 'error' );
                    }
                    chk.checked = ! chk.checked;
                    if ( rolesEl ) { rolesEl.classList.toggle( 'ab-hidden', chk.checked ); }
                }
            } ).catch( function () {
                if ( typeof window.showToast === 'function' ) {
                    window.showToast( i18n.failed || 'Failed to save. Please try again.', 'error' );
                }
                chk.checked = ! chk.checked;
                if ( rolesEl ) { rolesEl.classList.toggle( 'ab-hidden', chk.checked ); }
            } );
        } );
    } );

    // Role checkboxes - save roles immediately on change, no reload needed.
    document.querySelectorAll( '.ab-qs-role-checkbox' ).forEach( function ( chk ) {
        chk.addEventListener( 'change', function () {
            var key = this.dataset.key;
            var checked = Array.from(
                document.querySelectorAll( '.ab-qs-role-checkbox[data-key="' + key + '"]:checked' )
            ).map( function ( c ) { return c.value; } );

            // Always keep at least administrator
            if ( checked.length === 0 ) {
                checked = [ 'administrator' ];
                var adminChk = document.querySelector(
                    '.ab-qs-role-checkbox[data-key="' + key + '"][value="administrator"]'
                );
                if ( adminChk ) { adminChk.checked = true; }
            }

            ajaxPost( 'admbud_qs_save_roles', { key: key, roles: checked.join( ',' ) }, function ( res ) {
                if ( typeof window.showToast !== 'function' ) { return; }
                if ( res && res.success ) {
                    window.showToast( i18n.rolesSaved || 'Roles saved.', 'success' );
                } else {
                    window.showToast( i18n.rolesFailed || 'Failed to save roles.', 'error' );
                }
            } );
        } );
    } );

    // Bulk enable - confirm modal.
    if ( enableBtn ) {
        enableBtn.addEventListener( 'click', function () {
            if ( typeof window.openConfirmModal !== 'function' ) { return; }
            window.openConfirmModal(
                i18n.confirmEnableTitle || 'Enable all Quick Settings?',
                i18n.confirmEnableBody  || 'All settings will be turned on.',
                function () {
                    setBusy( true );
                    var p = ajaxPost( 'admbud_qs_bulk', { value: '1' }, function ( res ) {
                        if ( res && res.success ) {
                            if ( typeof window.showToast === 'function' ) {
                                window.showToast( i18n.allEnabled || 'All settings enabled.', 'success' );
                            }
                            if ( window.admbudReloadAfterAll ) { window.admbudReloadAfterAll.arm(); }
                        } else {
                            setBusy( false );
                            if ( typeof window.showToast === 'function' ) {
                                window.showToast( i18n.failed || 'Failed to save. Please try again.', 'error' );
                            }
                        }
                    } );
                    if ( window.admbudReloadAfterAll ) { window.admbudReloadAfterAll.wait( p ); }
                },
                i18n.confirmEnableYes || 'Yes, enable all',
                'ab-btn--primary'
            );
        } );
    }

    // Bulk disable - confirm modal.
    if ( disableBtn ) {
        disableBtn.addEventListener( 'click', function () {
            if ( typeof window.openConfirmModal !== 'function' ) { return; }
            window.openConfirmModal(
                i18n.confirmDisableTitle || 'Disable all Quick Settings?',
                i18n.confirmDisableBody  || 'All settings will be turned off.',
                function () {
                    setBusy( true );
                    var p = ajaxPost( 'admbud_qs_bulk', { value: '0' }, function ( res ) {
                        if ( res && res.success ) {
                            if ( typeof window.showToast === 'function' ) {
                                window.showToast( i18n.allDisabled || 'All settings disabled.', 'success' );
                            }
                            if ( window.admbudReloadAfterAll ) { window.admbudReloadAfterAll.arm(); }
                        } else {
                            setBusy( false );
                            if ( typeof window.showToast === 'function' ) {
                                window.showToast( i18n.failed || 'Failed to save. Please try again.', 'error' );
                            }
                        }
                    } );
                    if ( window.admbudReloadAfterAll ) { window.admbudReloadAfterAll.wait( p ); }
                },
                i18n.confirmDisableYes || 'Yes, disable all',
                'ab-btn--danger'
            );
        } );
    }

    updateBulkState();

    // -- TOC: smooth scroll + active highlight ------------------------------
    var tocLinks = document.querySelectorAll( '#ab-qs-toc .ab-toc__link' );
    tocLinks.forEach( function ( link ) {
        link.addEventListener( 'click', function ( e ) {
            e.preventDefault();
            var target = document.querySelector( this.getAttribute( 'href' ) );
            if ( target ) {
                target.scrollIntoView( { behavior: 'smooth', block: 'start' } );
            }
        } );
    } );

    var sections      = Array.from( document.querySelectorAll( '[id^="ab-qs-section-"]' ) );
    var clickCooldown = false;

    tocLinks.forEach( function ( link ) {
        link.addEventListener( 'click', function () {
            tocLinks.forEach( function ( l ) { l.classList.remove( 'is-active' ); } );
            this.classList.add( 'is-active' );
            clickCooldown = true;
            setTimeout( function () { clickCooldown = false; }, 600 );
        } );
    } );

    function updateTocActive() {
        if ( clickCooldown ) { return; }
        var offset = 140;

        if ( ( window.innerHeight + window.pageYOffset ) >= document.documentElement.scrollHeight - 20 ) {
            tocLinks.forEach( function ( l ) { l.classList.remove( 'is-active' ); } );
            if ( tocLinks.length ) { tocLinks[ tocLinks.length - 1 ].classList.add( 'is-active' ); }
            return;
        }

        var best     = null;
        var bestDist = Infinity;
        for ( var i = 0; i < sections.length; i++ ) {
            var top = sections[ i ].getBoundingClientRect().top;
            if ( top <= offset + 50 ) {
                var dist = Math.abs( top - offset );
                if ( top <= offset ) { dist = offset - top; }
                if ( dist < bestDist || top <= offset ) {
                    bestDist = dist;
                    best     = sections[ i ];
                }
            }
        }
        tocLinks.forEach( function ( l ) { l.classList.remove( 'is-active' ); } );
        if ( best ) {
            var link = document.querySelector( '#ab-qs-toc a[href="#' + best.id + '"]' );
            if ( link ) { link.classList.add( 'is-active' ); }
        } else if ( tocLinks.length ) {
            tocLinks[ 0 ].classList.add( 'is-active' );
        }
    }
    window.addEventListener( 'scroll', updateTocActive, { passive: true } );
    updateTocActive();
} )();

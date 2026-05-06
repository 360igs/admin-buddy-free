/**
 * Admin Buddy - admin.js
 * Core settings page script. Vanilla ES6+. No jQuery.
 *
 * Covers:
 *   - Utilities: qs, qsa, on, escHtml, hexToRgba, fetch helpers
 *   - Toast:          window.showToast(msg, type)
 *   - Modal:          window.openConfirmModal(title, body, onConfirm, label)
 *   - Subnav tabs:    .ab-subnav__item[data-panel] + .ab-pane
 *   - Save button:    .ab-form-save-btn  (disabled-until-change + confirm modal)
 *   - Import confirm: #ab-import-trigger
 *   - Reset forms:    .ab-reset-form
 *   - Media upload:   .ab-media-upload / .ab-media-upload-with-id
 *   - Image reset:    .ab-img-upload-reset / .ab-favicon-reset
 *   - Colour pickers: .ab-native-color-picker (hex display + preview triggers)
 *   - Login tab:      bg-type, card-position, live iframe preview
 *   - Maintenance:    mode selector, bg-type, live iframe preview
 *   - Colours tab:    contrast badge, WCAG helpers, live preview
 *   - UI Tweaks tab:  footer field, sidebar gradient toggle
 *   - Bricks tab:     colour pickers, reset, enable guard
 *   - Dashboard tab:  custom widgets, keep-list
 *   - Setup tab:      module AJAX toggle (React mounts separately)
 *   - Tools tab:      export checkboxes
 *   - Save notice:    ?admbud_notice=saved toast
 *   - Emergency URL:  copy + regenerate
 *
 * @version 1.2.0-beta9
 */

/* global admbudSettings, ajaxurl, wp */

( function () {
    'use strict';

    // -- Guard ----------------------------------------------------------------
    var wrap = document.querySelector( '.ab-wrap' );
    if ( ! wrap ) { return; }

    // Flag: true while a form is being submitted - suppresses beforeunload warning.
    var admbudSubmitting = false;

    // -- Portal overlays to <body> ---------------------------------------------
    // Move #ab-confirm-modal and #ab-toast to document.body so they are never
    // trapped inside .ab-wrap's overflow:hidden stacking context.
    // Must run synchronously before any other code references these elements.
    ( function portalOverlays() {
        [ '#ab-confirm-modal', '#ab-toast' ].forEach( function ( sel ) {
            var el = document.querySelector( sel );
            if ( el && el.parentNode !== document.body ) {
                document.body.appendChild( el );
            }
        } );
    } )();

    // -- Strip leaked WP core notices from .ab-wrap ------------------------------
    // Some WP modules inject notices into our admin pages. Remove anything that
    // isn't one of ours (.ab-notice). Runs once after DOM ready.
    document.addEventListener( 'DOMContentLoaded', function () {
        document.querySelectorAll( '.ab-wrap .notice, .ab-wrap .updated, .ab-wrap .update-nag' ).forEach( function ( n ) {
            if ( ! n.classList.contains( 'ab-notice' ) ) { n.remove(); }
        } );
    } );

    // -- Range-input value display -----------------------------------------------
    // Replaces inline `oninput="document.getElementById('X').textContent = ..."`
    // attributes on range sliders. The HTML attaches three data attributes:
    //   data-display       — id of the element whose textContent shows the value
    //   data-suffix        — appended after the numeric value (e.g. "%", "px")
    //   data-zero-label    — optional, displayed verbatim when value is "0"
    // Delegated input listener on document so dynamically-added inputs work too.
    document.addEventListener( 'input', function ( e ) {
        var el = e.target;
        if ( ! el || ! el.classList || ! el.classList.contains( 'ab-range-display' ) ) { return; }
        var targetId = el.getAttribute( 'data-display' );
        if ( ! targetId ) { return; }
        var target = document.getElementById( targetId );
        if ( ! target ) { return; }
        var zeroLabel = el.getAttribute( 'data-zero-label' );
        var suffix    = el.getAttribute( 'data-suffix' ) || '';
        target.textContent = ( zeroLabel && el.value === '0' ) ? zeroLabel : ( el.value + suffix );
    } );

    // -- Remember last visited tab ------------------------------------------------
    // Persist active tab slug to localStorage. On load (see below), if no ?tab=
    // in the URL we redirect to the last visited tab (if it's still enabled).
    ( function rememberTab() {
        var LS_TAB = 'admbud_last_tab';

        // On nav item click: save the tab slug before navigating
        document.querySelectorAll( '.ab-nav__item[href]' ).forEach( function ( a ) {
            a.addEventListener( 'click', function () {
                var url    = new URL( a.href );
                var slug   = url.searchParams.get( 'tab' );
                if ( slug ) {
                    try {
                        localStorage.setItem( LS_TAB, slug );
                        document.cookie = 'admbud_last_tab=' + slug + ';path=/;SameSite=Lax';
                    } catch ( e ) {}
                }
            } );
        } );

        // On page load: if no ?tab= param, redirect to last saved tab + subtab
        var params  = new URLSearchParams( window.location.search );
        var current = params.get( 'tab' );
        if ( ! current ) {
            try {
                var last = localStorage.getItem( LS_TAB );
                if ( last ) {
                    var exists = document.querySelector( '.ab-nav__item[href*="tab=' + last + '"]' );
                    if ( exists ) {
                        var url = new URL( exists.href );
                        // Also restore subtab if available
                        var savedSub = localStorage.getItem( 'admbud_subtab_' + last );
                        if ( savedSub ) { url.searchParams.set( 'admbud_subtab', savedSub ); }
                        // Redirect before frame becomes visible - no flicker
                        window.location.replace( url.toString() );
                        return; // don't reveal frame before redirect completes
                    }
                }
            } catch ( e ) {}
        }
        // Tab is in URL or no saved tab - reveal frame immediately
        var wrap = document.querySelector( '.ab-wrap' );
        if ( wrap ) { wrap.classList.remove( 'ab-loading' ); }
    } )();

    // -- Topbar save button mirror ---------------------------------------------
    // Mirrors the primary save action of the active tab into the topbar.
    // Handles both form submit buttons (.ab-form-save-btn) and AJAX buttons
    // (#ab-menu-save-btn, #ab-role-save-btn, #ab-smtp-test-btn etc.)
    var SAVE_BTN_ICON = '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="margin-right:5px;vertical-align:middle;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>';

    var S   = window.admbudSettings || {};
    var AJX = ( typeof ajaxurl !== 'undefined' ) ? ajaxurl : '';

    function syncTopbarSave() {
        var slot = document.getElementById('ab-topbar-actions');
        if (!slot) { return; }

        // If the demo data tab has injected its own content, leave it alone.
        if (slot.querySelector('#ab-demo-remove-btn')) { return; }

        slot.innerHTML = '';

        // Priority: form save btn → named AJAX save btns
        var origBtn = document.querySelector('.ab-form-save-btn')
                   || document.getElementById('ab-menu-save-btn')
                   || document.getElementById('ab-role-save-btn')
                   || document.getElementById('ab-op-save-btn')
                   || document.getElementById('ab-coll-save-btn');
        if (!origBtn) { return; }

        var label = origBtn.textContent.trim() || S.saveChanges || 'Save Changes';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ab-btn ab-btn--primary ab-btn--sm';
        btn.innerHTML = SAVE_BTN_ICON + label;
        btn.disabled = origBtn.disabled;
        if (!origBtn.disabled) { btn.classList.add('has-changes'); }
        btn.addEventListener('click', function () { admbudSubmitting = true; origBtn.click(); });
        var obs = new MutationObserver(function () {
            btn.disabled = origBtn.disabled;
            btn.classList.toggle('has-changes', !origBtn.disabled);
        });
        obs.observe(origBtn, { attributes: true, attributeFilter: ['disabled'] });
        slot.appendChild(btn);
    }
    syncTopbarSave();
    window.syncTopbarSave = syncTopbarSave;

    // -- Utilities ------------------------------------------------------------
    function qs( sel, ctx )  { return ( ctx || document ).querySelector( sel ); }
    function qsa( sel, ctx ) { return Array.from( ( ctx || document ).querySelectorAll( sel ) ); }

    function on( ctx, evt, sel, fn ) {
        ctx.addEventListener( evt, function ( e ) {
            var t = sel ? e.target.closest( sel ) : e.target;
            if ( sel && ! t ) { return; }
            fn.call( t, e );
        } );
    }

    function escHtml( s ) {
        return String( s )
            .replace( /&/g,'&amp;' ).replace( /</g,'&lt;' )
            .replace( />/g,'&gt;' ).replace( /"/g,'&quot;' )
            .replace( /'/g,'&#39;' );
    }

    function hexToRgba( hex, alpha ) {
        hex = hex.replace( '#', '' );
        if ( hex.length === 3 ) { hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2]; }
        return 'rgba(' +
            parseInt( hex.substring(0,2), 16 ) + ',' +
            parseInt( hex.substring(2,4), 16 ) + ',' +
            parseInt( hex.substring(4,6), 16 ) + ',' + alpha + ')';
    }

    function post( data ) {
        var fd = new FormData();
        Object.keys( data ).forEach( function(k){ fd.append(k, data[k]); } );
        return fetch( AJX, { method:'POST', body: fd } ).then( function(r){ return r.json(); } );
    }

    // -- WCAG helpers ---------------------------------------------------------
    function wcagLum( hex ) {
        hex = hex.replace('#','');
        if ( hex.length === 3 ) { hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2]; }
        var r = parseInt(hex.substring(0,2),16)/255,
            g = parseInt(hex.substring(2,4),16)/255,
            b = parseInt(hex.substring(4,6),16)/255,
            lin = function(c){ return c<=0.03928 ? c/12.92 : Math.pow((c+0.055)/1.055,2.4); };
        return 0.2126*lin(r)+0.7152*lin(g)+0.0722*lin(b);
    }
    function wcagRatio( h1, h2 ) {
        var l1=wcagLum(h1), l2=wcagLum(h2);
        return Math.round( ((Math.max(l1,l2)+0.05)/(Math.min(l1,l2)+0.05))*100 )/100;
    }
    function wcagSuggest( bg ) {
        var l=wcagLum(bg); return (1.05/(l+0.05))>=(( l+0.05)/0.05) ? '#ffffff' : '#000000';
    }

    // -- Toast ----------------------------------------------------------------
    var toastEl = qs('#ab-toast');
    var toastIcons = {
        success: '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        error:   '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
        warning: '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        info:    '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
    };
    /*
     * Debounced page reload that waits for tracked AJAX promises to
     * settle. Used by toggle UIs (Setup -> Modules, Quick Settings) so
     * rapid-fire toggles don't race:
     *   - Each click re-arms the debounce timer.
     *   - When the timer elapses, if tracked AJAX is still in flight it
     *     polls until all settled, THEN reloads.
     * Usage:
     *   var p = ajaxPost(...);
     *   window.admbudReloadAfterAll.wait(p);               // track in-flight
     *   p.then(r => r.success && window.admbudReloadAfterAll.arm());
     */
    window.admbudReloadAfterAll = ( function () {
        var pending  = 0;
        var timer    = null;
        var QUIET_MS = 1000;   // time after last click before we consider reloading
        var POLL_MS  = 150;    // poll interval while waiting for AJAX to drain

        function wait( promise ) {
            if ( ! promise ) { return; }
            pending++;
            var settle = function () { if ( pending > 0 ) { pending--; } };
            if ( typeof promise.finally === 'function' ) { promise.finally( settle ); }
            else if ( typeof promise.then === 'function' ) { promise.then( settle, settle ); }
            else { settle(); }
        }

        function arm() {
            if ( timer ) { clearTimeout( timer ); }
            timer = setTimeout( function tick() {
                if ( pending > 0 ) { timer = setTimeout( tick, POLL_MS ); return; }
                window.location.reload();
            }, QUIET_MS );
        }

        return { wait: wait, arm: arm };
    }() );

    window.showToast = function( msg, type ) {
        if ( ! toastEl ) { return; }
        type = type || 'success';
        var t = document.createElement('div');
        t.className = 'ab-toast ab-toast--' + type;
        var icon = toastIcons[type] || toastIcons.info;
        t.innerHTML = '<span class="ab-toast__icon">' + icon + '</span>'
                    + '<span class="ab-toast__body">' + msg + '</span>';
        toastEl.appendChild(t);
        setTimeout( function(){
            t.classList.add('is-leaving');
            setTimeout( function(){ t.remove(); }, 320 );
        }, 3000 );
    };

    // -- Modal ----------------------------------------------------------------
    var modal        = qs('#ab-confirm-modal');
    var modalTitle   = qs('#ab-modal-title');
    var modalBody    = qs('#ab-modal-body');
    var modalConfirm = qs('#ab-modal-confirm');
    var modalCancel  = qs('#ab-modal-cancel');
    var modalCb      = null;
    var _trapFn      = null;

    function trapFocus( el ) {
        releaseFocus();
        var SEL = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
        _trapFn = function(e) {
            if ( e.key !== 'Tab' ) { return; }
            var els = qsa(SEL,el).filter(function(n){return n.offsetParent!==null;});
            if ( !els.length ) { e.preventDefault(); return; }
            if ( e.shiftKey ) { if (document.activeElement===els[0])  { e.preventDefault(); els[els.length-1].focus(); } }
            else               { if (document.activeElement===els[els.length-1]) { e.preventDefault(); els[0].focus(); } }
        };
        document.addEventListener('keydown', _trapFn);
    }
    function releaseFocus() {
        if (_trapFn) { document.removeEventListener('keydown',_trapFn); _trapFn=null; }
    }
    window.trapFocus = trapFocus; window.releaseFocus = releaseFocus;

    function closeModal() {
        if (modal) { modal.classList.add('ab-hidden'); }
        modalCb = null; releaseFocus();
    }

    window.openConfirmModal = function( title, body, onConfirm, label, variant, htmlBody ) {
        if ( ! modal ) { if (typeof onConfirm==='function') { onConfirm(); } return; }
        if (modalTitle) { modalTitle.textContent = title; }
        if (modalBody)  {
            if ( htmlBody ) { modalBody.innerHTML = body; }
            else { modalBody.textContent = body; }
        }
        modalCb = onConfirm;
        if (modalConfirm) {
            modalConfirm.textContent = label || modalConfirm.getAttribute('data-default-label') || 'Yes, proceed';
            // Semantic button variant: 'danger' (default), 'success', 'primary'
            modalConfirm.className = 'ab-btn ' + ( variant || 'ab-btn--danger' );
        }
        modal.classList.remove('ab-hidden');
        if (modalConfirm) { modalConfirm.focus(); }
        trapFocus(modal);
    };

    if (modalConfirm) { modalConfirm.addEventListener('click', function(){
        if (typeof modalCb==='function') { modalCb(); } closeModal();
    }); }
    if (modalCancel)  { modalCancel.addEventListener('click', closeModal); }
    var closeX = qs('#ab-modal-close-x');
    if (closeX) { closeX.addEventListener('click', closeModal); }
    document.addEventListener('keydown', function(e){
        if ( e.key==='Escape' && modal && !modal.classList.contains('ab-hidden') ) { closeModal(); }
    });

    // -- Nav expand/collapse all ----------------------------------------------
    var expandBtn = qs('#ab-nav-expand-all');
    var collapseBtn = qs('#ab-nav-collapse-all');
    if (expandBtn) { expandBtn.addEventListener('click', function(){ qsa('details.ab-nav__group').forEach(function(d){ d.open = true; }); }); }
    if (collapseBtn) { collapseBtn.addEventListener('click', function(){ qsa('details.ab-nav__group').forEach(function(d){ d.open = false; }); }); }

    // -- Unsaved changes warning on nav switch --------------------------------
    // Intercept nav item clicks. If there are unsaved changes (save button is
    // enabled), show a confirm modal before navigating away.
    function admbudUnsavedGuard(e) {
        var saveBtn = qs('.ab-form-save-btn')
                   || document.getElementById('ab-menu-save-btn')
                   || document.getElementById('ab-role-save-btn')
                   || document.getElementById('ab-op-save-btn')
                   || document.getElementById('ab-coll-save-btn');
        if ( !saveBtn || saveBtn.disabled ) { return; }
        var link = this.closest('a');
        if ( !link ) { return; }
        var targetHref = link.getAttribute('href');
        if ( !targetHref || targetHref === '#' || targetHref.indexOf('javascript:') === 0 ) { return; }
        e.preventDefault();
        e.stopPropagation();
        window.openConfirmModal(
            S.unsavedTitle || 'Unsaved changes',
            S.unsavedBody  || 'You have unsaved changes that will be lost if you leave this tab.',
            function() {
                saveBtn.disabled = true; // prevent re-trigger
                window.location.href = targetHref;
            },
            S.unsavedLeave || 'Leave',
            'ab-btn--danger'
        );
    }

    // AB's own sidebar nav
    on( document, 'click', '.ab-nav__item', admbudUnsavedGuard );
    // WP admin sidebar menu
    on( document, 'click', '#adminmenu a', admbudUnsavedGuard );
    // WP admin bar
    on( document, 'click', '#wpadminbar a', admbudUnsavedGuard );

    // -- beforeunload: fallback for browser back/close ------------------------
    // Custom modals can't intercept browser back button or tab close,
    // so we use the native beforeunload dialog as a safety net.
    function admbudHasUnsavedChanges() {
        if (admbudSubmitting) { return false; }
        var saveBtn = qs('.ab-form-save-btn')
                   || document.getElementById('ab-menu-save-btn')
                   || document.getElementById('ab-role-save-btn')
                   || document.getElementById('ab-op-save-btn')
                   || document.getElementById('ab-coll-save-btn');
        return saveBtn && !saveBtn.disabled;
    }
    window.addEventListener('beforeunload', function(e) {
        if (admbudHasUnsavedChanges()) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
    // Mark as submitting when any AB form is submitted so beforeunload doesn't fire.
    qsa('.ab-wrap form').forEach(function(form) {
        form.addEventListener('submit', function() { admbudSubmitting = true; });
    });

    // -- Subnav tab switching -------------------------------------------------
    // Handles .ab-subnav[data-panel-prefix] → .ab-subnav__item[data-panel] → .ab-pane
    // Also handles legacy .ab-colour-subtabs / .ab-colour-subtab if still present.
    on( document, 'click', '.ab-subnav__item', function(e) {
        var btn    = this;
        var strip  = btn.closest('.ab-subnav');
        if (!strip) { return; }
        var slug   = btn.getAttribute('data-panel');
        if (!slug) { return; }
        var prefix = strip.getAttribute('data-panel-prefix') || '';
        var panelId = 'ab-pane-' + prefix + slug;

        // Active state
        qsa('.ab-subnav__item', strip).forEach(function(b){
            b.classList.remove('is-active');
            b.setAttribute('aria-selected','false');
        });
        btn.classList.add('is-active');
        btn.setAttribute('aria-selected','true');

        // Panels - siblings of the strip
        var parent = strip.parentElement;
        qsa('.ab-pane', parent).forEach(function(p){ p.classList.add('ab-hidden'); });
        var panel = document.getElementById(panelId);
        if (panel) { panel.classList.remove('ab-hidden'); }

        // Hide colours save bar on presets and exclusions panes
        var saveBar = qs('#ab-colours-save-bar');
        if (saveBar) { saveBar.classList.toggle('ab-hidden', slug==='presets' || slug==='exclusions'); }

        // Toggle colours form+grid vs exclusions pane
        var formWrap = qs('#ab-colours-form-wrap');
        if (formWrap) { formWrap.classList.toggle('ab-hidden', slug==='exclusions'); }

        // Sync hidden field
        var field = strip.getAttribute('data-subtab-field');
        if (field) {
            var inp = qs(field);
            if (inp) { inp.value = slug; }
        }

        // Update URL
        if (history.replaceState) {
            var p = new URLSearchParams(window.location.search);
            p.set('admbud_subtab', slug);
            history.replaceState(null,'', window.location.pathname+'?'+p.toString());
        }

        // Remember subtab per tab in localStorage + cookie for PHP
        try {
            var tab = new URLSearchParams(window.location.search).get('tab') || 'adminui';
            localStorage.setItem('admbud_subtab_' + tab, slug);
            document.cookie = 'admbud_subtab_' + tab + '=' + slug + ';path=/;SameSite=Lax';
        } catch(ex){}
    });

    // -- Restore subtab from localStorage on page load (flicker-free) ---------
    (function(){
        try {
            var p = new URLSearchParams(window.location.search);
            if (p.get('admbud_subtab')) { return; }
            var tab = p.get('tab') || 'adminui';
            var saved = localStorage.getItem('admbud_subtab_' + tab);
            if (!saved) { return; }
            var btn = qs('.ab-subnav__item[data-panel="' + saved + '"]');
            if (!btn) { return; }
            var strip = btn.closest('.ab-subnav');
            if (!strip) { return; }
            var prefix = strip.getAttribute('data-panel-prefix') || '';
            var panelId = 'ab-pane-' + prefix + saved;
            var panel = document.getElementById(panelId);
            if (!panel) { return; }
            qsa('.ab-subnav__item', strip).forEach(function(b){
                b.classList.remove('is-active');
                b.setAttribute('aria-selected','false');
            });
            btn.classList.add('is-active');
            btn.setAttribute('aria-selected','true');
            var parent = strip.parentElement;
            qsa('.ab-pane', parent).forEach(function(pn){ pn.classList.add('ab-hidden'); });
            panel.classList.remove('ab-hidden');
            // Colours tab: toggle form-wrap + save-bar when restoring exclusions/presets subtab.
            var formWrap = qs('#ab-colours-form-wrap');
            if (formWrap) { formWrap.classList.toggle('ab-hidden', saved==='exclusions'); }
            var saveBar = qs('#ab-colours-save-bar');
            if (saveBar) { saveBar.classList.toggle('ab-hidden', saved==='presets' || saved==='exclusions'); }
            var field = strip.getAttribute('data-subtab-field');
            if (field) { var inp = qs(field); if (inp) { inp.value = saved; } }
            if (history.replaceState) {
                p.set('admbud_subtab', saved);
                history.replaceState(null,'', window.location.pathname+'?'+p.toString());
            }
        } catch(ex){}
    })();

    // -- Highlight setting from search (admbud_highlight param) -----------------
    (function(){
        try {
            var p = new URLSearchParams(window.location.search);
            var hl = p.get('admbud_highlight');
            if (!hl) { return; }

            // Generous delay - subtab panels, colour pickers, and toggles need
            // time to render after PHP output + JS init.
            setTimeout(function(){ admbudHighlightSetting(hl); }, 500);

            // Clean up the URL so refreshing doesn't re-highlight.
            if (history.replaceState) {
                p.delete('admbud_highlight');
                var clean = p.toString();
                history.replaceState(null, '', window.location.pathname + (clean ? '?' + clean : ''));
            }
        } catch(ex){}
    })();

    // Shared highlight function - used by admin.js (on load) and search.js (same-page).
    window.admbudHighlightSetting = function( text ) {
        var target = null;
        var lower  = text.toLowerCase();

        // Get direct text of an element, excluding tooltips and Pro badges.
        function getDirectText(el) {
            var clone = el.cloneNode(true);
            // Remove tooltip and Pro badge spans from the clone.
            clone.querySelectorAll('.ab-tip, .ab-pro-tag, .ab-info-tip').forEach(function(t){ t.remove(); });
            return clone.textContent.trim();
        }

        // Search across all label-like elements for an exact or case-insensitive match.
        var walker = document.createTreeWalker(
            document.querySelector('.ab-wrap') || document.body,
            NodeFilter.SHOW_ELEMENT,
            { acceptNode: function(node) {
                // Skip hidden elements and script/style tags.
                if (node.offsetParent === null && node.tagName !== 'TR') { return NodeFilter.FILTER_SKIP; }
                if (/^(SCRIPT|STYLE|SVG|TEMPLATE)$/i.test(node.tagName)) { return NodeFilter.FILTER_REJECT; }
                // Skip tooltip elements themselves.
                if (node.classList && (node.classList.contains('ab-tip') || node.classList.contains('ab-info-tip'))) { return NodeFilter.FILTER_REJECT; }
                return NodeFilter.FILTER_ACCEPT;
            }}
        );
        var fallback = null;
        while (walker.nextNode()) {
            var el = walker.currentNode;
            var txt = getDirectText(el);
            if (!txt || txt.length > 200) { continue; }
            if (txt === text) { target = el; break; }
            if (!fallback && txt.toLowerCase() === lower) { fallback = el; }
        }
        if (!target) { target = fallback; }

        if (!target) { return; }

        // Find the highlightable row: <tr>, .ab-qs-row, .ab-debug-row, etc.
        // Walk up to find a highlightable row. Add new row-class selectors
        // here when introducing a new row pattern that should pulse as a unit
        // rather than falling back to the whole .ab-section.
        var row = target.closest('tr, .ab-qs-row, .ab-setup-module-row, .ab-op-field-row, .ab-debug-row');
        if (!row) { row = target.closest('.ab-section') || target; }

        // Ensure the element is visible (not inside a hidden panel).
        if (row.offsetParent === null) { return; }

        row.classList.remove('ab-highlight-pulse');
        void row.offsetWidth;
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        row.classList.add('ab-highlight-pulse');
        row.addEventListener('animationend', function() {
            row.classList.remove('ab-highlight-pulse');
        }, { once: true });
    };

    // -- FieldRegistry media handlers (image, file, gallery) ----------------
    // Delegated vanilla JS - works in metabox, option pages, and future modules.
    // wp.media check is inside each handler (not at registration time) because
    // wp_enqueue_media() scripts may load after this script runs.
    (function(){
        // Image / File - single select.
        document.addEventListener('click', function(e){
            var btn = e.target.closest('.ab-field-media__select');
            if ( ! btn ) { return; }
            e.preventDefault();
            if ( typeof wp === 'undefined' || ! wp.media ) { return; }
            var targetId = btn.getAttribute('data-target');
            var type     = btn.getAttribute('data-type') || 'image';
            var frame = wp.media({
                title:    type === 'image' ? 'Choose Image' : 'Choose File',
                multiple: false,
                library:  type === 'image' ? { type: 'image' } : {}
            });
            frame.on('select', function(){
                var att   = frame.state().get('selection').first().toJSON();
                var input = document.getElementById(targetId);
                if ( input ) { input.value = att.url; }
                var wrap    = btn.closest('.ab-field-media');
                var preview = wrap ? wrap.querySelector('.ab-field-media__preview') : null;
                if ( preview ) {
                    if ( type === 'image' ) {
                        preview.innerHTML =
                            '<div class="ab-field-media__thumb">'
                            +     '<img src="' + att.url + '" alt="">'
                            +     '<button type="button" class="ab-field-media__remove-x"'
                            +     ' data-target="' + targetId + '" title="Remove">&times;</button>'
                            + '</div>';
                    } else {
                        var fname = att.filename || att.url.split( '/' ).pop();
                        preview.innerHTML =
                            '<a href="' + att.url + '" target="_blank">' + fname + '</a> '
                            + '<button type="button" class="button button-small ab-field-media__remove-x"'
                            + ' data-target="' + targetId + '" title="Remove">&times;</button>';
                    }
                }
                if ( input ) { input.dispatchEvent(new Event('change', {bubbles:true})); }
            });
            frame.open();
        });

        // Image / File - remove (X on thumbnail).
        document.addEventListener('click', function(e){
            var btn = e.target.closest('.ab-field-media__remove-x');
            if ( ! btn ) { return; }
            e.preventDefault();
            var targetId = btn.getAttribute('data-target');
            var input    = document.getElementById(targetId);
            if ( input ) { input.value = ''; input.dispatchEvent(new Event('change', {bubbles:true})); }
            var wrap    = btn.closest('.ab-field-media');
            var preview = wrap ? wrap.querySelector('.ab-field-media__preview') : null;
            if ( preview ) { preview.innerHTML = ''; }
        });

        // Gallery - add images (multi-select).
        document.addEventListener('click', function(e){
            var btn = e.target.closest('.ab-field-gallery__add');
            if ( ! btn ) { return; }
            e.preventDefault();
            if ( typeof wp === 'undefined' || ! wp.media ) { return; }
            var targetId = btn.getAttribute('data-target');
            var frame = wp.media({ title: 'Add Images', multiple: true, library: { type: 'image' } });
            frame.on('select', function(){
                var input = document.getElementById(targetId);
                var wrap  = btn.closest('.ab-field-gallery');
                var grid  = wrap ? wrap.querySelector('.ab-field-gallery__grid') : null;
                var urls  = [];
                try { urls = JSON.parse(input.value || '[]'); } catch(ex){}
                frame.state().get('selection').forEach(function(att){
                    var url = att.toJSON().url;
                    urls.push(url);
                    if ( grid ) {
                        var item = document.createElement('div');
                        item.className = 'ab-field-gallery__item';
                        item.innerHTML = '<img src="'+url+'" alt=""><button type="button" class="ab-field-gallery__remove">&times;</button>';
                        grid.appendChild(item);
                    }
                });
                if ( input ) { input.value = JSON.stringify(urls); input.dispatchEvent(new Event('change', {bubbles:true})); }
            });
            frame.open();
        });

        // Gallery - remove single image.
        document.addEventListener('click', function(e){
            var btn = e.target.closest('.ab-field-gallery__remove');
            if ( ! btn ) { return; }
            e.preventDefault();
            var item = btn.closest('.ab-field-gallery__item');
            var wrap = btn.closest('.ab-field-gallery');
            if ( item ) { item.remove(); }
            // Rebuild the URLs array from remaining items.
            if ( wrap ) {
                var input = document.getElementById(wrap.getAttribute('data-target'));
                var imgs  = wrap.querySelectorAll('.ab-field-gallery__item img');
                var urls  = [];
                imgs.forEach(function(img){ urls.push(img.src); });
                if ( input ) { input.value = JSON.stringify(urls); input.dispatchEvent(new Event('change', {bubbles:true})); }
            }
        });
    })();

    // -- Save button - disabled until change, then confirm modal -------------
    qsa('.ab-form-save-btn').forEach(function(btn){
        btn.disabled = true;
        btn.classList.add('ab-btn--disabled-until-change');
    });
    qsa('form').forEach(function(form){
        var btn = qs('.ab-form-save-btn', form);
        if (!btn) { return; }
        form.addEventListener('change', enable); form.addEventListener('input', enable);
        function enable(){ btn.disabled=false; btn.classList.remove('ab-btn--disabled-until-change'); }
    });

    on( document, 'click', '.ab-form-save-btn', function(e) {
        e.preventDefault();
        var form = this.closest('form');
        window.openConfirmModal(
            S.formSaveConfirmTitle || 'Save changes?',
            S.formSaveConfirmBody  || 'Your settings will be saved.',
            function(){
                // Bricks: clear names of synced pickers so empty string is sent
                qsa('.ab-bricks-colour-picker', form).forEach(function(picker){
                    var opt = picker.name;
                    if (!opt || opt.indexOf('__skip')!==-1) { return; }
                    if (picker.closest('td').querySelector('.ab-bricks-colour-reset.ab-hidden')) {
                        picker.setAttribute('name', opt+'__skip');
                        var td = picker.closest('td');
                        if (!td.querySelector('input[name="'+opt+'"]')) {
                            var h = document.createElement('input');
                            h.type='hidden'; h.name=opt; h.value='';
                            td.appendChild(h);
                        }
                    }
                });
                form.submit();
            },
            null, 'ab-btn--success'
        );
    });

    // -- admbud_notice toast on page load ----------------------------------------
    ( function(){
        var p = new URLSearchParams(window.location.search);
        var notice = p.get('admbud_notice');
        if (!notice) { return; }
        var reason = p.get('admbud_reason') || '';
        var toasts = {
            saved:            { msg: S.settingsSaved || 'Settings saved.', type: 'success' },
            import_ok:        { msg: S.importSuccess || 'Settings imported successfully.', type: 'success' },
            reset_ok:         { msg: S.resetSuccess || 'Settings reset to defaults.', type: 'success' },
            import_empty:     { msg: S.importEmpty || 'Import failed: no file selected.', type: 'error' },
            import_read_fail: { msg: 'Import failed: could not read file.', type: 'error' },
            import_invalid:   { msg: 'Import failed: invalid settings file.' + (reason ? ' (Reason: ' + reason + ')' : ''), type: 'error' }
        };
        var t = toasts[notice];
        if (t) {
            setTimeout(function(){ window.showToast(t.msg, t.type); }, 250);
            p.delete('admbud_notice'); p.delete('admbud_count'); p.delete('admbud_reason');
            history.replaceState(null,'', window.location.pathname+(p.toString()?'?'+p.toString():''));
        }
    } )();

    // -- Import confirm -------------------------------------------------------
    var importTrigger = qs('#ab-import-trigger');
    if (importTrigger) { importTrigger.addEventListener('click', function(){
        var fi = qs('#admbud_import_file');
        if (!fi||!fi.files||!fi.files.length) { if(fi){fi.reportValidity();} return; }
        window.openConfirmModal(
            S.importConfirmTitle||'Import Settings?',
            S.importConfirmBody ||'This will replace all current settings. This cannot be undone.',
            function(){ var f=qs('#ab-import-form'); if(f){f.submit();} }
        );
    }); }

    // -- Reset forms ----------------------------------------------------------
    on( document, 'submit', '.ab-reset-form', function(e) {
        e.preventDefault();
        var form = this;
        window.openConfirmModal(
            form.getAttribute('data-confirm-title'),
            form.getAttribute('data-confirm-body'),
            function(){ form.submit(); }
        );
    });

    // -- Media upload (.ab-media-upload → URL target) -------------------------
    var mediaFrame = null, mediaTarget = null;
    on( document, 'click', '.ab-media-upload', function(e) {
        e.preventDefault();
        mediaTarget = this.getAttribute('data-target');
        if (!wp||!wp.media) { return; }
        if (mediaFrame) { mediaFrame.open(); return; }
        mediaFrame = wp.media({ title:S.chooseImageTitle||'Choose Image', button:{text:S.useImageText||'Use this image'}, multiple:false });
        mediaFrame.on('select', function(){
            var url = mediaFrame.state().get('selection').first().toJSON().url;
            var inp = qs('#'+mediaTarget); if(inp){ inp.value=url; }
            var reset = qs('[data-target="'+mediaTarget+'"].ab-img-upload-reset');
            if(reset){ reset.classList.remove('ab-hidden'); }
            var form = inp && inp.closest('form');
            var btn  = form && qs('.ab-form-save-btn',form);
            if(btn){ btn.disabled=false; btn.classList.remove('ab-btn--disabled-until-change'); }
            updateLoginPreview();
        });
        mediaFrame.open();
    });

    // -- Media upload with ID (.ab-media-upload-with-id → favicon) ------------
    on( document, 'click', '.ab-media-upload-with-id', function(e) {
        e.preventDefault();
        var btn = this, idT=btn.getAttribute('data-id-target'), urlD=btn.getAttribute('data-url-display');
        if (!wp||!wp.media) { return; }
        var ff = wp.media({ title:S.chooseImageTitle||'Choose Image', button:{text:S.useImageText||'Use this image'}, multiple:false });
        ff.on('select', function(){
            var att=ff.state().get('selection').first().toJSON();
            var idEl=qs('#'+idT); if(idEl){ idEl.value=att.id; }
            var urlEl=qs('#'+urlD); if(urlEl){ urlEl.value=att.url; }
            btn.parentElement.querySelector('.ab-favicon-reset')?.classList.remove('ab-hidden');
            var form=btn.closest('form'), sb=form&&qs('.ab-form-save-btn',form);
            if(sb){ sb.disabled=false; sb.classList.remove('ab-btn--disabled-until-change'); }
        });
        ff.open();
    });

    // -- Image reset ----------------------------------------------------------
    on( document, 'click', '.ab-img-upload-reset', function() {
        var t=this.getAttribute('data-target'), inp=qs('#'+t); if(inp){ inp.value=''; }
        this.classList.add('ab-hidden');
        var form=this.closest('form'), sb=form&&qs('.ab-form-save-btn',form);
        if(sb){ sb.disabled=false; sb.classList.remove('ab-btn--disabled-until-change'); }
    });
    on( document, 'click', '.ab-favicon-reset', function() {
        var idT=this.getAttribute('data-id-target'), urlD=this.getAttribute('data-url-display');
        var idEl=qs('#'+idT); if(idEl){ idEl.value='0'; }
        var urlEl=qs('#'+urlD); if(urlEl){ urlEl.value=''; }
        this.classList.add('ab-hidden');
        var form=this.closest('form'), sb=form&&qs('.ab-form-save-btn',form);
        if(sb){ sb.disabled=false; sb.classList.remove('ab-btn--disabled-until-change'); }
    });

    // -- Tooltip trigger ------------------------------------------------------
    on( document, 'click', '.ab-tooltip-trigger', function(e) {
        e.stopPropagation();
        var btn=this, id=btn.getAttribute('data-tooltip'), el=id&&qs('#'+id);
        if(!el){ return; }
        var opening = el.classList.contains('ab-hidden');
        el.classList.toggle('ab-hidden');
        btn.classList.toggle('is-active', opening);
        if (opening) {
            setTimeout(function(){
                document.addEventListener('click', function hide(ev){
                    if(!el.contains(ev.target) && ev.target !== btn){
                        el.classList.add('ab-hidden');
                        btn.classList.remove('is-active');
                    }
                    document.removeEventListener('click', hide);
                });
            }, 0);
        }
    });

    // -- Colour pickers: hex display + save-enable ----------------------------
    qsa('.ab-native-color-picker').forEach(function(picker){
        var val=picker.value, fb=picker.getAttribute('data-default-color')||'';
        if ((!val||val==='#000000')&&fb&&fb!=='#000000') {
            picker.value=fb; picker.setAttribute('data-is-default','1');
        }
        if (!picker.nextElementSibling||!picker.nextElementSibling.classList.contains('ab-color-hex')) {
            var sp=document.createElement('span');
            sp.className='ab-color-hex';
            sp.style.cssText='font-family:monospace;font-size:0.82rem;color:var(--ab-text-secondary,#64748b);margin-left:8px;vertical-align:middle;';
            sp.textContent=picker.value;
            picker.insertAdjacentElement('afterend',sp);
        }
    });

    on( document, 'input', '.ab-native-color-picker', function() {
        this.removeAttribute('data-is-default');
        var hex=this.nextElementSibling;
        if(hex&&hex.classList.contains('ab-color-hex')){ hex.textContent=this.value; }
        var form=this.closest('form'), sb=form&&qs('.ab-form-save-btn',form);
        if(sb){ sb.disabled=false; sb.classList.remove('ab-btn--disabled-until-change'); }
        setTimeout(function(){ updateLoginPreview(); updateColourPreview(); updateMaintPreview(); }, 50);
    });
    on( document, 'change', '.ab-native-color-picker', function() {
        setTimeout(function(){ updateLoginPreview(); updateColourPreview(); updateMaintPreview(); }, 50);
    });

    // -- Sep colour row --------------------------------------------------------
    var sepToggle = qs('#admbud_colours_menu_item_sep');
    if (sepToggle) { sepToggle.addEventListener('change', function(){
        var row=qs('#ab-sep-color-row'); if(row){ row.classList.toggle('ab-hidden',!this.checked); }
        // Ensure the form detects this change and enables the save button
        var form = this.closest('form');
        if (form) { form.dispatchEvent(new Event('change', {bubbles:true})); }
    }); }

    // -- Ensure all toggle checkboxes and radio cards bubble change to their form --
    on(document, 'change', '.ab-toggle input[type="checkbox"], .ab-card-pos-option input[type="radio"]', function() {
        var form = this.closest('form');
        if (form) {
            var btn = qs('.ab-form-save-btn', form);
            if (btn) { btn.disabled = false; btn.classList.remove('ab-btn--disabled-until-change'); }
        }
    });

    // -- Generate palette from Primary -----------------------------------------
    // Highlight selected mode radio
    qsa( 'input[name="admbud_palette_mode"]' ).forEach( function ( r ) {
        function upd() {
            qsa( '.ab-palette-mode-option' ).forEach( function ( l ) {
                var checked = l.querySelector( 'input:checked' );
                l.style.borderColor = checked ? 'var(--ab-accent,#7c3aed)'         : '';
                l.style.background  = checked ? 'var(--ab-surface-sunken,#f9fafb)' : '';
            } );
        }
        r.addEventListener( 'change', upd );
        upd();
    } );

    var syncBtn = qs('#ab-sync-from-primary');
    if (syncBtn) { syncBtn.addEventListener('click', function() {
        // Primary is always on the Accent tab (#admbud_colours_primary). The Auto
        // Palette pane shows a read-only display of it and relies on the user
        // having saved their chosen Primary first.
        var primary = (qs('#admbud_colours_primary')||{}).value;
        if (!primary) { return; }
        var modeEl = qs('input[name="admbud_palette_mode"]:checked');
        var mode = modeEl ? modeEl.value : 'dark_sidebar';

        // -- Colour helpers --
        function hexToRgb(h) {
            h = h.replace('#','');
            return { r: parseInt(h.substring(0,2),16), g: parseInt(h.substring(2,4),16), b: parseInt(h.substring(4,6),16) };
        }
        function rgbToHex(r,g,b) {
            return '#' + [r,g,b].map(function(c){ return Math.max(0,Math.min(255,Math.round(c))).toString(16).padStart(2,'0'); }).join('');
        }
        function lum(hex) {
            var c = hexToRgb(hex);
            var rs = c.r/255, gs = c.g/255, bs = c.b/255;
            var rl = rs<=0.03928 ? rs/12.92 : Math.pow((rs+0.055)/1.055,2.4);
            var gl = gs<=0.03928 ? gs/12.92 : Math.pow((gs+0.055)/1.055,2.4);
            var bl = bs<=0.03928 ? bs/12.92 : Math.pow((bs+0.055)/1.055,2.4);
            return 0.2126*rl + 0.7152*gl + 0.0722*bl;
        }
        function mix(a, b, r) {
            var x=hexToRgb(a), y=hexToRgb(b);
            return rgbToHex(x.r+(y.r-x.r)*r, x.g+(y.g-x.g)*r, x.b+(y.b-x.b)*r);
        }
        function darken(h, a) { return mix(h, '#000000', a); }
        function lighten(h, a) { return mix(h, '#ffffff', a); }
        function contrastText(bg) { return lum(bg) > 0.4 ? '#1d2327' : '#ffffff'; }

        // -- Primary is NEVER mutated --
        var isLight = lum(primary) > 0.65;
        // Anchor = darkened primary for buttons/links on LIGHT backgrounds
        var anchor = isLight ? darken(primary, 0.45) : primary;
        if (lum(anchor) > 0.35) { anchor = darken(primary, 0.60); }

        var secondary = darken(primary, 0.18);
        var onPrimary = contrastText(primary);
        var onSecondary = contrastText(secondary);
        var m = {};

        // -- Shared: sidebar dark surface derived from primary --
        function makeSidebarBase() {
            var sb = darken(primary, 0.60);
            if (lum(sb) > 0.12) { sb = darken(primary, 0.75); }
            if (lum(sb) < 0.02) { sb = lighten(sb, 0.15); }
            var sd = darken(sb, 0.30);
            if (lum(sd) < 0.01) { sd = lighten(sd, 0.08); }
            return { sb: sb, sd: sd };
        }

        // -- Shared: button/link colours for light content areas --
        var lightBtn     = isLight ? anchor : primary;
        var lightBtnHover= isLight ? darken(anchor, 0.18) : secondary;
        var lightLink    = isLight ? anchor : primary;
        var lightLinkHvr = isLight ? darken(anchor, 0.18) : secondary;

        // -- Shared: tints from user's actual primary --
        var tBase     = isLight ? primary : lighten(primary, 0.93);
        var tLight    = isLight ? lighten(primary, 0.30) : lighten(primary, 0.96);
        var tLightest = isLight ? lighten(primary, 0.50) : lighten(primary, 0.97);
        var bdrLight  = isLight ? mix(primary, anchor, 0.30) : lighten(primary, 0.78);
        var bdrLtr    = isLight ? mix(primary, anchor, 0.20) : lighten(primary, 0.85);

        if (mode === 'dark_sidebar') {
            // ================================================================
            // DARK SIDEBAR
            // ================================================================
            var side = makeSidebarBase(), sb = side.sb, sd = side.sd;
            // Admin bar = same as sidebar base
            var admbudBg = sb;

            m = {
                admbud_colours_primary: primary, admbud_colours_secondary: secondary, admbud_colours_hover_bg: secondary, admbud_colours_active_bg: primary,
                // Sidebar
                admbud_colours_menu_bg: sb, admbud_colours_menu_text: lighten(sb, 0.82),
                admbud_colours_active_text: onPrimary,
                admbud_colours_hover_text: lighten(sb, 0.85),
                admbud_colours_active_parent_text: onPrimary,
                admbud_colours_sep_color: primary,
                admbud_colours_menu_item_sep: '1', admbud_colours_sidebar_gradient: '1',
                admbud_colours_sidebar_grad_dir: 'to bottom',
                admbud_colours_sidebar_grad_from: sb, admbud_colours_sidebar_grad_to: sd,
                // Submenu - unified: bg=primary, text=onPrimary
                admbud_colours_submenu_bg: primary,
                admbud_colours_submenu_text: onPrimary,
                admbud_colours_submenu_hover_bg: secondary,
                admbud_colours_submenu_hover_text: onSecondary, admbud_colours_submenu_active_bg: primary, admbud_colours_submenu_active_text: onSecondary,
                // Admin bar - unified dark surface, flyout matches bar bg
                admbud_colours_adminbar_bg: admbudBg,
                admbud_colours_adminbar_text: lighten(sb, 0.70),
                admbud_colours_adminbar_hover_bg: primary,
                admbud_colours_adminbar_hover_text: onPrimary,
                admbud_colours_adminbar_submenu_bg: admbudBg,
                admbud_colours_adminbar_sub_text: lighten(sb, 0.70),
                admbud_colours_adminbar_sub_hover_bg: primary,
                admbud_colours_adminbar_sub_hover_text: onPrimary,
                // Chrome
                admbud_colours_body_bg: '#ffffff', admbud_colours_shadow_colour: '',
                admbud_colours_pill_maintenance: '#dd3333', admbud_colours_pill_coming_soon: '#dd3333',
                admbud_colours_pill_noindex: '#dd9933', admbud_colours_pill_admin_buddy: primary,
                // Content area - light
                admbud_colours_content_heading: '#1d2327', admbud_colours_content_text: '#3c434a',
                admbud_colours_content_link: lightLink, admbud_colours_content_link_hover: lightLinkHvr,
                admbud_colours_table_header_bg: tBase, admbud_colours_table_header_text: '#1d2327',
                admbud_colours_table_row_bg: '#ffffff', admbud_colours_table_row_text: '#3c434a',
                admbud_colours_table_row_alt_bg: tLight, admbud_colours_table_row_alt_text: '#3c434a',
                admbud_colours_table_row_hover: tBase, admbud_colours_table_border: bdrLight,
                admbud_colours_table_row_separator: bdrLtr, admbud_colours_table_header_link: '#1d2327', admbud_colours_table_title_link: lightLink, admbud_colours_table_action_link: lightLink,
                admbud_colours_input_bg: '#ffffff', admbud_colours_input_border: bdrLight, admbud_colours_input_focus: primary,
                admbud_colours_btn_primary_bg: lightBtn, admbud_colours_btn_primary_text: contrastText(lightBtn), admbud_colours_btn_primary_hover: lightBtnHover,
                admbud_colours_btn_secondary_bg: tLightest,
                admbud_colours_postbox_bg: '#ffffff', admbud_colours_postbox_header_bg: tLight,
                admbud_colours_postbox_border: bdrLight, admbud_colours_postbox_text: '#3c434a', admbud_colours_notice_bg: tLightest,
                // Login
                admbud_login_bg_type: 'solid', admbud_login_bg_color: sb,
                admbud_login_grad_from: sd, admbud_login_grad_to: sb, admbud_login_grad_direction: 'to bottom right',
                // Coming Soon
                admbud_cs_bg_type: 'gradient', admbud_cs_bg_color: sb,
                admbud_cs_grad_from: sd, admbud_cs_grad_to: sb, admbud_cs_grad_direction: 'to bottom right',
                admbud_cs_text_color: lighten(sb, 0.85), admbud_cs_message_color: lighten(sb, 0.70),
                // Maintenance
                admbud_maint_bg_type: 'gradient', admbud_maint_bg_color: sb,
                admbud_maint_grad_from: sd, admbud_maint_grad_to: sb, admbud_maint_grad_direction: 'to bottom right',
                admbud_maint_text_color: lighten(sb, 0.85), admbud_maint_message_color: lighten(sb, 0.70)
            };

        } else if (mode === 'light_sidebar') {
            // ================================================================
            // LIGHT SIDEBAR
            // ================================================================
            var menuTxt = isLight ? darken(anchor, 0.20) : darken(primary, 0.20);

            m = {
                admbud_colours_primary: primary, admbud_colours_secondary: secondary, admbud_colours_hover_bg: secondary, admbud_colours_active_bg: primary,
                // Sidebar - white
                admbud_colours_menu_bg: '#ffffff', admbud_colours_menu_text: menuTxt,
                admbud_colours_active_text: onPrimary,
                admbud_colours_hover_text: contrastText(secondary),
                admbud_colours_active_parent_text: onPrimary,
                admbud_colours_sep_color: isLight ? mix(primary, anchor, 0.15) : lighten(primary, 0.78),
                admbud_colours_menu_item_sep: '1', admbud_colours_sidebar_gradient: '1',
                admbud_colours_sidebar_grad_dir: 'to bottom',
                admbud_colours_sidebar_grad_from: isLight ? primary : lighten(primary, 0.92),
                admbud_colours_sidebar_grad_to: '#ffffff',
                // Submenu - unified: bg=primary, text=onPrimary
                admbud_colours_submenu_bg: primary,
                admbud_colours_submenu_text: onPrimary,
                admbud_colours_submenu_hover_bg: secondary,
                admbud_colours_submenu_hover_text: onSecondary, admbud_colours_submenu_active_bg: primary, admbud_colours_submenu_active_text: onSecondary,
                // Admin bar - light tinted surface, flyout matches bar bg
                admbud_colours_adminbar_bg: isLight ? primary : lighten(primary, 0.92),
                admbud_colours_adminbar_text: menuTxt,
                admbud_colours_adminbar_hover_bg: primary,
                admbud_colours_adminbar_hover_text: onPrimary,
                admbud_colours_adminbar_submenu_bg: isLight ? primary : lighten(primary, 0.92),
                admbud_colours_adminbar_sub_text: menuTxt,
                admbud_colours_adminbar_sub_hover_bg: primary,
                admbud_colours_adminbar_sub_hover_text: onPrimary,
                // Chrome
                admbud_colours_body_bg: '#ffffff',
                admbud_colours_shadow_colour: isLight ? mix(primary, anchor, 0.25) : lighten(primary, 0.72),
                admbud_colours_pill_maintenance: '#dc2626', admbud_colours_pill_coming_soon: '#dc2626',
                admbud_colours_pill_noindex: '#d97706', admbud_colours_pill_admin_buddy: isLight ? anchor : primary,
                // Content area - light
                admbud_colours_content_heading: '#111827', admbud_colours_content_text: '#374151',
                admbud_colours_content_link: lightLink, admbud_colours_content_link_hover: lightLinkHvr,
                admbud_colours_table_header_bg: isLight ? primary : lighten(primary, 0.95), admbud_colours_table_header_text: '#111827',
                admbud_colours_table_row_bg: '#ffffff', admbud_colours_table_row_text: '#374151',
                admbud_colours_table_row_alt_bg: tLight, admbud_colours_table_row_alt_text: '#374151',
                admbud_colours_table_row_hover: tBase, admbud_colours_table_border: bdrLight,
                admbud_colours_table_row_separator: bdrLtr, admbud_colours_table_header_link: '#111827', admbud_colours_table_title_link: lightLink, admbud_colours_table_action_link: lightLink,
                admbud_colours_input_bg: '#ffffff', admbud_colours_input_border: bdrLight, admbud_colours_input_focus: primary,
                admbud_colours_btn_primary_bg: lightBtn, admbud_colours_btn_primary_text: contrastText(lightBtn), admbud_colours_btn_primary_hover: lightBtnHover,
                admbud_colours_btn_secondary_bg: tLightest,
                admbud_colours_postbox_bg: '#ffffff', admbud_colours_postbox_header_bg: tLight,
                admbud_colours_postbox_border: bdrLight, admbud_colours_postbox_text: '#374151', admbud_colours_notice_bg: '#ffffff',
                // Login
                admbud_login_bg_type: 'solid', admbud_login_bg_color: isLight ? primary : lighten(primary, 0.92),
                admbud_login_grad_from: isLight ? primary : lighten(primary, 0.92), admbud_login_grad_to: '#ffffff', admbud_login_grad_direction: 'to bottom right',
                // Coming Soon
                admbud_cs_bg_type: 'solid', admbud_cs_bg_color: isLight ? primary : lighten(primary, 0.92),
                admbud_cs_grad_from: isLight ? primary : lighten(primary, 0.92), admbud_cs_grad_to: '#ffffff', admbud_cs_grad_direction: 'to bottom right',
                admbud_cs_text_color: darken(anchor, 0.30), admbud_cs_message_color: darken(anchor, 0.10),
                // Maintenance
                admbud_maint_bg_type: 'solid', admbud_maint_bg_color: isLight ? primary : lighten(primary, 0.92),
                admbud_maint_grad_from: isLight ? primary : lighten(primary, 0.92), admbud_maint_grad_to: '#ffffff', admbud_maint_grad_direction: 'to bottom right',
                admbud_maint_text_color: darken(anchor, 0.30), admbud_maint_message_color: darken(anchor, 0.10)
            };

        } else {
            // ================================================================
            // DARK MODE
            // ================================================================
            var db = darken(primary, 0.88);
            if (lum(db) < 0.005) { db = lighten(db, 0.06); }
            var ds  = lighten(db, 0.08);
            var dbr = lighten(db, 0.16);
            var dt  = '#e0e0e8';
            var dm  = lighten(db, 0.50);
            if (lum(dm) < 0.25) { dm = lighten(db, 0.60); }
            var dh  = lighten(db, 0.10);
            var di  = lighten(db, 0.06);
            // Dark mode: buttons use primary directly, text adapts
            var darkBtnText = contrastText(primary);
            // Links: light primaries work on dark; dark primaries need lightening
            var darkLink = isLight ? primary : lighten(primary, 0.65);
            if (!isLight && lum(darkLink) < 0.3) { darkLink = lighten(primary, 0.80); }

            m = {
                admbud_colours_primary: primary, admbud_colours_secondary: secondary, admbud_colours_hover_bg: secondary, admbud_colours_active_bg: primary,
                // Sidebar - darkest
                admbud_colours_menu_bg: darken(db, 0.30),
                admbud_colours_menu_text: dm,
                admbud_colours_active_text: onPrimary,
                admbud_colours_hover_text: dt,
                admbud_colours_active_parent_text: onPrimary,
                admbud_colours_sep_color: primary,
                admbud_colours_menu_item_sep: '1', admbud_colours_sidebar_gradient: '1',
                admbud_colours_sidebar_grad_dir: 'to bottom',
                admbud_colours_sidebar_grad_from: darken(db, 0.30),
                admbud_colours_sidebar_grad_to: darken(db, 0.15),
                // Submenu - unified: bg=primary, text=onPrimary
                admbud_colours_submenu_bg: primary,
                admbud_colours_submenu_text: onPrimary,
                admbud_colours_submenu_hover_bg: secondary,
                admbud_colours_submenu_hover_text: onSecondary, admbud_colours_submenu_active_bg: primary, admbud_colours_submenu_active_text: onSecondary,
                // Admin bar - unified dark surface, flyout matches bar bg
                admbud_colours_adminbar_bg: darken(db, 0.40),
                admbud_colours_adminbar_text: dm,
                admbud_colours_adminbar_hover_bg: primary,
                admbud_colours_adminbar_hover_text: onPrimary,
                admbud_colours_adminbar_submenu_bg: darken(db, 0.40),
                admbud_colours_adminbar_sub_text: dm,
                admbud_colours_adminbar_sub_hover_bg: primary,
                admbud_colours_adminbar_sub_hover_text: onPrimary,
                // Chrome
                admbud_colours_body_bg: db, admbud_colours_shadow_colour: darken(db, 0.50),
                admbud_colours_pill_maintenance: '#ef4444', admbud_colours_pill_coming_soon: '#ef4444',
                admbud_colours_pill_noindex: '#f59e0b', admbud_colours_pill_admin_buddy: primary,
                // Content area - dark
                admbud_colours_content_heading: dt, admbud_colours_content_text: dm,
                admbud_colours_content_link: darkLink, admbud_colours_content_link_hover: primary,
                admbud_colours_table_header_bg: ds, admbud_colours_table_header_text: dt,
                admbud_colours_table_row_bg: db, admbud_colours_table_row_text: dm,
                admbud_colours_table_row_alt_bg: ds, admbud_colours_table_row_alt_text: dm,
                admbud_colours_table_row_hover: dh, admbud_colours_table_border: dbr,
                admbud_colours_table_row_separator: dbr, admbud_colours_table_header_link: dt, admbud_colours_table_title_link: darkLink, admbud_colours_table_action_link: darkLink,
                admbud_colours_input_bg: di, admbud_colours_input_border: dbr, admbud_colours_input_focus: primary,
                admbud_colours_btn_primary_bg: primary, admbud_colours_btn_primary_text: darkBtnText, admbud_colours_btn_primary_hover: secondary,
                admbud_colours_btn_secondary_bg: ds,
                admbud_colours_postbox_bg: ds, admbud_colours_postbox_header_bg: dh,
                admbud_colours_postbox_border: dbr, admbud_colours_postbox_text: dm, admbud_colours_notice_bg: ds,
                // Login
                admbud_login_bg_type: 'solid', admbud_login_bg_color: darken(db, 0.40),
                admbud_login_grad_from: darken(db, 0.40), admbud_login_grad_to: db, admbud_login_grad_direction: 'to bottom right',
                // Coming Soon
                admbud_cs_bg_type: 'gradient', admbud_cs_bg_color: darken(db, 0.40),
                admbud_cs_grad_from: darken(db, 0.40), admbud_cs_grad_to: db, admbud_cs_grad_direction: 'to bottom right',
                admbud_cs_text_color: dt, admbud_cs_message_color: dm,
                // Maintenance
                admbud_maint_bg_type: 'gradient', admbud_maint_bg_color: darken(db, 0.40),
                admbud_maint_grad_from: darken(db, 0.40), admbud_maint_grad_to: db, admbud_maint_grad_direction: 'to bottom right',
                admbud_maint_text_color: dt, admbud_maint_message_color: dm
            };
        }

        // Apply to all pickers/fields on the form
        Object.keys(m).forEach(function(id) {
            var el = qs('#' + id);
            if (!el) { return; }
            if (el.type === 'checkbox') { el.checked = m[id] === '1'; }
            else { el.value = m[id] || el.getAttribute('data-default-color') || ''; }
            el.removeAttribute('data-is-default');
            var hex = el.nextElementSibling;
            if (hex && hex.classList.contains('ab-color-hex')) { hex.textContent = el.value; }
        });
        // Reflect any proxy pickers to their canonical value post-apply.
        qsa('.ab-primary-proxy').forEach(function(proxy) {
            var targetId = proxy.getAttribute('data-proxies');
            var target   = targetId && qs('#' + targetId);
            if (!target || proxy.value === target.value) { return; }
            proxy.value = target.value;
            var proxyHex = proxy.nextElementSibling;
            if (proxyHex && proxyHex.classList.contains('ab-color-hex')) {
                proxyHex.textContent = proxy.value;
            }
        });
        ['admbud_colours_sidebar_gradient','admbud_colours_menu_item_sep'].forEach(function(id) {
            var el = qs('#'+id);
            if (el && el.type==='checkbox' && m[id]!==undefined) { el.checked=m[id]==='1'; el.dispatchEvent(new Event('change',{bubbles:true})); }
        });
        if (m.admbud_colours_sidebar_grad_dir) { qsa('input[name="admbud_colours_sidebar_grad_dir"]').forEach(function(r){ r.checked=r.value===m.admbud_colours_sidebar_grad_dir; }); }
        if (m.admbud_login_bg_type) { qsa('input[name="admbud_login_bg_type"]').forEach(function(r){ r.checked=r.value===m.admbud_login_bg_type; }); }
        var form = syncBtn.closest('form'), sb2 = form && qs('.ab-form-save-btn', form);
        if (sb2) { sb2.disabled = false; sb2.classList.remove('ab-btn--disabled-until-change'); }
        setTimeout(function(){ updateColourPreview(); }, 50);

        // Save login/maintenance/coming-soon values via AJAX
        var ajaxValues = {};
        Object.keys(m).forEach(function(k) {
            if (k.indexOf('admbud_login_') === 0 || k.indexOf('admbud_cs_') === 0 || k.indexOf('admbud_maint_') === 0) {
                ajaxValues[k] = m[k];
            }
        });
        if (Object.keys(ajaxValues).length && AJX) {
            var presetNonce = (qs('.ab-preset-apply[data-nonce]') || {}).getAttribute('data-nonce') || '';
            var fd = new FormData();
            fd.append('action', 'admbud_apply_palette');
            fd.append('nonce', presetNonce);
            fd.append('values', JSON.stringify(ajaxValues));
            fetch(AJX, { method: 'POST', body: fd });
        }

        var labels = { dark_sidebar:'Dark Sidebar', light_sidebar:'Light Sidebar', dark_mode:'Dark Mode' };
        window.showToast('Palette generated - ' + (labels[mode]||mode) + '. Tweak and Save.', 'success');
    }); }
    // -- Login tab ------------------------------------------------------------
    function syncBgType(skip) {
        var type = ( qs('input[name="admbud_login_bg_type"]:checked')||{} ).value;
        var m = { solid:'.ab-login-solid', gradient:'.ab-login-gradient', image:'.ab-login-image' };
        Object.keys(m).forEach(function(k){
            qsa(m[k]).forEach(function(el){ el.classList.toggle('ab-hidden', type!==k); });
        });
        if (!skip) { updateLoginPreview(); }
    }

    qsa('input[name="admbud_login_bg_type"]').forEach(function(r){ r.addEventListener('change', function(){ syncBgType(false); }); });
    on( document,'change','input[name="admbud_login_grad_direction"]', function() {
        qsa('.ab-direction-grid__cell', this.closest('.ab-direction-grid')).forEach(function(c){ c.classList.remove('ab-direction-grid__cell--active'); });
        this.closest('.ab-direction-grid__cell').classList.add('ab-direction-grid__cell--active');
        updateLoginPreview();
    });
    on( document,'change','input[name="admbud_login_card_position"]', function() {
        updateLoginPreviewCard();
    });

    // Generic radio card active state toggle (covers login card position, UI rounding, etc.)
    on( document, 'change', '.ab-card-pos-option input[type="radio"]', function() {
        var group = this.closest('.ab-card-position-selector');
        if (group) {
            qsa('.ab-card-pos-option', group).forEach(function(o){ o.classList.remove('ab-card-pos-option--active'); });
            this.closest('.ab-card-pos-option').classList.add('ab-card-pos-option--active');
        }
    });

    var opSlider = qs('#admbud_login_bg_overlay_opacity');
    if(opSlider){ opSlider.addEventListener('input',function(){ var s=qs('#admbud_overlay_op_val'); if(s){s.textContent=this.value+'%';} updateLoginPreview(); }); }
    var bgImgUrl = qs('#admbud_login_bg_image_url');
    if(bgImgUrl){ bgImgUrl.addEventListener('input', updateLoginPreview); }
    var logoUrl  = qs('#admbud_login_logo_url');
    if(logoUrl)  { logoUrl.addEventListener('input',  updateLoginPreview); }

    function updateLoginPreview() {
        var el=qs('#ab-login-preview'); if(!el){ return; }
        var type=(qs('input[name="admbud_login_bg_type"]:checked')||{}).value||'solid';
        if (type==='gradient') {
            var from=(qs('#admbud_login_grad_from')||{}).value||'#2e1065',
                to  =(qs('#admbud_login_grad_to'  )||{}).value||'#1e1b2e',
                dir =(qs('input[name="admbud_login_grad_direction"]:checked')||{}).value||'to bottom right';
            el.style.background='linear-gradient('+dir+','+from+','+to+')';
            var ov=qs('#ab-login-preview-overlay'); if(ov){ov.style.background='';}
        } else if (type==='image') {
            var img=(qs('#admbud_login_bg_image_url')||{}).value||'';
            el.style.background=img?'url("'+img+'") center/cover no-repeat':'#e2e8f0';
            var oc=(qs('#admbud_login_bg_overlay_color')||{}).value||'#000000',
                op=parseInt((qs('#admbud_login_bg_overlay_opacity')||{}).value||30,10),
                ov2=qs('#ab-login-preview-overlay');
            if(ov2){ov2.style.background=hexToRgba(oc,(op/100).toFixed(2));}
        } else {
            el.style.background=(qs('#admbud_login_bg_color')||{}).value||'#1e1b2e';
            var ov3=qs('#ab-login-preview-overlay'); if(ov3){ov3.style.background='';}
        }
        var lUrl=(qs('#admbud_login_logo_url')||{}).value||'',
            lImg=qs('#ab-preview-logo-img'), lWp=qs('#ab-preview-logo-wp'),
            wrap2=qs('#ab-preview-logo-wrap');
        if(lUrl&&wrap2){
            if(lImg){ lImg.src=lUrl; }
            else { wrap2.innerHTML='<img id="ab-preview-logo-img" src="'+escHtml(lUrl)+'" alt="" style="max-height:28px;max-width:90px;object-fit:contain;display:block;margin:0 auto;">'; }
        } else if (wrap2 && !lUrl) {
            var bc = ( qs( '#admbud_colours_primary' ) || {} ).value || S.primaryColour || '#7c3aed';
            // Default WordPress mark used when the user hasn't uploaded a login
            // logo yet. Each <path> is the original WP logo path data — kept as
            // single strings since path coordinates aren't really splittable.
            var wpPath1 = 'M92.6 0C41.4 0 0 41.4 0 92.6s41.4 92.6 92.6 92.6 92.6-41.4 92.6-92.6S143.8 0 92.6 0z'
                        + 'm0 13.3c43.8 0 79.3 35.5 79.3 79.3 0 43.8-35.5 79.3-79.3 79.3-43.8 0-79.3-35.5-79.3-79.3 '
                        + '0-43.8 35.5-79.3 79.3-79.3z';
            var wpPath2 = 'M18 92.6C18 130.9 42 163 75.7 175.4L26.2 45.7C21.1 59.7 18 76.4 18 92.6z'
                        + 'm140.6-4.2c0-12.1-4.3-20.4-8-26.9-5-8.1-9.6-14.9-9.6-23 0-9 6.8-17.4 16.4-17.4.4 0 .8 0 1.3.1'
                        + '-17.4-15.9-40.6-25.7-66.1-25.7-34.2 0-64.3 17.5-81.8 44.1 2.3.1 4.5.1 6.3.1 10.2 0 26-1.2 26-1.2 '
                        + '5.3-.3 5.9 7.4.7 8-5.3.3-10.7 1.1-10.7 1.1l34 101.2 20.4-61.3-14.5-39.9c-5.3-.3-10.3-1.1-10.3-1.1'
                        + '-5.3-.3-4.7-8.3.6-8 0 0 16.1 1.2 25.7 1.2 10.2 0 26-1.2 26-1.2 5.3-.3 5.9 7.4.7 8-5.3.3-10.7 1.1'
                        + '-10.7 1.1l33.7 100.3 9.3-31c4.1-13 7.2-22.3 7.2-30.4z';
            var wpPath3 = 'M93.8 99.9l-28 81.4c8.4 2.4 17.2 3.8 26.4 3.8 10.9 0 21.3-1.9 31-5.3-.2-.4-.5-.8-.7-1.2L93.8 99.9z'
                        + 'm76.7-50.7c.4 3.1.6 6.4.6 9.9 0 9.8-1.8 20.8-7.4 34.5l-29.7 85.9c28.9-16.8 48.4-47.9 48.4-83.6 '
                        + '0-17-4.4-32.9-12-46.7z';
            wrap2.innerHTML = '<svg id="ab-preview-logo-wp" width="28" height="28" viewBox="0 0 185.2 185.2"'
                            + ' fill="' + bc + '" style="display:block;margin:0 auto;">'
                            + '<path d="' + wpPath1 + '"/>'
                            + '<path d="' + wpPath2 + '"/>'
                            + '<path d="' + wpPath3 + '"/>'
                            + '</svg>';
        }
        var btn2=qs('#ab-preview-login-btn');
        if(btn2){ var c2=(qs('#admbud_colours_primary')||{}).value||S.primaryColour||'#7c3aed'; btn2.style.backgroundColor=c2; btn2.style.borderColor=c2; }
        updateLoginPreviewCard();
    }
    function updateLoginPreviewCard() {
        var pos   = ( qs( 'input[name="admbud_login_card_position"]:checked' ) || {} ).value || 'center';
        var card  = qs( '#ab-login-preview-card' );
        var wrap3 = qs( '#ab-login-preview' );
        if ( ! card || ! wrap3 ) { return; }

        if ( pos === 'left' || pos === 'right' ) {
            Object.assign( wrap3.style, {
                justifyContent: 'flex-start',
                alignItems:     'stretch',
            } );
            Object.assign( card.style, {
                position:       'absolute',
                top:            '0',
                bottom:         '0',
                left:           pos === 'left'  ? '0' : 'auto',
                right:          pos === 'right' ? '0' : 'auto',
                width:          '38%',
                borderRadius:   '0',
                display:        'flex',
                flexDirection:  'column',
                alignItems:     'stretch',
                justifyContent: 'center',
                padding:        '24px 18px',
                boxShadow:      pos === 'left'
                                    ? '4px 0 18px rgba(0,0,0,.18)'
                                    : '-4px 0 18px rgba(0,0,0,.18)',
                background:     'rgba(255,255,255,.97)',
            } );
        } else {
            Object.assign( wrap3.style, {
                justifyContent: 'center',
                alignItems:     'center',
            } );
            Object.assign( card.style, {
                position:       'relative',
                top:            'auto',
                bottom:         'auto',
                left:           'auto',
                right:          'auto',
                width:          '130px',
                borderRadius:   '4px',
                display:        'block',
                flexDirection:  '',
                alignItems:     '',
                justifyContent: '',
                padding:        '20px 22px 18px',
                boxShadow:      '0 1px 3px rgba(0,0,0,.13)',
                background:     '#fff',
            } );
        }
    }
    syncBgType(true); updateLoginPreview();

    // -- Maintenance tab ------------------------------------------------------
    function syncModeCards() {
        var mode=(qs('input[name="admbud_maintenance_mode"]:checked')||{}).value||'off';
        qsa('.ab-mode-option').forEach(function(o){ o.classList.remove('ab-mode-option--active'); });
        var ch=qs('input[name="admbud_maintenance_mode"]:checked');
        if(ch){ var op=ch.closest('.ab-mode-option'); if(op){op.classList.add('ab-mode-option--active');} }
        qsa('.ab-mode-fields--coming_soon').forEach(function(e){ e.classList.toggle('ab-hidden', mode!=='coming_soon'); });
        qsa('.ab-mode-fields--maintenance').forEach(function(e){ e.classList.toggle('ab-hidden', mode!=='maintenance'); });
        qsa('.ab-mode-fields-off').forEach(function(e){ e.classList.toggle('ab-hidden', mode==='off'); });
        updateMaintPreview(); syncModeBgType('cs'); syncModeBgType('maint');
    }
    function syncModeBgType(prefix) {
        var type=(qs('input[name="admbud_'+prefix+'_bg_type"]:checked')||{}).value||'solid';
        [{k:'solid',c:'.ab-'+prefix+'-solid'},{k:'gradient',c:'.ab-'+prefix+'-gradient'},{k:'image',c:'.ab-'+prefix+'-image'}].forEach(function(m){
            qsa(m.c).forEach(function(el){ el.classList.toggle('ab-hidden',type!==m.k); });
        });
    }
    qsa('input[name="admbud_maintenance_mode"]').forEach(function(r){ r.addEventListener('change', syncModeCards); });
    qsa('input[name="admbud_cs_bg_type"]').forEach(function(r){ r.addEventListener('change', function(){ syncModeBgType('cs'); }); });
    qsa('input[name="admbud_maint_bg_type"]').forEach(function(r){ r.addEventListener('change', function(){ syncModeBgType('maint'); }); });
    on( document,'change','input[name="admbud_cs_grad_direction"],input[name="admbud_maint_grad_direction"]', function() {
        qsa('.ab-direction-grid__cell',this.closest('.ab-direction-grid')).forEach(function(c){ c.classList.remove('ab-direction-grid__cell--active'); });
        this.closest('.ab-direction-grid__cell').classList.add('ab-direction-grid__cell--active');
    });
    var csOp=qs('#admbud_cs_bg_overlay_opacity');
    if(csOp){ csOp.addEventListener('input',function(){ var s=qs('#admbud_cs_overlay_op_val'); if(s){s.textContent=this.value+'%';} }); }
    var mOp=qs('#admbud_maint_bg_overlay_opacity');
    if(mOp){ mOp.addEventListener('input',function(){ var s=qs('#admbud_maint_overlay_op_val'); if(s){s.textContent=this.value+'%';} }); }

    // Build the iframe srcdoc HTML for the maintenance page preview.
    // Split into named pieces so each line stays under ~200 chars and the
    // CSS rules are individually readable / diffable.
    function buildMaintPreviewSrcdoc( bg, headingColor, msgColor, title, message ) {
        var resetCss   = '*{box-sizing:border-box;margin:0;padding:0}'
                       + 'html,body{width:100%;height:100%}';
        var bodyCss    = 'body{'
                       +     'font-family:-apple-system,sans-serif;'
                       +     'display:flex;align-items:center;justify-content:center;'
                       +     'background:' + bg + ';'
                       +     'color:' + headingColor + ';'
                       +     'padding:1.5rem'
                       + '}';
        var cardCss    = '.card{text-align:center;max-width:280px}';
        var titleCss   = '.title{'
                       +     'font-size:1.1rem;font-weight:700;'
                       +     'margin-bottom:.5rem;'
                       +     'color:' + headingColor
                       + '}';
        var msgCss     = '.msg{font-size:.78rem;line-height:1.5;color:' + msgColor + '}';
        var styleBlock = '<style>' + resetCss + bodyCss + cardCss + titleCss + msgCss + '</style>';
        var body       = '<div class="card">'
                       +     '<div class="title">' + title + '</div>'
                       +     '<div class="msg">'   + message + '</div>'
                       + '</div>';
        return '<!DOCTYPE html><html><head><meta charset="UTF-8">'
             + styleBlock
             + '</head><body>'
             + body
             + '</body></html>';
    }

    function buildMaintPreviewEmptySrcdoc() {
        var css = '*{box-sizing:border-box;margin:0;padding:0}'
                + 'html,body{width:100%;height:100%}'
                + 'body{'
                +     'font-family:-apple-system,sans-serif;'
                +     'display:flex;align-items:center;justify-content:center;'
                +     'background:#f1f5f9;color:#94a3b8;'
                +     'padding:1.5rem;text-align:center'
                + '}'
                + '.msg{font-size:.78rem;line-height:1.6}';
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
             + css
             + '</style></head><body>'
             + '<div class="msg">Enable a mode to see preview.</div>'
             + '</body></html>';
    }

    function updateMaintPreview() {
        var iframe = qs( '#ab-maint-page-preview' );
        if ( ! iframe ) { return; }

        var mode = ( qs( 'input[name="admbud_maintenance_mode"]:checked' ) || {} ).value || 'off';
        if ( mode === 'off' ) {
            iframe.srcdoc = buildMaintPreviewEmptySrcdoc();
            return;
        }

        var pfx    = mode === 'coming_soon' ? 'cs' : 'maint';
        var bgType = ( qs( 'input[name="admbud_' + pfx + '_bg_type"]:checked' ) || {} ).value || 'solid';
        var bg;
        if ( bgType === 'gradient' ) {
            var gf = ( qs( '#admbud_' + pfx + '_grad_from' ) || {} ).value || '#2e1065';
            var gt = ( qs( '#admbud_' + pfx + '_grad_to'   ) || {} ).value || '#1e1b2e';
            var gd = ( qs( 'input[name="admbud_' + pfx + '_grad_direction"]:checked' ) || {} ).value || 'to bottom right';
            bg = 'linear-gradient(' + gd + ',' + gf + ',' + gt + ')';
        } else if ( bgType === 'image' ) {
            var iu = ( qs( '#admbud_' + pfx + '_bg_image_url' ) || {} ).value || '';
            bg = iu ? 'url("' + iu + '") center/cover no-repeat' : '#1e1b2e';
        } else {
            bg = ( qs( '#admbud_' + pfx + '_bg_color' ) || {} ).value || '#1e1b2e';
        }

        var hc      = ( qs( '#admbud_' + pfx + '_text_color'    ) || {} ).value || '#ede9fe';
        var mc      = ( qs( '#admbud_' + pfx + '_message_color' ) || {} ).value || '#c4b5fd';
        var tid     = pfx === 'maint' ? '#admbud_maintenance_title'   : '#admbud_coming_soon_title';
        var mid     = pfx === 'maint' ? '#admbud_maintenance_message' : '#admbud_coming_soon_message';
        var ttl     = escHtml( ( qs( tid ) || {} ).value || ( pfx === 'maint' ? 'Under Maintenance' : 'Coming Soon' ) );
        var msg2    = escHtml( ( qs( mid ) || {} ).value || ( pfx === 'maint' ? "We'll be back shortly." : "We're working on something exciting." ) );

        iframe.srcdoc = buildMaintPreviewSrcdoc( bg, hc, mc, ttl, msg2 );
    }

    [ ['change','input[name="admbud_maintenance_mode"]'],
      ['change','#admbud_cs_bg_color,#admbud_cs_grad_from,#admbud_cs_grad_to,#admbud_cs_text_color,#admbud_cs_message_color'],
      ['change','#admbud_maint_bg_color,#admbud_maint_grad_from,#admbud_maint_grad_to,#admbud_maint_text_color,#admbud_maint_message_color'],
      ['change','input[name="admbud_cs_bg_type"],input[name="admbud_cs_grad_direction"],input[name="admbud_maint_bg_type"],input[name="admbud_maint_grad_direction"]'],
      ['input', '#admbud_coming_soon_title,#admbud_coming_soon_message,#admbud_maintenance_title,#admbud_maintenance_message']
    ].forEach(function(pair){
        pair[1].split(',').forEach(function(sel){
            on(document, pair[0], sel.trim(), function(){ updateMaintPreview(); });
        });
    });
    syncModeCards(); syncModeBgType('cs'); syncModeBgType('maint'); updateMaintPreview();

    // -- Emergency URL: copy --------------------------------------------------
    var copyBtn=qs('#ab-copy-emergency-url');
    if(copyBtn){ copyBtn.addEventListener('click', function(){
        var url=(qs('#ab-emergency-url')||{}).value||''; if(!url){return;}
        navigator.clipboard && navigator.clipboard.writeText(url).then(function(){ showFeedback(S.copiedText||'✓ Copied'); });
    }); }

    // -- Emergency URL: regenerate --------------------------------------------
    var regenBtn=qs('#ab-regenerate-token');
    if(regenBtn){ regenBtn.addEventListener('click', function(){
        var nonce=this.getAttribute('data-nonce');
        var btn=this;
        function doRegen(){
            btn.disabled=true; btn.textContent=S.regeneratingText||'Regenerating…';
            post({action:'admbud_regenerate_token',nonce:nonce}).then(function(r){
                btn.disabled=false; btn.textContent=S.regenerateText||'Regenerate';
                if(r.success&&r.data&&r.data.url){
                    var u=qs('#ab-emergency-url'); if(u){u.value=r.data.url;}
                    showFeedback(S.regeneratedText||'✓ Token regenerated');
                } else { showFeedback(S.errorText||'✗ Something went wrong.'); }
            }).catch(function(){ btn.disabled=false; btn.textContent=S.regenerateText||'Regenerate'; showFeedback(S.errorText||'✗ Something went wrong.'); });
        }
        window.openConfirmModal(S.regenerateConfirmTitle||'Regenerate Emergency URL?', S.regenerateConfirm||'The old URL will stop working immediately.', doRegen);
    }); }

    function showFeedback(msg) {
        var fb=qs('#ab-token-feedback');
        if(!fb){return;}
        fb.textContent=msg; fb.style.display='';
        setTimeout(function(){ fb.style.opacity='0'; fb.style.transition='opacity 0.3s'; setTimeout(function(){fb.style.display='none';fb.style.opacity='';},320); },3000);
    }

    // -- Colours tab ----------------------------------------------------------
    function updateColourPreview() {
        var primary  = (qs('#admbud_colours_primary')||{}).value||'#7c3aed';
        var menuBg   = (qs('#admbud_colours_menu_bg')||{}).value||'#1e1b2e';
        var menuTxt  = (qs('#admbud_colours_menu_text')||{}).value||'#ede9fe';
        var adminbarBg = (qs('#admbud_colours_adminbar_bg')||{}).value || menuBg;
        var sidebarBg = menuBg;
        var gradTog = qs('#admbud_colours_sidebar_gradient');
        if(gradTog&&gradTog.checked){
            var gf2=(qs('#admbud_colours_sidebar_grad_from')||{}).value||'#2e1065',
                gt2=(qs('#admbud_colours_sidebar_grad_to'  )||{}).value||'#1e1b2e',
                gd2=(qs('input[name="admbud_colours_sidebar_grad_dir"]:checked')||{}).value||'to bottom';
            sidebarBg='linear-gradient('+gd2+','+gf2+','+gt2+')';
        }
        // Sidebar
        var ps=qs('#ab-preview-sidebar'); if(ps){ps.style.background=sidebarBg;}
        var pl=qs('#ab-preview-logo'); if(pl){var pld=pl.querySelector('div'); if(pld){pld.style.background=primary;}}
        var pa=qs('#ab-preview-item-active'); if(pa){pa.style.background=primary; var pad=pa.querySelector('div'); if(pad){pad.style.background=menuTxt;}}
        // Admin bar strip
        var pAdminbar=qs('#ab-preview-adminbar'); if(pAdminbar){pAdminbar.style.background=adminbarBg;}
        // Content area bg
        var bodyBg=(qs('#admbud_colours_body_bg')||{}).value||'#f0f0f1';
        var pc=qs('#ab-preview-content'); if(pc){pc.style.background=bodyBg;}
        // Primary button preview
        var btnPrimary=(qs('#admbud_colours_btn_primary_bg')||{}).value||primary;
        qsa('#ab-preview-content [style*="btn_primary"], #ab-preview-content div[style]').forEach(function(el){
            // Update button-coloured elements via data approach is complex - just update primary-bg elements
        });
        // Table header
        var tblHdrBg=(qs('#admbud_colours_table_header_bg')||{}).value||'#f4effd';
        var tblHdrTxt=(qs('#admbud_colours_table_header_text')||{}).value||'#1d2327';
        // Row colours
        var rowBg=(qs('#admbud_colours_table_row_bg')||{}).value||'#ffffff';
        var altBg=(qs('#admbud_colours_table_row_alt_bg')||{}).value||'#f9f7fe';
        var linkC=(qs('#admbud_colours_content_link')||{}).value||primary;
        var sepC=(qs('#admbud_colours_table_row_separator')||{}).value||'#ebe1fc';
        var headingC=(qs('#admbud_colours_content_heading')||{}).value||'#1d2327';
        var inputBdr=(qs('#admbud_colours_input_border')||{}).value||'#d1baf8';
        var inputBg=(qs('#admbud_colours_input_bg')||{}).value||'#ffffff';
        var pboxBg=(qs('#admbud_colours_postbox_bg')||{}).value||'#ffffff';
        var pboxHdr=(qs('#admbud_colours_postbox_header_bg')||{}).value||'#f9f7fe';
        var pboxBdr=(qs('#admbud_colours_postbox_border')||{}).value||'#decdfa';
        // Apply to preview elements by ID
        var pContent=qs('#ab-preview-content');
        if(pContent){
            var kids=pContent.children;
            // [0]=title+btn row, [1]=filter tabs, [2]=table header, [3-5]=table rows, [6]=input row, [7]=postbox
            if(kids[0]){ kids[0].style.borderBottomColor='rgba(0,0,0,0.06)'; var btn=kids[0].children[1]; if(btn){btn.style.background=btnPrimary;} }
            if(kids[2]){ kids[2].style.background=tblHdrBg; }
            var rowEls=[kids[3],kids[4],kids[5]];
            var rowBgs=[rowBg,altBg,rowBg];
            rowEls.forEach(function(r,i){ if(r){ r.style.background=rowBgs[i]; r.style.borderBottomColor=sepC; var lnk=r.children[1]; if(lnk){lnk.style.background=linkC;} } });
            if(kids[6]){ var inp=kids[6].children[0]; if(inp){inp.style.borderColor=inputBdr; inp.style.background=inputBg;} var b2=kids[6].children[1]; if(b2){b2.style.background=btnPrimary;} }
            if(kids[7]){ kids[7].style.background=pboxBg; kids[7].style.borderColor=pboxBdr;
                var ph=kids[7].children[0]; if(ph){ph.style.background=pboxHdr; ph.style.borderBottomColor=pboxBdr;} }
        }
    }
    function updateContrastBadge() {
        var bg =(qs('#admbud_colours_menu_bg'  )||{}).value||'#1e1b2e',
            txt=(qs('#admbud_colours_menu_text')||{}).value||'#ede9fe';
        var ratio=wcagRatio(bg,txt), ok=ratio>=4.5;
        var rb=qs('#ab-contrast-ratio'); if(rb){rb.textContent=ratio.toFixed(2);}
        var badge=qs('#ab-contrast-badge');
        if(badge){
            badge.classList.toggle('ab-contrast-badge--ok',ok);
            badge.classList.toggle('ab-contrast-badge--warn',!ok);
        }
    }
    var menuBgEl=qs('#admbud_colours_menu_bg');
    if(menuBgEl){ menuBgEl.addEventListener('change',function(){
        var suggest=wcagSuggest(this.value);
        var hint=qs('#ab-hint-menu-text'); if(hint){hint.textContent=suggest;}
        qsa('.ab-contrast-suggest').forEach(function(el){el.setAttribute('data-colour',suggest);});
        updateContrastBadge(); updateColourPreview();
    }); }
    var menuTxtEl=qs('#admbud_colours_menu_text');
    if(menuTxtEl){ menuTxtEl.addEventListener('change',function(){ updateContrastBadge(); updateColourPreview(); }); }
    on( document,'click','.ab-contrast-suggest',function(e){
        e.preventDefault();
        var c=this.getAttribute('data-colour'); if(!c){return;}
        var p=qs('#admbud_colours_menu_text'); if(p){p.value=c; p.dispatchEvent(new Event('input')); updateContrastBadge(); updateColourPreview();}
    });
    // Proxy color pickers: an .ab-primary-proxy input has no `name` attribute
    // (so it never submits) and mirrors the value of the field named in its
    // data-proxies attribute. Keeps the Auto Palette pane inline-editable while
    // the canonical input (#admbud_colours_primary on the Accent pane) remains the
    // single source of truth for both save and read.
    qsa('.ab-primary-proxy').forEach(function(proxy) {
        var targetId = proxy.getAttribute('data-proxies');
        if (!targetId) { return; }
        var target = qs('#' + targetId);
        if (!target) { return; }

        // Proxy -> canonical: user edits proxy, write through to canonical and
        // dispatch events so preview/tint/save-button listeners fire.
        function proxyChanged() {
            if (target.value !== proxy.value) {
                target.value = proxy.value;
                target.removeAttribute('data-is-default');
                // Update adjacent hex label on canonical input.
                var canonHex = target.nextElementSibling;
                if (canonHex && canonHex.classList.contains('ab-color-hex')) {
                    canonHex.textContent = target.value;
                }
                target.dispatchEvent(new Event('input',  { bubbles: true }));
                target.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
        proxy.addEventListener('input',  proxyChanged);
        proxy.addEventListener('change', proxyChanged);

        // Canonical -> proxy: if user edits the canonical input (e.g. on Accent
        // pane) keep the proxy display in sync. No-op if values already match.
        function canonicalChanged() {
            if (proxy.value !== target.value) {
                proxy.value = target.value;
                var proxyHex = proxy.nextElementSibling;
                if (proxyHex && proxyHex.classList.contains('ab-color-hex')) {
                    proxyHex.textContent = proxy.value;
                }
            }
        }
        target.addEventListener('input',  canonicalChanged);
        target.addEventListener('change', canonicalChanged);
    });

    [ '#admbud_colours_primary','#admbud_colours_secondary','#admbud_colours_adminbar_bg' ].forEach(function(sel){
        var el=qs(sel); if(el){ el.addEventListener('change',updateColourPreview); }
    });
    // Sidebar gradient toggle
    var gradToggle=qs('#admbud_colours_sidebar_gradient');
    if(gradToggle){ gradToggle.addEventListener('change',function(){
        qsa('.ab-sidebar-grad-fields').forEach(function(r){ r.classList.toggle('ab-hidden',!this.checked); },this);
        updateColourPreview();
    }); }
    on( document,'change','input[name="admbud_colours_sidebar_grad_dir"]', function(){
        qsa('.ab-direction-grid__cell').forEach(function(c){ c.classList.remove('ab-direction-grid__cell--active'); });
        this.closest('.ab-direction-grid__cell').classList.add('ab-direction-grid__cell--active');
        updateColourPreview();
    });
    on( document,'change','#admbud_colours_sidebar_grad_from,#admbud_colours_sidebar_grad_to', function(){ updateColourPreview(); });

    if(qs('#ab-preview-sidebar')){ updateColourPreview(); updateContrastBadge(); }

    // -- Colour preset apply --------------------------------------------------
    on( document,'click','.ab-preset-apply', function() {
        var btn=this, preset=btn.getAttribute('data-preset'), nonce=btn.getAttribute('data-nonce'),
            ajaxUrl=btn.getAttribute('data-ajax-url'),
            name2=btn.closest('.ab-preset-card')?.querySelector('.ab-preset-name')?.textContent||'';
        window.openConfirmModal(
            'Apply Preset: '+name2+'?',
            'This will overwrite all your current colour settings with the "'+name2+'" preset.',
            function(){
                btn.disabled=true; btn.textContent=S.saving||'Applying…';
                var fd=new FormData();
                fd.append('action','admbud_apply_preset'); fd.append('preset',preset); fd.append('nonce',nonce);
                fetch(ajaxUrl,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(res){
                    if(res.success){ window.location.href=window.location.pathname+'?page=admbud&tab=colours&admbud_subtab=presets&admbud_notice=saved'; }
                    else { alert('Could not apply preset.'); btn.disabled=false; btn.textContent='Apply'; }
                }).catch(function(){ alert('Could not apply preset.'); btn.disabled=false; btn.textContent='Apply'; });
            }
        );
    });

    // -- UI Tweaks: footer field show/hide -------------------------------------
    var footerToggle=qs('#admbud_core_custom_footer_enabled');
    function syncFooter(){ 
        var on = footerToggle && footerToggle.checked;
        qsa('.ab-footer-text-field').forEach(function(r){ r.classList.toggle('ab-hidden',!on); });
        qsa('.ab-footer-version-field').forEach(function(r){ r.classList.toggle('ab-hidden',!on); });
    }
    if(footerToggle){ footerToggle.addEventListener('change', syncFooter); }
    syncFooter();

    // -- CSS Exclusions: sync detected-plugin toggles → hidden textarea -------
    on( document, 'change', '.ab-exclusion-toggle', function() {
        var textarea = qs('#admbud_css_exclusions');
        if (!textarea) { return; }
        // Rebuild the exclusion list from all checked toggles + any manual entries.
        var prefixes = [];
        qsa('.ab-exclusion-toggle:checked').forEach(function(cb) {
            var p = cb.getAttribute('data-prefix');
            if (p) { prefixes.push(p); }
        });
        // Preserve manual entries that don't match any detected prefix.
        var manual = qs('#admbud_css_exclusions_manual');
        if (manual) {
            var manualLines = manual.value.split('\n').map(function(l){ return l.trim(); }).filter(function(l){ return l !== ''; });
            var detectedPrefixes = qsa('.ab-exclusion-toggle').map(function(cb){ return cb.getAttribute('data-prefix'); });
            manualLines.forEach(function(line) {
                var isDetected = detectedPrefixes.some(function(dp){ return dp === line; });
                if (!isDetected && prefixes.indexOf(line) === -1) { prefixes.push(line); }
            });
        }
        textarea.value = prefixes.join('\n');
    });

    // -- Bricks: colour pickers + reset + enable guard -------------------------
    on( document,'input','.ab-bricks-colour-picker', function(){
        var picker=this, val=picker.value;
        var hex=picker.parentElement.querySelector('.ab-bricks-colour-hex'); if(hex){hex.textContent=val;}
        var reset=picker.parentElement.querySelector('.ab-bricks-colour-reset'); if(reset){reset.classList.remove('ab-hidden');}
        var synced=picker.parentElement.querySelector('.ab-bricks-synced-badge');
        if(synced){ synced.className='ab-bricks-override-badge'; synced.textContent='Custom'; }
        var form=picker.closest('form'), sb=form&&qs('.ab-form-save-btn',form);
        if(sb){ sb.disabled=false; sb.classList.remove('ab-btn--disabled-until-change'); }
    });
    on( document,'click','.ab-bricks-colour-reset', function(){
        var btn=this, derived=btn.getAttribute('data-derived'), option=btn.getAttribute('data-option'),
            td=btn.closest('td'), picker=td.querySelector('.ab-bricks-colour-picker');
        if(picker){ picker.setAttribute('name',option+'__skip'); picker.value=derived; }
        var hex=td.querySelector('.ab-bricks-colour-hex'); if(hex){hex.textContent=derived;}
        if(!td.querySelector('input[name="'+option+'"]')){
            var h=document.createElement('input'); h.type='hidden'; h.name=option; h.value=''; td.appendChild(h);
        } else { td.querySelector('input[name="'+option+'"]').value=''; }
        btn.classList.add('ab-hidden');
        var ob=td.querySelector('.ab-bricks-override-badge'); if(ob){ ob.className='ab-bricks-synced-badge'; ob.textContent='Synced'; }
        var form=btn.closest('form'), sb=form&&qs('.ab-form-save-btn',form);
        if(sb){ sb.disabled=false; sb.classList.remove('ab-btn--disabled-until-change'); }
    });
    on( document,'change','.ab-bricks-main-toggle', function(){
        var t=this,
            installed=t.getAttribute('data-bricks-installed')==='1',
            active   =t.getAttribute('data-bricks-active')==='1';
        if(!t.checked){ var form=t.closest('form'),sb=form&&qs('.ab-form-save-btn',form); if(sb){sb.disabled=false;sb.classList.remove('ab-btn--disabled-until-change');} return; }
        if(!installed){ t.checked=false; window.openConfirmModal('Bricks Not Found','Please install Bricks theme, then enable this integration.',function(){}, null, 'ab-btn--primary'); return; }
        if(!active)   { t.checked=false; window.openConfirmModal('Bricks Not Active', 'Please activate Bricks theme, then enable this integration.',function(){}, null, 'ab-btn--primary'); return; }
        var form2=t.closest('form'),sb2=form2&&qs('.ab-form-save-btn',form2); if(sb2){sb2.disabled=false;sb2.classList.remove('ab-btn--disabled-until-change');}
    });

    // -- Dashboard: role-based page mapping ----------------------------------
    // Toggle role overrides grid visibility
    var roleToggle = qs('#admbud_dashboard_role_overrides_toggle');
    if (roleToggle) {
        roleToggle.addEventListener('change', function(){
            var grid = qs('#ab-dashboard-role-grid');
            if (grid) { grid.classList.toggle('ab-hidden', !this.checked); }
            syncDashboardRolePages();
            var form=this.closest('form'),sb=form&&qs('.ab-form-save-btn',form);
            if(sb){sb.disabled=false;sb.classList.remove('ab-btn--disabled-until-change');}
        });
    }

    function syncDashboardRolePages(){
        var map = {};
        var toggle = qs('#admbud_dashboard_role_overrides_toggle');
        map._per_role = toggle ? toggle.checked : false;
        qsa('.ab-dashboard-page-select').forEach(function(sel){
            var role = sel.getAttribute('data-role');
            var val = sel.value;
            if (!role) return;
            if (role === '_default') {
                if (val && val !== '0') { map._default = parseInt(val, 10); }
            } else {
                if (val === 'wp_default') { map[role] = 'wp_default'; }
                else if (val && val !== '') { map[role] = parseInt(val, 10); }
            }
        });
        var inp = qs('#admbud_dashboard_role_pages');
        if (inp) { inp.value = JSON.stringify(map); }
    }
    on( document, 'change', '.ab-dashboard-page-select', function(){
        syncDashboardRolePages();
        var form=this.closest('form'),sb=form&&qs('.ab-form-save-btn',form);
        if(sb){sb.disabled=false;sb.classList.remove('ab-btn--disabled-until-change');}
    });

    // -- Dashboard: custom widgets + keep-list ---------------------------------
    function syncWidgetKeepList(){
        var ids=qsa('.ab-widget-keep-toggle:checked').map(function(i){return i.getAttribute('data-widget-id');});
        var inp=qs('#admbud_dashboard_keep_widgets'); if(inp){inp.value=JSON.stringify(ids);}
    }
    function syncCustomWidgets(){
        var widgets=qsa('.ab-custom-widget-item').map(function(item){
            return { title:(qs('.ab-cw-title',item)||{}).value||'', content:(qs('.ab-cw-content',item)||{}).value||'' };
        });
        var inp=qs('#admbud_dashboard_custom_widgets'); if(inp){inp.value=JSON.stringify(widgets);}
    }
    on( document,'change','.ab-widget-keep-toggle', function(){
        syncWidgetKeepList();
        var form=this.closest('form'),sb=form&&qs('.ab-form-save-btn',form);
        if(sb){sb.disabled=false;sb.classList.remove('ab-btn--disabled-until-change');}
    });
    // Inverted toggle: grid checkbox ON = show widget, but hidden field stores "hide" value
    on( document,'change','[data-invert-sync]', function(){
        var target = qs('#' + this.getAttribute('data-invert-sync'));
        if (target) { target.value = this.checked ? '0' : '1'; }
        var form=this.closest('form'),sb=form&&qs('.ab-form-save-btn',form);
        if(sb){sb.disabled=false;sb.classList.remove('ab-btn--disabled-until-change');}
    });
    on( document,'input','.ab-cw-title,.ab-cw-content', function(){
        syncCustomWidgets();
        var form=this.closest('form'),sb=form&&qs('.ab-form-save-btn',form);
        if(sb){sb.disabled=false;sb.classList.remove('ab-btn--disabled-until-change');}
    });
    var cwAdd=qs('#ab-cw-add-widget');
    if(cwAdd){ cwAdd.addEventListener('click', function(){
        var list=qs('#ab-custom-widgets-list'); if(!list){return;}
        var item = document.createElement( 'div' );
        item.className = 'ab-custom-widget-item';
        item.innerHTML =
            '<div class="ab-custom-widget-fields">'
            +     '<input type="text" class="ab-cw-title regular-text"'
            +     ' aria-label="Widget title" placeholder="Widget title">'
            +     '<textarea class="ab-cw-content large-text" rows="3"'
            +     ' aria-label="Widget content" placeholder="Widget content"></textarea>'
            + '</div>'
            + '<button type="button" class="ab-btn ab-btn--ghost ab-cw-remove">'
            +     '<svg width="14" height="14" fill="none" viewBox="0 0 24 24"'
            +     ' stroke="currentColor" stroke-width="2">'
            +         '<line x1="18" y1="6"  x2="6"  y2="18"/>'
            +         '<line x1="6"  y1="6"  x2="18" y2="18"/>'
            +     '</svg>'
            + '</button>';
        list.appendChild(item); item.querySelector('.ab-cw-title').focus();
        var form=this.closest('form'),sb=form&&qs('.ab-form-save-btn',form);
        if(sb){sb.disabled=false;sb.classList.remove('ab-btn--disabled-until-change');}
    }); }
    on( document,'click','.ab-cw-remove', function(){
        var btn=this;
        var form=btn.closest('form'),sb=form&&qs('.ab-form-save-btn',form);
        if(typeof window.openConfirmModal==='function'){
            window.openConfirmModal(
                S.removeWidget || 'Remove widget?',
                S.removeWidgetBody || 'This widget will be removed. Save to apply.',
                function(){
                    btn.closest('.ab-custom-widget-item').remove();
                    syncCustomWidgets();
                    if(sb){sb.disabled=false;sb.classList.remove('ab-btn--disabled-until-change');}
                },
                'Remove','ab-btn--danger'
            );
        } else {
            btn.closest('.ab-custom-widget-item').remove();
            syncCustomWidgets();
            if(sb){sb.disabled=false;sb.classList.remove('ab-btn--disabled-until-change');}
        }
    });

    // -- Tools: export checkboxes ----------------------------------------------
    function updateExportBtn(){
        var any=qsa('.ab-export-checkbox:checked').length>0;
        var btn=qs('#ab-export-btn'); if(btn){btn.disabled=!any;}
        var msg=qs('#ab-export-none-msg'); if(msg){msg.style.display=any?'none':'';}
    }
    function updateExportNotices(){
        var rolesChecked=!!(qs('.ab-export-checkbox[value="roles"]:checked'));
        var rolesNotice=qs('#ab-export-roles-notice'); if(rolesNotice){rolesNotice.style.display=rolesChecked?'':'none';}
    }
    on( document,'change','.ab-export-checkbox', function(){ updateExportBtn(); updateExportNotices(); });
    on( document,'click','#ab-export-select-all',   function(){ qsa('.ab-export-checkbox').forEach(function(c){c.checked=true;});  updateExportBtn(); updateExportNotices(); });
    on( document,'click','#ab-export-deselect-all', function(){ qsa('.ab-export-checkbox').forEach(function(c){c.checked=false;}); updateExportBtn(); updateExportNotices(); });
    updateExportBtn(); updateExportNotices();


    // -- Setup tab: module toggle (used by non-React fallback path) ------------
    // The React component (setup-modules.js) handles toggles in the React grid.
    // This handler covers any non-React toggle that might exist.
    on( document,'change','.ab-setup-tab-toggle', function() {
        var input=this, grid=input.closest('.ab-setup-modules');
        var slug   = input.getAttribute('data-slug'),
            enabled= input.checked?'1':'0',
            ajaxUrl= grid&&grid.getAttribute('data-ajax-url'),
            nonce  = grid&&grid.getAttribute('data-nonce');
        if(!ajaxUrl||!nonce){ return; }
        if(grid.classList.contains('ab-setup--saving')){ input.checked=!input.checked; return; }
        grid.classList.add('ab-setup--saving');
        var fd=new FormData();
        fd.append('action','admbud_setup_toggle'); fd.append('nonce',nonce);
        fd.append('slug',slug); fd.append('enabled',enabled);
        fetch(ajaxUrl,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(res){
            if(res.success){ window.location.reload(); }
            else { grid.classList.remove('ab-setup--saving'); input.checked=!input.checked; }
        }).catch(function(){ grid.classList.remove('ab-setup--saving'); input.checked=!input.checked; });
    });

    // ========================================================================
    // window.AdmbudIcon - shared icon utilities used by all picker modules
    // (Custom Pages, Option Pages, Collections, Menu Customiser)
    //
    // Each tab-*.js module delegates to these instead of reimplementing.
    // ========================================================================
    ( function () {

        /**
         * Normalise hardcoded fill/stroke colours on an SVG element to currentColor.
         * Called when preserve_colors is false.
         * Skips elements with fill="none" or stroke="none" (structural, not decorative).
         *
         * @param {SVGElement} svg
         */
        function normaliseSvgColors( svg ) {
            svg.querySelectorAll( '[fill]' ).forEach( function ( el ) {
                if ( el.getAttribute( 'fill' ) !== 'none' ) {
                    el.setAttribute( 'fill', 'currentColor' );
                }
            } );
            svg.querySelectorAll( '[stroke]' ).forEach( function ( el ) {
                if ( el.getAttribute( 'stroke' ) !== 'none' ) {
                    el.setAttribute( 'stroke', 'currentColor' );
                }
            } );
            var rootFill = svg.getAttribute( 'fill' );
            if ( rootFill && rootFill !== 'none' ) {
                svg.setAttribute( 'fill', 'currentColor' );
            }
        }

        /**
         * Build and append a library icon <button> into a grid element.
         * Handles preserve_colors dot and ab-icon--normalised class.
         *
         * @param {HTMLElement} grid        Target grid container.
         * @param {object}      icon        Icon object from admbud_svg_list_picker response.
         * @param {string}      optionClass CSS class to add alongside ab-icon-svg-option (e.g. 'ab-op-icon-option').
         * @param {string|null} selectSlug  Pre-select this icon slug if it matches.
         */
        function buildLibraryBtn( grid, icon, optionClass, selectSlug ) {
            var btn = document.createElement( 'button' );
            btn.type = 'button';
            btn.className = 'ab-icon-svg-option ' + optionClass + ' ab-icon-lib-option';
            btn.setAttribute( 'data-icon', 'absvg_' + icon.slug );
            btn.setAttribute( 'data-source', 'library' );
            btn.title = icon.name + ( icon.preserve_colors ? ' (original colours)' : '' );
            btn.innerHTML = icon.svg;

            if ( ! icon.preserve_colors ) {
                btn.classList.add( 'ab-icon--normalised' );
                // Also normalise the SVG in the DOM so the picker grid shows correct colours.
                var svgEl = btn.querySelector( 'svg' );
                if ( svgEl ) { normaliseSvgColors( svgEl ); }
            }

            if ( icon.preserve_colors ) {
                // Purple dot indicator - icon has original colours.
                var dot = document.createElement( 'span' );
                dot.className = 'ab-icon-preserve-dot';
                btn.style.position = 'relative';
                btn.appendChild( dot );
            }

            if ( selectSlug && 'absvg_' + icon.slug === selectSlug ) {
                btn.classList.add( 'is-selected' );
            }
            grid.appendChild( btn );
        }

        /**
         * Load the SVG library into a grid element via AJAX.
         *
         * @param {object} opts
         * @param {string}      opts.ajaxUrl
         * @param {string}      opts.nonce
         * @param {HTMLElement} opts.grid        Grid container to populate.
         * @param {HTMLElement} opts.emptyEl     Element to show/hide when library is empty.
         * @param {string}      opts.optionClass CSS class for each button (e.g. 'ab-op-icon-option').
         * @param {string|null} opts.selectSlug  Pre-select this slug after load.
         * @param {Function}    opts.onDone      Called after render (or on error) with loaded icons array.
         */
        function loadLibrary( opts ) {
            var fd = new FormData();
            fd.append( 'action', 'admbud_svg_list_picker' );
            fd.append( 'nonce', opts.nonce );
            fetch( opts.ajaxUrl, { method: 'POST', body: fd } )
                .then( function ( r ) { return r.json(); } )
                .then( function ( res ) {
                    var grid = opts.grid;
                    if ( ! grid ) { return; }
                    // Clear loading placeholder and any existing icons.
                    Array.from( grid.querySelectorAll( '.ab-icon-lib-loading,.ab-icon-svg-option' ) )
                        .forEach( function ( el ) { el.remove(); } );

                    if ( opts.emptyEl ) { opts.emptyEl.style.display = 'none'; }

                    if ( ! res.success || ! res.data || ! res.data.icons || ! res.data.icons.length ) {
                        if ( opts.emptyEl ) { opts.emptyEl.style.display = ''; }
                        if ( opts.onDone ) { opts.onDone( [] ); }
                        return;
                    }

                    res.data.icons.forEach( function ( icon ) {
                        buildLibraryBtn( grid, icon, opts.optionClass, opts.selectSlug || null );
                    } );
                    if ( opts.onDone ) { opts.onDone( res.data.icons ); }
                } )
                .catch( function () { if ( opts.onDone ) { opts.onDone( [] ); } } );
        }

        /**
         * Update the icon picker button preview (the "Choose icon…" button).
         *
         * @param {string}      icon        Current icon slug value.
         * @param {HTMLElement} previewEl   The <span> that holds the SVG/dashicon.
         * @param {HTMLElement} labelEl     The <span> that holds the text label.
         * @param {string}      builtinGridId  DOM id of the builtin icon grid.
         * @param {string}      libraryGridId  DOM id of the library icon grid.
         */
        function updatePickerBtn( icon, previewEl, labelEl, builtinGridId, libraryGridId, fallbackSvg ) {
            if ( ! previewEl ) { return; }
            previewEl.innerHTML = '';

            if ( ! icon ) {
                if ( labelEl ) { labelEl.textContent = 'Choose icon\u2026'; }
                return;
            }

            var prefix = icon.indexOf( 'absvg_' ) === 0    ? 'library'
                       : icon.indexOf( 'dashicons-' ) === 0 ? 'dashicons'
                       : 'builtin';

            if ( prefix === 'dashicons' ) {
                previewEl.innerHTML = '<span class="dashicons ' + icon.replace( /"/g, '' ) + '" style="width:18px;height:18px;font-size:18px;"></span>';
                if ( labelEl ) { labelEl.textContent = icon.replace( 'dashicons-', '' ); }
                return;
            }

            // Try specific grid, then document-wide (covers builtin grid which is always present).
            var gridId = prefix === 'library' ? libraryGridId : builtinGridId;
            var optBtn = document.querySelector( '#' + gridId + ' [data-icon="' + icon + '"]' )
                      || document.querySelector( '.ab-icon-svg-grid [data-icon="' + icon + '"]' );
            var svgEl  = optBtn && optBtn.querySelector( 'svg' );

            if ( svgEl ) {
                previewEl.innerHTML = svgEl.outerHTML;
                var s = previewEl.querySelector( 'svg' );
                if ( s ) { s.style.cssText = 'width:18px;height:18px;display:block;'; }
                if ( labelEl ) {
                    labelEl.textContent = ( optBtn.title || optBtn.getAttribute( 'aria-label' ) ) || icon.replace( /^absvg_/, '' ).replace( /-/g, ' ' );
                }
                return;
            }

            // For library icons not yet in DOM: render fallback SVG and trigger a
            // background library load to update the button once data arrives.
            if ( prefix === 'library' ) {
                // Show fallback SVG immediately if available.
                if ( fallbackSvg ) {
                    var tmp = document.createElement( 'div' );
                    tmp.innerHTML = fallbackSvg;
                    var fsvg = tmp.querySelector( 'svg' );
                    if ( fsvg ) {
                        fsvg.style.cssText = 'width:18px;height:18px;display:block;';
                        previewEl.appendChild( fsvg );
                    }
                }
                if ( labelEl ) { labelEl.textContent = icon.replace( /^absvg_/, '' ).replace( /-/g, ' ' ); }

                // Silently load the library grid so future calls (and this one on reload) work.
                var libGrid = document.getElementById( libraryGridId );
                if ( libGrid && ! libGrid._abLoading ) {
                    libGrid._abLoading = true;
                    var libNonce = ( document.querySelector( '[id$="-svg-lib-nonce"]' ) || {} ).value || '';
                    var libAjax  = ( window.admbudCollData || window.admbudOpData || {} ).ajaxUrl
                                || ( typeof ajaxurl !== 'undefined' ? ajaxurl : '' );
                    if ( libNonce && libAjax ) {
                        var fd = new FormData();
                        fd.append( 'action', 'admbud_svg_list_picker' );
                        fd.append( 'nonce', libNonce );
                        fetch( libAjax, { method: 'POST', body: fd } )
                            .then( function ( r ) { return r.json(); } )
                            .then( function ( res ) {
                                libGrid._abLoading = false;
                                if ( ! res.success || ! res.data || ! res.data.icons ) { return; }
                                Array.from( libGrid.querySelectorAll( '.ab-icon-lib-loading,.ab-icon-svg-option' ) )
                                    .forEach( function ( el ) { el.remove(); } );
                                res.data.icons.forEach( function ( ic ) {
                                    var b = document.createElement( 'button' );
                                    b.type = 'button';
                                    b.className = 'ab-icon-svg-option ab-icon-lib-option';
                                    b.setAttribute( 'data-icon', 'absvg_' + ic.slug );
                                    b.setAttribute( 'data-source', 'library' );
                                    b.title = ic.name + ( ic.preserve_colors ? ' (original colours)' : '' );
                                    b.innerHTML = ic.svg;
                                    if ( ! ic.preserve_colors ) { b.classList.add( 'ab-icon--normalised' ); }
                                    libGrid.appendChild( b );
                                } );
                                // Now re-render the button with the real SVG.
                                var ob2 = libGrid.querySelector( '[data-icon="' + icon + '"]' );
                                var sv2 = ob2 && ob2.querySelector( 'svg' );
                                if ( sv2 ) {
                                    previewEl.innerHTML = sv2.outerHTML;
                                    var ss = previewEl.querySelector( 'svg' );
                                    if ( ss ) { ss.style.cssText = 'width:18px;height:18px;display:block;'; }
                                    if ( labelEl ) {
                                        labelEl.textContent = ( ob2.title || ob2.getAttribute( 'aria-label' ) ) || icon.replace( /^absvg_/, '' ).replace( /-/g, ' ' );
                                    }
                                }
                            } )
                            .catch( function () { libGrid._abLoading = false; } );
                    }
                }
                return;
            }

            // Builtin icon not found - shouldn't happen but degrade gracefully.
            if ( labelEl ) { labelEl.textContent = icon.replace( /^absvg_/, '' ).replace( /-/g, ' ' ); }
        }

        /**
         * Upload an SVG file via AJAX and reload the library.
         *
         * @param {object} opts
         * @param {File}        opts.file
         * @param {string}      opts.ajaxUrl
         * @param {string}      opts.nonce
         * @param {Function}    opts.onSuccess  Called with the new slug (e.g. 'absvg_foo').
         * @param {Function}    opts.onError    Called with an error message string.
         */
        function uploadSvg( opts ) {
            var file = opts.file;
            if ( ! file ) { return; }
            if ( ! file.name.toLowerCase().endsWith( '.svg' ) ) {
                if ( opts.onError ) { opts.onError( 'Only SVG files are supported.' ); }
                return;
            }
            var reader = new FileReader();
            reader.onload = function ( e ) {
                var name = file.name.replace( /\.svg$/i, '' ).replace( /[_-]/g, ' ' );
                var fd   = new FormData();
                fd.append( 'action',       'admbud_svg_upload' );
                fd.append( 'nonce',        opts.nonce );
                fd.append( 'icon_name',    name );
                fd.append( 'svg_content',  e.target.result );
                fetch( opts.ajaxUrl, { method: 'POST', body: fd } )
                    .then( function ( r ) { return r.json(); } )
                    .then( function ( res ) {
                        if ( res.success ) {
                            var slug = res.data && res.data.icon && res.data.icon.slug
                                     ? ( 'absvg_' + res.data.icon.slug ) : '';
                            if ( opts.onSuccess ) { opts.onSuccess( slug ); }
                        } else {
                            var msg = ( res.data && res.data.message ) || 'Upload failed.';
                            if ( opts.onError ) { opts.onError( msg ); }
                        }
                    } )
                    .catch( function () { if ( opts.onError ) { opts.onError( 'Upload failed.' ); } } );
            };
            reader.readAsText( file );
        }

        /**
         * Switch active tab + panel inside an icon modal.
         *
         * @param {string}      panel      Panel id suffix (e.g. 'op-builtins').
         * @param {HTMLElement} modalEl    The modal root element.
         * @param {Function}    onLibrary  Called when switching to the library tab, if not yet loaded.
         */
        function switchModalTab( panel, modalEl, onLibrary ) {
            if ( ! modalEl ) { return; }
            modalEl.querySelectorAll( '.ab-icon-modal-tab' ).forEach( function ( t ) {
                t.classList.remove( 'ab-icon-modal-tab--active' );
            } );
            modalEl.querySelectorAll( '.ab-icon-modal-panel' ).forEach( function ( p ) {
                p.classList.remove( 'ab-icon-modal-panel--active' );
            } );
            var tb = modalEl.querySelector( '.ab-icon-modal-tab[data-panel="' + panel + '"]' );
            if ( tb ) { tb.classList.add( 'ab-icon-modal-tab--active' ); }
            var pn = document.getElementById( 'ab-icon-panel-' + panel );
            if ( pn ) { pn.classList.add( 'ab-icon-modal-panel--active' ); }
            if ( onLibrary && panel.endsWith( '-library' ) ) { onLibrary(); }
        }

        // -- Sidebar icon injection - shared between CP, OP, Coll, Menu -------
        /**
         * Inject SVG icons into the WP admin sidebar for custom menu items.
         * Called from each module's inject_sidebar_icon_css() inline <script>.
         *
         * @param {object} svItems  Map of { cssClass: { svg, preserve } }
         */
        function injectSidebarIcons( svItems ) {
            Object.keys( svItems ).forEach( function ( cls ) {
                var item     = svItems[ cls ];
                var raw      = typeof item === 'string' ? item : ( item.svg || '' );
                var preserve = typeof item === 'object' && item.preserve;
                var wp = document.querySelector( '#adminmenu li.' + cls + ' .wp-menu-image' );
                if ( ! wp || wp.querySelector( '.ab-svg-injected' ) ) { return; }
                var tmp = document.createElement( 'div' );
                tmp.innerHTML = raw;
                var svg = tmp.querySelector( 'svg' );
                if ( ! svg ) { return; }
                svg.setAttribute( 'width',       '20' );
                svg.setAttribute( 'height',      '20' );
                svg.setAttribute( 'aria-hidden', 'true' );
                svg.classList.add( 'ab-svg-injected' );
                if ( ! preserve ) { normaliseSvgColors( svg ); }
                svg.style.cssText = 'display:block;width:20px;height:20px;color:inherit;';
                wp.appendChild( svg );
            } );
        }

        // Expose publicly.
        window.AdmbudIcon = {
            loadLibrary:       loadLibrary,
            buildLibraryBtn:   buildLibraryBtn,
            updatePickerBtn:   updatePickerBtn,
            uploadSvg:         uploadSvg,
            switchModalTab:    switchModalTab,
            normaliseSvgColors: normaliseSvgColors,
            injectSidebarIcons: injectSidebarIcons,
        };

    } )();

    // -- ab-collapsible - generic show more/less -------------------------------
    // Simple class toggle. Button rendered in PHP - no measurement, no injection.
    // Usage: wrap content in .ab-collapsible, put .ab-collapsible__btn after it.
    on( document, 'click', '.ab-collapsible__btn', function () {
        var btn  = this;
        var wrap = btn.previousElementSibling;
        if ( ! wrap || ! wrap.classList.contains( 'ab-collapsible' ) ) { return; }
        var expanded = wrap.classList.toggle( 'is-expanded' );
        btn.textContent = expanded
            ? ( btn.getAttribute( 'data-less' ) || 'Show less' )
            : ( btn.getAttribute( 'data-more' ) || 'Show more' );
    } );

    
} )();

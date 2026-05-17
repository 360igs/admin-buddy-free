/**
 * Admin Buddy - ab-icon-inject.js
 * Loaded on every admin page (not just the AB settings page).
 * Provides window.AdmbudIcon.injectSidebarIcons() so the inline <script> tags
 * emitted by inject_sidebar_icon_css() work on all admin pages.
 */
( function () {
    'use strict';

    function normaliseSvgColors( svg ) {
        svg.querySelectorAll( '[fill]' ).forEach( function ( el ) {
            if ( el.getAttribute( 'fill' ) !== 'none' ) { el.setAttribute( 'fill', 'currentColor' ); }
        } );
        svg.querySelectorAll( '[stroke]' ).forEach( function ( el ) {
            if ( el.getAttribute( 'stroke' ) !== 'none' ) { el.setAttribute( 'stroke', 'currentColor' ); }
        } );
        var rf = svg.getAttribute( 'fill' );
        if ( rf && rf !== 'none' ) { svg.setAttribute( 'fill', 'currentColor' ); }
    }

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

    // Expose on window.AdmbudIcon - may already exist if admin.js loaded on this page.
    // If admin.js loaded first it already defined window.AdmbudIcon; don't overwrite the
    // full object, just ensure injectSidebarIcons is available.
    if ( ! window.AdmbudIcon ) {
        window.AdmbudIcon = {};
    }
    if ( ! window.AdmbudIcon.injectSidebarIcons ) {
        window.AdmbudIcon.injectSidebarIcons = injectSidebarIcons;
    }
    if ( ! window.AdmbudIcon.normaliseSvgColors ) {
        window.AdmbudIcon.normaliseSvgColors = normaliseSvgColors;
    }

} )();

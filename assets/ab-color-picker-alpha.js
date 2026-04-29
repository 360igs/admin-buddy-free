/**
 * Admin Buddy - Colour Picker with Alpha Support
 *
 * Extends every .ab-color-picker input with:
 *  - All standard wpColorPicker functionality (hue wheel, saturation box)
 *  - An opacity/alpha slider (0–100%)
 *  - A format toggle: HEX · RGBA · HSLA
 *
 * Architecture:
 *  - Uses wpColorPicker as the base (already in WP core, no extra dep).
 *  - Alpha is handled entirely in JS: we store the value as rgba(r,g,b,a)
 *    or hsla(h,s%,l%,a) when alpha < 1, otherwise as hex.
 *  - The alpha slider is injected ONCE into the Iris picker DOM after init.
 *  - No external CDN. No tinycolor.js.
 *
 * @package Admbud
 */
/* global jQuery, wp */
( function ( $, wp ) {
    'use strict';

    // wp-color-picker exposes $.fn.wpColorPicker (a jQuery plugin), NOT wp.wpColorPicker.
    if ( ! $.fn.wpColorPicker ) { return; }

    // -- Colour math helpers -----------------------------------------------

    function hexToRgb( hex ) {
        hex = hex.replace( /^#/, '' );
        if ( hex.length === 3 ) {
            hex = hex.split('').map(function(c){ return c+c; }).join('');
        }
        var n = parseInt( hex, 16 );
        return { r: (n>>16)&255, g: (n>>8)&255, b: n&255 };
    }

    function rgbToHex( r, g, b ) {
        return '#' + [r,g,b].map(function(v){
            return ('0' + Math.round(v).toString(16)).slice(-2);
        }).join('');
    }

    function rgbToHsl( r, g, b ) {
        r /= 255; g /= 255; b /= 255;
        var max = Math.max(r,g,b), min = Math.min(r,g,b);
        var h, s, l = (max+min)/2;
        if ( max === min ) {
            h = s = 0;
        } else {
            var d = max - min;
            s = l > 0.5 ? d/(2-max-min) : d/(max+min);
            switch(max) {
                case r: h = ((g-b)/d + (g<b?6:0))/6; break;
                case g: h = ((b-r)/d + 2)/6; break;
                default: h = ((r-g)/d + 4)/6;
            }
        }
        return { h: Math.round(h*360), s: Math.round(s*100), l: Math.round(l*100) };
    }

    function hslToRgb( h, s, l ) {
        s /= 100; l /= 100; h /= 360;
        var r, g, b;
        if ( s === 0 ) {
            r = g = b = l;
        } else {
            function hue2rgb(p,q,t){ if(t<0)t+=1; if(t>1)t-=1; if(t<1/6)return p+(q-p)*6*t; if(t<1/2)return q; if(t<2/3)return p+(q-p)*(2/3-t)*6; return p; }
            var q = l < 0.5 ? l*(1+s) : l+s-l*s;
            var p = 2*l-q;
            r = hue2rgb(p,q,h+1/3); g = hue2rgb(p,q,h); b = hue2rgb(p,q,h-1/3);
        }
        return { r: Math.round(r*255), g: Math.round(g*255), b: Math.round(b*255) };
    }

    // Parse any colour string → { r, g, b, a }.
    function parseColor( str ) {
        if ( ! str ) { return null; }
        str = str.trim();

        // rgba(r, g, b, a)
        var m = str.match( /^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)(?:\s*,\s*([\d.]+))?\s*\)$/ );
        if ( m ) { return { r:+m[1], g:+m[2], b:+m[3], a: m[4] !== undefined ? +m[4] : 1 }; }

        // hsla(h, s%, l%, a)
        m = str.match( /^hsla?\(\s*([\d.]+)\s*,\s*([\d.]+)%?\s*,\s*([\d.]+)%?(?:\s*,\s*([\d.]+))?\s*\)$/ );
        if ( m ) {
            var rgb = hslToRgb( +m[1], +m[2], +m[3] );
            return { r: rgb.r, g: rgb.g, b: rgb.b, a: m[4] !== undefined ? +m[4] : 1 };
        }

        // #hex
        m = str.match( /^#([0-9a-f]{3}|[0-9a-f]{6})$/i );
        if ( m ) { var h = hexToRgb(str); return { r: h.r, g: h.g, b: h.b, a: 1 }; }

        return null;
    }

    function colorToString( r, g, b, a, fmt ) {
        a = Math.round( a * 100 ) / 100;
        if ( a >= 1 ) { return rgbToHex(r,g,b); }
        if ( fmt === 'hsla' ) {
            var hsl = rgbToHsl(r,g,b);
            return 'hsla(' + hsl.h + ',' + hsl.s + '%,' + hsl.l + '%,' + a + ')';
        }
        return 'rgba(' + Math.round(r) + ',' + Math.round(g) + ',' + Math.round(b) + ',' + a + ')';
    }

    // -- Plugin ------------------------------------------------------------

    $.fn.admbudColorPickerAlpha = function ( opts ) {
        return this.each( function () {
            var $input = $( this );
            if ( $input.data('ab-cp-init') ) { return; }
            $input.data('ab-cp-init', true);

            var currentAlpha  = 1;
            var currentFormat = 'hex';
            var ignoreChange  = false;

            // Parse existing value to pre-set alpha.
            var parsed = parseColor( $input.val() );
            if ( parsed ) {
                currentAlpha = parsed.a;
                if ( parsed.a < 1 ) { currentFormat = 'rgba'; }
            }

            // Init base wpColorPicker.
            $input.wpColorPicker( $.extend( {}, opts || {}, {
                change: function ( e, ui ) {
                    if ( ignoreChange ) { return; }
                    // ui.color is an Iris Color object, has .toString()
                    var hex = ui.color.toString();
                    var c   = hexToRgb( hex );
                    var str = colorToString( c.r, c.g, c.b, currentAlpha, currentFormat );
                    // Update preview swatch alpha.
                    $wrap.find('.wp-color-result').css( 'background-color', str );
                    // Write back without triggering another change cycle.
                    ignoreChange = true;
                    $input.val( str ).trigger( 'change' );
                    ignoreChange = false;
                    syncAlphaUI( c.r, c.g, c.b );
                },
                clear: function () {
                    currentAlpha = 1;
                    if ( $slider ) { $slider.val(100); $alphaNum.val(100); }
                    updateSwatchBg();
                },
            } ) );

            var $wrap = $input.closest('.wp-picker-container');
            if ( ! $wrap.length ) { return; }

            // -- Build alpha UI -------------------------------------------
            // Injected after the Iris picker is in the DOM.
            var $alphaRow   = $('<div class="ab-cp-alpha-row">');
            var $sliderWrap = $('<div class="ab-cp-alpha-slider-wrap">');
            var $slider     = $('<input type="range" class="ab-cp-alpha-slider" min="0" max="100" step="1">').val( Math.round(currentAlpha*100) );
            var $alphaNum   = $('<input type="number" class="ab-cp-alpha-num" min="0" max="100" step="1">').val( Math.round(currentAlpha*100) );
            var $fmtRow     = $('<div class="ab-cp-fmt-row">');

            $sliderWrap.append( $('<span class="ab-cp-alpha-label">Opacity</span>'), $slider, $alphaNum, $('<span class="ab-cp-alpha-pct">%</span>') );

            ['hex','rgba','hsla'].forEach(function(fmt){
                var $btn = $('<button type="button" class="ab-cp-fmt-btn">').text(fmt.toUpperCase());
                if ( fmt === currentFormat ) { $btn.addClass('ab-cp-fmt-btn--active'); }
                $btn.on('click', function(){
                    currentFormat = fmt;
                    $fmtRow.find('.ab-cp-fmt-btn').removeClass('ab-cp-fmt-btn--active');
                    $btn.addClass('ab-cp-fmt-btn--active');
                    commitValue();
                });
                $fmtRow.append($btn);
            });

            $alphaRow.append( $sliderWrap, $fmtRow );

            // Insert after Iris picker, before the hex text input row.
            var $irisContainer = $wrap.find('.iris-picker');
            if ( $irisContainer.length ) {
                $irisContainer.after( $alphaRow );
            } else {
                $wrap.append( $alphaRow );
            }

            function syncAlphaUI( r, g, b ) {
                // Update the slider background gradient.
                var transparent = 'rgba('+r+','+g+','+b+',0)';
                var opaque      = 'rgb('+r+','+g+','+b+')';
                $slider.css('--ab-alpha-from', transparent).css('--ab-alpha-to', opaque);
                $sliderWrap.css('background', 'linear-gradient(to right, '+transparent+', '+opaque+')');
            }

            function updateSwatchBg() {
                var c = parseColor( $input.val() ) || { r:0,g:0,b:0,a:currentAlpha };
                var str = colorToString(c.r,c.g,c.b,currentAlpha,currentFormat);
                $wrap.find('.wp-color-result').css('background-color', str);
            }

            function commitValue() {
                var c = parseColor( $input.val() );
                if ( ! c ) { return; }
                var str = colorToString( c.r, c.g, c.b, currentAlpha, currentFormat );
                $input.val( str ).trigger( 'change' );
                updateSwatchBg();
            }

            // Set initial slider gradient using stored colour.
            if ( parsed ) { syncAlphaUI(parsed.r, parsed.g, parsed.b); }

            // Slider → alpha.
            $slider.on( 'input change', function () {
                currentAlpha = +$slider.val() / 100;
                $alphaNum.val( $slider.val() );
                commitValue();
            });

            // Number input → alpha.
            $alphaNum.on( 'input change', function () {
                var v = Math.min( 100, Math.max( 0, +$alphaNum.val() || 0 ) );
                currentAlpha = v / 100;
                $slider.val(v);
                commitValue();
            });

            // When the wp-color-result swatch is clicked to open the picker,
            // re-sync slider gradient (hue may have changed since last open).
            $wrap.on( 'click', '.wp-color-result', function () {
                var c = parseColor( $input.val() );
                if (c) { syncAlphaUI(c.r,c.g,c.b); }
            });
        });
    };

    // -- Auto-init all .ab-color-picker inputs -----------------------------
    $( function () {
        $( '.ab-color-picker' ).admbudColorPickerAlpha();
    });

}( jQuery, window.wp ) );

<?php
/**
 * Login - custom branding for the WordPress login page.
 *
 * Handles:
 *  - Logo image at configurable width + height
 *  - Background: solid color | gradient | image + overlay
 *  - Card position: left | center | right
 *    - Left/Right: full-height frosted-glass panel (backdrop-filter: blur)
 *    - Center: classic floating card over background
 *  - Logo link → site home, title → site name (always applied)
 *
 * @package Admbud
 */

namespace Admbud;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Login {

    private static ?Login $instance = null;

    public static function get_instance(): Login {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter( 'login_headerurl',  [ $this, 'logo_url'   ] );
        add_filter( 'login_headertext', [ $this, 'logo_title' ] );
        add_action( 'login_enqueue_scripts', [ $this, 'enqueue_styles' ] );
    }

    // ============================================================================
    // STYLES
    // ============================================================================

    /**
     * Build the inline CSS for the login page and enqueue it.
     *
     * Each piece (background / logo / card position) is built from sanitised
     * option values via dedicated helpers below. Every interpolation in those
     * helpers escapes its input at source: hex colours via sanitize_hex_field()
     * + esc_attr(), URLs via esc_url(), integers via absint(), and the
     * gradient direction via a validated_direction() whitelist. The $css
     * string passed to wp_add_inline_style() therefore contains no
     * unescaped data.
     */
    public function enqueue_styles(): void {
        $css  = $this->css_background();
        $css .= $this->css_logo();
        $css .= $this->css_card_position();
        $css .= $this->css_card_surface();
        $css .= $this->css_button();

        if ( ! $css ) {
            return;
        }

        wp_register_style( 'ab-login', false, [], ADMBUD_VERSION );
        wp_enqueue_style( 'ab-login' );
        wp_add_inline_style( 'ab-login', $css );
    }

    // -- Background ------------------------------------------------------------

    private function css_background(): string {
        $type    = admbud_get_option( 'admbud_login_bg_type', 'solid' );
        $has_img = ( $type === 'image' && admbud_get_option( 'admbud_login_bg_image_url', '' ) );

        // Colours module OFF: solid / gradient backgrounds follow the active
        // WP admin colour scheme (dark, accent-tinted) instead of the stored
        // brand palette. An explicit background image is still honoured.
        // Settings::scheme_page_palette() is the single shared source.
        if ( ! \Admbud\Settings::colours_module_active() && ! $has_img ) {
            $p = \Admbud\Settings::scheme_page_palette();
            return sprintf(
                'body.login { background: linear-gradient(to bottom right, %s, %s) !important; }',
                esc_attr( $p['grad_from'] ), esc_attr( $p['grad_to'] )
            );
        }

        switch ( $type ) {
            case 'gradient':
                return $this->css_gradient_background();
            case 'image':
                return $this->css_image_background();
            default:
                $color = sanitize_hex_field( admbud_get_option( 'admbud_login_bg_color', \Admbud\Colours::DEFAULT_PAGE_BG ) );
                return $color
                    ? sprintf( 'body.login { background-color: %s !important; }', esc_attr( $color ) )
                    : '';
        }
    }

    private function css_gradient_background(): string {
        $from = sanitize_hex_field( admbud_get_option( 'admbud_login_grad_from', \Admbud\Colours::DEFAULT_SIDEBAR_GRAD_FROM ) );
        $to   = sanitize_hex_field( admbud_get_option( 'admbud_login_grad_to',   \Admbud\Colours::DEFAULT_SIDEBAR_GRAD_TO   ) );
        $dir  = $this->validated_direction( admbud_get_option( 'admbud_login_grad_direction', 'to bottom right' ) );

        if ( ! $from || ! $to ) {
            return '';
        }

        return sprintf(
            'body.login { background: linear-gradient(%s, %s, %s) !important; }',
            esc_attr( $dir ), esc_attr( $from ), esc_attr( $to )
        );
    }

    private function css_image_background(): string {
        $url     = esc_url( admbud_get_option( 'admbud_login_bg_image_url', '' ) );
        $opacity = min( 90, absint( admbud_get_option( 'admbud_login_bg_overlay_opacity', 30 ) ) );
        $color   = sanitize_hex_field( admbud_get_option( 'admbud_login_bg_overlay_color', '#000000' ) );

        if ( ! $url ) {
            return '';
        }

        $rgba = $color ? $this->hex_to_rgba( $color, $opacity / 100 ) : '';

        if ( $rgba ) {
            return sprintf(
                'body.login {
                    background-image: linear-gradient(%s, %s), url("%s") !important;
                    background-size: cover !important;
                    background-position: center !important;
                    background-repeat: no-repeat !important;
                }',
                esc_attr( $rgba ), esc_attr( $rgba ), esc_url( $url )
            );
        }

        return sprintf(
            'body.login {
                background-image: url("%s") !important;
                background-size: cover !important;
                background-position: center !important;
                background-repeat: no-repeat !important;
            }',
            esc_url( $url )
        );
    }


    // -- Logo ------------------------------------------------------------------

    private function css_logo(): string {
        $url    = esc_url( admbud_get_option( 'admbud_login_logo_url', '' ) );
        $width  = absint( admbud_get_option( 'admbud_login_logo_width', 84 ) );
        $height = absint( admbud_get_option( 'admbud_login_logo_height', 0 ) );

        $width  = ( $width  >= 40  && $width  <= 320 ) ? $width  : 84;
        // 0 = auto (60% of width, clamped). Otherwise use explicit value.
        $height = $height > 0
            ? max( 30, min( 200, $height ) )
            : max( 48, min( 160, (int) round( $width * 0.6 ) ) );

        if ( ! $url ) {
            return '';
        }

        return sprintf(
            'body.login #login h1 a {
                background-image:    url("%s") !important;
                background-size:     contain !important;
                background-repeat:   no-repeat !important;
                background-position: center !important;
                width:               %dpx !important;
                height:              %dpx !important;
                display:             block !important;
                margin:              0 auto !important;
            }',
            esc_url( $url ), $width, $height
        );
    }

    // -- Card position ---------------------------------------------------------

    /**
     * Left / Right: full-height frosted-glass panel.
     *   - body.login = flex row, background fills viewport
     *   - #login = fixed 400px wide panel, min-height 100vh
     *   - backdrop-filter: blur(20px) + semi-transparent white = frosted glass
     * Center: classic floating card, centered on the background.
     */
    private function css_card_position(): string {
        $position = admbud_get_option( 'admbud_login_card_position', 'center' );

        if ( $position === 'center' ) {
            return '
            body.login {
                display:         flex !important;
                align-items:     center !important;
                justify-content: center !important;
                min-height:      100vh !important;
                padding:         2rem !important;
                box-sizing:      border-box !important;
            }
            body.login #login {
                width:                   360px !important;
                max-width:               calc(100vw - 4rem) !important;
                background:              rgba(255,255,255,0.88) !important;
                -webkit-backdrop-filter: blur(18px) saturate(1.4) !important;
                backdrop-filter:         blur(18px) saturate(1.4) !important;
                border-radius:           12px !important;
                box-shadow:              0 8px 40px rgba(0,0,0,0.18) !important;
                padding:                 2rem !important;
                box-sizing:              border-box !important;
                margin:                  0 !important;
                float:                   none !important;
            }
            body.login #loginform {
                background: transparent !important;
                box-shadow: none !important;
                border:     none !important;
                padding:    16px 0 0 !important;
                width:      100% !important;
                max-width:  100% !important;
            }
            body.login .input,
            body.login input[type="text"],
            body.login input[type="password"],
            body.login #login #nav,
            body.login #login #backtoblog {
                width:     100% !important;
                max-width: 100% !important;
            }';
        }

        $align  = $position === 'left' ? 'flex-start' : 'flex-end';
        $shadow = $position === 'left'
            ? '4px 0 40px rgba(0,0,0,0.15)'
            : '-4px 0 40px rgba(0,0,0,0.15)';

        return sprintf(
            '/* Frosted-glass panel layout */
            body.login {
                display:         flex !important;
                flex-direction:  row !important;
                align-items:     stretch !important;
                justify-content: %s !important;
                min-height:      100vh !important;
                padding:         0 !important;
                margin:          0 !important;
                box-sizing:      border-box !important;
            }

            body.login #login {
                display:          flex !important;
                flex-direction:   column !important;
                justify-content:  center !important;
                align-items:      stretch !important;
                width:            400px !important;
                min-width:        320px !important;
                max-width:        90vw !important;
                min-height:       100vh !important;
                /* Frosted glass */
                background:       rgba(255,255,255,0.88) !important;
                -webkit-backdrop-filter: blur(20px) saturate(1.4) !important;
                backdrop-filter:  blur(20px) saturate(1.4) !important;
                box-shadow:       %s !important;
                padding:          3rem 2.5rem !important;
                box-sizing:       border-box !important;
                overflow-y:       auto !important;
                float:            none !important;
                margin-top:       0 !important;
                margin-bottom:    0 !important;
                margin-left:      %s !important;
                margin-right:     %s !important;
            }

            /* Form fills panel width, no WP card chrome */
            body.login #loginform {
                width:      100%% !important;
                max-width:  100%% !important;
                padding:    16px 0 !important;
                background: transparent !important;
                box-shadow: none !important;
                border:     none !important;
            }

            body.login #login h1,
            body.login #login #nav,
            body.login #login #backtoblog {
                width:      100%% !important;
                max-width:  100%% !important;
                text-align: center !important;
                padding:    0 !important;
            }

            body.login .input,
            body.login input[type="text"],
            body.login input[type="password"] {
                width: 100%% !important;
            }',
            esc_attr( $align ),
            esc_attr( $shadow ),
            $position === 'left'  ? '0' : 'auto',
            $position === 'right' ? '0' : 'auto'
        );
    }

    // -- Card surface (background / width / text) ------------------------------

    /**
     * Background colour, width, and text colour for the card / panel the login
     * form sits in. Emitted AFTER css_card_position() so these three override
     * its hardcoded defaults (white @ 88%, 360/400px) without disturbing the
     * layout rules. Applies to all three card positions.
     *
     * Independent of the Colours module - a plain Login setting. The frosted
     * blur stays on #login from css_card_position(); only the tint changes.
     */
    private function css_card_surface(): string {
        $bg_hex = sanitize_hex_field( admbud_get_option( 'admbud_login_card_bg_color', '#ffffff' ) ) ?: '#ffffff';

        $width = absint( admbud_get_option( 'admbud_login_card_width', 400 ) );
        $width = ( $width >= 320 && $width <= 600 ) ? $width : 400;

        // Text colour: auto-contrast against the card colour, or an explicit
        // override. Covers the field labels and the nav / back-to-site links.
        if ( admbud_get_option( 'admbud_login_card_text_auto', '1' ) === '1' ) {
            $text = \Admbud\Settings::contrast_text( $bg_hex );
        } else {
            $text = sanitize_hex_field( admbud_get_option( 'admbud_login_card_text_color', '#3c434a' ) ) ?: '#3c434a';
        }

        // Frosted glass: the chosen colour at 88% (the blur already lives on
        // #login from css_card_position()).
        $bg_rgba = $this->hex_to_rgba( $bg_hex, 0.88 ) ?: 'rgba(255,255,255,0.88)';

        return sprintf(
            'body.login #login { background: %s !important; width: %dpx !important; }'
            . 'body.login #login label,'
            . 'body.login #login #nav a,'
            . 'body.login #login #backtoblog a { color: %s !important; }',
            esc_attr( $bg_rgba ),
            $width,
            esc_attr( $text )
        );
    }

    // -- Login button ----------------------------------------------------------

    /**
     * Background colour for the login button (#wp-submit).
     *
     * Automatic (default): when the Colours module is ON it already styles the
     * button via inject_login_css() - leave it. When OFF, the button follows
     * the WP admin scheme accent so it tracks the rest of the page.
     *
     * Override: an explicit colour, scoped under #login (two ids) so it also
     * beats the Colours module's `body.login #wp-submit` rule when both apply.
     * Label colour auto-contrasts; hover is an auto-darkened shade.
     */
    private function css_button(): string {
        if ( admbud_get_option( 'admbud_login_btn_auto', '1' ) === '1' ) {
            if ( \Admbud\Settings::colours_module_active() ) {
                return ''; // Colours module owns the button styling.
            }
            $bg = \Admbud\Settings::scheme_accent_hex();
        } else {
            $bg = sanitize_hex_field( admbud_get_option( 'admbud_login_btn_bg', '#2271b1' ) ) ?: '#2271b1';
        }

        $hover = \Admbud\Settings::mix_hex( $bg, '#000000', 85 );
        $text  = \Admbud\Settings::contrast_text( $bg );

        return sprintf(
            'body.login #login #wp-submit,body.login #login .button-primary{background:%1$s!important;border-color:%1$s!important;color:%2$s!important}'
            . 'body.login #login #wp-submit:hover,body.login #login .button-primary:hover{background:%3$s!important;border-color:%3$s!important;color:%2$s!important}',
            esc_attr( $bg ),
            esc_attr( $text ),
            esc_attr( $hover )
        );
    }

    // -- Helpers ---------------------------------------------------------------

    private function validated_direction( string $direction ): string {
        $allowed = [
            'to top', 'to top right', 'to right', 'to bottom right',
            'to bottom', 'to bottom left', 'to left', 'to top left',
        ];
        return in_array( $direction, $allowed, true ) ? $direction : 'to bottom right';
    }

    private function hex_to_rgba( string $hex, float $alpha ): string {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0].$hex[0] . $hex[1].$hex[1] . $hex[2].$hex[2];
        }
        if ( strlen( $hex ) !== 6 ) {
            return '';
        }
        return sprintf(
            'rgba(%d, %d, %d, %.2f)',
            hexdec( substr( $hex, 0, 2 ) ),
            hexdec( substr( $hex, 2, 2 ) ),
            hexdec( substr( $hex, 4, 2 ) ),
            $alpha
        );
    }

    public function logo_url(): string {
        return esc_url( home_url( '/' ) );
    }

    public function logo_title(): string {
        return esc_attr( get_bloginfo( 'name' ) );
    }
}

// sanitize_hex_field() is defined in class-maintenance.php (always loaded).
// Guard ensures no redefinition when login module is loaded after maintenance.
if ( ! function_exists( 'Admbud\sanitize_hex_field' ) ) {
    /**
     * Minimal hex color sanitizer - no Customizer dependency.
     *
     * @param  string $color Raw color value.
     * @return string        Validated hex or empty string.
     */
    function sanitize_hex_field( string $color ): string {
        $color = trim( $color );
        return preg_match( '/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color ) ? $color : '';
    }
}

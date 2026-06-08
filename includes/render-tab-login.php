<?php
/**
 * Login tab UI - Logo, background, card position, and live preview.
 * Included by Settings::render_tab_login().
 * $settings is the Settings singleton - use $settings->card_open() etc.
 *
 * @package Admbud
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Variables scoped to this included template file.

use Admbud\Colours;

/** @var \Admbud\Settings $settings */
        $logo_url       = esc_url(  admbud_get_option( 'admbud_login_logo_url',       ''           ) );
        $logo_width     = absint(   admbud_get_option( 'admbud_login_logo_width',     84           ) );
        $logo_height    = absint(   admbud_get_option( 'admbud_login_logo_height',    0            ) );
        $card_position  =           admbud_get_option( 'admbud_login_card_position',  'center'     );
        $bg_type        =           admbud_get_option( 'admbud_login_bg_type',        'solid'      );
        $bg_color       = esc_attr( admbud_get_option( 'admbud_login_bg_color',       Colours::DEFAULT_PAGE_BG           ) );
        $grad_from      = esc_attr( admbud_get_option( 'admbud_login_grad_from',      Colours::DEFAULT_SIDEBAR_GRAD_FROM ) );
        $grad_to        = esc_attr( admbud_get_option( 'admbud_login_grad_to',        Colours::DEFAULT_SIDEBAR_GRAD_TO   ) );
        $grad_dir       =           admbud_get_option( 'admbud_login_grad_direction', 'to bottom right' );
        $bg_image       = esc_url(  admbud_get_option( 'admbud_login_bg_image_url',   ''           ) );
        $overlay_color  = esc_attr( admbud_get_option( 'admbud_login_bg_overlay_color',   '#000000' ) );
        $overlay_op     = absint(   admbud_get_option( 'admbud_login_bg_overlay_opacity', 30       ) );
        $card_bg_color  = esc_attr( admbud_get_option( 'admbud_login_card_bg_color',   '#ffffff' ) );
        $card_text_auto =           admbud_get_option( 'admbud_login_card_text_auto',  '1'       );
        $card_text_color= esc_attr( admbud_get_option( 'admbud_login_card_text_color', '#3c434a' ) );
        $card_width     = absint(   admbud_get_option( 'admbud_login_card_width',      400       ) );
        $btn_auto       =           admbud_get_option( 'admbud_login_btn_auto',        '1'       );
        $btn_bg         = esc_attr( admbud_get_option( 'admbud_login_btn_bg',          '#2271b1' ) );
        $directions = [
            'to top left' => '↖', 'to top' => '↑', 'to top right' => '↗',
            'to left'     => '←',                   'to right'     => '→',
            'to bottom left' => '↙', 'to bottom' => '↓', 'to bottom right' => '↘',
        ];
        $grid_order = [
            'to top left', 'to top', 'to top right',
            'to left',      null,    'to right',
            'to bottom left', 'to bottom', 'to bottom right',
        ];
        $positions = [
            'left'   => [ 'icon' => '⬅', 'label' => __( 'Left',   'admin-buddy' ) ],
            'center' => [ 'icon' => '↔',  'label' => __( 'Center', 'admin-buddy' ) ],
            'right'  => [ 'icon' => '➡', 'label' => __( 'Right',  'admin-buddy' ) ],
        ];
        $hs = $bg_type !== 'solid'    ? 'ab-hidden' : '';
        $hg = $bg_type !== 'gradient' ? 'ab-hidden' : '';
        $hi = $bg_type !== 'image'    ? 'ab-hidden' : '';
        // Colours module OFF: the login page follows the WP admin colour
        // scheme. Mirror that in the preview - the background override is
        // skipped when an explicit image is set (still honoured on the live
        // page), but the button / logo accent follows the scheme either way.
        $colours_off         = ! \Admbud\Settings::colours_module_active();
        $login_scheme_active = ( $colours_off && ! ( $bg_type === 'image' && $bg_image ) );
        $login_scheme_bg     = '';
        if ( $login_scheme_active ) {
            $login_pal       = \Admbud\Settings::scheme_page_palette();
            $login_scheme_bg = 'linear-gradient(to bottom right, ' . $login_pal['grad_from'] . ', ' . $login_pal['grad_to'] . ')';
        }
        // Preview accent for the mock Log In button + default WP logo mark:
        // the WP admin scheme accent when Colours is off (class-colours.php's
        // inject_login_css() isn't running), the brand primary when it is.
        $preview_accent = $colours_off
            ? \Admbud\Settings::scheme_accent_hex()
            : admbud_get_option( 'admbud_colours_primary', '#7c3aed' );
        $preview_btn_bg = esc_attr( $preview_accent );
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'admbud_login_group' ); ?>
            <input type="hidden" name="admbud_tab" value="login">
            <div class="ab-login-layout">
                <!-- Left: single scrollable column -->
                <div class="ab-login-main">

                    <?php $settings->card_open_svg( '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4Z"/></svg>', __( 'Layout', 'admin-buddy' ), __( 'Position of the login form on the page.', 'admin-buddy' ) ); ?>
                    <div class="ab-card-position-selector">
                        <?php foreach ( $positions as $value => $pos ) : ?>
                            <label class="ab-card-pos-option <?php echo $card_position === $value ? 'ab-card-pos-option--active' : ''; ?>">
                                <input type="radio" name="admbud_login_card_position" value="<?php echo esc_attr( $value ); ?>" <?php checked( $card_position, $value ); ?>>
                                <span class="ab-card-pos-option__icon"><?php echo esc_html( $pos['icon'] ); ?></span>
                                <span><?php echo esc_html( $pos['label'] ); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="description ab-mt-2"><?php esc_html_e( 'Left / Right: full-height frosted-glass panel. Center: classic floating card.', 'admin-buddy' ); ?></p>
                    <?php $settings->card_close(); ?>

                    <?php $settings->card_open_svg( '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/></svg>', __( 'Form Card', 'admin-buddy' ), __( 'The panel the login form sits on - shared by all three layouts.', 'admin-buddy' ) ); ?>
                    <table class="form-table ab-form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="admbud_login_card_bg_color"><?php esc_html_e( 'Background Colour', 'admin-buddy' ); ?></label></th>
                            <td>
                                <input type="color" id="admbud_login_card_bg_color" name="admbud_login_card_bg_color" value="<?php echo esc_attr( $card_bg_color ); ?>" class="ab-native-color-picker" data-default-color="#ffffff">
                                <p class="description"><?php esc_html_e( 'Applied at 88% opacity over the frosted-glass blur. Default white.', 'admin-buddy' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="admbud_login_card_width"><?php esc_html_e( 'Width', 'admin-buddy' ); ?></label></th>
                            <td>
                                <div class="ab-flex-row--sm">
                                    <input type="range" id="admbud_login_card_width" name="admbud_login_card_width" min="320" max="600" step="10" value="<?php echo esc_attr( $card_width ); ?>" style="width:200px;">
                                    <span id="admbud_login_card_width_val" class="ab-range-value"><?php echo esc_html( $card_width ); ?>px</span>
                                </div>
                                <p class="description"><?php esc_html_e( 'Width of the card / panel, 320-600px.', 'admin-buddy' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Text Colour', 'admin-buddy' ); ?></th>
                            <td>
                                <input type="hidden" name="admbud_login_card_text_auto" value="0">
                                <label class="ab-flex-row--xs" style="cursor:pointer;">
                                    <input type="checkbox" id="admbud_login_card_text_auto" name="admbud_login_card_text_auto" value="1" <?php checked( $card_text_auto, '1' ); ?>>
                                    <?php esc_html_e( 'Automatic - high-contrast with the card colour', 'admin-buddy' ); ?>
                                </label>
                                <div class="ab-login-card-text-row <?php echo $card_text_auto === '1' ? 'ab-hidden' : ''; ?>" style="margin-top:8px;">
                                    <input type="color" id="admbud_login_card_text_color" name="admbud_login_card_text_color" value="<?php echo esc_attr( $card_text_color ); ?>" class="ab-native-color-picker" data-default-color="#3c434a">
                                    <p class="description"><?php esc_html_e( 'Colour for the form labels and links on the card.', 'admin-buddy' ); ?></p>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Button Colour', 'admin-buddy' ); ?></th>
                            <td>
                                <input type="hidden" name="admbud_login_btn_auto" value="0">
                                <label class="ab-flex-row--xs" style="cursor:pointer;">
                                    <input type="checkbox" id="admbud_login_btn_auto" name="admbud_login_btn_auto" value="1" <?php checked( $btn_auto, '1' ); ?>>
                                    <?php esc_html_e( 'Automatic - follow the admin colour scheme', 'admin-buddy' ); ?>
                                </label>
                                <div class="ab-login-btn-row <?php echo $btn_auto === '1' ? 'ab-hidden' : ''; ?>" style="margin-top:8px;">
                                    <input type="color" id="admbud_login_btn_bg" name="admbud_login_btn_bg" value="<?php echo esc_attr( $btn_bg ); ?>" class="ab-native-color-picker" data-default-color="#2271b1">
                                    <p class="description"><?php esc_html_e( 'Login button background. The label colour adjusts automatically for contrast.', 'admin-buddy' ); ?></p>
                                </div>
                            </td>
                        </tr>
                    </table>
                    <?php $settings->card_close(); ?>

                    <?php $settings->card_open_svg( '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>', __( 'Logo', 'admin-buddy' ), __( 'Replaces the WordPress logo on the login page.', 'admin-buddy' ) ); ?>
                    <table class="form-table ab-form-table" role="presentation">
                        <?php $settings->image_upload_row(
                            'admbud_login_logo_url',
                            __( 'Logo Image', 'admin-buddy' ),
                            '',
                            true,
                            'admbud_login_logo_width',
                            'admbud_login_logo_height',
                            40, 320, 200
                        ); ?>
                    </table>
                    <?php $settings->card_close(); ?>

                    <?php $settings->card_open_svg( '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M2 13.5V20a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6.5"/><path d="M2 13.5C2 9.358 5.358 6 9.5 6h5C18.642 6 22 9.358 22 13.5"/><path d="M12 2v4"/><path d="m8 3 1.5 3"/><path d="m16 3-1.5 3"/></svg>', __( 'Background', 'admin-buddy' ), __( 'Choose how the login page background looks.', 'admin-buddy' ) ); ?>
                    <?php if ( $colours_off ) : ?>
                    <div class="ab-notice ab-notice--info" style="margin-bottom:14px;">
                        <?php esc_html_e( 'While the Colours module is off, the login page automatically follows your WordPress admin colour scheme. The colour and background fields below have no effect — enable the Colours module to set custom colours.', 'admin-buddy' ); ?>
                    </div>
                    <?php endif; ?>
                    <table class="form-table ab-form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Type', 'admin-buddy' ); ?></th>
                            <td>
                                <div class="ab-flex-row--lg">
                                    <?php
                                    $admbud_login_bg_options = [ 'solid' => __( 'Solid Color', 'admin-buddy' ), 'gradient' => __( 'Gradient', 'admin-buddy' ), 'image' => __( 'Image', 'admin-buddy' ) ];
                                    foreach ( $admbud_login_bg_options as $val => $lbl ) : ?>
                                    <label class="ab-flex-row--xs" style="cursor:pointer;">
                                        <input type="radio" name="admbud_login_bg_type" value="<?php echo esc_attr( $val ); ?>" <?php checked( $bg_type, $val ); ?>>
                                        <?php echo esc_html( $lbl ); ?>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                        <tr class="ab-login-solid <?php echo esc_attr( $hs ); ?>">
                            <th scope="row"><label for="admbud_login_bg_color"><?php esc_html_e( 'Color', 'admin-buddy' ); ?></label></th>
                            <td><input type="color" id="admbud_login_bg_color" name="admbud_login_bg_color" value="<?php echo esc_attr( $bg_color ); ?>" class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::DEFAULT_PAGE_BG ); ?>"></td>
                        </tr>
                        <tr class="ab-login-gradient <?php echo esc_attr( $hg ); ?>">
                            <th scope="row"><?php esc_html_e( 'Gradient', 'admin-buddy' ); ?></th>
                            <td>
                                <div class="ab-flex-row">
                                    <div>
                                        <label for="admbud_login_grad_from" class="ab-field-sublabel"><?php esc_html_e( 'From', 'admin-buddy' ); ?></label>
                                        <input type="color" id="admbud_login_grad_from" name="admbud_login_grad_from" value="<?php echo esc_attr( $grad_from ); ?>" class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::DEFAULT_SIDEBAR_GRAD_FROM ); ?>">
                                    </div>
                                    <span class="ab-grad-arrow">→</span>
                                    <div>
                                        <label for="admbud_login_grad_to" class="ab-field-sublabel"><?php esc_html_e( 'To', 'admin-buddy' ); ?></label>
                                        <input type="color" id="admbud_login_grad_to" name="admbud_login_grad_to" value="<?php echo esc_attr( $grad_to ); ?>" class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::DEFAULT_SIDEBAR_GRAD_TO ); ?>">
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr class="ab-login-gradient <?php echo esc_attr( $hg ); ?>">
                            <th scope="row"><?php esc_html_e( 'Direction', 'admin-buddy' ); ?></th>
                            <td>
                                <div class="ab-direction-grid">
                                    <?php foreach ( $grid_order as $dir ) :
                                        if ( $dir === null ) : ?><span class="ab-direction-grid__center">·</span>
                                        <?php else : ?>
                                            <label class="ab-direction-grid__cell <?php echo $grad_dir === $dir ? 'ab-direction-grid__cell--active' : ''; ?>" title="<?php echo esc_attr( $dir ); ?>">
                                                <input type="radio" name="admbud_login_grad_direction" value="<?php echo esc_attr( $dir ); ?>" <?php checked( $grad_dir, $dir ); ?>>
                                                <?php echo esc_html( $directions[ $dir ] ); ?>
                                            </label>
                                        <?php endif; endforeach; ?>
                                </div>
                            </td>
                        </tr>
                        <tr class="ab-login-image <?php echo esc_attr( $hi ); ?>">
                            <th scope="row"><label for="admbud_login_bg_image_url"><?php esc_html_e( 'Image', 'admin-buddy' ); ?></label></th>
                            <td>
                                <?php if ( $bg_image ) : ?><div class="ab-mb-2"><img src="<?php echo esc_url( $bg_image ); ?>" alt="" class="ab-img-preview"></div><?php endif; ?>
                                <input type="url" id="admbud_login_bg_image_url" name="admbud_login_bg_image_url" value="<?php echo esc_url( $bg_image ); ?>" class="regular-text" placeholder="https://...">
                                <button type="button" class="button ab-media-upload" data-target="admbud_login_bg_image_url"><?php esc_html_e( 'Choose Image', 'admin-buddy' ); ?></button>
                            </td>
                        </tr>
                        <tr class="ab-login-image <?php echo esc_attr( $hi ); ?>">
                            <th scope="row"><?php esc_html_e( 'Overlay', 'admin-buddy' ); ?></th>
                            <td>
                                <div class="ab-flex-row--lg">
                                    <div>
                                        <label for="admbud_login_bg_overlay_color" class="ab-field-sublabel"><?php esc_html_e( 'Color', 'admin-buddy' ); ?></label>
                                        <input type="color" id="admbud_login_bg_overlay_color" name="admbud_login_bg_overlay_color" value="<?php echo esc_attr( $overlay_color ); ?>" class="ab-native-color-picker" data-default-color="#000000">
                                    </div>
                                    <div>
                                        <label for="admbud_login_bg_overlay_opacity" class="ab-field-sublabel"><?php esc_html_e( 'Opacity', 'admin-buddy' ); ?></label>
                                        <div class="ab-flex-row--sm">
                                            <input type="range" class="ab-range-display" data-display="admbud_overlay_op_val" data-suffix="%" id="admbud_login_bg_overlay_opacity" name="admbud_login_bg_overlay_opacity" min="0" max="90" step="5" value="<?php echo esc_attr( $overlay_op ); ?>" style="width:140px;">
                                            <span id="admbud_overlay_op_val" class="ab-range-value"><?php echo esc_html( $overlay_op ); ?>%</span>
                                        </div>
                                    </div>
                                </div>
                                <p class="description"><?php esc_html_e( '0% = no overlay. Increase to improve card readability.', 'admin-buddy' ); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php $settings->card_close(); ?>

                    <button type="submit" class="ab-form-save-btn" style="display:none;" aria-hidden="true"></button>
                </div><!-- /.ab-login-main -->

                <!-- Right: sticky preview -->
                <div class="ab-login-preview-col">
                    <div class="ab-colours-preview-sticky">
                        <p class="ab-colours-preview-label"><?php esc_html_e( 'Live Preview', 'admin-buddy' ); ?></p>
                        <div id="ab-login-preview" data-position="<?php echo esc_attr( $card_position ); ?>"<?php if ( $login_scheme_active ) : ?> data-scheme-active="1" data-scheme-bg="<?php echo esc_attr( $login_scheme_bg ); ?>"<?php endif; ?><?php if ( $colours_off ) : ?> data-scheme-accent="<?php echo esc_attr( $preview_accent ); ?>"<?php endif; ?> style="width:100%;aspect-ratio:3/2;border-radius:8px;border:1px solid #e2e8f0;display:flex;align-items:center;justify-content:center;overflow:hidden;transition:background 0.3s;position:relative;<?php echo $login_scheme_active ? 'background:' . esc_attr( $login_scheme_bg ) . ';' : ''; ?>">
                            <div id="ab-login-preview-overlay" style="position:absolute;inset:0;pointer-events:none;"></div>
                            <!-- Realistic WP login form mockup -->
                            <div id="ab-login-preview-card" style="background:#fff;padding:20px 22px 18px;border-radius:4px;font-size:0;box-shadow:0 1px 3px rgba(0,0,0,.13);position:relative;z-index:1;width:130px;flex-shrink:0;">
                                <!-- Logo area -->
                                <div id="ab-preview-logo-wrap" style="text-align:center;margin-bottom:12px;min-height:24px;display:flex;align-items:center;justify-content:center;">
                                    <?php if ( $logo_url ) : ?>
                                        <img id="ab-preview-logo-img" src="<?php echo esc_url( $logo_url ); ?>" alt="" style="max-height:28px;max-width:90px;object-fit:contain;display:block;margin:0 auto;">
                                    <?php else : ?>
                                        <svg id="ab-preview-logo-wp" width="28" height="28" viewBox="0 0 185.2 185.2" fill="<?php echo esc_attr( $preview_btn_bg ); ?>"><path d="M92.6 0C41.4 0 0 41.4 0 92.6s41.4 92.6 92.6 92.6 92.6-41.4 92.6-92.6S143.8 0 92.6 0zm0 13.3c43.8 0 79.3 35.5 79.3 79.3 0 43.8-35.5 79.3-79.3 79.3-43.8 0-79.3-35.5-79.3-79.3 0-43.8 35.5-79.3 79.3-79.3z"/><path d="M18 92.6C18 130.9 42 163 75.7 175.4L26.2 45.7C21.1 59.7 18 76.4 18 92.6zm140.6-4.2c0-12.1-4.3-20.4-8-26.9-5-8.1-9.6-14.9-9.6-23 0-9 6.8-17.4 16.4-17.4.4 0 .8 0 1.3.1-17.4-15.9-40.6-25.7-66.1-25.7-34.2 0-64.3 17.5-81.8 44.1 2.3.1 4.5.1 6.3.1 10.2 0 26-1.2 26-1.2 5.3-.3 5.9 7.4.7 8-5.3.3-10.7 1.1-10.7 1.1l34 101.2 20.4-61.3-14.5-39.9c-5.3-.3-10.3-1.1-10.3-1.1-5.3-.3-4.7-8.3.6-8 0 0 16.1 1.2 25.7 1.2 10.2 0 26-1.2 26-1.2 5.3-.3 5.9 7.4.7 8-5.3.3-10.7 1.1-10.7 1.1l33.7 100.3 9.3-31c4.1-13 7.2-22.3 7.2-30.4z"/><path d="M93.8 99.9l-28 81.4c8.4 2.4 17.2 3.8 26.4 3.8 10.9 0 21.3-1.9 31-5.3-.2-.4-.5-.8-.7-1.2L93.8 99.9zm76.7-50.7c.4 3.1.6 6.4.6 9.9 0 9.8-1.8 20.8-7.4 34.5l-29.7 85.9c28.9-16.8 48.4-47.9 48.4-83.6 0-17-4.4-32.9-12-46.7z"/></svg>
                                    <?php endif; ?>
                                </div>
                                <!-- Username field -->
                                <div class="ab-lp-text" style="font-size:8px;color:#3c434a;font-weight:600;margin-bottom:3px;line-height:1;">Username</div>
                                <div style="height:14px;background:#f0f0f1;border:1px solid #8c8f94;border-radius:2px;margin-bottom:7px;"></div>
                                <!-- Password field -->
                                <div class="ab-lp-text" style="font-size:8px;color:#3c434a;font-weight:600;margin-bottom:3px;line-height:1;">Password</div>
                                <div style="height:14px;background:#f0f0f1;border:1px solid #8c8f94;border-radius:2px;margin-bottom:8px;display:flex;align-items:center;padding:0 4px;gap:2px;">
                                    <div class="ab-dot"></div>
                                    <div class="ab-dot"></div>
                                    <div class="ab-dot"></div>
                                    <div class="ab-dot"></div>
                                    <div class="ab-dot"></div>
                                </div>
                                <!-- Remember me + Login button (same row, matches real WP login) -->
                                <div style="display:flex;align-items:center;justify-content:space-between;gap:4px;margin-bottom:7px;">
                                    <div style="display:flex;align-items:center;gap:3px;">
                                        <div style="width:8px;height:8px;border:1px solid #8c8f94;border-radius:1px;background:#fff;flex-shrink:0;"></div>
                                        <div class="ab-lp-text" style="font-size:6px;color:#646970;line-height:1;white-space:nowrap;">Remember Me</div>
                                    </div>
                                    <div id="ab-preview-login-btn" style="height:16px;border-radius:3px;cursor:default;background-color:<?php echo esc_attr( $preview_btn_bg ); ?>;border:1px solid <?php echo esc_attr( $preview_btn_bg ); ?>;display:flex;align-items:center;justify-content:center;padding:0 7px;flex-shrink:0;">
                                        <span style="font-size:7px;font-weight:600;color:#fff;line-height:1;white-space:nowrap;">Log In</span>
                                    </div>
                                </div>
                                <!-- Lost password -->
                                <div class="ab-lp-text" style="text-align:center;margin-top:7px;font-size:7px;color:#646970;">Lost your password?</div>
                            </div>
                        </div>
                        <p class="description ab-mt-2 ab-hint"><?php esc_html_e( 'Live preview. Updates as you change settings.', 'admin-buddy' ); ?></p>
                    </div>
                </div><!-- /.ab-login-preview-col -->
            </div><!-- /.ab-login-layout -->
        </form>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=admbud&tab=login' ) ); ?>" class="ab-reset-form" style="margin-top:8px;"
              data-confirm-title="<?php esc_attr_e( 'Reset Login Settings?', 'admin-buddy' ); ?>"
              data-confirm-body="<?php esc_attr_e( 'This restores every Login tab setting - logo, layout, background, and form card - to its default. This cannot be undone.', 'admin-buddy' ); ?>">
            <?php wp_nonce_field( 'admbud_reset_login', 'admbud_reset_login_nonce' ); ?>
            <input type="hidden" name="admbud_action" value="reset_login">
            <button type="submit" class="ab-btn ab-btn--danger">
                <?php esc_html_e( 'Reset Login Settings', 'admin-buddy' ); ?>
            </button>
        </form>
        <?php

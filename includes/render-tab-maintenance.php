<?php
/**
 * Maintenance tab UI - Mode selector, pages, bypass URLs, emergency access.
 * Included by Settings::render_tab_maintenance().
 * $settings is the Settings singleton - use $settings->card_open() etc.
 *
 * @package Admbud
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Variables scoped to this included template file.

use Admbud\Colours;
use Admbud\Maintenance;

/** @var \Admbud\Settings $settings */
        $mode          = admbud_get_option( 'admbud_maintenance_mode', 'off' );
        $emergency_url = Maintenance::emergency_url();

        // Coming Soon values
        $cs_title      = admbud_get_option( 'admbud_coming_soon_title',   __( 'Coming Soon', 'admin-buddy' ) );
        $cs_message    = admbud_get_option( 'admbud_coming_soon_message', __( "We're working on something exciting. Stay tuned!", 'admin-buddy' ) );
        $cs_bg_type    = admbud_get_option( 'admbud_cs_bg_type',          'gradient' );
        $cs_bg_color   = esc_attr( admbud_get_option( 'admbud_cs_bg_color',       Colours::DEFAULT_PAGE_BG           ) );
        $cs_grad_from  = esc_attr( admbud_get_option( 'admbud_cs_grad_from',      Colours::DEFAULT_SIDEBAR_GRAD_FROM ) );
        $cs_grad_to    = esc_attr( admbud_get_option( 'admbud_cs_grad_to',        Colours::DEFAULT_SIDEBAR_GRAD_TO   ) );
        $cs_grad_dir   =           admbud_get_option( 'admbud_cs_grad_direction',  'to bottom right' );
        $cs_bg_image   = esc_url(  admbud_get_option( 'admbud_cs_bg_image_url',   ''           ) );
        $cs_ov_color   = esc_attr( admbud_get_option( 'admbud_cs_bg_overlay_color',   '#000000' ) );
        $cs_ov_op      = absint(   admbud_get_option( 'admbud_cs_bg_overlay_opacity', 30       ) );

        // Maintenance values
        $maint_title      = admbud_get_option( 'admbud_maintenance_title',   __( 'Under Maintenance', 'admin-buddy' ) );
        $maint_message    = admbud_get_option( 'admbud_maintenance_message', __( "We're performing scheduled maintenance. We'll be back shortly!", 'admin-buddy' ) );
        $maint_bg_type    = admbud_get_option( 'admbud_maint_bg_type',       'gradient' );
        $maint_bg_color   = esc_attr( admbud_get_option( 'admbud_maint_bg_color',       Colours::DEFAULT_PAGE_BG           ) );
        $maint_grad_from  = esc_attr( admbud_get_option( 'admbud_maint_grad_from',      Colours::DEFAULT_SIDEBAR_GRAD_FROM ) );
        $maint_grad_to    = esc_attr( admbud_get_option( 'admbud_maint_grad_to',        Colours::DEFAULT_SIDEBAR_GRAD_TO   ) );
        $maint_grad_dir   =           admbud_get_option( 'admbud_maint_grad_direction',  'to bottom right' );
        $maint_bg_image   = esc_url(  admbud_get_option( 'admbud_maint_bg_image_url',   ''           ) );
        $maint_ov_color   = esc_attr( admbud_get_option( 'admbud_maint_bg_overlay_color',   '#000000' ) );
        $maint_ov_op      = absint(   admbud_get_option( 'admbud_maint_bg_overlay_opacity', 30       ) );

        // Text colours for intercept pages
        $cs_text_color      = esc_attr( admbud_get_option( 'admbud_cs_text_color',      Colours::DEFAULT_PAGE_TEXT    ) );
        $cs_message_color   = esc_attr( admbud_get_option( 'admbud_cs_message_color',   Colours::DEFAULT_PAGE_MESSAGE ) );
        $maint_text_color   = esc_attr( admbud_get_option( 'admbud_maint_text_color',   Colours::DEFAULT_PAGE_TEXT    ) );
        $maint_message_color= esc_attr( admbud_get_option( 'admbud_maint_message_color',Colours::DEFAULT_PAGE_MESSAGE ) );

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

        // Visibility helpers (PHP-rendered initial state; JS keeps them in sync instantly)
        $cs_hidden    = $mode !== 'coming_soon' ? 'ab-hidden' : '';
        $maint_hidden = $mode !== 'maintenance' ? 'ab-hidden' : '';
        $off_hidden   = $mode === 'off'         ? 'ab-hidden' : '';

        // bg-type sub-row visibility helpers
        $cs_hs    = $cs_bg_type    !== 'solid'    ? 'ab-hidden' : '';
        $cs_hg    = $cs_bg_type    !== 'gradient' ? 'ab-hidden' : '';
        $cs_hi    = $cs_bg_type    !== 'image'    ? 'ab-hidden' : '';
        $maint_hs = $maint_bg_type !== 'solid'    ? 'ab-hidden' : '';
        $maint_hg = $maint_bg_type !== 'gradient' ? 'ab-hidden' : '';
        $maint_hi = $maint_bg_type !== 'image'    ? 'ab-hidden' : '';

        $subtabs = [ 'mode-pages' => __( 'Mode & Pages', 'admin-buddy' ), 'emergency' => __( 'Bypass & Emergency URLs', 'admin-buddy' ) ];
        $active  = \Admbud\Settings::get_active_subtab( 'maintenance', 'mode' );
        $active  = in_array( $active, [ 'mode-pages', 'emergency' ], true ) ? $active : 'mode-pages';
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'admbud_maintenance_group' ); ?>
            <input type="hidden" name="admbud_tab" value="maintenance">
            <input type="hidden" name="admbud_subtab" id="ab-maint-subtab-field" value="<?php echo esc_attr( $active ); ?>">

            <div class="ab-maint-layout">
            <div class="ab-maint-main">
            <div class="ab-subnav" data-subtab-field="#ab-maint-subtab-field" data-panel-prefix="maint-">
                <?php foreach ( $subtabs as $slug => $label ) : ?>
                <button type="button" class="ab-subnav__item<?php echo $active === $slug ? ' is-active' : ''; ?>" data-panel="<?php echo esc_attr( $slug ); ?>">
                    <?php echo esc_html( $label ); ?>
                </button>
                <?php endforeach; ?>
            </div>

            <!-- Panel: Mode & Pages -->
            <div class="ab-pane<?php echo $active !== 'mode-pages' ? ' ab-hidden' : ''; ?>" id="ab-pane-maint-mode-pages">
            <?php // -- Card: Mode selector -- ?>
            <div class="ab-section">
                <div class="ab-section__header">
                    <span class="ab-section__icon ab-section__icon"><svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg></span>
                    <div>
                        <h3>
                            <?php esc_html_e( 'Site Mode', 'admin-buddy' ); ?>
                            <?php if ( $mode !== 'off' ) : ?>
                                <span class="ab-badge ab-badge--active" style="margin-left:8px;">
                                    <?php echo $mode === 'coming_soon'
                                        ? esc_html__( 'COMING SOON ON', 'admin-buddy' )
                                        : esc_html__( 'MAINTENANCE ON', 'admin-buddy' ); ?>
                                </span>
                            <?php endif; ?>
                        </h3>
                        <p><?php esc_html_e( 'Admins are always bypassed. WP cron always runs.', 'admin-buddy' ); ?></p>
                    </div>
                </div>
                <div class="ab-section__body">
                    <div class="ab-mode-selector">
                        <?php
                        $modes = [
                            'off'         => [ 'label' => __( 'Off',         'admin-buddy' ), 'svg' => '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>', 'desc' => __( 'Site is live and accessible to everyone.',                                               'admin-buddy' ) ],
                            'coming_soon' => [ 'label' => __( 'Coming Soon', 'admin-buddy' ), 'svg' => '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/></svg>', 'desc' => __( 'Pre-launch page. Returns 200 OK. Search engines can index it.',                          'admin-buddy' ) ],
                            'maintenance' => [ 'label' => __( 'Maintenance', 'admin-buddy' ), 'svg' => '<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>', 'desc' => __( 'Temporary outage. Returns 503 + Retry-After. Search engines will not deindex your site.', 'admin-buddy' ) ],
                        ];
                        foreach ( $modes as $value => $opt ) : ?>
                            <label class="ab-mode-option <?php echo $mode === $value ? 'ab-mode-option--active' : ''; ?>">
                                <input type="radio" name="admbud_maintenance_mode"
                                       value="<?php echo esc_attr( $value ); ?>" <?php checked( $mode, $value ); ?>>
                                <span class="ab-mode-option__icon"><?php echo admbud_kses_svg( $opt['svg'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitised via wp_kses() ?></span>
                                <span class="ab-mode-option__label"><?php echo esc_html( $opt['label'] ); ?></span>
                                <span class="ab-mode-option__desc"><?php echo esc_html( $opt['desc'] ); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

<?php // -- Card: Coming Soon settings -- ?>
            <div class="ab-section ab-mode-fields ab-mode-fields--coming_soon <?php echo esc_attr( $cs_hidden ); ?>">
                <div class="ab-section__header">
                    <span class="ab-section__icon ab-section__icon"><svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg></span>
                    <div><h3><?php esc_html_e( 'Coming Soon Page', 'admin-buddy' ); ?></h3><p><?php esc_html_e( 'Customise what visitors see before launch.', 'admin-buddy' ); ?></p></div>
                </div>
                <div class="ab-section__body">
                    <table class="form-table ab-form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="admbud_coming_soon_title"><?php esc_html_e( 'Heading', 'admin-buddy' ); ?></label></th>
                            <td><input type="text" id="admbud_coming_soon_title" name="admbud_coming_soon_title" value="<?php echo esc_attr( $cs_title ); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="admbud_coming_soon_message"><?php esc_html_e( 'Message', 'admin-buddy' ); ?></label></th>
                            <td>
                                <textarea name="admbud_coming_soon_message" id="admbud_coming_soon_message" rows="3" class="large-text"><?php echo esc_textarea( $cs_message ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'Basic HTML allowed.', 'admin-buddy' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="admbud_cs_text_color"><?php esc_html_e( 'Heading Colour', 'admin-buddy' ); ?></label></th>
                            <td><input type="color" id="admbud_cs_text_color" name="admbud_cs_text_color" value="<?php echo esc_attr( $cs_text_color ); ?>" class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::DEFAULT_PAGE_TEXT ); ?>"><p class="description"><?php esc_html_e( 'Colour of the main heading text.', 'admin-buddy' ); ?></p></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="admbud_cs_message_color"><?php esc_html_e( 'Message Colour', 'admin-buddy' ); ?></label></th>
                            <td><input type="color" id="admbud_cs_message_color" name="admbud_cs_message_color" value="<?php echo esc_attr( $cs_message_color ); ?>" class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::DEFAULT_PAGE_MESSAGE ); ?>"><p class="description"><?php esc_html_e( 'Colour of the message body text.', 'admin-buddy' ); ?></p></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Background Type', 'admin-buddy' ); ?></th>
                            <td>
                                <div class="ab-flex-row--lg">
                                    <?php foreach ( [ 'solid' => __( 'Solid Color', 'admin-buddy' ), 'gradient' => __( 'Gradient', 'admin-buddy' ), 'image' => __( 'Image', 'admin-buddy' ) ] as $val => $lbl ) : ?>
                                        <label class="ab-flex-row--xs" style="cursor:pointer;">
                                            <input type="radio" name="admbud_cs_bg_type" value="<?php echo esc_attr( $val ); ?>" <?php checked( $cs_bg_type, $val ); ?>>
                                            <?php echo esc_html( $lbl ); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                        <tr class="ab-cs-solid <?php echo esc_attr( $cs_hs ); ?>">
                            <th scope="row"><label for="admbud_cs_bg_color"><?php esc_html_e( 'Color', 'admin-buddy' ); ?></label></th>
                            <td><input type="color" id="admbud_cs_bg_color" name="admbud_cs_bg_color" value="<?php echo esc_attr( $cs_bg_color ); ?>" class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::DEFAULT_PAGE_BG ); ?>"></td>
                        </tr>
                        <tr class="ab-cs-gradient <?php echo esc_attr( $cs_hg ); ?>">
                            <th scope="row"><?php esc_html_e( 'Gradient', 'admin-buddy' ); ?></th>
                            <td>
                                <div class="ab-flex-row">
                                    <div>
                                        <label for="admbud_cs_grad_from" class="ab-field-sublabel"><?php esc_html_e( 'From', 'admin-buddy' ); ?></label>
                                        <input type="color" id="admbud_cs_grad_from" name="admbud_cs_grad_from" value="<?php echo esc_attr( $cs_grad_from ); ?>" class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::DEFAULT_SIDEBAR_GRAD_FROM ); ?>">
                                    </div>
                                    <span class="ab-grad-arrow">→</span>
                                    <div>
                                        <label for="admbud_cs_grad_to" class="ab-field-sublabel"><?php esc_html_e( 'To', 'admin-buddy' ); ?></label>
                                        <input type="color" id="admbud_cs_grad_to" name="admbud_cs_grad_to" value="<?php echo esc_attr( $cs_grad_to ); ?>" class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::DEFAULT_SIDEBAR_GRAD_TO ); ?>">
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr class="ab-cs-gradient <?php echo esc_attr( $cs_hg ); ?>">
                            <th scope="row"><?php esc_html_e( 'Direction', 'admin-buddy' ); ?></th>
                            <td>
                                <div class="ab-direction-grid">
                                    <?php foreach ( $grid_order as $dir ) :
                                        if ( $dir === null ) : ?>
                                            <span class="ab-direction-grid__center">·</span>
                                        <?php else : ?>
                                            <label class="ab-direction-grid__cell <?php echo $cs_grad_dir === $dir ? 'ab-direction-grid__cell--active' : ''; ?>" title="<?php echo esc_attr( $dir ); ?>">
                                                <input type="radio" name="admbud_cs_grad_direction" value="<?php echo esc_attr( $dir ); ?>" <?php checked( $cs_grad_dir, $dir ); ?>>
                                                <?php echo esc_html( $directions[ $dir ] ); ?>
                                            </label>
                                        <?php endif;
                                    endforeach; ?>
                                </div>
                            </td>
                        </tr>
                        <tr class="ab-cs-image <?php echo esc_attr( $cs_hi ); ?>">
                            <th scope="row"><label for="admbud_cs_bg_image_url"><?php esc_html_e( 'Image', 'admin-buddy' ); ?></label></th>
                            <td>
                                <?php if ( $cs_bg_image ) : ?>
                                    <div class="ab-mb-2"><img src="<?php echo esc_url( $cs_bg_image ); ?>" alt="" class="ab-img-preview"></div>
                                <?php endif; ?>
                                <input type="url" id="admbud_cs_bg_image_url" name="admbud_cs_bg_image_url" value="<?php echo esc_url( $cs_bg_image ); ?>" class="regular-text" placeholder="https://...">
                                <button type="button" class="button ab-media-upload" data-target="admbud_cs_bg_image_url"><?php esc_html_e( 'Choose Image', 'admin-buddy' ); ?></button>
                            </td>
                        </tr>
                        <tr class="ab-cs-image <?php echo esc_attr( $cs_hi ); ?>">
                            <th scope="row"><?php esc_html_e( 'Overlay', 'admin-buddy' ); ?></th>
                            <td>
                                <div class="ab-flex-row--lg">
                                    <div>
                                        <label for="admbud_cs_bg_overlay_color" class="ab-field-sublabel"><?php esc_html_e( 'Color', 'admin-buddy' ); ?></label>
                                        <input type="color" id="admbud_cs_bg_overlay_color" name="admbud_cs_bg_overlay_color" value="<?php echo esc_attr( $cs_ov_color ); ?>" class="ab-native-color-picker" data-default-color="#000000">
                                    </div>
                                    <div>
                                        <label for="admbud_cs_bg_overlay_opacity" class="ab-field-sublabel"><?php esc_html_e( 'Opacity', 'admin-buddy' ); ?></label>
                                        <div class="ab-flex-row--sm">
                                            <input type="range" id="admbud_cs_bg_overlay_opacity" name="admbud_cs_bg_overlay_opacity" min="0" max="90" step="5" value="<?php echo esc_attr( $cs_ov_op ); ?>" style="width:140px;" oninput="document.getElementById('admbud_cs_overlay_op_val').textContent=this.value+'%'">
                                            <span id="admbud_cs_overlay_op_val" class="ab-range-value"><?php echo esc_html( $cs_ov_op ); ?>%</span>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

<?php // -- Card: Maintenance settings -- ?>
            <div class="ab-section ab-mode-fields ab-mode-fields--maintenance <?php echo esc_attr( $maint_hidden ); ?>">
                <div class="ab-section__header">
                    <span class="ab-section__icon ab-section__icon"><svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg></span>
                    <div><h3><?php esc_html_e( 'Maintenance Page', 'admin-buddy' ); ?></h3><p><?php esc_html_e( 'Customise what visitors see during maintenance.', 'admin-buddy' ); ?></p></div>
                </div>
                <div class="ab-section__body">
                    <table class="form-table ab-form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="admbud_maintenance_title"><?php esc_html_e( 'Heading', 'admin-buddy' ); ?></label></th>
                            <td><input type="text" id="admbud_maintenance_title" name="admbud_maintenance_title" value="<?php echo esc_attr( $maint_title ); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="admbud_maintenance_message"><?php esc_html_e( 'Message', 'admin-buddy' ); ?></label></th>
                            <td>
                                <textarea name="admbud_maintenance_message" id="admbud_maintenance_message" rows="3" class="large-text"><?php echo esc_textarea( $maint_message ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'Basic HTML allowed.', 'admin-buddy' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="admbud_maint_text_color"><?php esc_html_e( 'Heading Colour', 'admin-buddy' ); ?></label></th>
                            <td><input type="color" id="admbud_maint_text_color" name="admbud_maint_text_color" value="<?php echo esc_attr( $maint_text_color ); ?>" class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::DEFAULT_PAGE_TEXT ); ?>"><p class="description"><?php esc_html_e( 'Colour of the main heading text.', 'admin-buddy' ); ?></p></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="admbud_maint_message_color"><?php esc_html_e( 'Message Colour', 'admin-buddy' ); ?></label></th>
                            <td><input type="color" id="admbud_maint_message_color" name="admbud_maint_message_color" value="<?php echo esc_attr( $maint_message_color ); ?>" class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::DEFAULT_PAGE_MESSAGE ); ?>"><p class="description"><?php esc_html_e( 'Colour of the message body text.', 'admin-buddy' ); ?></p></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Background Type', 'admin-buddy' ); ?></th>
                            <td>
                                <div class="ab-flex-row--lg">
                                    <?php foreach ( [ 'solid' => __( 'Solid Color', 'admin-buddy' ), 'gradient' => __( 'Gradient', 'admin-buddy' ), 'image' => __( 'Image', 'admin-buddy' ) ] as $val => $lbl ) : ?>
                                        <label class="ab-flex-row--xs" style="cursor:pointer;">
                                            <input type="radio" name="admbud_maint_bg_type" value="<?php echo esc_attr( $val ); ?>" <?php checked( $maint_bg_type, $val ); ?>>
                                            <?php echo esc_html( $lbl ); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                        <tr class="ab-maint-solid <?php echo esc_attr( $maint_hs ); ?>">
                            <th scope="row"><label for="admbud_maint_bg_color"><?php esc_html_e( 'Color', 'admin-buddy' ); ?></label></th>
                            <td><input type="color" id="admbud_maint_bg_color" name="admbud_maint_bg_color" value="<?php echo esc_attr( $maint_bg_color ); ?>" class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::DEFAULT_PAGE_BG ); ?>"></td>
                        </tr>
                        <tr class="ab-maint-gradient <?php echo esc_attr( $maint_hg ); ?>">
                            <th scope="row"><?php esc_html_e( 'Gradient', 'admin-buddy' ); ?></th>
                            <td>
                                <div class="ab-flex-row">
                                    <div>
                                        <label for="admbud_maint_grad_from" class="ab-field-sublabel"><?php esc_html_e( 'From', 'admin-buddy' ); ?></label>
                                        <input type="color" id="admbud_maint_grad_from" name="admbud_maint_grad_from" value="<?php echo esc_attr( $maint_grad_from ); ?>" class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::DEFAULT_SIDEBAR_GRAD_FROM ); ?>">
                                    </div>
                                    <span class="ab-grad-arrow">→</span>
                                    <div>
                                        <label for="admbud_maint_grad_to" class="ab-field-sublabel"><?php esc_html_e( 'To', 'admin-buddy' ); ?></label>
                                        <input type="color" id="admbud_maint_grad_to" name="admbud_maint_grad_to" value="<?php echo esc_attr( $maint_grad_to ); ?>" class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::DEFAULT_SIDEBAR_GRAD_TO ); ?>">
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr class="ab-maint-gradient <?php echo esc_attr( $maint_hg ); ?>">
                            <th scope="row"><?php esc_html_e( 'Direction', 'admin-buddy' ); ?></th>
                            <td>
                                <div class="ab-direction-grid">
                                    <?php foreach ( $grid_order as $dir ) :
                                        if ( $dir === null ) : ?>
                                            <span class="ab-direction-grid__center">·</span>
                                        <?php else : ?>
                                            <label class="ab-direction-grid__cell <?php echo $maint_grad_dir === $dir ? 'ab-direction-grid__cell--active' : ''; ?>" title="<?php echo esc_attr( $dir ); ?>">
                                                <input type="radio" name="admbud_maint_grad_direction" value="<?php echo esc_attr( $dir ); ?>" <?php checked( $maint_grad_dir, $dir ); ?>>
                                                <?php echo esc_html( $directions[ $dir ] ); ?>
                                            </label>
                                        <?php endif;
                                    endforeach; ?>
                                </div>
                            </td>
                        </tr>
                        <tr class="ab-maint-image <?php echo esc_attr( $maint_hi ); ?>">
                            <th scope="row"><label for="admbud_maint_bg_image_url"><?php esc_html_e( 'Image', 'admin-buddy' ); ?></label></th>
                            <td>
                                <?php if ( $maint_bg_image ) : ?>
                                    <div class="ab-mb-2"><img src="<?php echo esc_url( $maint_bg_image ); ?>" alt="" class="ab-img-preview"></div>
                                <?php endif; ?>
                                <input type="url" id="admbud_maint_bg_image_url" name="admbud_maint_bg_image_url" value="<?php echo esc_url( $maint_bg_image ); ?>" class="regular-text" placeholder="https://...">
                                <button type="button" class="button ab-media-upload" data-target="admbud_maint_bg_image_url"><?php esc_html_e( 'Choose Image', 'admin-buddy' ); ?></button>
                            </td>
                        </tr>
                        <tr class="ab-maint-image <?php echo esc_attr( $maint_hi ); ?>">
                            <th scope="row"><?php esc_html_e( 'Overlay', 'admin-buddy' ); ?></th>
                            <td>
                                <div class="ab-flex-row--lg">
                                    <div>
                                        <label for="admbud_maint_bg_overlay_color" class="ab-field-sublabel"><?php esc_html_e( 'Color', 'admin-buddy' ); ?></label>
                                        <input type="color" id="admbud_maint_bg_overlay_color" name="admbud_maint_bg_overlay_color" value="<?php echo esc_attr( $maint_ov_color ); ?>" class="ab-native-color-picker" data-default-color="#000000">
                                    </div>
                                    <div>
                                        <label for="admbud_maint_bg_overlay_opacity" class="ab-field-sublabel"><?php esc_html_e( 'Opacity', 'admin-buddy' ); ?></label>
                                        <div class="ab-flex-row--sm">
                                            <input type="range" id="admbud_maint_bg_overlay_opacity" name="admbud_maint_bg_overlay_opacity" min="0" max="90" step="5" value="<?php echo esc_attr( $maint_ov_op ); ?>" style="width:140px;" oninput="document.getElementById('admbud_maint_overlay_op_val').textContent=this.value+'%'">
                                            <span id="admbud_maint_overlay_op_val" class="ab-range-value"><?php echo esc_html( $maint_ov_op ); ?>%</span>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>


            </div><!-- /.ab-pane maint-mode-pages -->

            <!-- Panel: Bypass & Emergency URLs -->
            <div class="ab-pane<?php echo $active !== 'emergency' ? ' ab-hidden' : ''; ?>" id="ab-pane-maint-emergency">
            <?php // -- Card: Bypass URLs -- ?>
            <?php $wp_login_path = wp_parse_url( wp_login_url(), PHP_URL_PATH ); ?>
            <div class="ab-section">
                <div class="ab-section__header">
                    <span class="ab-section__icon ab-section__icon"><svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg></span>
                    <div><h3><?php esc_html_e( 'Bypass URLs', 'admin-buddy' ); ?></h3><p><?php esc_html_e( 'Paths that stay accessible even when a mode is active.', 'admin-buddy' ); ?></p></div>
                </div>
                <div class="ab-section__body">
                    <div class="ab-notice ab-notice ab-notice--info" style="margin-bottom:12px;display:flex;flex-wrap:wrap;align-items:center;gap:6px;">
                        <strong><?php esc_html_e( 'Always bypassed automatically:', 'admin-buddy' ); ?></strong>
                        <code><?php echo esc_html( $wp_login_path ?: 'wp-login.php' ); ?></code> <span>and</span> <code>/wp-admin/</code>
                    </div>
                    <label for="admbud_maintenance_bypass_urls" style="display:block;font-weight:600;margin-bottom:6px;">
                        <?php esc_html_e( 'Custom bypass paths', 'admin-buddy' ); ?>
                    </label>
                    <textarea name="admbud_maintenance_bypass_urls" id="admbud_maintenance_bypass_urls"
                              rows="4" class="large-text"
                              placeholder="/login&#10;/my-account&#10;/custom-login"><?php echo esc_textarea( admbud_get_option( 'admbud_maintenance_bypass_urls', '' ) ); ?></textarea>
                    <p class="description" style="margin-top:6px;"><?php esc_html_e( 'One path per line. Prefix-matched: /my-account also covers /my-account/orders.', 'admin-buddy' ); ?></p>
                </div>
            </div>

            <?php // -- Card: Emergency Access -- ?>
            <div class="ab-section">
                <div class="ab-section__header">
                    <span class="ab-section__icon ab-section__icon"><svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="m21 2-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0 3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg></span>
                    <div>
                        <h3>
                            <?php esc_html_e( 'Emergency Access URL', 'admin-buddy' ); ?>
                            <button type="button" class="ab-tooltip-trigger" data-tooltip="ab-emergency-info" aria-label="<?php esc_attr_e( 'More information', 'admin-buddy' ); ?>">?</button>
                        </h3>
                        <p><?php esc_html_e( 'Your guaranteed way back in if you get locked out.', 'admin-buddy' ); ?></p>
                    </div>
                </div>
                <div id="ab-emergency-info" class="ab-tooltip-content ab-hidden">
                    <strong><?php esc_html_e( 'What it does:', 'admin-buddy' ); ?></strong>
                    <?php esc_html_e( 'Visiting this URL sets a session cookie that removes the maintenance page for your browser, then redirects to your homepage so you can reach your login page normally. Works with any login plugin (Bricks, WooCommerce, native WP, etc).', 'admin-buddy' ); ?>
                    <br><br>
                    <strong><?php esc_html_e( 'Bonus use:', 'admin-buddy' ); ?></strong>
                    <?php esc_html_e( 'Share this URL with a client or designer so they can preview the front end while the site is still in coming soon / maintenance mode, without logging them in or granting any admin access.', 'admin-buddy' ); ?>
                    <br><br>
                    <strong><?php esc_html_e( 'What it does NOT do:', 'admin-buddy' ); ?></strong>
                    <?php esc_html_e( 'Log you in. You still need your admin credentials. WordPress authentication is completely unaffected. The cookie clears when the browser is closed.', 'admin-buddy' ); ?>
                </div>
                <div class="ab-section__body">
                    <input type="text" id="ab-emergency-url" class="large-text"
                           value="<?php echo esc_url( $emergency_url ); ?>"
                           readonly onclick="this.select();"
                           style="font-family:monospace;font-size:0.82rem;background:#f6f7f7;margin-bottom:10px;">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <button type="button" id="ab-copy-emergency-url" class="button button-secondary">
                            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" class="ab-inline-icon"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg><?php esc_html_e( 'Copy URL', 'admin-buddy' ); ?>
                        </button>
                        <button type="button" id="ab-regenerate-token" class="button button-secondary"
                                data-nonce="<?php echo esc_attr( wp_create_nonce( 'admbud_regenerate_token' ) ); ?>">
                            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" class="ab-inline-icon"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg><?php esc_html_e( 'Regenerate', 'admin-buddy' ); ?>
                        </button>
                        <span id="ab-token-feedback" style="font-size:0.8rem;color:#16a34a;display:none;"></span>
                    </div>
                    <p class="description ab-mt-2">
                        <?php esc_html_e( 'Regenerate if this URL is ever exposed. The old one stops working immediately.', 'admin-buddy' ); ?>
                    </p>
                </div>
            </div>

            </div><!-- /.ab-pane maint-emergency -->

            <button type="submit" class="ab-form-save-btn" style="display:none;" aria-hidden="true"></button>
            </div><!-- /.ab-maint-main -->

            <!-- Right column: sticky preview - mirrors login tab structure exactly -->
            <div class="ab-maint-preview-col">
                <p class="ab-colours-preview-label"><?php esc_html_e( 'Page Preview', 'admin-buddy' ); ?></p>
                <?php if ( $mode === 'off' ) : ?>
                    <div class="ab-maint-placeholder">
                        <?php esc_html_e( 'Enable Coming Soon or Maintenance to see a preview.', 'admin-buddy' ); ?>
                    </div>
                    <p class="description ab-mt-2 ab-hint">&nbsp;</p>
                <?php else :
                    $prev_prefix = ( $mode === 'coming_soon' ) ? 'cs' : 'maint';
                    $settings->render_page_preview( $prev_prefix, 'ab-maint-page-preview' );
                endif; ?>
            </div><!-- /.ab-maint-preview-col -->
            </div><!-- /.ab-maint-layout -->
        </form>
        <?php

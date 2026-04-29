<?php
/**
 * White Label module - 2 subtabs: Branding, Dashboard.
 * Included by Settings::render_tab_adminui().
 * $settings is the Settings singleton - use $settings->card_open_svg(), toggle_row(), etc.
 *
 * @package Admbud
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Variables scoped to this included template file.

/** @var \Admbud\Settings $settings */
        $footer_enabled = admbud_get_option( 'admbud_core_custom_footer_enabled', '0' ) === '1';
        $footer_text    = admbud_get_option( 'admbud_core_custom_footer_text', '' );
        $suppress       = admbud_get_option( 'admbud_notices_suppress', '1' ) === '1';
        $pages          = get_pages( [ 'post_status' => [ 'publish', 'draft', 'private', 'pending' ], 'sort_column' => 'post_title' ] );
        $role_pages     = Admbud\Dashboard::get_role_pages();
        $default_page   = isset( $role_pages['_default'] ) ? $role_pages['_default'] : 0;
        $has_role_overrides = ! empty( $role_pages['_per_role'] );

        $subtabs = [
            'adminui-branding'  => __( 'Branding',          'admin-buddy' ),
            'adminui-dashboard' => __( 'Dashboard',         'admin-buddy' ),
        ];
        // Read-only navigation state: which subtab to render. sanitize_key()'d
        // and constrained to known whitelist via array_key_exists() below.
        $active  = sanitize_key( wp_unslash( $_GET['admbud_subtab'] ?? 'adminui-branding' ) ); // phpcs:ignore WordPress.Security.NonceVerification
        $active  = array_key_exists( $active, $subtabs ) ? $active : 'adminui-branding';
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'admbud_core_group' ); ?>
            <input type="hidden" name="admbud_tab" value="adminui">
            <input type="hidden" name="admbud_subtab" id="ab-adminui-subtab-field" value="<?php echo esc_attr( $active ); ?>">

            <div class="ab-subtab-layout">
                <div class="ab-subtab-main">
                    <div class="ab-subnav" data-subtab-field="#ab-adminui-subtab-field">
                        <?php foreach ( $subtabs as $slug => $label ) : ?>
                        <button type="button" class="ab-subnav__item<?php echo $active === $slug ? ' is-active' : ''; ?>" data-panel="<?php echo esc_attr( $slug ); ?>">
                            <?php echo esc_html( $label ); ?>
                        </button>
                        <?php endforeach; ?>
                    </div>

                    <?php // ===============================================================
                          // SUBTAB 1: BRANDING
                          // =============================================================== ?>
                    <div class="ab-pane<?php echo $active !== 'adminui-branding' ? ' ab-hidden' : ''; ?>" id="ab-pane-adminui-branding">

                        <?php // -- Card: Site Identity --------------------------------- ?>
                        <?php $settings->card_open_svg( '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>', __( 'Site Identity', 'admin-buddy' ), __( 'Global branding elements that appear across WordPress.', 'admin-buddy' ) ); ?>
                        <table class="form-table ab-form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="admbud_wl_favicon_url_display"><?php esc_html_e( 'Site Favicon', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <?php
                                    $favicon_id  = absint( get_option( 'site_icon', 0 ) );
                                    $favicon_url = $favicon_id ? esc_url( get_site_icon_url( 64, '', 0 ) ) : '';
                                    ?>
                                    <?php if ( $favicon_url ) : ?>
                                        <div class="ab-img-upload-preview ab-mb-2">
                                            <img src="<?php echo esc_url( $favicon_url ); ?>" alt=""
                                                 style="width:32px;height:32px;object-fit:contain;display:block;
                                                        border:1px solid var(--ab-neutral-200);border-radius:4px;
                                                        padding:2px;background:#fff;">
                                        </div>
                                    <?php endif; ?>
                                    <input type="hidden" id="admbud_wl_favicon_id" name="admbud_wl_favicon_id"
                                           value="<?php echo esc_attr( $favicon_id ); ?>">
                                    <div class="ab-img-upload-row">
                                        <input type="text" id="admbud_wl_favicon_url_display"
                                               aria-label="<?php esc_attr_e( 'Favicon URL', 'admin-buddy' ); ?>"
                                               value="<?php echo esc_attr( $favicon_url ); ?>"
                                               class="regular-text" placeholder="<?php esc_attr_e( 'Image URL', 'admin-buddy' ); ?>"
                                               readonly style="background:var(--ab-neutral-50);cursor:default;">
                                        <button type="button"
                                                class="ab-btn ab-btn--secondary ab-media-upload-with-id"
                                                data-url-display="admbud_wl_favicon_url_display"
                                                data-id-target="admbud_wl_favicon_id">
                                            <?php esc_html_e( 'Choose Image', 'admin-buddy' ); ?>
                                        </button>
                                        <button type="button"
                                                class="ab-btn ab-btn--ghost ab-favicon-reset<?php echo $favicon_id ? '' : ' ab-hidden'; ?>"
                                                data-id-target="admbud_wl_favicon_id"
                                                data-url-display="admbud_wl_favicon_url_display">
                                            <?php esc_html_e( 'Reset', 'admin-buddy' ); ?>
                                        </button>
                                    </div>
                                    <p class="description"><?php esc_html_e( 'Synced with WordPress Settings → General. Changing it here updates it there too, and vice versa.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                        </table>
                        <?php
                        // Sidebar Logo - free feature, rendered outside the table for valid HTML.
                        ?>
                        <table class="form-table ab-form-table" role="presentation">
                            <?php
                            $settings->image_upload_row(
                                'admbud_wl_sidebar_logo_url',
                                __( 'Sidebar Logo', 'admin-buddy' ),
                                __( 'Displayed at the top of the WordPress admin sidebar. Scales to the sidebar width automatically.', 'admin-buddy' ),
                                true,
                                'admbud_wl_sidebar_logo_width',
                                'admbud_wl_sidebar_logo_height',
                                40, 320, 120
                            );
                            ?>
                        </table>
                        <?php $settings->card_close(); ?>

                        <?php // -- Card: Admin Bar ------------------------------------- ?>
                        <?php $settings->card_open_svg( '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="2" y="3" width="20" height="4" rx="1"/><line x1="6" y1="5" x2="6.01" y2="5"/><line x1="10" y1="5" x2="18" y2="5"/></svg>', __( 'Admin Bar', 'admin-buddy' ), __( 'Control what appears in the top admin toolbar and replace WordPress branding.', 'admin-buddy' ) ); ?>
                        <table class="form-table ab-form-table" role="presentation">
                            <?php
                            $settings->text_field_row(
                                'admbud_wl_agency_name',
                                __( 'Agency Name', 'admin-buddy' ),
                                __( 'Replaces "WordPress" in browser tab titles (e.g. "Dashboard ‹ Your Agency") and shows "Powered by Your Agency" in the admin footer when no custom footer text is set.', 'admin-buddy' ),
                                __( 'e.g. Starter Digital', 'admin-buddy' )
                            );
                            $settings->url_field_row(
                                'admbud_wl_agency_url',
                                __( 'Agency URL', 'admin-buddy' ),
                                __( 'The WP logo in the admin bar links to this URL. Also used in the "Powered by" footer link when Agency Name is set.', 'admin-buddy' )
                            );
                            $settings->text_field_row(
                                'admbud_wl_greeting',
                                __( 'Custom Greeting', 'admin-buddy' ),
                                __( 'Replace "Howdy, username" with your own text. Use {username} as a placeholder for the display name.', 'admin-buddy' ),
                                __( 'e.g. Welcome', 'admin-buddy' )
                            );
                            ?>
                        </table>
                        <div class="ab-grid ab-grid--ruled ab-grid--module">
                            <?php
                            $admbud_bar_toggles = [
                                'admbud_core_remove_logo'           => [ 'label' => __( 'Remove WordPress Logo',        'admin-buddy' ), 'desc' => __( 'Hides the WP logo from the admin toolbar.',                                                               'admin-buddy' ), 'default' => '1' ],
                                'admbud_wl_remove_wp_links'         => [ 'label' => __( 'Remove WordPress.org Links',   'admin-buddy' ), 'desc' => __( 'Hides "About WordPress", "Documentation", "Support Forums" and "Feedback" from the admin bar dropdown.', 'admin-buddy' ), 'default' => '0' ],
                                'admbud_show_in_adminbar'           => [ 'label' => __( 'Show Admin Buddy in Admin Bar','admin-buddy' ), 'desc' => __( 'Adds a quick-access link to Admin Buddy in the top toolbar.',                                              'admin-buddy' ), 'default' => '0' ],
                                'admbud_core_remove_help'           => [ 'label' => __( 'Hide Help Tab',                'admin-buddy' ), 'desc' => __( 'Hides the Help dropdown tab from all admin pages.',                                                        'admin-buddy' ), 'default' => '0' ],
                                'admbud_core_remove_screen_options' => [ 'label' => __( 'Hide Screen Options',          'admin-buddy' ), 'desc' => __( 'Hides the Screen Options dropdown tab from admin pages.',                                                   'admin-buddy' ), 'default' => '0' ],
                            ];
                            foreach ( $admbud_bar_toggles as $key => $item ) :
                                $checked = admbud_get_option( $key, $item['default'] ) === '1';
                            ?>
                            <label class="ab-setup-module-row" style="cursor:pointer;align-items:flex-start;">
                                <span class="ab-setup-module-label" style="white-space:normal;display:flex;flex-direction:column;gap:2px;">
                                    <span><?php echo esc_html( $item['label'] ); ?></span>
                                    <span style="font-size:var(--ab-text-xs);font-weight:var(--ab-font-normal);color:var(--ab-text-muted);line-height:1.4;"><?php echo esc_html( $item['desc'] ); ?></span>
                                </span>
                                <span class="ab-toggle ab-module-card__toggle" style="flex-shrink:0;margin-top:2px;">
                                    <input type="hidden"   name="<?php echo esc_attr( $key ); ?>" value="0">
                                    <input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" id="<?php echo esc_attr( $key ); ?>" <?php checked( $checked ); ?>>
                                    <span class="ab-toggle__track"></span><span class="ab-toggle__thumb"></span>
                                </span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <?php $settings->card_close(); ?>

                        <?php // -- Card: Footer ---------------------------------------- ?>
                        <?php $settings->card_open_svg( '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="2" y="17" width="20" height="4" rx="1"/><line x1="6" y1="19" x2="14" y2="19"/></svg>', __( 'Footer', 'admin-buddy' ), __( 'Customise the admin page footer area.', 'admin-buddy' ) ); ?>
                        <table class="form-table ab-form-table" role="presentation">
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Custom Footer Text', 'admin-buddy' ); ?></th>
                                <td>
                                    <label class="ab-toggle">
                                        <input type="hidden"   name="admbud_core_custom_footer_enabled" value="0">
                                        <input type="checkbox" name="admbud_core_custom_footer_enabled" value="1"
                                               id="admbud_core_custom_footer_enabled" <?php checked( $footer_enabled ); ?>>
                                        <span class="ab-toggle__track"></span><span class="ab-toggle__thumb"></span>
                                    </label>
                                    <p class="description"><?php esc_html_e( 'Replace the default WordPress footer text with your own. Turn off to restore the original WP footer.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                            <tr class="ab-footer-text-field <?php echo ! $footer_enabled ? 'ab-hidden' : ''; ?>">
                                <th scope="row"><label for="admbud_core_custom_footer_text"><?php esc_html_e( 'Footer Text', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="text" id="admbud_core_custom_footer_text" name="admbud_core_custom_footer_text"
                                           value="<?php echo esc_attr( $footer_text ); ?>" class="regular-text"
                                           placeholder="<?php esc_attr_e( 'e.g. Built by Your Agency', 'admin-buddy' ); ?>">
                                    <p class="description"><?php esc_html_e( 'Leave blank to show nothing. Basic HTML allowed.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                            <tr class="ab-footer-version-field <?php echo ! $footer_enabled ? 'ab-hidden' : ''; ?>">
                                <th scope="row"><label for="admbud_wl_footer_version"><?php esc_html_e( 'Footer Version Text', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="text" id="admbud_wl_footer_version" name="admbud_wl_footer_version"
                                           value="<?php echo esc_attr( admbud_get_option( 'admbud_wl_footer_version', '' ) ); ?>" class="regular-text"
                                           placeholder="<?php esc_attr_e( 'e.g. Agency Platform v2.0', 'admin-buddy' ); ?>">
                                    <p class="description"><?php esc_html_e( 'Replaces "Version X.X" on the right side of the admin footer. Leave blank to show nothing.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                            <?php
                            $settings->toggle_row(
                                'admbud_wl_footer_quote',
                                __( 'Show Motivational Quote', 'admin-buddy' ),
                                __( 'Displays a random motivational quote on the right side of the admin footer, before the version text.', 'admin-buddy' ),
                                '0'
                            );
                            ?>
                        </table>
                        <?php $settings->card_close(); ?>

                    </div><!-- /.ab-pane (branding) -->

                    <?php // ===============================================================
                          // SUBTAB 2: DASHBOARD
                          // =============================================================== ?>
                    <div class="ab-pane<?php echo $active !== 'adminui-dashboard' ? ' ab-hidden' : ''; ?>" id="ab-pane-adminui-dashboard">

                        <?php
                        // -- Card: Dashboard Page ----------------------------------
                        // Helper: page option label with status badge
                        $admbud_page_options = function( $pages, $selected ) {
                            $out = '';
                            foreach ( $pages as $p ) {
                                $label = $p->post_title ?: __( '(no title)', 'admin-buddy' );
                                if ( $p->post_status !== 'publish' ) {
                                    $label .= ' [' . ucfirst( $p->post_status ) . ']';
                                }
                                $out .= sprintf(
                                    '<option value="%s"%s>%s</option>',
                                    esc_attr( $p->ID ),
                                    selected( $selected, $p->ID, false ),
                                    esc_html( $label )
                                );
                            }
                            return $out;
                        };
                        ?>
                        <?php $settings->card_open_svg( '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>', __( 'Dashboard Page', 'admin-buddy' ), __( 'Show a custom page instead of the standard WordPress dashboard.', 'admin-buddy' ) ); ?>
                        <input type="hidden" name="admbud_dashboard_role_pages" id="admbud_dashboard_role_pages"
                               value="<?php echo esc_attr( admbud_get_option( 'admbud_dashboard_role_pages', '{}' ) ); ?>">
                        <table class="form-table ab-form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="admbud_dashboard_default_page"><?php esc_html_e( 'Default Page', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <select id="admbud_dashboard_default_page" class="ab-select ab-dashboard-page-select" data-role="_default">
                                        <option value="0"><?php esc_html_e( '- WordPress Dashboard -', 'admin-buddy' ); ?></option>
                                        <?php echo $admbud_page_options( $pages, $default_page ); // phpcs:ignore ?>
                                    </select>
                                    <p class="description"><?php esc_html_e( 'Shown to all users unless a role override is set.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Different Page Per Role', 'admin-buddy' ); ?></th>
                                <td>
                                    <label class="ab-toggle">
                                        <input type="checkbox" id="admbud_dashboard_role_overrides_toggle"
                                               <?php checked( $has_role_overrides ); ?>>
                                        <span class="ab-toggle__track"></span><span class="ab-toggle__thumb"></span>
                                    </label>
                                    <p class="description"><?php esc_html_e( 'Show a different dashboard page based on the user role.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                        </table>

                        <?php // -- Role overrides grid -- ?>
                        <div id="ab-dashboard-role-grid" class="<?php echo $has_role_overrides ? '' : 'ab-hidden'; ?>" style="margin-top:var(--ab-space-2);">
                            <div class="ab-grid ab-grid--ruled ab-grid--module">
                                <?php
                                $wp_roles = wp_roles()->roles;
                                foreach ( $wp_roles as $role_slug => $role_data ) :
                                    $role_page = $role_pages[ $role_slug ] ?? '';
                                ?>
                                <div class="ab-setup-module-row" style="flex-direction:column;align-items:stretch;gap:var(--ab-space-1);">
                                    <span class="ab-setup-module-label" style="font-weight:600;font-size:var(--ab-text-xs);text-transform:uppercase;letter-spacing:0.03em;opacity:0.7;"><?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?></span>
                                    <select class="ab-select ab-dashboard-page-select" data-role="<?php echo esc_attr( $role_slug ); ?>" style="width:100%;">
                                        <option value=""><?php esc_html_e( 'Use Default', 'admin-buddy' ); ?></option>
                                        <option value="wp_default" <?php selected( $role_page, 'wp_default' ); ?>><?php esc_html_e( 'WordPress Dashboard', 'admin-buddy' ); ?></option>
                                        <?php echo $admbud_page_options( $pages, $role_page ); // phpcs:ignore ?>
                                    </select>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php $settings->card_close(); ?>

                        <?php // Hidden inputs so values survive saves when custom page is active ?>
                        <input type="hidden" name="admbud_dashboard_keep_widgets" id="admbud_dashboard_keep_widgets"
                               value="<?php echo esc_attr( admbud_get_option( 'admbud_dashboard_keep_widgets', '' ) ); ?>">
                        <input type="hidden" name="admbud_dashboard_custom_widgets" id="admbud_dashboard_custom_widgets"
                               value="<?php echo esc_attr( admbud_get_option( 'admbud_dashboard_custom_widgets', '[]' ) ); ?>">

                        <?php
                        // Check if a custom dashboard page is actually active.
                        $any_page_set = false;
                        $per_role_on  = ! empty( $role_pages['_per_role'] );
                        // Default page set?
                        if ( ! empty( $role_pages['_default'] ) && is_int( $role_pages['_default'] ) ) {
                            $any_page_set = true;
                        }
                        // Role overrides only count if _per_role is on.
                        if ( ! $any_page_set && $per_role_on ) {
                            foreach ( $role_pages as $k => $v ) {
                                if ( $k === '_per_role' || $k === '_default' ) { continue; }
                                if ( is_int( $v ) && $v > 0 ) { $any_page_set = true; break; }
                            }
                        }
                        if ( ! $any_page_set ) :

                        // -- Card: Dashboard Widgets -------------------------------- ?>
                        <?php
                        $catalogue = Admbud\Dashboard::get_catalogue();
                        $keep_ids  = Admbud\Dashboard::get_keep_ids();
                        $settings->card_open_svg(
                            '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',
                            __( 'Dashboard Widgets', 'admin-buddy' ),
                            __( 'Choose which dashboard widgets to show. Toggle off to hide.', 'admin-buddy' )
                        );
                        ?>

                        <?php if ( empty( $catalogue ) ) : ?>
                            <div class="ab-notice ab-notice--info" style="margin:0 0 var(--ab-space-4);">
                                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" class="ab-inline-icon" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                <?php
                                    printf(
                                        /* translators: %s: Dashboard link */
                                        esc_html__( 'Other widgets will appear here after you visit your %s once.', 'admin-buddy' ),
                                        '<a href="' . esc_url( admin_url( 'index.php' ) ) . '">' . esc_html__( 'WordPress Dashboard', 'admin-buddy' ) . '</a>'
                                    );
                                ?>
                            </div>
                        <?php else : ?>
                            <div class="ab-grid ab-grid--ruled ab-grid--module">
                                <?php
                                $is_configured = ! empty( admbud_get_option( 'admbud_dashboard_keep_widgets', '' ) );
                                foreach ( $catalogue as $wid => $wtitle ) :
                                    // If never configured, all are checked (visible). If configured, only kept ones are checked.
                                    $checked = $is_configured ? in_array( $wid, $keep_ids, true ) : true;
                                ?>
                                    <label class="ab-setup-module-row" style="cursor:pointer;">
                                        <span class="ab-setup-module-label"><?php echo esc_html( $wtitle ?: $wid ); ?></span>
                                        <span class="ab-toggle ab-module-card__toggle">
                                            <input type="checkbox" class="ab-widget-keep-toggle"
                                                   data-widget-id="<?php echo esc_attr( $wid ); ?>" <?php checked( $checked ); ?>>
                                            <span class="ab-toggle__track"></span><span class="ab-toggle__thumb"></span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php $settings->card_close(); ?>

                        <?php // -- Card: Custom Widgets -------------------------------- ?>
                        <?php
                        $custom_widgets = Admbud\Dashboard::get_custom_widgets();
                        $settings->card_open_svg(
                            '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>',
                            __( 'Custom Widgets', 'admin-buddy' ),
                            __( 'Add your own dashboard widgets. Supports HTML and shortcodes.', 'admin-buddy' )
                        );
                        ?>
                        <div class="ab-custom-widgets-list" id="ab-custom-widgets-list">
                            <?php foreach ( $custom_widgets as $idx => $cw ) : ?>
                                <div class="ab-custom-widget-item" data-index="<?php echo esc_attr( $idx ); ?>">
                                    <div class="ab-custom-widget-fields">
                                        <input type="text" class="ab-cw-title regular-text"
                                               aria-label="<?php esc_attr_e( 'Widget title', 'admin-buddy' ); ?>"
                                               placeholder="<?php esc_attr_e( 'Widget title', 'admin-buddy' ); ?>"
                                               value="<?php echo esc_attr( $cw['title'] ?? '' ); ?>">
                                        <textarea class="ab-cw-content large-text" rows="3"
                                                  aria-label="<?php esc_attr_e( 'Widget content', 'admin-buddy' ); ?>"
                                                  placeholder="<?php esc_attr_e( 'Widget content (HTML & shortcodes supported)', 'admin-buddy' ); ?>"><?php echo esc_textarea( $cw['content'] ?? '' ); ?></textarea>
                                    </div>
                                    <button type="button" class="ab-btn ab-btn--ghost ab-cw-remove" title="<?php esc_attr_e( 'Remove widget', 'admin-buddy' ); ?>">
                                        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="ab-btn ab-btn--secondary" id="ab-cw-add-widget" style="margin-top:10px;">
                            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            <?php esc_html_e( 'Add Widget', 'admin-buddy' ); ?>
                        </button>
                        <?php $settings->card_close(); ?>

                        <?php else : ?>
                        <div class="ab-notice ab-notice--info ab-m-0" style="margin-bottom:16px;">
                            <?php esc_html_e( 'Widget Visibility and Custom Widgets are unavailable while a Custom Dashboard Page is active. The custom page replaces the widget area entirely.', 'admin-buddy' ); ?>
                        </div>
                        <?php endif; ?>
                    </div><!-- /.ab-pane (dashboard) -->

                    <button type="submit" class="ab-form-save-btn" style="display:none;" aria-hidden="true"></button>
                </div><!-- /.ab-subtab-main -->
            </div><!-- /.ab-subtab-layout -->
        </form>
        <?php

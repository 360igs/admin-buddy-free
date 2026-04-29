<?php
/**
 * Colours tab UI - Admin colour scheme, presets, and live preview.
 * Included by Settings::render_tab_colours().
 * $settings is the Settings singleton - use $settings->card_open() etc.
 *
 * @package Admbud
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Variables scoped to this included template file.

use Admbud\Colours;


/**
 * Ensure a colour option value is valid for type="color" inputs.
 * Returns the saved value if non-empty, otherwise the fallback.
 * Prevents browser warnings about empty values on <input type="color">.
 */
function admbud_colour_val( string $option, string $fallback = '#000000' ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- admbud_ is the plugin prefix.
    $val = get_option( $option, '' );
    return ( $val !== '' && preg_match( '/^#[0-9a-fA-F]{6}$/', $val ) ) ? $val : $fallback;
}

/** @var \Admbud\Settings $settings */
        $primary   = esc_attr( admbud_get_option( 'admbud_colours_primary',   Colours::DEFAULT_PRIMARY   ) );
        $secondary = esc_attr( admbud_get_option( 'admbud_colours_secondary', Colours::DEFAULT_SECONDARY ) );
        $menu_text = esc_attr( admbud_get_option( 'admbud_colours_menu_text', Colours::DEFAULT_MENU_TEXT ) );
        $menu_bg   = esc_attr( admbud_get_option( 'admbud_colours_menu_bg',   Colours::DEFAULT_MENU_BG   ) );

        $text_hint_primary   = Colours::suggest_text_colour( admbud_get_option( 'admbud_colours_primary',   Colours::DEFAULT_PRIMARY ) );
        $text_hint_menu_bg   = Colours::suggest_text_colour( admbud_get_option( 'admbud_colours_menu_bg',   Colours::DEFAULT_MENU_BG ) );
        $contrast_menu       = Colours::contrast_ratio(
            admbud_get_option( 'admbud_colours_menu_text', Colours::DEFAULT_MENU_TEXT ),
            admbud_get_option( 'admbud_colours_menu_bg',   Colours::DEFAULT_MENU_BG   )
        );
        $contrast_ok         = $contrast_menu >= 4.5;

        $sidebar_gradient = admbud_get_option( 'admbud_colours_sidebar_gradient', '0' ) === '1';
        $menu_item_sep    = admbud_get_option( 'admbud_colours_menu_item_sep',     '1' ) === '1';
        $grad_dir         = admbud_get_option( 'admbud_colours_sidebar_grad_dir', Colours::DEFAULT_SIDEBAR_GRAD_DIR );
        $grad_from        = esc_attr( admbud_get_option( 'admbud_colours_sidebar_grad_from', Colours::DEFAULT_SIDEBAR_GRAD_FROM ) );
        $grad_to          = esc_attr( admbud_get_option( 'admbud_colours_sidebar_grad_to',   Colours::DEFAULT_SIDEBAR_GRAD_TO   ) );
        $grad_hidden      = $sidebar_gradient ? '' : 'ab-hidden';

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

        $effective_menu_text   = admbud_get_option( 'admbud_colours_menu_text',   Colours::DEFAULT_MENU_TEXT   );
        $effective_active_text = admbud_get_option( 'admbud_colours_active_text', Colours::DEFAULT_ACTIVE_TEXT );
        $submenu_text_val         = admbud_get_option( 'admbud_colours_submenu_text',        '' ) ?: $effective_menu_text;
        $hover_text_val           = admbud_get_option( 'admbud_colours_hover_text',          '' ) ?: $effective_menu_text;
        $act_parent_val           = admbud_get_option( 'admbud_colours_active_parent_text',  '' ) ?: $effective_active_text;
        $submenu_bg_val           = admbud_get_option( 'admbud_colours_submenu_bg',          '' ) ?: Colours::DEFAULT_SUBMENU_BG;
        $submenu_hover_bg_val     = admbud_get_option( 'admbud_colours_submenu_hover_bg',    '' ) ?: Colours::DEFAULT_SUBMENU_HOVER_BG;
        $submenu_hover_text_val   = admbud_get_option( 'admbud_colours_submenu_hover_text',  '' ) ?: Colours::DEFAULT_SUBMENU_HOVER_TEXT;
        $active_subtab = \Admbud\Settings::get_active_subtab( 'colours', 'presets' );
        $active_subtab = in_array( $active_subtab, [ 'presets', 'accent', 'auto-palette', 'menu', 'adminbar', 'content', 'exclusions' ], true ) ? $active_subtab : 'presets';
        ?>
        <div class="ab-colours-main">

                    <div class="ab-subnav" data-subtab-field="#ab-colours-subtab-field">
                        <button type="button" class="ab-subnav__item<?php echo $active_subtab === 'presets' ? ' is-active' : ''; ?>" data-panel="presets">
                            <?php esc_html_e( 'Presets', 'admin-buddy' ); ?>
                        </button>
                        <button type="button" class="ab-subnav__item<?php echo $active_subtab === 'accent'   ? ' is-active' : ''; ?>" data-panel="accent">
                            <?php esc_html_e( 'Accent', 'admin-buddy' ); ?>
                        </button>
                        <button type="button" class="ab-subnav__item<?php echo $active_subtab === 'menu'     ? ' is-active' : ''; ?>" data-panel="menu">
                            <?php esc_html_e( 'Admin Menu', 'admin-buddy' ); ?>
                        </button>
                        <button type="button" class="ab-subnav__item<?php echo $active_subtab === 'adminbar' ? ' is-active' : ''; ?>" data-panel="adminbar">
                            <?php esc_html_e( 'Admin Bar', 'admin-buddy' ); ?>
                        </button>
                        <button type="button" class="ab-subnav__item<?php echo $active_subtab === 'content' ? ' is-active' : ''; ?>" data-panel="content">
                            <?php esc_html_e( 'Content', 'admin-buddy' ); ?>
                        </button>
                        <button type="button" class="ab-subnav__item<?php echo $active_subtab === 'exclusions' ? ' is-active' : ''; ?>" data-panel="exclusions">
                            <?php esc_html_e( 'Exclusions', 'admin-buddy' ); ?>
                        </button>
                    </div>

        <div id="ab-colours-form-wrap"<?php echo $active_subtab === 'exclusions' ? ' class="ab-hidden"' : ''; ?>>
        <form method="post" action="options.php">
            <?php settings_fields( 'admbud_colours_group' ); ?>
            <input type="hidden" name="admbud_tab" value="colours">
            <input type="hidden" name="admbud_subtab" id="ab-colours-subtab-field" value="<?php echo esc_attr( $active_subtab ); ?>">

            <div class="ab-colours-layout" id="ab-colours-grid">

                <!-- -- Left column: colour panes --------------------------- -->
                <div class="ab-colours-left">

                    <!-- -- Panel: Accent ----------------------------------- -->
                    <div class="ab-pane<?php echo $active_subtab !== 'accent' ? ' ab-hidden' : ''; ?>" id="ab-pane-accent">
                        <?php $settings->card_open_svg(
                            '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 3"/></svg>',
                            __( 'Accent Colours', 'admin-buddy' ),
                            __( 'Used for buttons, links, focus rings, and active states throughout the admin.', 'admin-buddy' )
                        ); ?>
                        <table class="form-table ab-form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="admbud_colours_primary"><?php esc_html_e( 'Primary', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_primary" name="admbud_colours_primary"
                                           value="<?php echo esc_attr( $primary ); ?>" class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::DEFAULT_PRIMARY ); ?>">
                                    <p class="description"><?php esc_html_e( 'Main accent: buttons, active menu items, links.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="admbud_colours_secondary"><?php esc_html_e( 'Secondary', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_secondary" name="admbud_colours_secondary"
                                           value="<?php echo esc_attr( $secondary ); ?>" class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::DEFAULT_SECONDARY ); ?>">
                                    <p class="description"><?php esc_html_e( 'Controls hover backgrounds on sidebar menu items, admin bar, and buttons.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                        </table>
                        <?php $settings->card_close(); ?>
                    </div>
                    <div class="ab-pane<?php echo $active_subtab !== 'menu' ? ' ab-hidden' : ''; ?>" id="ab-pane-menu">
                        <?php $settings->card_open_svg(
                            '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>',
                            __( 'Admin Menu', 'admin-buddy' ),
                            __( 'Sidebar background, text colours, and optional gradient.', 'admin-buddy' )
                        ); ?>
                        <table class="form-table ab-form-table" role="presentation">
                            <tr class="ab-form-table__section-heading">
                                <th colspan="2" style="padding:var(--ab-space-3) 0 var(--ab-space-1);border-bottom:1px solid var(--ab-neutral-200);">
                                    <span style="font-size:var(--ab-text-xs);font-weight:var(--ab-font-semibold);text-transform:uppercase;letter-spacing:0.05em;color:var(--ab-text-secondary);"><?php esc_html_e( 'Menu', 'admin-buddy' ); ?></span>
                                </th>
                            </tr>
                            <tr>
                                <th scope="row"><label for="admbud_colours_menu_bg"><?php esc_html_e( 'Sidebar Background', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_menu_bg" name="admbud_colours_menu_bg"
                                           value="<?php echo esc_attr( $menu_bg ); ?>" class="ab-native-color-picker ab-colour-menu-bg"
                                           data-default-color="<?php echo esc_attr( Colours::DEFAULT_MENU_BG ); ?>">
                                    <p class="description">
                                        <?php esc_html_e( 'Auto-suggest: ', 'admin-buddy' ); ?>
                                        <a href="#" class="ab-contrast-suggest" data-target="admbud_colours_menu_text" data-colour="<?php echo esc_attr( $text_hint_menu_bg ); ?>">
                                            <?php /* translators: %s: dynamic value */ ?>
                                            <?php printf( esc_html__( 'use %s text for best contrast', 'admin-buddy' ), '<strong id="ab-hint-menu-text">' . esc_html( $text_hint_menu_bg ) . '</strong>' ); ?>
                                        </a>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="admbud_colours_menu_text"><?php esc_html_e( 'Menu Text', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_menu_text" name="admbud_colours_menu_text"
                                           value="<?php echo esc_attr( $menu_text ); ?>" class="ab-native-color-picker ab-colour-menu-text"
                                           data-default-color="<?php echo esc_attr( Colours::DEFAULT_MENU_TEXT ); ?>">
                                    <div class="ab-contrast-badge <?php echo esc_attr( $contrast_ok ? 'ab-contrast-badge--ok' : 'ab-contrast-badge--warn' ); ?>" id="ab-contrast-badge">
                                        <?php if ( $contrast_ok ) : ?>
                                            <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                            <?php /* translators: %s: dynamic value */ ?>
                                            <?php printf( esc_html__( 'Good contrast (%s:1)', 'admin-buddy' ), '<span id="ab-contrast-ratio">' . esc_html( $contrast_menu ) . '</span>' ); ?>
                                        <?php else : ?>
                                            <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                            <?php /* translators: %s: dynamic value */ ?>
                                            <?php printf( esc_html__( 'Low contrast (%s:1). WCAG AA needs 4.5+', 'admin-buddy' ), '<span id="ab-contrast-ratio">' . esc_html( $contrast_menu ) . '</span>' ); ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="admbud_colours_hover_bg"><?php esc_html_e( 'Menu Hover Background', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_hover_bg" name="admbud_colours_hover_bg"
                                           value="<?php echo esc_attr( admbud_get_option( 'admbud_colours_hover_bg', '' ) ?: Colours::DEFAULT_HOVER_BG ); ?>" class="ab-native-color-picker"
                                           data-default-color="<?php echo esc_attr( Colours::DEFAULT_HOVER_BG ); ?>">
                                    <p class="description"><?php esc_html_e( 'Background of menu items on hover. Defaults to Secondary colour.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="admbud_colours_hover_text"><?php esc_html_e( 'Menu Hover Text', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_hover_text" name="admbud_colours_hover_text"
                                           value="<?php echo esc_attr( $hover_text_val ); ?>" class="ab-native-color-picker"
                                           data-default-color="<?php echo esc_attr( $effective_menu_text ); ?>">
                                    <p class="description"><?php esc_html_e( 'Text for top-level items on hover. Defaults to Menu Text.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="admbud_colours_active_bg"><?php esc_html_e( 'Active Menu Background', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_active_bg" name="admbud_colours_active_bg"
                                           value="<?php echo esc_attr( admbud_get_option( 'admbud_colours_active_bg', '' ) ?: Colours::DEFAULT_ACTIVE_BG ); ?>" class="ab-native-color-picker"
                                           data-default-color="<?php echo esc_attr( Colours::DEFAULT_ACTIVE_BG ); ?>">
                                    <p class="description"><?php esc_html_e( 'Background of the currently active menu item. Defaults to Primary colour.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="admbud_colours_active_text"><?php esc_html_e( 'Active Menu Text', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_active_text" name="admbud_colours_active_text"
                                           value="<?php echo esc_attr( admbud_get_option( 'admbud_colours_active_text', Colours::DEFAULT_ACTIVE_TEXT ) ); ?>"
                                           class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::DEFAULT_ACTIVE_TEXT ); ?>">
                                    <p class="description"><?php esc_html_e( 'Text for the currently active/selected menu item.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="admbud_colours_active_parent_text"><?php esc_html_e( 'Active Parent Text', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_active_parent_text" name="admbud_colours_active_parent_text"
                                           value="<?php echo esc_attr( $act_parent_val ); ?>" class="ab-native-color-picker"
                                           data-default-color="<?php echo esc_attr( $effective_active_text ); ?>">
                                    <p class="description"><?php esc_html_e( 'Text for the parent item whose submenu is open. Defaults to Active Menu Text.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                            <tr class="ab-form-table__section-heading">
                                <th colspan="2" style="padding:var(--ab-space-5) 0 var(--ab-space-1);border-bottom:1px solid var(--ab-neutral-200);">
                                    <span style="font-size:var(--ab-text-xs);font-weight:var(--ab-font-semibold);text-transform:uppercase;letter-spacing:0.05em;color:var(--ab-text-secondary);"><?php esc_html_e( 'Submenu & Flyout', 'admin-buddy' ); ?></span>
                                </th>
                            </tr>
                            <tr>
                                <th scope="row"><label for="admbud_colours_submenu_bg"><?php esc_html_e( 'Submenu and Flyout Background', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_submenu_bg" name="admbud_colours_submenu_bg"
                                           value="<?php echo esc_attr( $submenu_bg_val ); ?>" class="ab-native-color-picker"
                                           data-default-color="<?php echo esc_attr( Colours::DEFAULT_PRIMARY ); ?>">
                                    <p class="description"><?php esc_html_e( 'Background for expanded submenus and flyout panels. Defaults to Primary colour.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="admbud_colours_submenu_text"><?php esc_html_e( 'Submenu and Flyout Text', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_submenu_text" name="admbud_colours_submenu_text"
                                           value="<?php echo esc_attr( $submenu_text_val ); ?>" class="ab-native-color-picker"
                                           data-default-color="<?php echo esc_attr( $effective_menu_text ); ?>">
                                    <p class="description"><?php esc_html_e( 'Text for submenu and flyout items. Defaults to Menu Text.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="admbud_colours_submenu_hover_bg"><?php esc_html_e( 'Submenu and Flyout Hover Background', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_submenu_hover_bg" name="admbud_colours_submenu_hover_bg"
                                           value="<?php echo esc_attr( $submenu_hover_bg_val ); ?>" class="ab-native-color-picker"
                                           data-default-color="<?php echo esc_attr( Colours::DEFAULT_SECONDARY ); ?>">
                                    <p class="description"><?php esc_html_e( 'Submenu item background on hover. Defaults to Secondary colour.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="admbud_colours_submenu_hover_text"><?php esc_html_e( 'Submenu and Flyout Hover Text', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_submenu_hover_text" name="admbud_colours_submenu_hover_text"
                                           value="<?php echo esc_attr( $submenu_hover_text_val ); ?>" class="ab-native-color-picker"
                                           data-default-color="<?php echo esc_attr( $effective_menu_text ); ?>">
                                    <p class="description"><?php esc_html_e( 'Submenu item text on hover. Defaults to Menu Text.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="admbud_colours_submenu_active_bg"><?php esc_html_e( 'Submenu and Flyout Active Background', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_submenu_active_bg" name="admbud_colours_submenu_active_bg"
                                           value="<?php echo esc_attr( admbud_get_option( 'admbud_colours_submenu_active_bg', '' ) ?: Colours::DEFAULT_SUBMENU_ACTIVE_BG ); ?>" class="ab-native-color-picker"
                                           data-default-color="<?php echo esc_attr( Colours::DEFAULT_SUBMENU_ACTIVE_BG ); ?>">
                                    <p class="description"><?php esc_html_e( 'Submenu item background for the current page. Defaults to Submenu Hover Background.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="admbud_colours_submenu_active_text"><?php esc_html_e( 'Submenu and Flyout Active Text', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_submenu_active_text" name="admbud_colours_submenu_active_text"
                                           value="<?php echo esc_attr( admbud_get_option( 'admbud_colours_submenu_active_text', '' ) ?: Colours::DEFAULT_SUBMENU_ACTIVE_TEXT ); ?>" class="ab-native-color-picker"
                                           data-default-color="<?php echo esc_attr( Colours::DEFAULT_SUBMENU_ACTIVE_TEXT ); ?>">
                                    <p class="description"><?php esc_html_e( 'Submenu item text for the current page. Defaults to Submenu Hover Text.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                            <tr class="ab-form-table__section-heading">
                                <th colspan="2" style="padding:var(--ab-space-5) 0 var(--ab-space-1);border-bottom:1px solid var(--ab-neutral-200);">
                                    <span style="font-size:var(--ab-text-xs);font-weight:var(--ab-font-semibold);text-transform:uppercase;letter-spacing:0.05em;color:var(--ab-text-secondary);"><?php esc_html_e( 'Sidebar Options', 'admin-buddy' ); ?></span>
                                </th>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Sidebar Gradient', 'admin-buddy' ); ?></th>
                                <td>
                                    <label class="ab-toggle">
                                        <input type="hidden"   name="admbud_colours_sidebar_gradient" value="0">
                                        <input type="checkbox" name="admbud_colours_sidebar_gradient" value="1"
                                               id="admbud_colours_sidebar_gradient" <?php checked( $sidebar_gradient ); ?>>
                                        <span class="ab-toggle__track"></span><span class="ab-toggle__thumb"></span>
                                    </label>
                                    <p class="description"><?php esc_html_e( 'Replace solid background with a top-to-bottom gradient.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>

                            <tr class="ab-sidebar-grad-fields <?php echo esc_attr( $grad_hidden ); ?>">
                                <th scope="row"><label for="admbud_colours_sidebar_grad_from"><?php esc_html_e( 'Gradient From (top)', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_sidebar_grad_from" name="admbud_colours_sidebar_grad_from"
                                           value="<?php echo esc_attr( $grad_from ); ?>" class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::DEFAULT_SIDEBAR_GRAD_FROM ); ?>">
                                </td>
                            </tr>
                            <tr class="ab-sidebar-grad-fields <?php echo esc_attr( $grad_hidden ); ?>">
                                <th scope="row"><label for="admbud_colours_sidebar_grad_to"><?php esc_html_e( 'Gradient To (bottom)', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_sidebar_grad_to" name="admbud_colours_sidebar_grad_to"
                                           value="<?php echo esc_attr( $grad_to ); ?>" class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::DEFAULT_SIDEBAR_GRAD_TO ); ?>">
                                </td>
                            </tr>
                            <tr class="ab-sidebar-grad-fields <?php echo esc_attr( $grad_hidden ); ?>">
                                <th scope="row"><?php esc_html_e( 'Direction', 'admin-buddy' ); ?></th>
                                <td>
                                    <div class="ab-direction-grid">
                                        <?php foreach ( $grid_order as $dir ) :
                                            if ( $dir === null ) : ?>
                                                <span class="ab-direction-grid__center">·</span>
                                            <?php else : ?>
                                                <label class="ab-direction-grid__cell <?php echo $grad_dir === $dir ? 'ab-direction-grid__cell--active' : ''; ?>" title="<?php echo esc_attr( $dir ); ?>">
                                                    <input type="radio" name="admbud_colours_sidebar_grad_dir" value="<?php echo esc_attr( $dir ); ?>" <?php checked( $grad_dir, $dir ); ?>>
                                                    <?php echo esc_html( $directions[ $dir ] ); ?>
                                                </label>
                                            <?php endif;
                                        endforeach; ?>
                                    </div>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><?php esc_html_e( 'Menu Item Separator', 'admin-buddy' ); ?></th>
                                <td>
                                    <label class="ab-toggle">
                                        <input type="hidden"   name="admbud_colours_menu_item_sep" value="0">
                                        <input type="checkbox" name="admbud_colours_menu_item_sep" value="1"
                                               id="admbud_colours_menu_item_sep" <?php checked( $menu_item_sep ); ?>>
                                        <span class="ab-toggle__track"></span><span class="ab-toggle__thumb"></span>
                                    </label>
                                    <p class="description"><?php esc_html_e( 'Show a subtle bottom border line under each sidebar menu item.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                            <?php $sep_color_val = esc_attr( admbud_colour_val( 'admbud_colours_sep_color', Colours::DEFAULT_PRIMARY ) ); ?>
                            <tr id="ab-sep-color-row"<?php echo $menu_item_sep ? '' : ' style="display:none"'; ?>>
                                <th scope="row"><label for="admbud_colours_sep_color"><?php esc_html_e( 'Separator Colour', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_sep_color" name="admbud_colours_sep_color"
                                           value="<?php echo esc_attr( $sep_color_val ); ?>" class="ab-native-color-picker"
                                           data-default-color="<?php echo esc_attr( Colours::DEFAULT_PRIMARY ); ?>">
                                    <p class="description"><?php esc_html_e( 'Leave blank to auto-derive from Primary.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                        </table>
                        <?php $settings->card_close(); ?>
                    </div>

                    <!-- -- Panel: Admin Bar -------------------------------- -->
                    <div class="ab-pane<?php echo $active_subtab !== 'adminbar' ? ' ab-hidden' : ''; ?>" id="ab-pane-adminbar">
                        <?php $settings->card_open_svg(
                            '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="2" y="3" width="20" height="5" rx="1"/><line x1="2" y1="8" x2="22" y2="8"/><path d="M6 5.5h.01M9 5.5h.01"/></svg>',
                            __( 'Admin Bar', 'admin-buddy' ),
                            __( 'Top admin bar colours. Applies on backend and frontend. Leave any blank to use its noted default.', 'admin-buddy' )
                        ); ?>
                        <table class="form-table ab-form-table" role="presentation">
                            <tr class="ab-form-table__section-heading">
                                <th colspan="2" style="padding:var(--ab-space-3) 0 var(--ab-space-1);border-bottom:1px solid var(--ab-neutral-200);">
                                    <span style="font-size:var(--ab-text-xs);font-weight:var(--ab-font-semibold);text-transform:uppercase;letter-spacing:0.05em;color:var(--ab-text-secondary);"><?php esc_html_e( 'Bar', 'admin-buddy' ); ?></span>
                                </th>
                            </tr>
                            <tr>
                                <th scope="row"><label for="admbud_colours_adminbar_bg"><?php esc_html_e( 'Bar Background', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_adminbar_bg" name="admbud_colours_adminbar_bg"
                                           value="<?php echo esc_attr( admbud_get_option( 'admbud_colours_adminbar_bg', '' ) ); ?>"
                                           class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::DEFAULT_MENU_BG ); ?>">
                                    <p class="description"><?php esc_html_e( 'Background of the admin bar. Leave blank to inherit the sidebar colour.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="admbud_colours_adminbar_text"><?php esc_html_e( 'Bar Text', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_adminbar_text" name="admbud_colours_adminbar_text"
                                           value="<?php echo esc_attr( admbud_get_option( 'admbud_colours_adminbar_text', '' ) ); ?>"
                                           class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::DEFAULT_MENU_TEXT ); ?>">
                                    <p class="description"><?php esc_html_e( 'Default text and icon colour. Leave blank to auto-derive from Menu Text.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="admbud_colours_adminbar_hover_bg"><?php esc_html_e( 'Hover Background', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_adminbar_hover_bg" name="admbud_colours_adminbar_hover_bg"
                                           value="<?php echo esc_attr( admbud_get_option( 'admbud_colours_adminbar_hover_bg', '' ) ); ?>"
                                           class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::DEFAULT_PRIMARY ); ?>">
                                    <p class="description"><?php esc_html_e( 'Background of top-level items on hover. Leave blank to use Primary.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="admbud_colours_adminbar_hover_text"><?php esc_html_e( 'Hover Text', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_adminbar_hover_text" name="admbud_colours_adminbar_hover_text"
                                           value="<?php echo esc_attr( admbud_get_option( 'admbud_colours_adminbar_hover_text', '' ) ); ?>"
                                           class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::DEFAULT_MENU_TEXT ); ?>">
                                    <p class="description"><?php esc_html_e( 'Text and icon colour on hover. Leave blank to use Menu Text.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                            <tr class="ab-form-table__section-heading">
                                <th colspan="2" style="padding:var(--ab-space-5) 0 var(--ab-space-1);border-bottom:1px solid var(--ab-neutral-200);">
                                    <span style="font-size:var(--ab-text-xs);font-weight:var(--ab-font-semibold);text-transform:uppercase;letter-spacing:0.05em;color:var(--ab-text-secondary);"><?php esc_html_e( 'Flyout', 'admin-buddy' ); ?></span>
                                </th>
                            </tr>
                            <tr>
                                <th scope="row"><label for="admbud_colours_adminbar_submenu_bg"><?php esc_html_e( 'Flyout Background', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_adminbar_submenu_bg" name="admbud_colours_adminbar_submenu_bg"
                                           value="<?php echo esc_attr( admbud_get_option( 'admbud_colours_adminbar_submenu_bg', '' ) ); ?>"
                                           class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::DEFAULT_MENU_BG ); ?>">
                                    <p class="description"><?php esc_html_e( 'Background of flyout/dropdown panels. Leave blank to inherit sidebar colour.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="admbud_colours_adminbar_sub_text"><?php esc_html_e( 'Flyout Text', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_adminbar_sub_text" name="admbud_colours_adminbar_sub_text"
                                           value="<?php echo esc_attr( admbud_get_option( 'admbud_colours_adminbar_sub_text', '' ) ); ?>"
                                           class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::DEFAULT_MENU_TEXT ); ?>">
                                    <p class="description"><?php esc_html_e( 'Text inside flyout panels. Leave blank to auto-derive at 75% opacity.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="admbud_colours_adminbar_sub_hover_bg"><?php esc_html_e( 'Flyout Background Hover', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_adminbar_sub_hover_bg" name="admbud_colours_adminbar_sub_hover_bg"
                                           value="<?php echo esc_attr( admbud_get_option( 'admbud_colours_adminbar_sub_hover_bg', '' ) ); ?>"
                                           class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::DEFAULT_PRIMARY ); ?>">
                                    <p class="description"><?php esc_html_e( 'Flyout item background on hover. Leave blank to use primary colour.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="admbud_colours_adminbar_sub_hover_text"><?php esc_html_e( 'Flyout Text Hover', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_adminbar_sub_hover_text" name="admbud_colours_adminbar_sub_hover_text"
                                           value="<?php echo esc_attr( admbud_get_option( 'admbud_colours_adminbar_sub_hover_text', '' ) ); ?>"
                                           class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::DEFAULT_MENU_TEXT ); ?>">
                                    <p class="description"><?php esc_html_e( 'Flyout item text on hover. Leave blank to use Menu Text.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                        </table>
                        <?php $settings->card_close(); ?>
                    </div>

                    <!-- -- Panel: Others ----------------------------------- -->
                    <div class="ab-pane<?php echo $active_subtab !== 'content' ? ' ab-hidden' : ''; ?>" id="ab-pane-content">

                        <?php /* -- Page ------------------------------------------- */ ?>
                        <?php $settings->card_open_svg(
                            '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>',
                            __( 'Page', 'admin-buddy' ),
                            __( 'Content area background, headings, body text and links.', 'admin-buddy' )
                        ); ?>
                        <table class="form-table ab-form-table" role="presentation">
                            <?php
                            $settings->colour_field( 'admbud_colours_body_bg',             __( 'Content Background', 'admin-buddy' ), __( 'Main admin content area background. Leave blank for WP default.', 'admin-buddy' ),           '#f0f0f1' );
                            $settings->colour_field( 'admbud_colours_content_heading',     __( 'Heading Text', 'admin-buddy' ),       __( 'h1–h4 colour in the content area. Leave blank for WP default.', 'admin-buddy' ),            '#1d2327' );
                            $settings->colour_field( 'admbud_colours_content_text',        __( 'Body Text', 'admin-buddy' ),          __( 'Paragraph and label text. Leave blank for WP default.', 'admin-buddy' ),                    '#3c434a' );
                            $settings->colour_field( 'admbud_colours_content_link',        __( 'Link Colour', 'admin-buddy' ),        __( 'Link colour. Leave blank to use your Primary colour.', 'admin-buddy' ),                     '#7c3aed' );
                            $settings->colour_field( 'admbud_colours_content_link_hover',  __( 'Link Hover', 'admin-buddy' ),         __( 'Link hover colour. Leave blank to use your Secondary colour.', 'admin-buddy' ),             '#6d28d9' );
                            $settings->colour_field( 'admbud_colours_shadow_colour',       __( 'Drop Shadow', 'admin-buddy' ),        __( 'Shadow colour for flyout panels and dropdowns. Leave blank for default.', 'admin-buddy' ),  '#000000' );
                            ?>
                        </table>
                        <?php $settings->card_close(); ?>

                        <?php /* -- Tables ------------------------------------------- */ ?>
                        <?php $settings->card_open_svg(
                            '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="9" x2="9" y2="21"/></svg>',
                            __( 'Tables', 'admin-buddy' ),
                            __( 'List tables used across Posts, Pages, Plugins, Users and more.', 'admin-buddy' )
                        ); ?>
                        <table class="form-table ab-form-table" role="presentation">
                            <?php
                            $settings->colour_field( 'admbud_colours_table_header_bg',     __( 'Header Background', 'admin-buddy' ),          '', '#f4effd' );
                            $settings->colour_field( 'admbud_colours_table_header_text',   __( 'Header Text', 'admin-buddy' ),                '', '#1d2327' );
                            $settings->colour_field( 'admbud_colours_table_header_link',   __( 'Header Links', 'admin-buddy' ),               __( 'Clickable column headings (Title, Author, Date).', 'admin-buddy' ), '#1d2327' );
                            $settings->colour_field( 'admbud_colours_table_row_bg',        __( 'Row Background', 'admin-buddy' ),             '', '#ffffff' );
                            $settings->colour_field( 'admbud_colours_table_row_text',      __( 'Row Text', 'admin-buddy' ),                   '', '#3c434a' );
                            $settings->colour_field( 'admbud_colours_table_row_alt_bg',    __( 'Alternating Row Background', 'admin-buddy' ), __( 'Auto-derived from row background if left blank.', 'admin-buddy' ), '#f9f7fe' );
                            $settings->colour_field( 'admbud_colours_table_row_alt_text',  __( 'Alternating Row Text', 'admin-buddy' ),       '', '#3c434a' );
                            $settings->colour_field( 'admbud_colours_table_row_hover',     __( 'Row Hover', 'admin-buddy' ),                  '', '#efe7fc' );
                            $settings->colour_field( 'admbud_colours_table_border',        __( 'Cell Border', 'admin-buddy' ),                __( 'Outer cell borders.', 'admin-buddy' ), '#decdfa' );
                            $settings->colour_field( 'admbud_colours_table_row_separator', __( 'Row Separator', 'admin-buddy' ),              __( 'Horizontal line between rows.', 'admin-buddy' ), '#ebe1fc' );
                            $settings->colour_field( 'admbud_colours_table_title_link',    __( 'Title Links', 'admin-buddy' ),                __( 'Post titles, plugin names, user names.', 'admin-buddy' ), '#7c3aed' );
                            $settings->colour_field( 'admbud_colours_table_action_link',   __( 'Action Links', 'admin-buddy' ),               __( 'Edit / Trash / View links under each row.', 'admin-buddy' ), '#7c3aed' );
                            ?>
                        </table>
                        <?php $settings->card_close(); ?>

                        <?php /* -- Forms ------------------------------------------- */ ?>
                        <?php $settings->card_open_svg(
                            '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="3" y="5" width="18" height="4" rx="1"/><rect x="3" y="13" width="11" height="4" rx="1"/><line x1="17" y1="15" x2="21" y2="15"/></svg>',
                            __( 'Forms', 'admin-buddy' ),
                            __( 'Input fields, focus rings and secondary buttons. Scoped to the content area only.', 'admin-buddy' )
                        ); ?>
                        <table class="form-table ab-form-table" role="presentation">
                            <?php
                            $settings->colour_field( 'admbud_colours_input_bg',            __( 'Input Background', 'admin-buddy' ),            '', '#ffffff' );
                            $settings->colour_field( 'admbud_colours_input_border',        __( 'Input Border', 'admin-buddy' ),                '', '#d1baf8' );
                            $settings->colour_field( 'admbud_colours_input_focus',         __( 'Focus Ring', 'admin-buddy' ),                  __( 'Border and box-shadow on focused inputs. Leave blank to use Primary.', 'admin-buddy' ), '#2271b1' );
                            $settings->colour_field( 'admbud_colours_btn_primary_bg',      __( 'Primary Button Background', 'admin-buddy' ),   __( 'Leave blank to use your Primary colour.', 'admin-buddy' ), '#7c3aed' );
                            $settings->colour_field( 'admbud_colours_btn_primary_text',    __( 'Primary Button Text', 'admin-buddy' ),         '', '#ffffff' );
                            $settings->colour_field( 'admbud_colours_btn_primary_hover',   __( 'Primary Button Hover', 'admin-buddy' ),        __( 'Leave blank to use your Secondary colour.', 'admin-buddy' ), '#6d28d9' );
                            $settings->colour_field( 'admbud_colours_btn_secondary_bg',    __( 'Secondary Button Background', 'admin-buddy' ), __( 'WP .button-secondary background.', 'admin-buddy' ), '#f7f3fd' );
                            ?>
                        </table>
                        <?php $settings->card_close(); ?>

                        <?php /* -- Cards ------------------------------------------- */ ?>
                        <?php $settings->card_open_svg(
                            '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="2" y="4" width="20" height="16" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>',
                            __( 'Cards', 'admin-buddy' ),
                            __( 'Postbox / meta box cards used on the Dashboard and edit screens.', 'admin-buddy' )
                        ); ?>
                        <table class="form-table ab-form-table" role="presentation">
                            <?php
                            $settings->colour_field( 'admbud_colours_postbox_bg',          __( 'Card Background', 'admin-buddy' ), '', '#ffffff' );
                            $settings->colour_field( 'admbud_colours_postbox_header_bg',   __( 'Card Header', 'admin-buddy' ),     '', '#f9f7fe' );
                            $settings->colour_field( 'admbud_colours_postbox_border',      __( 'Card Border', 'admin-buddy' ),     '', '#decdfa' );
                            $settings->colour_field( 'admbud_colours_postbox_text',        __( 'Card Text', 'admin-buddy' ),       __( 'Text colour inside cards. Leave blank to use Body Text.', 'admin-buddy' ), '#3c434a' );
                            ?>
                        </table>
                        <?php $settings->card_close(); ?>

                        <?php /* -- Notices -------------------------------------------- */ ?>
                        <?php $settings->card_open_svg(
                            '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
                            __( 'Notices', 'admin-buddy' ),
                            __( 'Admin notice background. Semantic border colours (info/success/warning/error) are preserved.', 'admin-buddy' )
                        ); ?>
                        <table class="form-table ab-form-table" role="presentation">
                            <?php
                            $settings->colour_field( 'admbud_colours_notice_bg', __( 'Notice Background', 'admin-buddy' ), __( 'Background for all admin notices. Leave blank for WP default white.', 'admin-buddy' ), '#fbf9fe' );
                            ?>
                        </table>
                        <?php $settings->card_close(); ?>

                        <?php /* -- Status Pills --------------------------------------- */ ?>
                        <?php $settings->card_open_svg(
                            '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="3" y="3" width="7" height="18" rx="1"/><rect x="14" y="3" width="7" height="10" rx="1"/><rect x="14" y="17" width="7" height="4" rx="1"/></svg>',
                            __( 'Status Pills', 'admin-buddy' ),
                            __( 'Admin bar indicator pill colours for Maintenance, Coming Soon and Search Blocked states.', 'admin-buddy' )
                        ); ?>
                        <table class="form-table ab-form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="admbud_colours_pill_admin_buddy"><?php esc_html_e( 'Admin Buddy Pill', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_pill_admin_buddy" name="admbud_colours_pill_admin_buddy"
                                           value="<?php echo esc_attr( admbud_colour_val( 'admbud_colours_pill_admin_buddy', Colours::DEFAULT_PRIMARY ) ); ?>"
                                           class="ab-native-color-picker" data-default-color="#7c3aed">
                                    <p class="description"><?php esc_html_e( 'Admin Buddy quick-access pill in the admin bar. Leave blank to use Primary colour.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="admbud_colours_pill_maintenance"><?php esc_html_e( 'Maintenance Pill', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_pill_maintenance" name="admbud_colours_pill_maintenance"
                                           value="<?php echo esc_attr( admbud_colour_val( 'admbud_colours_pill_maintenance', '#dd3333' ) ); ?>"
                                           class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::COLOR_MAINTENANCE ); ?>">
                                    <p class="description"><?php esc_html_e( 'Leave blank for default red.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="admbud_colours_pill_coming_soon"><?php esc_html_e( 'Coming Soon Pill', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_pill_coming_soon" name="admbud_colours_pill_coming_soon"
                                           value="<?php echo esc_attr( admbud_colour_val( 'admbud_colours_pill_coming_soon', '#dd3333' ) ); ?>"
                                           class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::COLOR_COMING_SOON ); ?>">
                                    <p class="description"><?php esc_html_e( 'Leave blank for default amber.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="admbud_colours_pill_noindex"><?php esc_html_e( 'Search Blocked Pill', 'admin-buddy' ); ?></label></th>
                                <td>
                                    <input type="color" id="admbud_colours_pill_noindex" name="admbud_colours_pill_noindex"
                                           value="<?php echo esc_attr( admbud_colour_val( 'admbud_colours_pill_noindex', '#dd9933' ) ); ?>"
                                           class="ab-native-color-picker" data-default-color="<?php echo esc_attr( Colours::COLOR_NOINDEX ); ?>">
                                    <p class="description"><?php esc_html_e( 'Leave blank for default violet.', 'admin-buddy' ); ?></p>
                                </td>
                            </tr>
                        </table>
                        <?php $settings->card_close(); ?>


                    </div><!-- /#ab-pane-content -->

                                        <!-- -- Panel: Presets ----------------------------------- -->
                    <div class="ab-pane<?php echo $active_subtab !== 'presets' ? ' ab-hidden' : ''; ?>" id="ab-pane-presets">
                        <?php
                        $presets      = $settings->get_colour_presets();
                        $preset_nonce = wp_create_nonce( 'admbud_apply_preset' );
                        $settings->card_open_svg(
                            '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>',
                            __( 'Colour Presets', 'admin-buddy' ),
                            __( 'Apply a ready-made colour scheme instantly. Overwrites your current colour settings.', 'admin-buddy' )
                        ); ?>
                        <div class="ab-preset-grid">
                            <?php foreach ( $presets as $slug => $preset ) : ?>
                            <div class="ab-preset-card">
                                <div class="ab-preset-swatches">
                                    <?php foreach ( $preset['swatches'] as $swatch ) : ?>
                                    <span class="ab-preset-swatch" style="background:<?php echo esc_attr( $swatch ); ?>;"></span>
                                    <?php endforeach; ?>
                                </div>
                                <div class="ab-preset-info">
                                    <strong class="ab-preset-name"><?php echo esc_html( $preset['label'] ); ?></strong>
                                    <p class="ab-preset-desc"><?php echo esc_html( $preset['description'] ); ?></p>
                                </div>
                                <button type="button" class="ab-btn ab-btn--primary ab-btn--sm ab-preset-apply"
                                        data-preset="<?php echo esc_attr( $slug ); ?>"
                                        data-nonce="<?php echo esc_attr( $preset_nonce ); ?>"
                                        data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
                                    <?php esc_html_e( 'Apply', 'admin-buddy' ); ?>
                                </button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php $settings->card_close(); ?>
                    </div>

                    <button type="submit" class="ab-form-save-btn" style="display:none;" aria-hidden="true"></button>


                </div><!-- /.ab-colours-left -->
                <!-- -- Right: sticky preview ------------------------------- -->
                <div class="ab-colours-preview-col" id="ab-colours-preview-col">
                        <p class="ab-colours-preview-label"><?php esc_html_e( 'Live Preview', 'admin-buddy' ); ?></p>
                        <!-- Admin bar strip -->
                        <div id="ab-preview-adminbar" style="border-radius:8px 8px 0 0;overflow:hidden;background:<?php echo esc_attr( admbud_get_option( 'admbud_colours_adminbar_bg','') ?: ( $menu_bg ?: Colours::DEFAULT_MENU_BG ) ); ?>;height:32px;display:flex;align-items:center;padding:0 10px;gap:6px;">
                            <div style="height:5px;background:rgba(255,255,255,0.5);border-radius:3px;width:22%;"></div>
                            <div style="height:5px;background:rgba(255,255,255,0.25);border-radius:3px;width:14%;"></div>
                            <div style="height:5px;background:rgba(255,255,255,0.25);border-radius:3px;width:14%;"></div>
                            <div style="margin-left:auto;height:18px;background:<?php echo esc_attr( $primary ?: Colours::DEFAULT_PRIMARY ); ?>;border-radius:10px;width:22%;display:flex;align-items:center;justify-content:center;">
                                <div style="height:4px;background:rgba(255,255,255,0.9);border-radius:2px;width:60%;"></div>
                            </div>
                            <div style="height:5px;background:rgba(255,255,255,0.3);border-radius:3px;width:14%;"></div>
                        </div>
                        <!-- Main layout: sidebar + content -->
                        <div id="ab-colour-preview" style="overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.15);height:340px;display:flex;border-radius:0 0 8px 8px;">
                            <!-- Sidebar -->
                            <div id="ab-preview-sidebar" style="width:90px;flex-shrink:0;background:<?php echo esc_attr( $menu_bg ?: Colours::DEFAULT_MENU_BG ); ?>;padding:8px 0;display:flex;flex-direction:column;gap:2px;">
                                <div style="padding:6px 10px 10px;margin-bottom:4px;border-bottom:1px solid rgba(255,255,255,0.08);">
                                    <div id="ab-preview-logo" style="width:22px;height:22px;background:<?php echo esc_attr( $primary ?: Colours::DEFAULT_PRIMARY ); ?>;border-radius:4px;"><div></div></div>
                                </div>
                                <div id="ab-preview-item-active" style="background:<?php echo esc_attr( $primary ?: Colours::DEFAULT_PRIMARY ); ?>;padding:5px 10px;margin:0 0 2px;">
                                    <div style="height:4px;border-radius:2px;background:<?php echo esc_attr( $menu_text ?: Colours::DEFAULT_MENU_TEXT ); ?>;opacity:0.95;width:72%;"></div>
                                </div>
                                <?php foreach ( [0.45,0.3,0.45,0.35,0.45,0.3] as $op ) : ?>
                                <div style="padding:5px 10px;">
                                    <div style="height:3px;border-radius:2px;background:<?php echo esc_attr( $menu_text ?: Colours::DEFAULT_MENU_TEXT ); ?>;opacity:<?php echo esc_attr($op); ?>;width:<?php echo (int)(50+$op*60); ?>%;"></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <!-- Content area -->
                            <div id="ab-preview-content" style="flex:1;background:<?php echo esc_attr( admbud_get_option( 'admbud_colours_body_bg','') ?: '#f0f0f1' ); ?>;display:flex;flex-direction:column;overflow:hidden;">
                                <!-- Page title + button row -->
                                <div style="padding:10px 10px 6px;display:flex;align-items:center;gap:6px;border-bottom:1px solid rgba(0,0,0,0.06);">
                                    <div style="height:6px;background:<?php echo esc_attr( admbud_get_option( 'admbud_colours_content_heading','') ?: '#1d2327' ); ?>;border-radius:2px;width:30%;opacity:0.85;"></div>
                                    <div style="height:18px;background:<?php echo esc_attr( admbud_get_option( 'admbud_colours_btn_primary_bg','') ?: ( $primary ?: Colours::DEFAULT_PRIMARY ) ); ?>;border-radius:3px;width:22%;margin-left:4px;"></div>
                                </div>
                                <!-- Filter tabs row -->
                                <div style="padding:5px 10px;display:flex;gap:8px;border-bottom:1px solid rgba(0,0,0,0.05);">
                                    <div style="height:4px;border-radius:2px;background:<?php echo esc_attr( admbud_get_option( 'admbud_colours_content_link','') ?: ( $primary ?: Colours::DEFAULT_PRIMARY ) ); ?>;width:12%;opacity:0.9;"></div>
                                    <div style="height:4px;border-radius:2px;background:<?php echo esc_attr( admbud_get_option( 'admbud_colours_content_text','') ?: '#3c434a' ); ?>;width:14%;opacity:0.4;"></div>
                                    <div style="height:4px;border-radius:2px;background:<?php echo esc_attr( admbud_get_option( 'admbud_colours_content_text','') ?: '#3c434a' ); ?>;width:18%;opacity:0.4;"></div>
                                </div>
                                <!-- Table header -->
                                <div style="padding:5px 10px;background:<?php echo esc_attr( admbud_get_option( 'admbud_colours_table_header_bg','') ?: '#f6f7f7' ); ?>;display:flex;gap:8px;align-items:center;">
                                    <div style="width:8px;height:8px;border:1px solid #8c8f94;border-radius:1px;flex-shrink:0;"></div>
                                    <div style="height:4px;border-radius:2px;background:<?php echo esc_attr( admbud_get_option( 'admbud_colours_table_header_text','') ?: '#1d2327' ); ?>;width:30%;opacity:0.7;"></div>
                                    <div style="height:4px;border-radius:2px;background:<?php echo esc_attr( admbud_get_option( 'admbud_colours_table_header_text','') ?: '#1d2327' ); ?>;width:20%;opacity:0.5;margin-left:auto;"></div>
                                </div>
                                <!-- Table rows -->
                                <?php
                                $row_bg  = admbud_get_option( 'admbud_colours_table_row_bg','')  ?: '#ffffff';
                                $alt_bg  = admbud_get_option( 'admbud_colours_table_row_alt_bg','') ?: '#f9f7fe';
                                $row_txt = admbud_get_option( 'admbud_colours_table_row_text','') ?: '#3c434a';
                                $link_c  = admbud_get_option( 'admbud_colours_content_link','') ?: ( $primary ?: Colours::DEFAULT_PRIMARY );
                                $sep_c   = admbud_get_option( 'admbud_colours_table_row_separator','') ?: '#ebe1fc';
                                foreach ( [ [$row_bg,1], [$alt_bg,0], [$row_bg,1] ] as [$bg,$idx] ) : ?>
                                <div style="padding:6px 10px;background:<?php echo esc_attr($bg); ?>;border-bottom:1px solid <?php echo esc_attr($sep_c); ?>;display:flex;gap:8px;align-items:center;">
                                    <div style="width:8px;height:8px;border:1px solid #c3c4c7;border-radius:1px;flex-shrink:0;"></div>
                                    <div style="height:4px;border-radius:2px;background:<?php echo esc_attr($link_c); ?>;width:35%;opacity:0.85;"></div>
                                    <div style="height:3px;border-radius:2px;background:<?php echo esc_attr($row_txt); ?>;width:22%;opacity:0.4;margin-left:auto;"></div>
                                </div>
                                <?php endforeach; ?>
                                <!-- Input + button row -->
                                <div style="padding:8px 10px;display:flex;gap:6px;align-items:center;margin-top:4px;">
                                    <div style="flex:1;height:22px;background:<?php echo esc_attr( admbud_get_option( 'admbud_colours_input_bg','') ?: '#fff' ); ?>;border:1px solid <?php echo esc_attr( admbud_get_option( 'admbud_colours_input_border','') ?: '#c4b5fd' ); ?>;border-radius:3px;"></div>
                                    <div style="height:22px;background:<?php echo esc_attr( admbud_get_option( 'admbud_colours_btn_primary_bg','') ?: ($primary ?: Colours::DEFAULT_PRIMARY) ); ?>;border-radius:3px;width:26%;display:flex;align-items:center;justify-content:center;">
                                        <div style="height:4px;background:rgba(255,255,255,0.9);border-radius:2px;width:60%;"></div>
                                    </div>
                                </div>
                                <!-- Postbox card -->
                                <div style="margin:0 10px 8px;background:<?php echo esc_attr( admbud_get_option( 'admbud_colours_postbox_bg','') ?: '#fff' ); ?>;border:1px solid <?php echo esc_attr( admbud_get_option( 'admbud_colours_postbox_border','') ?: '#decdfa' ); ?>;border-radius:4px;overflow:hidden;flex:1;">
                                    <div style="padding:5px 8px;background:<?php echo esc_attr( admbud_get_option( 'admbud_colours_postbox_header_bg','') ?: '#f9f7fe' ); ?>;border-bottom:1px solid <?php echo esc_attr( admbud_get_option( 'admbud_colours_postbox_border','') ?: '#decdfa' ); ?>;">
                                        <div style="height:4px;border-radius:2px;background:<?php echo esc_attr( admbud_get_option( 'admbud_colours_content_heading','') ?: '#1d2327' ); ?>;width:40%;opacity:0.7;"></div>
                                    </div>
                                    <div style="padding:8px;display:flex;flex-direction:column;gap:4px;">
                                        <div style="height:3px;border-radius:2px;background:<?php echo esc_attr($row_txt); ?>;width:90%;opacity:0.4;"></div>
                                        <div style="height:3px;border-radius:2px;background:<?php echo esc_attr($row_txt); ?>;width:75%;opacity:0.3;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <p class="description ab-mt-2 ab-hint"><?php esc_html_e( 'Updates as you change colours. Save to apply.', 'admin-buddy' ); ?></p>
                </div><!-- /.ab-colours-preview-col -->

            </div><!-- /.ab-colours-layout -->
        </form>
        </div><!-- /#ab-colours-form-wrap -->

            <!-- -- Panel: Exclusions ---------------------------------------- -->
            <div class="ab-pane<?php echo $active_subtab !== 'exclusions' ? ' ab-hidden' : ''; ?>" id="ab-pane-exclusions">
                <?php
                // -- Auto-detect third-party plugins --------------------------
                $exclusions_raw = admbud_get_option( 'admbud_css_exclusions', '' );
                $excluded_slugs = array_filter( array_map( 'trim', explode( "\n", $exclusions_raw ) ) );

                // Scan registered admin menu pages for third-party plugins.
                global $menu, $submenu;

                // Core WP slugs and generic junk to skip.
                $core_prefixes = [
                    'index.php', 'edit.php', 'upload.php', 'edit-comments.php',
                    'themes.php', 'plugins.php', 'users.php', 'tools.php',
                    'options-general.php', 'profile.php', 'site-health.php',
                    'update-core.php', 'import.php', 'export.php', 'privacy.php',
                    'admin-buddy', 'admbud_', 'customize',
                ];
                // Generic single-word slugs that are WP internals or too ambiguous.
                $junk_slugs = [ 'action', 'add', 'edit', 'post', 'page', 'link', 'comment', 'options', 'menu', 'widget', 'nav', 'theme', 'plugin', 'user', 'media', 'tool', 'setting', 'dashboard' ];

                $detected = []; // prefix => [ 'label' => string, 'slugs' => string[], 'count' => int ]

                /**
                 * Extract a plugin prefix from a menu slug.
                 * Splits on both hyphens and underscores to group sub-pages.
                 * e.g. "sureforms_entries" → "sureforms", "acf-field-group" → "acf"
                 */
                $get_prefix = static function ( string $slug ): string {
                    // Normalise to lowercase before splitting.
                    $slug = strtolower( $slug );
                    // strtok splits on ANY character in the delimiter string.
                    return strtok( $slug, '-_' );
                };

                /**
                 * Check if a slug should be skipped (core, junk, URL, hash-router, or .php file).
                 */
                $should_skip = static function ( string $slug ) use ( $core_prefixes, $junk_slugs, $get_prefix ): bool {
                    if ( $slug === '' ) { return true; }
                    // URLs and hash-router paths (e.g. Surerank#/advanced).
                    if ( str_contains( $slug, '://' ) || str_contains( $slug, '.php' ) || str_contains( $slug, '#' ) ) { return true; }
                    // Core prefixes.
                    $slug_lower = strtolower( $slug );
                    foreach ( $core_prefixes as $cp ) {
                        if ( $slug_lower === $cp || str_starts_with( $slug_lower, $cp ) ) { return true; }
                    }
                    // Junk single-word slugs.
                    $prefix = $get_prefix( $slug );
                    if ( in_array( $prefix, $junk_slugs, true ) ) { return true; }
                    // Skip very short prefixes (1 char) - too ambiguous.
                    if ( strlen( $prefix ) < 2 ) { return true; }
                    // Skip bare 'wp' slug only - allow wp-reset, wp-mail-smtp etc.
                    if ( $slug_lower === 'wp' ) { return true; }
                    return false;
                };

                // Walk through $menu (top-level) first - these have the best labels.
                if ( ! empty( $menu ) && is_array( $menu ) ) {
                    foreach ( $menu as $item ) {
                        $slug  = strtolower( $item[2] ?? '' );
                        $label = wp_strip_all_tags( $item[0] ?? '' );
                        if ( $should_skip( $slug ) || $label === '' ) { continue; }
                        $prefix = $get_prefix( $slug );
                        if ( ! isset( $detected[ $prefix ] ) ) {
                            $detected[ $prefix ] = [ 'label' => $label, 'slugs' => [], 'count' => 0 ];
                        }
                        // Top-level label is usually the plugin name - prefer it.
                        $detected[ $prefix ]['label'] = $label;
                        if ( ! in_array( $slug, $detected[ $prefix ]['slugs'], true ) ) {
                            $detected[ $prefix ]['slugs'][] = $slug;
                        }
                    }
                }

                // Walk through $submenu to count sub-pages per prefix.
                if ( ! empty( $submenu ) && is_array( $submenu ) ) {
                    foreach ( $submenu as $parent => $items ) {
                        foreach ( $items as $item ) {
                            $slug  = strtolower( $item[2] ?? '' );
                            if ( $should_skip( $slug ) ) { continue; }
                            $prefix = $get_prefix( $slug );
                            if ( ! isset( $detected[ $prefix ] ) ) {
                                $label = wp_strip_all_tags( $item[0] ?? '' );
                                if ( $label === '' ) { continue; }
                                $detected[ $prefix ] = [
                                    'label' => $label,
                                    'slugs' => [],
                                    'count' => 0,
                                ];
                            }
                            if ( ! in_array( $slug, $detected[ $prefix ]['slugs'], true ) ) {
                                $detected[ $prefix ]['slugs'][] = $slug;
                            }
                            $detected[ $prefix ]['count']++;
                        }
                    }
                }

                ksort( $detected );
                ?>
                <?php $settings->card_open_svg(
                    '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>',
                    __( 'Detected Plugins', 'admin-buddy' ),
                    __( 'Toggle plugins whose admin pages should keep their own styling. Admin Buddy\'s content colours (links, buttons, tables, inputs) will be skipped on their pages. The sidebar and admin bar remain styled.', 'admin-buddy' )
                ); ?>
                        <?php if ( ! empty( $detected ) ) : ?>
                        <form method="post" action="options.php" id="ab-exclusions-form">
                            <?php settings_fields( 'admbud_css_exclusions_group' ); ?>
                            <input type="hidden" name="admbud_tab" value="colours">
                            <input type="hidden" name="admbud_subtab" value="exclusions">
                            <div class="ab-grid ab-grid--ruled ab-grid--module" style="border-radius:var(--ab-radius-md);overflow:hidden;">
                                <?php foreach ( $detected as $prefix => $info ) :
                                    $is_excluded = false;
                                    foreach ( $excluded_slugs as $ex ) {
                                        if ( $prefix === $ex || str_starts_with( $prefix, $ex . '-' ) || str_starts_with( $prefix, $ex . '_' ) ) {
                                            $is_excluded = true; break;
                                        }
                                        if ( str_starts_with( $ex, $prefix . '-' ) || str_starts_with( $ex, $prefix . '_' ) || $ex === $prefix ) {
                                            $is_excluded = true; break;
                                        }
                                    }
                                    $page_count = max( 1, count( $info['slugs'] ) );
                                ?>
                                <label class="ab-setup-module-row" style="cursor:pointer;">
                                    <span class="ab-setup-module-label">
                                        <?php echo esc_html( $info['label'] ); ?>
                                        <?php if ( $page_count > 1 ) : ?>
                                        <?php /* translators: %d: count */ ?>
                                        <span class="ab-text-xs ab-text-muted" style="margin-left:var(--ab-space-2);"><?php printf( esc_html__( '(%d pages)', 'admin-buddy' ), (int) $page_count ); ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="ab-toggle ab-module-card__toggle">
                                        <input type="checkbox" class="ab-exclusion-toggle"
                                               data-prefix="<?php echo esc_attr( $prefix ); ?>"
                                               <?php checked( $is_excluded ); ?>>
                                        <span class="ab-toggle__track"></span>
                                        <span class="ab-toggle__thumb"></span>
                                    </span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <textarea id="admbud_css_exclusions" name="admbud_css_exclusions" class="ab-hidden" aria-hidden="true"><?php echo esc_textarea( $exclusions_raw ); ?></textarea>
                            <div style="margin-top:var(--ab-space-4);display:flex;align-items:center;gap:var(--ab-space-3);">
                                <?php submit_button( __( 'Save Exclusions', 'admin-buddy' ), 'primary', 'submit', false ); ?>
                            </div>
                        </form>
                        <?php else : ?>
                        <p class="ab-text-muted ab-text-sm"><?php esc_html_e( 'No third-party plugin pages detected.', 'admin-buddy' ); ?></p>
                        <?php endif; ?>
                <?php $settings->card_close(); ?>

                <?php $settings->card_open_svg(
                    '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>',
                    __( 'Manual Exclusions', 'admin-buddy' ),
                    __( 'For plugins not detected above, add page slugs manually. One entry per line. Prefix matching is supported: "acf" excludes acf-field-group, acf-post-type, etc.', 'admin-buddy' )
                ); ?>
                        <form method="post" action="options.php" id="ab-manual-exclusions-form">
                            <?php settings_fields( 'admbud_css_exclusions_group' ); ?>
                            <input type="hidden" name="admbud_tab" value="colours">
                            <input type="hidden" name="admbud_subtab" value="exclusions">
                            <textarea
                                id="admbud_css_exclusions_manual"
                                name="admbud_css_exclusions"
                                rows="6"
                                class="large-text code"
                                placeholder="acf&#10;elementor&#10;wpcf7"
                            ><?php echo esc_textarea( $exclusions_raw ); ?></textarea>
                            <div style="margin-top:var(--ab-space-3);">
                                <?php submit_button( __( 'Save Exclusions', 'admin-buddy' ), 'primary', 'submit', false ); ?>
                            </div>
                        </form>
                <?php $settings->card_close(); ?>
            </div><!-- /#ab-pane-exclusions -->



        </div><!-- /.ab-colours-main -->

        <?php

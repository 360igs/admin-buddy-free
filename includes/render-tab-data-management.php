<?php
/**
 * Data Management tab - Reset, Deactivate, Delete.
 * Included by the Setup tab's Data Management pane.
 *
 * @package Admbud
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Variables scoped to this included template file.

/** @var \Admbud\Settings $settings */

        $icon_trash  = '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" class="ab-inline-icon"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>';
        $tools_url  = esc_url( admin_url( 'admin.php?page=admbud' ) );

        // Inventory of user-created content the buttons below will destroy.
        // Serialised to JSON on each form so the confirm modal can enumerate
        // counts at click time — defeats autopilot through a generic modal.
        $inventory      = wp_json_encode( $settings->data_inventory() );
        $inventory_attr = esc_attr( $inventory );
        ?>
        <div class="ab-advanced-tab">

            <?php /* -- Data Management -- */ ?>
            <?php $settings->card_open_svg(
                '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>',
                __( 'Data Management', 'admin-buddy' ),
                __( 'Permanently erase Admin Buddy data — including your Collections, Option Pages, snippets, SVG icons, and uploaded files. Not just toggle settings.', 'admin-buddy' )
            ); ?>

            <div class="ab-notice ab-notice ab-notice--warning" style="margin-bottom:20px;">
                <strong><?php esc_html_e( 'Warning:', 'admin-buddy' ); ?></strong>
                <?php esc_html_e( 'These actions erase user-created content (Collections, Option Pages, snippets, SVG icons, activity log, uploaded files), not just preferences. Recovery is not possible without a backup. The confirmation step will show exactly what will be deleted.', 'admin-buddy' ); ?>
            </div>

            <div style="display:flex;flex-direction:column;gap:12px;max-width:400px;">

                <?php /* Button 1: Erase all data */ ?>
                <form method="post" action="<?php echo esc_url( $tools_url ); ?>" class="ab-reset-form"
                      data-confirm-title="<?php esc_attr_e( 'Erase All Admin Buddy Data?', 'admin-buddy' ); ?>"
                      data-inventory="<?php echo $inventory_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
                      data-require-type="ERASE">
                    <?php wp_nonce_field( 'admbud_reset_data', 'admbud_reset_nonce' ); ?>
                    <input type="hidden" name="admbud_action" value="reset_data">
                    <button type="submit" class="ab-btn ab-btn--danger ab-tools-danger-btn ab-w-full" style="justify-content:center;">
                        <?php echo wp_kses( $icon_trash, admbud_kses_svg_allowed() ); ?>
                        <?php esc_html_e( 'Erase All Admin Buddy Data', 'admin-buddy' ); ?>
                    </button>
                </form>

                <?php /* Button 2: Erase all data and deactivate */ ?>
                <form method="post" action="<?php echo esc_url( $tools_url ); ?>" class="ab-reset-form"
                      data-confirm-title="<?php esc_attr_e( 'Erase All Data and Deactivate?', 'admin-buddy' ); ?>"
                      data-inventory="<?php echo $inventory_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
                      data-require-type="ERASE"
                      data-extra-note="<?php esc_attr_e( 'The plugin will also be deactivated. You can reactivate it later from the Plugins screen, but the erased data will not return.', 'admin-buddy' ); ?>">
                    <?php wp_nonce_field( 'admbud_reset_deactivate', 'admbud_reset_deactivate_nonce' ); ?>
                    <input type="hidden" name="admbud_action" value="reset_deactivate">
                    <button type="submit" class="ab-btn ab-btn--danger ab-tools-danger-btn ab-w-full" style="justify-content:center;">
                        <?php echo wp_kses( $icon_trash, admbud_kses_svg_allowed() ); ?>
                        <?php esc_html_e( 'Erase All Data and Deactivate', 'admin-buddy' ); ?>
                    </button>
                </form>

            </div>
            <?php $settings->card_close(); ?>

        </div>

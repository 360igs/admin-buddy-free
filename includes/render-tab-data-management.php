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
        $tools_url  = esc_url( admin_url( 'admin.php?page=admin-buddy' ) );
        ?>
        <div class="ab-advanced-tab">

            <?php /* -- Data Management -- */ ?>
            <?php $settings->card_open_svg(
                '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>',
                __( 'Data Management', 'admin-buddy' ),
                __( 'Reset or permanently remove all Admin Buddy settings and the plugin itself.', 'admin-buddy' )
            ); ?>

            <div class="ab-notice ab-notice ab-notice--warning" style="margin-bottom:20px;">
                <strong><?php esc_html_e( 'Warning:', 'admin-buddy' ); ?></strong>
                <?php esc_html_e( 'These actions are irreversible. Deactivating or deleting the plugin does NOT delete settings data automatically. Use the buttons below for a clean removal.', 'admin-buddy' ); ?>
            </div>

            <div style="display:flex;flex-direction:column;gap:12px;max-width:400px;">

                <?php /* Button 1: Reset Plugin Data */ ?>
                <form method="post" action="<?php echo esc_url( $tools_url ); ?>" class="ab-reset-form"
                      data-confirm-title="<?php esc_attr_e( 'Reset Plugin Data?', 'admin-buddy' ); ?>"
                      data-confirm-body="<?php esc_attr_e( 'This will permanently delete all Admin Buddy settings from the database. The plugin will remain active. This cannot be undone.', 'admin-buddy' ); ?>">
                    <?php wp_nonce_field( 'admbud_reset_data', 'admbud_reset_nonce' ); ?>
                    <input type="hidden" name="admbud_action" value="reset_data">
                    <button type="submit" class="ab-btn ab-btn--danger ab-tools-danger-btn ab-w-full" style="justify-content:center;">
                        <?php echo $icon_trash; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php esc_html_e( 'Reset Plugin Data', 'admin-buddy' ); ?>
                    </button>
                </form>

                <?php /* Button 2: Reset and Deactivate Plugin */ ?>
                <form method="post" action="<?php echo esc_url( $tools_url ); ?>" class="ab-reset-form"
                      data-confirm-title="<?php esc_attr_e( 'Reset and Deactivate Plugin?', 'admin-buddy' ); ?>"
                      data-confirm-body="<?php esc_attr_e( 'This will delete all Admin Buddy settings and deactivate the plugin. You can reactivate it later from the Plugins screen.', 'admin-buddy' ); ?>">
                    <?php wp_nonce_field( 'admbud_reset_deactivate', 'admbud_reset_deactivate_nonce' ); ?>
                    <input type="hidden" name="admbud_action" value="reset_deactivate">
                    <button type="submit" class="ab-btn ab-btn--danger ab-tools-danger-btn ab-w-full" style="justify-content:center;">
                        <?php echo $icon_trash; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php esc_html_e( 'Reset and Deactivate Plugin', 'admin-buddy' ); ?>
                    </button>
                </form>

            </div>
            <?php $settings->card_close(); ?>

        </div>

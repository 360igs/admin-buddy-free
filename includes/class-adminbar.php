<?php
/**
 * AdminBar - injects status indicators into the WordPress admin toolbar.
 *
 * Shows persistent, colour-coded pills on every admin page when:
 *  1. Maintenance / Coming Soon mode is active.
 *  2. "Discourage search engines from indexing this site" is enabled.
 *
 * Design: A labelled coloured pill node directly in the toolbar, always visible.
 * 
 *
 * @package Admbud
 */

namespace Admbud;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AdminBar {

    private static ?AdminBar $instance = null;

    public static function get_instance(): AdminBar {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_bar_menu',        [ $this, 'add_nodes'  ], 999 );
        add_action( 'admin_enqueue_scripts', [ $this, 'inline_css' ]      );
        add_action( 'wp_enqueue_scripts',    [ $this, 'inline_css' ]      ); // also on front end for admins
    }

    // -- Nodes -----------------------------------------------------------------

    public function add_nodes( \WP_Admin_Bar $bar ): void {
        if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $this->maybe_add_mode_node( $bar );
        $this->maybe_add_noindex_node( $bar );
    }

    /**
     * Maintenance / Coming Soon pill.
     * Orange for Coming Soon, red for Maintenance, nothing when Off.
     */
    private function maybe_add_mode_node( \WP_Admin_Bar $bar ): void {
        $mode = admbud_get_option( 'admbud_maintenance_mode', 'off' );
        if ( $mode === 'off' ) {
            return;
        }

        $settings_url = admin_url( 'admin.php?page=admin-buddy&tab=maintenance' );

        // SVG icons - inline in the admin bar node title (WP_Admin_Bar accepts HTML).
        $rocket_svg = '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;position:relative;top:-1px;"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/></svg>';
        $wrench_svg = '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;position:relative;top:-1px;"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>';

        if ( $mode === 'coming_soon' ) {
            $icon  = $rocket_svg;
            $label = __( 'Coming Soon', 'admin-buddy' );
            $class = 'ab-bar-node ab-bar-node--coming-soon';
        } else {
            $icon  = $wrench_svg;
            $label = __( 'Maintenance', 'admin-buddy' );
            $class = 'ab-bar-node ab-bar-node--maintenance';
        }

        $bar->add_node( [
            'id'     => 'ab-mode-indicator',
            'title'  => '<span class="' . esc_attr( $class ) . '">' . $icon . esc_html( $label ) . '</span>',
            'href'   => $settings_url,
            'meta'   => [
                'title' => __( 'Admin Buddy: click to manage site mode', 'admin-buddy' ),
            ],
        ] );
    }

    /**
     * Search-engine discouragement pill.
     * Yellow warning when WP's "Discourage search engines" is checked.
     */
    private function maybe_add_noindex_node( \WP_Admin_Bar $bar ): void {
        if ( get_option( 'blog_public' ) !== '0' ) {
            return; // Site is indexable - nothing to show.
        }

        $settings_url = admin_url( 'options-reading.php' );

        $bar->add_node( [
            'id'    => 'ab-noindex-indicator',
            'title' => '<span class="ab-bar-node ab-bar-node--noindex"><svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;position:relative;top:-1px;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>' . esc_html__( 'Search Engines Blocked', 'admin-buddy' ) . '</span>',
            'href'  => $settings_url,
            'meta'  => [
                'title' => __( 'Search engines are discouraged from indexing this site. Click to change', 'admin-buddy' ),
            ],
        ] );
    }

    // -- Inline CSS ------------------------------------------------------------

    /**
     * Tiny inline stylesheet for the pill nodes.
     * Kept inline so it works on both admin and front-end admin bar
     * without needing to enqueue a separate file for front-end.
     */
    public function inline_css(): void {
        if ( ! is_admin_bar_showing() ) {
            return;
        }

        // Pull pill colours from options (with class-default fallbacks). Each
        // is run through sanitize_hex_field() at read so the final CSS string
        // contains no unescaped data.
        $coming_soon = sanitize_hex_field( admbud_get_option( 'admbud_colours_pill_coming_soon', '' ) ) ?: \Admbud\Colours::COLOR_COMING_SOON;
        $maintenance = sanitize_hex_field( admbud_get_option( 'admbud_colours_pill_maintenance', '' ) ) ?: \Admbud\Colours::COLOR_MAINTENANCE;
        $noindex     = sanitize_hex_field( admbud_get_option( 'admbud_colours_pill_noindex',     '' ) ) ?: \Admbud\Colours::COLOR_NOINDEX;
        $admin_buddy = sanitize_hex_field( admbud_get_option( 'admbud_colours_pill_admin_buddy', '' ) )
            ?: ( sanitize_hex_field( admbud_get_option( 'admbud_colours_primary', '' ) ) ?: \Admbud\Colours::DEFAULT_PRIMARY );

        $css = '#wpadminbar #wp-admin-bar-ab-mode-indicator > .ab-item,'
             . '#wpadminbar #wp-admin-bar-ab-noindex-indicator > .ab-item,'
             . '#wpadminbar #wp-admin-bar-ab-checklist-indicator > .ab-item,'
             . '#wpadminbar #wp-admin-bar-admin-buddy > .ab-item,'
             . '#wpadminbar .quicklinks #wp-admin-bar-ab-mode-indicator:hover > .ab-item,'
             . '#wpadminbar .quicklinks #wp-admin-bar-ab-noindex-indicator:hover > .ab-item,'
             . '#wpadminbar .quicklinks #wp-admin-bar-ab-checklist-indicator:hover > .ab-item,'
             . '#wpadminbar .quicklinks #wp-admin-bar-admin-buddy:hover > .ab-item,'
             . '#wpadminbar.mobile .quicklinks #wp-admin-bar-ab-mode-indicator:hover > .ab-item,'
             . '#wpadminbar.mobile .quicklinks #wp-admin-bar-ab-noindex-indicator:hover > .ab-item,'
             . '#wpadminbar.mobile .quicklinks #wp-admin-bar-ab-checklist-indicator:hover > .ab-item,'
             . '#wpadminbar.mobile .quicklinks #wp-admin-bar-admin-buddy:hover > .ab-item{'
             . 'padding:0!important;background:transparent!important;color:inherit!important;}'
             . '.ab-bar-node{display:inline-flex;align-items:center;gap:5px;padding:0 10px!important;'
             . 'border-radius:20px;font-size:12px;font-weight:600;line-height:1;height:22px;'
             . 'margin:5px 3px 0 3px;letter-spacing:0.01em;transition:opacity 0.15s;}'
             . '#wpadminbar .ab-bar-node:hover{opacity:0.85;}'
             . '.ab-bar-node--coming-soon{background:' . $coming_soon . ';color:#ffffff;}'
             . '.ab-bar-node--maintenance{background:' . $maintenance . ';color:#ffffff;}'
             . '.ab-bar-node--noindex{background:'     . $noindex     . ';color:#ffffff;}'
             . '.ab-bar-node--admin-buddy{background:' . $admin_buddy . ';color:#ffffff;}'
             . '.ab-bar-node--checklist-ok{background:#2d7a2d;color:#ffffff;}'
             . '.ab-bar-node--checklist-warn{background:#b45309;color:#ffffff;}'
             . '.ab-bar-node--checklist-alert{background:#b42318;color:#ffffff;}';

        wp_add_inline_style( 'admin-bar', $css );
    }
}

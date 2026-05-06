<?php
/**
 * Modules tab UI - Module toggle grid (React) + locked Pro modules section.
 * Included by Settings::render_tab_modules().
 * $settings is the Settings singleton.
 *
 * The locked Pro modules grid at the bottom is shown ONLY in the free build,
 * detected at runtime via `! $admbud_has_sdk`. In the Pro build the licensing SDK
 * is present so $admbud_has_sdk is true and the gate evaluates false.
 *
 * @package Admbud
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Variables scoped to this included template file.

/** @var \Admbud\Settings $settings */

// Determine license state.
$admbud_has_sdk    = file_exists( ADMBUD_DIR . 'licensing/src/Client.php' );
$admbud_is_licensed = true; // Modules page always accessible - pro features gated by admbud_is_pro() individually.
$admbud_is_paid     = function_exists( 'admbud_is_paid' ) && admbud_is_paid();
?>
<div class="ab-setup-wrap">

<?php
// -- Show modules --
?>

    <?php
    // Welcome banner on first activation or when no modules are enabled.
    $show_welcome = isset( $_GET['admbud_welcome'] ) || admbud_get_option( 'admbud_modules_enabled_tabs', '' ) === ''; // phpcs:ignore WordPress.Security.NonceVerification
    if ( $show_welcome ) : ?>
    <div class="ab-welcome-banner" style="background:linear-gradient(135deg, var(--ab-accent, #7c3aed) 0%, var(--ab-accent-hover, #6d28d9) 100%);color:#fff;padding:var(--ab-space-6) var(--ab-space-8);border-radius:var(--ab-radius-lg);margin-bottom:var(--ab-space-6);display:flex;align-items:center;gap:var(--ab-space-5);">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 777 782" width="56" height="56" fill="none" style="flex-shrink:0;opacity:0.95;" aria-hidden="true"><path d="M709.83 66.4628C752.727 109.346 776.292 166.276 776.292 226.901C776.286 302.009 730.895 452.542 549.382 453.812L453.805 454.852L453.805 326.966C506.261 327.244 637.157 308.059 644.707 226.904C644.707 201.484 634.91 177.395 616.902 159.13C598.901 141.124 574.812 131.327 549.125 131.327C498.294 131.327 453.805 175.811 453.805 226.904L453.805 326.966L322.536 327.236L322.49 225.584C322.49 166.276 346.058 109.349 388.946 66.4628C431.837 23.5662 488.762 0.000762939 549.39 0.000762939C610.017 0.000762939 666.944 23.5662 709.83 66.4628Z" fill="white"/><path d="M709.83 714.982C752.727 672.099 776.292 615.169 776.292 554.544C776.286 479.436 730.895 328.903 549.382 327.634L453.805 326.594L453.805 454.479C506.261 454.201 637.157 473.386 644.707 554.542C644.707 579.961 634.91 604.05 616.902 622.315C598.901 640.322 574.812 650.118 549.125 650.118C498.294 650.118 453.805 605.634 453.805 554.542L453.805 454.479L322.536 454.21L322.49 555.861C322.49 615.169 346.058 672.097 388.946 714.982C431.837 757.879 488.762 781.445 549.39 781.445C610.017 781.445 666.944 757.879 709.83 714.982Z" fill="white"/><path d="M66.4622 714.982C23.5655 672.099 0 615.169 0 554.544C0.00631603 479.436 45.3974 328.903 226.911 327.634L322.487 326.594L322.487 454.479C270.032 454.201 139.136 473.386 131.586 554.542C131.586 579.961 141.382 604.05 159.391 622.315C177.392 640.322 201.481 650.118 227.167 650.118C277.998 650.118 322.487 605.634 322.487 554.542L322.487 454.479L453.756 454.21L453.803 555.861C453.803 615.169 430.235 672.097 387.346 714.982C344.455 757.879 287.53 781.445 226.903 781.445C166.275 781.445 109.348 757.879 66.4622 714.982Z" fill="white"/><path fill-rule="evenodd" clip-rule="evenodd" d="M0 226.901C0 166.276 23.5655 109.346 66.4622 66.4628C109.348 23.5662 166.275 0.000762939 226.903 0.000762939C287.53 0.000762939 344.455 23.5662 387.346 66.4628C430.235 109.349 453.803 166.276 453.803 225.584L453.756 327.236L322.487 326.966L322.487 226.904C322.487 175.811 277.998 131.327 227.167 131.327C201.481 131.327 177.392 141.124 159.391 159.13C141.382 177.395 131.586 201.484 131.586 226.904C135.087 264.536 165.111 288.844 201.537 304.064C117.725 304.064 61.7316 345.618 44.2115 366.396C11.09 319.555 0.00312128 264.018 0 226.901Z" fill="white"/><rect x="322.481" y="326.594" width="132.194" height="128.258" fill="white"/></svg>
        <div>
            <h2 style="margin:0 0 4px;font-size:1.15rem;font-weight:700;color:#fff !important;"><?php esc_html_e( 'Welcome to Admin Buddy', 'admin-buddy' ); ?></h2>
            <p style="margin:0;opacity:0.9;font-size:0.9rem;"><?php esc_html_e( 'Enable the modules you need to get started. Each toggle takes effect immediately.', 'admin-buddy' ); ?></p>
        </div>
    </div>
    <?php endif; ?>

    <?php $settings->card_open_svg(
        '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>',
        __( 'Module Visibility', 'admin-buddy' ),
        __( 'Enable the modules you need. Toggles apply instantly, no save needed. Only enabled modules appear in the navigation.', 'admin-buddy' )
    ); ?>


    <div id="ab-setup-modules-react">
        <div style="padding:24px;text-align:center;color:#888;font-size:0.875rem;">
            <?php esc_html_e( 'Loading...', 'admin-buddy' ); ?>
        </div>
    </div>

    <?php $settings->card_close(); ?>

    <?php // -- Locked Pro modules section (free build only) --------------- ?>
    <?php // This block is rendered ONLY when no licensing SDK is present,
          // i.e. the free build. Pro build has the SDK → $admbud_has_sdk = true → skip.
          // No AB_PRO markers needed: this section ships in free. In Pro, the
          // `! $admbud_has_sdk` gate evaluates false and the cards never render.
    ?>
    <?php if ( ! $admbud_has_sdk ) :
        $admbud_pro_modules = [
            [
                'label' => __( 'Menu Customiser', 'admin-buddy' ),
                'desc'  => __( 'Reorder, hide, rename, and assign custom icons to admin menu items. Per-role visibility and separators.', 'admin-buddy' ),
                'icon'  => '<svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>',
            ],
            [
                'label' => __( 'Custom Pages', 'admin-buddy' ),
                'desc'  => __( 'Add your own admin pages with a visual block builder. Perfect for client training docs or internal resources.', 'admin-buddy' ),
                'icon'  => '<svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',
            ],
            [
                'label' => __( 'Notices & Updates', 'admin-buddy' ),
                'desc'  => __( 'Hide WordPress core, plugin and theme update nags, disable auto-update emails, and show a custom policy message.', 'admin-buddy' ),
                'icon'  => '<svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
            ],
            [
                'label' => __( 'Auto Palette', 'admin-buddy' ),
                'desc'  => __( 'Generate a complete, harmonious admin colour palette from one Primary colour. Plus global UI rounding controls.', 'admin-buddy' ),
                'icon'  => '<svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>',
            ],
            [
                'label' => __( 'Collections', 'admin-buddy' ),
                'desc'  => __( 'Build custom post types and field groups without code. Repeaters, relationships, taxonomies and more.', 'admin-buddy' ),
                'icon'  => '<svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>',
            ],
            [
                'label' => __( 'Option Pages', 'admin-buddy' ),
                'desc'  => __( 'Create client-friendly settings pages with custom fields. Values accessible via admbud_option() helper.', 'admin-buddy' ),
                'icon'  => '<svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/><line x1="9" y1="10" x2="15" y2="10"/></svg>',
            ],
            [
                'label' => __( 'Icon Library', 'admin-buddy' ),
                'desc'  => __( 'Upload custom SVG icons and use them anywhere in Admin Buddy - menus, custom pages, collections.', 'admin-buddy' ),
                'icon'  => '<svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 17.5h7M17.5 14v7"/></svg>',
            ],
            [
                'label' => __( 'Activity Log', 'admin-buddy' ),
                'desc'  => __( 'Audit trail of admin actions: logins, post edits, plugin changes, user events. Searchable and filterable.', 'admin-buddy' ),
                'icon'  => '<svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>',
            ],
            [
                'label' => __( 'Debug', 'admin-buddy' ),
                'desc'  => __( 'Toggle WP_DEBUG and related constants from the UI, view the error log inline, and roll back wp-config.php with one click.', 'admin-buddy' ),
                'icon'  => '<svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="8" y="6" width="8" height="14" rx="4"/><path d="M16 10h2a2 2 0 0 1 2 2v2"/><path d="M8 10H6a2 2 0 0 0-2 2v2"/><path d="M16 16h4"/><path d="M8 16H4"/><path d="M9 6V4a3 3 0 0 1 6 0v2"/></svg>',
            ],
            [
                'label' => __( 'Bricks Builder', 'admin-buddy' ),
                'desc'  => __( 'White-label the Bricks builder admin: replace logo, loading spinner, and colour-map the UI to your brand.', 'admin-buddy' ),
                'icon'  => '<svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="2" y="3" width="20" height="18" rx="2"/><path d="M2 9h20"/><path d="M9 21V9"/></svg>',
            ],
            [
                'label' => __( 'Remote', 'admin-buddy' ),
                'desc'  => __( 'Share snippets, collections and settings across multiple WordPress sites from one source of truth.', 'admin-buddy' ),
                'icon'  => '<svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>',
            ],
            [
                'label' => __( 'Export / Import', 'admin-buddy' ),
                'desc'  => __( 'Move your entire Admin Buddy setup between sites. JSON-based, selective, reliable.', 'admin-buddy' ),
                'icon'  => '<svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>',
            ],
            [
                'label' => __( 'Demo Data', 'admin-buddy' ),
                'desc'  => __( 'One-click niche starter content - posts, pages, users, products, menus and media - ready for client demos and theme testing.', 'admin-buddy' ),
                'icon'  => '<svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M3 3h7v7H3z"/><path d="M14 3h7v7h-7z"/><path d="M14 14h7v7h-7z"/><path d="M3 14h7v7H3z"/></svg>',
            ],
            [
                'label' => __( 'Blueprints', 'admin-buddy' ),
                'desc'  => __( 'Curated stacks of plugins and themes you install on every new site - apply your starter blueprint in seconds.', 'admin-buddy' ),
                'icon'  => '<svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg>',
            ],
            [
                'label' => __( 'Command Palette', 'admin-buddy' ),
                'desc'  => __( 'Alt+K anywhere in the admin to jump to any Admin Buddy setting, page, or post. Fast navigation for power users.', 'admin-buddy' ),
                'icon'  => '<svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
            ],
        ];

        $settings->card_open_svg(
            '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M12 2l2.09 6.26L20 9.27l-5 3.64L16.18 19 12 15.27 7.82 19 9 12.91 4 9.27l5.91-1.01L12 2z"/></svg>',
            __( 'Unlock more with Pro', 'admin-buddy' ),
            __( 'Upgrade to access additional modules and features designed for agencies, power users, and multi-site operators.', 'admin-buddy' )
        );
        ?>
        <div class="ab-pro-modules-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:var(--ab-space-4);margin-top:var(--ab-space-2);">
            <?php foreach ( $admbud_pro_modules as $mod ) : ?>
            <div class="ab-pro-module-card" style="border:1px solid var(--ab-neutral-200, #e5e7eb);border-radius:var(--ab-radius-md, 8px);padding:var(--ab-space-4) var(--ab-space-5);background:var(--ab-surface, #fff);display:flex;flex-direction:column;gap:var(--ab-space-3);position:relative;">
                <div style="display:flex;align-items:flex-start;gap:var(--ab-space-3);">
                    <span style="flex-shrink:0;width:40px;height:40px;display:inline-flex;align-items:center;justify-content:center;background:linear-gradient(135deg,rgba(124,58,237,0.08),rgba(124,58,237,0.02));border-radius:var(--ab-radius-sm, 6px);color:var(--ab-accent, #7c3aed);">
                        <?php echo $mod['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </span>
                    <div style="flex:1;min-width:0;">
                        <div style="display:flex;align-items:center;gap:var(--ab-space-2);margin-bottom:4px;">
                            <h4 style="margin:0;font-size:0.95rem;font-weight:600;"><?php echo esc_html( $mod['label'] ); ?></h4>
                            <span class="ab-badge ab-badge--pro" style="font-size:0.65rem;padding:1px 7px;">Pro</span>
                        </div>
                        <p style="margin:0;font-size:0.825rem;color:var(--ab-text-muted, #6b7280);line-height:1.45;"><?php echo esc_html( $mod['desc'] ); ?></p>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php $settings->card_close(); ?>
    <?php endif; ?>

</div>

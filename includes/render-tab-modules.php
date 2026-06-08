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
$admbud_has_sdk    = function_exists( 'admbud_fs' );
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

    <?php // The previous locked-Pro-cards grid was removed before WP.org
          // round 4 (the grid read as trialware-style upsell, see CLAUDE.md).
          // Pro discovery now lives as a single quiet footer line rendered
          // once by Settings::render_page() — see [class-settings.php]'s
          // .ab-wrap close — so it appears below every tab, not just here. ?>

</div>

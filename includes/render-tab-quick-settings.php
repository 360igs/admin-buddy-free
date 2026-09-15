<?php
/**
 * Quick Settings tab - single-toggle housekeeping settings.
 * Each toggle saves instantly via AJAX - no save button needed.
 *
 * @package Admbud
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Variables scoped to this included template file.

/** @var \Admbud\Settings $settings */

// Toggle config is shared with the AJAX save/bulk handlers so the UI and the
// saved key list can never drift. Single source of truth: includes/qs-sections.php.
$sections = $settings->qs_sections();

$all_keys    = [];
foreach ( $sections as $section ) {
    $all_keys = array_merge( $all_keys, array_keys( $section['items'] ) );
}
$any_enabled  = array_reduce( $all_keys, fn( $c, $k ) => $c || get_option( $k, '0' ) === '1', false );
$any_disabled = array_reduce( $all_keys, fn( $c, $k ) => $c || get_option( $k, '0' ) !== '1', false );
?>

<div id="ab-qs-wrap">

    <?php /* Enable all / Disable all */ ?>
    <div style="display:flex;gap:var(--ab-space-3);align-items:center;margin-bottom:var(--ab-space-5);">
        <button type="button" id="ab-qs-enable-all"
                class="ab-btn ab-btn--secondary ab-btn--sm"
                <?php disabled( ! $any_disabled ); ?>>
            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" class="ab-inline-icon"><polyline points="20 6 9 17 4 12"/></svg>
            <?php esc_html_e( 'Enable all', 'admin-buddy' ); ?>
        </button>
        <button type="button" id="ab-qs-disable-all"
                class="ab-btn ab-btn--secondary ab-btn--sm"
                <?php disabled( ! $any_enabled ); ?>>
            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" class="ab-inline-icon"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            <?php esc_html_e( 'Disable all', 'admin-buddy' ); ?>
        </button>
        <span id="ab-qs-spinner" class="ab-spinner ab-hidden"></span>
    </div>

    <div class="ab-sidebar-layout">
        <div class="ab-sidebar-layout__main">
            <?php foreach ( $sections as $idx => $section ) :
                $section_id = 'ab-qs-section-' . $idx;
            ?>
            <div id="<?php echo esc_attr( $section_id ); ?>">
                <?php $settings->card_open_svg( $section['icon'], $section['title'], $section['desc'] ); ?>
                <div class="ab-grid ab-grid--ruled ab-grid--qs">
        <?php foreach ( $section['items'] as $option_key => $item ) :
            $val       = get_option( $option_key, '0' );
            $has_roles = ! empty( $item['roles'] );
        ?>
        <div class="ab-qs-row<?php echo $has_roles ? ' ab-qs-row--has-roles' : ''; ?>" data-key="<?php echo esc_attr( $option_key ); ?>">
            <label class="ab-qs-row__main">
                <span class="ab-qs-row__content">
                    <span class="ab-qs-row__label"><?php echo wp_kses_post( $item['label'] ); ?><span class="ab-info-tip" tabindex="0"><svg class="ab-info-tip__icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg><span class="ab-tip"><?php echo esc_html( $item['desc'] ); ?></span></span></span>
                </span>
                <span class="ab-toggle">
                    <input type="checkbox"
                           class="ab-qs-checkbox"
                           data-key="<?php echo esc_attr( $option_key ); ?>"
                           value="1"
                           <?php checked( $val, '1' ); ?>>
                    <span class="ab-toggle__track"></span>
                    <span class="ab-toggle__thumb"></span>
                </span>
            </label>
            <?php if ( $has_roles ) :
                $saved_roles   = array_filter( explode( ',', get_option( $option_key . '_roles', 'administrator' ) ) );
                $all_wp_roles  = wp_roles()->roles;
            ?>
            <div class="ab-qs-roles<?php echo $val !== '1' ? ' ab-hidden' : ''; ?>" data-roles-for="<?php echo esc_attr( $option_key ); ?>">
                <span class="ab-qs-roles__label"><?php esc_html_e( 'Allowed roles', 'admin-buddy' ); ?></span>
                <?php
                // Each role item is ~28px tall, 2 cols, so ~14px per item.
                // 96px cap fits ~3 rows (6 items in 2 cols). Show button if more.
                $role_count    = count( $all_wp_roles );
                $needs_more    = $role_count > 6;
                $more_label    = esc_attr__( 'Show all roles', 'admin-buddy' );
                $less_label    = esc_attr__( 'Show less', 'admin-buddy' );
                ?>
                <div class="ab-qs-roles__grid<?php echo $needs_more ? ' ab-collapsible' : ''; ?>">
                    <?php foreach ( $all_wp_roles as $role_slug => $role_data ) : ?>
                    <label class="ab-qs-roles__item">
                        <input type="checkbox"
                               class="ab-qs-role-checkbox"
                               data-key="<?php echo esc_attr( $option_key ); ?>"
                               value="<?php echo esc_attr( $role_slug ); ?>"
                               <?php checked( in_array( $role_slug, $saved_roles, true ) ); ?>>
                        <?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php if ( $needs_more ) : ?>
                <button type="button"
                        class="ab-collapsible__btn"
                        data-more="<?php echo esc_attr( $more_label ); ?>"
                        data-less="<?php echo esc_attr( $less_label ); ?>">
                    <?php esc_html_e( 'Show all roles', 'admin-buddy' ); ?>
                </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php $settings->card_close(); ?>
    </div>
    <?php endforeach; ?>
        </div><!-- /.ab-sidebar-layout__main -->

        <aside class="ab-sidebar-layout__aside">
            <p class="ab-toc__label"><?php esc_html_e( 'On this page', 'admin-buddy' ); ?></p>
            <ul class="ab-toc" id="ab-qs-toc">
                <?php foreach ( $sections as $idx => $section ) : ?>
                <li class="ab-toc__item">
                    <a href="#ab-qs-section-<?php echo (int) $idx; ?>" class="ab-toc__link"><?php echo esc_html( $section['title'] ); ?></a>
                </li>
                <?php endforeach; ?>
            </ul>
        </aside>
    </div><!-- /.ab-sidebar-layout -->

</div>


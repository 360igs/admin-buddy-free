<?php
/**
 * Roles tab UI.
 *
 * Layout: role selector dropdown (top) + capability editor below.
 * Caps grouped by category with check-all per group.
 * Create / rename / clone / delete / reset via AJAX.
 *
 * @package Admbud
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Variables scoped to this included template file.
use Admbud\Roles;

$roles_obj    = \Admbud\Roles::get_instance();
$all_roles    = $roles_obj->get_all_roles();
$grouped_caps = $roles_obj->get_grouped_caps();
$nonce        = wp_create_nonce( 'admbud_roles_nonce' );
$builtin      = \Admbud\Roles::BUILTIN;
$admin_prot   = \Admbud\Roles::ADMIN_PROTECTED;

// Pre-encode all role caps for JS.
$roles_caps_json = wp_json_encode( array_map( fn($r) => $r['caps'], $all_roles ) );
?>

<div class="ab-roles-wrap">

    <?php /* -- Top toolbar: role selector + New + action buttons -- */ ?>
    <div class="ab-toolbar ab-roles-toolbar">

        <div class="ab-toolbar__left ab-roles-toolbar__left">
            <label class="ab-toolbar__label ab-roles-toolbar__label" for="ab-role-select">
                <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                <?php esc_html_e( 'Role:', 'admin-buddy' ); ?>
            </label>
            <select id="ab-role-select" class="ab-select ab-roles-role-select">
                <?php foreach ( $all_roles as $slug => $role ) : ?>
                <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $role['name'] ); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="ab-btn ab-btn--secondary ab-btn--sm" id="ab-role-new-btn">
                <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <?php esc_html_e( 'New Role', 'admin-buddy' ); ?>
            </button>
        </div>

        <div class="ab-toolbar__right ab-roles-toolbar__right">
            <?php
            $last_backup = (int) admbud_get_option( 'admbud_roles_last_backup', 0 );
            ?>
            <span id="ab-role-backup-badge" class="ab-roles-backup-badge" <?php echo $last_backup ? '' : 'style="display:none;"'; ?>>
                <?php
                if ( $last_backup ) {
                    /* translators: %s = human-readable time e.g. "2 hours ago" */
                    printf( esc_html__( 'Last backup: %s', 'admin-buddy' ), esc_html( human_time_diff( $last_backup, time() ) . ' ' . __( 'ago', 'admin-buddy' ) ) );
                }
                ?>
            </span>
            <button type="button" class="ab-btn ab-btn--sm ab-btn--secondary" id="ab-role-backup-btn"
                    title="<?php esc_attr_e( 'Download a JSON backup of all roles before making changes', 'admin-buddy' ); ?>">
                <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" class="ab-inline-icon" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                <?php esc_html_e( 'Backup Roles', 'admin-buddy' ); ?>
            </button>
            <button type="button" class="ab-btn ab-btn--sm ab-btn--secondary" id="ab-role-rename-btn">
                <?php esc_html_e( 'Rename', 'admin-buddy' ); ?>
            </button>
            <button type="button" class="ab-btn ab-btn--sm ab-btn--secondary" id="ab-role-clone-btn">
                <?php esc_html_e( 'Clone', 'admin-buddy' ); ?>
            </button>
            <button type="button" class="ab-btn ab-btn--sm ab-btn--secondary" id="ab-role-reset-btn" style="display:none;">
                <?php esc_html_e( 'Reset to defaults', 'admin-buddy' ); ?>
            </button>
            <button type="button" class="ab-btn ab-btn--sm ab-btn--danger" id="ab-role-delete-btn" style="display:none;">
                <?php esc_html_e( 'Delete role', 'admin-buddy' ); ?>
            </button>
        </div>

    </div>

    <?php /* Administrator lockout notice */ ?>
    <div id="ab-role-admin-notice" class="ab-notice ab-notice--info ab-m-0" style="margin-bottom:16px;">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" class="ab-inline-icon" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?php esc_html_e( 'Administrator capabilities are protected. Core admin caps cannot be removed to prevent lockout.', 'admin-buddy' ); ?>
    </div>

    <?php /* Cap search */ ?>
    <div class="ab-roles-search-wrap">
        <input type="text" id="ab-cap-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search capabilities…', 'admin-buddy' ); ?>">
        <button type="button" id="ab-caps-expand-all" class="ab-btn ab-btn--sm ab-btn--secondary">
            <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
            <?php esc_html_e( 'Expand All', 'admin-buddy' ); ?>
        </button>
        <button type="button" id="ab-caps-collapse-all" class="ab-btn ab-btn--sm ab-btn--secondary">
            <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="18 15 12 9 6 15"/></svg>
            <?php esc_html_e( 'Collapse All', 'admin-buddy' ); ?>
        </button>
    </div>

    <?php /* Cap groups */ ?>
    <div id="ab-cap-groups">
    <?php foreach ( $grouped_caps as $group_name => $caps ) : ?>
        <div class="ab-cap-group" data-group="<?php echo esc_attr( $group_name ); ?>">
            <div class="ab-cap-group__head">
                <label class="ab-cap-group__check-all">
                    <input type="checkbox" class="ab-group-toggle" data-group="<?php echo esc_attr( $group_name ); ?>">
                </label>
                <button type="button" class="ab-cap-group__label" aria-expanded="true">
                    <span class="ab-cap-group__title"><?php echo esc_html( $group_name ); ?></span>
                    <span class="ab-cap-group__count"></span>
                    <?php $desc = \Admbud\Roles::get_group_description( $group_name ); ?>
                    <?php if ( $desc ) : ?>
                    <span class="ab-cap-group__desc"><?php echo esc_html( $desc ); ?></span>
                    <?php endif; ?>
                    <svg class="ab-cap-group__chevron" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
            </div>
            <div class="ab-cap-group__body">
                <div class="ab-cap-grid">
                <?php foreach ( $caps as $cap ) :
                    $is_protected = in_array( $cap, $admin_prot, true );
                ?>
                    <label class="ab-cap-item" data-cap="<?php echo esc_attr( $cap ); ?>">
                        <input type="checkbox"
                            class="ab-cap-check"
                            value="<?php echo esc_attr( $cap ); ?>"
                            <?php echo $is_protected ? 'data-protected="1"' : ''; ?>>
                        <span class="ab-cap-item__name"><?php echo esc_html( $cap ); ?></span>
                        <?php if ( $is_protected ) : ?>
                            <svg class="ab-cap-item__lock" width="11" height="11" fill="currentColor" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

    <?php /* -- Save bar -- */ ?>
    <div class="ab-topbar-actions-mirror" style="display:none;">
        <button type="button" id="ab-role-save-btn" class="ab-btn ab-btn--primary" disabled>
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            <?php esc_html_e( 'Save Capabilities', 'admin-buddy' ); ?>
        </button>
    </div>

</div><!-- /.ab-roles-wrap -->

<?php /* -- Create / Clone modal -- */ ?>
<div id="ab-create-role-modal" class="ab-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="ab-create-role-title">
    <div class="ab-modal__backdrop" id="ab-create-role-backdrop"></div>
    <div class="ab-modal__box">
        <div class="ab-modal__header">
            <h3 class="ab-modal__title" id="ab-create-role-title"><?php esc_html_e( 'Create Role', 'admin-buddy' ); ?></h3>
            <button type="button" class="ab-modal__close ab-modal-close" data-modal="ab-create-role-modal" aria-label="<?php esc_attr_e( 'Close', 'admin-buddy' ); ?>">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="ab-modal__fields">
            <div class="ab-field-row">
                <label class="ab-field-label" for="ab-new-role-name"><?php esc_html_e( 'Role name', 'admin-buddy' ); ?></label>
                <input type="text" id="ab-new-role-name" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Shop Manager', 'admin-buddy' ); ?>">
            </div>
            <div id="ab-clone-from-row" class="ab-field-row" style="display:none;">
                <label class="ab-field-label" for="ab-clone-from"><?php esc_html_e( 'Copy caps from', 'admin-buddy' ); ?></label>
                <select id="ab-clone-from" class="ab-select">
                    <option value=""><?php esc_html_e( '- None (start blank) -', 'admin-buddy' ); ?></option>
                    <?php foreach ( $all_roles as $slug => $role ) : ?>
                    <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $role['name'] ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div id="ab-create-role-error" class="ab-notice ab-notice--error ab-hidden"></div>
        <div class="ab-modal__actions">
            <button type="button" class="ab-btn ab-btn--secondary ab-modal-close" data-modal="ab-create-role-modal"><?php esc_html_e( 'Cancel', 'admin-buddy' ); ?></button>
            <button type="button" id="ab-create-role-confirm" class="ab-btn ab-btn--primary"><?php esc_html_e( 'Create', 'admin-buddy' ); ?></button>
        </div>
    </div>
</div>

<?php /* -- Rename modal -- */ ?>
<div id="ab-rename-role-modal" class="ab-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="ab-rename-role-heading">
    <div class="ab-modal__backdrop" id="ab-rename-role-backdrop"></div>
    <div class="ab-modal__box">
        <div class="ab-modal__header">
            <h3 class="ab-modal__title" id="ab-rename-role-heading"><?php esc_html_e( 'Rename Role', 'admin-buddy' ); ?></h3>
            <button type="button" class="ab-modal__close ab-modal-close" data-modal="ab-rename-role-modal" aria-label="<?php esc_attr_e( 'Close', 'admin-buddy' ); ?>">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="ab-modal__fields">
            <div class="ab-field-row">
                <label class="ab-field-label" for="ab-rename-role-input"><?php esc_html_e( 'New name', 'admin-buddy' ); ?></label>
                <input type="text" id="ab-rename-role-input" class="regular-text">
            </div>
        </div>
        <div id="ab-rename-role-error" class="ab-notice ab-notice--error ab-hidden"></div>
        <div class="ab-modal__actions">
            <button type="button" class="ab-btn ab-btn--secondary ab-modal-close" data-modal="ab-rename-role-modal"><?php esc_html_e( 'Cancel', 'admin-buddy' ); ?></button>
            <button type="button" id="ab-rename-role-confirm" class="ab-btn ab-btn--primary"><?php esc_html_e( 'Rename', 'admin-buddy' ); ?></button>
        </div>
    </div>
</div>

<input type="hidden" id="ab-roles-nonce"    value="<?php echo esc_attr( $nonce ); ?>">
<input type="hidden" id="ab-roles-ajax-url" value="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
<input type="hidden" id="ab-roles-caps-data"    value="<?php echo esc_attr( $roles_caps_json ); ?>">
<input type="hidden" id="ab-roles-admin-prot"   value="<?php echo esc_attr( wp_json_encode( $admin_prot ) ); ?>">
<input type="hidden" id="ab-roles-builtin-data" value="<?php echo esc_attr( wp_json_encode( $builtin ) ); ?>">

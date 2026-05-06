<?php
/**
 * Snippets tab UI - type tabs, search, modal editor.
 *
 * @package Admbud
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Variables scoped to this included template file.
use Admbud\Snippets;

$snippets = \Admbud\Snippets::get_instance()->get_all_snippets();
$nonce    = wp_create_nonce( 'admbud_snippets_nonce' );

$type_labels = [
    'css'  => 'CSS',
    'js'   => 'JavaScript',
    'html' => 'HTML',
];
$scope_labels = [
    'global'   => __( 'Everywhere',    'admin-buddy' ),
    'frontend' => __( 'Front end',     'admin-buddy' ),
    'admin'    => __( 'Admin only',    'admin-buddy' ),
];
$type_colors = [
    'css'  => '#0284c7',
    'js'   => '#d97706',
    'html' => '#16a34a',
];

// Count per type for tab badges.
$type_counts = [
    'all'    => count( $snippets ),
    'css'    => 0,
    'js'     => 0,
    'html'   => 0,
];
$status_counts = [ 'active' => 0, 'inactive' => 0 ];
foreach ( $snippets as $s ) {
    if ( isset( $type_counts[ $s->type ] ) ) { $type_counts[ $s->type ]++; }
    if ( ! empty( $s->active ) ) { $status_counts['active']++; } else { $status_counts['inactive']++; }
}
?>

<div class="ab-snippets-wrap">

    <?php /* -- Toolbar: New + Sample + Search -- */ ?>
    <div class="ab-snippets-toolbar">

        <button type="button" class="ab-btn ab-btn--primary" id="ab-snippet-new">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <?php esc_html_e( 'New Snippet', 'admin-buddy' ); ?>
        </button>

        <div class="ab-samples-dropdown">
            <button type="button" class="ab-btn ab-btn--secondary" id="ab-samples-toggle" aria-haspopup="true" aria-expanded="false">
                <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <?php esc_html_e( 'Insert Sample', 'admin-buddy' ); ?>
                <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div id="ab-samples-menu" class="ab-samples-menu" style="display:none;">
                <?php
                $samples = [
                    'css'  => [ 'color' => '#0284c7', 'label' => 'CSS - Hide Admin Bar' ],
                    'js'   => [ 'color' => '#d97706', 'label' => 'JS - Console Hello' ],
                    'html' => [ 'color' => '#16a34a', 'label' => 'HTML - Footer Note' ],
                ];
                foreach ( $samples as $key => $s ) : ?>
                <button type="button" class="ab-sample-item" data-sample="<?php echo esc_attr( $key ); ?>">
                    <span class="ab-sample-dot" style="background:<?php echo esc_attr( $s['color'] ); ?>;"></span>
                    <?php echo esc_html( $s['label'] ); ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>

        <input type="text" id="ab-snippet-search" class="ab-snippets-search-input"
               placeholder="<?php esc_attr_e( 'Search snippets…', 'admin-buddy' ); ?>"
               aria-label="<?php esc_attr_e( 'Search snippets', 'admin-buddy' ); ?>"
               autocomplete="off" style="margin-left:4px;">

    </div>

    <?php /* -- Type tabs + Status tabs -- */ ?>
    <div class="ab-snippet-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Filter snippets', 'admin-buddy' ); ?>">

        <button type="button" class="ab-snippet-tab ab-snippet-tab--active"
                data-tab="all" role="tab" aria-selected="true">
            <?php esc_html_e( 'All', 'admin-buddy' ); ?>
            <span class="ab-snippet-tab__count"><?php echo (int) $type_counts['all']; ?></span>
        </button>

        <?php foreach ( $type_labels as $val => $label ) : ?>
        <button type="button" class="ab-snippet-tab"
                data-tab="<?php echo esc_attr( $val ); ?>"
                role="tab" aria-selected="false"
                style="--tab-color:<?php echo esc_attr( $type_colors[ $val ] ); ?>">
            <?php echo esc_html( $label ); ?>
            <span class="ab-snippet-tab__count"><?php echo (int) $type_counts[ $val ]; ?></span>
        </button>
        <?php endforeach; ?>


        <span class="ab-snippet-tabs__divider" aria-hidden="true"></span>

        <span class="ab-snippet-status-toggles">
            <label class="ab-snippet-status-toggle-label">
                <span class="ab-snippet-status-toggle-text"><?php esc_html_e( 'Active', 'admin-buddy' ); ?> <span class="ab-snippet-tab__count" id="ab-status-count-active"><?php echo (int) $status_counts['active']; ?></span></span>
                <span class="ab-toggle ab-toggle--sm">
                    <input type="checkbox" id="ab-status-show-active" checked>
                    <span class="ab-toggle__track"></span><span class="ab-toggle__thumb"></span>
                </span>
            </label>
            <label class="ab-snippet-status-toggle-label">
                <span class="ab-snippet-status-toggle-text"><?php esc_html_e( 'Inactive', 'admin-buddy' ); ?> <span class="ab-snippet-tab__count" id="ab-status-count-inactive"><?php echo (int) $status_counts['inactive']; ?></span></span>
                <span class="ab-toggle ab-toggle--sm">
                    <input type="checkbox" id="ab-status-show-inactive" checked>
                    <span class="ab-toggle__track"></span><span class="ab-toggle__thumb"></span>
                </span>
            </label>
        </span>

    </div>

    <?php /* -- Empty / no-results states -- */ ?>
    <div class="ab-snippets-empty<?php echo empty( $snippets ) ? '' : ' ab-hidden'; ?>" id="ab-snippets-empty">
        <svg width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.25" opacity="0.3"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="10" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
        <p><?php esc_html_e( 'No snippets yet. Click "New Snippet" to add your first one.', 'admin-buddy' ); ?></p>
    </div>

    <div class="ab-snippets-empty ab-hidden" id="ab-snippets-no-results">
        <svg width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.25" opacity="0.3"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <p><?php esc_html_e( 'No snippets match your search or filters.', 'admin-buddy' ); ?></p>
    </div>

    <?php /* -- Snippet list -- */ ?>
    <div class="ab-snippets-list" id="ab-snippets-list">
        <?php foreach ( $snippets as $snippet ) :
            $has_error = ! empty( $snippet->error );
        ?>
        <div class="ab-snippet-row<?php echo $has_error ? ' ab-snippet-row--error' : ''; ?>"
             data-id="<?php echo (int) $snippet->id; ?>"
             data-type="<?php echo esc_attr( $snippet->type ); ?>"
             data-active="<?php echo $snippet->active ? '1' : '0'; ?>"
             data-source-id="<?php echo esc_attr( $snippet->source_id ?? '' ); ?>"
             data-search="<?php echo esc_attr( strtolower( $snippet->title ) ); ?>">

            <div class="ab-snippet-row__toggle">
                <label class="ab-toggle" title="<?php echo $snippet->active ? esc_attr__( 'Active. Click to disable', 'admin-buddy' ) : esc_attr__( 'Inactive. Click to enable', 'admin-buddy' ); ?>">
                    <input type="hidden" value="0">
                    <input type="checkbox" class="ab-snippet-toggle" data-id="<?php echo (int) $snippet->id; ?>" <?php checked( $snippet->active, 1 ); ?>>
                    <span class="ab-toggle__track"></span><span class="ab-toggle__thumb"></span>
                </label>
            </div>

            <div class="ab-snippet-row__info">
                <div class="ab-snippet-row__title">
                    <span class="ab-snippet-type-badge" style="background:<?php echo esc_attr( $type_colors[ $snippet->type ] ?? '#64748b' ); ?>">
                        <?php echo esc_html( $type_labels[ $snippet->type ] ?? $snippet->type ); ?>
                    </span>
                    <span class="ab-snippet-row__name"><?php echo esc_html( $snippet->title ?: __( '(untitled)', 'admin-buddy' ) ); ?></span>
                </div>
                <div class="ab-snippet-row__meta">
                    <?php echo esc_html( $scope_labels[ $snippet->scope ] ?? $snippet->scope ); ?>
                    <?php if ( $snippet->type !== 'php' ) : ?>
                        &middot; <?php echo $snippet->position === 'head' ? esc_html__( 'Head', 'admin-buddy' ) : esc_html__( 'Footer', 'admin-buddy' ); ?>
                    <?php endif; ?>
                </div>
                <?php if ( $has_error ) : ?>
                <div class="ab-snippet-error-banner">
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <?php esc_html_e( 'Auto-disabled due to error:', 'admin-buddy' ); ?>
                    <code><?php echo esc_html( $snippet->error ); ?></code>
                </div>
                <?php endif; ?>
                <?php if ( ! empty( $snippet->notes ) ) : ?>
                <div class="ab-snippet-row__notes"><?php echo esc_html( $snippet->notes ); ?></div>
                <?php endif; ?>
            </div>

            <div class="ab-snippet-row__actions">
                <button type="button" class="ab-snippet-edit ab-btn ab-btn--sm" data-id="<?php echo (int) $snippet->id; ?>">
                    <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    <?php esc_html_e( 'Edit', 'admin-buddy' ); ?>
                </button>
                <button type="button" class="ab-snippet-delete ab-btn ab-btn--sm ab-btn--danger"
                        data-id="<?php echo (int) $snippet->id; ?>"
                        data-title="<?php echo esc_attr( $snippet->title ); ?>">
                    <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                    <?php esc_html_e( 'Delete', 'admin-buddy' ); ?>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

</div><!-- /.ab-snippets-wrap -->

<?php /* -- Editor slide panel - positioned fixed, outside wrap -- */ ?>
<div class="ab-backdrop" id="ab-snippet-modal-backdrop" style="display:none;" aria-hidden="true"></div>
<div class="ab-slide-panel ab-slide-panel--xl" id="ab-snippet-modal"
     role="dialog" aria-modal="true" aria-labelledby="ab-editor-title"
     style="display:none;" aria-hidden="true">

        <div class="ab-slide-panel__header">
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
            <h3 id="ab-editor-title" class="ab-slide-panel__title">
                <span id="ab-editor-title-text"><?php esc_html_e( 'New Snippet', 'admin-buddy' ); ?></span>
            </h3>
            <button type="button" class="ab-slide-panel__close ab-snippet-close" aria-label="<?php esc_attr_e( 'Close', 'admin-buddy' ); ?>">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        <div class="ab-slide-panel__body">
            <input type="hidden" id="ab-edit-id" value="0">

            <?php /* Fields area - scrollable */ ?>
            <div class="ab-snippet-modal__fields-area">

                <div class="ab-snippet-modal__fields">

                    <div class="ab-snippet-modal__field-row">
                        <label class="ab-snippet-modal__label" for="ab-edit-title"><?php esc_html_e( 'Title', 'admin-buddy' ); ?></label>
                        <input type="text" id="ab-edit-title" class="regular-text" placeholder="<?php esc_attr_e( 'Give this snippet a name…', 'admin-buddy' ); ?>">
                    </div>

                    <div class="ab-snippet-modal__field-row">
                        <label class="ab-snippet-modal__label"><?php esc_html_e( 'Type', 'admin-buddy' ); ?></label>
                        <div class="ab-snippet-type-selector">
                            <?php foreach ( $type_labels as $val => $label ) : ?>
                            <label class="ab-snippet-type-option" data-type="<?php echo esc_attr( $val ); ?>">
                                <input type="radio" name="admbud_edit_type" value="<?php echo esc_attr( $val ); ?>" <?php checked( $val, 'php' ); ?>>
                                <span style="border-color:<?php echo esc_attr( $type_colors[ $val ] ); ?>; --type-color:<?php echo esc_attr( $type_colors[ $val ] ); ?>">
                                    <?php echo esc_html( $label ); ?>
                                </span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="ab-snippet-modal__field-row ab-snippet-modal__meta-row">
                        <div class="ab-snippet-modal__meta-group">
                            <label class="ab-snippet-modal__label" for="ab-edit-scope"><?php esc_html_e( 'Scope', 'admin-buddy' ); ?></label>
                            <select id="ab-edit-scope" class="ab-select">
                                <?php foreach ( $scope_labels as $val => $label ) : ?>
                                <option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ab-snippet-modal__meta-group ab-snippet-position-row">
                            <label class="ab-snippet-modal__label" for="ab-edit-position"><?php esc_html_e( 'Position', 'admin-buddy' ); ?></label>
                            <select id="ab-edit-position" class="ab-select">
                                <option value="footer"><?php esc_html_e( 'Footer', 'admin-buddy' ); ?></option>
                                <option value="head"><?php esc_html_e( 'Head', 'admin-buddy' ); ?></option>
                            </select>
                        </div>
                        <div class="ab-snippet-modal__meta-group">
                            <label class="ab-snippet-modal__label" for="ab-edit-priority"><?php esc_html_e( 'Priority', 'admin-buddy' ); ?></label>
                            <input type="number" id="ab-edit-priority" value="10" min="1" max="999" step="1" class="ab-snippet-priority-input" style="width:68px;">
                        </div>
                    </div>

                </div>

                <div class="ab-snippet-code-wrap">
                    <div class="ab-snippet-code-label">
                        <span><?php esc_html_e( 'Notes', 'admin-buddy' ); ?></span>
                        <span class="description"><?php esc_html_e( 'Optional. Remind yourself what this snippet does', 'admin-buddy' ); ?></span>
                    </div>
                    <textarea id="ab-edit-notes" rows="2" class="ab-snippet-notes-field" aria-label="<?php esc_attr_e( 'Notes', 'admin-buddy' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Adds Google Analytics to the frontend footer…', 'admin-buddy' ); ?>"></textarea>
                </div>

            </div>

            <?php /* Code area - fills remaining height */ ?>
            <div class="ab-snippet-modal__code-area">
                <div class="ab-snippet-code-label">
                    <span><?php esc_html_e( 'Code', 'admin-buddy' ); ?></span>
                    <span id="ab-php-note" class="description" style="display:none;"><?php esc_html_e( 'omit the opening <?php tag', 'admin-buddy' ); ?></span>
                </div>
                <div class="ab-snippet-editor-wrap">
                    <textarea id="ab-edit-code" rows="20" aria-label="<?php esc_attr_e( 'Snippet code', 'admin-buddy' ); ?>" style="width:100%;font-family:monospace;"></textarea>
                </div>
                <div id="ab-snippet-syntax-error" class="ab-notice ab-notice--error" style="display:none;margin-top:8px;"></div>
            </div>

        </div>

        <div class="ab-slide-panel__footer">
            <div class="ab-snippet-modal__footer-left">
                <label class="ab-snippet-active-label">
                    <input type="checkbox" id="ab-edit-active" checked>
                    <?php esc_html_e( 'Active', 'admin-buddy' ); ?>
                </label>
            </div>
            <div class="ab-snippet-modal__actions">
                <button type="button" class="ab-btn ab-btn--secondary ab-snippet-close">
                    <?php esc_html_e( 'Cancel', 'admin-buddy' ); ?>
                </button>
                <button type="button" id="ab-snippet-save" class="ab-btn ab-btn--primary">
                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    <?php esc_html_e( 'Save Snippet', 'admin-buddy' ); ?>
                </button>
            </div>
        </div>

</div>

<input type="hidden" id="ab-snippets-nonce" value="<?php echo esc_attr( $nonce ); ?>">
<input type="hidden" id="ab-snippets-ajax-url" value="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">

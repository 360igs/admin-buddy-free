<?php
/**
 * Settings UI render helpers - cards, toggles, fields, previews.
 * Extracted from class-settings.php to reduce file size.
 *
 * @package Admbud
 */

namespace Admbud;

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait Settings_Render {

    /** Open a settings card with icon, title, optional subtitle. */
    public function card_open( string $icon, string $title, string $subtitle = '' ): void {
        ?>
        <div class="ab-section">
            <div class="ab-section__header">
                <span class="ab-section__icon"><?php echo esc_html( $icon ); ?></span>
                <div>
                    <h3><?php echo esc_html( $title ); ?></h3>
                    <?php if ( $subtitle ) : ?><p><?php echo esc_html( $subtitle ); ?></p><?php endif; ?>
                </div>
            </div>
            <div class="ab-section__body">
        <?php
    }

    /** Open a settings card with an inline SVG icon. SVG is sanitised via admbud_kses_svg(). */
    public function card_open_svg( string $svg, string $title, string $subtitle = '' ): void {
        ?>
        <div class="ab-section">
            <div class="ab-section__header">
                <span class="ab-section__icon ab-section__icon"><?php echo admbud_kses_svg( $svg ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped via admbud_kses_svg() (wp_kses with SVG ruleset) ?></span>
                <div>
                    <h3><?php echo esc_html( $title ); ?></h3>
                    <?php if ( $subtitle ) : ?><p><?php echo esc_html( $subtitle ); ?></p><?php endif; ?>
                </div>
            </div>
            <div class="ab-section__body">
        <?php
    }

    /** Close a settings card. */
    public function card_close(): void {
        echo '</div></div>';
    }

    /**
     * Render a live mini-preview of a Coming Soon / Maintenance page.
     * Uses JS-data attributes so the preview updates as pickers change without a reload.
     *
     * @param string $prefix   'cs' or 'maint' - matches the DB option prefixes.
     */
    public function render_page_preview( string $prefix, string $iframe_id = '' ): void {
        $is_maint   = ( $prefix === 'maint' );
        $iframe_id  = $iframe_id ?: ( 'ab-page-preview-' . $prefix );
        $bg_type    = admbud_get_option( "admbud_{$prefix}_bg_type",      'solid' );
        $bg_color   = esc_attr( admbud_get_option( "admbud_{$prefix}_bg_color",   Colours::DEFAULT_PAGE_BG           ) );
        $grad_from  = esc_attr( admbud_get_option( "admbud_{$prefix}_grad_from",  Colours::DEFAULT_SIDEBAR_GRAD_FROM ) );
        $grad_to    = esc_attr( admbud_get_option( "admbud_{$prefix}_grad_to",    Colours::DEFAULT_SIDEBAR_GRAD_TO   ) );
        $grad_dir   =           admbud_get_option( "admbud_{$prefix}_grad_direction", 'to bottom right' );
        $heading_c  = esc_attr( admbud_get_option( "admbud_{$prefix}_text_color",    Colours::DEFAULT_PAGE_TEXT    ) );
        $message_c  = esc_attr( admbud_get_option( "admbud_{$prefix}_message_color", Colours::DEFAULT_PAGE_MESSAGE ) );

        // Build initial background CSS string for PHP render.
        if ( $bg_type === 'gradient' ) {
            $initial_bg = "linear-gradient({$grad_dir}, {$grad_from}, {$grad_to})";
        } elseif ( $bg_type === 'image' ) {
            $img_url   = esc_url( admbud_get_option( "admbud_{$prefix}_bg_image_url", '' ) );
            $ov_color  = esc_attr( admbud_get_option( "admbud_{$prefix}_bg_overlay_color", '#000000' ) );
            $ov_op     = absint( admbud_get_option( "admbud_{$prefix}_bg_overlay_opacity", 30 ) );
            $initial_bg = $img_url
                ? "url('{$img_url}') center/cover no-repeat"
                : $bg_color;
        } else {
            $initial_bg = $bg_color;
        }

        $heading_text = esc_html( admbud_get_option( 
            $is_maint ? 'admbud_maintenance_title' : 'admbud_coming_soon_title',
            $is_maint ? __( 'Under Maintenance', 'admin-buddy' ) : __( 'Coming Soon', 'admin-buddy' )
        ) );
        $message_text = esc_html( admbud_get_option( 
            $is_maint ? 'admbud_maintenance_message' : 'admbud_coming_soon_message',
            __( 'We\'ll be back shortly.', 'admin-buddy' )
        ) );

        // Build the srcdoc - a self-contained HTML document written directly
        // into the iframe. Completely isolated from WP's DOM and JS so admin
        // notices can never bleed in.
        $srcdoc = '<!DOCTYPE html><html><head><meta charset="UTF-8">'
            . '<style>'
            . '*{box-sizing:border-box;margin:0;padding:0}'
            . 'html,body{width:100%;height:100%;}'
            . 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;'
            . 'display:flex;align-items:center;justify-content:center;'
            . 'background:' . esc_attr( $initial_bg ) . ';'
            . 'color:' . esc_attr( $heading_c ) . ';padding:1.5rem;}'
            . '.card{text-align:center;max-width:280px;}'
            . '.title{font-size:1.1rem;font-weight:700;margin-bottom:0.5rem;color:' . esc_attr( $heading_c ) . ';}'
            . '.msg{font-size:0.78rem;line-height:1.5;color:' . esc_attr( $message_c ) . ';}'
            . '</style></head><body>'
            . '<div class="card">'
            . '<div class="title">' . $heading_text . '</div>'
            . '<div class="msg">' . $message_text . '</div>'
            . '</div>'
            . '</body></html>';
        ?>
        <iframe
            id="<?php echo esc_attr( $iframe_id ); ?>"
            data-prefix="<?php echo esc_attr( $prefix ); ?>"
            srcdoc="<?php echo esc_attr( $srcdoc ); ?>"
            class="ab-maint-preview-iframe"
            style="width:100%;aspect-ratio:3/2;border-radius:8px;border:1px solid #e2e8f0;display:block;"
            scrolling="no"
            title="<?php echo esc_attr( $is_maint ? __( 'Maintenance page preview', 'admin-buddy' ) : __( 'Coming soon page preview', 'admin-buddy' ) ); ?>"
        ></iframe>
        <p class="description" style="margin-top:8px;">
            <?php esc_html_e( 'Live preview. Updates as you change settings above. Save to apply.', 'admin-buddy' ); ?>
        </p>
        <?php
    }

    /** Render a toggle row inside a table. */
    /**
     * Render a reusable image-upload table row.
     *
     * Outputs: URL input + Choose/Reset buttons on one line, optional preview
     * thumbnail, and optional width/height range sliders - all as a single <tr>.
     *
     * @param string      $url_key    Option name for the URL  (e.g. 'admbud_login_logo_url').
     * @param string      $label      Row label text.
     * @param string      $desc       Description paragraph (empty = omitted).
     * @param bool        $show_size  Whether to render Width / Height sliders.
     * @param string|null $width_key  Option name for width  (required when $show_size = true).
     * @param string|null $height_key Option name for height (required when $show_size = true).
     * @param int         $w_min      Min px for width slider.
     * @param int         $w_max      Max px for width slider.
     * @param int         $h_max      Max px for height slider (0 = auto at bottom).
     */
    public function image_upload_row(
        string  $url_key,
        string  $label,
        string  $desc       = '',
        bool    $show_size  = false,
        ?string $width_key  = null,
        ?string $height_key = null,
        int     $w_min      = 40,
        int     $w_max      = 320,
        int     $h_max      = 200
    ): void {
        $url    = esc_url( admbud_get_option( $url_key, '' ) );
        $width  = $show_size && $width_key  ? absint( admbud_get_option( $width_key,  84 ) ) : 84;
        $height = $show_size && $height_key ? absint( admbud_get_option( $height_key,  0 ) ) : 0;
        $uid    = esc_attr( $url_key ); // safe HTML id base
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr( $uid ); ?>"><?php echo esc_html( $label ); ?></label></th>
            <td>
                <?php if ( $url ) : ?>
                    <div class="ab-img-upload-preview" style="margin-bottom:8px;">
                        <img src="<?php echo esc_url( $url ); ?>" alt=""
                             style="max-height:60px;max-width:220px;object-fit:contain;display:block;
                                    border:1px solid var(--ab-neutral-200);border-radius:4px;
                                    padding:4px;background:#fff;">
                    </div>
                <?php endif; ?>

                <div class="ab-img-upload-row">
                    <input type="text"
                           id="<?php echo esc_attr( $uid ); ?>"
                           name="<?php echo esc_attr( $uid ); ?>"
                           value="<?php echo esc_attr( $url ); ?>"
                           class="regular-text"
                           placeholder="<?php esc_attr_e( 'Image URL', 'admin-buddy' ); ?>">
                    <button type="button"
                            class="ab-btn ab-btn--secondary ab-media-upload"
                            data-target="<?php echo esc_attr( $uid ); ?>">
                        <?php esc_html_e( 'Choose Image', 'admin-buddy' ); ?>
                    </button>
                    <button type="button"
                            class="ab-btn ab-btn--ghost ab-img-upload-reset<?php echo $url ? '' : ' ab-hidden'; ?>"
                            data-target="<?php echo esc_attr( $uid ); ?>">
                        <?php esc_html_e( 'Reset', 'admin-buddy' ); ?>
                    </button>
                </div>

                <?php if ( $show_size && $width_key && $height_key ) :
                    $w_id = esc_attr( $url_key . '_w_val' );
                    $h_id = esc_attr( $url_key . '_h_val' );
                ?>
                <div class="ab-img-size-sliders">
                    <div class="ab-img-size-row">
                        <label for="<?php echo esc_attr( $width_key ); ?>"><?php esc_html_e( 'Width', 'admin-buddy' ); ?></label>
                        <input type="range"
                               class="ab-range-display"
                               data-display="<?php echo esc_attr( $w_id ); ?>"
                               data-suffix="px"
                               id="<?php echo esc_attr( $width_key ); ?>"
                               name="<?php echo esc_attr( $width_key ); ?>"
                               min="<?php echo esc_attr( $w_min ); ?>"
                               max="<?php echo esc_attr( $w_max ); ?>"
                               step="4"
                               value="<?php echo esc_attr( $width ); ?>">
                        <span id="<?php echo esc_attr( $w_id ); ?>"><?php echo esc_html( $width ); ?>px</span>
                    </div>
                    <div class="ab-img-size-row">
                        <label for="<?php echo esc_attr( $height_key ); ?>"><?php esc_html_e( 'Height', 'admin-buddy' ); ?></label>
                        <input type="range"
                               class="ab-range-display"
                               data-display="<?php echo esc_attr( $h_id ); ?>"
                               data-suffix="px"
                               data-zero-label="Auto"
                               id="<?php echo esc_attr( $height_key ); ?>"
                               name="<?php echo esc_attr( $height_key ); ?>"
                               min="0"
                               max="<?php echo esc_attr( $h_max ); ?>"
                               step="4"
                               value="<?php echo esc_attr( $height ); ?>">
                        <span id="<?php echo esc_attr( $h_id ); ?>"><?php echo $height === 0 ? 'Auto' : esc_html( $height ) . 'px'; ?></span>
                    </div>
                </div>
                <p class="description"><?php esc_html_e( 'Height 0 = auto (proportional to width).', 'admin-buddy' ); ?></p>
                <?php endif; ?>


                <?php if ( $desc ) : ?>
                    <p class="description"><?php echo esc_html( $desc ); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    public function toggle_row( string $key, string $label, string $desc, string $default = '1', string $id = '' ): void {
        $checked = admbud_get_option( $key, $default ) === '1';
        $id      = $id ?: $key;

        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
            <td>
                <label class="ab-toggle">
                    <input type="hidden"   name="<?php echo esc_attr( $key ); ?>" value="0">
                    <input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1"
                           id="<?php echo esc_attr( $id ); ?>" <?php checked( $checked ); ?>
                           aria-describedby="<?php echo esc_attr( $id ); ?>-desc">
                    <span class="ab-toggle__track"></span><span class="ab-toggle__thumb"></span>
                </label>
                <?php if ( $desc ) : ?>
                    <p class="description" id="<?php echo esc_attr( $id ); ?>-desc"><?php echo esc_html( $desc ); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * Render a text input table row.
     */
    public function text_field_row(
        string $key,
        string $label,
        string $desc        = '',
        string $placeholder = '',
        string $default     = ''
    ): void {
        $value = esc_attr( admbud_get_option( $key, $default ) );
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
            <td>
                <input type="text"
                       id="<?php echo esc_attr( $key ); ?>"
                       name="<?php echo esc_attr( $key ); ?>"
                       value="<?php echo esc_attr( $value ); ?>"
                       class="regular-text"
                       placeholder="<?php echo esc_attr( $placeholder ); ?>">
                <?php if ( $desc ) : ?>
                    <p class="description"><?php echo esc_html( $desc ); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * Render a URL input table row.
     */
    public function url_field_row(
        string $key,
        string $label,
        string $desc        = '',
        string $placeholder = 'https://'
    ): void {
        $value = esc_url( admbud_get_option( $key, '' ) );
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
            <td>
                <input type="url"
                       id="<?php echo esc_attr( $key ); ?>"
                       name="<?php echo esc_attr( $key ); ?>"
                       value="<?php echo esc_attr( $value ); ?>"
                       class="regular-text"
                       placeholder="<?php echo esc_attr( $placeholder ); ?>">
                <?php if ( $desc ) : ?>
                    <p class="description"><?php echo esc_html( $desc ); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * Render a colour picker table row.
     *
     * @param string $key     Option key (e.g. 'admbud_colours_content_heading').
     * @param string $label   Human-readable label.
     * @param string $desc    Description text below the picker.
     * @param string $default Default hex value for data-default-color.
     */
    public function colour_field(
        string $key,
        string $label,
        string $desc    = '',
        string $default = '#000000'
    ): void {
        $saved      = admbud_get_option( $key, '' );
        $has_saved  = ( $saved !== '' && preg_match( '/^#[0-9a-fA-F]{6}$/', $saved ) );
        $active_val = $has_saved ? $saved : $default;
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
            <td>
                <input type="color"
                       id="<?php echo esc_attr( $key ); ?>"
                       name="<?php echo esc_attr( $key ); ?>"
                       value="<?php echo esc_attr( $active_val ); ?>"
                       class="ab-native-color-picker"
                       data-default-color="<?php echo esc_attr( $default ); ?>"
                       <?php echo $has_saved ? '' : 'data-is-default="1"'; ?>>
                <?php if ( $desc ) : ?>
                    <p class="description"><?php echo esc_html( $desc ); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }
}

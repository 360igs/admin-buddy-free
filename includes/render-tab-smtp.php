<?php
/**
 * SMTP tab UI - included by Settings::render_tab_smtp().
 *
 * Subtabs: Settings | Email Log
 *
 * @package Admbud
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Variables scoped to this included template file.
use Admbud\SMTP;

$smtp      = \Admbud\SMTP::get_instance();
$presets   = \Admbud\SMTP::get_presets();
$log       = $smtp->get_log();
$nonce     = wp_create_nonce( 'admbud_smtp_nonce' );

$enabled    = admbud_get_option( 'admbud_smtp_enabled',    '0' ) === '1';
$mailer     = admbud_get_option( 'admbud_smtp_mailer',     'smtp' );
$host       = admbud_get_option( 'admbud_smtp_host',       '' );
$port       = admbud_get_option( 'admbud_smtp_port',       587 );
$username   = admbud_get_option( 'admbud_smtp_username',   '' );
$encryption = admbud_get_option( 'admbud_smtp_encryption', 'tls' );
$auth       = admbud_get_option( 'admbud_smtp_auth',       '1' ) === '1';
$from_name  = admbud_get_option( 'admbud_smtp_from_name',  get_bloginfo( 'name' ) );
$from_email = admbud_get_option( 'admbud_smtp_from_email', get_option( 'admin_email' ) );
$fallback   = admbud_get_option( 'admbud_smtp_fallback',   '0' ) === '1';
$preset     = admbud_get_option( 'admbud_smtp_preset',     'custom' );
$pass_set   = ! empty( get_option( \Admbud\SMTP::PASS_OPTION, '' ) );

$subtabs = [
    'settings' => __( 'Settings',  'admin-buddy' ),
    'log'      => __( 'Email Log', 'admin-buddy' ),
];
$active_sub = \Admbud\Settings::get_active_subtab( 'smtp', 'settings' );
$active_sub = array_key_exists( $active_sub, $subtabs ) ? $active_sub : 'settings';
?>

<input type="hidden" name="admbud_subtab" id="ab-smtp-subtab-field" value="<?php echo esc_attr( $active_sub ); ?>">

<div class="ab-subnav" data-subtab-field="#ab-smtp-subtab-field" data-panel-prefix="smtp-">
    <?php foreach ( $subtabs as $slug => $label ) : ?>
    <button type="button"
            class="ab-subnav__item<?php echo $active_sub === $slug ? ' is-active' : ''; ?>"
            data-panel="<?php echo esc_attr( $slug ); ?>">
        <?php echo esc_html( $label ); ?>
        <?php if ( $slug === 'log' && ! empty( $log ) ) : ?>
            <span class="ab-snippet-tab__count" style="margin-left:4px;"><?php echo count( $log ); ?></span>
        <?php endif; ?>
    </button>
    <?php endforeach; ?>
</div>

<!-- -- Settings panel -------------------------------------------- -->
<div class="ab-pane<?php echo $active_sub !== 'settings' ? ' ab-hidden' : ''; ?>"
     id="ab-pane-smtp-settings">

<form method="post" action="options.php" id="ab-smtp-form">
    <?php settings_fields( 'admbud_smtp_group' ); ?>
    <input type="hidden" name="admbud_tab" value="smtp">

    <?php
    $icon_mail = '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>';
    $this->card_open_svg( $icon_mail, __( 'Mail Settings', 'admin-buddy' ), __( 'Control how WordPress sends email.', 'admin-buddy' ) );
    ?>
    <table class="form-table ab-form-table" role="presentation">
        <tr>
            <th><?php esc_html_e( 'Enable Custom Mail', 'admin-buddy' ); ?></th>
            <td>
                <label class="ab-toggle">
                    <input type="checkbox" name="admbud_smtp_enabled" value="1" id="ab-smtp-enabled" <?php checked( $enabled ); ?>>
                    <span class="ab-toggle__track"></span><span class="ab-toggle__thumb"></span>
                </label>
                <p class="description"><?php esc_html_e( 'When disabled, WordPress uses its built-in PHP mail behaviour.', 'admin-buddy' ); ?></p>
            </td>
        </tr>
        <tr id="ab-mailer-row">
            <th><?php esc_html_e( 'Mailer', 'admin-buddy' ); ?></th>
            <td>
                <div class="ab-snippet-type-selector">
                    <label class="ab-snippet-type-option">
                        <input type="radio" name="admbud_smtp_mailer" value="smtp" <?php checked( $mailer, 'smtp' ); ?> id="ab-mailer-smtp">
                        <span style="border-color:#7c3aed;--type-color:#7c3aed">SMTP</span>
                    </label>
                    <label class="ab-snippet-type-option">
                        <input type="radio" name="admbud_smtp_mailer" value="phpmail" <?php checked( $mailer, 'phpmail' ); ?> id="ab-mailer-phpmail">
                        <span style="border-color:#0284c7;--type-color:#0284c7">PHP Mail</span>
                    </label>
                </div>
                <p class="description" id="ab-phpmail-note" <?php echo $mailer !== 'phpmail' ? 'style="display:none"' : ''; ?>>
                    <?php esc_html_e( 'Uses PHP\'s built-in mail() function. From name/email below still apply. No host/port/auth needed.', 'admin-buddy' ); ?>
                </p>
            </td>
        </tr>
    </table>
    <?php $this->card_close(); ?>

    <?php
    $icon_user = '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
    $this->card_open_svg( $icon_user, __( 'From Address', 'admin-buddy' ), __( 'The name and email that appear in the From field of every outgoing email.', 'admin-buddy' ) );
    ?>
    <table class="form-table ab-form-table" role="presentation">
        <tr>
            <th><label for="admbud_smtp_from_name"><?php esc_html_e( 'From Name', 'admin-buddy' ); ?></label></th>
            <td><input type="text" name="admbud_smtp_from_name" id="admbud_smtp_from_name" value="<?php echo esc_attr( $from_name ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"></td>
        </tr>
        <tr>
            <th><label for="admbud_smtp_from_email"><?php esc_html_e( 'From Email', 'admin-buddy' ); ?></label></th>
            <td>
                <input type="email" name="admbud_smtp_from_email" id="admbud_smtp_from_email" value="<?php echo esc_attr( $from_email ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
                <p class="description"><?php esc_html_e( 'Use an address from a domain you own for best deliverability.', 'admin-buddy' ); ?></p>
            </td>
        </tr>
    </table>
    <?php $this->card_close(); ?>

    <?php
    $icon_server = '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>';
    ?>
    <div id="ab-smtp-server-card" <?php echo $mailer === 'phpmail' ? 'style="display:none"' : ''; ?>>
    <?php $this->card_open_svg( $icon_server, __( 'SMTP Server', 'admin-buddy' ), __( 'Connection details for your outgoing mail server.', 'admin-buddy' ) ); ?>
    <table class="form-table ab-form-table" role="presentation">
        <tr>
            <th><label for="admbud_smtp_host"><?php esc_html_e( 'SMTP Host', 'admin-buddy' ); ?></label></th>
            <td><input type="text" name="admbud_smtp_host" id="admbud_smtp_host" value="<?php echo esc_attr( $host ); ?>" class="regular-text" placeholder="smtp.example.com"></td>
        </tr>
        <tr>
            <th><label for="admbud_smtp_port"><?php esc_html_e( 'SMTP Port', 'admin-buddy' ); ?></label></th>
            <td>
                <input type="number" name="admbud_smtp_port" id="admbud_smtp_port" value="<?php echo esc_attr( $port ); ?>" style="width:100px;" min="1" max="65535">
                <p class="description"><?php esc_html_e( 'Common: 587 (TLS), 465 (SSL), 25 (none, not recommended).', 'admin-buddy' ); ?></p>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Encryption', 'admin-buddy' ); ?></th>
            <td>
                <select name="admbud_smtp_encryption" id="admbud_smtp_encryption" class="ab-select">
                    <option value="tls" <?php selected( $encryption, 'tls' ); ?>>TLS</option>
                    <option value="ssl" <?php selected( $encryption, 'ssl' ); ?>>SSL</option>
                    <option value="" <?php selected( $encryption, '' ); ?>><?php esc_html_e( 'None', 'admin-buddy' ); ?></option>
                </select>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Authentication', 'admin-buddy' ); ?></th>
            <td>
                <label class="ab-toggle">
                    <input type="hidden" name="admbud_smtp_auth" value="0">
                    <input type="checkbox" name="admbud_smtp_auth" value="1" id="ab-smtp-auth" <?php checked( $auth ); ?>>
                    <span class="ab-toggle__track"></span><span class="ab-toggle__thumb"></span>
                </label>
                <p class="description"><?php esc_html_e( 'Most providers require authentication. Only disable if your server uses IP-based trust.', 'admin-buddy' ); ?></p>
            </td>
        </tr>
        <tr id="ab-smtp-user-row" <?php echo ! $auth ? 'style="display:none"' : ''; ?>>
            <th><label for="admbud_smtp_username"><?php esc_html_e( 'Username', 'admin-buddy' ); ?></label></th>
            <td><input type="text" name="admbud_smtp_username" id="admbud_smtp_username" value="<?php echo esc_attr( $username ); ?>" class="regular-text" autocomplete="off"></td>
        </tr>
        <tr id="ab-smtp-pass-row" <?php echo ! $auth ? 'style="display:none"' : ''; ?>>
            <th><label for="admbud_smtp_password"><?php esc_html_e( 'Password / App Key', 'admin-buddy' ); ?></label></th>
            <td>
                <input type="password" name="admbud_smtp_password" id="admbud_smtp_password" class="regular-text" autocomplete="new-password"
                       placeholder="<?php echo $pass_set ? esc_attr__( '••••••• (saved)', 'admin-buddy' ) : ''; ?>">
                <p class="description"><?php esc_html_e( 'Stored encrypted. Leave blank to keep the existing password.', 'admin-buddy' ); ?></p>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Fallback to PHP Mail', 'admin-buddy' ); ?></th>
            <td>
                <label class="ab-toggle">
                    <input type="checkbox" name="admbud_smtp_fallback" value="1" <?php checked( $fallback ); ?>>
                    <span class="ab-toggle__track"></span><span class="ab-toggle__thumb"></span>
                </label>
                <p class="description"><?php esc_html_e( 'If SMTP delivery fails, fall back to PHP mail() as a last resort.', 'admin-buddy' ); ?></p>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Disable SSL Verification', 'admin-buddy' ); ?></th>
            <td>
                <?php $disable_ssl_verify = admbud_get_option( 'admbud_smtp_disable_ssl_verify', '0' ) === '1'; ?>
                <label class="ab-toggle">
                    <input type="hidden" name="admbud_smtp_disable_ssl_verify" value="0">
                    <input type="checkbox" name="admbud_smtp_disable_ssl_verify" value="1" <?php checked( $disable_ssl_verify ); ?>>
                    <span class="ab-toggle__track"></span><span class="ab-toggle__thumb"></span>
                </label>
                <?php if ( $disable_ssl_verify ) : ?>
                <div class="ab-notice ab-notice--error" style="margin-top:10px;">
                    <strong><?php esc_html_e( '⚠ Security risk active', 'admin-buddy' ); ?></strong>:
                    <?php esc_html_e( 'SSL peer verification is disabled. Your connection to the SMTP server is vulnerable to man-in-the-middle attacks. Only use this on a local/staging environment or as a last resort when your host uses a self-signed certificate.', 'admin-buddy' ); ?>
                </div>
                <?php else : ?>
                <p class="description"><?php esc_html_e( 'Disables SSL certificate verification and allows self-signed certificates. This is a security risk. Only enable on local or staging environments, or if your SMTP host uses a self-signed certificate.', 'admin-buddy' ); ?></p>
                <?php endif; ?>
            </td>
        </tr>
    </table>
    <?php $this->card_close(); ?>
    </div><!-- /#ab-smtp-server-card -->

    <button type="submit" class="ab-form-save-btn ab-btn--disabled-until-change" style="display:none;" aria-hidden="true" disabled></button>
</form>

<?php
$icon_send = '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>';
$this->card_open_svg( $icon_send, __( 'Send Test Email', 'admin-buddy' ), __( 'Verify your configuration by sending a test message right now.', 'admin-buddy' ) );
?>
<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
    <div>
        <label for="ab-smtp-test-to" style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Send to', 'admin-buddy' ); ?></label>
        <input type="email" id="ab-smtp-test-to" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" class="regular-text">
    </div>
    <button type="button" id="ab-smtp-test-btn" class="ab-btn ab-btn--primary">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
        <?php esc_html_e( 'Send Test', 'admin-buddy' ); ?>
    </button>
    <span id="ab-smtp-test-result" style="font-size:0.875rem;"></span>
</div>
<?php $this->card_close(); ?>

</div><!-- /.ab-pane (settings) -->

<!-- -- Email Log panel ------------------------------------------- -->
<div class="ab-pane<?php echo $active_sub !== 'log' ? ' ab-hidden' : ''; ?>"
     id="ab-pane-smtp-log">

<?php
$icon_log = '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>';
			/* translators: %d: email log limit number */
$this->card_open_svg( $icon_log, __( 'Email Log', 'admin-buddy' ), sprintf( __( 'Last %d emails sent or attempted by WordPress.', 'admin-buddy' ), \Admbud\SMTP::LOG_LIMIT ) );
?>
<?php if ( empty( $log ) ) : ?>
    <p class="description"><?php esc_html_e( 'No emails logged yet.', 'admin-buddy' ); ?></p>
<?php else : ?>
    <div style="display:flex;justify-content:flex-end;margin-bottom:10px;">
        <button type="button" id="ab-smtp-clear-log" class="ab-btn ab-btn--secondary ab-btn--sm">
            <?php esc_html_e( 'Clear Log', 'admin-buddy' ); ?>
        </button>
    </div>
    <table class="widefat striped ab-smtp-log-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Time',    'admin-buddy' ); ?></th>
                <th><?php esc_html_e( 'To',      'admin-buddy' ); ?></th>
                <th><?php esc_html_e( 'Subject', 'admin-buddy' ); ?></th>
                <th><?php esc_html_e( 'Status',  'admin-buddy' ); ?></th>
            </tr>
        </thead>
        <tbody id="ab-smtp-log-body">
        <?php foreach ( $log as $i => $entry ) :
            $is_fail = ( $entry['status'] ?? '' ) === 'failed';
            $entry_json = esc_attr( wp_json_encode( $entry ) );
        ?>
            <tr class="ab-log-row" data-entry="<?php echo $entry_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped via esc_attr() on L262 ?>" style="cursor:pointer;" title="<?php esc_attr_e( 'Click to view full email', 'admin-buddy' ); ?>">
                <td style="white-space:nowrap;color:var(--ab-neutral-600);font-size:0.8rem;"><?php echo esc_html( $entry['time'] ?? '' ); ?></td>
                <td style="font-size:0.85rem;"><?php echo esc_html( $entry['to'] ?? '' ); ?></td>
                <td style="font-size:0.85rem;"><?php echo esc_html( $entry['subject'] ?? '' ); ?></td>
                <td>
                    <?php if ( $is_fail ) : ?>
                        <span class="ab-badge" style="background:var(--ab-error-bg);color:var(--ab-error-text);"><?php esc_html_e( 'Failed', 'admin-buddy' ); ?></span>
                    <?php else : ?>
                        <span class="ab-badge" style="background:var(--ab-success-bg);color:var(--ab-success-text);"><?php esc_html_e( 'Sent', 'admin-buddy' ); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="ab-backdrop" id="ab-email-panel-backdrop" style="display:none;" aria-hidden="true"></div>
    <div class="ab-slide-panel ab-slide-panel--lg" id="ab-email-panel" role="dialog" aria-modal="true" aria-labelledby="ab-email-panel-subject" style="display:none;" aria-hidden="true">
        <div class="ab-slide-panel__header">
            <h3 class="ab-slide-panel__title" id="ab-email-panel-subject"></h3>
            <button type="button" id="ab-email-panel-close" class="ab-slide-panel__close" aria-label="<?php esc_attr_e( 'Close email detail', 'admin-buddy' ); ?>">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="ab-slide-panel__body">
            <div class="ab-email-panel__meta" id="ab-email-panel-meta"></div>
            <div class="ab-email-panel__status" id="ab-email-panel-status"></div>
            <div class="ab-email-panel__message" id="ab-email-panel-body"></div>
            <div class="ab-email-panel__error" id="ab-email-panel-error" style="display:none;"></div>
        </div>
    </div>
<?php endif; ?>
<?php $this->card_close(); ?>

</div><!-- /.ab-pane (log) -->

<input type="hidden" id="ab-smtp-nonce" value="<?php echo esc_attr( $nonce ); ?>">
<input type="hidden" id="ab-smtp-ajax-url" value="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">

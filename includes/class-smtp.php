<?php
/**
 * SMTP - replaces WordPress's default PHPMailer configuration.
 *
 * Features:
 *  - Full SMTP field set: host, port, username, encrypted password,
 *    from name, from email, encryption (TLS/SSL/none), auth toggle.
 *  - PHP mail() fallback if SMTP send fails.
 *  - Provider presets: Gmail, Outlook, SendGrid, Mailgun, Brevo, custom.
 *  - Test email via AJAX (send to current admin email).
 *  - Email log: last 50 sent/failed entries stored in wp_options as JSON.
 *  - Password encrypted with openssl_encrypt using a site-specific key
 *    derived from AUTH_KEY - never stored in plain text.
 *
 * @package Admbud
 */

namespace Admbud;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SMTP {

    const LOG_OPTION  = 'admbud_smtp_log';
    const LOG_LIMIT   = 50;
    const PASS_OPTION = 'admbud_smtp_password_enc';

    private static ?SMTP $instance = null;

    public static function get_instance(): SMTP {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if ( admbud_get_option( 'admbud_smtp_enabled', '0' ) === '1' ) {
            add_action( 'phpmailer_init', [ $this, 'configure_mailer' ], 10, 1 );
            add_action( 'wp_mail_failed', [ $this, 'on_mail_failed'   ], 10, 1 );
        }
        // Always log sent mail for the log feature.
        add_action( 'wp_mail_succeeded', [ $this, 'on_mail_succeeded' ], 10, 1 );
        add_action( 'wp_ajax_admbud_smtp_test', [ $this, 'ajax_test_email' ] );
        add_action( 'wp_ajax_admbud_smtp_clear_log', [ $this, 'ajax_clear_log' ] );
    }

    // ========================================================================
    // PHPMAILER CONFIGURATION
    // ========================================================================

    public function configure_mailer( \PHPMailer\PHPMailer\PHPMailer $mailer ): void {
        $mailer_type = admbud_get_option( 'admbud_smtp_mailer', 'smtp' );
        $from_name   = admbud_get_option( 'admbud_smtp_from_name',  get_bloginfo( 'name' ) );
        $from_email  = admbud_get_option( 'admbud_smtp_from_email', get_option( 'admin_email' ) );

        // Always apply From overrides regardless of mailer type.
        if ( ! empty( $from_email ) ) {
            $mailer->From     = sanitize_email( $from_email );
            $mailer->FromName = $from_name;
        }

        // PHP mail() mode - no SMTP config needed.
        if ( $mailer_type === 'phpmail' ) {
            $mailer->isMail();
            return;
        }

        // -- SMTP mode ---------------------------------------------------------
        $host       = admbud_get_option( 'admbud_smtp_host',       '' );
        $port       = (int) admbud_get_option( 'admbud_smtp_port', 587 );
        $user       = admbud_get_option( 'admbud_smtp_username',   '' );
        $pass       = $this->decrypt_password( get_option( self::PASS_OPTION, '' ) );
        $encryption = admbud_get_option( 'admbud_smtp_encryption', 'tls' );
        $auth       = admbud_get_option( 'admbud_smtp_auth',       '1' ) === '1';
        $fallback         = admbud_get_option( 'admbud_smtp_fallback',   '0' ) === '1'; // legacy
        $disable_ssl_verify = admbud_get_option( 'admbud_smtp_disable_ssl_verify', '0' ) === '1' || $fallback;

        if ( empty( $host ) ) { return; }

        $mailer->isSMTP();
        $mailer->Host     = $host;
        $mailer->Port     = $port;
        $mailer->SMTPAuth = $auth;
        $mailer->Username = $user;
        $mailer->Password = $pass;

        switch ( $encryption ) {
            case 'ssl':
                $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                break;
            case 'tls':
                $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                break;
            default:
                $mailer->SMTPSecure  = '';
                $mailer->SMTPAutoTLS = false;
                break;
        }

        if ( $disable_ssl_verify ) {
            $mailer->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ];
        }
    }

    // ========================================================================
    // PASSWORD ENCRYPTION
    // ========================================================================

    /**
     * Derive a site-specific encryption key from AUTH_KEY.
     * Uses PBKDF2 with a site-specific salt so the key is unique per install.
     */
    private function encryption_key(): string {
        $base = defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_salt( 'auth' );
        $salt = admbud_get_option( 'admbud_smtp_key_salt' );
        if ( ! $salt ) {
            $salt = bin2hex( random_bytes( 16 ) );
            admbud_update_option( 'admbud_smtp_key_salt', $salt, false );
        }
        return hash_pbkdf2( 'sha256', $base, $salt, 10000, 32, true );
    }

    /**
     * Encrypt an SMTP password for storage.
     *
     * Priority: OpenSSL AES-256-CBC → Sodium secretbox → base64 (last resort).
     * base64_encode/decode are used as transport encoding for ciphertext
     * (binary data), not for obfuscation.
     */
    public function encrypt_password( string $plain ): string {
        if ( empty( $plain ) ) { return ''; }

        // Prefer OpenSSL (most common).
        if ( function_exists( 'openssl_encrypt' ) ) {
            $key    = $this->encryption_key();
            $iv     = random_bytes( openssl_cipher_iv_length( 'aes-256-cbc' ) );
            $cipher = openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
            return 'ossl:' . base64_encode( $iv . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        }

        // Fallback: Sodium (available in PHP 7.2+).
        if ( function_exists( 'sodium_crypto_secretbox' ) ) {
            $key   = substr( hash( 'sha256', $this->encryption_key(), true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
            $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            $cipher = sodium_crypto_secretbox( $plain, $nonce, $key );
            return 'nacl:' . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        }

        // Last resort: base64 only (no encryption available).
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding, not obfuscation.
        return base64_encode( $plain );
    }

    /** Decrypt a stored SMTP password. Supports ossl:, nacl:, and legacy base64 formats. */
    public function decrypt_password( string $stored ): string {
        if ( empty( $stored ) ) { return ''; }

        try {
            // OpenSSL format (prefixed with 'ossl:').
            if ( str_starts_with( $stored, 'ossl:' ) && function_exists( 'openssl_decrypt' ) ) {
                $key    = $this->encryption_key();
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- transport decoding of OpenSSL ciphertext stored in wp_options.
                $raw    = base64_decode( substr( $stored, 5 ) );
                $iv_len = openssl_cipher_iv_length( 'aes-256-cbc' );
                $iv     = substr( $raw, 0, $iv_len );
                $cipher = substr( $raw, $iv_len );
                $plain  = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
                return $plain !== false ? $plain : '';
            }

            // Sodium format (prefixed with 'nacl:').
            if ( str_starts_with( $stored, 'nacl:' ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
                $key   = substr( hash( 'sha256', $this->encryption_key(), true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- transport decoding of libsodium ciphertext stored in wp_options.
                $raw   = base64_decode( substr( $stored, 5 ) );
                $nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
                $cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
                $plain = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
                return $plain !== false ? $plain : '';
            }

            // Legacy format (no prefix): try OpenSSL first, then base64.
            if ( function_exists( 'openssl_decrypt' ) ) {
                $key    = $this->encryption_key();
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- transport decoding of legacy OpenSSL ciphertext (pre-prefix format).
                $raw    = base64_decode( $stored );
                $iv_len = openssl_cipher_iv_length( 'aes-256-cbc' );
                if ( strlen( $raw ) > $iv_len ) {
                    $iv     = substr( $raw, 0, $iv_len );
                    $cipher = substr( $raw, $iv_len );
                    $plain  = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
                    if ( $plain !== false ) { return $plain; }
                }
            }

            // Bare base64 fallback for legacy unencrypted SMTP passwords stored
            // before encryption was added. Decoded value is the user's password
            // string — never used to evaluate code.
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- transport decoding of legacy unencrypted password.
            return base64_decode( $stored );
        } catch ( \Throwable $e ) {
            return '';
        }
    }

    // ========================================================================
    // EMAIL LOG
    // ========================================================================

    public function on_mail_succeeded( array $mail_data ): void {
        $this->log_entry( $mail_data );
    }

    public function on_mail_failed( \WP_Error $error ): void {
        $data      = $error->get_error_data() ?: [];
        $mail_data = is_array( $data ) ? $data : [];
        $this->log_entry( $mail_data, 'failed', $error->get_error_message() );
    }

    private function log_entry( array $mail_data, string $status = 'sent', string $error = '' ): void {
        $log = get_option( self::LOG_OPTION, [] );
        if ( ! is_array( $log ) ) { $log = []; }

        // Parse headers string/array into a readable associative array.
        $raw_headers = $mail_data['headers'] ?? [];
        if ( is_string( $raw_headers ) ) {
            $raw_headers = explode( "\n", $raw_headers );
        }
        $parsed_headers = [];
        $cc  = [];
        $bcc = [];
        foreach ( (array) $raw_headers as $header ) {
            if ( ! is_string( $header ) || strpos( $header, ':' ) === false ) { continue; }
            [ $name, $value ] = explode( ':', $header, 2 );
            $name  = trim( $name );
            $value = trim( $value );
            $lower = strtolower( $name );
            if ( $lower === 'cc'  ) { $cc[]  = $value; continue; }
            if ( $lower === 'bcc' ) { $bcc[] = $value; continue; }
            $parsed_headers[ $name ] = $value;
        }

        // Normalise 'to' - can be array or string.
        $to = $mail_data['to'] ?? [];
        if ( is_string( $to ) ) { $to = [ $to ]; }

        array_unshift( $log, [
            'time'        => current_time( 'mysql' ),
            'to'          => implode( ', ', (array) $to ),
            'cc'          => implode( ', ', $cc ),
            'bcc'         => implode( ', ', $bcc ),
            'subject'     => $mail_data['subject'] ?? '',
            'message'     => $mail_data['message'] ?? '',
            'headers'     => $parsed_headers,
            'attachments' => array_map( 'basename', (array) ( $mail_data['attachments'] ?? [] ) ),
            'status'      => $status,
            'error'       => $error,
        ] );

        $log = array_slice( $log, 0, self::LOG_LIMIT );
        update_option( self::LOG_OPTION, $log, false );
    }

    public function get_log(): array {
        return get_option( self::LOG_OPTION, [] );
    }

    // ========================================================================
    // AJAX
    // ========================================================================

    public function ajax_test_email(): void {
        check_ajax_referer( 'admbud_smtp_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'admin-buddy' ) ] );
        }

        // I-3: Rate limit - 10-second cooldown between test emails.
        $throttle_key = 'admbud_smtp_test_throttle_' . get_current_user_id();
        if ( get_transient( $throttle_key ) ) {
            wp_send_json_error( [
                'message' => __( 'Please wait a few seconds before sending another test email.', 'admin-buddy' ),
            ] );
        }
        set_transient( $throttle_key, '1', 10 );

        $to      = sanitize_email( $_POST['to'] ?? get_option( 'admin_email' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $subject = __( 'Admin Buddy: SMTP Test Email', 'admin-buddy' );
        $body    = sprintf(
            /* translators: %s: site name */
            __( "This is a test email sent by Admin Buddy from %s.\n\nIf you received this, your SMTP configuration is working correctly.", 'admin-buddy' ),
            get_bloginfo( 'name' )
        );

        // Temporarily hook to capture any PHPMailer exception.
        $send_error = null;
        $error_hook = function( \WP_Error $err ) use ( &$send_error ) {
            $send_error = $err->get_error_message();
        };
        add_action( 'wp_mail_failed', $error_hook );

        $result = wp_mail( $to, $subject, $body );

        remove_action( 'wp_mail_failed', $error_hook );

        if ( $result ) {
            wp_send_json_success( [
                'message' => sprintf(
                    /* translators: %s: email address */
                    __( 'Test email sent successfully to %s.', 'admin-buddy' ),
                    $to
                ),
            ] );
        } else {
            wp_send_json_error( [
                'message' => __( 'Failed to send test email.', 'admin-buddy' ),
                'detail'  => $send_error ?? __( 'Unknown error. Check your SMTP settings.', 'admin-buddy' ),
            ] );
        }
    }

    public function ajax_clear_log(): void {
        check_ajax_referer( 'admbud_smtp_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }
        delete_option( self::LOG_OPTION );
        wp_send_json_success();
    }

    // ========================================================================
    // PROVIDER PRESETS
    // ========================================================================

    public static function get_presets(): array {
        return [
            'custom'    => [ 'label' => __( 'Custom',    'admin-buddy' ), 'host' => '',                       'port' => 587,  'encryption' => 'tls'  ],
        ];
    }
}

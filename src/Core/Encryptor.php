<?php
/**
 * Credential encryptor.
 *
 * Provides AES-256-CBC encryption / decryption for sensitive config values
 * (currently the OpenCart DB password) stored in wp_options.
 *
 * Encrypted values carry a version prefix ("octowoo_enc1::") so the code
 * can transparently handle both encrypted and legacy plain-text values.
 *
 * Key precedence (strongest → fallback):
 *  1. OCTOWOO_CRYPT_KEY constant  – define in wp-config.php for best security.
 *  2. WordPress AUTH_KEY constant – site-unique but not rotation-proof.
 *  3. Hard-coded string           – only when both above are absent; still
 *                                   better than plain text but not recommended.
 */

namespace OctoWoo\Core;

defined( 'ABSPATH' ) || exit;

class Encryptor {

    private const CIPHER = 'AES-256-CBC';
    private const PREFIX = 'octowoo_enc1::';
    private const IV_LEN = 16;

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Encrypt a plain-text value with AES-256-CBC.
     *
     * Returns the original value unchanged if:
     *  - openssl extension is unavailable.
     *  - The value is empty.
     *  - The value is already encrypted.
     *
     * @param string $plain  Plain-text value (e.g. database password).
     * @return string  Prefixed base64 ciphertext or original plain text.
     */
    public static function encrypt( string $plain ): string {
        if ( ! extension_loaded( 'openssl' ) || $plain === '' || self::isEncrypted( $plain ) ) {
            return $plain;
        }

        $iv         = openssl_random_pseudo_bytes( self::IV_LEN );
        $ciphertext = openssl_encrypt( $plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv );

        if ( $ciphertext === false ) {
            return $plain; // Silently fall back to plain text on openssl failure.
        }

        return self::PREFIX . base64_encode( $iv . $ciphertext );
    }

    /**
     * Decrypt a value produced by {@see encrypt()}.
     *
     * If the value does not carry the OctoWoo prefix (legacy plain text or
     * openssl unavailable), it is returned unchanged so existing configs are
     * not broken.
     *
     * @param string $value  Possibly encrypted value.
     * @return string  Decrypted plain text or original value.
     */
    public static function decrypt( string $value ): string {
        if ( ! extension_loaded( 'openssl' ) || ! self::isEncrypted( $value ) ) {
            return $value;
        }

        $raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true );

        if ( $raw === false || strlen( $raw ) <= self::IV_LEN ) {
            return $value; // Malformed — return as-is.
        }

        $iv         = substr( $raw, 0, self::IV_LEN );
        $ciphertext = substr( $raw, self::IV_LEN );
        $plain      = openssl_decrypt( $ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv );

        return $plain !== false ? $plain : $value;
    }

    /**
     * Return true when the value carries the OctoWoo encryption prefix.
     */
    public static function isEncrypted( string $value ): bool {
        return strncmp( $value, self::PREFIX, strlen( self::PREFIX ) ) === 0;
    }

    // ── Key resolution ────────────────────────────────────────────────────────

    private static function key(): string {
        if ( defined( 'OCTOWOO_CRYPT_KEY' ) ) {
            return (string) OCTOWOO_CRYPT_KEY;
        }

        if ( defined( 'AUTH_KEY' ) && strlen( AUTH_KEY ) >= 16 ) {
            return (string) AUTH_KEY;
        }

        // Last-resort fallback — encourages users to set OCTOWOO_CRYPT_KEY.
        return 'octowoo-fallback-key-please-define-OCTOWOO_CRYPT_KEY';
    }
}

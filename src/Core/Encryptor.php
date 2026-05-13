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

    /**
     * v2.5.0: Key precedence (strongest → fallback):
     *  1. OCTOWOO_CRYPT_KEY constant   – define in wp-config.php; best security.
     *  2. octowoo_secret_key option    – random 64-char key generated on activation.
     *  3. WordPress AUTH_KEY           – site-unique but shared across all plugins.
     *  4. Hard-coded fallback          – last resort; still better than plain text.
     */
    private static function key(): string {
        // 1. Explicit constant — highest priority.
        if ( defined( 'OCTOWOO_CRYPT_KEY' ) && strlen( (string) OCTOWOO_CRYPT_KEY ) >= 16 ) {
            return hash( 'sha256', (string) OCTOWOO_CRYPT_KEY );
        }

        // 2. Plugin-specific random key stored in wp_options (generated on activation).
        $stored = (string) get_option( 'octowoo_secret_key', '' );
        if ( strlen( $stored ) >= 32 ) {
            return hash( 'sha256', $stored );
        }

        // Key not yet generated (e.g. plugin was activated before this version).
        // Generate it now and store it so future calls use the same key.
        $generated = self::generateAndStoreKey();
        if ( $generated ) {
            return hash( 'sha256', $generated );
        }

        // 3. WordPress AUTH_KEY fallback.
        if ( defined( 'AUTH_KEY' ) && strlen( (string) AUTH_KEY ) >= 16 ) {
            return hash( 'sha256', (string) AUTH_KEY );
        }

        // 4. Absolute last resort.
        return hash( 'sha256', 'octowoo-fallback-key-please-define-OCTOWOO_CRYPT_KEY' );
    }

    /**
     * Generate a cryptographically random 64-char key and persist it to wp_options.
     * Returns the generated key string, or empty string on failure.
     */
    public static function generateAndStoreKey(): string {
        // Use wp_generate_password (alphanumeric + symbols, not in wp_hash).
        $key = function_exists( 'wp_generate_password' )
            ? wp_generate_password( 64, true, true )
            : bin2hex( random_bytes( 32 ) );

        if ( ! add_option( 'octowoo_secret_key', $key, '', 'no' ) ) {
            // Option already exists — read existing.
            $existing = (string) get_option( 'octowoo_secret_key', '' );
            if ( strlen( $existing ) >= 32 ) {
                return $existing;
            }
            // Force update if existing value is too short.
            update_option( 'octowoo_secret_key', $key, 'no' );
        }

        return $key;
    }
}

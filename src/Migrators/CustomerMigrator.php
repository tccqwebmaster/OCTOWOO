<?php
/**
 * Customer migrator.
 *
 * Imports OpenCart customers as WordPress users with WooCommerce billing/
 * shipping metadata.
 *
 * Security considerations:
 *  - OpenCart 3.x passwords use sha1(md5(salt.password)) which is NOT
 *    compatible with WordPress's phpass hashing.
 *  - We NEVER try to replay or store plaintext passwords.
 *  - Each imported user is given a random secure password.
 *  - If config.woocommerce.force_password_reset = true (default), the
 *    user is flagged so WP prompts them to set a new password on first login.
 *  - The original OC password hash is stored in user meta ONLY when
 *    config.woocommerce.migrate_oc_passwords = true (default: false). This
 *    is an advanced opt-in feature; leave it disabled unless you specifically
 *    need first-login hash-upgrade support.
 *  - OC 2.x/3.x hash formula: sha1($salt . sha1($salt . sha1($plaintext)))
 *    Verified against system/library/customer.php in all OC 2.x / 3.x releases.
 *
 * Fields deliberately NOT migrated (sensitive / WooCommerce-irrelevant):
 *   token, code, cart, wishlist, ip, safe, custom_field,
 *   fax, customer_group, store_id
 *
 * OpenCart tables read:
 *   oc_customer, oc_address, oc_country, oc_zone
 */

namespace OctoWoo\Migrators;

defined( 'ABSPATH' ) || exit;

class CustomerMigrator extends AbstractMigrator {

    /** Checkpoint key (matches MigrationManager order/config key). */
    private const KEY = 'customers';

    /** Stable ID-map entity key used by dependent migrators (orders/reviews). */
    private const MAP_KEY = 'customer';

    // ── Entry point ───────────────────────────────────────────────────────────

    public function migrate(): array {
        $resume_id = $this->checkpoint->getLastId( self::KEY );
        if ( $resume_id === PHP_INT_MAX ) {
            $this->logger->info( '[customers] Already completed – skipping.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
        }

        // Pre-fetch supporting data.
        $addresses = $this->fetchAddresses();
        $countries = $this->fetchCountries();
        $zones     = $this->fetchZones();

        $pfx = $this->pfx();

        $total_callback = fn() => $this->oc->count( 'customer', 'status = 1' );

        // v2.4.72: OC1 schema has no 'salt' column — use COALESCE for safe compat.
        $oc_major = $this->ocMajor();
        $salt_col  = ( $oc_major === 1 ) ? "'' AS oc_salt" : 'salt AS oc_salt';

        $batch_callback = fn( int $offset, int $limit ) => $this->oc->fetchBatch(
            /* Sensitive fields intentionally excluded: token, code, cart,
             * wishlist, ip, safe, custom_field, fax, customer_group, store_id. */
            "SELECT customer_id, firstname, lastname, email, telephone,
                    newsletter, address_id, date_added, status,
                    `password` AS oc_password, {$salt_col}
             FROM `{$pfx}customer`
             WHERE status = 1
             ORDER BY customer_id ASC",
            [],
            $limit,
            $offset
        );

        $item_callback = fn( array $row ) => $this->processCustomer( $row, $addresses, $countries, $zones );

        return $this->batch->run(
            total_callback:  $total_callback,
            batch_callback:  $batch_callback,
            item_callback:   $item_callback,
            migrator:        self::KEY,
            checkpoint:      $this->checkpoint,
            resume_after_id: $resume_id,
            id_field:        'customer_id'
        );
    }

    // ── Per-customer processing ───────────────────────────────────────────────

    private function processCustomer(
        array $row,
        array $addresses,
        array $countries,
        array $zones
    ): bool {
        $oc_id = (int) $row['customer_id'];
        $email = sanitize_email( $row['email'] );

        if ( ! is_email( $email ) ) {
            $this->logger->warning( "[customers] Invalid email for OC #{$oc_id}: {$row['email']} – skipping." );
            return false;
        }

        // Duplicate check by email.
        $existing_wp_user = get_user_by( 'email', $email );

        if ( $existing_wp_user ) {
            // Map the existing WP user.
            $this->checkpoint->saveIdMap( self::MAP_KEY, $oc_id, $existing_wp_user->ID );

            if ( $this->onDuplicate() === 'update' ) {
                $this->updateCustomerMeta( $existing_wp_user->ID, $row, $addresses, $countries, $zones );
                $this->logger->info( "[customers] Updated existing WP user #{$existing_wp_user->ID} (OC #{$oc_id})." );
                return true;
            }

            $this->logger->debug( "[customers] Duplicate email {$email} → WP #{$existing_wp_user->ID} – skipping." );
            return false;
        }

        // Check by octowoo_oc_id meta (previous run mapped to a user).
        $mapped_wc_id = $this->checkpoint->getWcId( self::MAP_KEY, $oc_id );
        if ( $mapped_wc_id ) {
            $this->logger->debug( "[customers] Already migrated OC #{$oc_id} → WP #{$mapped_wc_id} – skipping." );
            return false;
        }

        if ( $this->isDry() ) {
            $this->logger->debug( "[DRY-RUN] Would create user: {$email} (OC #{$oc_id})" );
            return true;
        }

        return $this->createCustomer( $oc_id, $row, $addresses, $countries, $zones );
    }

    // ── Create ────────────────────────────────────────────────────────────────

    private function createCustomer(
        int   $oc_id,
        array $row,
        array $addresses,
        array $countries,
        array $zones
    ): bool {
        $email      = sanitize_email( $row['email'] );
        $first_name = $this->sanitizeText( $row['firstname'] );
        $last_name  = $this->sanitizeText( $row['lastname'] );

        // Generate a safe random password – the customer will reset it.
        $password = wp_generate_password( 24, true, true );

        // Build a unique username from the email.
        $username = $this->generateUsername( $email );

        $user_id = wp_insert_user( [
            'user_login'   => $username,
            'user_email'   => $email,
            'user_pass'    => $password,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => trim( "{$first_name} {$last_name}" ),
            'role'         => $this->config['woocommerce']['customer_role'] ?? 'customer',
            'user_registered' => $row['date_added'] ?? current_time( 'mysql' ),
        ] );

        if ( is_wp_error( $user_id ) ) {
            $this->logger->error(
                "[customers] wp_insert_user failed for OC #{$oc_id}: " . $user_id->get_error_message(),
                [ 'email' => $email ]
            );
            return false;
        }

        $this->updateCustomerMeta( $user_id, $row, $addresses, $countries, $zones );

        // Flag for mandatory password reset on first login.
        if ( $this->config['woocommerce']['force_password_reset'] ?? true ) {
            update_user_meta( $user_id, 'default_password_nag', true );
            update_user_meta( $user_id, '_octowoo_password_reset_required', 1 );
        }

        // Store OC password hash + salt for first-login upgrade when enabled.
        // WARNING: storing a password hash (even a weak one) in WP usermeta
        // makes it visible to any WP admin and any plugin that queries usermeta.
        // Only enable migrate_oc_passwords when you fully control the hosting
        // environment and understand the trade-off.
        if ( ! empty( $this->config['woocommerce']['migrate_oc_passwords'] )
            && ! empty( $row['oc_password'] )
        ) {
            $this->logger->warning(
                "[customers] migrate_oc_passwords is ON – storing OC password hash in WP usermeta for user #{$user_id}. " .
                'Disable after first-login upgrade is complete.'
            );
            update_user_meta( $user_id, '_octowoo_oc_password_hash', (string) $row['oc_password'] );
            update_user_meta( $user_id, '_octowoo_oc_password_salt', (string) ( $row['oc_salt'] ?? '' ) );
        }

        update_user_meta( $user_id, '_octowoo_oc_id', $oc_id );

        $this->checkpoint->saveIdMap( self::MAP_KEY, $oc_id, $user_id );

        $this->logger->info( sprintf(
            '[customers] ✔ Created customer | WC user #%d | OC #%d | Email: %s | Name: %s %s',
            $user_id, $oc_id,
            $row['email'] ?? '—',
            $this->sanitizeText( $row['firstname'] ?? '' ),
            $this->sanitizeText( $row['lastname']  ?? '' )
        ) );
        return true;
    }

    // ── Meta ──────────────────────────────────────────────────────────────────

    private function updateCustomerMeta(
        int   $user_id,
        array $row,
        array $addresses,
        array $countries,
        array $zones
    ): void {
        $oc_id       = (int) $row['customer_id'];
        $default_aid = (int) $row['address_id'];

        $billing  = $addresses[ $oc_id ][ $default_aid ] ?? $this->firstAddress( $addresses[ $oc_id ] ?? [] );
        $shipping = $billing; // OC doesn't have a separate default shipping address.

        // Base.
        update_user_meta( $user_id, 'first_name', $this->sanitizeText( $row['firstname'] ) );
        update_user_meta( $user_id, 'last_name',  $this->sanitizeText( $row['lastname'] ) );

        if ( $billing ) {
            $country_code = $countries[ (int) ( $billing['country_id'] ?? 0 ) ] ?? '';
            $state_code   = $zones[ (int) ( $billing['zone_id'] ?? 0 ) ] ?? '';

            // Billing.
            update_user_meta( $user_id, 'billing_first_name',  $this->sanitizeText( $billing['firstname'] ?? $row['firstname'] ) );
            update_user_meta( $user_id, 'billing_last_name',   $this->sanitizeText( $billing['lastname']  ?? $row['lastname'] ) );
            update_user_meta( $user_id, 'billing_company',     $this->sanitizeText( $billing['company']   ?? '' ) );
            update_user_meta( $user_id, 'billing_address_1',   $this->sanitizeText( $billing['address_1'] ?? '' ) );
            update_user_meta( $user_id, 'billing_address_2',   $this->sanitizeText( $billing['address_2'] ?? '' ) );
            update_user_meta( $user_id, 'billing_city',        $this->sanitizeText( $billing['city']      ?? '' ) );
            update_user_meta( $user_id, 'billing_postcode',    sanitize_text_field( $billing['postcode']  ?? '' ) );
            update_user_meta( $user_id, 'billing_country',     sanitize_text_field( $country_code ) );
            update_user_meta( $user_id, 'billing_state',       sanitize_text_field( $state_code ) );
            update_user_meta( $user_id, 'billing_email',       sanitize_email( $row['email'] ) );
            update_user_meta( $user_id, 'billing_phone',       sanitize_text_field( $row['telephone'] ?? '' ) );

        // Newsletter / marketing email opt-in.
        // Stored so the store owner can honour the customer's original preference.
        // 0 = opted out, 1 = opted in.
        $newsletter_optin = ( (int) ( $row['newsletter'] ?? 0 ) ) === 1 ? 'yes' : 'no';
        update_user_meta( $user_id, 'woocommerce_marketing_optin_status',  $newsletter_optin );
        update_user_meta( $user_id, '_octowoo_newsletter_optin', $newsletter_optin );

            // Shipping (mirrors billing by default).
            update_user_meta( $user_id, 'shipping_first_name', $this->sanitizeText( $billing['firstname'] ?? $row['firstname'] ) );
            update_user_meta( $user_id, 'shipping_last_name',  $this->sanitizeText( $billing['lastname']  ?? $row['lastname'] ) );
            update_user_meta( $user_id, 'shipping_company',    $this->sanitizeText( $billing['company']   ?? '' ) );
            update_user_meta( $user_id, 'shipping_address_1',  $this->sanitizeText( $billing['address_1'] ?? '' ) );
            update_user_meta( $user_id, 'shipping_address_2',  $this->sanitizeText( $billing['address_2'] ?? '' ) );
            update_user_meta( $user_id, 'shipping_city',       $this->sanitizeText( $billing['city']      ?? '' ) );
            update_user_meta( $user_id, 'shipping_postcode',   sanitize_text_field( $billing['postcode']  ?? '' ) );
            update_user_meta( $user_id, 'shipping_country',    sanitize_text_field( $country_code ) );
            update_user_meta( $user_id, 'shipping_state',      sanitize_text_field( $state_code ) );
        }
    }

    // ── Data fetching helpers ─────────────────────────────────────────────────

    /**
     * Fetch all addresses keyed by [customer_id][address_id].
     *
     * @return array<int, array<int, array<string,mixed>>>
     */
    private function fetchAddresses(): array {
        $pfx      = $this->pfx();
        $oc_major = $this->ocMajor();
        // v2.4.72: OC 1.0.x had no zone_id column; OC 1.5.x added it.
        // Use COALESCE so the query works for both OC1 variants.
        $zone_col = ( $oc_major === 1 )
            ? 'COALESCE(zone_id, 0) AS zone_id'
            : 'zone_id';
        $rows = $this->oc->fetchAll(
            "SELECT address_id, customer_id, firstname, lastname, company,
                    address_1, address_2, city, postcode, country_id, {$zone_col}
             FROM `{$pfx}address`"
        );

        $indexed = [];
        foreach ( $rows as $row ) {
            $indexed[ (int) $row['customer_id'] ][ (int) $row['address_id'] ] = $row;
        }
        return $indexed;
    }

    /**
     * Fetch ISO country codes keyed by country_id.
     *
     * @return array<int, string>
     */
    private function fetchCountries(): array {
        $pfx  = $this->pfx();
        $rows = $this->oc->fetchAll( "SELECT country_id, iso_code_2 FROM `{$pfx}country`" );

        $map = [];
        foreach ( $rows as $row ) {
            $map[ (int) $row['country_id'] ] = strtoupper( $row['iso_code_2'] ?? '' );
        }
        return $map;
    }

    /**
     * Fetch zone/state codes keyed by zone_id.
     *
     * @return array<int, string>
     */
    private function fetchZones(): array {
        $pfx  = $this->pfx();
        $rows = $this->oc->fetchAll( "SELECT zone_id, code FROM `{$pfx}zone`" );

        $map = [];
        foreach ( $rows as $row ) {
            $map[ (int) $row['zone_id'] ] = strtoupper( $row['code'] ?? '' );
        }
        return $map;
    }

    // ── Utilities ─────────────────────────────────────────────────────────────

    private function firstAddress( array $addr_map ): ?array {
        return ! empty( $addr_map ) ? reset( $addr_map ) : null;
    }

    /**
     * Generate a unique WP username from an email address.
     */
    private function generateUsername( string $email ): string {
        // Use the local part of the email as a base.
        $base = strstr( $email, '@', true );
        $base = sanitize_user( $base, true );

        if ( ! username_exists( $base ) ) {
            return $base;
        }

        $i = 1;
        do {
            $candidate = $base . $i;
            $i++;
        } while ( username_exists( $candidate ) );

        return $candidate;
    }
}

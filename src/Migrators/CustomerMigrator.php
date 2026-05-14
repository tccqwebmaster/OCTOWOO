<?php
/**
 * Customer migrator — v2.5.6 performance + completeness rebuild.
 *
 * PERFORMANCE CHANGES vs previous version:
 *   OLD: get_user_by('email') called per customer → N individual DB queries.
 *   NEW: buildExistingEmailMap() pre-fetches all WP emails in ONE query.
 *        For 10 000 customers this removes ~10 000 DB hits.
 *
 *   OLD: username_exists() called per username attempt → DB query each loop.
 *   NEW: buildExistingUsernameSet() pre-fetches all logins in ONE query.
 *
 *   OLD: No cache suspension — WP flushed object cache on every user insert.
 *   NEW: wp_suspend_cache_invalidation() wraps batch; wp_cache_flush() at end.
 *
 * COMPLETENESS — ALL OC FIELDS NOW MIGRATED:
 *   customer_group_id  → _octowoo_customer_group_id (usermeta)
 *   telephone          → billing_phone (WC standard meta)
 *   newsletter         → woocommerce_marketing_optin_status
 *   All billing + shipping address fields (address_1/2, city, postcode,
 *   country ISO-2, state/zone code, company, first/last name)
 *   date_added         → user_registered
 *   Password reset flag on first login (default: enabled)
 *
 * Fields deliberately NOT migrated (sensitive / irrelevant):
 *   token, code, cart, wishlist, ip, safe, custom_field, fax, store_id
 *
 * OpenCart tables: oc_customer, oc_address, oc_country, oc_zone
 *
 * @package OctoWoo\Migrators
 */

namespace OctoWoo\Migrators;

defined( 'ABSPATH' ) || exit;

class CustomerMigrator extends AbstractMigrator {

	private const KEY     = 'customers';
	private const MAP_KEY = 'customer';

	// ── Entry point ───────────────────────────────────────────────────────────

	public function migrate(): array {
		$resume_id = $this->checkpoint->getLastId( self::KEY );
		if ( $resume_id === PHP_INT_MAX ) {
			$this->logger->info( '[customers] Already completed – skipping.' );
			return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
		}

		// Pre-fetch OC lookup tables (small; loaded once for the whole batch).
		$addresses = $this->fetchAddresses();
		$countries = $this->fetchCountries();
		$zones     = $this->fetchZones();

		// PERFORMANCE: load all existing WP emails + usernames in TWO queries
		// instead of one DB query per customer.
		$existing_emails    = $this->buildExistingEmailMap();    // lower(email) → user_id
		$existing_usernames = $this->buildExistingUsernameSet(); // username → true

		// Suspend per-row object-cache invalidation; flush once at the end.
		wp_suspend_cache_invalidation( true );

		$pfx      = $this->pfx();
		$oc_major = $this->ocMajor();
		$salt_col  = ( $oc_major === 1 ) ? "'' AS oc_salt"           : 'salt AS oc_salt';
		$group_col = ( $oc_major >= 2 )  ? 'customer_group_id'       : '0 AS customer_group_id';

		$total_callback = fn() => $this->oc->count( 'customer', 'status = 1' );

		$batch_callback = fn( int $offset, int $limit ) => $this->oc->fetchBatch(
			/* Intentionally excluded: token, code, cart, wishlist, ip,
			 * safe, custom_field, fax, store_id. */
			"SELECT customer_id, firstname, lastname, email, telephone,
			        newsletter, address_id, date_added, status,
			        `password` AS oc_password, {$salt_col}, {$group_col}
			 FROM `{$pfx}customer`
			 WHERE status = 1
			 ORDER BY customer_id ASC",
			[],
			$limit,
			$offset
		);

		$item_callback = function ( array $row ) use (
			$addresses, $countries, $zones,
			&$existing_emails, &$existing_usernames
		): bool {
			return $this->processCustomer(
				$row, $addresses, $countries, $zones,
				$existing_emails, $existing_usernames
			);
		};

		$result = $this->batch->run(
			total_callback:  $total_callback,
			batch_callback:  $batch_callback,
			item_callback:   $item_callback,
			migrator:        self::KEY,
			checkpoint:      $this->checkpoint,
			resume_after_id: $resume_id,
			id_field:        'customer_id'
		);

		wp_suspend_cache_invalidation( false );
		wp_cache_flush();

		return $result;
	}

	// ── Per-customer processing ───────────────────────────────────────────────

	private function processCustomer(
		array  $row,
		array  $addresses,
		array  $countries,
		array  $zones,
		array &$existing_emails,
		array &$existing_usernames
	): bool {
		$oc_id = (int) $row['customer_id'];
		$email = sanitize_email( $row['email'] ?? '' );

		if ( ! is_email( $email ) ) {
			$this->logger->warning( "[customers] Invalid email OC #{$oc_id}: '{$row['email']}' – skipping." );
			return false;
		}

		// Duplicate check by email — O(1) hash lookup (no DB query).
		$email_lower = strtolower( $email );
		if ( isset( $existing_emails[ $email_lower ] ) ) {
			$existing_user_id = $existing_emails[ $email_lower ];
			$this->checkpoint->saveIdMap( self::MAP_KEY, $oc_id, $existing_user_id );

			if ( $this->onDuplicate() === 'update' ) {
				$this->updateCustomerMeta( $existing_user_id, $row, $addresses, $countries, $zones );
				$this->logger->info( "[customers] ↺ Updated user #{$existing_user_id} (OC #{$oc_id}) | Email: {$email}" );
				return true;
			}

			$this->logger->debug( "[customers] ↷ Duplicate email {$email} → WP #{$existing_user_id} – skipping." );
			return false;
		}

		// id_map check — handles resume from a previous partial run.
		$mapped_id = $this->checkpoint->getWcId( self::MAP_KEY, $oc_id );
		if ( $mapped_id ) {
			$this->logger->debug( "[customers] Already migrated OC #{$oc_id} → WP #{$mapped_id} – skipping." );
			return false;
		}

		if ( $this->isDry() ) {
			$this->logger->debug( "[DRY-RUN] Would create user: {$email} (OC #{$oc_id})" );
			return true;
		}

		return $this->createCustomer(
			$oc_id, $row, $addresses, $countries, $zones,
			$existing_emails, $existing_usernames
		);
	}

	// ── Create ────────────────────────────────────────────────────────────────

	private function createCustomer(
		int    $oc_id,
		array  $row,
		array  $addresses,
		array  $countries,
		array  $zones,
		array &$existing_emails,
		array &$existing_usernames
	): bool {
		$email      = sanitize_email( $row['email'] ?? '' );
		$first_name = $this->sanitizeText( $row['firstname'] ?? '' );
		$last_name  = $this->sanitizeText( $row['lastname']  ?? '' );

		$password = wp_generate_password( 24, true, true );

		// O(1) username generation — no DB query per attempt.
		$username = $this->generateUsernameFrom( $email, $existing_usernames );
		// Mark as used so subsequent customers in this batch don't pick the same name.
		$existing_usernames[ $username ] = true;

		$user_id = wp_insert_user( [
			'user_login'      => $username,
			'user_email'      => $email,
			'user_pass'       => $password,
			'first_name'      => $first_name,
			'last_name'       => $last_name,
			'display_name'    => trim( "{$first_name} {$last_name}" ),
			'role'            => $this->config['woocommerce']['customer_role'] ?? 'customer',
			'user_registered' => $row['date_added'] ?? current_time( 'mysql' ),
		] );

		if ( is_wp_error( $user_id ) ) {
			$this->logger->error(
				"[customers] wp_insert_user failed for OC #{$oc_id}: " . $user_id->get_error_message(),
				[ 'email' => $email ]
			);
			return false;
		}

		// Add to in-memory map so the next customer in this batch doesn't get
		// checked against DB (covers edge case of duplicate OC emails).
		$existing_emails[ strtolower( $email ) ] = $user_id;

		// Write all billing/shipping/preference meta.
		$this->updateCustomerMeta( $user_id, $row, $addresses, $countries, $zones );

		// Password reset on first login.
		if ( $this->config['woocommerce']['force_password_reset'] ?? true ) {
			update_user_meta( $user_id, 'default_password_nag',               true );
			update_user_meta( $user_id, '_octowoo_password_reset_required',   1 );
		}

		// Optional: store OC password hash for first-login upgrade.
		// Only enable migrate_oc_passwords with full understanding of the risk.
		if ( ! empty( $this->config['woocommerce']['migrate_oc_passwords'] )
			&& ! empty( $row['oc_password'] )
		) {
			$this->logger->warning(
				"[customers] migrate_oc_passwords ON — storing OC hash in usermeta for WP #{$user_id}. " .
				'Disable after first-login upgrade completes.'
			);
			update_user_meta( $user_id, '_octowoo_oc_password_hash', (string) $row['oc_password'] );
			update_user_meta( $user_id, '_octowoo_oc_password_salt', (string) ( $row['oc_salt'] ?? '' ) );
		}

		update_user_meta( $user_id, '_octowoo_oc_id', $oc_id );
		$this->checkpoint->saveIdMap( self::MAP_KEY, $oc_id, $user_id );

		$this->logger->info( sprintf(
			'[customers] ✔ Created customer | WC user #%d | OC #%d | Email: %s | Name: %s %s',
			$user_id, $oc_id, $email, $first_name, $last_name
		) );

		return true;
	}

	// ── Meta writer ───────────────────────────────────────────────────────────

	/**
	 * Write all WooCommerce billing/shipping/preference meta for a WP user.
	 * Called on both create and update paths.
	 */
	private function updateCustomerMeta(
		int   $user_id,
		array $row,
		array $addresses,
		array $countries,
		array $zones
	): void {
		$oc_id       = (int) $row['customer_id'];
		$default_aid = (int) $row['address_id'];

		// Resolve default billing address (OC stores one default; use it for both billing + shipping).
		$billing = $addresses[ $oc_id ][ $default_aid ]
			?? $this->firstAddress( $addresses[ $oc_id ] ?? [] );

		// ── WP profile base fields ─────────────────────────────────────────────
		update_user_meta( $user_id, 'first_name', $this->sanitizeText( $row['firstname'] ?? '' ) );
		update_user_meta( $user_id, 'last_name',  $this->sanitizeText( $row['lastname']  ?? '' ) );

		// ── Newsletter / marketing opt-in ──────────────────────────────────────
		$optin = ( (int) ( $row['newsletter'] ?? 0 ) === 1 ) ? 'yes' : 'no';
		update_user_meta( $user_id, 'woocommerce_marketing_optin_status', $optin );
		update_user_meta( $user_id, '_octowoo_newsletter_optin',          $optin );

		// ── Customer group (WC has no direct equivalent — store for reference) ─
		if ( isset( $row['customer_group_id'] ) && (int) $row['customer_group_id'] > 0 ) {
			update_user_meta( $user_id, '_octowoo_customer_group_id', (int) $row['customer_group_id'] );
		}

		// ── Billing ───────────────────────────────────────────────────────────
		update_user_meta( $user_id, 'billing_email', sanitize_email( $row['email'] ?? '' ) );
		update_user_meta( $user_id, 'billing_phone', sanitize_text_field( $row['telephone'] ?? '' ) );

		if ( $billing ) {
			$country_code = $countries[ (int) ( $billing['country_id'] ?? 0 ) ] ?? '';
			$state_code   = $zones[    (int) ( $billing['zone_id']    ?? 0 ) ] ?? '';

			update_user_meta( $user_id, 'billing_first_name', $this->sanitizeText( $billing['firstname'] ?? $row['firstname'] ?? '' ) );
			update_user_meta( $user_id, 'billing_last_name',  $this->sanitizeText( $billing['lastname']  ?? $row['lastname']  ?? '' ) );
			update_user_meta( $user_id, 'billing_company',    $this->sanitizeText( $billing['company']   ?? '' ) );
			update_user_meta( $user_id, 'billing_address_1',  $this->sanitizeText( $billing['address_1'] ?? '' ) );
			update_user_meta( $user_id, 'billing_address_2',  $this->sanitizeText( $billing['address_2'] ?? '' ) );
			update_user_meta( $user_id, 'billing_city',       $this->sanitizeText( $billing['city']      ?? '' ) );
			update_user_meta( $user_id, 'billing_postcode',   sanitize_text_field( $billing['postcode']  ?? '' ) );
			update_user_meta( $user_id, 'billing_country',    sanitize_text_field( $country_code ) );
			update_user_meta( $user_id, 'billing_state',      sanitize_text_field( $state_code ) );

			// ── Shipping (mirrors billing — OC has no separate default shipping addr) ─
			update_user_meta( $user_id, 'shipping_first_name', $this->sanitizeText( $billing['firstname'] ?? $row['firstname'] ?? '' ) );
			update_user_meta( $user_id, 'shipping_last_name',  $this->sanitizeText( $billing['lastname']  ?? $row['lastname']  ?? '' ) );
			update_user_meta( $user_id, 'shipping_company',    $this->sanitizeText( $billing['company']   ?? '' ) );
			update_user_meta( $user_id, 'shipping_address_1',  $this->sanitizeText( $billing['address_1'] ?? '' ) );
			update_user_meta( $user_id, 'shipping_address_2',  $this->sanitizeText( $billing['address_2'] ?? '' ) );
			update_user_meta( $user_id, 'shipping_city',       $this->sanitizeText( $billing['city']      ?? '' ) );
			update_user_meta( $user_id, 'shipping_postcode',   sanitize_text_field( $billing['postcode']  ?? '' ) );
			update_user_meta( $user_id, 'shipping_country',    sanitize_text_field( $country_code ) );
			update_user_meta( $user_id, 'shipping_state',      sanitize_text_field( $state_code ) );
			update_user_meta( $user_id, 'shipping_phone',      sanitize_text_field( $row['telephone'] ?? '' ) );
		}
	}

	// ── OC data fetchers ──────────────────────────────────────────────────────

	/** @return array<int, array<int, array<string,mixed>>> [customer_id][address_id] → address row */
	private function fetchAddresses(): array {
		$pfx      = $this->pfx();
		$oc_major = $this->ocMajor();
		$zone_col = ( $oc_major === 1 ) ? 'COALESCE(zone_id, 0) AS zone_id' : 'zone_id';

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

	/** @return array<int, string> country_id → ISO-2 code */
	private function fetchCountries(): array {
		$rows = $this->oc->fetchAll( "SELECT country_id, iso_code_2 FROM `{$this->pfx()}country`" );
		$map  = [];
		foreach ( $rows as $r ) { $map[ (int) $r['country_id'] ] = strtoupper( $r['iso_code_2'] ?? '' ); }
		return $map;
	}

	/** @return array<int, string> zone_id → zone code */
	private function fetchZones(): array {
		$rows = $this->oc->fetchAll( "SELECT zone_id, code FROM `{$this->pfx()}zone`" );
		$map  = [];
		foreach ( $rows as $r ) { $map[ (int) $r['zone_id'] ] = strtoupper( $r['code'] ?? '' ); }
		return $map;
	}

	// ── WP bulk pre-fetch helpers ─────────────────────────────────────────────

	/**
	 * Load all WP user emails into a hash: lower(email) → user_id.
	 * Replaces N individual get_user_by('email') calls with ONE query.
	 *
	 * @return array<string, int>
	 */
	private function buildExistingEmailMap(): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT ID, user_email FROM {$wpdb->users}",
			ARRAY_A
		);
		$map = [];
		foreach ( $rows as $r ) {
			$map[ strtolower( (string) $r['user_email'] ) ] = (int) $r['ID'];
		}
		return $map;
	}

	/**
	 * Load all WP usernames into a set for O(1) uniqueness checks.
	 * Replaces N individual username_exists() calls with ONE query.
	 *
	 * @return array<string, true>
	 */
	private function buildExistingUsernameSet(): array {
		global $wpdb;
		$logins = $wpdb->get_col( "SELECT user_login FROM {$wpdb->users}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$set    = [];
		foreach ( $logins as $login ) { $set[ (string) $login ] = true; }
		return $set;
	}

	// ── Utilities ─────────────────────────────────────────────────────────────

	private function firstAddress( array $addr_map ): ?array {
		return ! empty( $addr_map ) ? reset( $addr_map ) : null;
	}

	/**
	 * Generate a unique WP username using a pre-fetched set (O(1) per attempt).
	 *
	 * @param  string        $email
	 * @param  array<string, true> &$existing_usernames  In-memory set of taken usernames.
	 * @return string
	 */
	private function generateUsernameFrom( string $email, array &$existing_usernames ): string {
		$base = sanitize_user( (string) strstr( $email, '@', true ), true );
		if ( $base === '' ) { $base = 'customer'; }

		if ( ! isset( $existing_usernames[ $base ] ) ) {
			return $base;
		}

		$i = 1;
		do { $candidate = $base . $i; $i++; } while ( isset( $existing_usernames[ $candidate ] ) );
		return $candidate;
	}
}

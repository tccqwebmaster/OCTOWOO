<?php
/**
 * RestorePoint — safety net for destructive operations.
 *
 * Before any destructive term/taxonomy operation runs, capture() writes a JSON
 * snapshot of the affected rows (terms, term_taxonomy, term_relationships, and
 * WPML icl_translations) to wp-content/uploads/octowoo-logs/restore/. restore()
 * can re-insert those exact rows to undo an accidental deletion/relabel.
 *
 * This is a defensive complement to a real database backup — NOT a replacement.
 * Capture is strictly read-only (it only SELECTs), so creating a restore point
 * can never itself damage data.
 */

namespace OctoWoo\Core;

defined( 'ABSPATH' ) || exit;

class RestorePoint {

	/** Snapshot format version (bump if the structure changes). */
	private const FORMAT = 1;

	/** Maximum number of snapshots to keep (older ones are pruned). */
	private const KEEP = 20;

	/**
	 * Directory where snapshots live (under the relocated log dir, web-protected).
	 */
	public static function dir(): string {
		$dir = trailingslashit( OCTOWOO_LOG_DIR ) . 'restore/';
		if ( ! is_dir( $dir ) ) {
			// Reuse Logger's protection (index.html + .htaccess) on the parent,
			// then create the restore subdir.
			Logger::ensureLogDir();
			@wp_mkdir_p( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return $dir;
	}

	/**
	 * Capture a snapshot of the given taxonomies BEFORE a destructive op.
	 *
	 * @param string[] $taxonomies Taxonomies to snapshot (e.g. ['product_cat','product_brand']).
	 * @param string   $label      Human label for the operation (e.g. 'full_cleanup').
	 * @return string|null Absolute path to the snapshot file, or null on failure.
	 */
	public static function capture( array $taxonomies, string $label ): ?string {
		global $wpdb;

		$taxonomies = array_values( array_filter( array_unique( $taxonomies ), 'taxonomy_exists' ) );
		if ( empty( $taxonomies ) ) {
			return null;
		}

		$icl        = $wpdb->prefix . 'icl_translations';
		$has_wpml   = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $icl ) ); // phpcs:ignore WordPress.DB
		$snapshot   = [
			'format'     => self::FORMAT,
			'created'    => gmdate( 'c' ),
			'label'      => $label,
			'site'       => home_url(),
			'taxonomies' => $taxonomies,
			'terms'      => [],
			'taxonomy'   => [],
			'rel'        => [],
			'icl'        => [],
		];

		foreach ( $taxonomies as $tax ) {
			// term_taxonomy rows for this taxonomy.
			$tt_rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT term_taxonomy_id, term_id, taxonomy, description, parent, count
				 FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
				$tax
			), ARRAY_A );
			if ( ! $tt_rows ) {
				continue;
			}
			$term_ids = array_map( static fn( $r ) => (int) $r['term_id'], $tt_rows );
			$tt_ids   = array_map( static fn( $r ) => (int) $r['term_taxonomy_id'], $tt_rows );
			$snapshot['taxonomy'] = array_merge( $snapshot['taxonomy'], $tt_rows );

			// term rows.
			$in_terms = implode( ',', array_map( 'intval', $term_ids ) );
			if ( $in_terms !== '' ) {
				$snapshot['terms'] = array_merge(
					$snapshot['terms'],
					$wpdb->get_results( "SELECT term_id, name, slug, term_group FROM {$wpdb->terms} WHERE term_id IN ({$in_terms})", ARRAY_A ) // phpcs:ignore WordPress.DB
				);
			}

			// term_relationships (object→term links) — so product assignments can be restored.
			$in_tt = implode( ',', array_map( 'intval', $tt_ids ) );
			if ( $in_tt !== '' ) {
				$snapshot['rel'] = array_merge(
					$snapshot['rel'],
					$wpdb->get_results( "SELECT object_id, term_taxonomy_id, term_order FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ({$in_tt})", ARRAY_A ) // phpcs:ignore WordPress.DB
				);

				// WPML translation rows keyed by term_taxonomy_id.
				if ( $has_wpml ) {
					$et = 'tax_' . $tax;
					$snapshot['icl'] = array_merge(
						$snapshot['icl'],
						$wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
							"SELECT translation_id, element_type, element_id, trid, language_code, source_language_code
							 FROM `{$icl}` WHERE element_type = %s AND element_id IN ({$in_tt})",
							$et
						), ARRAY_A )
					);
				}
			}
		}

		$file = self::dir() . sprintf( 'restore-%s-%s.json', sanitize_key( $label ), gmdate( 'Ymd-His' ) );
		$json = wp_json_encode( $snapshot, JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.PHP.NoSilencedErrors.Discouraged
		$ok = @file_put_contents( $file, $json, LOCK_EX );

		self::prune();
		return $ok ? $file : null;
	}

	/**
	 * List available snapshots, newest first.
	 *
	 * @return array<int,array{file:string,label:string,created:string,terms:int}>
	 */
	public static function listSnapshots(): array {
		$out = [];
		foreach ( (array) glob( self::dir() . 'restore-*.json' ) as $path ) {
			$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( ! is_array( $data ) ) {
				continue;
			}
			$out[] = [
				'file'    => basename( $path ),
				'label'   => (string) ( $data['label'] ?? 'unknown' ),
				'created' => (string) ( $data['created'] ?? '' ),
				'terms'   => is_array( $data['terms'] ?? null ) ? count( $data['terms'] ) : 0,
			];
		}
		usort( $out, static fn( $a, $b ) => strcmp( $b['created'], $a['created'] ) );
		return $out;
	}

	/**
	 * Restore a snapshot by filename. Re-inserts any missing term / term_taxonomy /
	 * relationship / icl rows. Uses INSERT IGNORE semantics so existing rows are not
	 * duplicated — this brings back what was DELETED without clobbering current data.
	 *
	 * @return array{terms:int,taxonomy:int,rel:int,icl:int,message:string}
	 */
	public static function restore( string $filename ): array {
		global $wpdb;

		$file = self::dir() . basename( $filename ); // basename() prevents path traversal.
		if ( ! is_file( $file ) ) {
			return [ 'terms' => 0, 'taxonomy' => 0, 'rel' => 0, 'icl' => 0, 'message' => 'Snapshot not found.' ];
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! is_array( $data ) || (int) ( $data['format'] ?? 0 ) !== self::FORMAT ) {
			return [ 'terms' => 0, 'taxonomy' => 0, 'rel' => 0, 'icl' => 0, 'message' => 'Snapshot unreadable or wrong format.' ];
		}

		$n = [ 'terms' => 0, 'taxonomy' => 0, 'rel' => 0, 'icl' => 0 ];

		foreach ( (array) ( $data['terms'] ?? [] ) as $r ) {
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->terms} WHERE term_id=%d", (int) $r['term_id'] ) ); // phpcs:ignore WordPress.DB
			if ( ! $exists ) {
				$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
					"INSERT INTO {$wpdb->terms} (term_id, name, slug, term_group) VALUES (%d,%s,%s,%d)",
					(int) $r['term_id'], (string) $r['name'], (string) $r['slug'], (int) ( $r['term_group'] ?? 0 )
				) );
				$n['terms']++;
			}
		}
		foreach ( (array) ( $data['taxonomy'] ?? [] ) as $r ) {
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id=%d", (int) $r['term_taxonomy_id'] ) ); // phpcs:ignore WordPress.DB
			if ( ! $exists ) {
				$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
					"INSERT INTO {$wpdb->term_taxonomy} (term_taxonomy_id, term_id, taxonomy, description, parent, count) VALUES (%d,%d,%s,%s,%d,%d)",
					(int) $r['term_taxonomy_id'], (int) $r['term_id'], (string) $r['taxonomy'], (string) ( $r['description'] ?? '' ), (int) ( $r['parent'] ?? 0 ), (int) ( $r['count'] ?? 0 )
				) );
				$n['taxonomy']++;
			}
		}
		foreach ( (array) ( $data['rel'] ?? [] ) as $r ) {
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id=%d AND term_taxonomy_id=%d", (int) $r['object_id'], (int) $r['term_taxonomy_id'] ) ); // phpcs:ignore WordPress.DB
			if ( ! $exists ) {
				$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
					"INSERT INTO {$wpdb->term_relationships} (object_id, term_taxonomy_id, term_order) VALUES (%d,%d,%d)",
					(int) $r['object_id'], (int) $r['term_taxonomy_id'], (int) ( $r['term_order'] ?? 0 )
				) );
				$n['rel']++;
			}
		}
		$icl = $wpdb->prefix . 'icl_translations';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $icl ) ) ) { // phpcs:ignore WordPress.DB
			foreach ( (array) ( $data['icl'] ?? [] ) as $r ) {
				// Respect the trid_lang unique key: only insert if that (trid,lang) is free.
				$clash = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$icl}` WHERE trid=%d AND language_code=%s", (int) $r['trid'], (string) $r['language_code'] ) ); // phpcs:ignore WordPress.DB
				$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$icl}` WHERE element_type=%s AND element_id=%d", (string) $r['element_type'], (int) $r['element_id'] ) ); // phpcs:ignore WordPress.DB
				if ( ! $exists && ! $clash ) {
					$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
						"INSERT INTO `{$icl}` (element_type, element_id, trid, language_code, source_language_code) VALUES (%s,%d,%d,%s,%s)",
						(string) $r['element_type'], (int) $r['element_id'], (int) $r['trid'], (string) $r['language_code'], $r['source_language_code'] !== null ? (string) $r['source_language_code'] : null
					) );
					$n['icl']++;
				}
			}
		}

		foreach ( (array) ( $data['taxonomies'] ?? [] ) as $tax ) {
			clean_taxonomy_cache( $tax );
		}

		$n['message'] = sprintf(
			'Restored from %s: %d terms, %d taxonomy rows, %d product links, %d translation links re-inserted.',
			basename( $file ), $n['terms'], $n['taxonomy'], $n['rel'], $n['icl']
		);
		return $n;
	}

	/** Keep only the most recent self::KEEP snapshots. */
	private static function prune(): void {
		$files = (array) glob( self::dir() . 'restore-*.json' );
		if ( count( $files ) <= self::KEEP ) {
			return;
		}
		usort( $files, static fn( $a, $b ) => filemtime( $a ) <=> filemtime( $b ) );
		foreach ( array_slice( $files, 0, count( $files ) - self::KEEP ) as $old ) {
			@unlink( $old ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions
		}
	}
}

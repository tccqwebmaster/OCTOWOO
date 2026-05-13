<?php
/**
 * Download migrator.
 *
 * Reads OpenCart downloadable files (oc_download + oc_product_to_download)
 * and registers them as WooCommerce downloadable file records on products.
 *
 * Fix v2.4.70:
 *  - Now checks that woocommerce_uploads destination directory is writable
 *    before attempting any file copy. Logs a clear, actionable error and marks
 *    item as FAILED (not processed) so it is retried on resume.
 *  - Pre-flight directory check is performed once per migration run via a
 *    static flag, not on every item, so there is no performance overhead.
 *
 * @package OctoWoo\Migrators
 */

namespace OctoWoo\Migrators;

defined( 'ABSPATH' ) || exit;

class DownloadMigrator extends AbstractMigrator {

	private const KEY = 'downloads';

	/** WP uploads sub-directory for migrated downloads. */
	private const UPLOAD_SUBDIR = 'opencart-downloads';

	/** @var bool|null Pre-flight upload-dir writable check result (null = not yet checked). */
	private static ?bool $upload_dir_ok = null;

	/** @var string|null Path to the destination uploads dir (set on first check). */
	private static ?string $dest_dir_path = null;

	// ── Entry point ───────────────────────────────────────────────────────────

	public function migrate(): array {
		$resume_id = $this->checkpoint->getLastId( self::KEY );

		if ( $resume_id === PHP_INT_MAX ) {
			$this->logger->info( '[downloads] Already completed – skipping.' );
			return array( 'processed' => 0, 'skipped' => 0, 'failed' => 0 );
		}

		// Pre-flight: verify upload destination is writable BEFORE processing any items.
		if ( ! $this->checkUploadDirWritable() ) {
			$this->checkpoint->init( self::KEY, 0 );
			$this->checkpoint->start( self::KEY );
			$this->checkpoint->fail( self::KEY );
			return array( 'processed' => 0, 'skipped' => 0, 'failed' => 1 );
		}

		$pfx = $this->pfx();

		$total_callback = function () use ( $pfx ): int {
			return (int) $this->oc->fetchColumn(
				"SELECT COUNT(DISTINCT product_id) FROM `{$pfx}product_to_download`"
			);
		};

		$batch_callback = function ( int $offset, int $limit ) use ( $pfx ): array {
			return $this->oc->fetchBatch(
				"SELECT DISTINCT product_id FROM `{$pfx}product_to_download` ORDER BY product_id ASC",
				array(),
				$limit,
				$offset
			);
		};

		$item_callback = function ( array $row ): bool {
			return $this->processProductDownloads( (int) $row['product_id'] );
		};

		return $this->batch->run(
			total_callback:  $total_callback,
			batch_callback:  $batch_callback,
			item_callback:   $item_callback,
			migrator:        self::KEY,
			checkpoint:      $this->checkpoint,
			resume_after_id: $resume_id,
			id_field:        'product_id'
		);
	}

	// ── Pre-flight writable check ─────────────────────────────────────────────

	/**
	 * Check that the destination upload directory exists and is writable.
	 * Uses a static cache so the check only runs once per PHP request.
	 *
	 * @return bool  True when the directory is ready for file writes.
	 */
	private function checkUploadDirWritable(): bool {
		if ( self::$upload_dir_ok !== null ) {
			return self::$upload_dir_ok;
		}

		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) ) {
			$this->logger->error(
				'[downloads] WordPress uploads directory error: ' . $upload_dir['error'] . ' — Download migration cannot proceed. Check your server file permissions for wp-content/uploads/.'
			);
			self::$upload_dir_ok = false;
			return false;
		}

		$dest_dir = trailingslashit( $upload_dir['basedir'] ) . self::UPLOAD_SUBDIR;

		// Create the sub-directory if it does not exist.
		if ( ! is_dir( $dest_dir ) ) {
			if ( ! wp_mkdir_p( $dest_dir ) ) {
				$this->logger->error(
					"[downloads] Cannot create upload directory: {$dest_dir} — "
					. 'Check that wp-content/uploads/ is writable by the web server user (e.g. run: chmod -R 755 wp-content/uploads/).'
				);
				self::$upload_dir_ok = false;
				return false;
			}
		}

		// Verify the directory is actually writable by attempting a test write.
		$test_file = $dest_dir . '/.octowoo_write_test';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$writable = @file_put_contents( $test_file, 'test' ) !== false;
		if ( $writable ) {
			@unlink( $test_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		if ( ! $writable ) {
			$this->logger->error(
				"[downloads] Upload directory is NOT writable: {$dest_dir} — "
				. 'Download migration cannot copy files. Fix with: chmod -R 755 wp-content/uploads/ '
				. 'and ensure the web server user owns the directory. '
				. 'Then click Resume to retry.'
			);
			self::$upload_dir_ok = false;
			return false;
		}

		// Also protect the directory from direct URL access.
		$htaccess = $dest_dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "Options -Indexes\ndeny from all\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		self::$upload_dir_ok  = true;
		self::$dest_dir_path  = $dest_dir;

		$this->logger->info( "[downloads] Upload directory ready: {$dest_dir}" );
		return true;
	}

	// ── Per-product processing ────────────────────────────────────────────────

	private function processProductDownloads( int $oc_product_id ): bool {
		$wc_product_id = $this->checkpoint->getWcId( 'product', $oc_product_id );
		if ( ! $wc_product_id ) {
			$this->logger->debug( "[downloads] OC product #{$oc_product_id} not in ID map – skipping." );
			return false;
		}

		$pfx     = $this->pfx();
		$lang_id = $this->langId();

		$rows = $this->oc->fetchAll(
			"SELECT d.download_id, d.filename, d.mask,
                COALESCE( dd_lang.name, dd_any.name, d.mask ) AS display_name
             FROM `{$pfx}product_to_download` ptd
             JOIN `{$pfx}download` d ON d.download_id = ptd.download_id
             LEFT JOIN `{$pfx}download_description` dd_lang
                    ON dd_lang.download_id = d.download_id
                   AND dd_lang.language_id = {$lang_id}
             LEFT JOIN (
                 SELECT download_id, name FROM `{$pfx}download_description` GROUP BY download_id
             ) dd_any ON dd_any.download_id = d.download_id
             WHERE ptd.product_id = ?",
			array( $oc_product_id )
		);

		if ( empty( $rows ) ) {
			return false;
		}

		$downloadable_files = array();

		foreach ( $rows as $row ) {
			$download_id = (int) $row['download_id'];
			$file_entry  = $this->resolveDownloadFile( $download_id, $row );
			if ( $file_entry ) {
				$downloadable_files[ $file_entry['id'] ] = $file_entry;
			}
		}

		if ( empty( $downloadable_files ) ) {
			return false;
		}

		if ( $this->isDry() ) {
			$this->logger->debug(
				sprintf( '[DRY-RUN] Would add %d download(s) to WC product #%d', count( $downloadable_files ), $wc_product_id )
			);
			return true;
		}

		$existing = get_post_meta( (int) $wc_product_id, '_downloadable_files', true );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		$merged = array_merge( $existing, $downloadable_files );

		update_post_meta( (int) $wc_product_id, '_downloadable',       'yes' );
		update_post_meta( (int) $wc_product_id, '_virtual',            'yes' );
		update_post_meta( (int) $wc_product_id, '_download_limit',     -1 );
		update_post_meta( (int) $wc_product_id, '_download_expiry',    -1 );
		update_post_meta( (int) $wc_product_id, '_downloadable_files', $merged );

		$this->logger->info( "[downloads] Registered " . count( $downloadable_files ) . " file(s) on WC product #{$wc_product_id}" );

		return true;
	}

	// ── File resolution ───────────────────────────────────────────────────────

	/**
	 * Copy the OC file into WP uploads and return the WC downloadable-files entry.
	 * Returns null when the source file cannot be located or copy fails.
	 */
	private function resolveDownloadFile( int $download_id, array $row ): ?array {
		$cached_attachment = $this->checkpoint->getWcId( 'download', $download_id );
		if ( $cached_attachment ) {
			$cached_url = (string) get_post_meta( (int) $cached_attachment, '_octowoo_download_url', true );
			if ( $cached_url ) {
				return $this->buildDownloadEntry( $cached_url, $row );
			}
		}

		$src_path = $this->resolveSourcePath( $row['filename'] ?? '' );
		if ( ! $src_path ) {
			$this->logger->warning( "[downloads] Source file not found for download #{$download_id} ({$row['filename']})" );
			return null;
		}

		$upload_dir    = wp_upload_dir();
		$dest_dir      = self::$dest_dir_path ?? ( trailingslashit( $upload_dir['basedir'] ) . self::UPLOAD_SUBDIR );
		$dest_filename = $this->buildDestFilename( $download_id, $row );
		$dest_path     = $dest_dir . '/' . $dest_filename;

		if ( ! is_file( $dest_path ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! @copy( $src_path, $dest_path ) ) {
				$error = error_get_last();
				$this->logger->error(
					"[downloads] File copy failed: {$src_path} → {$dest_path}. "
					. ( $error ? $error['message'] : 'Unknown error.' )
					. ' Check file permissions.'
				);
				return null;
			}
		}

		$file_url = trailingslashit( $upload_dir['baseurl'] ) . self::UPLOAD_SUBDIR . '/' . rawurlencode( $dest_filename );

		$attachment_id = $this->registerAttachment( $dest_path, $file_url, $row );

		if ( $attachment_id ) {
			update_post_meta( $attachment_id, '_octowoo_download_url', $file_url );
			update_post_meta( $attachment_id, '_octowoo_oc_id', $download_id );
			$this->checkpoint->saveIdMap( 'download', $download_id, $attachment_id );
		}

		return $this->buildDownloadEntry( $file_url, $row );
	}

	/**
	 * Build the absolute server path to the OC download file.
	 */
	private function resolveSourcePath( string $hashed_filename ): ?string {
		$hashed_filename = sanitize_file_name( basename( $hashed_filename ) );
		if ( ! $hashed_filename ) {
			return null;
		}

		// Config: opencart.download_path (explicit override).
		$explicit = $this->config['opencart']['download_path'] ?? '';
		if ( $explicit && is_dir( $explicit ) ) {
			$path = trailingslashit( $explicit ) . $hashed_filename;
			if ( is_file( $path ) ) {
				return $path;
			}
		}

		// Derive from image_path: go up to /system/storage/download/.
		$image_path = $this->config['opencart']['image_path'] ?? '';
		if ( $image_path ) {
			$candidates = array(
				dirname( $image_path ) . '/system/storage/download/' . $hashed_filename,
				dirname( $image_path, 2 ) . '/system/storage/download/' . $hashed_filename,
				dirname( $image_path ) . '/download/' . $hashed_filename,
			);
			foreach ( $candidates as $p ) {
				if ( is_file( $p ) ) {
					return $p;
				}
			}
		}

		return null;
	}

	/**
	 * Build a sanitised destination filename that is unique per download ID.
	 */
	private function buildDestFilename( int $download_id, array $row ): string {
		$mask = sanitize_file_name( $row['mask'] ?? '' );
		$ext  = pathinfo( $row['filename'] ?? '', PATHINFO_EXTENSION );
		$stem = $mask !== '' ? pathinfo( $mask, PATHINFO_FILENAME ) : "download-{$download_id}";
		$stem = sanitize_file_name( $stem );
		return $stem . '-' . $download_id . ( $ext ? ".{$ext}" : '' );
	}

	/**
	 * Register a basic media attachment for the copied file.
	 */
	private function registerAttachment( string $file_path, string $file_url, array $row ): int {
		$filetype   = wp_check_filetype( $file_path );
		$attachment = array(
			'post_title'     => sanitize_text_field( $row['display_name'] ?? basename( $file_path ) ),
			'post_mime_type' => $filetype['type'] ?: 'application/octet-stream',
			'post_status'    => 'inherit',
			'guid'           => $file_url,
		);
		$attach_id = wp_insert_attachment( $attachment, $file_path );
		if ( ! is_wp_error( $attach_id ) && function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $file_path ) );
		}
		return is_wp_error( $attach_id ) ? 0 : (int) $attach_id;
	}

	/**
	 * Build the WooCommerce downloadable-files array entry.
	 */
	private function buildDownloadEntry( string $url, array $row ): ?array {
		if ( ! $url ) {
			return null;
		}
		$key = md5( $url );
		return array(
			'id'   => $key,
			'name' => $this->sanitizeText( $row['display_name'] ?? basename( $url ) ),
			'file' => esc_url_raw( $url ),
		);
	}
}

<?php
/**
 * Image migrator.
 *
 * Copies image files from the OpenCart /image/ directory into the
 * WordPress media library using media_handle_sideload().
 *
 * Features:
 *  - Deduplication: MD5-hashes are stored in post_meta so the same file
 *    is never imported twice.
 *  - Organises uploads into a subdirectory: uploads/opencart-migration/
 *  - Returns the WP attachment ID so callers can set featured / gallery images.
 *  - Used by both CategoryMigrator (for thumbnails) and ProductMigrator.
 */

namespace OctoWoo\Migrators;

defined( 'ABSPATH' ) || exit;

class ImageMigrator extends AbstractMigrator {

    /** Meta key used to look up an attachment by its OC image path. */
    private const META_KEY_OC_PATH = '_octowoo_oc_image_path';

    /** Meta key storing the file's MD5 hash (dedup). */
    private const META_KEY_MD5 = '_octowoo_image_md5';

    /** Sub-folder inside uploads directory. */
    private const UPLOAD_SUBDIR = 'opencart-migration';

    // ── Entry point (implements AbstractMigrator::migrate) ────────────────────

    /**
     * The ImageMigrator is driven directly by ProductMigrator / CategoryMigrator
     * rather than running a standalone batch over OC records.
     *
     * When called via MigrationManager as part of the pipeline, we scan
     * product records that have images and ensure they are imported.
     *
     * @return array{processed: int, skipped: int, failed: int}
     */
    public function migrate(): array {
        // In the standard pipeline the ImageMigrator piggybacks on ProductMigrator.
        // Standalone: import all product images that haven't been imported yet.
        $pfx = $this->pfx();

        $resume_id = $this->checkpoint->getLastId( 'images' );
        if ( $resume_id === PHP_INT_MAX ) {
            $this->logger->info( '[images] Already completed – skipping.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
        }

        $stats = [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];

        // Collect all distinct image paths from oc_product and oc_product_image.
        $paths = $this->oc->fetchAll(
            "SELECT DISTINCT image AS path FROM `{$pfx}product` WHERE image != '' AND image IS NOT NULL
             UNION
             SELECT DISTINCT image AS path FROM `{$pfx}product_image` WHERE image != '' AND image IS NOT NULL
             UNION
             SELECT DISTINCT image AS path FROM `{$pfx}category` WHERE image != '' AND image IS NOT NULL"
        );

        $this->checkpoint->init( 'images', count( $paths ) );
        $this->checkpoint->start( 'images' );

        foreach ( $paths as $row ) {
            $path = $row['path'];
            if ( empty( $path ) ) {
                continue;
            }

            $attachment_id = $this->importByOcPath( $path );
            if ( $attachment_id ) {
                $stats['processed']++;
            } else {
                $stats['failed']++;
            }
        }

        $this->checkpoint->complete( 'images' );
        $this->logger->success(
            "[images] Done. processed={$stats['processed']}, failed={$stats['failed']}"
        );

        return $stats;
    }

    // ── Core import method ────────────────────────────────────────────────────

    /**
     * Import a single image by its OpenCart-relative path (e.g. "catalog/product/foo.jpg").
     *
     * @param string $oc_path  Path relative to OpenCart's /image/ directory.
     * @return int|null  WP attachment post ID, or null on failure.
     */
    public function importByOcPath( string $oc_path ): ?int {
        if ( empty( $oc_path ) ) {
            return null;
        }

        // Check cache first.
        $cached = $this->findAttachmentByOcPath( $oc_path );
        if ( $cached ) {
            return $cached;
        }

        $abs_source = $this->resolveSourcePath( $oc_path );
        if ( ! $abs_source || ! file_exists( $abs_source ) ) {
            $this->logger->warning( "[images] Source file not found: {$abs_source}" );
            return null;
        }

        // Dedup by MD5 hash.
        $hash   = md5_file( $abs_source );
        $by_hash = $this->findAttachmentByHash( $hash );
        if ( $by_hash ) {
            // Already imported – store the OC path mapping and return.
            update_post_meta( $by_hash, self::META_KEY_OC_PATH, $oc_path );
            return $by_hash;
        }

        if ( $this->isDry() ) {
            $this->logger->debug( "[DRY-RUN] Would import image: {$oc_path}" );
            return -1; // Placeholder ID in dry-run mode.
        }

        return $this->sideloadFile( $abs_source, $oc_path, $hash );
    }

    // ── Sideloading ───────────────────────────────────────────────────────────

    private function sideloadFile( string $abs_source, string $oc_path, string $hash ): ?int {
        // WordPress sideload functions require this file.
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        // Copy to a temp file that WP can process.
        $tmp = $this->copyToTemp( $abs_source );
        if ( ! $tmp ) {
            return null;
        }

        $filename   = basename( $oc_path );
        $file_array = [
            'name'     => $filename,
            'tmp_name' => $tmp,
        ];

        // Determine the upload sub-directory.
        add_filter( 'upload_dir', [ $this, 'filterUploadDir' ] );

        $attachment_id = media_handle_sideload( $file_array, 0, null );

        remove_filter( 'upload_dir', [ $this, 'filterUploadDir' ] );

        // Clean up temp file if sideload didn't consume it.
        if ( file_exists( $tmp ) ) {
            @unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }

        if ( is_wp_error( $attachment_id ) ) {
            $this->logger->error(
                "[images] media_handle_sideload failed for [{$oc_path}]: " . $attachment_id->get_error_message()
            );
            return null;
        }

        // Store metadata for dedup and lookup.
        update_post_meta( $attachment_id, self::META_KEY_OC_PATH, $oc_path );
        update_post_meta( $attachment_id, self::META_KEY_MD5, $hash );

        $this->logger->debug( "[images] Imported attachment #{$attachment_id}: {$oc_path}" );
        return (int) $attachment_id;
    }

    /**
     * Upload-dir filter to isolate migrated images into their own subfolder.
     *
     * @param array<string, mixed> $dirs
     * @return array<string, mixed>
     */
    public function filterUploadDir( array $dirs ): array {
        $sub            = '/' . self::UPLOAD_SUBDIR;
        $dirs['subdir'] = $sub;
        $dirs['path']   = $dirs['basedir'] . $sub;
        $dirs['url']    = $dirs['baseurl'] . $sub;
        return $dirs;
    }

    // ── Lookup helpers ────────────────────────────────────────────────────────

    /**
     * Find an existing WP attachment by its OC image path meta.
     */
    public function findAttachmentByOcPath( string $oc_path ): ?int {
        global $wpdb;

        $id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = %s AND meta_value = %s
                 LIMIT 1",
                self::META_KEY_OC_PATH,
                $oc_path
            )
        );

        return $id ? (int) $id : null;
    }

    /**
     * Find an existing WP attachment by its file MD5 hash.
     */
    private function findAttachmentByHash( string $hash ): ?int {
        global $wpdb;

        $id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = %s AND meta_value = %s
                 LIMIT 1",
                self::META_KEY_MD5,
                $hash
            )
        );

        return $id ? (int) $id : null;
    }

    // ── Path resolution ───────────────────────────────────────────────────────

    /**
     * Build an absolute path on disk for an OC image path.
     * OpenCart stores paths like "catalog/product/foo.jpg" relative to /image/.
     */
    private function resolveSourcePath( string $oc_path ): ?string {
        // Local mode: images were extracted from a ZIP upload.
        if ( ( $this->config['opencart']['image_source'] ?? 'remote' ) === 'local' ) {
            $image_base = rtrim( \OctoWoo\Core\SqlImporter::getImagesDir(), '/\\' );
        } else {
            $image_base = rtrim( $this->config['opencart']['image_path'] ?? '', '/\\' );
        }

        if ( empty( $image_base ) ) {
            $this->logger->warning( '[images] opencart.image_path is not configured.' );
            return null;
        }

        // Prevent directory traversal.
        $safe = ltrim( str_replace( '\\', '/', $oc_path ), '/' );
        if ( strpos( $safe, '..' ) !== false ) {
            $this->logger->warning( "[images] Suspicious path rejected: {$oc_path}" );
            return null;
        }

        return $image_base . DIRECTORY_SEPARATOR . $safe;
    }

    /**
     * Copy a file to PHP's temp directory so WP can sideload it safely.
     */
    private function copyToTemp( string $source ): ?string {
        $ext     = pathinfo( $source, PATHINFO_EXTENSION );
        $tmp     = tempnam( sys_get_temp_dir(), 'octowoo_' ) . '.' . $ext;

        if ( ! copy( $source, $tmp ) ) {
            $this->logger->error( "[images] Failed to copy [{$source}] to temp." );
            return null;
        }

        return $tmp;
    }
}

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

    /** Prevent repeating the same local-path warning for every image row. */
    private bool $localPathWarningLogged = false;

    /**
     * Circuit breaker: set to true after the first connection-level failure
     * (timeout, DNS error, etc.) so we stop hammering an unreachable host
     * for the remainder of this migration chunk.
     */
    private bool $remoteHostDown = false;

    /**
     * v2.4.72: Circuit breaker is now PER-HOST (not global).
     * One unreachable host no longer kills all remote image fetching.
     */
    private static function remoteDownKey( string $url ): string {
        $host = parse_url( $url, PHP_URL_HOST ) ?: $url;
        return 'octowoo_img_down_' . md5( strtolower( $host ) );
    }

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
        if ( ! $this->shouldImportImages() ) {
            $this->checkpoint->init( 'images', 0 );
            $this->checkpoint->start( 'images' );
            $this->checkpoint->complete( 'images' );
            $this->logger->info( '[images] Image import disabled by settings (run_images=false) – skipping.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
        }

        $resume_id = $this->checkpoint->getLastId( 'images' );
        if ( $resume_id === PHP_INT_MAX ) {
            $this->logger->info( '[images] Already completed – skipping.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
        }

        $stats      = [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
        $batch_size = max( 1, (int) ( $this->config['migration']['batch_size'] ?? 20 ) );
        $demo_limit = max( 0, (int) ( $this->config['migration']['demo_limit'] ?? 0 ) );

        // Use one canonical image source query so total + paginated reads are consistent.
        $pfx      = $this->pfx();
        $base_sql = "SELECT DISTINCT image AS path FROM `{$pfx}product` WHERE image != '' AND image IS NOT NULL
                     UNION
                     SELECT DISTINCT image AS path FROM `{$pfx}product_image` WHERE image != '' AND image IS NOT NULL
                     UNION
                     SELECT DISTINCT image AS path FROM `{$pfx}category` WHERE image != '' AND image IS NOT NULL";

        $total = (int) $this->oc->fetchColumn( "SELECT COUNT(*) FROM ({$base_sql}) AS octowoo_img" );
        if ( $demo_limit > 0 ) {
            $total = min( $total, $demo_limit );
        }

        $offset = $this->checkpoint->getProcessedCount( 'images' );

        if ( $offset === 0 ) {
            $this->checkpoint->init( 'images', $total );
            $this->checkpoint->start( 'images' );
        }

        if ( $offset >= $total ) {
            $this->checkpoint->complete( 'images' );
            return $stats;
        }

        // In chunk mode process exactly one page; in full mode loop to completion.
        $single_pass = $this->batch->isChunkMode();

        while ( $offset < $total ) {
            $limit = min( $batch_size, $total - $offset );
            $rows  = $this->oc->fetchAll(
                "SELECT path FROM ({$base_sql}) AS octowoo_img
                 ORDER BY path ASC
                 LIMIT {$limit} OFFSET {$offset}"
            );

            if ( empty( $rows ) ) {
                break;
            }

            $batch_done = 0;
            foreach ( $rows as $row ) {
                $path = (string) ( $row['path'] ?? '' );
                if ( $path === '' ) {
                    continue;
                }

                $attachment_id = $this->importByOcPath( $path );
                if ( $attachment_id ) {
                    $stats['processed']++;
                } else {
                    // We intentionally count missing/unreachable files as failed
                    // but still advance progress so images cannot stall the run.
                    $stats['failed']++;
                }
                $batch_done++;
            }

            if ( $batch_done > 0 ) {
                // Use a synthetic increasing last_id for offset-based datasets.
                $this->checkpoint->update( 'images', $offset + $batch_done, $batch_done );
                $offset += $batch_done;
            } else {
                // Safety: avoid infinite loops if rows were all empty.
                $offset += count( $rows );
                $this->checkpoint->update( 'images', $offset, count( $rows ) );
            }

            if ( $single_pass ) {
                break;
            }
        }

        if ( $offset >= $total ) {
            $this->checkpoint->complete( 'images' );
            $this->logger->success(
                "[images] Done. processed={$stats['processed']}, failed={$stats['failed']}"
            );
        }

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

        // Always check cache first — even when image import is disabled for this run,
        // an attachment already in the media library can still be used as a thumbnail.
        // This covers the case where images were imported in a previous run or in a
        // prior step of the same run (the standalone Images migrator runs before Products).
        $cached = $this->findAttachmentByOcPath( $oc_path );
        if ( $cached ) {
            return $cached;
        }

        // Import gate: only attempt sideloading when the images step is enabled.
        // Avoids hammering the source server when the admin selectively re-runs only
        // specific migrators (e.g., Products only) without enabling Images.
        if ( ! $this->shouldImportImages() ) {
            return null;
        }

        $is_local_source = ( $this->config['opencart']['image_source'] ?? 'remote' ) === 'local';

        $abs_source = $this->resolveSourcePath( $oc_path );

        // Local mode with an unavailable base path should not spam retries.
        if ( $is_local_source && ! $abs_source ) {
            return null;
        }

        // Strategy 1: local filesystem.
        if ( $abs_source && file_exists( $abs_source ) ) {
            // Dedup by MD5 hash.
            $hash    = md5_file( $abs_source );
            $by_hash = $this->findAttachmentByHash( $hash );
            if ( $by_hash ) {
                update_post_meta( $by_hash, self::META_KEY_OC_PATH, $oc_path );
                return $by_hash;
            }

            if ( $this->isDry() ) {
                $this->logger->debug( "[DRY-RUN] Would import image (local): {$oc_path}" );
                return -1;
            }

            return $this->sideloadFile( $abs_source, $oc_path, $hash );
        }

        // In local-source mode we intentionally skip remote fallback when files are
        // not accessible on this server (e.g. path not mounted / unreadable).
        if ( $is_local_source ) {
            $this->logger->debug( "[images] Local source file missing; skipping image: {$oc_path}" );
            return null;
        }

        // Local file not present — attempt remote fetch strategies.
        // v2.4.72: Per-host circuit breaker — check only when we know the target URL.
        // The instance-level $remoteHostDown flag is now only used within a single chunk.
        // Across chunks, the per-host transient is authoritative.

        $this->logger->warning( "[images] Source file not found locally: {$abs_source}. Trying HTTP fallback for {$oc_path}." );

        // v2.4.72: Fast-fail per-host if circuit breaker tripped from prior chunk.
        if ( $this->remoteHostDown ) {
            return null;
        }

        if ( $this->isDry() ) {
            $this->logger->debug( "[DRY-RUN] Would attempt remote fetch for: {$oc_path}" );
            return -1;
        }

        // Strategy 2: if oc_path is already a URL, try it directly.
        if ( preg_match( '#^https?://#i', $oc_path ) ) {
            $aid = $this->tryRemoteSideload( $oc_path, $oc_path );
            if ( $aid ) {
                update_post_meta( $aid, self::META_KEY_OC_PATH, $oc_path );
                return $aid;
            }
        }

        // Strategy 3: construct from configured shop_url + '/image/' + oc_path.
        $shop = rtrim( $this->config['opencart']['shop_url'] ?? '', '/ ' );
        if ( $shop !== '' ) {
            $url = $shop . '/image/' . ltrim( $oc_path, '/\\' );
            $aid = $this->tryRemoteSideload( $url, $oc_path );
            if ( $aid ) {
                update_post_meta( $aid, self::META_KEY_OC_PATH, $oc_path );
                return $aid;
            }
        }

        // Strategy 4: try shop base + relative path (some OC setups omit /image/ prefix).
        if ( $shop !== '' ) {
            $url2 = $shop . '/' . ltrim( $oc_path, '/\\' );
            $aid  = $this->tryRemoteSideload( $url2, $oc_path );
            if ( $aid ) {
                update_post_meta( $aid, self::META_KEY_OC_PATH, $oc_path );
                return $aid;
            }
        }

        $this->logger->warning( "[images] Remote fetch failed for: {$oc_path}" );
        return null;
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

        // JOIN with wp_posts to guarantee we only return an actual media-library
        // attachment.  ProductMigrator also stores '_octowoo_oc_image_path' on the
        // product post (for multilingual-pass retry), so without the post_type
        // filter a plain wp_postmeta lookup can return the product's own post_id
        // instead of the attachment — making _thumbnail_id point to the wrong post
        // and causing the featured image to appear missing on the storefront.
        $id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT pm.post_id
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key  = %s
                   AND pm.meta_value = %s
                   AND p.post_type  = 'attachment'
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
            if ( ! $this->localPathWarningLogged ) {
                $this->logger->warning( '[images] opencart.image_path is not configured. Image imports will be skipped.' );
                $this->localPathWarningLogged = true;
            }
            return null;
        }

        // Cloudways path normalization (/home vs /mnt/data/home).
        $image_base = $this->resolveImageBasePath( $image_base );

        if ( ! is_dir( $image_base ) || ! is_readable( $image_base ) ) {
            if ( ! $this->localPathWarningLogged ) {
                $this->logger->warning(
                    "[images] Local image path unavailable or unreadable: {$image_base}. " .
                    'Image imports will be skipped for this run.'
                );
                $this->localPathWarningLogged = true;
            }
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
     * Resolve equivalent filesystem roots used on some managed hosts.
     */
    private function resolveImageBasePath( string $base ): string {
        $base = rtrim( $base, '/\\' );

        $candidates = [ $base ];
        if ( strpos( $base, '/home/' ) === 0 ) {
            $candidates[] = '/mnt/data' . $base;
        }
        if ( strpos( $base, '/mnt/data/home/' ) === 0 ) {
            $candidates[] = substr( $base, strlen( '/mnt/data' ) );
        }

        foreach ( array_unique( $candidates ) as $candidate ) {
            if ( is_dir( $candidate ) && is_readable( $candidate ) ) {
                return rtrim( $candidate, '/\\' );
            }
        }

        return $base;
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

    /**
     * Try to download a remote URL to a temp file and sideload it.
     * Returns the attachment ID on success or null on failure.
     */
    private function tryRemoteSideload( string $url, string $oc_path ): ?int {
        $tmp = $this->downloadToTemp( $url );
        if ( ! $tmp ) {
            $this->logger->warning( "[images] Remote download failed: {$url}" );
            return null;
        }

        // Dedup by MD5 hash.
        $hash = md5_file( $tmp );
        if ( $hash ) {
            $by_hash = $this->findAttachmentByHash( $hash );
            if ( $by_hash ) {
                update_post_meta( $by_hash, self::META_KEY_OC_PATH, $oc_path );
                if ( file_exists( $tmp ) ) {
                    @unlink( $tmp );
                }
                return $by_hash;
            }
        }

        $attachment_id = $this->sideloadFile( $tmp, $oc_path, $hash );

        if ( file_exists( $tmp ) ) {
            @unlink( $tmp );
        }

        return $attachment_id;
    }

    /**
     * Download remote URL to a temporary file and return the path, or null.
     */
    private function downloadToTemp( string $url ): ?string {
        // Fast-fail if circuit breaker tripped for this specific host.
        if ( $this->remoteHostDown || get_transient( self::remoteDownKey( $url ) ) ) {
            return null;
        }

        if ( ! function_exists( 'wp_remote_get' ) ) {
            require_once ABSPATH . 'wp-includes/http.php';
        }

        $resp = wp_remote_get( $url, [ 'timeout' => 10, 'redirection' => 3, 'sslverify' => false ] );
        if ( is_wp_error( $resp ) ) {
            // Connection-level failure (timeout, DNS, refused) — trip the circuit breaker
            // and persist it via transient so subsequent AJAX chunks skip remote too.
            $this->remoteHostDown = true;
            // v2.4.72: Store circuit-breaker per host so only THIS host is blocked.
            set_transient( self::remoteDownKey( $url ), 1, 30 * MINUTE_IN_SECONDS );
            $this->logger->warning( '[images] Remote host unreachable (' . $resp->get_error_message() . '). Disabling fetching from this host for 30 min.' );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $resp );
        if ( (int) $code !== 200 ) {
            return null;
        }

        $body = wp_remote_retrieve_body( $resp );
        if ( $body === '' ) {
            return null;
        }

        $path = parse_url( $url, PHP_URL_PATH );
        $ext  = $path ? pathinfo( $path, PATHINFO_EXTENSION ) : '';
        $tmp  = tempnam( sys_get_temp_dir(), 'octowoo_dl_' ) . ( $ext ? '.' . $ext : '' );

        if ( file_put_contents( $tmp, $body ) === false ) {
            if ( file_exists( $tmp ) ) {
                @unlink( $tmp );
            }
            return null;
        }

        return $tmp;
    }

    // ── Strategy 2: HTTP remote fetch ─────────────────────────────────────────

    /**
     * Attempt to fetch an image from the configured OpenCart shop URL.
     *
     * URL constructed as:  {shop_url}/image/{oc_path}
     *
     * @param string $oc_path  OC-relative path, e.g. "catalog/product/foo.jpg".
     * @return int|null  WP attachment ID, or null on failure.
     */
    private function tryRemoteStrategy( string $oc_path ): ?int {
        $shop_url = rtrim( $this->config['opencart']['shop_url'] ?? '', '/' );

        if ( $shop_url === '' ) {
            $this->logger->debug( "[images] No shop_url configured; remote fetch unavailable for: {$oc_path}" );
            return null;
        }

        $safe = ltrim( str_replace( '\\', '/', $oc_path ), '/' );
        if ( strpos( $safe, '..' ) !== false ) {
            $this->logger->warning( "[images] Rejected suspicious path (remote): {$oc_path}" );
            return null;
        }

        // Only allow http/https shop URLs (block SSRF to internal networks).
        if ( ! preg_match( '/^https?:\/\//i', $shop_url ) ) {
            $this->logger->warning( "[images] shop_url must use http/https; skipping remote fetch: {$oc_path}" );
            return null;
        }

        $remote_url = $shop_url . '/image/' . $safe;

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $tmp = download_url( $remote_url, 30 );

        if ( is_wp_error( $tmp ) ) {
            $this->logger->debug(
                "[images] Remote fetch failed [{$remote_url}]: " . $tmp->get_error_message()
            );
            return null;
        }

        // Dedup by hash.
        $hash    = md5_file( $tmp );
        $by_hash = $this->findAttachmentByHash( $hash );
        if ( $by_hash ) {
            @unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            update_post_meta( $by_hash, self::META_KEY_OC_PATH, $oc_path );
            return $by_hash;
        }

        if ( $this->isDry() ) {
            @unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            $this->logger->debug( "[DRY-RUN] Would import remote image: {$oc_path}" );
            return -1;
        }

        $attachment_id = $this->sideloadFromTemp( $tmp, $oc_path, $hash );

        // sideloadFromTemp / media_handle_sideload deletes $tmp on success.
        // Clean up ourselves only if it still exists (failure path).
        if ( null === $attachment_id && file_exists( $tmp ) ) {
            @unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }

        if ( $attachment_id ) {
            $this->logger->debug( "[images] Remote fetch succeeded #{$attachment_id}: {$oc_path}" );
        }

        return $attachment_id;
    }

    /**
     * Sideload a file that is already in a temp location (e.g. from download_url()).
     * Skips the copy-to-temp step used by sideloadFile().
     *
     * @param string $tmp       Absolute path to the existing temp file.
     * @param string $oc_path   OC-relative path (used for meta and filename).
     * @param string $hash      MD5 hash of the file (already computed by caller).
     * @return int|null  WP attachment ID, or null on failure.
     */
    private function sideloadFromTemp( string $tmp, string $oc_path, string $hash ): ?int {
        $file_array = [
            'name'     => basename( $oc_path ),
            'tmp_name' => $tmp,
        ];

        add_filter( 'upload_dir', [ $this, 'filterUploadDir' ] );
        $attachment_id = media_handle_sideload( $file_array, 0, null );
        remove_filter( 'upload_dir', [ $this, 'filterUploadDir' ] );

        if ( is_wp_error( $attachment_id ) ) {
            $this->logger->error(
                "[images] media_handle_sideload (remote) failed for [{$oc_path}]: "
                . $attachment_id->get_error_message()
            );
            return null;
        }

        update_post_meta( $attachment_id, self::META_KEY_OC_PATH, $oc_path );
        update_post_meta( $attachment_id, self::META_KEY_MD5, $hash );

        return (int) $attachment_id;
    }
}

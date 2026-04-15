<?php
/**
 * Download migrator.
 *
 * Reads OpenCart downloadable files (oc_download + oc_product_to_download)
 * and registers them as WooCommerce downloadable file records on products.
 *
 * Files are copied from the OpenCart download storage directory to the WordPress
 * uploads directory so customers can access them via WooCommerce's secure
 * download endpoint.
 *
 * OpenCart tables used:
 *   oc_download             – download_id, filename (hashed), mask (display name)
 *   oc_download_description – download_id, language_id, name
 *   oc_product_to_download  – product_id, download_id
 *
 * Must run AFTER ProductMigrator.
 *
 * Config key used:
 *   opencart.download_path  – absolute server path to the OC download directory
 *                            (default: opencart.image_path/../system/storage/download)
 *
 * @package OctoWoo\Migrators
 */

namespace OctoWoo\Migrators;

defined( 'ABSPATH' ) || exit;

class DownloadMigrator extends AbstractMigrator {

    private const KEY = 'downloads';

    /** WP uploads sub-directory for migrated downloads. */
    private const UPLOAD_SUBDIR = 'opencart-downloads';

    // ── Entry point ───────────────────────────────────────────────────────────

    public function migrate(): array {
        $resume_id = $this->checkpoint->getLastId( self::KEY );

        if ( $resume_id === PHP_INT_MAX ) {
            $this->logger->info( '[downloads] Already completed – skipping.' );
            return [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ];
        }

        $pfx = $this->pfx();

        // Iterate unique OC products that have downloads.
        $total_callback = function () use ( $pfx ): int {
            return (int) $this->oc->fetchColumn(
                "SELECT COUNT(DISTINCT product_id) FROM `{$pfx}product_to_download`"
            );
        };

        $batch_callback = function ( int $offset, int $limit ) use ( $pfx ): array {
            return $this->oc->fetchBatch(
                "SELECT DISTINCT product_id FROM `{$pfx}product_to_download` ORDER BY product_id ASC",
                [],
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

    // ── Per-product processing ────────────────────────────────────────────────

    private function processProductDownloads( int $oc_product_id ): bool {
        $wc_product_id = $this->checkpoint->getWcId( 'product', $oc_product_id );
        if ( ! $wc_product_id ) {
            $this->logger->debug( "[downloads] OC product #{$oc_product_id} not in ID map – skipping." );
            return false;
        }

        $pfx     = $this->pfx();
        $lang_id = $this->langId();

        // Fetch all downloads for this product.
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
            [ $oc_product_id ]
        );

        if ( empty( $rows ) ) {
            return false;
        }

        $downloadable_files = [];

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
                sprintf(
                    '[DRY-RUN] Would add %d download(s) to WC product #%d',
                    count( $downloadable_files ),
                    $wc_product_id
                )
            );
            return true;
        }

        // Merge with any existing downloadable files.
        $existing = get_post_meta( (int) $wc_product_id, '_downloadable_files', true );
        if ( ! is_array( $existing ) ) {
            $existing = [];
        }
        $merged = array_merge( $existing, $downloadable_files );

        update_post_meta( (int) $wc_product_id, '_downloadable',      'yes' );
        update_post_meta( (int) $wc_product_id, '_virtual',           'yes' );
        update_post_meta( (int) $wc_product_id, '_download_limit',    -1 );   // unlimited
        update_post_meta( (int) $wc_product_id, '_download_expiry',   -1 );   // never expires
        update_post_meta( (int) $wc_product_id, '_downloadable_files', $merged );

        $this->logger->info(
            "[downloads] Registered " . count( $downloadable_files ) . " file(s) on WC product #{$wc_product_id}"
        );

        return true;
    }

    // ── File resolution ───────────────────────────────────────────────────────

    /**
     * Copy the OC file into WP uploads and return the WC downloadable-files entry.
     * Returns null when the source file cannot be located.
     *
     * @param  int   $download_id  OC download_id (used for dedup via ID map).
     * @param  array $row          OC download row (filename, mask, display_name).
     * @return array<string, mixed>|null
     */
    private function resolveDownloadFile( int $download_id, array $row ): ?array {
        // Check ID map first (file previously processed).
        $cached_attachment = $this->checkpoint->getWcId( 'download', $download_id );
        if ( $cached_attachment ) {
            return $this->buildDownloadEntry( (string) get_post_meta( (int) $cached_attachment, '_octowoo_download_url', true ), $row );
        }

        $src_path = $this->resolveSourcePath( $row['filename'] ?? '' );
        if ( ! $src_path ) {
            $this->logger->warning( "[downloads] Source file not found for download #{$download_id} ({$row['filename']})" );
            return null;
        }

        // Determine destination.
        $upload_dir = wp_upload_dir();
        $dest_dir   = trailingslashit( $upload_dir['basedir'] ) . self::UPLOAD_SUBDIR;

        if ( ! is_dir( $dest_dir ) ) {
            wp_mkdir_p( $dest_dir );
        }

        $dest_filename = $this->buildDestFilename( $download_id, $row );
        $dest_path     = $dest_dir . '/' . $dest_filename;

        if ( ! is_file( $dest_path ) ) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            if ( ! @copy( $src_path, $dest_path ) ) {
                $this->logger->error( "[downloads] Could not copy file: {$src_path} → {$dest_path}" );
                return null;
            }
        }

        $file_url = trailingslashit( $upload_dir['baseurl'] ) . self::UPLOAD_SUBDIR . '/' . $dest_filename;

        // Create a media attachment so WC can serve it properly.
        $attachment_id = $this->registerAttachment( $dest_path, $file_url, $row );

        if ( $attachment_id ) {
            add_post_meta( $attachment_id, '_octowoo_download_url', $file_url, true );
            $this->checkpoint->saveIdMap( 'download', $download_id, $attachment_id );
        }

        return $this->buildDownloadEntry( $file_url, $row );
    }

    /**
     * Build the absolute server path to the OC download file.
     */
    private function resolveSourcePath( string $hashed_filename ): ?string {
        $hashed_filename = sanitize_file_name( basename( $hashed_filename ) );

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
            $candidates = [
                dirname( $image_path ) . '/system/storage/download/' . $hashed_filename,
                dirname( $image_path, 2 ) . '/system/storage/download/' . $hashed_filename,
            ];
            foreach ( $candidates as $p ) {
                if ( is_file( $p ) ) {
                    return $p;
                }
            }
        }

        return null;
    }

    /**
     * Build a sanitised destination filename.
     */
    private function buildDestFilename( int $download_id, array $row ): string {
        $mask = sanitize_file_name( $row['mask'] ?? '' );
        $ext  = pathinfo( $row['filename'] ?? '', PATHINFO_EXTENSION );
        $stem = $mask !== '' ? pathinfo( $mask, PATHINFO_FILENAME ) : "download-{$download_id}";
        return $stem . '-' . $download_id . ( $ext ? ".{$ext}" : '' );
    }

    /**
     * Register a basic media attachment for the copied file.
     */
    private function registerAttachment( string $file_path, string $file_url, array $row ): int {
        $filetype = wp_check_filetype( $file_path );
        $attachment = [
            'post_title'     => sanitize_text_field( $row['display_name'] ?? basename( $file_path ) ),
            'post_mime_type' => $filetype['type'] ?: 'application/octet-stream',
            'post_status'    => 'inherit',
            'guid'           => $file_url,
        ];
        $attach_id = wp_insert_attachment( $attachment, $file_path );
        if ( ! is_wp_error( $attach_id ) && function_exists( 'wp_generate_attachment_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $file_path ) );
        }
        return is_wp_error( $attach_id ) ? 0 : (int) $attach_id;
    }

    /**
     * Build the WooCommerce downloadable-files array entry format.
     *
     * @return array<string, string>
     */
    private function buildDownloadEntry( string $url, array $row ): ?array {
        if ( ! $url ) {
            return null;
        }
        $key = md5( $url );
        return [
            'id'   => $key,
            'name' => $this->sanitizeText( $row['display_name'] ?? basename( $url ) ),
            'file' => esc_url_raw( $url ),
        ];
    }
}

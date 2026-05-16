<?php
/**
 * Abstract base for all migrator classes.
 *
 * Injects the four shared services every migrator needs and exposes
 * common helper utilities (duplicate detection, WC term helpers, etc.).
 */

namespace OctoWoo\Migrators;

use OctoWoo\Core\DatabaseConnector;
use OctoWoo\Core\Logger;
use OctoWoo\Core\CheckpointManager;
use OctoWoo\Core\BatchProcessor;

defined( 'ABSPATH' ) || exit;

abstract class AbstractMigrator {

    /** @var DatabaseConnector OpenCart database. */
    protected DatabaseConnector $oc;

    /** @var Logger */
    protected Logger $logger;

    /** @var CheckpointManager */
    protected CheckpointManager $checkpoint;

    /** @var BatchProcessor */
    protected BatchProcessor $batch;

    /** @var array<string, mixed> Full resolved config. */
    protected array $config;

    // ── Construction ─────────────────────────────────────────────────────────

    public function __construct(
        DatabaseConnector $oc,
        Logger            $logger,
        CheckpointManager $checkpoint,
        BatchProcessor    $batch,
        array             $config
    ) {
        $this->oc         = $oc;
        $this->logger     = $logger;
        $this->checkpoint = $checkpoint;
        $this->batch      = $batch;
        $this->config     = $config;
    }

    // ── Contract ──────────────────────────────────────────────────────────────

    /**
     * Run the migration for this entity type.
     *
     * @return array{processed: int, skipped: int, failed: int}
     */
    abstract public function migrate(): array;

    // ── Shared helpers ────────────────────────────────────────────────────────

    /**
     * Return the resolved OC table prefix (default "oc_").
     */
    protected function pfx(): string {
        return $this->oc->getPrefix();
    }

    /**
     * True when in dry-run mode (no writes should occur).
     */
    protected function isDry(): bool {
        return $this->batch->isDryRun();
    }

    /**
     * Return the configured OC language ID (primary language).
     */
    protected function langId(): int {
        return (int) ( $this->config['opencart']['language_id'] ?? 1 );
    }

    /**
     * Return the secondary OC language ID (e.g. Arabic = 2).  0 = disabled.
     */
    protected function langIdSecondary(): int {
        return (int) ( $this->config['opencart']['language_id_secondary'] ?? 0 );
    }

    /**
     * Return the meta-key suffix used to store secondary-language data,
     * derived from the configured secondary_locale (e.g. '_ar', '_fr', '_de').
     * All migrators write and WpmlIntegration reads with this same suffix.
     */
    /**
     * v2.4.72: Return the detected OpenCart major version (1, 2, 3, or 4).
     * Used by migrators to emit version-adaptive SQL for OC1 schema differences.
     * Returns 0 when the version cannot be determined (treat as OC2+).
     */
    protected function ocMajor(): int {
        $override = (int) ( $this->config['opencart']['version'] ?? 0 );
        if ( $override > 0 && $override < 5 ) {
            return $override;
        }
        try {
            $detector = new \OctoWoo\Core\VersionDetector( $this->oc );
            return $detector->getMajor();
        } catch ( \Throwable $e ) {
            return 0; // Fallback — treat as modern OC.
        }
    }


    protected function secLangSuffix(): string {
        // Normalise to first segment so 'ar_SA' → '_ar', 'ar' → '_ar'.
        // This ensures all migrators write to the same meta key regardless of
        // whether the user configured a short code ('ar') or full locale ('ar_SA').
        $locale = $this->config['multilingual']['secondary_locale'] ?? 'ar';
        return '_' . strtolower( explode( '_', $locale )[0] );
    }

    /**
     * Return the configured WPML/Polylang primary language code (e.g. 'en').
     */
    protected function primaryLocale(): string {
        return (string) ( $this->config['multilingual']['primary_locale'] ?? 'en' );
    }

    /**
     * If WPML is active, switch the current language to the configured primary
     * locale so that any subsequent wp_insert_post() / wp_insert_term() calls
     * are automatically assigned to the primary language by WPML.
     *
     * Call wpmlRestoreLanguage() after the batch to reset.
     */
    protected function wpmlSwitchToPrimary(): void {
        if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
            do_action( 'wpml_switch_language', $this->primaryLocale() );
        }
    }

    /**
     * Restore WPML to the default language after a batch.
     */
    protected function wpmlRestoreLanguage(): void {
        if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
            do_action( 'wpml_switch_language', null );
        }
    }

    /**
     * Sanitise a string value for use as a WordPress post slug.
     * Preserves Arabic characters (does NOT transliterate them).
     */
    protected function toSlug( string $text ): string {
        // Let WP's sanitize_title handle the basic normalisation.
        // We then strip leftover characters that are not URL-safe.
        $slug = sanitize_title( $text );

        // sanitize_title may strip all Arabic chars on some setups.
        // Fallback: manually lowercase + replace spaces.
        if ( $slug === '' ) {
            $slug = mb_strtolower( trim( $text ), 'UTF-8' );
            $slug = preg_replace( '/\s+/u', '-', $slug );
            $slug = preg_replace( '/[^\p{L}\p{N}\-]/u', '', $slug );
        }

        return $slug ?: 'item-' . wp_rand( 1000, 9999 );
    }

    /**
     * Return the duplicate-handling strategy: "skip" or "update".
     */
    protected function onDuplicate(): string {
        return $this->config['migration']['on_duplicate'] ?? 'skip';
    }

    /**
     * True when image import is enabled for this run.
     */
    protected function shouldImportImages(): bool {
        return (bool) ( $this->config['migration']['run_images'] ?? true );
    }

    /**
     * Sanitise text values coming from OpenCart (ensure valid UTF-8).
     */
    /**
     * Format a dimension or weight value from OC for WC storage.
     * OC stores dimensions as DECIMAL(15,8) in MySQL (e.g. "208.00000000").
     * WC displays the raw stored value so we strip trailing zeros for clean display.
     * Examples: "208.00000000" → "208", "13.90000000" → "13.9", "0.00000000" → ""
     */
    protected function formatDimension( $value ): string {
        if ( $value === '' || $value === null ) { return ''; }
        $float = (float) $value;
        if ( $float <= 0 ) { return ''; }
        // rtrim trailing zeros and decimal point: "13.90" → "13.9", "208.00" → "208"
        return rtrim( rtrim( number_format( $float, 4, '.', '' ), '0' ), '.' );
    }

    protected function sanitizeText( string $text ): string {
        // Convert encoding to UTF-8 if necessary.
        if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
            $text = mb_convert_encoding( $text, 'UTF-8', 'auto' );
        }
        return $text;
    }

    /**
     * Sanitise names/titles coming from OpenCart.
     *
     * OpenCart stores sometimes contain HTML or escaped entities in name fields
     * (e.g. &lt;b&gt;Name&lt;/b&gt;). Names should be plain text in WooCommerce.
     */
    protected function sanitizeName( string $name ): string {
        $name = $this->sanitizeText( $name );
        $name = html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $name = wp_strip_all_tags( $name, true );
        $name = preg_replace( '/\s+/u', ' ', $name );
        return trim( $name );
    }

    /**
     * Clean an OpenCart HTML description for use as a WooCommerce product/category description.
     *
     * Strips any leading <h1>–<h6> block that OpenCart editors commonly prepend as a copy of
     * the product title (e.g. <h1><b>Product Name</b></h1>). WooCommerce already stores the
     * title separately, so repeating it in post_content creates a duplicate visible heading.
     *
     * Does NOT strip headings that appear mid-description — only the very first element when
     * it is a heading tag.
     */
    protected function cleanDescription( string $html ): string {
        $html = $this->sanitizeText( $html );
        // Some OpenCart stores save HTML as escaped entities (&lt;p&gt;...&lt;/p&gt;).
        // Decode first so WooCommerce stores real markup instead of literal tags.
        $html = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        // Remove one optional leading heading block (h1–h6), including any inner tags.
        $html = preg_replace( '/^\s*<h[1-6][^>]*>.*?<\/h[1-6]>\s*/is', '', $html );
        return trim( $html );
    }

    /**
     * Map an OC product stock-status to a WC stock status string.
     */
    protected function mapStockStatus( int $oc_stock_status_id, int $quantity ): string {
        // OC stock status IDs vary; cover common defaults.
        // 5 = In Stock, 6 = 2-3 days, 7 = Pre-Order, 8 = Out of Stock.
        if ( $quantity > 0 ) {
            return 'instock';
        }
        return in_array( $oc_stock_status_id, [ 7 ], true ) ? 'onbackorder' : 'outofstock';
    }

    /**
     * Map an OpenCart order status ID to a WooCommerce order status string.
     *
     * Resolution order:
     *  1. Dynamic map built by OrderStatusMigrator (option 'octowoo_order_status_map').
     *     This is the preferred source when order_statuses migrator has run.
     *  2. Hardcoded defaults for the standard OC status IDs (1–15).
     *  3. Configured fallback ('woocommerce.default_order_status') or 'pending'.
     */
    protected function mapOrderStatus( int $oc_status_id ): string {
        // 1. Dynamic map from OrderStatusMigrator (populated at migration time).
        $dynamic_map = (array) get_option( 'octowoo_order_status_map', [] );

        if ( isset( $dynamic_map[ $oc_status_id ] ) ) {
            return (string) $dynamic_map[ $oc_status_id ];
        }

        // 2. Hardcoded built-in OC status defaults.
        /*
         * OpenCart default status IDs (English):
         *  1  = Pending
         *  2  = Processing
         *  3  = Shipped
         *  5  = Complete
         *  7  = Cancelled
         *  8  = Failed
         *  9  = Refunded
         *  10 = Reversed
         *  11 = Chargeback
         *  15 = Denied
         */
        $static_map = [
            1  => 'pending',
            2  => 'processing',
            3  => 'on-hold',
            5  => 'completed',
            7  => 'cancelled',
            8  => 'failed',
            9  => 'refunded',
            10 => 'refunded',
            11 => 'cancelled',
            15 => 'failed',
        ];

        return $static_map[ $oc_status_id ]
            ?? ( $this->config['woocommerce']['default_order_status'] ?? 'pending' );
    }

    /**
     * Ensure a WC product attribute exists (global PA_ taxonomy).
     * Returns the attribute (term group) ID.
     *
     * Results are cached in a static array for the lifetime of the PHP request
     * so repeated calls for the same attribute (common during bulk product import)
     * don't hit the database each time.
     */
    protected function ensureProductAttribute( string $name, string $slug ): int {
        static $cache = [];

        if ( isset( $cache[ $slug ] ) ) {
            return $cache[ $slug ];
        }

        // Check if the attribute taxonomy exists first.
        $attribute_id = wc_attribute_taxonomy_id_by_name( 'pa_' . $slug );

        if ( ! $attribute_id ) {
            $args = [
                'name'         => $name,
                'slug'         => $slug,
                'type'         => 'select',
                'order_by'     => 'menu_order',
                'has_archives' => false,
            ];
            $attribute_id = wc_create_attribute( $args );

            if ( is_wp_error( $attribute_id ) ) {
                $this->logger->error( "Could not create attribute [{$name}]: " . $attribute_id->get_error_message() );
                $cache[ $slug ] = 0;
                return 0;
            }

            // Register the taxonomy immediately so subsequent term inserts work.
            $taxonomy_name = wc_attribute_taxonomy_name( $slug );
            if ( ! taxonomy_exists( $taxonomy_name ) ) {
                register_taxonomy( $taxonomy_name, 'product' );
            }
        }

        $cache[ $slug ] = $attribute_id;
        return $attribute_id;
    }
}

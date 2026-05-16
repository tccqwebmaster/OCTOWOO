<?php
/**
 * Admin page controller.
 *
 * Registers the WP admin menu page with:
 *  - Settings tab: DB credentials, image path, migration toggles.
 *  - Migration tab: start / resume / abort controls + progress display.
 *  - Logs tab: live log viewer with level filter.
 *
 * All form submissions are verified with nonces.
 * All output is escaped.
 */

namespace OctoWoo\Admin;

use OctoWoo\Core\CheckpointManager;
use OctoWoo\Core\MigrationManager;

defined( 'ABSPATH' ) || exit;

class AdminPage {

    private const MENU_SLUG    = 'octowoo-migration';
    private const SETTINGS_KEY = 'octowoo_config';
    private const CAP          = 'manage_woocommerce';

    // ── Bootstrap ─────────────────────────────────────────────────────────────

    public function init(): void {
        add_action( 'admin_menu',          [ $this, 'registerMenu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueueAssets' ] );
        add_action( 'admin_post_octowoo_save_settings', [ $this, 'handleSaveSettings' ] );
        add_action( 'admin_post_octowoo_test_connection', [ $this, 'handleTestConnection' ] );

        // Register the early-redirect handler for SEO redirects on the front end.
        add_action( 'template_redirect', [ 'OctoWoo\\Migrators\\SeoMigrator', 'handleWpRedirect' ] );
    }

    // ── Menu registration ─────────────────────────────────────────────────────

    public function registerMenu(): void {
        add_menu_page(
            __( 'OctoWoo Migration', 'octowoo' ),
            __( 'OctoWoo', 'octowoo' ),
            self::CAP,
            self::MENU_SLUG,
            [ $this, 'renderPage' ],
            'dashicons-migrate',
            56
        );
    }

    // ── Asset loading ─────────────────────────────────────────────────────────

    public function enqueueAssets( string $hook ): void {
        if ( strpos( $hook, self::MENU_SLUG ) === false ) {
            return;
        }

        wp_enqueue_style(
            'octowoo-admin',
            OCTOWOO_PLUGIN_URL . 'admin/css/octowoo-admin.css',
            [],
            OCTOWOO_VERSION
        );

        wp_enqueue_script(
            'octowoo-admin',
            OCTOWOO_PLUGIN_URL . 'admin/js/octowoo-admin.js',
            [ 'jquery' ],
            OCTOWOO_VERSION,
            true
        );

        // Build the JS config object. Wrap in try/catch so a failure in any
        // one value (e.g. CronManager::getStatus() throwing) does not prevent
        // wp_localize_script from running — which would make octoWoo undefined
        // in the browser and kill ALL button event handlers.
        try {
            $cfg = self::getConfig();
            $cron_status = \OctoWoo\Core\CronManager::getStatus();
        } catch ( \Throwable $e ) {
            $cfg         = [];
            $cron_status = [ 'status' => 'error', 'error' => $e->getMessage() ];
            error_log( '[OctoWoo] enqueueAssets error: ' . $e->getMessage() );
        }

        wp_localize_script( 'octowoo-admin', 'octoWoo', [
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'octowoo_ajax' ),
            'activeRunId' => CheckpointManager::getActiveRunId() ?? '',
            'lastRunId'   => get_option( 'octowoo_last_run_id', '' ),
            'demoLimit'   => (int) ( $cfg['migration']['demo_limit'] ?? 20 ),
            'batchSize'   => (int) ( $cfg['migration']['batch_size'] ?? 20 ),
            'isDryRun'    => ! empty( $cfg['migration']['dry_run'] ),
            'onDuplicate' => $cfg['migration']['on_duplicate'] ?? 'skip',
            'cronStatus'  => $cron_status,
            'i18n'        => [
                'starting'     => __( 'Starting migration…', 'octowoo' ),
                'running'      => __( 'Migration in progress…', 'octowoo' ),
                'completed'    => __( 'Migration completed!', 'octowoo' ),
                'aborted'      => __( 'Migration aborted.', 'octowoo' ),
                'confirmAbort' => __( 'Are you sure you want to abort the migration?', 'octowoo' ),
                'confirmReset' => __( 'Delete all migration progress and ID map? This cannot be undone.', 'octowoo' ),
                'confirmPurge' => __( 'Purge the selected entity types? Only OctoWoo-tagged items will be deleted.', 'octowoo' ),
                'confirmForce' => __( 'FORCE PURGE will delete ALL WooCommerce data, including items not created by OctoWoo. Are you absolutely sure?', 'octowoo' ),
            ],
        ] );
    }

    // ── Page render ───────────────────────────────────────────────────────────

    public function renderPage(): void {
        if ( ! ( current_user_can( self::CAP ) || current_user_can( 'manage_options' ) ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'octowoo' ) );
        }

        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'migration'; // phpcs:ignore WordPress.Security.NonceVerification

        // Safety buffer: catch any PHP error in the template so we see an error
        // message instead of a blank page.
        ob_start();
        try {
            require_once OCTOWOO_PLUGIN_DIR . 'templates/admin-dashboard.php';
        } catch ( \Throwable $e ) {
            ob_end_clean();
            printf(
                '<div class="notice notice-error" style="margin:20px 0;padding:16px;">' .
                '<h2>OctoWoo — Template Error (v%s)</h2>' .
                '<p><strong>%s</strong></p>' .
                '<p>File: %s line %d</p>' .
                '<pre style="overflow:auto;font-size:11px;background:#f0f0f0;padding:10px;">%s</pre>' .
                '</div>',
                esc_html( OCTOWOO_VERSION ),
                esc_html( $e->getMessage() ),
                esc_html( $e->getFile() ),
                (int) $e->getLine(),
                esc_html( $e->getTraceAsString() )
            );
            return;
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo ob_get_clean();
    }

    // ── Settings form handler ─────────────────────────────────────────────────

    public function handleSaveSettings(): void {
        if ( ! ( current_user_can( self::CAP ) || current_user_can( 'manage_options' ) ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'octowoo' ) );
        }

        check_admin_referer( 'octowoo_save_settings' );

        // phpcs:ignore WordPress.Security.NonceVerification
        $posted = $_POST['octowoo'] ?? [];

        $existing = get_option( self::SETTINGS_KEY, [] );

        $config = [
            'source' => sanitize_key( $posted['source'] ?? 'remote' ),
            'db' => [
                'host'     => sanitize_text_field( $posted['db']['host']     ?? '' ),
                'port'     => (int)              ( $posted['db']['port']     ?? 3306 ),
                'database' => sanitize_text_field( $posted['db']['database'] ?? '' ),
                'username' => sanitize_text_field( $posted['db']['username'] ?? '' ),
                // Password: encrypt at rest; only update if a value was entered
                // (avoids overwriting with blank on settings re-save).
                'password' => isset( $posted['db']['password'] ) && $posted['db']['password'] !== ''
                    ? \OctoWoo\Core\Encryptor::encrypt( $posted['db']['password'] )
                    : ( $existing['db']['password'] ?? '' ),
                'prefix'   => sanitize_text_field( $posted['db']['prefix']   ?? 'oc_' ),
                'socket'   => sanitize_text_field( $posted['db']['socket']   ?? '' ),
            ],
            'opencart' => [
                'image_path'            => sanitize_text_field( $posted['opencart']['image_path']            ?? '' ),
                'image_source'          => sanitize_key(        $posted['opencart']['image_source']          ?? 'remote' ),
                'download_path'         => sanitize_text_field( $posted['opencart']['download_path']         ?? '' ),
                'shop_url'              => esc_url_raw(          $posted['opencart']['shop_url']              ?? '' ),
                'language_id'           => (int)               ( $posted['opencart']['language_id']           ?? 1 ),
                'language_id_secondary' => (int)               ( $posted['opencart']['language_id_secondary'] ?? 0 ),
            ],
            'migration' => [
                'batch_size'          => max( 5, min( 500, (int) ( $posted['migration']['batch_size'] ?? 20 ) ) ),
                'dry_run'             => ! empty( $posted['migration']['dry_run'] ),
                'on_duplicate'        => sanitize_key( $posted['migration']['on_duplicate'] ?? 'skip' ),
                'run_categories'      => ! empty( $posted['migration']['run_categories'] ),
                'run_products'        => ! empty( $posted['migration']['run_products'] ),
                'run_images'          => ! empty( $posted['migration']['run_images'] ),
                'run_customers'       => ! empty( $posted['migration']['run_customers'] ),
                'run_orders'          => ! empty( $posted['migration']['run_orders'] ),
                'run_coupons'         => ! empty( $posted['migration']['run_coupons'] ),
                'run_seo'             => ! empty( $posted['migration']['run_seo'] ),
                'run_information'     => ! empty( $posted['migration']['run_information'] ),
                'run_tags'            => ! empty( $posted['migration']['run_tags'] ),
                'run_filters'         => ! empty( $posted['migration']['run_filters'] ),
                'run_downloads'       => ! empty( $posted['migration']['run_downloads'] ),
                'run_tax'             => ! empty( $posted['migration']['run_tax'] ),
                'run_order_statuses'  => ! empty( $posted['migration']['run_order_statuses'] ),
                'run_manufacturers'   => ! empty( $posted['migration']['run_manufacturers'] ),
                'run_related'         => ! empty( $posted['migration']['run_related'] ),
                'run_bundles'         => ! empty( $posted['migration']['run_bundles'] ),
                'run_reviews'         => ! empty( $posted['migration']['run_reviews'] ),
            ],
            'seo' => [
                'write_htaccess'   => ! empty( $posted['seo']['write_htaccess'] ),
                'use_wp_redirects' => ! empty( $posted['seo']['use_wp_redirects'] ),
            ],
            'multilingual' => [
                'enabled'          => ! empty( $posted['multilingual']['enabled'] ),
                'use_wpml'         => ! empty( $posted['multilingual']['use_wpml'] ),
                'use_polylang'     => ! empty( $posted['multilingual']['use_polylang'] ),
                'primary_locale'   => sanitize_text_field( $posted['multilingual']['primary_locale']   ?? 'en_US' ),
                'secondary_locale' => sanitize_text_field( $posted['multilingual']['secondary_locale'] ?? 'ar' ),
            ],
            'cron' => [
                'enabled'   => ! empty( $posted['cron']['enabled'] ),
                'interval'  => sanitize_key( $posted['cron']['interval'] ?? 'daily' ),
                'migrators' => sanitize_text_field( $posted['cron']['migrators'] ?? 'products,images,orders' ),
            ],
            'woocommerce' => [
                'force_password_reset'  => ! empty( $posted['woocommerce']['force_password_reset'] ),
                'migrate_oc_passwords'  => ! empty( $posted['woocommerce']['migrate_oc_passwords'] ),
            ],
        ];

        // Merge with existing to avoid overwriting keys that weren't present
        // in the submitted form (prevents accidental blanks when fields are
        // omitted from POST). Right-most values (posted) win.
        $merged = array_replace_recursive( $existing, $config );

        // The prefix "octowoo_oc_" is an internal local-import prefix
        // (SqlImporter renames oc_ tables to octowoo_oc_ inside WP's DB).
        // When the source is Remote, the user's real OC prefix should be used;
        // strip the octowoo_ wrapper automatically so migrations query the
        // correct remote table names.
        if ( $merged['source'] === 'remote'
             && strpos( $merged['db']['prefix'], \OctoWoo\Core\SqlImporter::IMPORT_PREFIX ) === 0 ) {
            $merged['db']['prefix'] = substr(
                $merged['db']['prefix'],
                strlen( 'octowoo_' )
            );
        }

        // Flush WP's option cache so update_option never short-circuits due
        // to stale alloptions / individual-option cache (common on hosts with
        // persistent object cache like Redis / Memcached).
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( self::SETTINGS_KEY, 'options' );
        wp_cache_delete( 'notoptions', 'options' );

        $saved_ok = update_option( self::SETTINGS_KEY, $merged, 'yes' );

        // update_option returns false both on failure AND when old === new.
        // Read back immediately to distinguish the two cases.
        if ( ! $saved_ok ) {
            $readback = get_option( self::SETTINGS_KEY, [] );
            $saved_ok = ( maybe_serialize( $readback ) === maybe_serialize( $merged ) );
        }

        $redirect_params = [
            'page'    => self::MENU_SLUG,
            'tab'     => 'settings',
        ];

        if ( $saved_ok ) {
            $redirect_params['updated'] = '1';
        } else {
            global $wpdb;
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( 'OctoWoo: settings save failed. DB error: ' . $wpdb->last_error );
            $redirect_params['save_error'] = '1';
        }

        wp_safe_redirect( add_query_arg( $redirect_params, admin_url( 'admin.php' ) ) );
        exit;
    }

    // ── Test connection ───────────────────────────────────────────────────────

    public function handleTestConnection(): void {
        if ( ! ( current_user_can( self::CAP ) || current_user_can( 'manage_options' ) ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'octowoo' ) );
        }

        check_admin_referer( 'octowoo_test_connection' );

        $config = get_option( self::SETTINGS_KEY, [] );

        $defaults = require OCTOWOO_PLUGIN_DIR . 'config/default-config.php';
        $db_config = array_merge( $defaults['db'], is_array( $config['db'] ?? null ) ? $config['db'] : [] );

        $connector = new \OctoWoo\Core\DatabaseConnector( $db_config );
        $error     = $connector->testConnection();

        $redirect_args = [
            'page' => self::MENU_SLUG,
            'tab'  => 'settings',
        ];

        if ( $error === null ) {
            $redirect_args['oc_db_ok'] = '1';
        } else {
            $redirect_args['oc_db_err'] = urlencode( $error );
        }

        wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
        exit;
    }

    // ── Public helpers ────────────────────────────────────────────────────────

    /**
     * Return current saved config merged with defaults (for template rendering).
     *
     * @return array<string, mixed>
     */
    public static function getConfig(): array {
        $defaults = require OCTOWOO_PLUGIN_DIR . 'config/default-config.php';
        $saved    = get_option( self::SETTINGS_KEY, [] );

        if ( ! is_array( $saved ) ) {
            $saved = [];
        }

        // If the user hasn't set an explicit OpenCart image path but an
        // imported images directory exists (from SQL import or ZIP), use
        // that as the default so the admin UI and system check are happy.
        if ( empty( $saved['opencart']['image_path'] ) ) {
            try {
                $img_dir = \OctoWoo\Core\SqlImporter::getImagesDir();
                if ( is_dir( $img_dir ) && count( glob( $img_dir . '*' ) ) > 0 ) {
                    $saved['opencart']['image_path'] = $img_dir;
                }
            } catch ( \Throwable $e ) {
                // Ignore — SqlImporter may not be available in some contexts.
            }
        }

        // Shallow merge for display purposes; deep merge handled by MigrationManager.
        return array_replace_recursive( $defaults, $saved );
    }

    public static function getMenuSlug(): string {
        return self::MENU_SLUG;
    }
}

<?php
/**
 * Admin dashboard template.
 *
 * Rendered by AdminPage::renderPage().
 * Uses $active_tab variable set by the caller.
 * All output is escaped.
 */

defined( 'ABSPATH' ) || exit;

use OctoWoo\Admin\AdminPage;
use OctoWoo\Core\CheckpointManager;

$config     = AdminPage::getConfig();
$menu_slug  = AdminPage::getMenuSlug();
$active_run = CheckpointManager::getActiveRunId();
$last_run   = get_option( 'octowoo_last_run_id', '' );
$last_run_at = get_option( 'octowoo_last_run_at', '' );

// URL-based notices.
// phpcs:disable WordPress.Security.NonceVerification
$saved      = isset( $_GET['updated'] )    && '1' === $_GET['updated'];
$save_error = isset( $_GET['save_error'] ) && '1' === $_GET['save_error'];
$db_ok      = isset( $_GET['oc_db_ok'] )   && '1' === $_GET['oc_db_ok'];
$db_err     = ! empty( $_GET['oc_db_err'] );
// phpcs:enable
?>
<div class="wrap" id="octowoo-app">

    <!-- Header -->
    <div class="ow-header">
        <div class="ow-logo">OW</div>
        <h1><?php esc_html_e( 'OctoWoo – OpenCart → WooCommerce Migration', 'octowoo' ); ?></h1>
        <span style="margin-left:auto;font-size:12px;opacity:.65;align-self:center;">Version <?php echo esc_html( OCTOWOO_VERSION ); ?></span>
    </div>

    <!-- Global notices -->
    <?php if ( $saved ): ?>
        <div class="ow-alert ow-alert-success"><?php esc_html_e( 'Settings saved successfully.', 'octowoo' ); ?></div>
    <?php endif; ?>
    <?php if ( $save_error ): ?>
        <div class="ow-alert ow-alert-error"><?php esc_html_e( '⚠ Settings could not be saved — database write failed. Check your server\'s error log for details.', 'octowoo' ); ?></div>
    <?php endif; ?>
    <?php if ( $db_ok ): ?>
        <div class="ow-alert ow-alert-success"><?php esc_html_e( '✔ OpenCart database connection successful!', 'octowoo' ); ?></div>
    <?php endif; ?>
    <?php if ( $db_err ): ?>
        <div class="ow-alert ow-alert-error"><?php esc_html_e( '✘ Could not connect to OpenCart database. Please check your credentials.', 'octowoo' ); ?></div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="ow-tabs">
        <a href="#" class="ow-tab-btn <?php echo $active_tab === 'migration' ? 'active' : ''; ?>" data-tab="migration">
            <?php esc_html_e( '▶ Migration', 'octowoo' ); ?>
        </a>
        <a href="#" class="ow-tab-btn <?php echo $active_tab === 'settings' ? 'active' : ''; ?>" data-tab="settings">
            <?php esc_html_e( '⚙ Settings', 'octowoo' ); ?>
        </a>
        <a href="#" class="ow-tab-btn <?php echo $active_tab === 'logs' ? 'active' : ''; ?>" data-tab="logs">
            <?php esc_html_e( '📋 Logs', 'octowoo' ); ?>
        </a>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════
         TAB: MIGRATION
    ═════════════════════════════════════════════════════════════════════ -->
    <div id="ow-tab-migration" class="ow-tab-pane" style="display:none;">

        <!-- Status banner (populated by JS) -->
        <div id="ow-status-banner" class="ow-alert ow-alert-info" style="display:none;"></div>

        <?php if ( $active_run ): ?>
            <div id="ow-active-run-banner" class="ow-alert ow-alert-warning">
                <?php printf( esc_html__( 'A migration is in progress (Run ID: %s). Resume or Abort it below.', 'octowoo' ), '<code>' . esc_html( $active_run ) . '</code>' ); ?>
            </div>
        <?php elseif ( $last_run ): ?>
            <div class="ow-alert ow-alert-info">
                <?php printf( esc_html__( 'Last migration: Run ID %1$s completed at %2$s.', 'octowoo' ), '<code>' . esc_html( $last_run ) . '</code>', esc_html( $last_run_at ) ); ?>
            </div>
        <?php endif; ?>

        <!-- ── System Check ────────────────────────────────────────────── -->
        <div class="ow-card" style="border-left:4px solid #2271b1;">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:10px;">
                <div>
                    <h2 style="margin:0 0 4px;">⚙ <?php esc_html_e( 'Server &amp; Configuration Check', 'octowoo' ); ?></h2>
                    <p class="ow-form-hint" style="margin:0;">
                        <?php esc_html_e( 'Run this before starting a migration to verify your server meets all requirements.', 'octowoo' ); ?>
                    </p>
                </div>
                <button type="button" id="ow-btn-validate" class="ow-btn ow-btn-secondary">
                    🔎 <?php esc_html_e( 'Run System Check', 'octowoo' ); ?>
                </button>
            </div>
            <div id="ow-validate-results" style="display:none;"></div>
        </div>

        <!-- ── STEP 1: Select Entities ──────────────────────────────────── -->
        <div class="ow-card">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
                <h2 style="margin:0;"><?php esc_html_e( '1 — Select Entities to Migrate', 'octowoo' ); ?></h2>
                <button type="button" id="ow-btn-scan" class="ow-btn ow-btn-secondary" style="font-size:12px;padding:5px 14px;">
                    🔍 <?php esc_html_e( 'Scan Source DB', 'octowoo' ); ?>
                </button>
            </div>
            <div id="ow-scan-result" style="display:none;margin-bottom:12px;padding:8px 12px;background:#f0f6fc;border-left:3px solid #2271b1;border-radius:4px;font-size:12px;color:#444;"></div>

            <!-- Entity grid -->
            <div style="border:1px solid #e0e0e0;border-radius:6px;overflow:hidden;font-size:13px;">
                <?php
                $ow_entity_rows = [
                    [
                        'label' => __( 'Products', 'octowoo' ), 'value' => 'products', 'scan_key' => 'products',
                        'children' => [
                            [ 'label' => __( 'Reviews', 'octowoo' ),           'value' => 'reviews',  'scan_key' => 'reviews' ],
                            [ 'label' => __( 'Product Bundles *', 'octowoo' ),  'value' => 'bundles',  'scan_key' => '' ],
                        ],
                    ],
                    [
                        'label' => __( 'Customers', 'octowoo' ), 'value' => 'customers', 'scan_key' => 'customers',
                        'children' => [
                            [ 'label' => __( 'Orders', 'octowoo' ), 'value' => 'orders', 'scan_key' => 'orders' ],
                        ],
                    ],
                    [
                        'label' => __( 'Categories', 'octowoo' ), 'value' => 'categories', 'scan_key' => 'categories',
                        'children' => [],
                    ],
                    [
                        'label' => __( 'CMS Pages', 'octowoo' ), 'value' => 'information', 'scan_key' => 'information',
                        'children' => [],
                    ],
                    [
                        'label' => __( 'Manufacturers / Brands', 'octowoo' ), 'value' => 'manufacturers', 'scan_key' => 'manufacturers',
                        'children' => [],
                    ],
                    [
                        'label' => __( 'Coupons', 'octowoo' ), 'value' => 'coupons', 'scan_key' => 'coupons',
                        'children' => [],
                    ],
                    [
                        'label' => __( 'Tax Classes', 'octowoo' ), 'value' => 'tax_classes', 'scan_key' => 'tax_classes',
                        'children' => [],
                    ],
                    [
                        'label' => __( 'Tags &amp; Filters', 'octowoo' ), 'value' => 'tags_filters', 'scan_key' => 'tags',
                        'children' => [],
                    ],
                ];
                $ow_total = count( $ow_entity_rows );
                for ( $oi = 0; $oi < $ow_total; $oi += 2 ) {
                    $ow_left    = $ow_entity_rows[ $oi ];
                    $ow_right   = $ow_entity_rows[ $oi + 1 ] ?? null;
                    $ow_is_last = ( $oi + 2 >= $ow_total );
                    echo '<div style="display:grid;grid-template-columns:1fr 1fr;' . ( $ow_is_last ? '' : 'border-bottom:1px solid #e0e0e0;' ) . '">';
                    foreach ( [ $ow_left, $ow_right ] as $ow_ci => $ow_ent ) {
                        if ( ! $ow_ent ) { echo '<div></div>'; continue; }
                        echo '<div style="padding:12px 16px;' . ( $ow_ci === 0 ? 'border-right:1px solid #e0e0e0;' : '' ) . '">';
                        $ow_badge = $ow_ent['scan_key']
                            ? ' <span class="ow-count-badge" data-scan="' . esc_attr( $ow_ent['scan_key'] ) . '" style="display:none;background:#2271b1;color:#fff;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:normal;"></span>'
                            : '';
                        printf(
                            '<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">' .
                            '<input type="checkbox" class="ow-entity-chk" value="%s" checked> %s%s</label>',
                            esc_attr( $ow_ent['value'] ), esc_html( $ow_ent['label'] ), $ow_badge
                        );
                        foreach ( $ow_ent['children'] as $ow_ch ) {
                            $ow_ch_badge = $ow_ch['scan_key']
                                ? ' <span class="ow-count-badge" data-scan="' . esc_attr( $ow_ch['scan_key'] ) . '" style="display:none;background:#2271b1;color:#fff;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:normal;"></span>'
                                : '';
                            printf(
                                '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;color:#555;margin:6px 0 0 26px;">' .
                                '<input type="checkbox" class="ow-entity-chk" value="%s" checked> %s%s</label>',
                                esc_attr( $ow_ch['value'] ), esc_html( $ow_ch['label'] ), $ow_ch_badge
                            );
                        }
                        echo '</div>';
                    }
                    echo '</div>';
                }
                ?>
            </div>
            <p class="ow-form-hint" style="margin-top:8px;">
                * <?php esc_html_e( 'Bundles requires WooCommerce Product Bundles (SomewhereWarm) + OpenCart 4.x.', 'octowoo' ); ?>
            </p>
        </div>

        <!-- ── STEP 2: Additional Options ───────────────────────────────── -->
        <div class="ow-card">
            <h2><?php esc_html_e( '2 — Additional Options', 'octowoo' ); ?></h2>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 32px;font-size:13px;">
                <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="ow-opt-images" checked style="margin-top:2px;">
                    <span><?php esc_html_e( 'Transfer images from Categories &amp; Product descriptions', 'octowoo' ); ?></span>
                </label>
                <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="ow-opt-passwords" checked style="margin-top:2px;">
                    <span><?php esc_html_e( "Migrate customers' passwords", 'octowoo' ); ?></span>
                </label>
                <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="ow-opt-seo" checked style="margin-top:2px;">
                    <span><?php esc_html_e( 'Migrate categories &amp; products SEO URLs', 'octowoo' ); ?></span>
                </label>
                <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="ow-opt-redirects" style="margin-top:2px;">
                    <span><?php esc_html_e( 'Create 301 redirects on your store after migration', 'octowoo' ); ?></span>
                </label>
                <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="ow-opt-strip-html" style="margin-top:2px;">
                    <span><?php esc_html_e( 'Strip HTML from category &amp; product names', 'octowoo' ); ?></span>
                </label>
                <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="ow-opt-downloads" checked style="margin-top:2px;">
                    <span><?php esc_html_e( 'Migrate downloadable products', 'octowoo' ); ?></span>
                </label>
                <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="ow-opt-multilingual" style="margin-top:2px;">
                    <span><?php esc_html_e( 'Multilingual data (WPML / Polylang)', 'octowoo' ); ?></span>
                </label>
            </div>
        </div>

        <!-- ── STEP 3: Run Migration ────────────────────────────────────── -->
        <div class="ow-card">
            <h2 style="margin-bottom:6px;"><?php esc_html_e( '3 — Run Migration', 'octowoo' ); ?></h2>
            <p class="ow-form-hint" style="margin:0 0 14px;">
                <?php esc_html_e( 'Run a Demo first (migrates ~20 items per entity) to verify the result, then run a Full Migration.', 'octowoo' ); ?>
            </p>
            <!-- Standard (AJAX chunk) mode -->
            <div class="ow-actions">
                <button type="button" id="ow-btn-demo" class="ow-btn ow-btn-warning">
                    ▷ <?php esc_html_e( 'Start Demo Migration', 'octowoo' ); ?>
                </button>
                <button type="button" id="ow-btn-start" class="ow-btn ow-btn-primary">
                    ▶ <?php esc_html_e( 'Start Full Migration', 'octowoo' ); ?>
                </button>
                <button type="button" id="ow-btn-images-only" class="ow-btn ow-btn-secondary">
                    🖼 <?php esc_html_e( 'Images-Only Recovery', 'octowoo' ); ?>
                </button>
                <button type="button" id="ow-btn-products-images" class="ow-btn ow-btn-secondary">
                    🧩 <?php esc_html_e( 'Products + Images Recovery', 'octowoo' ); ?>
                </button>
                <button type="button" id="ow-btn-cats-manufacturers" class="ow-btn ow-btn-secondary">
                    🗂 <?php esc_html_e( 'Categories + Manufacturers Recovery', 'octowoo' ); ?>
                </button>
                <button type="button" id="ow-btn-resume" class="ow-btn ow-btn-warning" <?php echo ! $active_run ? 'disabled' : ''; ?>>
                    ⏯ <?php esc_html_e( 'Resume', 'octowoo' ); ?>
                </button>
                <button type="button" id="ow-btn-abort" class="ow-btn ow-btn-danger" disabled>
                    ⏹ <?php esc_html_e( 'Abort', 'octowoo' ); ?>
                </button>
                <button type="button" id="ow-btn-pause" class="ow-btn ow-btn-secondary" disabled>
                    ⏸ <?php esc_html_e( 'Pause', 'octowoo' ); ?>
                </button>
                <button type="button" id="ow-btn-skip" class="ow-btn ow-btn-secondary" disabled>
                    ⏭ <?php esc_html_e( 'Skip Current', 'octowoo' ); ?>
                </button>
                <button type="button" id="ow-btn-reset" class="ow-btn ow-btn-secondary">
                    ↺ <?php esc_html_e( 'Reset Progress', 'octowoo' ); ?>
                </button>
            </div>

            <!-- Background mode (Action Scheduler) -->
            <div style="margin-top:14px;padding-top:14px;border-top:1px dashed #dcdcde;">
                <p style="margin:0 0 8px;font-size:12px;font-weight:600;color:#3c434a;">
                    ⚡ <?php esc_html_e( 'Background Mode', 'octowoo' ); ?>
                    <span style="font-weight:normal;color:#666;">
                        — <?php esc_html_e( 'Uses WooCommerce Action Scheduler. Browser tab can be closed.', 'octowoo' ); ?>
                    </span>
                </p>

                <?php
                $as_available = function_exists( 'as_schedule_single_action' );
                ?>

                <?php if ( $as_available ) : ?>
                    <div class="ow-bg-controls ow-actions" style="gap:8px;">
                        <button type="button" id="ow-btn-start-bg" class="ow-btn ow-btn-secondary">
                            ⚙ <?php esc_html_e( 'Start in Background', 'octowoo' ); ?>
                        </button>
                        <button type="button" id="ow-btn-resume-bg" class="ow-btn ow-btn-secondary" <?php echo ! $active_run ? 'disabled' : ''; ?>>
                            ⚙ <?php esc_html_e( 'Resume in Background', 'octowoo' ); ?>
                        </button>
                        <button type="button" id="ow-btn-cancel-bg" class="ow-btn ow-btn-danger" disabled>
                            ✖ <?php esc_html_e( 'Cancel Background', 'octowoo' ); ?>
                        </button>
                        <span id="ow-bg-status" style="font-size:13px;margin-left:4px;"></span>
                    </div>
                <?php else : ?>
                    <p id="ow-bg-as-notice" class="ow-form-hint" style="color:#b45309;margin:0;">
                        ⚠ <?php esc_html_e( 'Background mode requires WooCommerce 4.0+ (Action Scheduler not detected).', 'octowoo' ); ?>
                    </p>
                <?php endif; ?>
            </div>

            <p class="ow-form-hint" style="margin-top:12px;">
                WP-CLI: <code>wp octowoo migrate</code> &nbsp;|&nbsp;
                <code>wp octowoo migrate --resume</code> &nbsp;|&nbsp;
                <code>wp octowoo migrate --dry-run</code>
            </p>
        </div>

        <!-- Migration Progress -->
        <div class="ow-card">
            <h2><?php esc_html_e( 'Migration Progress', 'octowoo' ); ?></h2>
            <table class="ow-progress-table" id="ow-progress-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Entity', 'octowoo' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'octowoo' ); ?></th>
                        <th><?php esc_html_e( 'Items', 'octowoo' ); ?></th>
                        <th style="min-width:150px;"><?php esc_html_e( 'Progress', 'octowoo' ); ?></th>
                        <th><?php esc_html_e( '%', 'octowoo' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td colspan="5" style="color:#888;"><?php esc_html_e( 'Start a migration to see progress.', 'octowoo' ); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

    </div><!-- /tab-migration -->


    <!-- ═══════════════════════════════════════════════════════════════════
         TAB: SETTINGS
    ═════════════════════════════════════════════════════════════════════ -->
    <div id="ow-tab-settings" class="ow-tab-pane" style="display:none;">

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="octowoo-settings-form" autocomplete="off">
            <?php wp_nonce_field( 'octowoo_save_settings' ); ?>
            <input type="hidden" name="action" value="octowoo_save_settings">

            <!-- Source Mode -->
            <div class="ow-card">
                <h2><?php esc_html_e( '📦 Data Source Mode', 'octowoo' ); ?></h2>
                <p style="font-size:13px;color:#555;margin:0 0 14px;">
                    <?php esc_html_e( 'Choose how OctoWoo reads your OpenCart data. Use "Remote" if WooCommerce can reach the OpenCart database directly. Use "Local Import" if your OpenCart database is on a different server (e.g. Cloudways) or behind a firewall — upload a SQL dump and/or a ZIP of the images folder instead.', 'octowoo' ); ?>
                </p>

                <?php $source = $config['source'] ?? 'remote'; ?>

                <div class="ow-source-mode-tabs" style="display:flex;gap:12px;margin-bottom:16px;">
                    <label class="ow-source-btn <?php echo $source === 'remote' ? 'active' : ''; ?>" id="ow-src-remote-btn" style="cursor:pointer;">
                        <input type="radio" name="octowoo[source]" value="remote" <?php checked( $source, 'remote' ); ?> style="margin-right:6px;">
                        🌐 <?php esc_html_e( 'Remote / Live Database', 'octowoo' ); ?>
                    </label>
                    <label class="ow-source-btn <?php echo $source === 'local' ? 'active' : ''; ?>" id="ow-src-local-btn" style="cursor:pointer;">
                        <input type="radio" name="octowoo[source]" value="local" <?php checked( $source, 'local' ); ?> style="margin-right:6px;">
                        💾 <?php esc_html_e( 'Local Import (Upload Files)', 'octowoo' ); ?>
                    </label>
                </div>

                <!-- SQL Upload (local mode only) -->
                <div id="ow-local-import-area" style="<?php echo $source === 'local' ? '' : 'display:none;'; ?>border:1px dashed #c3c4c7;border-radius:6px;padding:16px;background:#fafafa;">
                    <h3 style="margin:0 0 12px;font-size:14px;"><?php esc_html_e( '1 — Upload OpenCart SQL Dump', 'octowoo' ); ?></h3>
                    <p style="font-size:12px;color:#666;margin:0 0 10px;">
                        <?php esc_html_e( 'Export your OpenCart database with phpMyAdmin or mysqldump and upload the .sql (or .sql.gz) file here. It will be imported into WordPress\'s own database under a safe prefix and used for migration.', 'octowoo' ); ?>
                    </p>
                    <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
                        <div class="ow-form-group" style="flex:1;min-width:200px;">
                            <label><?php esc_html_e( 'SQL / GZ File', 'octowoo' ); ?></label>
                            <input type="file" id="ow-sql-file" accept=".sql,.gz" style="font-size:13px;">
                        </div>
                        <div class="ow-form-group" style="width:130px;">
                            <label><?php esc_html_e( 'OC Table Prefix', 'octowoo' ); ?></label>
                            <input type="text" id="ow-sql-prefix" value="<?php echo esc_attr( $config['db']['prefix'] ?? 'oc_' ); ?>" placeholder="oc_" style="width:100%;">
                        </div>
                        <button type="button" id="ow-btn-import-sql" class="ow-btn ow-btn-secondary" style="margin-bottom:1px;">
                            ⬆ <?php esc_html_e( 'Import SQL', 'octowoo' ); ?>
                        </button>
                    </div>
                    <div id="ow-sql-progress" style="display:none;margin-top:10px;">
                        <div class="ow-progress-bar-wrap" style="margin-bottom:4px;">
                            <div class="ow-progress-bar" id="ow-sql-progress-bar" style="width:0%"></div>
                        </div>
                        <span id="ow-sql-status" style="font-size:12px;color:#555;"></span>
                    </div>
                    <div id="ow-sql-result" style="margin-top:8px;font-size:13px;"></div>

                    <?php
                    $sql_info = \OctoWoo\Core\SqlImporter::getImportedInfo();
                    if ( $sql_info['tables'] > 0 ):
                    ?>
                        <div id="ow-sql-imported-status" class="ow-alert ow-alert-success" style="margin-top:10px;padding:8px 12px;font-size:12px;">
                            ✔ <strong><?php esc_html_e( 'SQL data ready:', 'octowoo' ); ?></strong>
                            <?php echo esc_html( $sql_info['tables'] ); ?> <?php esc_html_e( 'tables imported', 'octowoo' ); ?>
                            <?php if ( $sql_info['filename'] ): ?>
                                — <code><?php echo esc_html( $sql_info['filename'] ); ?></code>
                            <?php endif; ?>
                            <?php if ( $sql_info['imported_at'] ): ?>
                                <span style="color:#555;"> (<?php echo esc_html( $sql_info['imported_at'] ); ?>)</span>
                            <?php endif; ?>
                            <span style="float:right;">
                                <a href="#" id="ow-btn-drop-sql" style="color:#c62828;font-size:11px;" title="<?php esc_attr_e( 'Drop all imported tables and clear this status', 'octowoo' ); ?>">
                                    ✕ <?php esc_html_e( 'Clear', 'octowoo' ); ?>
                                </a>
                            </span>
                        </div>
                    <?php else: ?>
                        <div id="ow-sql-imported-status" class="ow-alert" style="display:none;margin-top:10px;padding:8px 12px;font-size:12px;"></div>
                    <?php endif; ?>

                    <h3 style="margin:18px 0 12px;font-size:14px;"><?php esc_html_e( '2 — Upload Images ZIP (optional)', 'octowoo' ); ?></h3>
                    <p style="font-size:12px;color:#666;margin:0 0 10px;">
                        <?php esc_html_e( 'ZIP your OpenCart /image/ folder and upload it here. The images will be extracted to wp-content/uploads/octowoo-images/ and used during migration instead of a remote path.', 'octowoo' ); ?>
                    </p>
                    <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
                        <div class="ow-form-group" style="flex:1;min-width:200px;">
                            <label><?php esc_html_e( 'Images ZIP File', 'octowoo' ); ?></label>
                            <input type="file" id="ow-images-zip" accept=".zip" style="font-size:13px;">
                        </div>
                        <button type="button" id="ow-btn-import-images" class="ow-btn ow-btn-secondary" style="margin-bottom:1px;">
                            🖼 <?php esc_html_e( 'Upload & Extract', 'octowoo' ); ?>
                        </button>
                    </div>
                    <div id="ow-images-result" style="margin-top:8px;font-size:13px;"></div>

                    <?php
                    $img_dir = \OctoWoo\Core\SqlImporter::getImagesDir();
                    $img_ok  = is_dir( $img_dir ) && count( glob( $img_dir . '*' ) ) > 0;
                    ?>
                    <?php if ( $img_ok ): ?>
                        <div class="ow-alert ow-alert-success" style="margin-top:12px;padding:8px 12px;font-size:12px;">
                            ✔ <?php printf( esc_html__( 'Images directory ready: %s', 'octowoo' ), '<code>' . esc_html( $img_dir ) . '</code>' ); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- OpenCart Database -->
            <div class="ow-card" id="ow-remote-db-card" style="<?php echo $source === 'local' ? 'opacity:.5;pointer-events:none;' : ''; ?>">
                <h2><?php esc_html_e( 'OpenCart Database Connection', 'octowoo' ); ?></h2>
                <p style="font-size:12px;color:#888;margin:0 0 10px;">
                    <?php esc_html_e( 'Used in Remote mode only. Ignored when Local Import is selected.', 'octowoo' ); ?>
                </p>
                <div class="ow-form-grid">
                    <div class="ow-form-group">
                        <label><?php esc_html_e( 'Host', 'octowoo' ); ?></label>
                        <input type="text" name="octowoo[db][host]"
                               value="<?php echo esc_attr( $config['db']['host'] ?? '127.0.0.1' ); ?>"
                               placeholder="127.0.0.1" required>
                    </div>
                    <div class="ow-form-group">
                        <label><?php esc_html_e( 'Port', 'octowoo' ); ?></label>
                        <input type="number" name="octowoo[db][port]"
                               value="<?php echo esc_attr( $config['db']['port'] ?? 3306 ); ?>"
                               placeholder="3306" min="1" max="65535">
                    </div>
                    <div class="ow-form-group">
                        <label><?php esc_html_e( 'Database Name', 'octowoo' ); ?></label>
                        <input type="text" name="octowoo[db][database]"
                               value="<?php echo esc_attr( $config['db']['database'] ?? '' ); ?>"
                               placeholder="opencart" required>
                    </div>
                    <div class="ow-form-group">
                        <label><?php esc_html_e( 'Username', 'octowoo' ); ?></label>
                        <input type="text" name="octowoo[db][username]"
                               value="<?php echo esc_attr( $config['db']['username'] ?? '' ); ?>"
                               placeholder="root" required>
                    </div>
                    <div class="ow-form-group">
                        <label><?php esc_html_e( 'Password', 'octowoo' ); ?></label>
                        <input type="password" name="octowoo[db][password]" autocomplete="new-password" placeholder="<?php echo esc_attr( ! empty( $config['db']['password'] ) ? '••••••••' : '' ); ?>">
                        <span class="ow-form-hint">
                            <?php if ( ! empty( $config['db']['password'] ) ): ?>
                                ✔ <?php esc_html_e( 'Password is saved. Leave blank to keep it, or enter a new one to change.', 'octowoo' ); ?>
                            <?php else: ?>
                                ⚠ <?php esc_html_e( 'No password saved yet. Enter the OpenCart database password.', 'octowoo' ); ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="ow-form-group">
                        <label><?php esc_html_e( 'Table Prefix', 'octowoo' ); ?></label>
                        <input type="text" name="octowoo[db][prefix]"
                               value="<?php echo esc_attr( $config['db']['prefix'] ?? 'oc_' ); ?>"
                               placeholder="oc_">
                        <span class="ow-form-hint">
                            <?php esc_html_e( 'Your OpenCart table prefix, usually oc_ — check your OpenCart config.php file. Do NOT use octowoo_oc_ (that is an internal local-import prefix).', 'octowoo' ); ?>
                        </span>
                    </div>
                    <div class="ow-form-group" style="grid-column:1/-1;">
                        <label><?php esc_html_e( 'Unix Socket Path (optional)', 'octowoo' ); ?></label>
                        <input type="text" name="octowoo[db][socket]"
                               value="<?php echo esc_attr( $config['db']['socket'] ?? '' ); ?>"
                               placeholder="/var/run/mysqld/mysqld.sock">
                        <span class="ow-form-hint">
                            <?php esc_html_e( 'Leave blank to connect via TCP (Host/Port above). Set this if you get MySQL error 1698 (auth_socket) — enter the socket path shown by: ', 'octowoo' ); ?>
                            <code>mysql -u root -e "SELECT @@socket;"</code>
                        </span>
                    </div>
                </div>

                <div style="margin-top:12px;">
                    <button type="button" id="ow-btn-test-conn" class="ow-btn ow-btn-secondary">
                        🔌 <?php esc_html_e( 'Test Connection', 'octowoo' ); ?>
                    </button>
                    <span id="ow-conn-result" style="margin-left:10px; font-size:13px;"></span>
                </div>
            </div><!-- /ow-remote-db-card -->

            <!-- hidden: persists image_source across form save -->
            <input type="hidden" name="octowoo[opencart][image_source]" id="ow-image-source-input"
                   value="<?php echo esc_attr( $config['opencart']['image_source'] ?? 'remote' ); ?>">

            <!-- OpenCart installation -->
            <div class="ow-card">
                <h2><?php esc_html_e( 'OpenCart Installation', 'octowoo' ); ?></h2>
                <div class="ow-form-grid">
                    <div class="ow-form-group">
                        <label><?php esc_html_e( 'Image Directory (absolute path)', 'octowoo' ); ?></label>
                        <input type="text" name="octowoo[opencart][image_path]"
                               value="<?php echo esc_attr( $config['opencart']['image_path'] ?? '' ); ?>"
                               placeholder="/var/www/html/opencart/image">
                        <span class="ow-form-hint"><?php esc_html_e( 'Absolute server path to OpenCart\'s /image/ directory.', 'octowoo' ); ?></span>
                    </div>
                    <div class="ow-form-group">
                        <label><?php esc_html_e( 'Old Store URL (for 301 redirects)', 'octowoo' ); ?></label>
                        <input type="url" name="octowoo[opencart][shop_url]"
                               value="<?php echo esc_attr( $config['opencart']['shop_url'] ?? '' ); ?>"
                               placeholder="https://old-shop.com">
                    </div>
                    <div class="ow-form-group">
                        <label><?php esc_html_e( 'Primary Language ID', 'octowoo' ); ?></label>
                        <input type="number" name="octowoo[opencart][language_id]"
                               value="<?php echo esc_attr( $config['opencart']['language_id'] ?? 1 ); ?>"
                               min="1">
                        <span class="ow-form-hint"><?php esc_html_e( 'Usually 1 (English).', 'octowoo' ); ?></span>
                    </div>
                    <div class="ow-form-group">
                        <label><?php esc_html_e( 'Secondary Language ID (e.g. Arabic)', 'octowoo' ); ?></label>
                        <input type="number" name="octowoo[opencart][language_id_secondary]"
                               value="<?php echo esc_attr( $config['opencart']['language_id_secondary'] ?? 0 ); ?>"
                               min="0">
                        <span class="ow-form-hint"><?php esc_html_e( '0 = disabled.', 'octowoo' ); ?></span>
                    </div>
                </div>
            </div>

            <!-- Migration options -->
            <div class="ow-card">
                <h2><?php esc_html_e( 'Migration Options', 'octowoo' ); ?></h2>
                <div class="ow-form-grid">
                    <div class="ow-form-group">
                        <label><?php esc_html_e( 'Batch Size', 'octowoo' ); ?></label>
                        <input type="number" name="octowoo[migration][batch_size]"
                               value="<?php echo esc_attr( $config['migration']['batch_size'] ?? 20 ); ?>"
                               min="5" max="500">
                        <span class="ow-form-hint"><?php esc_html_e( 'Items per request (5–500). This controls both full-run batches and chunked AJAX requests. Lower = safer on shared hosting (10–30 recommended for products).', 'octowoo' ); ?></span>
                    </div>
                    <div class="ow-form-group">
                        <label><?php esc_html_e( 'If Item Already Exists in WooCommerce', 'octowoo' ); ?></label>
                        <select name="octowoo[migration][on_duplicate]">
                            <option value="skip"   <?php selected( $config['migration']['on_duplicate'] ?? 'skip', 'skip' ); ?>>
                                <?php esc_html_e( 'Skip — keep existing WC data unchanged', 'octowoo' ); ?>
                            </option>
                            <option value="update" <?php selected( $config['migration']['on_duplicate'] ?? 'skip', 'update' ); ?>>
                                <?php esc_html_e( 'Update — overwrite price, stock, title, description from OpenCart', 'octowoo' ); ?>
                            </option>
                        </select>
                        <span class="ow-form-hint">
                            <?php esc_html_e( 'Detection is automatic: the plugin checks by OpenCart ID meta tag, so it works even after Reset Progress.', 'octowoo' ); ?>
                        </span>
                    </div>
                </div>

                <div style="margin-top:16px;">
                    <strong style="font-size:13px;"><?php esc_html_e( 'Enable / Disable Migrators:', 'octowoo' ); ?></strong>
                    <div class="ow-checkbox-group" style="margin-top:8px;">
                        <?php
                        $migrator_labels = [
                            'run_tax'            => __( 'Tax Classes', 'octowoo' ),
                            'run_order_statuses' => __( 'Order Statuses', 'octowoo' ),
                            'run_categories'     => __( 'Categories', 'octowoo' ),
                            'run_products'       => __( 'Products', 'octowoo' ),
                            'run_related'        => __( 'Related Products', 'octowoo' ),
                            'run_bundles'        => __( 'Product Bundles *', 'octowoo' ),
                            'run_images'         => __( 'Images', 'octowoo' ),
                            'run_customers'      => __( 'Customers', 'octowoo' ),
                            'run_orders'         => __( 'Orders', 'octowoo' ),
                            'run_coupons'        => __( 'Coupons', 'octowoo' ),
                            'run_seo'            => __( 'SEO URLs', 'octowoo' ),
                            'run_information'    => __( 'Information Pages', 'octowoo' ),
                            'run_tags'           => __( 'Tags', 'octowoo' ),
                            'run_filters'        => __( 'Filters', 'octowoo' ),
                            'run_downloads'      => __( 'Downloads', 'octowoo' ),
                            'run_manufacturers'  => __( 'Manufacturers', 'octowoo' ),
                            'run_reviews'        => __( 'Reviews', 'octowoo' ),
                        ];
                        foreach ( $migrator_labels as $key => $label ):
                            $checked = ! empty( $config['migration'][ $key ] );
                            ?>
                            <label>
                                <input type="checkbox" name="octowoo[migration][<?php echo esc_attr( $key ); ?>]" value="1"
                                       <?php checked( $checked ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="ow-form-hint" style="margin-top:6px;">
                        <?php esc_html_e( '* Product Bundles requires "WooCommerce Product Bundles" (SomewhereWarm) + OpenCart 4.x.', 'octowoo' ); ?>
                    </p>
                </div>
            </div>

            <!-- SEO & Redirects -->
            <div class="ow-card">
                <h2><?php esc_html_e( 'SEO & Redirects', 'octowoo' ); ?></h2>
                <div class="ow-checkbox-group">
                    <label>
                        <input type="checkbox" name="octowoo[seo][write_htaccess]" value="1"
                               <?php checked( ! empty( $config['seo']['write_htaccess'] ) ); ?>>
                        <?php esc_html_e( 'Write 301 rules to .htaccess', 'octowoo' ); ?>
                    </label>
                    <label>
                        <input type="checkbox" name="octowoo[seo][use_wp_redirects]" value="1"
                               <?php checked( ! empty( $config['seo']['use_wp_redirects'] ) ); ?>>
                        <?php esc_html_e( 'Use WordPress template_redirect hook', 'octowoo' ); ?>
                    </label>
                </div>
            </div>

            <!-- Multilingual -->
            <div class="ow-card">
                <h2><?php esc_html_e( 'Multilingual (WPML / Polylang)', 'octowoo' ); ?></h2>
                <div class="ow-checkbox-group">
                    <label>
                        <input type="checkbox" name="octowoo[multilingual][enabled]" value="1"
                               <?php checked( ! empty( $config['multilingual']['enabled'] ) ); ?>>
                        <?php esc_html_e( 'Enable multilingual import', 'octowoo' ); ?>
                    </label>
                    <label>
                        <input type="checkbox" name="octowoo[multilingual][use_wpml]" value="1"
                               <?php checked( ! empty( $config['multilingual']['use_wpml'] ) ); ?>>
                        <?php esc_html_e( 'Use WPML', 'octowoo' ); ?>
                    </label>
                    <label>
                        <input type="checkbox" name="octowoo[multilingual][use_polylang]" value="1"
                               <?php checked( ! empty( $config['multilingual']['use_polylang'] ) ); ?>>
                        <?php esc_html_e( 'Use Polylang', 'octowoo' ); ?>
                    </label>
                </div>
                <div class="ow-form-grid" style="margin-top:12px;">
                    <div class="ow-form-group">
                        <label><?php esc_html_e( 'Primary Language Locale (e.g. en_US)', 'octowoo' ); ?></label>
                        <input type="text" name="octowoo[multilingual][primary_locale]"
                               value="<?php echo esc_attr( $config['multilingual']['primary_locale'] ?? 'en_US' ); ?>"
                               placeholder="en_US">
                    </div>
                    <div class="ow-form-group">
                        <label><?php esc_html_e( 'Secondary Language Locale (e.g. ar)', 'octowoo' ); ?></label>
                        <input type="text" name="octowoo[multilingual][secondary_locale]"
                               value="<?php echo esc_attr( $config['multilingual']['secondary_locale'] ?? 'ar' ); ?>"
                               placeholder="ar">
                    </div>
                </div>
            </div>

            <!-- Cron / Dropshipping -->
            <div class="ow-card">
                <h2><?php esc_html_e( 'Dropshipping / Automatic Cron Import', 'octowoo' ); ?></h2>
                <div class="ow-checkbox-group">
                    <label>
                        <input type="checkbox" name="octowoo[cron][enabled]" value="1"
                               <?php checked( ! empty( $config['cron']['enabled'] ) ); ?>>
                        <?php esc_html_e( 'Enable automatic cron migration', 'octowoo' ); ?>
                    </label>
                </div>
                <div class="ow-form-grid" style="margin-top:12px;">
                    <div class="ow-form-group">
                        <label><?php esc_html_e( 'Cron Interval', 'octowoo' ); ?></label>
                        <select name="octowoo[cron][interval]">
                            <?php
                            $intervals = [
                                'hourly'     => __( 'Every Hour', 'octowoo' ),
                                'twicedaily' => __( 'Twice Daily', 'octowoo' ),
                                'daily'      => __( 'Daily', 'octowoo' ),
                            ];
                            $current_interval = $config['cron']['interval'] ?? 'daily';
                            foreach ( $intervals as $val => $lbl ):
                            ?>
                                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $current_interval, $val ); ?>>
                                    <?php echo esc_html( $lbl ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ow-form-group">
                        <label><?php esc_html_e( 'Cron Migrators', 'octowoo' ); ?></label>
                        <input type="text" name="octowoo[cron][migrators]"
                               value="<?php echo esc_attr( $config['cron']['migrators'] ?? 'products,images,orders' ); ?>"
                               placeholder="products,images,orders">
                        <span class="ow-form-hint"><?php esc_html_e( 'Comma-separated list of migrators to run on cron.', 'octowoo' ); ?></span>
                    </div>
                </div>
            </div>

            <!-- Customers / Security -->
            <div class="ow-card">
                <h2><?php esc_html_e( 'Customers & Security', 'octowoo' ); ?></h2>
                <div class="ow-checkbox-group">
                    <label>
                        <input type="checkbox" name="octowoo[woocommerce][force_password_reset]" value="1"
                               <?php checked( ! empty( $config['woocommerce']['force_password_reset'] ) ); ?>>
                        <?php esc_html_e( 'Force password reset on first login (recommended)', 'octowoo' ); ?>
                    </label>
                    <label>
                        <input type="checkbox" name="octowoo[woocommerce][migrate_oc_passwords]" value="1"
                               <?php checked( ! empty( $config['woocommerce']['migrate_oc_passwords'] ) ); ?>>
                        <?php esc_html_e( 'Try OC password hash on login (then upgrade to WP hash)', 'octowoo' ); ?>
                    </label>
                </div>
            </div>

            <div class="ow-actions">
                <button type="submit" id="ow-btn-save-settings" class="ow-btn ow-btn-primary">
                    💾 <?php esc_html_e( 'Save Settings', 'octowoo' ); ?>
                </button>
                <button type="button" id="ow-btn-autodetect" class="ow-btn ow-btn-secondary" style="margin-left:8px;">
                    🔎 <?php esc_html_e( 'Auto-detect Image Path & Logs', 'octowoo' ); ?>
                </button>
            </div>
        </form>

        <!-- ── Purge Imported Data ──────────────────────────────────────── -->
        <div class="ow-card" style="border:1px solid #dc3545; margin-top:24px;">
            <h2 style="color:#c62828;">⚠️ <?php esc_html_e( 'Purge Imported Data', 'octowoo' ); ?></h2>
            <p style="margin:0 0 14px; font-size:13px; color:#555;">
                <?php esc_html_e( 'Permanently delete data that was imported by OctoWoo. Only items created by this plugin (identified by an internal OctoWoo tag) are affected — your manually-added products, pages, blog posts, and admin users are never touched.', 'octowoo' ); ?>
            </p>

            <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:8px 20px; font-size:13px; margin-bottom:18px;">
                <?php
                $purge_entities = [
                    'products'      => __( 'Products (+ images & variations)', 'octowoo' ),
                    'categories'    => __( 'Categories', 'octowoo' ),
                    'tags'          => __( 'Tags', 'octowoo' ),
                    'manufacturers' => __( 'Manufacturers / Brands', 'octowoo' ),
                    'customers'     => __( 'Customers', 'octowoo' ),
                    'orders'        => __( 'Orders', 'octowoo' ),
                    'coupons'       => __( 'Coupons', 'octowoo' ),
                    'reviews'       => __( 'Reviews', 'octowoo' ),
                    'information'   => __( 'Information Pages', 'octowoo' ),
                    'filters'       => __( 'Filters', 'octowoo' ),
                    'downloads'     => __( 'Downloads', 'octowoo' ),
                ];
                foreach ( $purge_entities as $key => $label ) : ?>
                    <label style="display:flex; align-items:center; gap:7px; cursor:pointer;">
                        <input type="checkbox" class="ow-purge-chk" value="<?php echo esc_attr( $key ); ?>">
                        <?php echo esc_html( $label ); ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
                <button type="button" id="ow-btn-purge" class="ow-btn ow-btn-danger">
                    🗑 <?php esc_html_e( 'Purge Selected', 'octowoo' ); ?>
                </button>
                <label style="display:flex; align-items:center; gap:6px; font-size:13px; cursor:pointer; color:#c62828; font-weight:600;">
                    <input type="checkbox" id="ow-force-purge" value="1">
                    <?php esc_html_e( '☢ Force Purge All WooCommerce Data (ignores OctoWoo tag)', 'octowoo' ); ?>
                </label>
                <span id="ow-purge-result" style="font-size:13px;"></span>
            </div>
            <p class="ow-form-hint" style="color:#c62828; margin-top:8px;">
                <?php esc_html_e( 'Force Purge deletes ALL WooCommerce products/categories/orders/etc. — even items not imported by OctoWoo. Use this only if normal purge returns 0 and you want a complete clean slate. Customers and Information pages always require the OctoWoo tag regardless.', 'octowoo' ); ?>
            </p>
        </div>

    </div><!-- /tab-settings -->


    <!-- ═══════════════════════════════════════════════════════════════════
         TAB: LOGS
    ═════════════════════════════════════════════════════════════════════ -->
    <div id="ow-tab-logs" class="ow-tab-pane" style="display:none;">

        <div class="ow-card">
            <h2><?php esc_html_e( 'Migration Logs', 'octowoo' ); ?></h2>

            <div class="ow-log-controls">
                <label style="font-size:13px; font-weight:500;"><?php esc_html_e( 'Filter Level:', 'octowoo' ); ?></label>
                <select id="ow-log-level-filter">
                    <option value=""><?php esc_html_e( 'All', 'octowoo' ); ?></option>
                    <option value="DEBUG">DEBUG</option>
                    <option value="INFO">INFO</option>
                    <option value="WARNING">WARNING</option>
                    <option value="ERROR">ERROR</option>
                    <option value="SUCCESS">SUCCESS</option>
                </select>
                <button type="button" id="ow-btn-refresh-logs" class="ow-btn ow-btn-secondary">
                    ⟳ <?php esc_html_e( 'Refresh', 'octowoo' ); ?>
                </button>
                <button type="button" id="ow-btn-clear-logs" class="ow-btn ow-btn-secondary">
                    🗑 <?php esc_html_e( 'Clear Display', 'octowoo' ); ?>
                </button>
                <?php if ( $last_run ): ?>
                    <a href="<?php echo esc_url( OCTOWOO_PLUGIN_URL . 'logs/' ); ?>" target="_blank" class="ow-btn ow-btn-secondary">
                        📂 <?php esc_html_e( 'Download Log Files', 'octowoo' ); ?>
                    </a>
                <?php endif; ?>
            </div>

            <div id="ow-log-container" class="ow-log-container">
                <div style="color:#6e6e6e;"><?php esc_html_e( 'Select the Logs tab to load entries…', 'octowoo' ); ?></div>
            </div>
        </div>

    </div><!-- /tab-logs -->

</div><!-- #octowoo-app -->
<p style="color:#999;font-size:11px;text-align:right;margin-top:4px;">
    <?php printf( esc_html__( 'OctoWoo v%s', 'octowoo' ), esc_html( OCTOWOO_VERSION ) ); ?>
</p>

/**
 * OctoWoo – Admin Dashboard JavaScript
 *
 * Handles:
 *  - Tab switching (Migration / Settings / Logs)
 *  - Start / Resume / Abort migration via AJAX
 *  - Progress polling (every 3 s while running)
 *  - Log viewer with live refresh + level filter
 *  - Progress bar rendering
 *  - Test DB connection
 */
/* global octoWoo, jQuery */
(function ($) {
    'use strict';

    /* ── State ───────────────────────────────────────────────────────────── */
    let pollTimer      = null;
    let currentRunId   = octoWoo.activeRunId || octoWoo.lastRunId || '';
    // isRunning = true only when THIS page's JS is actively firing chunks.
    // An activeRunId in the DB just means a run can be resumed; it does NOT
    // mean this JS instance is sending requests.
    let isRunning      = false;
    let isPausedState  = false;
    let currentMigrator = '';
    // Epoch counter incremented every time a NEW migration session starts.
    // Each runNextChunk() call captures the epoch at dispatch time; the .done
    // callback discards the response if the epoch has since changed.  This
    // prevents a slow in-flight chunk from a previous run (e.g. a full
    // migration that was running while the user clicks a recovery button) from
    // clobbering currentRunId and firing unwanted migrators.
    let migrationEpoch = 0;

    /* ── DOM refs (populated in init) ────────────────────────────────────── */
    let $progressTable, $logContainer, $statusBanner, $btnStart, $btnResume, $btnAbort, $btnPause, $btnSkip, $btnReset;

    /* ── Init ────────────────────────────────────────────────────────────── */
    function init() {
        // Cache DOM refs.
        $progressTable = $('#ow-progress-table');
        $logContainer  = $('#ow-log-container');
        $statusBanner  = $('#ow-status-banner');
        $btnStart      = $('#ow-btn-start');
        $btnResume     = $('#ow-btn-resume');
        $btnAbort      = $('#ow-btn-abort');
        $btnPause      = $('#ow-btn-pause');
        $btnSkip       = $('#ow-btn-skip');
        $btnReset      = $('#ow-btn-reset');

        // Tab navigation (query-string approach for back-button support).
        $(document).on('click', '.ow-tab-btn', function (e) {
            e.preventDefault();
            switchTab($(this).data('tab'));
        });

        // Activate correct tab on load.
        const urlParams = new URLSearchParams(window.location.search);
        switchTab(urlParams.get('tab') || 'migration');

        // Button handlers.
        $btnStart.on('click', function () { startMigration(false, false); });
        $btnResume.on('click', function () { startMigration(true,  false); });
        $btnAbort.on('click', abortMigration);
        $btnPause.on('click', pauseMigration);
        $btnSkip.on('click', skipCurrentMigrator);
        $btnReset.on('click', resetMigration);

        $('#ow-btn-demo').on('click', function () { startMigration(false, true); });
        $('#ow-btn-images-only').on('click', startImagesOnlyRecovery);
        $('#ow-btn-products-images').on('click', startProductsImagesRecovery);
        $('#ow-btn-cats-manufacturers').on('click', startCategoriesManufacturersRecovery);
        $('#ow-btn-multilingual').on('click', startMultilingualRecovery);
        $('#ow-btn-cleanup-ml-terms').on('click', cleanupMlTerms);
        $('#ow-btn-repair-order-items').on('click', repairOrderItems);

        $('#ow-btn-test-conn').on('click', testConnection);
        $('#ow-btn-autodetect').on('click', function () {
            const $btn = $(this);
            $btn.prop('disabled', true).text('🔎 Detecting…');
            $.post(octoWoo.ajaxUrl, {
                action: 'octowoo_prescan',
                nonce:  octoWoo.nonce,
            })
            .done(function (res) {
                if (!res.success) {
                    alert('Auto-detect failed: ' + (res.data && res.data.message ? res.data.message : 'Unknown'));
                    return;
                }
                const data = res.data || {};
                // If scanner found a local images dir, populate hidden input and visible field.
                if (data.images && data.images.detected_path) {
                    $('#ow-image-source-input').val('local');
                    $('#ow-tab-settings').find('input[name="octowoo[opencart][image_path]"]').val(data.images.detected_path);
                }
                // If logger writable info returned, inform the admin.
                let msg = 'Auto-detect complete.';
                if (data.logs) {
                    msg += ' Logs dir: ' + (data.logs.path || 'unknown') + ' (' + (data.logs.writable ? 'writable' : 'not writable') + ')';
                }
                alert(msg);
            })
            .fail(function (xhr) {
                alert('Auto-detect request failed: ' + xhr.statusText);
            })
            .always(function () {
                $btn.prop('disabled', false).text('🔎 Auto-detect Image Path & Logs');
            });
        });

        $('#ow-btn-scan').on('click', scanSourceCounts);

        // System (pre-migration) check.
        $('#ow-btn-validate').on('click', runSystemCheck);

        // Background mode (Action Scheduler).
        $('#ow-btn-start-bg').on('click', function () { startBackgroundMigration(false); });
        $('#ow-btn-resume-bg').on('click', function () { startBackgroundMigration(true); });
        $('#ow-btn-cancel-bg').on('click', cancelBackgroundMigration);

        // Log controls.
        $('#ow-log-level-filter').on('change', function () { refreshLogs(); });
        $('#ow-btn-refresh-logs').on('click', function () { refreshLogs(); });
        $('#ow-btn-clear-logs').on('click', function () { $logContainer.empty(); });

        // If there's a run (active or last), show its progress immediately.
        if (currentRunId) {
            pollProgress();
        }

        // If there's an active run in the DB (interrupted), start continuous
        // polling so the table stays live — and enable Abort.
        if (octoWoo.activeRunId) {
            startPolling();
            $btnAbort.prop('disabled', false);
            $btnPause.prop('disabled', false);
            $btnSkip.prop('disabled', false);
        }

        // Settings: live validation feedback.
        $('#octowoo-settings-form').on('input change', 'input, select', validateSettingsForm);

        // ── Source mode (remote vs local import) ──────────────────────────
        $('input[name="octowoo[source]"]').on('change', function () {
            const isLocal = $(this).val() === 'local';
            $('#ow-local-import-area').toggle(isLocal);
            $('#ow-remote-db-card').css({
                opacity: isLocal ? 0.5 : 1,
                pointerEvents: isLocal ? 'none' : ''
            });
            $('.ow-source-btn').removeClass('active');
            $(this).closest('.ow-source-btn').addClass('active');
            // Keep hidden image_source input in sync.
            $('#ow-image-source-input').val(isLocal ? 'local' : 'remote');
        });

        // Trigger once on page load to honour the saved value.
        $('input[name="octowoo[source]"]:checked').trigger('change');

        // ── SQL import button ─────────────────────────────────────────────
        $('#ow-btn-import-sql').on('click', function () {
            var file = document.getElementById('ow-sql-file').files[0];
            if (!file) { alert('Please select a SQL or .sql.gz file first.'); return; }
            var prefix = $('#ow-sql-prefix').val() || 'oc_';
            var fd = new FormData();
            fd.append('action', 'octowoo_import_sql');
            fd.append('nonce', octoWoo.nonce);
            fd.append('sql_file', file);
            fd.append('source_prefix', prefix);
            $('#ow-sql-progress').show();
            $('#ow-sql-progress-bar').css('width', '0%');
            $('#ow-sql-status').text('Uploading…');
            $('#ow-sql-result').text('').css('color', '#555');
            $('#ow-btn-import-sql').prop('disabled', true).text('Importing…');
            $.ajax({
                url: octoWoo.ajaxUrl,
                type: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                xhr: function () {
                    var xhr = new window.XMLHttpRequest();
                    xhr.upload.addEventListener('progress', function (e) {
                        if (e.lengthComputable) {
                            var pct = Math.round(e.loaded / e.total * 100);
                            $('#ow-sql-progress-bar').css('width', pct + '%');
                            $('#ow-sql-status').text('Uploading: ' + pct + '%');
                        }
                    }, false);
                    return xhr;
                }
            })
            .done(function (res) {
                if (res.success) {
                    $('#ow-sql-result').text('✔ ' + res.data.message).css('color', '#2e7d32');
                    $('#ow-sql-progress-bar').css('width', '100%').addClass('done');
                    $('#ow-sql-status').text('Done');                    // Refresh the persistent status banner without a page reload.
                    var d = res.data;
                    var tables   = d.tables   || 0;
                    var filename = d.filename || '';
                    var $status  = $('#ow-sql-imported-status');
                    if (tables > 0) {
                        var html = '\u2714 <strong>SQL data ready:</strong> ' + tables + ' tables imported';
                        if (filename) { html += ' \u2014 <code>' + $('<span>').text(filename).html() + '</code>'; }
                        html += ' <span style="float:right;"><a href="#" id="ow-btn-drop-sql" style="color:#c62828;font-size:11px;">\u2715 Clear</a></span>';
                        $status.html(html)
                               .css({'background':'#edf7ed','border-color':'#4caf50','color':'#1b5e20','display':'block'});
                    }                } else {
                    $('#ow-sql-result').text('✘ ' + (res.data && res.data.message ? res.data.message : 'Import failed.')).css('color', '#c62828');
                }
            })
            .fail(function (xhr) {
                $('#ow-sql-result').text('✘ Request failed: ' + xhr.statusText).css('color', '#c62828');
            })
            .always(function () {
                $('#ow-btn-import-sql').prop('disabled', false).text('⬆ Import SQL');
                setTimeout(function () { $('#ow-sql-progress').hide(); }, 2000);
            });
        });

        // ── Drop imported SQL tables (Clear button) ───────────────────────
        $(document).on('click', '#ow-btn-drop-sql', function (e) {
            e.preventDefault();
            if (!confirm('This will drop all imported OpenCart tables (octowoo_oc_*) from this WordPress database. Continue?')) { return; }
            $.post(octoWoo.ajaxUrl, {
                action: 'octowoo_drop_sql',
                nonce:  octoWoo.nonce,
            })
            .done(function (res) {
                if (res.success) {
                    $('#ow-sql-imported-status').hide();
                    $('#ow-sql-result').text('\u2714 ' + res.data.message).css('color', '#2e7d32');
                } else {
                    alert((res.data && res.data.message) ? res.data.message : 'Drop failed.');
                }
            });
        });

        // ── Images ZIP upload button ──────────────────────────────────────
        $('#ow-btn-import-images').on('click', function () {
            var file = document.getElementById('ow-images-zip').files[0];
            if (!file) { alert('Please select a ZIP file first.'); return; }
            var fd = new FormData();
            fd.append('action', 'octowoo_import_images');
            fd.append('nonce', octoWoo.nonce);
            fd.append('images_zip', file);
            $('#ow-images-result').text('Uploading and extracting…').css('color', '#555');
            $('#ow-btn-import-images').prop('disabled', true).text('Extracting…');
            $.ajax({
                url: octoWoo.ajaxUrl,
                type: 'POST',
                data: fd,
                processData: false,
                contentType: false
            })
            .done(function (res) {
                if (res.success) {
                    $('#ow-images-result').text('✔ ' + res.data.message).css('color', '#2e7d32');
                } else {
                    $('#ow-images-result').text('✘ ' + (res.data && res.data.message ? res.data.message : 'Upload failed.')).css('color', '#c62828');
                }
            })
            .fail(function (xhr) {
                $('#ow-images-result').text('✘ ' + xhr.statusText).css('color', '#c62828');
            })
            .always(function () {
                $('#ow-btn-import-images').prop('disabled', false).text('🖼 Upload & Extract');
            });
        });

        // ── Step 2 Additional Options — Select All / Deselect All ─────────
        $('#ow-opt-select-all').on('click', function () {
            $('.ow-step2-opt').prop('checked', true);
        });
        $('#ow-opt-deselect-all').on('click', function () {
            $('.ow-step2-opt').prop('checked', false);
        });

        // ── Purge imported data ───────────────────────────────────────────

        // Select All / Deselect All toggles.
        $('#ow-btn-select-all').on('click', function () {
            $('.ow-purge-chk').prop('checked', true);
        });
        $('#ow-btn-deselect-all').on('click', function () {
            $('.ow-purge-chk').prop('checked', false);
        });

        // Helper: fire a purge AJAX call with given entities + force flag.
        function doPurge( entities, force, $btn, originalLabel ) {
            const $result = $('#ow-purge-result');
            $btn.prop('disabled', true).text('Purging…');
            $result.text('').css('color', '#555');

            $.post(octoWoo.ajaxUrl, {
                action:   'octowoo_purge_imported',
                nonce:    octoWoo.nonce,
                entities: entities,
                force:    force ? '1' : '0',
            })
            .done(function (res) {
                if (res.success) {
                    const breakdown = Object.entries(res.data.results || {})
                        .map(([k, v]) => k + ': ' + v)
                        .join(', ');
                    let msg = '✔ ' + res.data.message + (breakdown ? ' (' + breakdown + ')' : '');
                    $result.text(msg).css('color', '#2e7d32');

                    // Show diagnostic hints when 0 items were deleted but WC has data.
                    const hints = res.data.hints || [];
                    if (hints.length > 0) {
                        const $hint = $('<div style="margin-top:6px;color:#b45309;font-size:12px;"></div>');
                        hints.forEach(function (h) {
                            $hint.append($('<p style="margin:2px 0;">⚠ ' + h + '</p>'));
                        });
                        $result.after($hint);
                        $('#ow-btn-purge').one('click', function () { $hint.remove(); });
                    }

                    // Uncheck boxes after success.
                    $('.ow-purge-chk').prop('checked', false);
                } else {
                    $result.text('✘ ' + (res.data.message || 'Purge failed.')).css('color', '#c62828');
                }
            })
            .fail(function (xhr) {
                $result.text('✘ ' + (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
                    ? xhr.responseJSON.data.message
                    : xhr.statusText)).css('color', '#c62828');
            })
            .always(function () {
                $btn.prop('disabled', false).text(originalLabel);
            });
        }

        // Purge Selected button.
        $('#ow-btn-purge').on('click', function () {
            const entities = [];
            $('.ow-purge-chk:checked').each(function () { entities.push($(this).val()); });
            if (entities.length === 0) {
                alert('Please select at least one entity type to purge.');
                return;
            }
            const force    = $('#ow-force-purge').is(':checked');
            const listStr  = entities.join(', ');
            const modeStr  = force
                ? '⚠ FORCE mode: ALL WooCommerce data regardless of OctoWoo tag.'
                : 'Only OctoWoo-imported items (tagged by this plugin).';
            const confirmMsg = force
                ? '☢ FORCE PURGE WARNING ☢\n\nThis will permanently delete ALL WooCommerce data for:\n\n' + listStr + '\n\n' + modeStr + '\n\nThis cannot be undone. Type "FORCE" to confirm:'
                : '⚠ WARNING: This will permanently delete all OctoWoo-imported data for:\n\n' + listStr + '\n\nThis cannot be undone. Proceed?';

            if ( force ) {
                const typed = prompt( confirmMsg );
                if ( typed === null || typed.trim().toUpperCase() !== 'FORCE' ) { return; }
            } else {
                if ( ! confirm( confirmMsg ) ) { return; }
            }

            doPurge( entities, force, $(this), '🗑 Purge Selected' );
        });

        // Purge Everything (Force) — selects all entities + force in one click.
        $('#ow-btn-purge-everything').on('click', function () {
            const allEntities = [];
            $('.ow-purge-chk').each(function () { allEntities.push($(this).val()); });

            const typed = prompt(
                '☢ PURGE EVERYTHING ☢\n\n' +
                'This will force-delete ALL of the following:\n' +
                '  • Products, Variations, Images\n' +
                '  • Categories, Tags, Brands, Filters\n' +
                '  • Orders, Coupons\n' +
                '  • Customers (OctoWoo-tagged only)\n' +
                '  • Reviews\n' +
                '  • Information Pages (WooCommerce shop/cart/checkout and home page are protected)\n' +
                '  • Downloads\n\n' +
                'Your theme, design, menus, WooCommerce settings, and admin users are NOT affected.\n\n' +
                'This cannot be undone. Type "FORCE" to confirm:'
            );
            if ( typed === null || typed.trim().toUpperCase() !== 'FORCE' ) { return; }

            doPurge( allEntities, true, $(this), '☢ Purge Everything (Force)' );
        });
    }

    /* ── Pre-migration system check ──────────────────────────────────── */
    function runSystemCheck() {
        const $btn    = $('#ow-btn-validate');
        const $panel  = $('#ow-validate-results');

        $btn.prop('disabled', true).html('<span class="ow-spinner"></span>&nbsp; Checking…');
        $panel.html('<em style="color:#888;">Running checks…</em>').show();

        $.post(octoWoo.ajaxUrl, {
            action: 'octowoo_validate',
            nonce:  octoWoo.nonce,
        })
        .done(function (res) {
            if (!res.success) {
                $panel.html('<span style="color:#c62828;">✘ Validation request failed.</span>');
                return;
            }
            const data    = res.data;
            const results = data.results || {};
            const asAvail = data.as_available;

            // Show/hide Background button based on AS availability.
            if (asAvail) {
                $('.ow-bg-controls').show();
                $('#ow-bg-as-notice').hide();
            } else {
                $('.ow-bg-controls').hide();
                $('#ow-bg-as-notice').show();
            }

            const labelMap = {
                woocommerce:    'WooCommerce',
                php_version:    'PHP Version',
                php_extensions: 'PHP Extensions',
                memory_limit:   'Memory Limit',
                upload_limit:   'Upload Limit',
                max_execution:  'Execution Time',
                db_connection:  'DB Connection',
                image_path:     'Image Path',
                log_directory:  'Log Directory',
                disk_space:     'Disk Space',
                hpos_compat:    'WC HPOS',
            };

            const iconMap = { pass: '✔', warning: '⚠', fail: '✘' };
            const colorMap = { pass: '#2e7d32', warning: '#b45309', fail: '#c62828' };
            const bgMap    = { pass: '#edf7ed', warning: '#fffbeb', fail: '#fef2f2' };

            let html = '<table class="ow-validate-table" style="width:100%;border-collapse:collapse;font-size:13px;">';
            html += '<thead><tr>';
            html += '<th style="text-align:left;padding:6px 10px;background:#f0f0f0;">Check</th>';
            html += '<th style="text-align:left;padding:6px 10px;background:#f0f0f0;">Status</th>';
            html += '<th style="text-align:left;padding:6px 10px;background:#f0f0f0;">Details</th>';
            html += '<th style="text-align:left;padding:6px 10px;background:#f0f0f0;">How to fix</th>';
            html += '</tr></thead><tbody>';

            Object.entries(results).forEach(function ([key, check]) {
                const status = check.status || 'pass';
                const icon   = iconMap[status]  || '?';
                const color  = colorMap[status] || '#333';
                const bg     = bgMap[status]    || '#fff';
                const label  = labelMap[key]    || key;

                html += '<tr style="background:' + bg + ';border-bottom:1px solid #e5e5e5;">';
                html += '<td style="padding:5px 10px;font-weight:600;">' + $('<span>').text(label).html() + '</td>';
                html += '<td style="padding:5px 10px;color:' + color + ';font-weight:700;white-space:nowrap;">'  + icon + ' ' + $('<span>').text(status.toUpperCase()).html() + '</td>';
                html += '<td style="padding:5px 10px;color:' + color + ';">' + $('<span>').text(check.message || '').html();
                if (check.value) { html += ' <code style="font-size:11px;background:#f5f5f5;padding:1px 4px;border-radius:2px;">' + $('<span>').text(check.value).html() + '</code>'; }
                html += '</td>';
                html += '<td style="padding:5px 10px;font-size:11px;color:#666;">' + (check.fix ? $('<span>').text(check.fix).html() : '') + '</td>';
                html += '</tr>';
            });

            html += '</tbody></table>';

            // Summary banner.
            let summaryHtml;
            if (data.all_passed) {
                summaryHtml = '<div style="padding:8px 12px;background:#edf7ed;border:1px solid #4caf50;color:#1b5e20;border-radius:4px;margin-bottom:8px;font-weight:600;">✔ All checks passed — your server is ready to migrate.</div>';
            } else if (data.has_warnings) {
                summaryHtml = '<div style="padding:8px 12px;background:#fffbeb;border:1px solid #f59e0b;color:#78350f;border-radius:4px;margin-bottom:8px;font-weight:600;">⚠ Some warnings detected — migration can proceed but review the notes below.</div>';
            } else {
                summaryHtml = '<div style="padding:8px 12px;background:#fef2f2;border:1px solid #ef4444;color:#7f1d1d;border-radius:4px;margin-bottom:8px;font-weight:600;">✘ One or more checks failed — fix these issues before migrating.</div>';
            }

            $panel.html(summaryHtml + html);
        })
        .fail(function (xhr) {
            $panel.html('<span style="color:#c62828;">✘ System check request failed: ' + xhr.statusText + '</span>');
        })
        .always(function () {
            $btn.prop('disabled', false).text('🔎 Run System Check');
        });
    }

    /* ── Background mode migration ───────────────────────────────────── */
    function startBackgroundMigration(resume) {
        if (isRunning) { return; }

        if (!resume) {
            if (!confirm('Start migration in Background mode?\n\nWooCommerce Action Scheduler will process batches in the background.\nYou can close this tab — check back for progress.')) {
                return;
            }
        }

        const migrators  = buildMigrators();
        const $btnBg     = $('#ow-btn-start-bg');
        const $bgStatus  = $('#ow-bg-status');

        $btnBg.prop('disabled', true).html('<span class="ow-spinner"></span>&nbsp; Queuing…');
        $bgStatus.text('');

        $.post(octoWoo.ajaxUrl, {
            action:    'octowoo_start_background',
            nonce:     octoWoo.nonce,
            resume:    resume ? 1 : 0,
            migrators: migrators,
        })
        .done(function (res) {
            if (res.success) {
                currentRunId = res.data.run_id || currentRunId;
                $bgStatus.css('color', '#2e7d32').text('✔ ' + res.data.message);
                setBannerInfo('Background migration queued. Progress updates every few seconds.');
                startPolling();
                // Enable the Cancel BG button.
                $('#ow-btn-cancel-bg').prop('disabled', false);
                $btnAbort.prop('disabled', false);
                $btnPause.prop('disabled', false);
                $btnSkip.prop('disabled', false);
            } else {
                $bgStatus.css('color', '#c62828').text('✘ ' + (res.data.message || 'Failed to queue.'));
            }
        })
        .fail(function (xhr) {
            $bgStatus.css('color', '#c62828').text('✘ Request failed: ' + xhr.statusText);
        })
        .always(function () {
            $btnBg.prop('disabled', false).text('⚙ Start in Background');
        });
    }

    function cancelBackgroundMigration() {
        const runId = currentRunId || octoWoo.activeRunId;
        if (!confirm('Cancel the background migration?')) { return; }

        $.post(octoWoo.ajaxUrl, {
            action: 'octowoo_cancel_background',
            nonce:  octoWoo.nonce,
            run_id: runId,
        })
        .done(function (res) {
            if (res.success) {
                stopPolling();
                setButtonState('idle');
                setBannerInfo(res.data.message);
                $('#ow-btn-cancel-bg').prop('disabled', true);
                $btnPause.prop('disabled', true);
                $btnSkip.prop('disabled', true);
            } else {
                setBannerError(res.data.message || 'Cancel failed.');
            }
        });
    }

    /* ── Source database scan ─────────────────────────────────────────── */
    function scanSourceCounts() {
        var $btn    = $('#ow-btn-scan');
        var $result = $('#ow-scan-result');
        $btn.prop('disabled', true).text('Scanning…');
        $result.show().html('<em style="color:#888;">Connecting to source database…</em>');

        $.post(octoWoo.ajaxUrl, {
            action: 'octowoo_scan_counts',
            nonce:  octoWoo.nonce,
        })
        .done(function (res) {
            $result.data('scanned', true);
            if (res.success) {
                var counts = res.data.counts;

                // Populate count badges next to entity checkboxes.
                $('.ow-count-badge[data-scan]').each(function () {
                    var key = $(this).data('scan');
                    var val = counts[key];
                    if (val !== undefined && val !== -1) {
                        $(this).text(parseInt(val, 10).toLocaleString()).show();
                    }
                });

                // Inline summary line.
                var parts = [];
                var summary = {
                    products: 'Products', categories: 'Categories', customers: 'Customers',
                    orders: 'Orders', coupons: 'Coupons', reviews: 'Reviews',
                    manufacturers: 'Manufacturers', information: 'Pages',
                    tax_classes: 'Tax', tags: 'Tags',
                };
                $.each(summary, function (k, label) {
                    var v = counts[k];
                    if (v !== undefined && v !== -1) {
                        parts.push('<strong>' + parseInt(v,10).toLocaleString() + '</strong>&nbsp;' + label);
                    }
                });
                $result.html(
                    '<span style="color:#2271b1;font-weight:600;">✔ Source scanned.</span>&nbsp;&nbsp;' +
                    parts.join('&nbsp;&nbsp;·&nbsp;&nbsp;')
                );
            } else {
                $result.html('<span style="color:#c62828;">✘ ' +
                    ((res.data && res.data.message) ? res.data.message : 'Scan failed.') + '</span>');
            }
        })
        .fail(function (xhr) {
            $result.data('scanned', true);
            $result.show().html('<span style="color:#c62828;">✘ ' + xhr.statusText + '</span>');
        })
        .always(function () {
            $btn.prop('disabled', false).text('🔍 Scan Source DB');
        });
    }

    /* ── Tab switching ───────────────────────────────────────────────────── */
    function switchTab(tab) {
        $('.ow-tab-btn').removeClass('active');
        $('.ow-tab-pane').hide();
        $('[data-tab="' + tab + '"]').addClass('active');
        $('#ow-tab-' + tab).show();

        // Refresh logs when switching to logs tab.
        if (tab === 'logs') {
            refreshLogs();
        }

        // Auto-scan when migration tab first opened.
        if (tab === 'migration' && !$('#ow-scan-result').data('scanned')) {
            scanSourceCounts();
        }

        // Update URL without reload.
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.replaceState({}, '', url.toString());
    }

    /* ── Migration start / resume ────────────────────────────────────────── */
    let chunkMigrators    = '';
    let chunkDryRun        = 0;
    let chunkDemoLimit     = 0;
    let chunkFailCount     = 0;
    let chunkOnDuplicate   = 'skip'; // 'skip' | 'update'
    let chunkClearOrdersPending = false;

    /**
     * Build a comma-separated migrator list from the entity + option checkboxes.
     */
    function buildMigrators() {
        const ENTITY_MAP = {
            'products':      ['products'],
            'related':       ['related'],
            'reviews':       ['reviews'],
            'bundles':       ['bundles'],
            'categories':    ['categories'],
            'manufacturers': ['manufacturers'],
            'tax_classes':   ['tax', 'order_statuses'],
            'customers':     ['customers'],
            'orders':        ['orders'],
            'information':   ['information'],
            'coupons':       ['coupons'],
            'tags_filters':  ['tags', 'filters'],
        };
        const list = [];
        function add(m) { if (!list.includes(m)) list.push(m); }

        $('.ow-entity-chk:checked').each(function () {
            const key = $(this).val();
            if (ENTITY_MAP[key]) { ENTITY_MAP[key].forEach(add); }
        });

        if ($('#ow-opt-images').is(':checked'))       add('images');
        if ($('#ow-opt-seo').is(':checked'))          add('seo');
        if ($('#ow-opt-downloads').is(':checked'))    add('downloads');
        if ($('#ow-opt-multilingual').is(':checked')) add('multilingual');

        return list.join(',');
    }

    function startMigration(resume, isDemo, forcedMigrators, customModeLabel, noClearOrders) {
        if (isRunning) { return; }

        // Resume: always call octowoo_resume_migration so the server can
        // re-activate aborted checkpoints back to pending.  Use activeRunId
        // first, then fall back to lastRunId so an accidentally aborted run
        // (where the active lock was already cleared) can still be recovered.
        if (resume && (isPausedState || currentRunId || octoWoo.lastRunId)) {
            const resumeRunId = currentRunId || octoWoo.activeRunId || octoWoo.lastRunId || '';
            $.post(octoWoo.ajaxUrl, {
                action: 'octowoo_resume_migration',
                nonce:  octoWoo.nonce,
                run_id: resumeRunId,
            }).always(function (res) {
                // Use the server-returned run_id if provided (covers the last_run fallback).
                if (res && res.success && res.data && res.data.run_id) {
                    currentRunId = res.data.run_id;
                } else if (resumeRunId) {
                    currentRunId = resumeRunId;
                }
                startMigrationInternal(resume, isDemo, forcedMigrators, customModeLabel, noClearOrders);
            });
            return;
        }

        startMigrationInternal(resume, isDemo, forcedMigrators, customModeLabel, noClearOrders);
    }

    function startMigrationInternal(resume, isDemo, forcedMigrators, customModeLabel, noClearOrders) {
        if (isRunning) { return; }

        isPausedState = false;

        const demoLimitVal = isDemo ? 20 : 0;

        if (!resume) {
            const modeStr = isDemo
                ? 'Demo Migration (first 20 items per entity)'
                : (customModeLabel || 'Full Migration');
            const confirmMsg = noClearOrders
                ? 'Start ' + modeStr + '?'
                : 'Start ' + modeStr + '?\n\nOpenCart data will be imported into your WooCommerce store.';
            if (!confirm(confirmMsg)) { return; }
        }

        chunkMigrators  = forcedMigrators || buildMigrators();
        chunkDryRun     = 0;
        chunkDemoLimit  = demoLimitVal;
        // Read the on_duplicate selection live from the DOM so the user's
        // current choice always applies without needing to save settings first.
        chunkOnDuplicate = $('select[name="octowoo[migration][on_duplicate]"]').val() || 'skip';
        // Recovery runs must never clear orders — only a full fresh migration should.
        chunkClearOrdersPending = !resume && !noClearOrders;

        setButtonState('running');
        setBannerRunning();

        // For resume, keep the existing currentRunId; for a fresh start, clear it
        // so the server assigns a new one on the first chunk.
        if (!resume) {
            currentRunId = '';
        }
        chunkFailCount = 0;
        isRunning = true;
        migrationEpoch++; // invalidate any in-flight responses from previous sessions
        // NOTE: polling is started AFTER the first chunk response sets a valid
        // currentRunId.  Starting it here with an empty run_id causes a race:
        // the poll hits the server before markRunActive() runs, the server
        // returns active:false for the OLD finished run, and the JS callback
        // shows "Migration completed!" before the first chunk even finishes.
        runNextChunk();
    }

    function startImagesOnlyRecovery() {
        // Re-import all images: product images (ImageMigrator), category thumbnails
        // (CategoryMigrator), and brand/manufacturer logos (ManufacturerMigrator).
        startMigration(false, false, 'images,categories,manufacturers', 'Images-Only Recovery', true);
    }

    function startProductsImagesRecovery() {
        // Recovery mode: refresh product-image linking and gallery imports
        // without running unrelated entities.
        startMigration(false, false, 'products,images,related', 'Products + Images Recovery', true);
    }

    function startCategoriesManufacturersRecovery() {
        // Recovery mode for taxonomy/brand terms and their media,
        // without touching orders/customers/coupons.
        startMigration(false, false, 'categories,manufacturers', 'Categories + Manufacturers Recovery', true);
    }

    function startMultilingualRecovery() {
        // Re-run only the WPML/Polylang translation pass without re-migrating
        // primary entities. Useful after fixing Arabic/multilingual issues.
        startMigration(false, false, 'multilingual', 'Multilingual-only Recovery', true);
    }

    /**
     * Delete placeholder ('octowoo-ar-new-…') and duplicate WPML category terms
     * left over from failed/retried chunk runs.  One-shot AJAX — not a migration.
     */
    function cleanupMlTerms() {
        const $btn = $('#ow-btn-cleanup-ml-terms');
        if ($btn.prop('disabled')) { return; }
        $btn.prop('disabled', true).text('🧹 Cleaning…');
        $.post(octoWoo.ajaxUrl, {
            action: 'octowoo_cleanup_ml_terms',
            nonce:  octoWoo.nonce,
        })
        .done(function (res) {
            if (res && res.success) {
                alert(res.data.message || 'Cleanup complete.');
            } else {
                alert('Cleanup failed: ' + ((res && res.data && res.data.message) || 'Unknown error'));
            }
        })
        .fail(function () {
            alert('Cleanup request failed. Check the server logs.');
        })
        .always(function () {
            $btn.prop('disabled', false).text('🧹 Fix Orphan Categories');
        });
    }

    function repairOrderItems() {
        const $btn = $('#ow-btn-repair-order-items');
        if ($btn.prop('disabled')) { return; }
        $btn.prop('disabled', true);

        let totalRelinked = 0;

        function runBatch(isFirst) {
            $btn.text('🔗 Repairing… (' + totalRelinked + ' linked)');
            $.post(octoWoo.ajaxUrl, {
                action: 'octowoo_repair_order_items',
                nonce:  octoWoo.nonce,
                reset:  isFirst ? 1 : 0,
            })
            .done(function (res) {
                if (!res || !res.success) {
                    alert('Repair failed: ' + ((res && res.data && res.data.message) || 'Unknown error'));
                    $btn.prop('disabled', false).text('🔗 Repair Order Items');
                    return;
                }
                totalRelinked += (res.data.relinked || 0);
                if (res.data.done) {
                    alert('Repair complete. Re-linked ' + totalRelinked + ' order item(s) to current products.');
                    $btn.prop('disabled', false).text('🔗 Repair Order Items');
                } else {
                    runBatch(false);
                }
            })
            .fail(function () {
                alert('Repair request failed. Check the server logs.');
                $btn.prop('disabled', false).text('🔗 Repair Order Items');
            });
        }

        runBatch(true);
    }

    function runNextChunk() {
        if (!isRunning) { return; }

        const shouldClearOrders = chunkClearOrdersPending ? 1 : 0;
        // Send clear_orders only once at the beginning of a new run.
        chunkClearOrdersPending = false;

        // Capture epoch at dispatch time so the .done handler can detect if
        // this response is stale (a newer migration session has started).
        const dispatchEpoch = migrationEpoch;

        $.post(octoWoo.ajaxUrl, {
            action:       'octowoo_run_chunk',
            nonce:        octoWoo.nonce,
            run_id:       currentRunId,
            resume:       currentRunId ? 1 : 0,
            dry_run:      chunkDryRun,
            demo_limit:   chunkDemoLimit,
            on_duplicate: chunkOnDuplicate,
            clear_orders: shouldClearOrders,
            migrators:    chunkMigrators,
        })
        .done(function (res) {
            // Discard responses from a previous migration session.
            if (dispatchEpoch !== migrationEpoch) { return; }
            if (!res.success) {
                // If this is a DB connection error, give a helpful message.
                var msg = (res.data && res.data.message) ? res.data.message : 'Chunk error.';
                if (res.data && res.data.db_error) {
                    msg = '🔌 ' + msg + '\n\nPlease go to Settings tab and configure your OpenCart database credentials, then try again.';
                }
                setBannerError(msg);
                setButtonState('idle');
                isRunning = false;
                return;
            }

            chunkFailCount = 0; // reset on any successful HTTP response
            const d = res.data;
            var wasFirstChunk = !currentRunId;
            currentRunId = d.run_id || currentRunId;
            currentMigrator = d.migrator || currentMigrator;
            const checkpoints = d.checkpoints || [];
            renderProgressTable(checkpoints);

            if (d.paused) {
                isRunning = false;
                isPausedState = true;
                stopPolling();
                setButtonState('paused');
                setBannerInfo('Migration paused. Click Resume to continue from the same checkpoint.');
                return;
            }

            if (d.skipped && d.migrator) {
                setBannerInfo('Skipped current entity: ' + d.migrator + '. Continuing with next entity...');
            }

            // Defensive guard: if backend reports done_all but checkpoints still
            // have pending/running rows, keep chunking instead of showing a false
            // "completed" banner.
            if (d.done_all && hasNonTerminalCheckpoints(checkpoints)) {
                setBannerInfo('Migration still has pending entities. Continuing...');
                setTimeout(runNextChunk, 300);
                return;
            }

            // Start fallback polling after the first chunk so we have a valid
            // currentRunId — avoids the race condition where an immediate poll
            // with an empty run_id sees the OLD finished run and kills the loop.
            if (wasFirstChunk && currentRunId) {
                startPolling();
            }

            if (d.done_all || d.aborted) {
                isRunning = false;
                isPausedState = false;
                stopPolling();
                setButtonState('idle');
                $btnResume.prop('disabled', true);
                $('#ow-active-run-banner').hide();
                if (d.aborted) {
                    setBannerInfo(octoWoo.i18n.aborted);
                } else {
                    setBannerDone(d.report);
                }
                // Scroll the progress table into view so the user sees the final
                // state — especially important for fast demo runs where progress
                // updates flash by in under a second.
                var $table = $('#ow-progress-table');
                if ($table.length) {
                    $table[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
                refreshLogs();
            } else {
                // Fire next chunk immediately (no delay needed — each request is short).
                setTimeout(runNextChunk, 50);
            }
        })
        .fail(function (xhr) {
            chunkFailCount++;
            const httpStatus = xhr.status || 0;

            // Try to parse JSON body from our shutdown handler.
            let fatalMsg = '';
            try {
                const j = JSON.parse(xhr.responseText);
                if (j && j.data && j.data.message) {
                    fatalMsg = j.data.message;
                }
            } catch (e) { /* not JSON */ }

            const preview = fatalMsg || (xhr.responseText || '').replace(/<[^>]*>/g, ' ').trim().substring(0, 300);

            if (chunkFailCount >= 3) {
                // Too many failures — stop and show diagnostic.
                const detail = httpStatus
                    ? 'HTTP ' + httpStatus + (preview ? ': ' + preview : '')
                    : (preview || 'No response — check server error log.');
                setBannerError('Migration stopped after ' + chunkFailCount + ' failures. ' + detail);
                setButtonState('idle');
                isRunning = false;
                return;
            }

            const retryIn = chunkFailCount * 4; // 4 s, 8 s
            setBannerInfo(
                'Chunk failed (HTTP ' + (httpStatus || '?') + ') — retrying in ' + retryIn + 's… (' + chunkFailCount + '/3)'
            );
            pollProgress(); // refresh table from DB so any completed rows stay visible
            setTimeout(runNextChunk, retryIn * 1000);
        });
    }

    /* ── Abort ───────────────────────────────────────────────────────────── */
    function abortMigration() {
        const runId = currentRunId || octoWoo.activeRunId;
        if (!runId && !isRunning) { return; }

        if (!confirm(octoWoo.i18n.confirmAbort)) { return; }

        isRunning = false; // stop chunk loop immediately

        $.post(octoWoo.ajaxUrl, {
            action: 'octowoo_abort_migration',
            nonce:  octoWoo.nonce,
            run_id: runId || currentRunId,
        })
        .done(function (res) {
            if (res.success) {
                stopPolling();
                currentRunId = '';
                currentMigrator = '';
                isPausedState = false;
                setButtonState('idle');
                $btnResume.prop('disabled', true);
                $('#ow-active-run-banner').hide();
                setBannerInfo(res.data.message);
            }
        });
    }

    function pauseMigration() {
        const runId = currentRunId || octoWoo.activeRunId;
        if (!runId) { return; }

        $.post(octoWoo.ajaxUrl, {
            action: 'octowoo_pause_migration',
            nonce:  octoWoo.nonce,
            run_id: runId,
        })
        .done(function (res) {
            if (!res.success) {
                setBannerError((res.data && res.data.message) ? res.data.message : 'Pause failed.');
                return;
            }
            isRunning = false;
            isPausedState = true;
            stopPolling();
            setButtonState('paused');
            setBannerInfo(res.data.message + ' Click Resume to continue.');
        });
    }

    function skipCurrentMigrator() {
        const runId = currentRunId || octoWoo.activeRunId;
        if (!runId) { return; }

        $.post(octoWoo.ajaxUrl, {
            action:   'octowoo_skip_migrator',
            nonce:    octoWoo.nonce,
            run_id:   runId,
            migrator: currentMigrator,
        })
        .done(function (res) {
            if (!res.success) {
                setBannerError((res.data && res.data.message) ? res.data.message : 'Skip failed.');
                return;
            }
            setBannerInfo(res.data.message);
        });
    }

    /* ── Reset ───────────────────────────────────────────────────────────── */
    function resetMigration() {
        if (isRunning) {
            alert('Abort the running migration before resetting.');
            return;
        }

        if (!confirm('This will delete all migration progress records. Continue?')) { return; }

        $.post(octoWoo.ajaxUrl, {
            action: 'octowoo_reset_migration',
            nonce:  octoWoo.nonce,
        })
        .done(function (res) {
            if (res.success) {
                $progressTable.find('tbody').empty();
                setBannerInfo(res.data.message);
                currentRunId = '';
                currentMigrator = '';
                isPausedState = false;
                setButtonState('idle');
                $btnResume.prop('disabled', true);
                $('#ow-btn-resume-bg').prop('disabled', true);
                $('#ow-active-run-banner').hide();
            } else {
                setBannerError(res.data.message);
            }
        });
    }

    /* ── Progress polling ────────────────────────────────────────────────── */
    function startPolling() {
        stopPolling();
        pollTimer = setInterval(pollProgress, 3000);
        pollProgress(); // immediate first tick
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function pollProgress() {
        $.get(octoWoo.ajaxUrl, {
            action: 'octowoo_get_progress',
            nonce:  octoWoo.nonce,
            run_id: currentRunId,
        })
        .done(function (res) {
            if (!res.success) { return; }

            const data = res.data;
            isPausedState = !!data.paused;
            renderProgressTable(data.checkpoints || []);

            if (isPausedState && !isRunning) {
                setButtonState('paused');
            }

            if (!data.active && isRunning && currentRunId && data.run_id === currentRunId) {
                // Migration finished (server-side).
                isRunning = false;
                isPausedState = false;
                stopPolling();
                setButtonState('idle');
                setBannerDone(null);
                refreshLogs();
            } else if (!data.active && !isRunning && currentRunId && data.run_id === currentRunId && !isPausedState) {
                setButtonState('idle');
                $('#ow-btn-cancel-bg').prop('disabled', true);
            }
        });
    }

    function hasNonTerminalCheckpoints(checkpoints) {
        if (!Array.isArray(checkpoints) || checkpoints.length === 0) {
            return false;
        }
        return checkpoints.some(function (cp) {
            const status = String(cp.status || '').toLowerCase();
            return status === 'pending' || status === 'running';
        });
    }

    /* ── Progress table render ───────────────────────────────────────────── */
    function renderProgressTable(checkpoints) {
        const $tbody = $progressTable.find('tbody');
        $tbody.empty();

        if (!checkpoints.length) {
            $tbody.append(
                $('<tr>').append($('<td colspan="5">').text('No migration data yet.').css('color','#888'))
            );
            return;
        }

        const runningCp = checkpoints.find(function (cp) { return cp.status === 'running'; })
            || checkpoints.find(function (cp) { return cp.status === 'pending'; });
        if (runningCp && runningCp.migrator) {
            currentMigrator = runningCp.migrator;
        }

        checkpoints.forEach(function (cp) {
            const processed = parseInt(cp.processed_count, 10) || 0;
            const total     = parseInt(cp.total_count, 10)     || 0;
            const safeProcessed = total > 0 ? Math.min(processed, total) : processed;
            const pctRaw = total > 0 ? Math.round(safeProcessed / total * 100) : (cp.status === 'completed' ? 100 : 0);
            const pct       = Math.max(0, Math.min(100, pctRaw));
            const isDone    = cp.status === 'completed';
            const isFailed  = cp.status === 'failed';

            const barClass = isFailed ? ' failed' : (isDone ? ' done' : '');
            const $bar = $('<div class="ow-progress-bar-wrap">').append(
                $('<div class="ow-progress-bar' + barClass + '">')
                    .css('width', (isFailed ? 100 : pct) + '%')
            );

            // Expand button — click to toggle a sub-row with recent logs for this migrator.
            const $expandBtn = $('<span class="ow-expand-btn" title="Show recent log entries">&#9658;</span>');

            const $tr = $('<tr>').append(
                $('<td>').append($expandBtn, ' ', ucFirst(cp.migrator)),
                $('<td>').append($('<span class="ow-badge ow-badge-' + cp.status + '">').text(cp.status)),
                $('<td>').text(safeProcessed.toLocaleString() + ' / ' + total.toLocaleString()),
                $('<td>').append($bar),
                $('<td>').text(pct + '%')
            );

            $expandBtn.on('click', function () {
                const $existing = $tr.next('.ow-log-detail-row');
                if ($existing.length) {
                    $expandBtn.html('&#9658;');
                    $existing.remove();
                    return;
                }
                $expandBtn.html('&#9660;'); // rotated arrow while open

                const runId = currentRunId || octoWoo.lastRunId;
                $.get(octoWoo.ajaxUrl, {
                    action:   'octowoo_get_logs',
                    nonce:    octoWoo.nonce,
                    run_id:   runId,
                    migrator: cp.migrator,
                    limit:    15,
                })
                .done(function (r) {
                    if (!r.success) { return; }
                    const entries = (r.data.logs || []).slice().reverse();
                    const lines = entries.map(function (e) {
                        return '[' + (e.level || 'INFO') + '] ' + (e.message || '');
                    }).join('\n') || '(no log entries)';

                    const $pre = $('<pre>').text(lines).css({
                        margin: '4px 0 4px 18px',
                        fontSize: '11px',
                        color: '#444',
                        whiteSpace: 'pre-wrap',
                        maxHeight: '180px',
                        overflowY: 'auto',
                    });
                    $('<tr class="ow-log-detail-row">').append(
                        $('<td colspan="5">').append($pre)
                    ).insertAfter($tr);
                });
            });

            $tbody.append($tr);
        });
    }

    /* ── Logs ────────────────────────────────────────────────────────────── */
    function refreshLogs() {
        const level = $('#ow-log-level-filter').val() || '';
        const runId = currentRunId || octoWoo.activeRunId;

        $.get(octoWoo.ajaxUrl, {
            action: 'octowoo_get_logs',
            nonce:  octoWoo.nonce,
            run_id: runId,
            level:  level,
            limit:  200,
        })
        .done(function (res) {
            if (!res.success) { return; }
            renderLogs(res.data.logs || []);
        });
    }

    function renderLogs(logs) {
        $logContainer.empty();

        if (!logs.length) {
            $logContainer.append($('<div>').text('No log entries.').css('color', '#6e6e6e'));
            return;
        }

        // Logs are returned newest-first; reverse for chronological display.
        logs.slice().reverse().forEach(function (entry) {
            const $row = $('<div class="ow-log-entry">').append(
                $('<span class="ow-log-ts">').text(entry.created_at || ''),
                $('<span class="ow-log-level ow-log-level-' + (entry.level||'INFO') + '">').text('[' + (entry.level||'INFO') + ']'),
                $('<span class="ow-log-migrator">').text(entry.migrator ? '[' + entry.migrator + ']' : ''),
                $('<span class="ow-log-msg">').text(entry.message || '')
            );
            $logContainer.append($row);
        });

        // Auto-scroll to bottom.
        $logContainer.scrollTop($logContainer[0].scrollHeight);
    }

    /* ── Test DB connection ──────────────────────────────────────────────── */
    function testConnection() {
        const $btn    = $('#ow-btn-test-conn');
        const $result = $('#ow-conn-result');

        $btn.prop('disabled', true).text('Testing…');
        $result.css('color', '#888').text('');

        // Read live form values so the test reflects what the user has typed,
        // even if settings haven't been saved yet.
        const $form = $('#octowoo-settings-form');
        $.post(octoWoo.ajaxUrl, {
            action:    'octowoo_test_connection',
            nonce:     octoWoo.nonce,
            db_host:   $form.find('[name="octowoo[db][host]"]').val(),
            db_port:   $form.find('[name="octowoo[db][port]"]').val(),
            db_name:   $form.find('[name="octowoo[db][database]"]').val(),
            db_user:   $form.find('[name="octowoo[db][username]"]').val(),
            db_pass:   $form.find('[name="octowoo[db][password]"]').val(),
            db_prefix: $form.find('[name="octowoo[db][prefix]"]').val(),
        })
        .done(function (res) {
            if (res.success) {
                $result.css('color', '#2e7d32').text(res.data.message);
            } else {
                $result.css('color', '#c62828').text(res.data.message || 'Connection failed.');
            }
        })
        .fail(function (xhr) {
            $result.css('color', '#c62828').text('Request failed: ' + xhr.statusText);
        })
        .always(function () {
            $btn.prop('disabled', false).text('🔌 Test Connection');
        });
    }

    /* ── Banner helpers ──────────────────────────────────────────────────── */
    function setBannerRunning() {
        $statusBanner
            .removeClass('ow-alert-success ow-alert-error ow-alert-info ow-alert-warning')
            .addClass('ow-alert ow-alert-info')
            .html('<span class="ow-spinner"></span>&nbsp; ' + octoWoo.i18n.running)
            .show();
    }

    function setBannerDone(report) {
        let msg = octoWoo.i18n.completed;
        if (report && report.migrators) {
            const totals = Object.values(report.migrators)
                .reduce(function (acc, m) {
                    acc.processed += (m.processed || 0);
                    acc.failed    += (m.failed    || 0);
                    return acc;
                }, { processed: 0, failed: 0 });
            msg += ' — ' + totals.processed.toLocaleString() + ' items processed, ' + totals.failed + ' failed.';
        }
        msg += ' See progress table below for details.';
        // Clear the PHP-rendered "in progress" warning — it's now stale.
        $('#ow-active-run-banner').hide();
        $btnResume.prop('disabled', true);
        $statusBanner
            .removeClass('ow-alert-info ow-alert-error ow-alert-warning')
            .addClass('ow-alert ow-alert-success')
            .text(msg)
            .show();
    }

    function setBannerError(msg) {
        $statusBanner
            .removeClass('ow-alert-info ow-alert-success ow-alert-warning')
            .addClass('ow-alert ow-alert-error')
            .text('Error: ' + msg)
            .show();
    }

    function setBannerInfo(msg) {
        $statusBanner
            .removeClass('ow-alert-error ow-alert-success ow-alert-warning')
            .addClass('ow-alert ow-alert-info')
            .text(msg)
            .show();
    }

    /* ── Button state helpers ────────────────────────────────────────────── */
    function setButtonState(state) {
        const $btnDemo     = $('#ow-btn-demo');
        const $btnRecovery = $('#ow-btn-images-only, #ow-btn-products-images, #ow-btn-cats-manufacturers, #ow-btn-multilingual, #ow-btn-cleanup-ml-terms, #ow-btn-repair-order-items');
        const $btnBgStart  = $('#ow-btn-start-bg, #ow-btn-resume-bg');

        if (state === 'running') {
            $btnStart.prop('disabled', true)
                .html('<span class="ow-spinner"></span>&nbsp; Running…');
            $btnDemo.prop('disabled', true);
            $btnRecovery.prop('disabled', true);
            $btnResume.prop('disabled', true);
            $btnAbort.prop('disabled', false);
            $btnPause.prop('disabled', false);
            $btnSkip.prop('disabled', false);
            // Prevent user from launching a second (background) run in parallel,
            // which caused duplicate categories/products in the past.
            $btnBgStart.prop('disabled', true);
        } else if (state === 'paused') {
            $btnStart.prop('disabled', true).text('▶ Start Full Migration');
            $btnDemo.prop('disabled', true);
            $btnRecovery.prop('disabled', true);
            $btnResume.prop('disabled', false);
            $btnAbort.prop('disabled', false);
            $btnPause.prop('disabled', true);
            $btnSkip.prop('disabled', false);
            // Allow resuming via background mode when paused.
            $('#ow-btn-start-bg').prop('disabled', true);
            $('#ow-btn-resume-bg').prop('disabled', false);
        } else {
            $btnStart.prop('disabled', false).text('▶ Start Full Migration');
            $btnDemo.prop('disabled', false);
            $btnRecovery.prop('disabled', false);
            $btnResume.prop('disabled', false);
            $btnAbort.prop('disabled', true);
            $btnPause.prop('disabled', true);
            $btnSkip.prop('disabled', true);
            // Re-enable background start; disable background resume (nothing to resume in idle).
            $('#ow-btn-start-bg').prop('disabled', false);
            $('#ow-btn-resume-bg').prop('disabled', true);
        }
    }

    /* ── Settings validation ─────────────────────────────────────────────── */
    function validateSettingsForm() {
        // Simple: highlight empty required fields.
        let valid = true;
        $('#octowoo-settings-form [required]').each(function () {
            const empty = !$(this).val().trim();
            $(this).toggleClass('ow-field-error', empty);
            if (empty) { valid = false; }
        });
        $('#ow-btn-save-settings').prop('disabled', !valid);
    }

    /* ── Utility ─────────────────────────────────────────────────────────── */
    function ucFirst(str) {
        return str.charAt(0).toUpperCase() + str.slice(1);
    }

    /* ── Boot ────────────────────────────────────────────────────────────── */
    $(document).ready(init);

}(jQuery));

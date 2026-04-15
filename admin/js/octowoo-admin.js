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

    /* ── DOM refs (populated in init) ────────────────────────────────────── */
    let $progressTable, $logContainer, $statusBanner, $btnStart, $btnResume, $btnAbort, $btnReset;

    /* ── Init ────────────────────────────────────────────────────────────── */
    function init() {
        // Cache DOM refs.
        $progressTable = $('#ow-progress-table');
        $logContainer  = $('#ow-log-container');
        $statusBanner  = $('#ow-status-banner');
        $btnStart      = $('#ow-btn-start');
        $btnResume     = $('#ow-btn-resume');
        $btnAbort      = $('#ow-btn-abort');
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
        $btnStart.on('click', function () { startMigration(false); });
        $btnResume.on('click', function () { startMigration(true); });
        $btnAbort.on('click', abortMigration);
        $btnReset.on('click', resetMigration);

        $('#ow-sel-all').on('click',  function (e) { e.preventDefault(); $('.ow-migrator-chk').prop('checked', true); });
        $('#ow-sel-none').on('click', function (e) { e.preventDefault(); $('.ow-migrator-chk').prop('checked', false); });

        $('#ow-btn-test-conn').on('click', testConnection);

        $('#ow-btn-scan').on('click', scanSourceCounts);

        $('#ow-btn-scan').on('click', scanSourceCounts);

        // Log controls.
        $('#ow-log-level-filter').on('change', function () { refreshLogs(); });
        $('#ow-btn-refresh-logs').on('click', function () { refreshLogs(); });
        $('#ow-btn-clear-logs').on('click', function () { $logContainer.empty(); });

        // If there's a run (active or last), show its progress immediately.
        if (currentRunId) {
            pollProgress();
        }

        // If there's an active run in the DB (interrupted), enable Abort so
        // the user can clear the lock without having to Resume first.
        if (octoWoo.activeRunId) {
            $btnAbort.prop('disabled', false);
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
                    $('#ow-sql-status').text('Done');
                } else {
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

        // ── Purge imported data ───────────────────────────────────────────
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
                if ( typed === null || typed.trim().toUpperCase() !== 'FORCE' ) {
                    return;
                }
            } else {
                if ( ! confirm( confirmMsg ) ) {
                    return;
                }
            }
            const $btn = $('#ow-btn-purge');
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
                    // Build per-entity breakdown string.
                    const breakdown = Object.entries(res.data.results || {})
                        .map(([k, v]) => k + ': ' + v)
                        .join(', ');
                    $result.text('✔ ' + res.data.message + (breakdown ? ' (' + breakdown + ')' : '')).css('color', '#2e7d32');
                    // Uncheck boxes after success.
                    $('.ow-purge-chk').prop('checked', false);
                } else {
                    $result.text('✘ ' + (res.data.message || 'Purge failed.')).css('color', '#c62828');
                }
            })
            .fail(function (xhr) {
                $result.text('✘ ' + xhr.statusText).css('color', '#c62828');
            })
            .always(function () {
                $btn.prop('disabled', false).text('🗑 Purge Selected');
            });
        });
    }

    /* ── Source database scan ─────────────────────────────────────────── */
    function scanSourceCounts() {
        var $btn    = $('#ow-btn-scan');
        var $result = $('#ow-scan-result');
        $btn.prop('disabled', true).text('Scanning…');
        $result.html('<span style="color:#888;">Connecting to source database…</span>');

        $.post(octoWoo.ajaxUrl, {
            action: 'octowoo_scan_counts',
            nonce:  octoWoo.nonce,
        })
        .done(function (res) {
            if (res.success) {
                var counts = res.data.counts;
                var labels = {
                    products:       'Products',
                    categories:     'Categories',
                    manufacturers:  'Manufacturers / Brands',
                    customers:      'Customers',
                    orders:         'Orders',
                    reviews:        'Reviews',
                    tax_classes:    'Tax Classes',
                    coupons:        'Coupons',
                    languages:      'Languages',
                    information:    'Information Pages',
                    order_statuses: 'Order Statuses',
                    product_images: 'Product Images',
                    tags:           'Tags',
                    filter_groups:  'Filter Groups',
                    downloads:      'Downloads',
                };
                var html = '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px 24px;font-size:13px;margin-top:6px;">';
                $.each(labels, function (key, label) {
                    var val = counts[key];
                    if (val === undefined || val === -1) {
                        html += '<div style="color:#bbb;">― ' + label + '</div>';
                    } else {
                        html += '<div><strong style="font-size:15px;color:#1d2327;">' +
                                parseInt(val, 10).toLocaleString() +
                                '</strong> <span style="color:#555;">' + label + '</span></div>';
                    }
                });
                html += '</div>';
                $result.html(html);
            } else {
                $result.html('<span style="color:#c62828;">✘ ' +
                    ((res.data && res.data.message) ? res.data.message : 'Scan failed.') + '</span>');
            }
        })
        .fail(function (xhr) {
            $result.html('<span style="color:#c62828;">✘ ' + xhr.statusText + '</span>');
        })
        .always(function () {
            $btn.prop('disabled', false).text('🔍 Scan Database');
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

        // Update URL without reload.
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.replaceState({}, '', url.toString());
    }

    /* ── Migration start / resume ────────────────────────────────────────── */
    let chunkMigrators = '';
    let chunkDryRun    = 0;
    let chunkDemoLimit = 0;
    let chunkFailCount = 0;

    // Toggle demo limit number input when checkbox changes.
    $(document).on('change', '#ow-demo-mode', function () {
        $('#ow-demo-limit').toggle($(this).is(':checked'));
    });

    function startMigration(resume) {
        if (isRunning) { return; }

        const dryRun   = $('#ow-dry-run').is(':checked') ? 1 : 0;
        const demoMode = $('#ow-demo-mode').is(':checked');
        const demoLimitVal = demoMode ? (parseInt($('#ow-demo-limit').val(), 10) || 10) : 0;

        if (!resume && !dryRun) {
            const confirmMsg = demoMode
                ? octoWoo.i18n.starting + ' (Demo: first ' + demoLimitVal + ' items per migrator)\n\nProceed?'
                : octoWoo.i18n.starting + '\n\nProceed?';
            if (!confirm(confirmMsg)) { return; }
        }

        // Collect selected migrators.
        const migrators = [];
        $('.ow-migrator-chk:checked').each(function () { migrators.push($(this).val()); });
        chunkMigrators = migrators.join(',');
        chunkDryRun    = dryRun;
        chunkDemoLimit = demoLimitVal;

        setButtonState('running');
        setBannerRunning();

        // For resume, keep the existing currentRunId; for a fresh start, clear it
        // so the server assigns a new one on the first chunk.
        if (!resume) {
            currentRunId = '';
        }
        chunkFailCount = 0;
        isRunning = true;
        runNextChunk();
    }

    function runNextChunk() {
        if (!isRunning) { return; }

        $.post(octoWoo.ajaxUrl, {
            action:      'octowoo_run_chunk',
            nonce:       octoWoo.nonce,
            run_id:      currentRunId,
            resume:      currentRunId ? 1 : 0,
            dry_run:     chunkDryRun,
            demo_limit:  chunkDemoLimit,
            migrators:   chunkMigrators,
        })
        .done(function (res) {
            if (!res.success) {
                setBannerError(res.data.message || 'Chunk error.');
                setButtonState('idle');
                isRunning = false;
                return;
            }

            chunkFailCount = 0; // reset on any successful HTTP response
            const d = res.data;
            currentRunId = d.run_id || currentRunId;
            renderProgressTable(d.checkpoints || []);

            if (d.done_all || d.aborted) {
                isRunning = false;
                stopPolling();
                setButtonState('idle');
                $btnResume.prop('disabled', true);
                $('#ow-active-run-banner').hide();
                if (d.aborted) {
                    setBannerInfo(octoWoo.i18n.aborted);
                } else {
                    setBannerDone(d.report);
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
            const preview    = (xhr.responseText || '').replace(/<[^>]*>/g, ' ').trim().substring(0, 300);

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
                setButtonState('idle');
                $btnResume.prop('disabled', true);
                $('#ow-active-run-banner').hide();
                setBannerInfo(res.data.message);
            }
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
            renderProgressTable(data.checkpoints || []);

            if (!data.active && isRunning) {
                // Migration finished (server-side).
                isRunning = false;
                stopPolling();
                setButtonState('idle');
                setBannerDone(null);
                refreshLogs();
            }
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

        checkpoints.forEach(function (cp) {
            const processed = parseInt(cp.processed_count, 10) || 0;
            const total     = parseInt(cp.total_count, 10)     || 0;
            const pct       = total > 0 ? Math.round(processed / total * 100) : (cp.status === 'completed' ? 100 : 0);
            const isDone    = cp.status === 'completed';

            const $bar = $('<div class="ow-progress-bar-wrap">').append(
                $('<div class="ow-progress-bar' + (isDone ? ' done' : '') + '">')
                    .css('width', pct + '%')
            );

            // Expand button — click to toggle a sub-row with recent logs for this migrator.
            const $expandBtn = $('<span class="ow-expand-btn" title="Show recent log entries">&#9658;</span>');

            const $tr = $('<tr>').append(
                $('<td>').append($expandBtn, ' ', ucFirst(cp.migrator)),
                $('<td>').append($('<span class="ow-badge ow-badge-' + cp.status + '">').text(cp.status)),
                $('<td>').text(processed.toLocaleString() + ' / ' + total.toLocaleString()),
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
        if (state === 'running') {
            $btnStart.prop('disabled', true)
                .html('<span class="ow-spinner"></span>&nbsp; Running…');
            $btnResume.prop('disabled', true);
            $btnAbort.prop('disabled', false);
        } else {
            $btnStart.prop('disabled', false).text('▶ Start Migration');
            $btnResume.prop('disabled', false);
            $btnAbort.prop('disabled', true);
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

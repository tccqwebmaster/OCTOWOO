/**
 * OctoWoo – Admin Dashboard JavaScript  v2.4.70
 *
 * Fixes in this version:
 *  - Replaced ALL window.alert() / window.confirm() with in-page toast notifications
 *  - Added real-time ETA ("~X min remaining") in the progress table
 *  - Added "Download Logs" button (Blob download, no page reload)
 *  - Added "Export Settings" / "Import Settings" buttons
 *  - Fixed polling race condition (poll starts only AFTER first chunk sets run_id)
 *  - Fixed JS crash when repair-order-items response has no data.done
 */
/* global octoWoo, jQuery */
(function ($) {
    'use strict';

    /* ── State ───────────────────────────────────────────────────────────── */
    let pollTimer        = null;
    let currentRunId     = octoWoo.activeRunId || octoWoo.lastRunId || '';
    let isRunning        = false;
    let isPausedState    = false;
    let currentMigrator  = '';
    let migrationEpoch   = 0;
    let chunkStartTimes  = {};   // migrator => Date.now() when it started
    let chunkItemRates   = {};   // migrator => items/sec rolling average

    /* ── chunk params ────────────────────────────────────────────────────── */
    let chunkMigrators          = '';
    let chunkDryRun             = 0;
    let chunkDemoLimit          = 0;
    let chunkFailCount          = 0;
    let chunkOnDuplicate        = 'skip';
    let chunkClearOrdersPending = false;

    /* ── DOM refs ────────────────────────────────────────────────────────── */
    let $progressTable, $logContainer, $statusBanner, $btnStart, $btnResume,
        $btnAbort, $btnPause, $btnSkip, $btnReset;

    /* ════════════════════════════════════════════════════════════════════
       TOAST NOTIFICATION SYSTEM
       Replaces all window.alert() / window.confirm() calls.
    ════════════════════════════════════════════════════════════════════ */

    /**
     * Show a slide-in toast notification.
     * @param {string} msg    Message text
     * @param {string} type   'success' | 'error' | 'warning' | 'info'
     * @param {number} ms     Auto-dismiss delay (0 = stay until clicked)
     */
    function showToast(msg, type, ms) {
        type = type || 'info';
        ms   = (ms === undefined) ? 4000 : ms;

        var colors = {
            success: { bg: '#edf7ed', border: '#4caf50', text: '#1b5e20', icon: '✔' },
            error:   { bg: '#fef2f2', border: '#ef4444', text: '#7f1d1d', icon: '✘' },
            warning: { bg: '#fffbeb', border: '#f59e0b', text: '#78350f', icon: '⚠' },
            info:    { bg: '#e8f4fd', border: '#2196f3', text: '#0d3349', icon: 'ℹ' },
        };
        var c = colors[type] || colors.info;

        // Ensure container exists.
        if (!$('#ow-toast-container').length) {
            $('body').append(
                '<div id="ow-toast-container" style="position:fixed;top:40px;right:20px;z-index:99999;display:flex;flex-direction:column;gap:8px;max-width:380px;"></div>'
            );
        }

        var $toast = $('<div>')
            .css({
                background:    c.bg,
                border:        '1px solid ' + c.border,
                color:         c.text,
                borderRadius:  '6px',
                padding:       '10px 36px 10px 14px',
                fontSize:      '13px',
                lineHeight:    '1.5',
                boxShadow:     '0 4px 12px rgba(0,0,0,.15)',
                position:      'relative',
                cursor:        'pointer',
                wordBreak:     'break-word',
                opacity:       0,
                transform:     'translateX(20px)',
                transition:    'opacity .2s,transform .2s',
            })
            .html('<strong style="margin-right:6px;">' + c.icon + '</strong>' + $('<span>').text(msg).html())
            .append(
                $('<span>').text('×').css({
                    position: 'absolute', top: '8px', right: '10px',
                    fontSize: '16px', fontWeight: 'bold', opacity: '.6', cursor: 'pointer',
                }).on('click', function () { dismiss($toast); })
            )
            .on('click', function () { dismiss($toast); });

        $('#ow-toast-container').append($toast);

        // Animate in.
        setTimeout(function () {
            $toast.css({ opacity: 1, transform: 'translateX(0)' });
        }, 10);

        if (ms > 0) {
            setTimeout(function () { dismiss($toast); }, ms);
        }

        function dismiss($t) {
            $t.css({ opacity: 0, transform: 'translateX(20px)' });
            setTimeout(function () { $t.remove(); }, 220);
        }

        return $toast;
    }

    /**
     * In-page confirm dialog (async, returns Promise<bool>).
     * Replaces window.confirm() throughout the codebase.
     */
    function owConfirm(msg, okLabel, cancelLabel) {
        okLabel     = okLabel     || 'OK';
        cancelLabel = cancelLabel || 'Cancel';
        return new Promise(function (resolve) {
            var $overlay = $('<div>').css({
                position: 'fixed', inset: 0, background: 'rgba(0,0,0,.45)',
                zIndex: 99998, display: 'flex', alignItems: 'center', justifyContent: 'center',
            });
            var $box = $('<div>').css({
                background: '#fff', borderRadius: '8px', padding: '24px 28px',
                maxWidth: '420px', width: '90%', boxShadow: '0 8px 32px rgba(0,0,0,.2)',
                fontFamily: 'inherit', fontSize: '14px', lineHeight: '1.6',
            });
            $box.append(
                $('<p>').css({ margin: '0 0 20px', color: '#1d2327' }).text(msg),
                $('<div>').css({ display: 'flex', gap: '10px', justifyContent: 'flex-end' }).append(
                    $('<button>').addClass('button').text(cancelLabel).on('click', function () {
                        $overlay.remove();
                        resolve(false);
                    }),
                    $('<button>').addClass('button button-primary').text(okLabel).on('click', function () {
                        $overlay.remove();
                        resolve(true);
                    })
                )
            );
            $overlay.append($box);
            $('body').append($overlay);
        });
    }

    /* ════════════════════════════════════════════════════════════════════
       INIT
    ════════════════════════════════════════════════════════════════════ */
    function init() {
        $progressTable = $('#ow-progress-table');
        $logContainer  = $('#ow-log-container');
        $statusBanner  = $('#ow-status-banner');
        $btnStart      = $('#ow-btn-start');
        $btnResume     = $('#ow-btn-resume');
        $btnAbort      = $('#ow-btn-abort');
        $btnPause      = $('#ow-btn-pause');
        $btnSkip       = $('#ow-btn-skip');
        $btnReset      = $('#ow-btn-reset');

        // Tab navigation.
        $(document).on('click', '.ow-tab-btn', function (e) {
            e.preventDefault();
            switchTab($(this).data('tab'));
        });

        var urlParams = new URLSearchParams(window.location.search);
        switchTab(urlParams.get('tab') || 'migration');

        // ── Migration buttons ──────────────────────────────────────────────
        $btnStart.on('click',  function () { startMigration(false, false); });
        $btnResume.on('click', function () { startMigration(true, false); });

        $btnAbort.on('click', abortMigration);
        $btnPause.on('click', pauseMigration);
        $btnSkip.on('click',  skipCurrentMigrator);
        $btnReset.on('click', resetMigration);

        $('#ow-btn-demo').on('click', function () { startMigration(false, true); });

        // Recovery buttons.
        $('#ow-btn-images-only').on('click', startImagesOnlyRecovery);
        $('#ow-btn-products-images').on('click', startProductsImagesRecovery);
        $('#ow-btn-cats-manufacturers').on('click', startCategoriesManufacturersRecovery);
        $('#ow-btn-multilingual').on('click', startMultilingualRecovery);
        $('#ow-btn-cleanup-ml-terms').on('click', cleanupMlTerms);
        $('#ow-btn-rerun-seo').on('click', rerunSeoMigrator);
        $('#ow-btn-repair-order-items').on('click', repairOrderItems);
        $('#ow-btn-repair-categories').on('click', repairCategories);

        // Connection test.
        $('#ow-btn-detect-image-path').on('click', detectImagePath);
        $('#ow-btn-detect-languages').on('click', detectLanguages);
        $(document).on('click', '.ow-use-img-path', function () {
            $('#ow-image-path-input').val($(this).data('path'));
            showToast('Image path set: ' + $(this).data('path'), 'success');
        });
        // Event delegation for dynamically-created language set buttons.
        $(document).on('click', '.ow-set-lang-btn', function () {
            owSetLang($(this).data('type'), $(this).data('id'), $(this).data('name'));
        });
        $('#ow-btn-test-connection').on('click', testConnection);

        // Prescan.
        $('#ow-btn-auto-detect').on('click', autoDetect);
        $('#ow-btn-scan').on('click', scanSourceCounts);
        $('#ow-btn-validate').on('click', runSystemCheck);

        // Background mode.
        $('#ow-btn-start-bg').on('click', function () { startBackgroundMigration(false); });
        $('#ow-btn-resume-bg').on('click', function () { startBackgroundMigration(true); });
        $('#ow-btn-cancel-bg').on('click', cancelBackgroundMigration);

        // Log controls.
        $('#ow-log-level-filter').on('change', function () { refreshLogs(); });
        $('#ow-log-migrator-filter').on('change', function () { refreshLogs(); });
        // Debounced search — fires 300ms after user stops typing.
        var logSearchTimer;
        $('#ow-log-search').on('input', function () {
            clearTimeout(logSearchTimer);
            logSearchTimer = setTimeout(function () { refreshLogs(); }, 300);
        });
        $('#ow-btn-refresh-logs').on('click', function () { refreshLogs(); });
        $('#ow-btn-clear-logs').on('click', function () { $logContainer.empty(); $('#ow-log-stats').css('border-color','#2a2d3a'); });
        $('#ow-btn-download-logs').on('click', downloadLogs);

        // Settings export / import.
        $('#ow-btn-export-settings').on('click', exportSettings);
        $('#ow-btn-import-settings-trigger').on('click', function () {
            $('#ow-import-settings-file').trigger('click');
        });
        $('#ow-import-settings-file').on('change', function () {
            var file = this.files[0];
            if (file) { importSettings(file); }
        });

        // SQL upload.
        $('#ow-btn-import-sql').on('click', importSql);
        $(document).on('click', '#ow-btn-drop-sql', dropSql);
        $('#ow-btn-import-images').on('click', importImages);

        // Purge.
        // Step 2 options: Select All / Deselect All
        $('#ow-opt-select-all').on('click',   function () { $('.ow-step2-opt').prop('checked', true); });
        $('#ow-opt-deselect-all').on('click', function () { $('.ow-step2-opt').prop('checked', false); });

        // Purge section: Select All / Deselect All
        $('#ow-btn-select-all-purge').on('click',   function () { $('.ow-purge-chk').prop('checked', true); });
        $('#ow-btn-deselect-all-purge').on('click', function () { $('.ow-purge-chk').prop('checked', false); });
        $('#ow-btn-audit-purge').on('click', auditPurge);
        $('#ow-btn-purge').on('click',              runPurge);
        $('#ow-btn-purge-force').on('click',        function () { runPurge(true); });
        $('#ow-btn-purge-everything').on('click',   runPurgeEverything);

        // Source mode toggle.
        $('input[name="octowoo[source]"]').on('change', function () {
            var isLocal = $(this).val() === 'local';
            $('#ow-local-import-area').toggle(isLocal);
            $('#ow-remote-db-card').css({ opacity: isLocal ? 0.5 : 1, pointerEvents: isLocal ? 'none' : '' });
            $('.ow-source-btn').removeClass('active');
            $(this).closest('.ow-source-btn').addClass('active');
            $('#ow-image-source-input').val(isLocal ? 'local' : 'remote');
        });
        $('input[name="octowoo[source]"]:checked').trigger('change');

        // Settings live validation.
        $('#octowoo-settings-form').on('input change', 'input, select', validateSettingsForm);

        // Resume active run on page load — check server state to set correct button state.
        if (currentRunId) { pollProgress(); }
        if (octoWoo.activeRunId) {
            startPolling();
            // Check if run is paused on server (persisted across page reload).
            $.get(octoWoo.ajaxUrl, { action: 'octowoo_get_progress', nonce: octoWoo.nonce, run_id: octoWoo.activeRunId })
            .done(function (res) {
                if (res && res.success && res.data) {
                    isPausedState = !!res.data.paused;
                    if (isPausedState) {
                        setButtonState('paused');
                        setBannerInfo('Migration paused. Click Resume to continue.');
                    } else {
                        setButtonState('running');
                        $btnAbort.prop('disabled', false);
                        $btnPause.prop('disabled', false);
                        $btnSkip.prop('disabled', false);
                    }
                } else {
                    setButtonState('running');
                    $btnAbort.prop('disabled', false);
                    $btnPause.prop('disabled', false);
                    $btnSkip.prop('disabled', false);
                }
            })
            .fail(function () {
                setButtonState('running');
                $btnAbort.prop('disabled', false);
            });
        }
    }

    /* ════════════════════════════════════════════════════════════════════
       TAB SWITCHING
    ════════════════════════════════════════════════════════════════════ */
    function switchTab(tab) {
        $('.ow-tab-pane').hide();
        $('.ow-tab-btn').removeClass('active');
        $('#ow-tab-' + tab).show();
        $('.ow-tab-btn[data-tab="' + tab + '"]').addClass('active');
        // Always hide wizard overlay when switching tabs (failsafe).
        var wz = document.getElementById('ow-wizard-overlay');
        if (wz) { wz.style.display = 'none'; }

        if (tab === 'logs' && currentRunId) { refreshLogs(); }

        var url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.replaceState({}, '', url.toString());
    }

    /* ════════════════════════════════════════════════════════════════════
       MIGRATION: START / RESUME / ABORT / PAUSE / SKIP / RESET
    ════════════════════════════════════════════════════════════════════ */
    function buildMigrators() {
        var ENTITY_MAP = {
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
        var list = [];
        function add(m) { if (list.indexOf(m) === -1) list.push(m); }

        $('.ow-entity-chk:checked').each(function () {
            var key = $(this).val();
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

        if (resume && (isPausedState || currentRunId || octoWoo.lastRunId)) {
            var resumeRunId = currentRunId || octoWoo.activeRunId || octoWoo.lastRunId || '';
            $.post(octoWoo.ajaxUrl, {
                action: 'octowoo_resume_migration',
                nonce:  octoWoo.nonce,
                run_id: resumeRunId,
            }).always(function (res) {
                if (res && res.success && res.data && res.data.run_id) {
                    currentRunId = res.data.run_id;
                } else if (resumeRunId) {
                    currentRunId = resumeRunId;
                }
                _doStartMigration(true, isDemo, forcedMigrators, customModeLabel, noClearOrders);
            });
            return;
        }

        _doStartMigration(resume, isDemo, forcedMigrators, customModeLabel, noClearOrders);
    }

    function _doStartMigration(resume, isDemo, forcedMigrators, customModeLabel, noClearOrders) {
        chunkMigrators    = forcedMigrators || buildMigrators();
        chunkDryRun       = $('#ow-opt-dry-run').is(':checked') ? 1 : 0;
        chunkDemoLimit    = isDemo ? (parseInt(octoWoo.demoLimit, 10) || 20) : 0;
        chunkOnDuplicate  = $('#ow-opt-on-duplicate').val() || 'skip';
        chunkFailCount    = 0;
        chunkClearOrdersPending = !noClearOrders && !resume && (chunkMigrators.indexOf('orders') !== -1);

        if (!resume) { currentRunId = ''; }
        isRunning = true;
        migrationEpoch++;
        chunkStartTimes = {};
        chunkItemRates  = {};

        var modeLabel = customModeLabel || (isDemo ? 'Demo (20 items)' : 'Full');
        setBannerRunning(modeLabel);
        setButtonState('running');
        runNextChunk();
    }

    function runNextChunk() {
        if (!isRunning) { return; }

        var shouldClearOrders = chunkClearOrdersPending ? 1 : 0;
        chunkClearOrdersPending = false;
        var dispatchEpoch = migrationEpoch;

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
            if (dispatchEpoch !== migrationEpoch) { return; }

            if (!res.success) {
                var msg = (res.data && res.data.message) ? res.data.message : 'Chunk error.';
                if (res.data && res.data.db_error) {
                    msg = '🔌 Database error: ' + msg + ' — Go to Settings → Database Connection.';
                }
                chunkFailCount++;
                if (chunkFailCount >= 3) {
                    isRunning = false;
                    setButtonState('idle');
                    setBannerError(msg);
                    stopPolling();
                    return;
                }
                showToast('Chunk failed (' + chunkFailCount + '/3): ' + msg, 'warning', 5000);
                setTimeout(runNextChunk, 2000);
                return;
            }

            chunkFailCount = 0;
            var data = res.data;

            if (data.run_id && !currentRunId) {
                currentRunId = data.run_id;
                // Start polling only NOW we have a valid run_id.
                startPolling();
            }

            if (data.migrator) { currentMigrator = data.migrator; }
            if (data.checkpoints) {
                renderProgressTable(data.checkpoints);
                updateETA(data.checkpoints, data.migrator, data.chunk);
            }

            if (data.done_all) {
                isRunning = false;
                isPausedState = false;
                stopPolling();
                setButtonState('idle');
                setBannerDone(data.report || null);
                if (data.report) { renderReport(data.report); }
                refreshLogs();
                return;
            }

            if (data.aborted) {
                isRunning = false;
                isPausedState = false;
                stopPolling();
                setButtonState('idle');
                setBannerInfo('Migration aborted.');
                refreshLogs();
                return;
            }

            if (data.paused) {
                isRunning = false;
                isPausedState = true;
                stopPolling();
                setButtonState('paused');
                setBannerInfo('Migration paused. Click Resume to continue.');
                return;
            }

            // Continue to next chunk.
            setTimeout(runNextChunk, 50);
        })
        .fail(function (xhr) {
            if (dispatchEpoch !== migrationEpoch) { return; }
            chunkFailCount++;
            var statusMsg = xhr.status ? '(HTTP ' + xhr.status + ')' : '';
            if (chunkFailCount >= 3) {
                isRunning = false;
                setButtonState('idle');
                setBannerError('Migration stopped after 3 consecutive HTTP failures ' + statusMsg + '. Check server error logs.');
                stopPolling();
                return;
            }
            showToast('Request failed ' + statusMsg + ' — retrying (' + chunkFailCount + '/3)…', 'warning', 4000);
            setTimeout(runNextChunk, 3000);
        });
    }

    function startImagesOnlyRecovery()           { startMigration(false, false, 'images,categories,manufacturers',  'Images-Only Recovery',          true); }
    function startProductsImagesRecovery()       { startMigration(false, false, 'products,images,related',          'Products + Images Recovery',    true); }
    function startCategoriesManufacturersRecovery() { startMigration(false, false, 'categories,manufacturers',      'Categories + Manufacturers',    true); }
    function startMultilingualRecovery()         { startMigration(false, false, 'multilingual',                     'Multilingual-only Recovery',    true); }

    /* ── Abort ───────────────────────────────────────────────────────────── */
    function abortMigration() {
        var runId = currentRunId || octoWoo.activeRunId;
        if (!runId) { showToast('No active migration to abort.', 'warning'); return; }

        $.post(octoWoo.ajaxUrl, { action: 'octowoo_abort_migration', nonce: octoWoo.nonce, run_id: runId })
        .done(function (res) {
            isRunning     = false;
            isPausedState = false;
            stopPolling();
            setButtonState('idle');
            if (res.success) {
                setBannerInfo('Migration aborted.');
                showToast('Migration aborted.', 'warning');
            } else {
                setBannerError(res.data ? res.data.message : 'Abort failed.');
            }
        });
    }

    /* ── Pause ───────────────────────────────────────────────────────────── */
    function pauseMigration() {
        var runId = currentRunId || octoWoo.activeRunId;
        if (!runId) { return; }
        $.post(octoWoo.ajaxUrl, { action: 'octowoo_pause_migration', nonce: octoWoo.nonce, run_id: runId });
    }

    /* ── Skip current migrator ───────────────────────────────────────────── */
    function skipCurrentMigrator() {
        var runId = currentRunId || octoWoo.activeRunId;
        if (!runId) { return; }
        $.post(octoWoo.ajaxUrl, {
            action:   'octowoo_skip_migrator',
            nonce:    octoWoo.nonce,
            run_id:   runId,
            migrator: currentMigrator,
        }).done(function (res) {
            if (!res.success) {
                setBannerError(res.data ? res.data.message : 'Skip failed.');
                return;
            }
            showToast(res.data.message || 'Skipped.', 'info');
        });
    }

    /* ── Reset ───────────────────────────────────────────────────────────── */
    function resetMigration() {
        if (isRunning) { showToast('Abort the running migration before resetting.', 'warning'); return; }

        owConfirm('This will delete ALL migration progress records and the ID map. Are you sure?', 'Yes, reset', 'Cancel')
        .then(function (confirmed) {
            if (!confirmed) { return; }
            $.post(octoWoo.ajaxUrl, { action: 'octowoo_reset_migration', nonce: octoWoo.nonce })
            .done(function (res) {
                if (res.success) {
                    $progressTable.find('tbody').html('<tr><td colspan="5" style="color:#888;">Start a migration to see progress.</td></tr>');
                    currentRunId    = '';
                    currentMigrator = '';
                    isPausedState   = false;
                    chunkStartTimes = {};
                    chunkItemRates  = {};
                    setButtonState('idle');
                    $btnResume.prop('disabled', true);
                    $('#ow-btn-resume-bg').prop('disabled', true);
                    $('#ow-active-run-banner').hide();
                    setBannerInfo(res.data.message || 'Migration reset.');
                    showToast('Progress reset. Ready for a fresh migration.', 'success');
                } else {
                    setBannerError(res.data ? res.data.message : 'Reset failed.');
                }
            });
        });
    }

    /* ════════════════════════════════════════════════════════════════════
       BACKGROUND MODE (Action Scheduler)
    ════════════════════════════════════════════════════════════════════ */
    function startBackgroundMigration(resume) {
        if (isRunning) { return; }

        var confirmMsg = resume
            ? 'Resume migration in Background mode? WooCommerce Action Scheduler will continue the run.'
            : 'Start migration in Background mode?\n\nBatches run in the background — you can close this tab. Check back for progress.';

        owConfirm(confirmMsg, resume ? 'Resume in background' : 'Start in background', 'Cancel')
        .then(function (confirmed) {
            if (!confirmed) { return; }

            var migrators = buildMigrators();
            var $btn = resume ? $('#ow-btn-resume-bg') : $('#ow-btn-start-bg');
            $btn.prop('disabled', true).html('<span class="ow-spinner dark"></span>&nbsp; Starting…');

            $.post(octoWoo.ajaxUrl, {
                action:       'octowoo_start_background',
                nonce:        octoWoo.nonce,
                resume:       resume ? 1 : 0,
                run_id:       resume ? (currentRunId || octoWoo.activeRunId || '') : '',
                migrators:    migrators,
                on_duplicate: $('#ow-opt-on-duplicate').val() || 'skip',
                dry_run:      $('#ow-opt-dry-run').is(':checked') ? 1 : 0,
                demo_limit:   0,
            })
            .done(function (res) {
                if (res.success) {
                    currentRunId = res.data.run_id || currentRunId;
                    var bgRunId = res.data.run_id || currentRunId;
                    showToast('Background migration started (Run: ' + (bgRunId || '').substr(0, 8) + '…). Progress updates every few seconds.', 'success', 6000);
                    setBannerInfo('⚙ Background migration running via Action Scheduler. You can close this tab — it will continue. Check back for progress.');
                    startPolling();
                    $('#ow-btn-cancel-bg').prop('disabled', false);
                } else {
                    var msg = res.data ? res.data.message : 'Could not start background migration.';
                    showToast(msg, 'error');
                }
            })
            .fail(function () {
                showToast('Background migration request failed. Check server logs.', 'error');
            })
            .always(function () {
                $btn.prop('disabled', false).html(resume ? '⚙ Resume in Background' : '⚙ Start in Background');
            });
        });
    }

    function cancelBackgroundMigration() {
        owConfirm('Cancel the background migration? Pending Action Scheduler jobs will be removed.', 'Cancel migration', 'Keep running')
        .then(function (confirmed) {
            if (!confirmed) { return; }
            $.post(octoWoo.ajaxUrl, { action: 'octowoo_cancel_background', nonce: octoWoo.nonce, run_id: currentRunId || '' })
            .done(function (res) {
                stopPolling();
                setButtonState('idle');
                if (res.success) {
                    showToast('Background migration cancelled.', 'warning');
                    setBannerInfo('Background migration cancelled.');
                } else {
                    showToast(res.data ? res.data.message : 'Cancel failed.', 'error');
                }
                $('#ow-btn-cancel-bg').prop('disabled', true);
            });
        });
    }

    /* ════════════════════════════════════════════════════════════════════
       PROGRESS POLLING
    ════════════════════════════════════════════════════════════════════ */
    function startPolling() {
        stopPolling();
        pollProgress();
        pollTimer = setInterval(pollProgress, 3000);
    }

    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    function pollProgress() {
        $.get(octoWoo.ajaxUrl, { action: 'octowoo_get_progress', nonce: octoWoo.nonce, run_id: currentRunId })
        .done(function (res) {
            if (!res.success) { return; }
            var data = res.data;
            isPausedState = !!data.paused;
            if (data.checkpoints) { renderProgressTable(data.checkpoints); }

            if (isPausedState && !isRunning) { setButtonState('paused'); }

            if (!data.active && !isRunning && currentRunId && data.run_id === currentRunId && !isPausedState) {
                setButtonState('idle');
                $('#ow-btn-cancel-bg').prop('disabled', true);
                stopPolling();
            }
        });
    }

    /* ════════════════════════════════════════════════════════════════════
       ETA CALCULATION
    ════════════════════════════════════════════════════════════════════ */
    function updateETA(checkpoints, activeMigrator, chunk) {
        if (!activeMigrator || !chunk) { return; }

        var processed = chunk.processed || 0;
        var total     = chunk.total     || 0;
        var remaining = total - processed;

        if (!chunkStartTimes[activeMigrator]) {
            chunkStartTimes[activeMigrator] = Date.now();
            chunkItemRates[activeMigrator]  = { count: 0, startCount: processed };
        }

        var elapsed   = (Date.now() - chunkStartTimes[activeMigrator]) / 1000; // seconds
        var itemsDone = processed - (chunkItemRates[activeMigrator].startCount || 0);
        var rate      = elapsed > 2 ? (itemsDone / elapsed) : 0; // items/sec

        if (rate > 0 && remaining > 0) {
            var etaSec = remaining / rate;
            var etaStr = etaSec < 60
                ? '~' + Math.ceil(etaSec) + 's remaining'
                : '~' + Math.ceil(etaSec / 60) + ' min remaining';

            // Inject into progress table row for this migrator.
            $('#ow-progress-table tbody tr').each(function () {
                if ($(this).data('migrator') === activeMigrator) {
                    var $etaCell = $(this).find('.ow-eta');
                    if ($etaCell.length) {
                        $etaCell.text(etaStr);
                    } else {
                        $(this).find('td:last').append(' <span class="ow-eta" style="font-size:11px;color:#888;margin-left:6px;">' + etaStr + '</span>');
                    }
                }
            });
        }
    }

    /* ════════════════════════════════════════════════════════════════════
       PROGRESS TABLE RENDERING
    ════════════════════════════════════════════════════════════════════ */
    var LABEL_MAP = {
        tax:           'Tax Classes',
        order_statuses:'Order Statuses',
        categories:    'Categories',
        images:        'Images',
        products:      'Products',
        manufacturers: 'Manufacturers / Brands',
        related:       'Related Products',
        bundles:       'Bundles',
        customers:     'Customers',
        orders:        'Orders',
        coupons:       'Coupons',
        seo:           'SEO URLs',
        information:   'Information Pages',
        tags:          'Tags',
        filters:       'Product Filters',
        downloads:     'Downloads',
        reviews:       'Reviews',
        multilingual:  'Multilingual (WPML/Polylang)',
    };

    function renderProgressTable(checkpoints) {
        var $tbody = $progressTable.find('tbody');
        $tbody.empty();

        if (!checkpoints || !checkpoints.length) {
            $tbody.append('<tr><td colspan="5" style="color:#888;">No migration data yet.</td></tr>');
            return;
        }

        var runningCp = checkpoints.filter(function (cp) { return cp.status === 'running'; })[0]
                     || checkpoints.filter(function (cp) { return cp.status === 'pending'; })[0];
        if (runningCp) { currentMigrator = runningCp.migrator; }

        checkpoints.forEach(function (cp) {
            var processed = parseInt(cp.processed_count, 10) || 0;
            var total     = parseInt(cp.total_count, 10)     || 0;
            var safe      = total > 0 ? Math.min(processed, total) : processed;
            var pct       = total > 0 ? Math.round(safe / total * 100) : (cp.status === 'completed' ? 100 : 0);
            pct = Math.max(0, Math.min(100, pct));
            var isDone   = cp.status === 'completed';
            var isFailed = cp.status === 'failed';
            var isRun    = cp.status === 'running';

            var statusIcons = {
                completed: '✔',
                failed:    '✘',
                running:   '<span class="ow-spinner dark" style="width:11px;height:11px;"></span>',
                aborted:   '⊘',
                pending:   '○',
                skipped:   '⤳',
            };
            var statusColors = {
                completed: '#2e7d32',
                failed:    '#c62828',
                running:   '#1565c0',
                aborted:   '#757575',
                pending:   '#9e9e9e',
                skipped:   '#ef6c00',
            };

            var icon  = statusIcons[cp.status]  || '·';
            var color = statusColors[cp.status] || '#333';

            var barCls = isFailed ? ' failed' : (isDone ? ' done' : (isRun ? ' running' : ''));
            var barHtml = '<div class="ow-progress-bar-wrap"><div class="ow-progress-bar' + barCls + '" style="width:' + (isFailed ? 100 : pct) + '%"></div></div>';

            var $tr = $('<tr>').attr('data-migrator', cp.migrator).append(
                $('<td>').html('<strong>' + (LABEL_MAP[cp.migrator] || cp.migrator) + '</strong>'),
                $('<td>').html('<span style="color:' + color + ';white-space:nowrap;">' + icon + ' ' + cp.status.toUpperCase() + '</span>'),
                $('<td>').html(safe.toLocaleString() + ' / ' + (total > 0 ? total.toLocaleString() : '—')),
                $('<td>').html(barHtml),
                $('<td>').html('<strong>' + pct + '%</strong>')
            );

            $tbody.append($tr);
        });
    }

    /* ════════════════════════════════════════════════════════════════════
       MIGRATION REPORT
    ════════════════════════════════════════════════════════════════════ */
    function renderReport(report) {
        var $panel = $('#ow-report-panel');
        if (!$panel.length || !report || !report.migrators) { return; }

        var html = '<h3 style="margin:0 0 10px;">Migration Summary</h3>';
        html += '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
        html += '<thead><tr><th style="text-align:left;padding:6px 10px;background:#f5f5f5;border:1px solid #ddd;">Entity</th>';
        html += '<th style="padding:6px 10px;background:#f5f5f5;border:1px solid #ddd;">Processed</th>';
        html += '<th style="padding:6px 10px;background:#f5f5f5;border:1px solid #ddd;">Skipped</th>';
        html += '<th style="padding:6px 10px;background:#f5f5f5;border:1px solid #ddd;">Failed</th></tr></thead><tbody>';

        var totalProcessed = 0, totalFailed = 0;
        $.each(report.migrators, function (key, m) {
            var p = m.processed || 0, s = m.skipped || 0, f = m.failed || 0;
            totalProcessed += p; totalFailed += f;
            html += '<tr>';
            html += '<td style="padding:5px 10px;border:1px solid #ddd;">' + (LABEL_MAP[key] || key) + '</td>';
            html += '<td style="padding:5px 10px;border:1px solid #ddd;text-align:center;color:#2e7d32;">' + p.toLocaleString() + '</td>';
            html += '<td style="padding:5px 10px;border:1px solid #ddd;text-align:center;color:#757575;">' + s.toLocaleString() + '</td>';
            html += '<td style="padding:5px 10px;border:1px solid #ddd;text-align:center;color:' + (f > 0 ? '#c62828' : '#2e7d32') + ';">' + f + '</td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        html += '<p style="margin:8px 0 0;font-size:12px;color:#555;">Total: <strong>' + totalProcessed.toLocaleString() + '</strong> processed';
        if (totalFailed > 0) { html += ', <strong style="color:#c62828;">' + totalFailed + '</strong> failed'; }
        html += '.</p>';

        $panel.html(html).show();
    }

    /* ════════════════════════════════════════════════════════════════════
       BANNER HELPERS
    ════════════════════════════════════════════════════════════════════ */
    function setBannerRunning(modeLabel) {
        $statusBanner
            .removeClass('ow-alert-success ow-alert-error ow-alert-warning')
            .addClass('ow-alert ow-alert-info')
            .html('<span class="ow-spinner"></span>&nbsp; ' + (modeLabel || 'Migration') + ' in progress…')
            .show();
    }

    function setBannerDone(report) {
        var msg = '✔ Migration complete!';
        if (report && report.migrators) {
            var totals = Object.values(report.migrators).reduce(function (acc, m) {
                acc.processed += (m.processed || 0);
                acc.failed    += (m.failed    || 0);
                return acc;
            }, { processed: 0, failed: 0 });
            msg += ' — ' + totals.processed.toLocaleString() + ' items processed';
            if (totals.failed > 0) { msg += ', ' + totals.failed + ' failed'; }
            msg += '.';
        }
        $statusBanner
            .removeClass('ow-alert-info ow-alert-error ow-alert-warning')
            .addClass('ow-alert ow-alert-success').text(msg).show();
        showToast(msg, 'success', 8000);
        $progressTable[0] && $progressTable[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function setBannerError(msg) {
        $statusBanner.removeClass('ow-alert-info ow-alert-success ow-alert-warning')
            .addClass('ow-alert ow-alert-error').text('Error: ' + msg).show();
        showToast(msg, 'error', 0);
    }

    function setBannerInfo(msg) {
        $statusBanner.removeClass('ow-alert-error ow-alert-success ow-alert-warning')
            .addClass('ow-alert ow-alert-info').text(msg).show();
    }

    /* ════════════════════════════════════════════════════════════════════
       BUTTON STATE MACHINE
    ════════════════════════════════════════════════════════════════════ */
    function setButtonState(state) {
        var $btnDemo     = $('#ow-btn-demo');
        var $btnRecovery = $('#ow-btn-images-only,#ow-btn-products-images,#ow-btn-cats-manufacturers,#ow-btn-multilingual,#ow-btn-cleanup-ml-terms,#ow-btn-repair-order-items,#ow-btn-rerun-seo');
        var $btnBgStart  = $('#ow-btn-start-bg,#ow-btn-resume-bg');

        if (state === 'running') {
            $btnStart.prop('disabled', true).html('<span class="ow-spinner"></span>&nbsp; Running…');
            $btnDemo.prop('disabled', true);
            $btnRecovery.prop('disabled', true);
            $btnResume.prop('disabled', true);
            $btnAbort.prop('disabled', false);
            $btnPause.prop('disabled', false);
            $btnSkip.prop('disabled', false);
            $btnBgStart.prop('disabled', true);
        } else if (state === 'paused') {
            $btnStart.prop('disabled', true).text('▶ Start Full Migration');
            $btnDemo.prop('disabled', true);
            $btnRecovery.prop('disabled', true);
            $btnResume.prop('disabled', false);
            $btnAbort.prop('disabled', false);
            $btnPause.prop('disabled', true);
            $btnSkip.prop('disabled', false);
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
            $('#ow-btn-start-bg').prop('disabled', false);
            $('#ow-btn-resume-bg').prop('disabled', true);
        }
    }

    /* ════════════════════════════════════════════════════════════════════
       SYSTEM CHECK (VALIDATOR)
    ════════════════════════════════════════════════════════════════════ */
    function runSystemCheck() {
        var $btn   = $('#ow-btn-validate');
        var $panel = $('#ow-validate-panel');
        $btn.prop('disabled', true).html('<span class="ow-spinner dark"></span>&nbsp; Checking…');
        $panel.html('<em style="color:#888;">Running system checks…</em>').show();

        $.post(octoWoo.ajaxUrl, { action: 'octowoo_validate', nonce: octoWoo.nonce })
        .done(function (res) {
            if (!res.success || !res.data) {
                $panel.html('<span style="color:#c62828;">✘ Validation request failed.</span>');
                return;
            }
            var data    = res.data;
            var results = data.results || {};

            var colorMap = { pass: '#2e7d32', warning: '#e65100', fail: '#c62828' };
            var bgMap    = { pass: '#f1f8f1', warning: '#fff8f1', fail: '#fef2f2' };
            var iconMap  = { pass: '✔', warning: '⚠', fail: '✘' };
            var labelMap = {
                php_version: 'PHP Version', memory_limit: 'Memory Limit', required_extensions: 'PHP Extensions',
                upload_size: 'Max Upload Size', execution_time: 'Execution Time',
                db_connection: 'DB Connection', image_path: 'Image Path',
                disk_space: 'Disk Space', log_directory: 'Log Directory', hpos_compat: 'HPOS Compatibility',
            };

            var html = '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
            html += '<thead><tr><th style="text-align:left;padding:5px 10px;background:#f5f5f5;border:1px solid #ddd;">Check</th>';
            html += '<th style="padding:5px 10px;background:#f5f5f5;border:1px solid #ddd;">Status</th>';
            html += '<th style="padding:5px 10px;background:#f5f5f5;border:1px solid #ddd;">Details</th>';
            html += '<th style="padding:5px 10px;background:#f5f5f5;border:1px solid #ddd;">Action</th></tr></thead><tbody>';

            $.each(results, function (key, check) {
                var status = check.status || 'pass';
                var color  = colorMap[status] || '#333';
                var bg     = bgMap[status]    || '#fff';
                var icon   = iconMap[status]  || '·';
                var label  = labelMap[key]    || key;
                html += '<tr style="background:' + bg + ';border-bottom:1px solid #e5e5e5;">';
                html += '<td style="padding:5px 10px;font-weight:600;">' + label + '</td>';
                html += '<td style="padding:5px 10px;color:' + color + ';font-weight:700;white-space:nowrap;">' + icon + ' ' + status.toUpperCase() + '</td>';
                html += '<td style="padding:5px 10px;">' + $('<span>').text(check.message || '').html();
                if (check.value) { html += ' <code style="font-size:11px;background:#f5f5f5;padding:1px 4px;border-radius:2px;">' + $('<span>').text(check.value).html() + '</code>'; }
                html += '</td>';
                html += '<td style="padding:5px 10px;font-size:11px;color:#666;">' + (check.fix ? $('<span>').text(check.fix).html() : '') + '</td>';
                html += '</tr>';
            });
            html += '</tbody></table>';

            var summary;
            if (data.all_passed) {
                summary = '<div style="padding:8px 12px;background:#edf7ed;border:1px solid #4caf50;color:#1b5e20;border-radius:4px;margin-bottom:8px;font-weight:600;">✔ All checks passed — your server is ready to migrate.</div>';
            } else if (data.has_warnings) {
                summary = '<div style="padding:8px 12px;background:#fffbeb;border:1px solid #f59e0b;color:#78350f;border-radius:4px;margin-bottom:8px;font-weight:600;">⚠ Warnings detected — migration can proceed but review the notes below.</div>';
            } else {
                summary = '<div style="padding:8px 12px;background:#fef2f2;border:1px solid #ef4444;color:#7f1d1d;border-radius:4px;margin-bottom:8px;font-weight:600;">✘ Checks failed — fix these issues before migrating.</div>';
            }
            $panel.html(summary + html);
        })
        .fail(function (xhr) {
            $panel.html('<span style="color:#c62828;">✘ System check failed: ' + xhr.statusText + '</span>');
        })
        .always(function () { $btn.prop('disabled', false).text('🔎 Run System Check'); });
    }

    /* ════════════════════════════════════════════════════════════════════
       TEST DB CONNECTION
    ════════════════════════════════════════════════════════════════════ */
    /* ════════════════════════════════════════════════════════════════════
       LANGUAGE AUTO-DETECTOR
    ════════════════════════════════════════════════════════════════════ */
    function detectLanguages() {
        var $btn    = $('#ow-btn-detect-languages');
        var $panel  = $('#ow-lang-detect-panel');
        var $result = $('#ow-lang-detect-result');

        $btn.prop('disabled', true).html('<span class="ow-spinner dark"></span>&nbsp; Detecting…');
        $panel.show();
        $result.html('<em style="color:#888;">Querying OpenCart oc_language table…</em>');

        var payload = {
            action:    'octowoo_detect_languages',
            nonce:     octoWoo.nonce,
            db_host:   $('input[name="octowoo[db][host]"]').val()     || '',
            db_port:   $('input[name="octowoo[db][port]"]').val()     || '3306',
            db_name:   $('input[name="octowoo[db][database]"]').val() || '',
            db_user:   $('input[name="octowoo[db][username]"]').val() || '',
            db_prefix: $('input[name="octowoo[db][prefix]"]').val()   || 'oc_',
        };
        var pass = $('input[name="octowoo[db][password]"]').val();
        if (pass && pass !== '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022') { payload.db_pass = pass; }

        $.post(octoWoo.ajaxUrl, payload)
        .done(function(res) {
            if (!res || !res.success || !res.data) {
                $result.html('<span style="color:#c62828;">\u2718 ' + ((res && res.data && res.data.message) || 'Detection failed') + '</span>');
                return;
            }

            var langs  = res.data.languages  || [];
            var sugPri = res.data.suggested_pri || 0;
            var sugSec = res.data.suggested_sec || 0;
            var curPri = parseInt($('#ow-lang-primary').val())   || 0;
            var curSec = parseInt($('#ow-lang-secondary').val()) || 0;

            if (!langs.length) {
                $result.html('<span style="color:#c62828;">No languages found in oc_language.</span>');
                return;
            }

            var html = '<p style="margin:0 0 10px;font-size:12px;font-weight:600;">'
                + '\u2714 Found ' + langs.length + ' language(s) in OpenCart. Click any row to set as Primary / Secondary:</p>';
            html += '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
            html += '<thead><tr style="background:#e8f0fe;">'
                + '<th style="padding:5px 8px;border:1px solid #c7d3f0;text-align:left;">ID</th>'
                + '<th style="padding:5px 8px;border:1px solid #c7d3f0;text-align:left;">Language Name</th>'
                + '<th style="padding:5px 8px;border:1px solid #c7d3f0;text-align:left;">Code</th>'
                + '<th style="padding:5px 8px;border:1px solid #c7d3f0;text-align:center;">Products</th>'
                + '<th style="padding:5px 8px;border:1px solid #c7d3f0;text-align:center;">Set as</th>'
                + '</tr></thead><tbody>';

            langs.forEach(function(lang) {
                var isPri    = lang.id === sugPri;
                var isSec    = lang.id === sugSec;
                var isCurPri = lang.id === curPri;
                var isCurSec = lang.id === curSec;
                var rowBg    = isCurPri ? '#edf7ed' : (isCurSec ? '#fff8e7' : (isPri ? '#f0fdf4' : (isSec ? '#fffbeb' : '#fff')));
                var badge    = isPri ? ' <span style="background:#2e7d32;color:#fff;font-size:9px;padding:1px 5px;border-radius:3px;margin-left:4px;">suggested primary</span>'
                             : isSec ? ' <span style="background:#e65100;color:#fff;font-size:9px;padding:1px 5px;border-radius:3px;margin-left:4px;">suggested secondary</span>'
                             : '';
                var curBadge = isCurPri ? ' <span style="background:#2271b1;color:#fff;font-size:9px;padding:1px 5px;border-radius:3px;margin-left:4px;">\u2714 current primary</span>'
                             : isCurSec ? ' <span style="background:#9c6f00;color:#fff;font-size:9px;padding:1px 5px;border-radius:3px;margin-left:4px;">\u2714 current secondary</span>'
                             : '';
                var statusIcon = lang.status ? '' : ' <span style="color:#c62828;font-size:10px;" title="Inactive in OpenCart">\u26a0 inactive</span>';

                html += '<tr style="background:' + rowBg + ';cursor:default;">';
                html += '<td style="padding:6px 8px;border:1px solid #ddd;font-weight:700;font-family:monospace;font-size:14px;">' + lang.id + '</td>';
                html += '<td style="padding:6px 8px;border:1px solid #ddd;">'
                    + '<strong>' + $('<span>').text(lang.name).html() + '</strong>'
                    + badge + curBadge + statusIcon + '</td>';
                html += '<td style="padding:6px 8px;border:1px solid #ddd;font-family:monospace;color:#555;">' + $('<span>').text(lang.code).html() + '</td>';
                html += '<td style="padding:6px 8px;border:1px solid #ddd;text-align:center;">'
                    + (lang.count > 0 ? '<strong>' + lang.count.toLocaleString() + '</strong>' : '<span style="color:#aaa;">—</span>') + '</td>';
                html += '<td style="padding:6px 8px;border:1px solid #ddd;text-align:center;white-space:nowrap;">';
                html += '<button type="button" class="ow-btn ow-btn-secondary ow-set-lang-btn" '
                    + 'data-type="primary" data-id="' + lang.id + '" data-name="' + $('<span>').text(lang.name).html() + '" '
                    + 'style="font-size:10px;padding:2px 8px;margin-right:4px;">'
                    + (isCurPri ? '\u2714 ' : '') + 'Set Primary</button>';
                html += '<button type="button" class="ow-btn ow-btn-secondary ow-set-lang-btn" '
                    + 'data-type="secondary" data-id="' + lang.id + '" data-name="' + $('<span>').text(lang.name).html() + '" '
                    + 'style="font-size:10px;padding:2px 8px;">'
                    + (isCurSec ? '\u2714 ' : '') + 'Set Secondary</button>';
                html += '</td></tr>';
            });
            html += '</tbody></table>';
            html += '<p style="margin:8px 0 0;font-size:11px;color:#555;">'
                + '<strong>Tip:</strong> The language with the most Products is usually your primary language. '
                + 'After setting, click <strong>Save Settings</strong>.</p>';

            // Auto-apply suggestions when fields are at defaults.
            if (sugPri > 0 && (curPri === 0 || curPri === 1)) {
                var priName = (langs.find(function(l) { return l.id === sugPri; }) || {}).name || '';
                owSetLang('primary', sugPri, priName);
            }
            if (sugSec > 0 && sugSec !== sugPri && (curSec === 0 || curSec === 2)) {
                var secName = (langs.find(function(l) { return l.id === sugSec; }) || {}).name || '';
                owSetLang('secondary', sugSec, secName);
            }

            $result.html(html);
        })
        .fail(function() {
            $result.html('<span style="color:#c62828;">Request failed. Save settings and check DB connection first.</span>');
        })
        .always(function() {
            $btn.prop('disabled', false).html('\ud83d\udd0d Auto-Detect from OpenCart DB');
        });
    }

    // Global setter so onclick handlers inside the dynamically-built table can call it.
    window.owSetLang = function(type, id, name) {
        if (type === 'primary') {
            $('#ow-lang-primary').val(id);
            $('#ow-lang-primary-name').text(name ? '\u2192 ' + name : '');
        } else {
            $('#ow-lang-secondary').val(id);
            $('#ow-lang-secondary-name').text(name ? '\u2192 ' + name : '');
        }
    };

    function testConnection() {
        var $btn    = $('#ow-btn-test-connection');
        var $result = $('#ow-connection-result');
        $btn.prop('disabled', true).html('<span class="ow-spinner dark"></span>&nbsp; Testing…');
        $result.text('').css('color', '#555');

        // Send live field values so user can test before saving.
        // Also handles the case where settings have never been saved yet.
        var payload = {
            action:    'octowoo_test_connection',
            nonce:     octoWoo.nonce,
            db_host:   $('input[name="octowoo[db][host]"]').val()     || '',
            db_port:   $('input[name="octowoo[db][port]"]').val()     || 3306,
            db_name:   $('input[name="octowoo[db][database]"]').val() || '',
            db_user:   $('input[name="octowoo[db][username]"]').val() || '',
            db_prefix: $('input[name="octowoo[db][prefix]"]').val()   || 'oc_',
            db_socket: $('input[name="octowoo[db][socket]"]').val()   || '',
        };
        // Only send password if user typed one (blank = use saved encrypted value).
        var pass = $('input[name="octowoo[db][password]"]').val();
        if (pass && pass !== '••••••••') { payload.db_pass = pass; }

        $.post(octoWoo.ajaxUrl, payload)
        .done(function (res) {
            if (res.success) {
                $result.css('color', '#2e7d32').text('✔ ' + (res.data ? res.data.message : 'Connection successful.'));
                showToast('OpenCart DB connection successful!', 'success');
            } else {
                var msg = res.data ? res.data.message : 'Connection failed.';
                $result.css('color', '#c62828').text('✘ ' + msg);
                showToast('Connection failed: ' + msg, 'error', 0);
            }
        })
        .fail(function (xhr) {
            $result.css('color', '#c62828').text('✘ Request failed: ' + xhr.statusText);
        })
        .always(function () { $btn.prop('disabled', false).text('🔌 Test Connection'); });
    }

    /* ════════════════════════════════════════════════════════════════════
       AUTO-DETECT
    ════════════════════════════════════════════════════════════════════ */
    function autoDetect() {
        var $btn = $('#ow-btn-auto-detect');
        $btn.prop('disabled', true).html('<span class="ow-spinner dark"></span>&nbsp; Detecting…');

        $.post(octoWoo.ajaxUrl, { action: 'octowoo_prescan', nonce: octoWoo.nonce })
        .done(function (res) {
            if (res.success && res.data) {
                var data = res.data;

                // Auto-fill image path.
                if (data.images && data.images.detected_path) {
                    $('input[name="octowoo[opencart][image_path]"]').val(data.images.detected_path);
                }

                // v2.4.72: Render pre-scan summary panel.
                renderPrescanPanel(data);

                var msg = 'Store scan complete.';
                if (data.logs && !data.logs.writable) {
                    msg += ' ⚠ Log directory is NOT writable.';
                }
                showToast(msg, data.logs && !data.logs.writable ? 'warning' : 'success', 5000);
            } else {
                showToast('Auto-detect returned no results. Check your DB connection in Settings.', 'warning');
            }
        })
        .fail(function (xhr) { showToast('Auto-detect failed: ' + xhr.statusText, 'error'); })
        .always(function () { $btn.prop('disabled', false).text('🔎 Auto-detect Image Path & Logs'); });
    }

    /* ════════════════════════════════════════════════════════════════════
       PRE-SCAN SUMMARY PANEL (v2.4.72)
       Renders entity counts from prescan or scan_counts response.
    ════════════════════════════════════════════════════════════════════ */
    function renderPrescanPanel(data) {
        var $panel = $('#ow-prescan-summary');
        var $grid  = $('#ow-prescan-grid');
        var $advice = $('#ow-prescan-advice');

        if (!$panel.length) { return; }

        var LABELS = {
            products:      '📦 Products',
            categories:    '📁 Categories',
            customers:     '👤 Customers',
            orders:        '🧾 Orders',
            manufacturers: '🏭 Brands',
            reviews:       '⭐ Reviews',
            coupons:       '🏷️ Coupons',
            images:        '🖼️ Images',
        };

        var html = '';
        var totalItems = 0;
        var counts = {};

        // Merge data from prescan (may have counts nested)
        $.each(data, function(key, val) {
            if (LABELS[key]) {
                var count = 0;
                if (typeof val === 'number') { count = val; }
                else if (val && typeof val.count === 'number') { count = val.count; }
                else if (val && typeof val.total === 'number') { count = val.total; }
                counts[key] = count;
                totalItems += count;
            }
        });
        // Also check data.counts (from scan_counts action)
        if (data.counts) {
            $.each(data.counts, function(key, val) {
                if (LABELS[key]) {
                    counts[key] = parseInt(val) || 0;
                    totalItems += counts[key];
                }
            });
        }

        if (!Object.keys(counts).length) {
            $panel.hide();
            return;
        }

        $.each(counts, function(key, count) {
            var pct   = totalItems > 0 ? Math.round(count / totalItems * 100) : 0;
            var color = count > 10000 ? '#e65100' : (count > 1000 ? '#1565c0' : '#2e7d32');
            html += '<div style="background:#f9f9f9;border:1px solid #e0e0e0;border-radius:6px;padding:10px 12px;text-align:center;">' +
                '<div style="font-size:18px;font-weight:700;color:' + color + ';">' + count.toLocaleString() + '</div>' +
                '<div style="font-size:11px;color:#666;margin-top:2px;">' + (LABELS[key] || key) + '</div>' +
                '</div>';
        });

        $grid.html(html);

        // Advice message.
        var advice = '';
        if (totalItems > 50000) {
            advice = '⚠ Large store (' + totalItems.toLocaleString() + ' total items). <strong>Use Background Mode</strong> and set Batch Size to 10–15.';
        } else if (totalItems > 10000) {
            advice = 'ℹ Medium store (' + totalItems.toLocaleString() + ' total items). Batch Size 20–30 recommended. Background Mode optional.';
        } else {
            advice = '✔ Small store (' + totalItems.toLocaleString() + ' total items). Standard AJAX migration should work fine.';
        }
        $advice.html(advice);

        $panel.slideDown(200);
    }

    /* ════════════════════════════════════════════════════════════════════
       SCAN SOURCE COUNTS
    ════════════════════════════════════════════════════════════════════ */
    function scanSourceCounts() {
        var $btn   = $('#ow-btn-scan');
        var $panel = $('#ow-scan-panel');
        $btn.prop('disabled', true).html('<span class="ow-spinner dark"></span>&nbsp; Scanning…');
        if (!$panel.length) { $btn.after('<div id="ow-scan-panel" style="margin-top:10px;"></div>'); }
        $('#ow-scan-panel').html('<em style="color:#888;">Counting OpenCart records…</em>').show();

        $.post(octoWoo.ajaxUrl, { action: 'octowoo_scan_counts', nonce: octoWoo.nonce })
        .done(function (res) {
            if (!res.success || !res.data) {
                $('#ow-scan-panel').html('<span style="color:#c62828;">Scan failed.</span>');
                return;
            }
            var counts = res.data.counts || {};
            var html = '<table style="font-size:12px;border-collapse:collapse;">';
            $.each(counts, function (entity, count) {
                html += '<tr><td style="padding:3px 10px 3px 0;color:#555;font-weight:600;">' + entity + '</td>';
                html += '<td style="padding:3px 0;font-weight:700;">' + parseInt(count).toLocaleString() + '</td></tr>';
            });
            html += '</table>';
            $('#ow-scan-panel').html(html);
            renderPrescanPanel({ counts: counts });
            showToast('Source counts refreshed.', 'success');
        })
        .fail(function () { $('#ow-scan-panel').html('<span style="color:#c62828;">Scan request failed.</span>'); })
        .always(function () { $btn.prop('disabled', false).text('🔍 Scan Source'); });
    }

    /* ════════════════════════════════════════════════════════════════════
       REPAIR PRODUCT CATEGORIES (v2.5.1)
       Re-assigns category terms for all OctoWoo products.
       Fixes silent failures when ProductMigrator ran before CategoryMigrator.
    ════════════════════════════════════════════════════════════════════ */
    function repairCategories() {
        var $btn = $('#ow-btn-repair-categories');
        if ($btn.prop('disabled')) { return; }

        owConfirm(
            'This will re-scan all migrated products and re-assign their WooCommerce categories using the current ID map.\n\nRun this if products appear uncategorised after migration.',
            'Yes, repair categories',
            'Cancel'
        ).then(function (confirmed) {
            if (!confirmed) { return; }
            $btn.prop('disabled', true).html('<span class="ow-spinner dark"></span>&nbsp; Repairing…');

            var totalRepaired = 0;

            function runPage(page) {
                $.post(octoWoo.ajaxUrl, {
                    action: 'octowoo_repair_categories',
                    nonce:  octoWoo.nonce,
                    page:   page,
                })
                .done(function (res) {
                    if (!res || !res.success) {
                        showToast('Category repair failed: ' + ((res && res.data && res.data.message) || 'Unknown error'), 'error');
                        $btn.prop('disabled', false).text('🏷️ Repair Product Categories');
                        return;
                    }
                    totalRepaired += (res.data.repaired || 0);
                    $btn.html('<span class="ow-spinner dark"></span>&nbsp; Page ' + page + '… (' + totalRepaired + ' repaired)');

                    if (res.data.done) {
                        showToast('Category repair complete. ' + totalRepaired + ' product(s) updated.', 'success', 7000);
                        $btn.prop('disabled', false).text('🏷️ Repair Product Categories');
                    } else {
                        runPage(page + 1);
                    }
                })
                .fail(function () {
                    showToast('Repair request failed. Check server logs.', 'error');
                    $btn.prop('disabled', false).text('🏷️ Repair Product Categories');
                });
            }

            runPage(1);
        });
    }

    /* ════════════════════════════════════════════════════════════════════
       REPAIR ORDER ITEMS
    ════════════════════════════════════════════════════════════════════ */
    function repairOrderItems() {
        var $btn = $('#ow-btn-repair-order-items');
        if ($btn.prop('disabled')) { return; }

        owConfirm('This will scan all migrated orders and re-link broken product references by SKU. Continue?', 'Yes, repair', 'Cancel')
        .then(function (confirmed) {
            if (!confirmed) { return; }
            $btn.prop('disabled', true).html('<span class="ow-spinner dark"></span>&nbsp; Repairing…');
            var totalRelinked = 0;

            function runBatch(isFirst) {
                $.post(octoWoo.ajaxUrl, {
                    action: 'octowoo_repair_order_items',
                    nonce:  octoWoo.nonce,
                    page:   isFirst ? 1 : undefined,
                })
                .done(function (res) {
                    if (!res || !res.success) {
                        var errMsg = (res && res.data && res.data.message) ? res.data.message : 'Repair failed.';
                        showToast('Repair failed: ' + errMsg, 'error');
                        $btn.prop('disabled', false).text('🔗 Repair Order Items');
                        return;
                    }
                    totalRelinked += (res.data.relinked || 0);
                    if (res.data.done) {
                        showToast('Repair complete. Re-linked ' + totalRelinked + ' order item(s).', 'success', 6000);
                        $btn.prop('disabled', false).text('🔗 Repair Order Items');
                    } else {
                        runBatch(false);
                    }
                })
                .fail(function () {
                    showToast('Repair request failed. Check server logs.', 'error');
                    $btn.prop('disabled', false).text('🔗 Repair Order Items');
                });
            }
            runBatch(true);
        });
    }

    /* ════════════════════════════════════════════════════════════════════
       CLEANUP ML TERMS
    ════════════════════════════════════════════════════════════════════ */
    function cleanupMlTerms() {
        var $btn = $('#ow-btn-cleanup-ml-terms');
        if ($btn.prop('disabled')) { return; }
        $btn.prop('disabled', true).html('<span class="ow-spinner dark"></span>&nbsp; Cleaning…');

        $.post(octoWoo.ajaxUrl, { action: 'octowoo_cleanup_ml_terms', nonce: octoWoo.nonce })
        .done(function (res) {
            showToast(res && res.success ? (res.data.message || 'Cleanup complete.') : 'Cleanup failed.', res && res.success ? 'success' : 'error');
        })
        .fail(function () { showToast('Cleanup request failed.', 'error'); })
        .always(function () { $btn.prop('disabled', false).text('🧹 Fix Orphan Categories'); });
    }

    /* ════════════════════════════════════════════════════════════════════
       RERUN SEO MIGRATOR
    ════════════════════════════════════════════════════════════════════ */
    function rerunSeoMigrator() {
        var $btn = $('#ow-btn-rerun-seo');
        if ($btn.prop('disabled')) { return; }

        owConfirm('This will reset the SEO checkpoint and clear stored redirects, then re-run only the SEO migrator. Continue?', 'Yes, re-run SEO', 'Cancel')
        .then(function (confirmed) {
            if (!confirmed) { return; }
            $btn.prop('disabled', true).html('<span class="ow-spinner dark"></span>&nbsp; Resetting SEO…');

            $.post(octoWoo.ajaxUrl, { action: 'octowoo_rerun_seo', nonce: octoWoo.nonce, run_id: currentRunId || '' })
            .done(function (res) {
                if (res.success) {
                    showToast('SEO checkpoint reset. Starting SEO migrator…', 'info');
                    currentRunId = res.data.run_id || currentRunId;
                    startMigration(true, false, 'seo', 'SEO Re-run', true);
                } else {
                    showToast(res.data ? res.data.message : 'Rerun failed.', 'error');
                }
            })
            .fail(function () { showToast('SEO rerun request failed.', 'error'); })
            .always(function () { $btn.prop('disabled', false).text('🌐 Rerun SEO Migrator'); });
        });
    }

    /* ════════════════════════════════════════════════════════════════════
       SQL IMPORT
    ════════════════════════════════════════════════════════════════════ */
    function importSql() {
        var file = document.getElementById('ow-sql-file') && document.getElementById('ow-sql-file').files[0];
        if (!file) { showToast('Please select a .sql or .sql.gz file first.', 'warning'); return; }

        var $btn    = $('#ow-btn-import-sql');
        var $result = $('#ow-sql-result');
        var $prog   = $('#ow-sql-progress');
        var prefix  = $('#ow-sql-prefix').val() || 'oc_';

        $btn.prop('disabled', true).html('<span class="ow-spinner dark"></span>&nbsp; Uploading…');
        $result.text('').css('color', '#555');
        $prog.show();

        var fd = new FormData();
        fd.append('action',     'octowoo_import_sql');
        fd.append('nonce',      octoWoo.nonce);
        fd.append('oc_prefix',  prefix);
        fd.append('sql_file',   file);

        $.ajax({ url: octoWoo.ajaxUrl, type: 'POST', data: fd, processData: false, contentType: false,
            xhr: function () {
                var xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function (e) {
                    if (e.lengthComputable) {
                        var pct = Math.round(e.loaded / e.total * 100);
                        $prog.find('.ow-upload-progress').text('Uploading: ' + pct + '%');
                    }
                });
                return xhr;
            },
        })
        .done(function (res) {
            if (res.success) {
                $result.css('color', '#2e7d32').text('✔ SQL imported: ' + (res.data.tables || '?') + ' tables.');
                showToast('SQL file imported successfully!', 'success');
            } else {
                var msg = res.data ? res.data.message : 'Import failed.';
                $result.css('color', '#c62828').text('✘ ' + msg);
                showToast('SQL import failed: ' + msg, 'error', 0);
            }
        })
        .fail(function (xhr) {
            $result.css('color', '#c62828').text('✘ Upload failed: ' + xhr.statusText);
        })
        .always(function () {
            $btn.prop('disabled', false).text('⬆ Import SQL');
            $prog.hide();
        });
    }

    function dropSql(e) {
        e.preventDefault();
        owConfirm('Drop all imported OpenCart tables (octowoo_oc_*) from this database?', 'Yes, drop tables', 'Cancel')
        .then(function (confirmed) {
            if (!confirmed) { return; }
            $.post(octoWoo.ajaxUrl, { action: 'octowoo_drop_sql', nonce: octoWoo.nonce })
            .done(function (res) {
                if (res.success) {
                    $('#ow-sql-imported-status').hide();
                    showToast(res.data.message || 'Tables dropped.', 'success');
                } else {
                    showToast(res.data ? res.data.message : 'Drop failed.', 'error');
                }
            });
        });
    }

    /* ════════════════════════════════════════════════════════════════════
       IMAGES ZIP IMPORT
    ════════════════════════════════════════════════════════════════════ */
    function importImages() {
        var file = document.getElementById('ow-images-zip') && document.getElementById('ow-images-zip').files[0];
        if (!file) { showToast('Please select a ZIP file first.', 'warning'); return; }

        var $btn    = $('#ow-btn-import-images');
        var $result = $('#ow-images-result');
        $btn.prop('disabled', true).html('<span class="ow-spinner dark"></span>&nbsp; Uploading…');

        var fd = new FormData();
        fd.append('action',     'octowoo_import_images');
        fd.append('nonce',      octoWoo.nonce);
        fd.append('images_zip', file);

        $.ajax({ url: octoWoo.ajaxUrl, type: 'POST', data: fd, processData: false, contentType: false })
        .done(function (res) {
            if (res.success) {
                $result.css('color', '#2e7d32').text('✔ Images extracted: ' + (res.data.files || '?') + ' files.');
                showToast('Images ZIP extracted successfully!', 'success');
            } else {
                var msg = res.data ? res.data.message : 'Image import failed.';
                $result.css('color', '#c62828').text('✘ ' + msg);
                showToast(msg, 'error', 0);
            }
        })
        .fail(function (xhr) { $result.css('color', '#c62828').text('✘ ' + xhr.statusText); })
        .always(function () { $btn.prop('disabled', false).text('🗜 Extract ZIP'); });
    }

    /* ════════════════════════════════════════════════════════════════════
       LOGS
    ════════════════════════════════════════════════════════════════════ */
    function refreshLogs() {
        var runId    = currentRunId || octoWoo.lastRunId || '';
        var level    = $('#ow-log-level-filter').val()    || '';
        var migrator = $('#ow-log-migrator-filter').val() || '';
        var search   = $('#ow-log-search').val()          || '';
        var limit    = 500;

        if (!runId) { return; }

        $.get(octoWoo.ajaxUrl, {
            action:   'octowoo_get_logs',
            nonce:    octoWoo.nonce,
            run_id:   runId,
            level:    level,
            migrator: migrator,
            limit:    limit,
        })
        .done(function (res) {
            if (!res.success || !res.data) { return; }
            var logs = res.data.logs || [];

            // Client-side search filter (fast, no extra AJAX).
            if (search) {
                var q = search.toLowerCase();
                logs = logs.filter(function(e) {
                    return (e.message || '').toLowerCase().indexOf(q) !== -1
                        || (e.migrator || '').toLowerCase().indexOf(q) !== -1;
                });
            }

            renderLogs(logs);
            updateLogStats(logs, runId);
        });
    }

    // Update the stats bar above the log container.
    function updateLogStats(logs, runId) {
        var errors   = logs.filter(function(e) { return e.level === 'ERROR'; }).length;
        var warnings = logs.filter(function(e) { return e.level === 'WARNING'; }).length;
        var success  = logs.filter(function(e) { return e.level === 'SUCCESS'; }).length;

        $('#ow-log-stat-total').text(logs.length.toLocaleString() + ' entries');
        $('#ow-log-stat-errors').text('✘ ' + errors + ' error' + (errors !== 1 ? 's' : ''));
        $('#ow-log-stat-warnings').text('⚠ ' + warnings + ' warning' + (warnings !== 1 ? 's' : ''));
        $('#ow-log-stat-success').text('✔ ' + success + ' success');
        $('#ow-log-stat-run').text('Run: ' + (runId || '').substr(0, 8) + '…');

        // Highlight stats bar if errors found.
        var $stats = $('#ow-log-stats');
        if (errors > 0) {
            $stats.css('border-color', '#f4474780');
        } else if (warnings > 0) {
            $stats.css('border-color', '#ffcc0280');
        } else {
            $stats.css('border-color', '#2a2d3a');
        }
    }

    /* ════════════════════════════════════════════════════════════════════
       LOG RENDERER — v2.5.5 rebuild
       Dark terminal with:
         - Colour-coded level badges with icons
         - Per-migrator colour-coded labels
         - Syntax highlighting for IDs, SKUs, names, statuses in messages
         - Sticky column header
         - Empty state with guidance
    ════════════════════════════════════════════════════════════════════ */
    var LOG_LEVEL_ICONS = {
        DEBUG:   '·',
        INFO:    'ℹ',
        WARNING: '⚠',
        ERROR:   '✘',
        SUCCESS: '✔',
    };

    var LOG_MIGRATOR_CLASS = {
        products:     'ow-mig-products',
        categories:   'ow-mig-categories',
        images:       'ow-mig-images',
        orders:       'ow-mig-orders',
        customers:    'ow-mig-customers',
        manufacturers:'ow-mig-manufacturers',
        seo:          'ow-mig-seo',
        tags:         'ow-mig-tags',
        filters:      'ow-mig-filters',
        coupons:      'ow-mig-coupons',
        reviews:      'ow-mig-reviews',
        multilingual: 'ow-mig-multilingual',
        information:  'ow-mig-information',
        downloads:    'ow-mig-downloads',
    };

    /**
     * Highlight structured tokens inside a plain-text log message.
     * Converts patterns like:
     *   WC #5043          → <span class="ow-log-id">WC #5043</span>
     *   OC #821           → <span class="ow-log-id">OC #821</span>
     *   SKU: "abc-123"    → <span class="ow-log-sku">SKU: "abc-123"</span>
     *   Name: "My Product" → <span class="ow-log-name">Name: "My Product"</span>
     *   Status: published → <span class="ow-log-status">Status: published</span>
     *   ✔ / ↺ / ↷        → styled icons
     */
    function highlightLogMessage(raw) {
        // Escape HTML first.
        var msg = $('<span>').text(raw).html();

        // ID tokens: WC #NNN, OC #NNN, term #NNN, user #NNN, order #NNN.
        msg = msg.replace(/(WC|OC|term|user|order|post)\s*(#\d+)/g,
            '<span class="ow-log-id">$1 $2</span>');

        // SKU pattern: SKU: xxx or SKU "xxx".
        msg = msg.replace(/\bSKU:\s*([^\s|,<>]+)/g,
            'SKU: <span class="ow-log-sku">$1</span>')

        // Name in quotes: Name: "…" or name: "…".
        msg = msg.replace(/Name:\s*&quot;([^&]*)&quot;/g,
            'Name: <span class="ow-log-name">&quot;$1&quot;</span>');
        msg = msg.replace(/Name:\s*"([^"]*)"/g,
            'Name: <span class="ow-log-name">"$1"</span>');

        // Status: value.
        msg = msg.replace(/Status:\s*(\w+)/g,
            'Status: <span class="ow-log-status">$1</span>');

        // Email: value.
        msg = msg.replace(/Email:\s*([\w.@+\-]+)/g,
            'Email: <span class="ow-log-sku">$1</span>');

        // Total / currency amounts.
        msg = msg.replace(/Total:\s*([\d.,]+\s*\w+)/g,
            'Total: <span class="ow-log-status">$1</span>');

        // Success icon prefix.
        msg = msg.replace(/^(✔|↺|↷)\s/, '<span class="ow-log-ok">$1</span> ');
        // Warning icon prefix.
        msg = msg.replace(/^(⚠)\s/, '<span class="ow-log-warn">$1</span> ');
        // Error icon prefix.
        msg = msg.replace(/^(✘)\s/, '<span class="ow-log-err">$1</span> ');

        return msg;
    }

    function renderLogs(logs) {
        $logContainer.empty();

        if (!logs || !logs.length) {
            $logContainer.html(
                '<div class="ow-log-empty"><span>📋</span>' +
                'No log entries yet. Start a migration to see live output here.</div>'
            );
            return;
        }

        // Sticky column header.
        var header = '<div class="ow-log-header">' +
            '<span>Timestamp</span>' +
            '<span>Level</span>' +
            '<span>Migrator</span>' +
            '<span>Message</span>' +
            '</div>';

        var rows = '';
        logs.forEach(function (entry) {
            var level    = (entry.level    || 'INFO').toUpperCase();
            var migrator = (entry.migrator || 'general').toLowerCase();
            var ts       = (entry.created_at || '').replace('T', ' ').substr(0, 19);
            var icon     = LOG_LEVEL_ICONS[level]    || '·';
            var migCls   = LOG_MIGRATOR_CLASS[migrator] || 'ow-mig-general';
            var msg      = highlightLogMessage( entry.message || '' );

            // Context JSON — show inline if present.
            if (entry.context && entry.context !== 'null' && entry.context !== '{}') {
                try {
                    var ctx = (typeof entry.context === 'string') ? JSON.parse(entry.context) : entry.context;
                    if (ctx && Object.keys(ctx).length) {
                        var ctxParts = [];
                        Object.keys(ctx).forEach(function(k) {
                            ctxParts.push('<span class="ow-log-id">' + $('<s>').text(k).html() + '</span>=' +
                                '<span class="ow-log-sku">' + $('<s>').text(String(ctx[k])).html() + '</span>');
                        });
                        msg += ' <span style="opacity:.5;font-size:10px;">{ ' + ctxParts.join(' ') + ' }</span>';
                    }
                } catch(e) {}
            }

            rows += '<div class="ow-log-entry lvl-' + level + '">' +
                '<span class="ow-log-ts">'       + ts      + '</span>' +
                '<span class="ow-log-level ow-lvl-' + level + '">' + icon + ' ' + level + '</span>' +
                '<span class="ow-log-migrator ' + migCls + '">' + migrator + '</span>' +
                '<span class="ow-log-msg">'      + msg     + '</span>' +
                '</div>';
        });

        $logContainer.html(header + rows);

        // Auto-scroll to bottom to show most recent entries.
        $logContainer.scrollTop($logContainer[0].scrollHeight);
    }

    /* ── Download Logs as .txt file ─────────────────────────────────────── */
    function downloadLogs() {
        var runId = currentRunId || octoWoo.lastRunId || '';
        if (!runId) { showToast('No migration run selected. Start or resume a migration first.', 'warning'); return; }

        var $btn = $('#ow-btn-download-logs');
        $btn.prop('disabled', true).html('<span class="ow-spinner dark"></span>&nbsp; Preparing…');

        $.get(octoWoo.ajaxUrl, { action: 'octowoo_get_logs', nonce: octoWoo.nonce, run_id: runId, limit: 9999 })
        .done(function (res) {
            if (!res.success || !res.data || !res.data.logs) {
                showToast('No logs found for this run.', 'warning');
                return;
            }
            var lines = res.data.logs.map(function (e) {
                return '[' + (e.created_at || '') + '] [' + (e.level || '') + '] ' + (e.migrator ? '[' + e.migrator + '] ' : '') + (e.message || '');
            });
            var blob = new Blob([lines.join('\n')], { type: 'text/plain' });
            var url  = URL.createObjectURL(blob);
            var a    = document.createElement('a');
            a.href     = url;
            a.download = 'octowoo-log-' + runId.substr(0, 8) + '.txt';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            showToast('Log file downloaded.', 'success');
        })
        .fail(function () { showToast('Failed to fetch logs.', 'error'); })
        .always(function () { $btn.prop('disabled', false).text('⬇ Download Logs'); });
    }

    /* ════════════════════════════════════════════════════════════════════
       SETTINGS EXPORT / IMPORT
    ════════════════════════════════════════════════════════════════════ */
    function exportSettings() {
        $.post(octoWoo.ajaxUrl, { action: 'octowoo_export_settings', nonce: octoWoo.nonce })
        .done(function (res) {
            if (!res.success || !res.data || !res.data.config) {
                showToast('Export failed.', 'error');
                return;
            }
            // Strip DB password from export for safety.
            var config = JSON.parse(JSON.stringify(res.data.config));
            if (config.db) { config.db.password = ''; }

            var blob = new Blob([JSON.stringify(config, null, 2)], { type: 'application/json' });
            var url  = URL.createObjectURL(blob);
            var a    = document.createElement('a');
            a.href     = url;
            a.download = 'octowoo-settings.json';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            showToast('Settings exported. Re-enter your DB password after import.', 'success', 6000);
        })
        .fail(function () { showToast('Export request failed.', 'error'); });
    }

    function importSettings(file) {
        var reader = new FileReader();
        reader.onload = function (e) {
            var config;
            try { config = JSON.parse(e.target.result); }
            catch (err) { showToast('Invalid JSON file: ' + err.message, 'error', 0); return; }

            owConfirm('Import these settings? Your current settings will be overwritten. (DB password is not imported — you will need to re-enter it.)', 'Yes, import', 'Cancel')
            .then(function (confirmed) {
                if (!confirmed) { return; }
                $.post(octoWoo.ajaxUrl, {
                    action: 'octowoo_import_settings',
                    nonce:  octoWoo.nonce,
                    config: JSON.stringify(config),
                })
                .done(function (res) {
                    if (res.success) {
                        showToast('Settings imported. Reloading page…', 'success', 2000);
                        setTimeout(function () { location.reload(); }, 2200);
                    } else {
                        showToast(res.data ? res.data.message : 'Import failed.', 'error', 0);
                    }
                })
                .fail(function () { showToast('Import request failed.', 'error'); });
            });
        };
        reader.readAsText(file);
    }

    /* ════════════════════════════════════════════════════════════════════
       PURGE AUDIT — safety check before deletion
    ════════════════════════════════════════════════════════════════════ */
    function auditPurge() {
        var entities = [];
        $('.ow-purge-chk:checked').each(function () { entities.push($(this).val()); });
        if (!entities.length) { showToast('Select at least one entity type to audit.', 'warning'); return; }

        var force = $('#ow-purge-force').is(':checked');
        var $panel = $('#ow-purge-audit-panel');
        var $btn   = $('#ow-btn-audit-purge');

        $btn.prop('disabled', true).html('<span class="ow-spinner dark"></span>&nbsp; Auditing…');
        $panel.html('<em style="color:#888;">Counting items…</em>').show();

        $.post(octoWoo.ajaxUrl, {
            action:   'octowoo_audit_purge',
            nonce:    octoWoo.nonce,
            entities: entities,
            force:    force ? 1 : 0,
        })
        .done(function (res) {
            if (!res || !res.success || !res.data || !res.data.audit) {
                $panel.html('<span style="color:#c62828;">Audit failed: ' + ((res && res.data && res.data.message) || 'unknown error') + '</span>');
                return;
            }

            var audit = res.data.audit;
            var isForce = !!res.data.force;
            var html = '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:10px;">';
            html += '<thead><tr style="background:#f5f5f5;">';
            html += '<th style="text-align:left;padding:5px 8px;border:1px solid #ddd;">Entity</th>';
            html += '<th style="padding:5px 8px;border:1px solid #ddd;">OctoWoo items</th>';
            html += '<th style="padding:5px 8px;border:1px solid #ddd;">Total in WC</th>';
            html += '<th style="padding:5px 8px;border:1px solid #ddd;">Will delete</th>';
            html += '<th style="padding:5px 8px;border:1px solid #ddd;">Extra (non-OctoWoo)</th>';
            html += '</tr></thead><tbody>';

            var totalWillDelete = 0;
            var hasWarnings = false;

            $.each(audit, function(entity, info) {
                var willDelete = info.will_delete || 0;
                var extra      = info.extra_count  || 0;
                totalWillDelete += willDelete;
                if (extra > 0) hasWarnings = true;

                var safeIcon = info.safe ? '✔' : '⚠';
                var safeColor = info.safe ? '#2e7d32' : '#e65100';
                var extraCell = extra > 0
                    ? '<span style="color:#c62828;font-weight:700;">+' + extra + ' (non-OctoWoo!)</span>'
                    : '<span style="color:#2e7d32;">0</span>';

                html += '<tr>';
                html += '<td style="padding:4px 8px;border:1px solid #ddd;font-weight:600;">' + entity + '</td>';
                html += '<td style="padding:4px 8px;border:1px solid #ddd;text-align:center;">' + (info.tagged_count || 0).toLocaleString() + '</td>';
                html += '<td style="padding:4px 8px;border:1px solid #ddd;text-align:center;">' + (info.total_count || 0).toLocaleString() + '</td>';
                html += '<td style="padding:4px 8px;border:1px solid #ddd;text-align:center;color:' + safeColor + ';font-weight:700;">' + safeIcon + ' ' + willDelete.toLocaleString() + '</td>';
                html += '<td style="padding:4px 8px;border:1px solid #ddd;text-align:center;">' + extraCell + '</td>';
                html += '</tr>';

                if (info.warnings && info.warnings.length) {
                    info.warnings.forEach(function(w) {
                        html += '<tr><td colspan="5" style="padding:3px 8px 3px 20px;border:1px solid #ddd;background:#fffbeb;color:#b45309;font-size:11px;">ℹ ' + $('<span>').text(w).html() + '</td></tr>';
                    });
                }
            });

            html += '</tbody></table>';

            var summaryColor = hasWarnings ? '#c62828' : '#2e7d32';
            var summaryText = hasWarnings
                ? '⚠ ' + totalWillDelete.toLocaleString() + ' items will be deleted — some are NOT OctoWoo items. Review the Extra column carefully before proceeding.'
                : '✔ ' + totalWillDelete.toLocaleString() + ' OctoWoo item(s) will be deleted. No non-OctoWoo data will be affected.';

            html += '<div style="padding:8px 10px;border-radius:4px;background:' + (hasWarnings ? '#fef2f2' : '#edf7ed') + ';border:1px solid ' + summaryColor + ';color:' + summaryColor + ';font-size:12px;font-weight:600;">' + summaryText + '</div>';

            $panel.html(html);
        })
        .fail(function () { $panel.html('<span style="color:#c62828;">Audit request failed.</span>'); })
        .always(function () { $btn.prop('disabled', false).text('🔍 Audit Before Purge'); });
    }

    /* ════════════════════════════════════════════════════════════════════
       PURGE
    ════════════════════════════════════════════════════════════════════ */
    function runPurge(forceTagged) {
        var entities = [];
        $('.ow-purge-chk:checked').each(function () { entities.push($(this).val()); });
        if (!entities.length) { showToast('Select at least one entity type to purge.', 'warning'); return; }

        var forcePurge = forceTagged || $('#ow-purge-force').is(':checked');
        var confirmMsg = forcePurge
            ? '⚠ Force purge: deletes ALL ' + entities.join(', ') + ' in WooCommerce, including items NOT created by OctoWoo. Continue?'
            : 'Delete ' + entities.join(', ') + ' created by OctoWoo? Continue?';

        owConfirm(confirmMsg, 'Yes, purge', 'Cancel')
        .then(function (confirmed) {
            if (!confirmed) { return; }
            _executePurge(entities, forcePurge, false);
        });
    }

    function runPurgeEverything() {
        owConfirm('☢ FORCE PURGE: Delete ALL products, categories, customers, orders and other WooCommerce data — including items NOT created by OctoWoo. This is irreversible. Are you absolutely sure?', 'Yes, purge EVERYTHING', 'Cancel')
        .then(function (confirmed) {
            if (!confirmed) { return; }
            var all = ['products', 'categories', 'manufacturers', 'customers', 'orders', 'coupons', 'tags', 'filters', 'downloads', 'reviews', 'information'];
            _executePurge(all, true, true);
        });
    }

    function _executePurge(entities, force, everything) {
        var $btn = everything ? $('#ow-btn-purge-everything') : $('#ow-btn-purge');
        $btn.prop('disabled', true).html('<span class="ow-spinner dark"></span>&nbsp; Purging…');
        var $result = $('#ow-purge-result');
        $result.html('<em style="color:#888;">Purging…</em>').show();

        $.post(octoWoo.ajaxUrl, {
            action:    'octowoo_purge_imported',
            nonce:     octoWoo.nonce,
            entities:  entities.join(','),
            force:     force ? 1 : 0,
        })
        .done(function (res) {
            if (res.success) {
                var data    = res.data || {};
                var deleted = data.deleted || 0;
                var msg     = 'Purge complete. ' + parseInt(deleted).toLocaleString() + ' item(s) deleted.';
                $result.html('<span style="color:#2e7d32;font-weight:600;">✔ ' + msg + '</span>');
                showToast(msg, 'success', 6000);

                if (data.warnings && data.warnings.length) {
                    var warnHtml = '<ul style="margin:6px 0 0;padding-left:18px;">';
                    data.warnings.forEach(function (w) { warnHtml += '<li style="color:#e65100;font-size:12px;">' + $('<span>').text(w).html() + '</li>'; });
                    warnHtml += '</ul>';
                    $result.append(warnHtml);
                }
            } else {
                var errMsg = res.data ? res.data.message : 'Purge failed.';
                $result.html('<span style="color:#c62828;">✘ ' + $('<span>').text(errMsg).html() + '</span>');
                showToast(errMsg, 'error', 0);
            }
        })
        .fail(function (xhr) {
            $result.html('<span style="color:#c62828;">✘ Request failed: ' + xhr.statusText + '</span>');
        })
        .always(function () { $btn.prop('disabled', false).text($btn.data('label') || 'Purge'); });
    }

    /* ════════════════════════════════════════════════════════════════════
       SETTINGS VALIDATION
    ════════════════════════════════════════════════════════════════════ */
    function validateSettingsForm() {
        var valid = true;
        $('#octowoo-settings-form [required]').each(function () {
            var empty = !$(this).val().trim();
            $(this).toggleClass('ow-field-error', empty);
            if (empty) { valid = false; }
        });
        $('#ow-btn-save-settings').prop('disabled', !valid);
    }

    /* ════════════════════════════════════════════════════════════════════
       UTILITY
    ════════════════════════════════════════════════════════════════════ */
    function ucFirst(str) {
        return str.charAt(0).toUpperCase() + str.slice(1);
    }

    /* ════════════════════════════════════════════════════════════════════
       CRON MANAGEMENT (v2.5.0)
    ════════════════════════════════════════════════════════════════════ */
    window.owRunCronNow = function () {
        var $btn = document.getElementById('ow-btn-cron-run-now');
        if (!$btn) { return; }
        $btn.disabled = true;
        $btn.textContent = '⏳ Running…';

        $.post(octoWoo.ajaxUrl, { action: 'octowoo_run_cron_now', nonce: octoWoo.nonce })
        .done(function (res) {
            if (res && res.success) {
                showToast((res.data && res.data.message) || 'Cron migration triggered.', 'success', 5000);
                // Reload page after short delay to refresh cron status widget.
                setTimeout(function () { location.reload(); }, 2500);
            } else {
                showToast((res && res.data && res.data.message) || 'Cron run failed.', 'error');
            }
        })
        .fail(function () { showToast('Cron request failed.', 'error'); })
        .always(function () {
            if ($btn) { $btn.disabled = false; $btn.textContent = '▶ Run Now'; }
        });
    };

    /* ════════════════════════════════════════════════════════════════════
       BOOT
    ════════════════════════════════════════════════════════════════════ */
    $(document).ready(init);

}(jQuery));

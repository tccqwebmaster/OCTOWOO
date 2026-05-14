<?php
/**
 * OctoWoo – First-run onboarding wizard.
 *
 * Shown on first activation (no octowoo_config saved yet).
 * Rendered as a fixed-position full-screen overlay from within admin-dashboard.php.
 *
 * Steps:
 *  1 — Source mode (Remote DB vs Local SQL Import)
 *  2 — Database credentials + inline Test Connection
 *  3 — System Check + Finish
 *
 * @package OctoWoo
 */

defined( 'ABSPATH' ) || exit;

$show_wizard = empty( get_option( 'octowoo_config', [] ) );
if ( ! $show_wizard ) { return; }
?>
<div id="ow-wizard-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:100000;display:flex;align-items:center;justify-content:center;" role="dialog" aria-modal="true" aria-labelledby="ow-wizard-title">

    <div id="ow-wizard-box" style="background:#fff;border-radius:12px;width:560px;max-width:95vw;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.3);">

        <!-- Header -->
        <div style="background:linear-gradient(135deg,#6c4fd4,#9b72ef);padding:24px 28px;color:#fff;">
            <div style="display:flex;align-items:center;gap:14px;margin-bottom:4px;">
                <span style="font-size:28px;">🐙</span>
                <div>
                    <h1 id="ow-wizard-title" style="margin:0;font-size:20px;font-weight:600;"><?php esc_html_e( 'Welcome to OctoWoo!', 'octowoo' ); ?></h1>
                    <p style="margin:3px 0 0;font-size:13px;opacity:.85;"><?php esc_html_e( 'OpenCart → WooCommerce Migration', 'octowoo' ); ?></p>
                </div>
            </div>
            <!-- Step dots -->
            <div id="ow-wizard-dots" style="display:flex;gap:8px;margin-top:16px;">
                <div class="ow-wz-dot active" data-step="1" style="width:28px;height:6px;border-radius:3px;background:rgba(255,255,255,.9);"></div>
                <div class="ow-wz-dot" data-step="2" style="width:28px;height:6px;border-radius:3px;background:rgba(255,255,255,.35);"></div>
                <div class="ow-wz-dot" data-step="3" style="width:28px;height:6px;border-radius:3px;background:rgba(255,255,255,.35);"></div>
            </div>
        </div>

        <!-- Steps container -->
        <div style="flex:1;overflow-y:auto;padding:28px;">

            <!-- ── STEP 1: Source Mode ──────────────────────────────── -->
            <div id="ow-wz-step-1" class="ow-wz-step" style="display:block;">
                <h2 style="margin:0 0 8px;font-size:16px;"><?php esc_html_e( 'Step 1 — How to read your OpenCart data', 'octowoo' ); ?></h2>
                <p style="margin:0 0 20px;font-size:13px;color:#555;"><?php esc_html_e( 'Choose how OctoWoo will connect to your OpenCart store.', 'octowoo' ); ?></p>

                <div id="ow-wz-mode-remote" onclick="owWzSelectMode('remote')" style="border:2px solid #7952b3;border-radius:8px;padding:16px;margin-bottom:12px;cursor:pointer;transition:.15s;" class="ow-wz-mode-card selected">
                    <div style="display:flex;align-items:flex-start;gap:12px;">
                        <span style="font-size:24px;flex-shrink:0;">🌐</span>
                        <div>
                            <div style="font-weight:600;font-size:14px;color:#1d2327;"><?php esc_html_e( 'Remote / Live Database', 'octowoo' ); ?></div>
                            <div style="font-size:12px;color:#555;margin-top:4px;"><?php esc_html_e( 'OctoWoo connects directly to your OpenCart database. Best when your WooCommerce server can reach the OpenCart database host.', 'octowoo' ); ?></div>
                        </div>
                    </div>
                </div>

                <div id="ow-wz-mode-local" onclick="owWzSelectMode('local')" style="border:2px solid #ddd;border-radius:8px;padding:16px;cursor:pointer;transition:.15s;" class="ow-wz-mode-card">
                    <div style="display:flex;align-items:flex-start;gap:12px;">
                        <span style="font-size:24px;flex-shrink:0;">📂</span>
                        <div>
                            <div style="font-weight:600;font-size:14px;color:#1d2327;"><?php esc_html_e( 'Local Import (Upload SQL File)', 'octowoo' ); ?></div>
                            <div style="font-size:12px;color:#555;margin-top:4px;"><?php esc_html_e( 'Upload a SQL dump of your OpenCart database. Best for remote/Cloudways servers or when the databases are behind a firewall.', 'octowoo' ); ?></div>
                        </div>
                    </div>
                </div>

                <input type="hidden" id="ow-wz-source" value="remote">
            </div>

            <!-- ── STEP 2: DB Credentials ──────────────────────────── -->
            <div id="ow-wz-step-2" class="ow-wz-step" style="display:none;">
                <h2 style="margin:0 0 8px;font-size:16px;"><?php esc_html_e( 'Step 2 — OpenCart Database Connection', 'octowoo' ); ?></h2>
                <p id="ow-wz-step2-desc" style="margin:0 0 20px;font-size:13px;color:#555;"><?php esc_html_e( 'Enter your OpenCart database credentials. You can skip this step if using Local Import.', 'octowoo' ); ?></p>

                <div id="ow-wz-db-fields">
                    <?php
                    $wz_fields = [
                        [ 'id' => 'ow-wz-host',   'label' => __( 'Host', 'octowoo' ),          'placeholder' => '13.206.54.252', 'type' => 'text' ],
                        [ 'id' => 'ow-wz-port',   'label' => __( 'Port', 'octowoo' ),          'placeholder' => '3306',          'type' => 'number' ],
                        [ 'id' => 'ow-wz-dbname', 'label' => __( 'Database Name', 'octowoo' ), 'placeholder' => 'opencart_db',   'type' => 'text' ],
                        [ 'id' => 'ow-wz-user',   'label' => __( 'Username', 'octowoo' ),      'placeholder' => 'db_user',       'type' => 'text' ],
                        [ 'id' => 'ow-wz-pass',   'label' => __( 'Password', 'octowoo' ),      'placeholder' => '',              'type' => 'password' ],
                        [ 'id' => 'ow-wz-prefix', 'label' => __( 'Table Prefix', 'octowoo' ),  'placeholder' => 'oc_',           'type' => 'text' ],
                    ];
                    foreach ( $wz_fields as $f ) :
                        ?>
                        <div style="margin-bottom:12px;">
                            <label for="<?php echo esc_attr( $f['id'] ); ?>" style="display:block;font-size:12px;font-weight:600;color:#444;margin-bottom:4px;"><?php echo esc_html( $f['label'] ); ?></label>
                            <input type="<?php echo esc_attr( $f['type'] ); ?>" id="<?php echo esc_attr( $f['id'] ); ?>"
                                   placeholder="<?php echo esc_attr( $f['placeholder'] ); ?>"
                                   style="width:100%;padding:7px 10px;border:1px solid #ddd;border-radius:5px;font-size:13px;box-sizing:border-box;">
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top:16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <button type="button" id="ow-wz-test-btn" onclick="owWzTestConnection()" class="ow-btn ow-btn-secondary" style="font-size:12px;padding:6px 14px;">
                        🔌 <?php esc_html_e( 'Test Connection', 'octowoo' ); ?>
                    </button>
                    <span id="ow-wz-test-result" style="font-size:12px;"></span>
                </div>
            </div>

            <!-- ── STEP 3: System Check + Finish ──────────────────── -->
            <div id="ow-wz-step-3" class="ow-wz-step" style="display:none;">
                <h2 style="margin:0 0 8px;font-size:16px;"><?php esc_html_e( 'Step 3 — System Check', 'octowoo' ); ?></h2>
                <p style="margin:0 0 20px;font-size:13px;color:#555;"><?php esc_html_e( "We'll verify your server is ready. You can also skip this and run it later.", 'octowoo' ); ?></p>

                <div id="ow-wz-check-result" style="margin-bottom:16px;min-height:60px;font-size:12px;"></div>

                <button type="button" onclick="owWzRunCheck()" class="ow-btn ow-btn-secondary" style="font-size:12px;padding:6px 14px;">
                    🔎 <?php esc_html_e( 'Run System Check', 'octowoo' ); ?>
                </button>

                <div style="margin-top:20px;padding:14px;background:#f0f6fc;border-left:4px solid #2271b1;border-radius:4px;font-size:12px;color:#444;">
                    <strong><?php esc_html_e( "You're all set!", 'octowoo' ); ?></strong>
                    <?php esc_html_e( 'Click Finish to save your settings and go to the Migration tab where you can start migrating.', 'octowoo' ); ?>
                </div>
            </div>

        </div><!-- /steps container -->

        <!-- Footer navigation -->
        <div style="padding:16px 28px;border-top:1px solid #eee;display:flex;justify-content:space-between;align-items:center;background:#fafafa;">
            <button type="button" id="ow-wz-skip" onclick="owWzSkip()" style="background:none;border:none;color:#888;font-size:12px;cursor:pointer;padding:4px 8px;"><?php esc_html_e( 'Skip setup wizard', 'octowoo' ); ?></button>
            <div style="display:flex;gap:10px;">
                <button type="button" id="ow-wz-prev" onclick="owWzStep(-1)" class="ow-btn ow-btn-secondary" style="display:none;font-size:13px;"><?php esc_html_e( '← Back', 'octowoo' ); ?></button>
                <button type="button" id="ow-wz-next" onclick="owWzStep(1)" class="ow-btn ow-btn-primary" style="font-size:13px;"><?php esc_html_e( 'Continue →', 'octowoo' ); ?></button>
                <button type="button" id="ow-wz-finish" onclick="owWzFinish()" class="ow-btn ow-btn-primary" style="display:none;font-size:13px;background:#2e7d32;border-color:#2e7d32;"><?php esc_html_e( '✔ Finish & Start Migrating', 'octowoo' ); ?></button>
            </div>
        </div>

    </div><!-- /wizard box -->
</div><!-- /wizard overlay -->

<script>
(function() {
    var step = 1;
    var totalSteps = 3;

    function goTo(n) {
        step = Math.max(1, Math.min(totalSteps, n));
        document.querySelectorAll('.ow-wz-step').forEach(function(el) { el.style.display = 'none'; });
        var s = document.getElementById('ow-wz-step-' + step);
        if (s) s.style.display = 'block';

        document.querySelectorAll('.ow-wz-dot').forEach(function(dot) {
            dot.style.background = parseInt(dot.dataset.step) <= step ? 'rgba(255,255,255,.9)' : 'rgba(255,255,255,.35)';
        });

        document.getElementById('ow-wz-prev').style.display   = step > 1 ? '' : 'none';
        document.getElementById('ow-wz-next').style.display   = step < totalSteps ? '' : 'none';
        document.getElementById('ow-wz-finish').style.display = step === totalSteps ? '' : 'none';

        // Skip DB fields if local mode.
        if (step === 2) {
            var isLocal = document.getElementById('ow-wz-source').value === 'local';
            document.getElementById('ow-wz-db-fields').style.opacity = isLocal ? '.5' : '1';
            document.getElementById('ow-wz-db-fields').style.pointerEvents = isLocal ? 'none' : '';
        }
    }

    window.owWzStep = function(dir) { goTo(step + dir); };

    window.owWzSelectMode = function(mode) {
        document.getElementById('ow-wz-source').value = mode;
        document.querySelectorAll('.ow-wz-mode-card').forEach(function(c) {
            c.style.borderColor = '#ddd';
        });
        document.getElementById('ow-wz-mode-' + mode).style.borderColor = '#7952b3';
    };

    window.owWzTestConnection = function() {
        var $btn = document.getElementById('ow-wz-test-btn');
        var $res = document.getElementById('ow-wz-test-result');
        $btn.disabled = true;
        $btn.textContent = '⏳ Testing…';
        $res.textContent = '';
        $res.style.color = '#555';

        var data = new URLSearchParams({
            action:    'octowoo_test_connection',
            nonce:     (window.octoWoo && octoWoo.nonce) || '',
            _wizard:   '1',
            // Key names match what AjaxHandler::actionTestConnection() expects.
            db_host:   document.getElementById('ow-wz-host').value,
            db_port:   document.getElementById('ow-wz-port').value   || '3306',
            db_name:   document.getElementById('ow-wz-dbname').value,
            db_user:   document.getElementById('ow-wz-user').value,
            db_pass:   document.getElementById('ow-wz-pass').value,
            db_prefix: document.getElementById('ow-wz-prefix').value || 'oc_',
        });

        fetch((window.octoWoo && octoWoo.ajaxUrl) || ajaxurl, { method: 'POST', body: data })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                $res.style.color = '#2e7d32';
                $res.textContent = '✔ ' + ((res.data && res.data.message) || 'Connection successful');
            } else {
                $res.style.color = '#c62828';
                $res.textContent = '✘ ' + ((res.data && res.data.message) || 'Connection failed');
            }
        })
        .catch(function() { $res.textContent = '✘ Request failed'; $res.style.color = '#c62828'; })
        .finally(function() { $btn.disabled = false; $btn.textContent = '🔌 Test Connection'; });
    };

    window.owWzRunCheck = function() {
        var $res = document.getElementById('ow-wz-check-result');
        $res.innerHTML = '<em style="color:#888;">Running checks…</em>';

        fetch((window.octoWoo && octoWoo.ajaxUrl) || ajaxurl, {
            method: 'POST',
            body: new URLSearchParams({ action: 'octowoo_validate', nonce: (window.octoWoo && octoWoo.nonce) || '' })
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success || !res.data) { $res.innerHTML = '<span style="color:#c62828;">Check failed.</span>'; return; }
            var html = '<table style="font-size:11px;border-collapse:collapse;width:100%;">';
            var colors = { pass: '#2e7d32', warning: '#e65100', fail: '#c62828' };
            var icons  = { pass: '✔', warning: '⚠', fail: '✘' };
            Object.entries(res.data.results || {}).forEach(function(e) {
                var k = e[0], v = e[1]; var s = v.status || 'pass';
                html += '<tr><td style="padding:3px 8px 3px 0;color:#555;font-size:11px;">' + k.replace(/_/g,' ') + '</td>';
                html += '<td style="color:' + (colors[s]||'#333') + ';font-weight:700;">' + (icons[s]||'·') + ' ' + s.toUpperCase() + '</td>';
                html += '<td style="color:#666;padding-left:8px;">' + (v.message||'') + '</td></tr>';
            });
            html += '</table>';
            $res.innerHTML = html;
        })
        .catch(function() { $res.innerHTML = '<span style="color:#c62828;">Check request failed.</span>'; });
    };

    window.owWzFinish = function() {
        // Save settings from wizard fields, then close.
        var payload = {
            action: 'octowoo_import_settings',
            nonce:  (window.octoWoo && octoWoo.nonce) || '',
            config: JSON.stringify({
                source: document.getElementById('ow-wz-source').value,
                db: {
                    host:     document.getElementById('ow-wz-host')   ? document.getElementById('ow-wz-host').value   : '',
                    port:     document.getElementById('ow-wz-port')   ? parseInt(document.getElementById('ow-wz-port').value) || 3306 : 3306,
                    database: document.getElementById('ow-wz-dbname') ? document.getElementById('ow-wz-dbname').value : '',
                    username: document.getElementById('ow-wz-user')   ? document.getElementById('ow-wz-user').value   : '',
                    password: document.getElementById('ow-wz-pass')   ? document.getElementById('ow-wz-pass').value   : '',
                    prefix:   document.getElementById('ow-wz-prefix') ? document.getElementById('ow-wz-prefix').value : 'oc_',
                },
            }),
        };

        fetch((window.octoWoo && octoWoo.ajaxUrl) || ajaxurl, {
            method: 'POST',
            body: new URLSearchParams(payload)
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            document.getElementById('ow-wizard-overlay').remove();
            // Navigate to the migration tab.
            var url = new URL(window.location.href);
            url.searchParams.set('tab', 'migration');
            url.searchParams.set('wizard_done', '1');
            window.location.href = url.toString();
        })
        .catch(function() {
            document.getElementById('ow-wizard-overlay').remove();
        });
    };

    window.owWzSkip = function() {
        // Mark wizard as skipped so it doesn't show again.
        fetch((window.octoWoo && octoWoo.ajaxUrl) || ajaxurl, {
            method: 'POST',
            body: new URLSearchParams({
                action: 'octowoo_import_settings',
                nonce:  (window.octoWoo && octoWoo.nonce) || '',
                config: JSON.stringify({ _wizard_skipped: true }),
            })
        });
        document.getElementById('ow-wizard-overlay').remove();
    };

    // Boot.
    goTo(1);
})();
</script>

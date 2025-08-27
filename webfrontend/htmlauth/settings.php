<?php
require_once "loxberry_web.php";
require_once "loxberry_system.php";
require_once "settings.inc.php";

session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// Navbar
$navbar = [];
$navbar[1]['Name']   = 'Overzicht';
$navbar[1]['URL']    = 'index.php';
$navbar[2]['Name']   = 'Logs';
$navbar[2]['URL']    = 'logs.php';
$navbar[3]['Name']   = 'Settings';
$navbar[3]['URL']    = 'settings.php';
$navbar[3]['active'] = True;

// Flash helpers
function flash($msg, $type='info') { $_SESSION['flash'][] = ['msg'=>$msg,'type'=>$type]; }
function show_flashes() {
    if (!empty($_SESSION['flash'])) {
        foreach ($_SESSION['flash'] as $f) {
            $cls = $f['type'] === 'error' ? 'flash-error' : ($f['type'] === 'success' ? 'flash-success' : 'flash-info');
            echo "<div class='flash $cls'>" . htmlspecialchars($f['msg']) . "</div>";
        }
        unset($_SESSION['flash']);
    }
}

// Load settings/meta
$settings = sh_settings_load($settingsFile, $dataDir);
$meta = [];
if (is_readable($metaFile)) {
    $j = json_decode(@file_get_contents($metaFile), true);
    if (is_array($j)) $meta = $j;
}

// Load current cron file content (fallback to preview if target not readable)
$cron_target  = $settings['paths']['cron_target'];
$cron_preview = $settings['paths']['cron_preview'];
$cron_text = '';
if (is_readable($cron_target)) {
    $cron_text = (string)@file_get_contents($cron_target);
} elseif (is_readable($cron_preview)) {
    $cron_text = (string)@file_get_contents($cron_preview);
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        flash("Invalid request token.", "error");
        header("Location: settings.php"); exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        // Sanitize inputs
        $script_dir   = rtrim($_POST['script_dir']   ?? $settings['paths']['script_dir'], '/');
        $log_file     = $_POST['log_file']     ?? $settings['paths']['log_file'];
        $lock_dir     = rtrim($_POST['lock_dir']     ?? $settings['paths']['lock_dir'], '/');
        $cron_preview = $_POST['cron_preview'] ?? $settings['paths']['cron_preview'];
        $cron_target  = $_POST['cron_target']  ?? $settings['paths']['cron_target'];

        // Ensure dirs
        if (!is_dir($script_dir)) @mkdir($script_dir, 0777, true);
        if (!is_dir($script_dir) || !is_readable($script_dir)) {
            flash("Script directory is not accessible: $script_dir", "error");
            header("Location: settings.php"); exit;
        }
        // Persist
        $settings['paths']['script_dir']   = $script_dir;
        $settings['paths']['log_file']     = $log_file;
        $settings['paths']['lock_dir']     = $lock_dir;
        $settings['paths']['cron_preview'] = $cron_preview;
        $settings['paths']['cron_target']  = $cron_target;

        sh_settings_save($settingsFile, $settings);

        // Touch dirs/files and relax perms
        if (!is_dir($lock_dir)) @mkdir($lock_dir, 0777, true);
        @chmod($lock_dir, 0777);
        if (!file_exists($log_file)) @touch($log_file);
        @chmod($log_file, 0666);

        flash("Settings saved.", "success");
        header("Location: settings.php"); exit;
    }

    if ($action === 'apply_cron') {
        list($ok,$msg) = sh_apply_cron($meta, $settings);
        flash(($ok?"✅ ":"⚠️ ").$msg, $ok?"success":"error");
        header("Location: settings.php"); exit;
    }

    if ($action === 'repair_perms') {
        $lock_dir = $settings['paths']['lock_dir'];
        $log_file = $settings['paths']['log_file'];
        if (!is_dir($lock_dir)) @mkdir($lock_dir, 0777, true);
        @chmod($lock_dir, 0777);
        if (!file_exists($log_file)) @touch($log_file);
        @chmod($log_file, 0666);
        @chown($lock_dir, 'loxberry'); @chgrp($lock_dir, 'loxberry');
        @chown($log_file, 'loxberry'); @chgrp($log_file, 'loxberry');
        flash("Permissions repaired for lock dir and log file.", "success");
        header("Location: settings.php"); exit;
    }

    if ($action === 'create_test') {
        $script_dir = $settings['paths']['script_dir'];
        $test = $script_dir . '/scripthub_hello.sh';
        if (!file_exists($test)) {
            $content = "#!/bin/bash\n" .
                       'echo "$(date -Iseconds) Hello from Scripthub test."' . "\n" .
                       "sleep 1\n";
            @file_put_contents($test, $content);
            @chmod($test, 0755);
            flash("Created $test", "success");
        } else {
            flash("Test script already exists: $test", "info");
        }
        header("Location: settings.php"); exit;
    }

    if ($action === 'rotate_log') {
        $log = $settings['paths']['log_file'];
        if (file_exists($log)) {
            @rename($log, $log . ".1");
            @touch($log);
            @chmod($log, 0666);
            flash("Rotated log to " . basename($log) . ".1", "success");
        } else {
            @touch($log); @chmod($log, 0666);
            flash("Log file created.", "success");
        }
        header("Location: settings.php"); exit;
    }

    if ($action === 'save_cronfile') {
        $newContent = $_POST['cron_text'] ?? '';
        // Ensure newline at end (cron sometimes ignores last line otherwise)
        if ($newContent !== '' && substr($newContent, -1) !== "\n") {
            $newContent .= "\n";
        }
        $target  = $settings['paths']['cron_target'];
        $preview = $settings['paths']['cron_preview'];

        // Try direct write
        $ok = (@file_put_contents($target, $newContent) !== false);
        if ($ok) {
            @chmod($target, 0644);
        } else {
            // sudo fallback
            $tmp = tempnam(sys_get_temp_dir(), 'scripthub_cron_edit_');
            @file_put_contents($tmp, $newContent);
            $cmd = 'sudo /bin/cp ' . escapeshellarg($tmp) . ' ' . escapeshellarg($target)
                 . ' && sudo /bin/chmod 644 ' . escapeshellarg($target)
                 . ' && sudo /bin/chown root:root ' . escapeshellarg($target);
            $out = []; $rc = 0;
            @exec($cmd, $out, $rc);
            @unlink($tmp);
            $ok = ($rc === 0);
        }

        if ($ok) {
            @exec('systemctl reload cron 2>/dev/null || service cron reload 2>/dev/null || true');
            flash("Cron file saved to $target and cron reloaded.", "success");
        } else {
            // Always preserve a preview for manual copy
            @file_put_contents($preview, $newContent);
            flash("Could not write $target. Your changes were saved to preview at $preview. Copy manually and reload cron.", "error");
        }

        header("Location: settings.php"); exit;
    }
}

LBWeb::lbheader("scripthub — Settings", "http://www.loxwiki.eu:80/x/2wzL", "help.html");
?>
<style>
.flash { padding:10px; margin:10px 0; border-radius:6px; }
.flash-info { background:#eef5ff; border:1px solid #bcd4ff; }
.flash-success { background:#eaffea; border:1px solid #b6e3b6; }
.flash-error { background:#ffecec; border:1px solid #ffb9b9; }
.lb-section { background:#fff; border:1px solid #ddd; border-radius:8px; padding:16px; margin:16px 0; }
.lb-section h3 { margin:0 0 10px 0; }
label { display:block; margin-top:10px; font-weight:600; }
input[type="text"] { width:100%; padding:8px; border:1px solid #ccc; border-radius:6px; }
.btn { padding:8px 14px; border:none; border-radius:6px; cursor:pointer; }
.btn-primary { background:#007BFF; color:#fff; }
.btn-ghost { background:#f8f9fa; border:1px solid #cfd3d7; color:#333; }
.btn-danger { background:#dc3545; color:#fff; }
.btn-cancel { background:#6c757d; color:#fff; }
.row { display:flex; gap:12px; flex-wrap:wrap; }
.col { flex:1 1 320px; }
.small { color:#666; font-size:12px; }
pre.codeview {
    background:#fff; color:#000;
    padding:10px; border-radius:6px; overflow:auto; max-height:420px;
    font-family:monospace; font-size:13px; border:1px solid #ccc;
}
textarea.code { width:100%; min-height:260px; font-family:monospace; font-size:13px; }
</style>

<?php show_flashes(); ?>

<div class="lb-section">
  <h3>Paths</h3>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
    <input type="hidden" name="action" value="save_settings">
    <div class="row">
      <div class="col">
        <label>Script directory</label>
        <input type="text" name="script_dir" value="<?= htmlspecialchars($settings['paths']['script_dir']) ?>">
        <div class="small">Default: <code><?= htmlspecialchars($dataDir) ?></code></div>
      </div>
      <div class="col">
        <label>Log file</label>
        <input type="text" name="log_file" value="<?= htmlspecialchars($settings['paths']['log_file']) ?>">
        <div class="small">Start/Finish and script output append here.</div>
      </div>
    </div>
    <div class="row">
      <div class="col">
        <label>Cron preview file</label>
        <input type="text" name="cron_preview" value="<?= htmlspecialchars($settings['paths']['cron_preview']) ?>">
        <div class="small">Generated on every apply; good for inspection/backups.</div>
      </div>
      <div class="col">
        <label>Cron target file (applied)</label>
        <input type="text" name="cron_target" value="<?= htmlspecialchars($settings['paths']['cron_target']) ?>">
        <div class="small">For system cron use <code>/etc/cron.d/scripthub</code>. You can point elsewhere for testing.</div>
      </div>
    </div>
    <div class="row">
      <div class="col">
        <label>Lock directory</label>
        <input type="text" name="lock_dir" value="<?= htmlspecialchars($settings['paths']['lock_dir']) ?>">
        <div class="small">Used by <code>flock</code>. Must be writable by <code>loxberry</code>.</div>
      </div>
    </div>
    <div style="margin-top:12px;">
      <button class="btn btn-primary" type="submit">Save settings</button>
    </div>
  </form>
</div>

<div class="lb-section">
  <h3>Maintenance</h3>

  <div class="row">
    <div class="col">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="apply_cron">
        <button class="btn btn-ghost" type="submit">Apply / Repair cron now</button>
      </form>
      <div class="small">Regenerates cron from schedules and writes <code><?= htmlspecialchars($settings['paths']['cron_target']) ?></code>. Also reloads cron.</div>
    </div>
    <div class="col">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="repair_perms">
        <button class="btn btn-ghost" type="submit">Repair permissions</button>
      </form>
      <div class="small">Fix perms on lock dir and log file for the <code>loxberry</code> user.</div>
    </div>
  </div>

  <div class="row" style="margin-top:10px;">
    <div class="col">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="create_test">
        <button class="btn btn-ghost" type="submit">Create "Hello world" test script</button>
      </form>
      <div class="small">Creates <code>scripthub_hello.sh</code> in your script directory.</div>
    </div>
    <div class="col">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="rotate_log">
        <button class="btn btn-ghost" type="submit">Rotate log</button>
      </form>
      <div class="small">Moves the current log to <code>.1</code> and starts a new one.</div>
    </div>
  </div>

  <!-- Cron file editor -->
  <div style="margin-top:16px;">
    <h4 style="margin:0 0 8px 0;">Cron file (<?= htmlspecialchars($settings['paths']['cron_target']) ?>)</h4>

    <!-- View mode -->
    <div id="cron-view-wrap">
      <pre class="codeview"><code><?= htmlspecialchars($cron_text) ?></code></pre>
    </div>

    <!-- Edit mode -->
    <form id="cron-edit-form" method="post" style="display:none; margin:0;">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
      <input type="hidden" name="action" value="save_cronfile">
      <textarea id="cron-ta" class="code" name="cron_text" spellcheck="false"><?= htmlspecialchars($cron_text) ?></textarea>
    </form>

    <div style="margin-top:8px; display:flex; gap:8px; align-items:center;">
      <button id="cron-edit-btn" type="button" class="btn btn-primary" onclick="cronToggle()">Edit</button>
      <button id="cron-cancel-btn" type="button" class="btn btn-cancel" style="display:none;" onclick="cronCancel()">Cancel</button>
    </div>
    <div class="small" style="margin-top:6px;">
      Tip: leave a newline at the end of the file. 
      After saving, cron is reloaded automatically.
    </div>
  </div>
</div>

<script>
function cronToggle() {
  const btn = document.getElementById('cron-edit-btn');
  const cancel = document.getElementById('cron-cancel-btn');
  const view = document.getElementById('cron-view-wrap');
  const form = document.getElementById('cron-edit-form');

  if (btn.dataset.mode === 'save') {
    // Submit
    form.submit();
    return;
  }
  // Switch to edit mode
  btn.dataset.mode = 'save';
  btn.textContent = 'Save';
  cancel.style.display = 'inline-block';
  view.style.display = 'none';
  form.style.display = 'block';
}
function cronCancel() {
  const btn = document.getElementById('cron-edit-btn');
  const cancel = document.getElementById('cron-cancel-btn');
  const view = document.getElementById('cron-view-wrap');
  const form = document.getElementById('cron-edit-form');

  btn.dataset.mode = 'edit';
  btn.textContent = 'Edit';
  cancel.style.display = 'none';
  form.style.display = 'none';
  view.style.display = 'block';
}
</script>

<?php LBWeb::lbfooter(); ?>

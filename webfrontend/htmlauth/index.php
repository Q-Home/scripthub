<?php
require_once "loxberry_web.php";
require_once "loxberry_system.php";
require_once "settings.inc.php";

$settings = sh_settings_load($settingsFile, $dataDir);

// Use settings instead of constants
$scriptDir = $settings['paths']['script_dir'];
$logfile   = $settings['paths']['log_file'];
$lockDir   = $settings['paths']['lock_dir'];

session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$template_title = "scripthub";
$helplink = "http://www.loxwiki.eu:80/x/2wzL";
$helptemplate = "help.html";

$navbar[1]['Name'] = 'Overzicht';
$navbar[1]['URL'] = 'index.php';
$navbar[1]['active'] = True;
$navbar[2]['Name'] = 'Logs';
$navbar[2]['URL'] = 'logs.php';
$navbar[3]['Name'] = 'Settings';
$navbar[3]['URL'] = 'settings.php';



// --- Load metadata (mode/schedule) ---
$meta = [];
if (is_readable($metaFile)) {
    $json = @file_get_contents($metaFile);
    $meta = json_decode($json, true);
    if (!is_array($meta)) $meta = [];
}

// --- Helpers ---
function isScriptRunning($scriptName) {
    $escapedName = escapeshellarg($scriptName);
    $output = shell_exec("ps -ef | grep $escapedName | grep -v grep");
    return !empty(trim($output));
}
function getLastModified($scriptPath) {
    return file_exists($scriptPath) ? date("Y-m-d H:i:s", filemtime($scriptPath)) : "N/A";
}
function getLastRunTimeFromCustomLog($scriptName, $isRunning) {
    global $logfile;
    if ($isRunning) {
        return "Currently";
    }
    if (!is_readable($logfile)) {
        return "Log not readable";
    }

    // Only look at marker lines for this script (Started/Finished/Stopped/Skipped)
    $pat = '(=== Started|--- Finished|--- Stopped|--- Skipped).*\\b' . preg_quote($scriptName, '/') . '\\b';
    $cmd = 'grep -E ' . escapeshellarg($pat) . ' ' . escapeshellarg($logfile) . ' | tail -1';
    $line = shell_exec($cmd);
    if (empty($line)) {
        return "Not found";
    }

    // Match both "YYYY-MM-DD HH:MM:SS" and "YYYY-MM-DDTHH:MM:SS[±HH:MM]"
    if (preg_match(
        '/^(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:\d{2})?)\s+(=== Started|--- Finished|--- Stopped|--- Skipped)/',
        $line,
        $m
    )) {
        $when = $m[1];
        $what = strpos($m[2], 'Started') !== false ? 'Started'
              : (strpos($m[2], 'Finished') !== false ? 'Finished'
              : (strpos($m[2], 'Stopped') !== false ? 'Stopped' : 'Skipped'));
        return "$when ($what)";
    }

    return "Unknown";
}
function flash($msg, $type='info') {
    $_SESSION['flash'][] = ['msg'=>$msg,'type'=>$type];
}
function show_flashes() {
    if (!empty($_SESSION['flash'])) {
        foreach ($_SESSION['flash'] as $f) {
            $cls = $f['type'] === 'error' ? 'flash-error' : ($f['type'] === 'success' ? 'flash-success' : 'flash-info');
            echo "<div class='flash $cls'>" . htmlspecialchars($f['msg']) . "</div>";
        }
        unset($_SESSION['flash']);
    }
}
function meta_get($script, $meta) {
    return $meta[$script] ?? ['mode' => 'manual', 'cron_expr' => ''];
}
function meta_set($script, $data, &$meta, $metaFile) {
    $meta[$script] = array_merge(['mode'=>'manual','cron_expr'=>''], $data);
    @file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
}

// --- Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        flash("Invalid request token.", "error");
        header("Location: index.php"); exit;
    }

    $action = $_POST['action'] ?? '';
    $script = basename($_POST['script'] ?? '');
    $scriptPath = "$scriptDir/$script";
    $cfg = meta_get($script, $meta);
    $mode = $cfg['mode'] ?? 'manual';

    // Start/Stop background (nohup) — only in manual mode
    if ($action === 'startstop' && $script) {
        if ($mode === 'cron') {
            flash("This script is in Scheduled (cron) mode. Disable cron first to use Start/Stop.", "error");
            header("Location: index.php"); exit;
        }

        $escapedPath = escapeshellarg($scriptPath);
        $escapedLog  = escapeshellarg($logfile);

        if (isScriptRunning($script)) {
            $escapedScript = escapeshellarg($scriptPath);
            shell_exec("pkill -f $escapedScript 2>/dev/null");
            $stopEntry = date("Y-m-d H:i:s") . " --- Stopped $script ---\n";
            file_put_contents($logfile, $stopEntry, FILE_APPEND);
            flash("Stopped $script", "success");
        } else {
            $ext = strtolower(pathinfo($script, PATHINFO_EXTENSION));
            if (!file_exists($scriptPath) || !is_readable($scriptPath)) {
                flash("Script not found or not readable: $script", "error");
                header("Location: index.php"); exit;
            }
            if ($ext === 'php') {
                shell_exec("nohup php $escapedPath >> $escapedLog 2>&1 &");
            } elseif ($ext === 'py') {
                $python = trim(shell_exec('command -v python3 2>/dev/null')) ?: '/usr/bin/python3';
                $pyEsc = escapeshellarg($python);
                shell_exec("nohup $pyEsc $escapedPath >> $escapedLog 2>&1 &");
            } elseif ($ext === 'sh') {
                $bash = trim(shell_exec('command -v bash 2>/dev/null')) ?: '/bin/bash';
                $bashEsc = escapeshellarg($bash);
                shell_exec("nohup $bashEsc $escapedPath >> $escapedLog 2>&1 &");
            } else {
                if (!is_executable($scriptPath)) {
                    flash("Script is not executable: $script", "error");
                    header("Location: index.php"); exit;
                }
                shell_exec("nohup $escapedPath >> $escapedLog 2>&1 &");
            }
            $startEntry = date("Y-m-d H:i:s") . " === Started $script ===\n";
            file_put_contents($logfile, $startEntry, FILE_APPEND);
            flash("Started $script (logging to scripthub_cron.log)", "success");
        }
        header("Location: index.php"); exit;
    }

    // Save file content (editor)
    if ($action === 'save' && $script) {
        if (!file_exists($scriptPath)) { flash("Script does not exist: $script", "error"); header("Location: index.php"); exit; }
        $newContent = $_POST['content'] ?? '';
        $realBase = realpath($scriptDir);
        $realTarget = realpath($scriptPath);
        if ($realBase === false || $realTarget === false || strpos($realTarget, $realBase) !== 0) {
            flash("Invalid script path.", "error"); header("Location: index.php"); exit;
        }
        $backup = $scriptPath . "." . date("Ymd_His") . ".bkp";
        @copy($scriptPath, $backup);
        $tmp = tempnam(sys_get_temp_dir(), 'scripthub_');
        $bytes = @file_put_contents($tmp, $newContent);
        if ($bytes === false) { @unlink($tmp); flash("Failed to write temp file for $script", "error"); header("Location: index.php"); exit; }
        $perms = @fileperms($scriptPath) & 0777; if ($perms) @chmod($tmp, $perms);
        if (!@rename($tmp, $scriptPath)) { $ok = @copy($tmp, $scriptPath); @unlink($tmp); if (!$ok) { flash("Failed to replace $script", "error"); header("Location: index.php"); exit; } }
        @touch($scriptPath, time());
        flash("Saved changes to $script (backup: " . basename($backup) . ")", "success");
        header("Location: index.php"); exit;
    }

    // Save cron schedule -> apply to /etc/cron.d
    if ($action === 'cron_save' && $script) {
        if (isScriptRunning($script)) {
            flash("Stop the script before enabling or changing its cron schedule.", "error");
            header("Location: index.php"); exit;
        }
        $cron_expr = trim($_POST['cron_expr'] ?? '');
        if ($cron_expr === '') { flash("Cron expression cannot be empty.", "error"); header("Location: index.php"); exit; }
        if ($cron_expr[0] !== '@') {
            $parts = preg_split('/\s+/', $cron_expr);
            if (count($parts) < 5) { flash("Invalid cron expression. Use a macro (@hourly) or 5 fields.", "error"); header("Location: index.php"); exit; }
        }

        meta_set($script, ['mode'=>'cron', 'cron_expr'=>$cron_expr, 'last_applied'=>date("Y-m-d H:i:s")], $meta, $metaFile);

        // APPLY CRON (shared helper uses settings paths)
        list($ok, $msg) = sh_apply_cron($meta, $settings);
        flash(($ok ? "✅ " : "⚠️ ") . $msg, $ok ? "success" : "error");
        header("Location: index.php"); exit;
        }

    // Disable cron -> apply file
    if ($action === 'cron_disable' && $script) {
        meta_set($script, ['mode'=>'manual', 'cron_expr'=>''], $meta, $metaFile);

        // APPLY CRON (shared helper)
        list($ok, $msg) = sh_apply_cron($meta, $settings);
        flash(($ok ? "✅ " : "⚠️ ") . $msg, $ok ? "success" : "error");
        header("Location: index.php"); exit;
    }

    // Manual one-shot run (allowed in both modes; disabled in UI if currently running)
    if ($action === 'run_once' && $script) {
        if (isScriptRunning($script)) {
            flash("Script is already running. Stop it first to run once.", "error");
            header("Location: index.php"); exit;
        }
        $escapedPath = escapeshellarg($scriptPath);
        $escapedLog  = escapeshellarg($logfile);
        $ext = strtolower(pathinfo($script, PATHINFO_EXTENSION));
        if (!file_exists($scriptPath) || !is_readable($scriptPath)) { flash("Script not readable: $script", "error"); header("Location: index.php"); exit; }
        if ($ext === 'php') {
            shell_exec("nohup php $escapedPath >> $escapedLog 2>&1 &");
        } elseif ($ext === 'py') {
            $python = trim(shell_exec('command -v python3 2>/dev/null')) ?: '/usr/bin/python3';
            $pyEsc = escapeshellarg($python);
            shell_exec("nohup $pyEsc $escapedPath >> $escapedLog 2>&1 &");
        } elseif ($ext === 'sh') {
            $bash = trim(shell_exec('command -v bash 2>/dev/null')) ?: '/bin/bash';
            $bashEsc = escapeshellarg($bash);
            shell_exec("nohup $bashEsc $escapedPath >> $escapedLog 2>&1 &");
        } else {
            if (!is_executable($scriptPath)) { flash("Script is not executable: $script", "error"); header("Location: index.php"); exit; }
            shell_exec("nohup $escapedPath >> $escapedLog 2>&1 &");
        }
        $startEntry = date("Y-m-d H:i:s") . " === Started (one-shot) $script ===\n";
        file_put_contents($logfile, $startEntry, FILE_APPEND);
        flash("Triggered one-shot run of $script.", "success");
        header("Location: index.php"); exit;
    }
}

// --- Collect scripts ---
$scripts = array_values(array_filter(scandir($scriptDir), function ($file) use ($scriptDir) {
    if (!is_file("$scriptDir/$file")) return false;
    return preg_match('/\.(php|py|sh)$/i', $file) || is_executable("$scriptDir/$file");
}));

LBWeb::lbheader($template_title, $helplink, $helptemplate);
?>

<!-- CodeMirror assets (CDN) -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/php/php.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/python/python.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/shell/shell.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/javascript/javascript.min.js"></script>

<style>
.flash { padding:10px; margin:10px 0; border-radius:6px; }
.flash-info { background:#eef5ff; border:1px solid #bcd4ff; }
.flash-success { background:#eaffea; border:1px solid #b6e3b6; }
.flash-error { background:#ffecec; border:1px solid #ffb9b9; }
table { width:100%; border-collapse:collapse; margin:20px 0; }
th, td { border:1px solid #ddd; padding:10px; text-align:left; vertical-align:top; }
th { background-color:#f4f4f4; }
.status-running { color:green; font-weight:bold; }
.status-offline { color:gray; font-style:italic; }
.badge { display:inline-block; padding:2px 8px; border-radius:12px; font-size:12px; }
.badge-manual { background:#eef; color:#223; border:1px solid #cdd; }
.badge-cron { background:#efe; color:#232; border:1px solid #cdc; }
.btn { padding:6px 12px; font-size:14px; border:none; border-radius:4px; cursor:pointer; }
.btn-primary { background-color:#007BFF; color:#fff; }
.btn-view { background-color:#28a745; color:#fff; }
.btn-cancel { background:#6c757d; color:#fff; }
.btn-ghost { background:#f8f9fa; border:1px solid #cfd3d7; color:#333; }
.btn-danger { background:#dc3545; color:#fff; }
.btn-disabled { opacity:0.55; cursor:not-allowed; }
.editor-wrap { background:#f8f9fa; border:1px solid #ccc; padding:10px; }
textarea.code { width:100%; height:300px; font-family:monospace; font-size:13px; }
pre.codeview {
    background:#fff; color:#000;
    padding:10px; border-radius:6px; overflow:auto; max-height:420px;
    font-family:monospace; font-size:13px; border:1px solid #ccc;
}
.meta-hint { margin-top:6px; font-size:12px; color:#555; }
.quick-picks button { margin-right:6px; margin-bottom:6px; }
.section-title { font-weight:600; margin:10px 0 6px; }
.small-note { font-size:12px; color:#666; }

/* Actions row: keep buttons on one horizontal line */
.actions-row {
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
}
.actions-row form {
    display:inline-flex;
    margin:0;
    width:auto !important;
}
.actions-row .btn {
    width:auto !important;
    flex:0 0 auto;
    white-space:nowrap;
}
/* Schedule buttons row */
.btn-row {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
  margin-top: 12px;
}
.btn-row form { display: contents; }       /* keep buttons side-by-side */
.btn-row .btn { width: auto !important; }  /* no full-width buttons */
</style>

<h2>Script Overview</h2>
<?php show_flashes(); ?>

<table>
<tr>
    <th>Script</th>
    <th>Status</th>
    <th>Mode</th>
    <th>Last Updated</th>
    <th>Last Run</th>
    <th>Actions</th>
    <th>View</th>
</tr>
<?php foreach ($scripts as $script):
    $scriptPath = "$scriptDir/$script";
    $isRunning = isScriptRunning($script);
    $lastMod   = getLastModified($scriptPath);
    $lastRun   = getLastRunTimeFromCustomLog($script, $isRunning);
    $rid = md5($script);
    $fileContents = @file_get_contents($scriptPath);
    $fileContents = $fileContents === false ? "" : $fileContents;
    $ext = strtolower(pathinfo($script, PATHINFO_EXTENSION));
    $cfg = meta_get($script, $meta);
    $mode = $cfg['mode'] ?? 'manual';
    $cron_expr = $cfg['cron_expr'] ?? '';
?>
<tr id="row-<?= $rid ?>">
    <td><?= htmlspecialchars($script) ?></td>
    <td class="<?= $isRunning ? 'status-running' : 'status-offline' ?>">
        <?= $isRunning ? 'Running' : 'Offline' ?>
    </td>
    <td>
        <?php if ($mode === 'cron'): ?>
            <span class="badge badge-cron">Scheduled</span>
        <?php else: ?>
            <span class="badge badge-manual">Manual</span>
        <?php endif; ?>
    </td>
    <td><?= htmlspecialchars($lastMod) ?></td>
    <td><?= htmlspecialchars($lastRun) ?></td>
    <td>
        <div class="actions-row">
            <?php if ($mode === 'cron'): ?>
                <button class="btn btn-ghost btn-disabled" disabled>Start/Stop</button>

                <form method="post">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="run_once">
                    <input type="hidden" name="script" value="<?= htmlspecialchars($script) ?>">
                    <button type="submit" class="btn btn-primary<?= $isRunning ? ' btn-disabled' : '' ?>" <?= $isRunning ? 'disabled' : '' ?>>Run once</button>
                </form>

                <button id="cronbtn-<?= $rid ?>" type="button" class="btn btn-ghost" onclick="toggleSchedule('<?= $rid ?>', this)">Cron</button>

            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="startstop">
                    <input type="hidden" name="script" value="<?= htmlspecialchars($script) ?>">
                    <button type="submit" class="btn btn-primary"><?= $isRunning ? 'Stop' : 'Start' ?></button>
                </form>

                <form method="post">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="run_once">
                    <input type="hidden" name="script" value="<?= htmlspecialchars($script) ?>">
                    <button type="submit" class="btn btn-ghost<?= $isRunning ? ' btn-disabled' : '' ?>" <?= $isRunning ? 'disabled' : '' ?>>Run once</button>
                </form>

                <button id="cronbtn-<?= $rid ?>"
                        type="button"
                        class="btn btn-ghost<?= $isRunning ? ' btn-disabled' : '' ?>"
                        onclick="if(!this.classList.contains('btn-disabled')) toggleSchedule('<?= $rid ?>', this)"
                        <?= $isRunning ? 'disabled' : '' ?>>Cron</button>
            <?php endif; ?>
        </div>
    </td>
    <td>
        <button id="viewbtn-<?= $rid ?>" type="button" class="btn btn-view" onclick="toggleView('<?= $rid ?>', this)">View</button>
    </td>
</tr>

<!-- VIEW/EDIT -->
<tr id="viewrow-<?= $rid ?>" style="display:none;">
    <td colspan="7" class="editor-wrap">
        <div id="ro-<?= $rid ?>">
            <pre class="codeview"><code><?= htmlspecialchars($fileContents) ?></code></pre>
            <div style="margin-top:10px;">
                <button type="button" class="btn btn-primary" onclick="startEdit('<?= $rid ?>')">Edit</button>
            </div>
            <div class="meta-hint">Output log: <code><?= htmlspecialchars($logfile) ?></code></div>
        </div>
        <div id="edit-<?= $rid ?>" style="display:none;">
            <form method="post" style="margin:0;">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="script" value="<?= htmlspecialchars($script) ?>">
                <textarea class="code" name="content" id="ta-<?= $rid ?>" spellcheck="false"
                          data-original="<?= htmlspecialchars($fileContents) ?>"
                          data-filename="<?= htmlspecialchars($script) ?>"
                          data-ext="<?= htmlspecialchars($ext) ?>"><?= htmlspecialchars($fileContents) ?></textarea>
                <div style="margin-top:10px; display:flex; gap:8px;">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <button type="button" class="btn btn-cancel" onclick="cancelEdit('<?= $rid ?>')">Cancel</button>
                </div>
                <div class="meta-hint">Backup on save: <code><?= htmlspecialchars($script) ?>.YYYYMMDD_HHMMSS.bkp</code></div>
            </form>
        </div>
    </td>
</tr>

<!-- SCHEDULE -->
<tr id="schedrow-<?= $rid ?>" style="display:none;">
    <td colspan="7" class="editor-wrap">
        <div class="section-title">Schedule for <code><?= htmlspecialchars($script) ?></code></div>

        <div class="small-note" style="margin-bottom:8px;">
            Saving a schedule switches this script into <strong>Scheduled (cron) mode</strong>.
            The Start/Stop button is disabled in that mode.
        </div>

        <div class="quick-picks" style="margin:8px 0;">
            <span class="small-note" style="display:inline-block; width:120px;">Quick picks:</span>
            <button class="btn btn-ghost" onclick="pickCron('<?= $rid ?>','*/5 * * * *')">Every 5 min</button>
            <button class="btn btn-ghost" onclick="pickCron('<?= $rid ?>','*/15 * * * *')">Every 15 min</button>
            <button class="btn btn-ghost" onclick="pickCron('<?= $rid ?>','*/30 * * * *')">Every 30 min</button>
            <button class="btn btn-ghost" onclick="pickCron('<?= $rid ?>','@hourly')">@hourly</button>
            <button class="btn btn-ghost" onclick="pickCron('<?= $rid ?>','@daily')">@daily</button>
            <button class="btn btn-ghost" onclick="pickCron('<?= $rid ?>','@weekly')">@weekly</button>
            <button class="btn btn-ghost" onclick="pickCron('<?= $rid ?>','@monthly')">@monthly</button>
            <button class="btn btn-ghost" onclick="pickCron('<?= $rid ?>','@reboot')">@reboot</button>
        </div>

        <!-- Save schedule (separate form) -->
        <form method="post" style="margin-top:8px;">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="cron_save">
            <input type="hidden" name="script" value="<?= htmlspecialchars($script) ?>">

            <label for="cron-<?= $rid ?>" class="section-title" style="display:block;">Cron expression or macro</label>
            <input id="cron-<?= $rid ?>" type="text" name="cron_expr" value="<?= htmlspecialchars($cron_expr) ?>"
                   placeholder="e.g. */5 * * * *  or  @hourly"
                   style="width:100%; padding:8px; border:1px solid #ccc; border-radius:6px;" />

            <div class="small-note" style="margin-top:8px;">
                Format: five fields (<code>min hour dom mon dow</code>) or a macro like <code>@hourly</code>.
            </div>

            <div class="btn-row">
              <button type="submit" class="btn btn-primary">Save Schedule</button>
              <?php if ($mode === 'cron'): ?>
                <form method="post" style="margin:0">
                  <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                  <input type="hidden" name="action" value="cron_disable">
                  <input type="hidden" name="script" value="<?= htmlspecialchars($script) ?>">
                  <button type="submit" class="btn btn-danger">Disable Cron</button>
                </form>
              <?php endif; ?>
            </div>

            <div class="small-note" style="margin-top:8px;">
              If automatic write to <code>/etc/cron.d/scripthub</code> is not permitted, a preview is saved at
              <code><?= htmlspecialchars($scriptDir) ?>/scripthub.cron.preview</code>.
            </div>

        </form>
    </td>
</tr>
<?php endforeach; ?>
</table>

<script>
let editors = {}; // CodeMirror instances per row

function closeRow(id) {
    const row = document.getElementById(id);
    if (row) row.style.display = "none";
}
function setViewBtn(rid, text) {
    const b = document.getElementById("viewbtn-" + rid);
    if (b) b.textContent = text;
}
function setCronBtn(rid, text) {
    const b = document.getElementById("cronbtn-" + rid);
    if (b) b.textContent = text;
}

function toggleView(rid, btn) {
    const view = document.getElementById("viewrow-" + rid);
    const showing = (view.style.display === "table-row");

    // Close schedule pane and reset its button label
    closeRow("schedrow-" + rid);
    setCronBtn(rid, "Cron");

    // Toggle view pane + set this button label
    view.style.display = showing ? "none" : "table-row";
    if (btn) btn.textContent = showing ? "View" : "Hide";

    if (!showing) { // ensure read-only by default
        document.getElementById("ro-" + rid).style.display = "block";
        document.getElementById("edit-" + rid).style.display = "none";
    }
}

function startEdit(rid) {
    const ro = document.getElementById("ro-" + rid);
    const ed = document.getElementById("edit-" + rid);
    ro.style.display = "none";
    ed.style.display = "block";

    if (!editors[rid]) {
        const ta = document.getElementById("ta-" + rid);
        const ext = (ta.dataset.ext || "").toLowerCase();
        let mode = "shell";
        if (ext === "php" || ta.value.trim().startsWith("<?php")) mode = "php";
        else if (ext === "py") mode = "python";
        else if (ext === "sh" || ext === "bash") mode = "shell";
        else if (ext === "js") mode = "javascript";

        editors[rid] = CodeMirror.fromTextArea(ta, {
            lineNumbers: true,
            mode: mode,
            indentUnit: 4,
            smartIndent: true
        });
        editors[rid].setSize("100%", 400);
    } else {
        editors[rid].refresh();
    }
}

function cancelEdit(rid, silent=false) {
    const ro = document.getElementById("ro-" + rid);
    const ed = document.getElementById("edit-" + rid);
    const ta = document.getElementById("ta-" + rid);

    if (editors[rid]) editors[rid].setValue(ta.dataset.original || "");
    else ta.value = ta.dataset.original || "";

    ed.style.display = "none";
    ro.style.display = "block";
}

function toggleSchedule(rid, btn) {
    const sched = document.getElementById("schedrow-" + rid);
    const showing = (sched.style.display === "table-row");

    // Close view pane and reset its button label
    closeRow("viewrow-" + rid);
    setViewBtn(rid, "View");

    // Toggle schedule pane + set this button label
    sched.style.display = showing ? "none" : "table-row";
    if (btn) btn.textContent = showing ? "Cron" : "Close";
}

function pickCron(rid, expr) {
    const inp = document.getElementById("cron-" + rid);
    if (inp) inp.value = expr;
}
</script>

<?php LBWeb::lbfooter(); ?>

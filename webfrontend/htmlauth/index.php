<?php
require_once "loxberry_web.php";
require_once "loxberry_system.php";

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

$scriptDir = "/opt/loxberry/data/plugins/scripthub";
$logfile   = "$scriptDir/scripthub_cron.log"; // unified log target

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
    if (!is_readable($logfile)) return "Log not readable";
    $command = "grep " . escapeshellarg($scriptName) . " $logfile | tail -1";
    $output = shell_exec($command);
    if (empty($output)) return "Not found";

    if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+(=== Started|--- Stopped)/', $output, $m)) {
        $when = $m[1];
        $what = (strpos($m[2], 'Started') !== false) ? "Started" : "Stopped";
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

// --- Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        flash("Invalid request token.", "error");
        header("Location: index.php"); exit;
    }

    $action = $_POST['action'] ?? '';
    $script = basename($_POST['script'] ?? '');
    $scriptPath = "$scriptDir/$script";

    if ($action === 'startstop' && $script) {
        $escapedPath = escapeshellarg($scriptPath);
        $escapedLog  = escapeshellarg($logfile);

        if (isScriptRunning($script)) {
            // Stop
            $escapedScript = escapeshellarg($scriptPath);
            shell_exec("pkill -f $escapedScript 2>/dev/null");
            // Log stop marker (no brackets)
            $stopEntry = date("Y-m-d H:i:s") . " --- Stopped $script ---\n";
            file_put_contents($logfile, $stopEntry, FILE_APPEND);
            flash("Stopped $script", "success");
        } else {
            // Start: route via interpreter by extension, else run directly
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

            // Log start marker (no brackets)
            $startEntry = date("Y-m-d H:i:s") . " === Started $script ===\n";
            file_put_contents($logfile, $startEntry, FILE_APPEND);
            flash("Started $script (logging to scripthub_cron.log)", "success");
        }

        header("Location: index.php"); exit;
    }

    if ($action === 'save' && $script) {
        if (!file_exists($scriptPath)) {
            flash("Script does not exist: $script", "error");
            header("Location: index.php"); exit;
        }
        $newContent = $_POST['content'] ?? '';

        $realBase = realpath($scriptDir);
        $realTarget = realpath($scriptPath);
        if ($realBase === false || $realTarget === false || strpos($realTarget, $realBase) !== 0) {
            flash("Invalid script path.", "error");
            header("Location: index.php"); exit;
        }

        $backup = $scriptPath . "." . date("Ymd_His") . ".bkp";
        @copy($scriptPath, $backup);

        $tmp = tempnam(sys_get_temp_dir(), 'scripthub_');
        $bytes = @file_put_contents($tmp, $newContent);
        if ($bytes === false) {
            @unlink($tmp);
            flash("Failed to write temp file for $script", "error");
            header("Location: index.php"); exit;
        }

        $perms = @fileperms($scriptPath) & 0777;
        if ($perms) @chmod($tmp, $perms);

        if (!@rename($tmp, $scriptPath)) {
            $ok = @copy($tmp, $scriptPath);
            @unlink($tmp);
            if (!$ok) {
                flash("Failed to replace $script", "error");
                header("Location: index.php"); exit;
            }
        }
        @touch($scriptPath, time());

        flash("Saved changes to $script (backup: " . basename($backup) . ")", "success");
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
.btn { padding:6px 12px; font-size:14px; border:none; border-radius:4px; cursor:pointer; }
.btn-primary { background-color:#007BFF; color:#fff; }
.btn-view { background-color:#28a745; color:#fff; }
.btn-cancel { background:#6c757d; color:#fff; }
.editor-wrap { background:#f8f9fa; border:1px solid #ccc; padding:10px; }
textarea.code { width:100%; height:300px; font-family:monospace; font-size:13px; }
</style>

<h2>Script Overview</h2>
<?php show_flashes(); ?>

<table>
<tr>
    <th>Script</th>
    <th>Status</th>
    <th>Last Updated</th>
    <th>Last Run</th>
    <th>Action</th>
    <th>View / Edit</th>
</tr>
<?php foreach ($scripts as $script):
    $scriptPath = "$scriptDir/$script";
    $isRunning = isScriptRunning($script);
    $lastMod   = getLastModified($scriptPath);
    $lastRun   = getLastRunTimeFromCustomLog($script, $isRunning);
    $rid = md5($script);
?>
<tr id="row-<?= $rid ?>">
    <td><?= htmlspecialchars($script) ?></td>
    <td class="<?= $isRunning ? 'status-running' : 'status-offline' ?>">
        <?= $isRunning ? 'Running' : 'Offline' ?>
    </td>
    <td><?= htmlspecialchars($lastMod) ?></td>
    <td><?= htmlspecialchars($lastRun) ?></td>
    <td>
        <form method="post" style="margin:0">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="startstop">
            <input type="hidden" name="script" value="<?= htmlspecialchars($script) ?>">
            <button type="submit" class="btn btn-primary">
                <?= $isRunning ? 'Stop' : 'Start' ?>
            </button>
        </form>
    </td>
    <td>
        <button type="button" class="btn btn-view" onclick="toggleEdit('edit-<?= $rid ?>', this)">View</button>
    </td>
</tr>
<tr id="edit-<?= $rid ?>" style="display:none;">
    <td colspan="6" class="editor-wrap">
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="script" value="<?= htmlspecialchars($script) ?>">
            <textarea class="code" name="content" spellcheck="false"><?= htmlspecialchars(@file_get_contents($scriptPath)) ?></textarea>
            <div style="margin-top:10px; display:flex; gap:8px;">
                <button type="submit" class="btn btn-primary">Save</button>
                <button type="button" class="btn btn-cancel" onclick="toggleEdit('edit-<?= $rid ?>')">Cancel</button>
            </div>
            <div style="margin-top:6px; font-size:12px; color:#555;">
                Backup created on save: <code><?= htmlspecialchars($script) ?>.YYYYMMDD_HHMMSS.bkp</code><br>
                All output goes to: <code><?= htmlspecialchars($logfile) ?></code>
            </div>
        </form>
    </td>
</tr>
<?php endforeach; ?>
</table>

<script>
function toggleEdit(id, btn) {
    const row = document.getElementById(id);
    const isHidden = row.style.display === "none" || row.style.display === "";
    row.style.display = isHidden ? "table-row" : "none";
    if (btn) btn.textContent = isHidden ? "Hide" : "View";
}
</script>

<?php LBWeb::lbfooter(); ?>

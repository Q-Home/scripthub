<?php
require_once "loxberry_web.php";
require_once "loxberry_system.php";

$template_title = "scripthub";
$helplink = "http://www.loxwiki.eu:80/x/2wzL";
$helptemplate = "help.html";

$navbar[1]['Name'] = 'ScriptHub';
$navbar[1]['URL'] = 'index.php';
$navbar[1]['active'] = True;
$navbar[2]['Name'] = 'Logfile';
$navbar[2]['URL'] = 'logs.php';

$scriptDir = "/opt/loxberry/data/plugins/scripthub";
$logfile = "$scriptDir/scripthub_cron.log";

// === HELPER: Check if script is running ===
function isScriptRunning($scriptName) {
    $escapedName = escapeshellarg($scriptName);
    $output = shell_exec("ps -ef | grep $escapedName | grep -v grep");
    return !empty(trim($output));
}

// === HELPER: Get last modified time ===
function getLastModified($scriptPath) {
    return file_exists($scriptPath) ? date("Y-m-d H:i:s", filemtime($scriptPath)) : "N/A";
}

// === HELPER: Get last run time from custom log ===
function getLastRunTimeFromCustomLog($scriptName) {
    global $logfile;
    if (!is_readable($logfile)) return "Log not readable";
    $command = "grep " . escapeshellarg($scriptName) . " $logfile | tail -1";
    $output = shell_exec($command);
    if (empty($output)) return "Not found";
    if (preg_match('/^([\d\-]+\s+[\d\:]+)\s+/', $output, $matches)) {
        return $matches[1];
    }
    return "Unknown";
}

// === HANDLE START/STOP ACTION ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['script'])) {
    $script = basename($_POST['script']);
    $scriptPath = "$scriptDir/$script";

    if (file_exists($scriptPath) && is_executable($scriptPath)) {
        if (isScriptRunning($script)) {
            // Stop
            $escapedScript = escapeshellarg($script);
            shell_exec("pkill -f $escapedScript");
        } else {
            // Start — detect PHP or other executable
            $escapedPath = escapeshellarg($scriptPath);
            if (substr($script, -4) === '.php') {
                shell_exec("nohup php $escapedPath > /dev/null 2>&1 &");
            } else {
                shell_exec("nohup $escapedPath > /dev/null 2>&1 &");
            }

            // Log start time immediately
            $logEntry = date("Y-m-d H:i:s") . " $script started manually\n";
            file_put_contents($logfile, $logEntry, FILE_APPEND);
        }
    }

    // Redirect to avoid form resubmission
    header("Location: index.php");
    exit;
}

// === Collect script info ===
$scripts = array_filter(scandir($scriptDir), function ($file) use ($scriptDir) {
    return is_file("$scriptDir/$file") && is_executable("$scriptDir/$file");
});

LBWeb::lbheader($template_title, $helplink, $helptemplate);
?>

<style>
    table {
        width: 100%;
        border-collapse: collapse;
        margin: 20px 0;
    }
    th, td {
        border: 1px solid #ddd;
        padding: 10px;
        text-align: left;
    }
    th {
        background-color: #f4f4f4;
    }
    .status-running {
        color: green;
        font-weight: bold;
    }
    .status-offline {
        color: gray;
        font-style: italic;
    }
    .btn-action {
        padding: 6px 12px;
        font-size: 14px;
        border: none;
        background-color: #007BFF;
        color: white;
        border-radius: 4px;
        cursor: pointer;
    }
    .btn-action:hover {
        background-color: #0056b3;
    }
</style>

<h2>Script Overview</h2>

<table>
    <tr>
        <th>Script</th>
        <th>Status</th>
        <th>Last Updated</th>
        <th>Last Run</th>
        <th>Action</th>
        <th>View</th>
    </tr>
    <?php foreach ($scripts as $script): 
        $scriptPath = "$scriptDir/$script";
        $isRunning = isScriptRunning($script);
        $lastMod = getLastModified($scriptPath);
        $lastRun = getLastRunTimeFromCustomLog($script);
    ?>
    <tr>
        <td><?= htmlspecialchars($script) ?></td>
        <td class="<?= $isRunning ? 'status-running' : 'status-offline' ?>">
            <?= $isRunning ? 'Running' : 'Offline' ?>
        </td>
        <td><?= $lastMod ?></td>
        <td><?= htmlspecialchars($lastRun) ?></td>
        <td>
            <form method="post" style="margin:0">
                <input type="hidden" name="script" value="<?= htmlspecialchars($script) ?>">
                <button type="submit" class="btn-action">
                    <?= $isRunning ? 'Stop' : 'Start' ?>
                </button>
            </form>
        </td>
        <td>
            <button type="button" class="btn-action" style="background-color: #28a745;"
                    onclick="toggleView('view-<?= md5($script) ?>', this)">
                View
            </button>
        </td>
    </tr>
    <tr id="view-<?= md5($script) ?>" style="display:none;">
        <td colspan="6" style="background:#f4f4f4;">
            <pre style="margin:0; padding:10px; border:1px solid #ccc; overflow:auto;">
<?= htmlspecialchars(file_get_contents($scriptPath)) ?>
            </pre>
        </td>
    </tr>
    <?php endforeach; ?>
</table>

<script>
function toggleView(id, btn) {
    const row = document.getElementById(id);
    if (row.style.display === "none") {
        row.style.display = "table-row";
        btn.textContent = "Hide";
    } else {
        row.style.display = "none";
        btn.textContent = "View";
    }
}
</script>

<?php LBWeb::lbfooter(); ?>

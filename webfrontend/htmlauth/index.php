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


LBWeb::lbheader($template_title, $helplink, $helptemplate);

// === CONFIGURATION ===
$scriptDir = "/opt/loxberry/data/plugins/scripthub";

// === HELPER: Check if script is running ===
function isScriptRunning($scriptName) {
    $escapedName = escapeshellarg($scriptName);
    $output = shell_exec("ps -ef | grep php | grep $escapedName | grep -v grep");
    return !empty(trim($output));
}

// === HELPER: Get last modified time ===
function getLastModified($scriptPath) {
    return file_exists($scriptPath) ? date("Y-m-d H:i:s", filemtime($scriptPath)) : "N/A";
}

// === HELPER: Get last run time from syslog ===
function getLastRunTimeFromCustomLog($scriptName) {
    $logfile = "/opt/loxberry/data/plugins/scripthub/scripthub_cron.log";
    if (!is_readable($logfile)) return "Log not readable";

    $command = "grep " . escapeshellarg($scriptName) . " $logfile | tail -1";
    $output = shell_exec($command);

    if (empty($output)) return "Not found";

    if (preg_match('/^([\d\-]+\s+[\d\:]+)\s+/', $output, $matches)) {
        return $matches[1];
    }

    return "Unknown";
}


// === Collect script info ===
$scripts = array_filter(scandir($scriptDir), function ($file) use ($scriptDir) {
    return is_file("$scriptDir/$file") && is_executable("$scriptDir/$file");
});
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
            <form method="post" action="toggle.php" style="margin:0">
                <input type="hidden" name="script" value="<?= htmlspecialchars($script) ?>">
                <button type="submit" class="btn-action">
                    <?= $isRunning ? 'Stop' : 'Start' ?>
                </button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
</table>

<?php LBWeb::lbfooter(); ?>

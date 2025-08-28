<?php
require_once "loxberry_web.php";
require_once "loxberry_system.php";
require_once "settings.inc.php";
$settings = sh_settings_load($settingsFile, $dataDir);

$scriptDir = $settings['paths']['script_dir'];
$logfile   = $settings['paths']['log_file'];
$lockDir   = $settings['paths']['lock_dir'];


$L = LBSystem::readlanguage("language.ini");
$template_title = "Plugin Logs";
$helplink = "http://www.loxwiki.eu:80/x/2wzL";
$helptemplate = "help.html";

// Navigation
$navbar[1]['Name'] = 'Overzicht';
$navbar[1]['URL'] = 'index.php';
$navbar[2]['Name'] = 'Logs';
$navbar[2]['URL'] = 'logs.php';
$navbar[2]['active'] = True;
$navbar[3]['Name'] = 'Settings';
$navbar[3]['URL'] = 'settings.php';



// Define log file
// Define log file (from Settings)
$log_path = $logfile;                // comes from settings.inc.php
$log_dir  = dirname($log_path);

// Ensure the file exists (so the viewer doesn’t 404 on a first run)
if (!file_exists($log_path)) {
    @touch($log_path);
    @chmod($log_path, 0666);
}

// Validate file exists and is within its parent dir
$rp_file = realpath($log_path);
$rp_dir  = realpath($log_dir);
$valid   = ($rp_file !== false && $rp_dir !== false && strpos($rp_file, $rp_dir) === 0 && is_readable($rp_file));

$log_lines = [];
if ($valid) {
    // Read newest 500 lines (newest first)
    $lines = @file($rp_file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) { $lines = []; }
    $log_lines = array_reverse(array_slice($lines, -500));
}

// AJAX: return just the log lines
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    if ($valid) {
        foreach ($log_lines as $line) {
            $logClass = 'log-info';
            if (stripos($line, '[error]') !== false)       $logClass = 'log-error';
            elseif (stripos($line, '[warning]') !== false) $logClass = 'log-warning';
            echo '<div class="' . $logClass . '">' . htmlspecialchars(trim($line)) . '</div>';
        }
    } else {
        echo '<div class="text-danger">Log file not found or invalid.</div>';
    }
    exit;
}


LBWeb::lbheader($template_title, $helplink, $helptemplate);

// Validate file exists and is within log_dir
$valid = ($log_path && strpos($log_path, realpath($log_dir)) === 0 && file_exists($log_path));
$log_lines = [];

if ($valid) {
    $log_lines = file($log_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $log_lines = array_reverse($log_lines); // Newest entries first
    $log_lines = array_slice($log_lines, 0, 500); // Only show the 500 newest lines
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Log Viewer</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" />
    <style>
        #log-viewer {
            width: 100%;
            height: 600px;
            overflow-y: scroll;
            border: 1px solid #ccc;
            padding: 10px;
            margin-top: 20px;
            font-family: monospace;
            font-size: 13px;
            background-color: #f9f9f9;
        }
        .log-info { color: #000000; }
        .log-warning { color: #FF9800; }
        .log-error { color: #F44336; }
        .log-info, .log-warning, .log-error {
            padding: 5px 0;
            border-bottom: 1px solid #ddd;
            white-space: pre-wrap;
        }
    </style>
</head>
<body>
<div class="container mt-5">
    <div class="col-lg-10 offset-lg-1">
        <h3 class="mb-4">Log Viewer: MQTT Listener</h3>

        <div class="mb-3">
            <a href="#" id="refresh-log" class="btn btn-primary">Refresh Log</a>
        </div>

        <div id="log-viewer">
            <?php if ($valid): ?>
                <?php foreach ($log_lines as $line): ?>
                    <?php
                        $logClass = 'log-info';
                        if (stripos($line, '[error]') !== false) {
                            $logClass = 'log-error';
                        } elseif (stripos($line, '[warning]') !== false) {
                            $logClass = 'log-warning';
                        }
                    ?>
                    <div class="<?= $logClass ?>"><?= htmlspecialchars(trim($line)) ?></div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-danger">Log file not found or invalid.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    document.getElementById('refresh-log').addEventListener('click', function(event) {
        event.preventDefault();
        fetch('logs.php?ajax=1')
            .then(response => response.text())
            .then(html => {
                document.getElementById('log-viewer').innerHTML = html;
            })
            .catch(error => {
                alert('Error refreshing log: ' + error);
            });
    });
</script>

<?php LBWeb::lbfooter(); ?>
</body>
</html>

<?php
// Handle AJAX request
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    if ($valid) {
        foreach ($log_lines as $line) {
            $logClass = 'log-info';
            if (stripos($line, '[error]') !== false) {
                $logClass = 'log-error';
            } elseif (stripos($line, '[warning]') !== false) {
                $logClass = 'log-warning';
            }
            echo '<div class="' . $logClass . '">' . htmlspecialchars(trim($line)) . '</div>';
        }
    } else {
        echo '<div class="text-danger">Log file not found or invalid.</div>';
    }
    exit;
}
?>
<?php
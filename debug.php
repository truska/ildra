<?php
declare(strict_types=1);

/**
 * Minimal environment sanity checker for deployment debugging.
 *
 * Safe-by-default: does NOT print DB credentials or phpinfo unless unlocked.
 *
 * Unlock options:
 * - Set an environment variable `ILDRA_DEBUG_KEY` and open `debug.php?key=...`
 * - Or add `'debug_key' => '...'` to `config.php` and open `debug.php?key=...`
 *
 * Extra (unlocked only):
 * - `?db=1` tests DB connectivity (no credentials printed)
 * - `?phpinfo=1` shows phpinfo()
 *
 * Delete this file after debugging production.
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function env_value(string $key): ?string
{
    $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    $val = is_string($val) ? trim($val) : '';
    return $val !== '' ? $val : null;
}

function load_config(): array
{
    $path = __DIR__ . '/config.php';
    if (!is_file($path)) {
        return [];
    }
    $cfg = require $path;
    return is_array($cfg) ? $cfg : [];
}

function is_unlocked(array $config): bool
{
    $expected = env_value('ILDRA_DEBUG_KEY');
    if (!$expected && isset($config['debug_key']) && is_string($config['debug_key'])) {
        $expected = trim($config['debug_key']);
    }
    if (!$expected) {
        return false;
    }
    $provided = isset($_GET['key']) ? (string)$_GET['key'] : '';
    return hash_equals($expected, $provided);
}

function bool_text(bool $value): string
{
    return $value ? 'Yes' : 'No';
}

function section(string $title): void
{
    echo '<h2 style="margin:18px 0 8px;font-size:18px">' . h($title) . '</h2>';
}

$config = load_config();
$unlocked = is_unlocked($config);

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ILDRA debug</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; margin: 24px; color: #111; }
        .box { border: 1px solid #ddd; border-radius: 10px; padding: 14px; background: #fff; max-width: 980px; }
        .row { display: grid; grid-template-columns: 220px 1fr; gap: 10px; padding: 6px 0; border-bottom: 1px solid #f1f1f1; }
        .row:last-child { border-bottom: 0; }
        .k { color: #555; font-weight: 600; }
        .v { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
        .pill { display:inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; border: 1px solid #ddd; background:#f7f7f7; }
        .ok { border-color: #b8e0c2; background: #eef8f0; color:#0c441a; }
        .bad { border-color: #f2b6b6; background: #fdecec; color:#7a1111; }
        .warn { border-color: #f0d29b; background: #fff6e6; color:#6b4b00; }
        a { color: #0a58ca; }
    </style>
</head>
<body>
    <h1 style="margin:0 0 8px;font-size:26px">ILDRA debug</h1>
    <div style="margin-bottom:14px;color:#555">
        Status:
        <?php if ($unlocked): ?>
            <span class="pill ok">Unlocked</span>
        <?php else: ?>
            <span class="pill warn">Locked (safe mode)</span>
        <?php endif; ?>
        <span style="margin-left:10px" class="pill">Time: <?php echo h(date('d M Y H:i:s')); ?></span>
    </div>

    <div class="box">
        <?php section('Runtime'); ?>
        <div class="row"><div class="k">PHP</div><div class="v"><?php echo h(PHP_VERSION); ?></div></div>
        <div class="row"><div class="k">SAPI</div><div class="v"><?php echo h(PHP_SAPI); ?></div></div>
        <div class="row"><div class="k">Server</div><div class="v"><?php echo h((string)($_SERVER['SERVER_SOFTWARE'] ?? '')); ?></div></div>
        <div class="row"><div class="k">Document root</div><div class="v"><?php echo h((string)($_SERVER['DOCUMENT_ROOT'] ?? '')); ?></div></div>
        <div class="row"><div class="k">Script</div><div class="v"><?php echo h((string)($_SERVER['SCRIPT_FILENAME'] ?? __FILE__)); ?></div></div>
        <div class="row"><div class="k">display_errors</div><div class="v"><?php echo h((string)ini_get('display_errors')); ?></div></div>
        <div class="row"><div class="k">log_errors</div><div class="v"><?php echo h((string)ini_get('log_errors')); ?></div></div>
        <div class="row"><div class="k">error_log</div><div class="v"><?php echo h((string)ini_get('error_log')); ?></div></div>

        <?php section('Extensions'); ?>
        <?php
        $required = ['pdo', 'pdo_mysql', 'json', 'mbstring'];
        foreach ($required as $ext) {
            $loaded = extension_loaded($ext);
            echo '<div class="row"><div class="k">' . h($ext) . '</div><div class="v">'
                . '<span class="pill ' . ($loaded ? 'ok' : 'bad') . '">' . h(bool_text($loaded)) . '</span>'
                . '</div></div>';
        }
        ?>
        <div class="row">
            <div class="k">Loaded (top)</div>
            <div class="v"><?php echo h(implode(', ', array_slice(get_loaded_extensions(), 0, 30))); ?><?php echo count(get_loaded_extensions()) > 30 ? h(' …') : ''; ?></div>
        </div>

        <?php section('Filesystem'); ?>
        <?php
        $paths = [
            'Project dir' => __DIR__,
            'Config file' => __DIR__ . '/config.php',
            'DB file' => __DIR__ . '/db.php',
            'CMS file' => __DIR__ . '/cms.php',
            'Admin dir' => __DIR__ . '/admin',
            'Views dir' => __DIR__ . '/views',
            'Temp dir' => sys_get_temp_dir(),
            'Session path' => (string)ini_get('session.save_path'),
        ];
        foreach ($paths as $label => $path) {
            $exists = $path !== '' && file_exists($path);
            $isDir = $exists && is_dir($path);
            $writable = $exists ? is_writable($path) : false;
            $extra = $exists ? ($isDir ? 'dir' : 'file') : 'missing';
            $pillClass = $exists ? 'ok' : 'bad';
            echo '<div class="row"><div class="k">' . h($label) . '</div><div class="v">'
                . '<span class="pill ' . $pillClass . '">' . h($extra) . '</span>'
                . ($exists ? (' <span class="pill ' . ($writable ? 'ok' : 'warn') . '">writable: ' . h(bool_text($writable)) . '</span>') : '')
                . ' ' . h($path)
                . '</div></div>';
        }
        ?>

        <?php section('App config'); ?>
        <div class="row">
            <div class="k">config.php</div>
            <div class="v">
                <span class="pill <?php echo is_file(__DIR__ . '/config.php') ? 'ok' : 'bad'; ?>"><?php echo h(bool_text(is_file(__DIR__ . '/config.php'))); ?></span>
                <span class="pill <?php echo $unlocked ? 'ok' : 'warn'; ?>"><?php echo $unlocked ? 'Unlocked' : 'Locked'; ?></span>
            </div>
        </div>
        <?php if (!$unlocked): ?>
            <div style="margin-top:10px;color:#555">
                To unlock DB test/phpinfo, set `ILDRA_DEBUG_KEY` (env) or `debug_key` in `config.php`, then visit:
                <span class="v"><?php echo h(basename(__FILE__)); ?>?key=YOUR_KEY&amp;db=1</span>
            </div>
        <?php endif; ?>

        <?php if ($unlocked && ($_GET['db'] ?? '') === '1'): ?>
            <?php section('Database test (unlocked)'); ?>
            <?php
            $dbOk = false;
            $dbMsg = '';
            try {
                if (!is_file(__DIR__ . '/db.php')) {
                    throw new RuntimeException('db.php missing.');
                }
                require_once __DIR__ . '/db.php';
                if (!function_exists('createPdo')) {
                    throw new RuntimeException('createPdo() not found in db.php');
                }
                $alerts = [];
                $pdo = createPdo($config, $alerts);
                if (!$pdo) {
                    $dbMsg = $alerts ? ('PDO not created: ' . ($alerts[0]['message'] ?? 'unknown')) : 'PDO not created.';
                } else {
                    $stmt = $pdo->query('SELECT DATABASE() AS db, NOW() AS now');
                    $row = $stmt ? $stmt->fetch() : null;
                    $dbOk = true;
                    $dbMsg = 'Connected. DB=' . ($row['db'] ?? '(none)') . ' time=' . ($row['now'] ?? '');
                }
            } catch (Throwable $e) {
                $dbMsg = get_class($e) . ': ' . $e->getMessage();
            }
            ?>
            <div class="row">
                <div class="k">PDO connect</div>
                <div class="v"><span class="pill <?php echo $dbOk ? 'ok' : 'bad'; ?>"><?php echo $dbOk ? 'OK' : 'FAIL'; ?></span> <?php echo h($dbMsg); ?></div>
            </div>
        <?php endif; ?>

        <?php if ($unlocked && ($_GET['phpinfo'] ?? '') === '1'): ?>
            <?php
            echo '</div>';
            phpinfo();
            echo '</body></html>';
            exit;
            ?>
        <?php endif; ?>
    </div>

    <div style="margin-top:14px;color:#777">
        If you’re getting HTTP 500, check your web server error log and PHP error log. Delete `debug.php` after use.
    </div>
</body>
</html>

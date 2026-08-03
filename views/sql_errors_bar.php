<?php
$sqlErrors = $_SESSION['sql_errors'] ?? [];
if (!$sqlErrors || !is_array($sqlErrors)) {
    return;
}
$sqlErrors = array_values($sqlErrors);
$errorCount = count($sqlErrors);
$uri = $_SERVER['REQUEST_URI'] ?? '';
$parts = parse_url($uri);
$path = $parts['path'] ?? '';
$query = [];
if (!empty($parts['query'])) {
    parse_str($parts['query'], $query);
}
$query['clear_sql_errors'] = '1';
$clearUrl = $path . '?' . http_build_query($query);
?>
<style>
    .sql-error-bar {
        position: sticky;
        top: 0;
        z-index: 4000;
        background: #4b0000;
        color: #fff;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        font-size: 0.85rem;
        letter-spacing: 0.01em;
        border-bottom: 2px solid rgba(255,255,255,0.18);
    }
    .sql-error-bar .sql-error-inner {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0.6rem 1rem;
        display: flex;
        gap: 1rem;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
    }
    .sql-error-bar a {
        color: #fff;
        text-decoration: underline;
        font-weight: 700;
    }
    .sql-error-list {
        max-height: 260px;
        overflow: auto;
        padding: 0 1rem 0.8rem 1rem;
    }
    .sql-error-item {
        padding: 0.4rem 0;
        border-top: 1px solid rgba(255,255,255,0.15);
        white-space: pre-wrap;
        word-break: break-word;
    }
    .sql-error-item code {
        color: #ffd7d7;
    }
</style>
<div class="sql-error-bar" role="alert" aria-live="polite">
    <div class="sql-error-inner">
        <div><strong>SQL errors:</strong> <?php echo (int)$errorCount; ?></div>
        <a href="<?php echo htmlspecialchars($clearUrl, ENT_QUOTES, 'UTF-8'); ?>">Clear</a>
    </div>
    <div class="sql-error-list">
        <?php foreach (array_reverse($sqlErrors) as $row): ?>
            <?php
                $time = $row['time'] ?? '';
                $context = $row['context'] ?? '';
                $message = $row['message'] ?? '';
                $statement = $row['statement'] ?? '';
            ?>
            <div class="sql-error-item">
                <div>[<?php echo htmlspecialchars((string)$time, ENT_QUOTES, 'UTF-8'); ?>] <?php echo htmlspecialchars((string)$context, ENT_QUOTES, 'UTF-8'); ?>: <?php echo htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php if ($statement !== ''): ?>
                    <div><code><?php echo htmlspecialchars((string)$statement, ENT_QUOTES, 'UTF-8'); ?></code></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

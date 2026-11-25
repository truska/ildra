<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../admin_settings_audit.php';
require_once __DIR__ . '/table_sort.php';
if (!roleIsSuperadminOrDeveloper((string)($currentUser['role'] ?? ''))) {
    http_response_code(403);
    exit('Access denied.');
}
header('Cache-Control: no-store, private');
ensureAdminAuditLogTable($pdo);
$records = $pdo->query("SELECT a.*, u.first_name, u.last_name, u.email FROM admin_audit_log a LEFT JOIN users u ON u.id=a.actor_user_id ORDER BY a.created_at DESC,a.id DESC")->fetchAll();
$actions = $sections = $actors = [];
foreach ($records as &$record) {
    $detail = json_decode((string)($record['detail_json'] ?? ''), true);
    $record['detail'] = adminAuditSafeValue(is_array($detail) ? $detail : []);
    $record['actor'] = trim((string)($record['detail']['actor_name'] ?? trim((string)($record['first_name'] ?? '') . ' ' . (string)($record['last_name'] ?? ''))));
    $email = (string)($record['detail']['actor_email'] ?? $record['email'] ?? '');
    $record['actor'] = ($record['actor'] !== '' ? $record['actor'] . ' · ' : '') . $email;
    if ($record['actor'] === '') $record['actor'] = $record['actor_user_id'] ? 'User #' . $record['actor_user_id'] : 'Unknown';
    $record['section'] = (string)($record['detail']['section'] ?? '');
    $record['target'] = trim((string)$record['target_type'] . ' ' . (string)$record['target_id']);
    $record['search_details'] = json_encode($record['detail'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $record['day'] = substr((string)$record['created_at'], 0, 10);
    $actions[$record['action_key']] = $record['action_key'];
    if ($record['section'] !== '') $sections[$record['section']] = ucwords(str_replace(['_', '.'], ' ', $record['section']));
    $actors[$record['actor']] = $record['actor'];
}
unset($record);
asort($actions); asort($sections); asort($actors);
$columns = [
    'day'=>['label'=>'When','field'=>'day','sortable'=>true,'sort_value'=>static fn(array $r)=>$r['created_at'],'filter'=>'date'],
    'actor'=>['label'=>'Who','sortable'=>true,'filter'=>'select','options'=>$actors],
    'action_key'=>['label'=>'Action','sortable'=>true,'filter'=>'select','options'=>$actions],
    'section'=>['label'=>'Settings tab','sortable'=>true,'filter'=>'select','options'=>$sections],
    'target'=>['label'=>'Record','sortable'=>true,'filter'=>'text'],
    'ip_address'=>['label'=>'IP address','sortable'=>true,'filter'=>'text'],
    'search_details'=>['label'=>'Changes / details','filter'=>'text','placeholder'=>'Search changed fields or values'],
];
$table = admin_table_prepare($records, $columns, 'day', 'desc');
admin_layout_start('Audit Log', 'audit_log');
?>
<?php echo render_back_navigation('tech.php', 'Back To Tech', 'Technical tools'); ?>
<div class="card-soft p-4">
    <h5>Audit Log</h5>
    <p class="text-muted small">Settings changes and existing recorded admin actions. Settings history starts when logging is enabled; earlier changes cannot be reconstructed. Unchanged saves do not add entries. Times are shown in UK time. This is a read-only record.</p>
    <?php echo admin_table_record_count($table, 'entry', 'entries'); ?>
    <form method="get">
        <input type="hidden" name="sort" value="<?php echo h($table['sort_key']); ?>">
        <input type="hidden" name="dir" value="<?php echo h($table['sort_dir']); ?>">
        <input type="hidden" name="per_page" value="<?php echo (int)$table['pagination']['per_page']; ?>">
        <div class="table-responsive"><table class="table table-sm align-middle">
            <thead><tr><?php foreach ($columns as $key=>$column): ?><th><?php echo admin_table_heading($key,$column,$table['sort_key'],$table['sort_dir']); ?></th><?php endforeach; ?></tr>
            <tr><?php foreach ($columns as $key=>$column): ?><th><?php echo admin_table_filter($key,$column,$table['filters']); ?></th><?php endforeach; ?></tr></thead>
            <tbody><?php foreach ($table['rows'] as $record): ?><tr>
                <td class="text-nowrap"><?php echo h(admin_display_datetime($record['created_at'])); ?></td>
                <td><?php echo h($record['actor']); ?><?php if ($record['actor_user_id']): ?><div class="small text-muted">User #<?php echo (int)$record['actor_user_id']; ?></div><?php endif; ?></td>
                <td><?php echo h($record['action_key']); ?></td>
                <td><?php echo h($sections[$record['section']] ?? '—'); ?></td>
                <td><?php echo h($record['target'] ?: '—'); ?></td>
                <td><?php echo h($record['ip_address'] ?: '—'); ?></td>
                <td><?php if (!empty($record['detail']['changes'])): ?>
                    <details><summary><?php echo count($record['detail']['changes']); ?> changed field(s)<?php echo ($record['detail']['outcome'] ?? '') === 'partial' ? ' (partial save)' : ''; ?></summary>
                        <table class="table table-sm mt-2"><thead><tr><th>Setting</th><th>Before</th><th>After</th></tr></thead><tbody>
                        <?php foreach ($record['detail']['changes'] as $field=>$change): ?><tr><th><?php echo h(ucwords(str_replace('_',' ',(string)$field))); ?></th><?php foreach (['before','after'] as $side): ?><td><pre class="small mb-0 text-wrap" style="min-width:10rem;max-width:30rem;overflow-wrap:anywhere"><?php $value=$change[$side] ?? null; echo h(is_array($value) ? json_encode($value,JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : ($value === null ? '(not set)' : ((string)$value === '' ? '(blank)' : (string)$value))); ?></pre></td><?php endforeach; ?></tr><?php endforeach; ?>
                        </tbody></table>
                    </details>
                <?php elseif ($record['detail']): ?><details><summary>View details</summary><pre class="small text-wrap mb-0"><?php echo h(json_encode($record['detail'],JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)); ?></pre></details><?php else: ?>—<?php endif; ?></td>
            </tr><?php endforeach; ?>
            <?php if (!$table['rows']): ?><tr><td colspan="7" class="text-muted">No audit entries match these filters.</td></tr><?php endif; ?></tbody>
        </table></div>
        <?php echo admin_table_pagination($table); ?>
        <div class="text-end mt-2"><button class="btn btn-sm btn-outline-secondary">Filter</button> <a href="audit_log.php" class="btn btn-sm btn-secondary">Clear All</a></div>
    </form>
</div>
<?php admin_layout_end(); ?>

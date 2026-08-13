<?php
declare(strict_types=1);

// This is an include-only helper. Avoid a confusing blank response if its file is
// opened directly from the browser.
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    header('Location: advertising.php');
    exit;
}

/**
 * Shared sortable table header link helper for the admin area.
 *
 * UX goals:
 * - Always reserve space for the arrow to prevent header "jumping".
 * - Use ↕ for inactive columns, and ↑/↓ for the active sort column.
 * - Toggle direction on repeated clicks.
 * - Preserve existing query parameters by default.
 */
function admin_sort_link(string $key, string $label, string $currentKey, string $currentDir, array $extraQuery = []): string
{
    $dir = ($currentKey === $key && $currentDir === 'asc') ? 'desc' : 'asc';

    $arrow = '↕';
    if ($currentKey === $key) {
        $arrow = $currentDir === 'asc' ? '↑' : '↓';
    }

    $query = $_GET;
    $query['sort'] = $key;
    $query['dir'] = $dir;
    foreach ($extraQuery as $k => $v) {
        $query[$k] = $v;
    }

    $url = '?' . http_build_query($query);

    return '<a class="text-decoration-none text-dark sort-link" href="' . h($url) . '">'
        . h($label)
        . '<span class="sort-arrow" aria-hidden="true">' . h($arrow) . '</span>'
        . '</a>';
}

/**
 * Apply configured header filters and sorting to an in-memory admin result set.
 * Column options: label, field, sort_field, sortable, filter (text|select|date),
 * options, placeholder, value (callable), compare (number|string).
 */
function admin_table_prepare(array $rows, array $columns, string $defaultSort, string $defaultDir = 'asc'): array
{
    $filters = [];
    foreach ($columns as $key => $column) {
        if (!empty($column['filter'])) $filters[$key] = trim((string)($_GET[$key] ?? ''));
    }
    $valueFor = static function (array $row, string $key, array $column) {
        if (isset($column['value']) && is_callable($column['value'])) return $column['value']($row);
        return $row[$column['field'] ?? $key] ?? '';
    };
    $rows = array_values(array_filter($rows, static function (array $row) use ($columns, $filters, $valueFor): bool {
        foreach ($filters as $key => $needle) {
            if ($needle === '') continue;
            $column = $columns[$key];
            $value = (string)$valueFor($row, $key, $column);
            if (isset($column['filter_match']) && is_callable($column['filter_match'])) {
                if (!$column['filter_match']($row, $needle, $value)) return false;
            } elseif (($column['filter'] ?? '') === 'select') {
                if ($value !== $needle) return false;
            } elseif (stripos($value, $needle) === false) return false;
        }
        return true;
    }));
    $sortKey = (string)($_GET['sort'] ?? $defaultSort);
    if (empty($columns[$sortKey]['sortable'])) $sortKey = $defaultSort;
    $sortDir = strtolower((string)($_GET['dir'] ?? $defaultDir)) === 'desc' ? 'desc' : 'asc';
    $column = $columns[$sortKey] ?? [];
    usort($rows, static function (array $a, array $b) use ($sortKey, $sortDir, $column, $valueFor): int {
        $sortValue = isset($column['sort_value']) && is_callable($column['sort_value']) ? $column['sort_value'] : null;
        $left = $sortValue ? $sortValue($a) : $valueFor($a, $sortKey, $column);
        $right = $sortValue ? $sortValue($b) : $valueFor($b, $sortKey, $column);
        $result = ($column['compare'] ?? 'string') === 'number' ? ((float)$left <=> (float)$right) : strcasecmp((string)$left, (string)$right);
        return $sortDir === 'desc' ? -$result : $result;
    });
    $total = count($rows);
    $allowedPerPage = [10, 25, 50, 100, 250, 500];
    $perPage = (int)($_GET['per_page'] ?? 50);
    if (!in_array($perPage, $allowedPerPage, true)) $perPage = 50;
    $pageCount = max(1, (int)ceil($total / $perPage));
    $page = max(1, min((int)($_GET['p'] ?? 1), $pageCount));
    $rows = array_slice($rows, ($page - 1) * $perPage, $perPage);
    return ['rows'=>$rows, 'filters'=>$filters, 'sort_key'=>$sortKey, 'sort_dir'=>$sortDir,
        'pagination'=>['total'=>$total,'per_page'=>$perPage,'page'=>$page,'page_count'=>$pageCount,'show'=>$total>25]];
}

function admin_table_pagination(array $table): string
{
    $pagination=$table['pagination']??[];
    if(empty($pagination['show']))return '';
    $page=(int)$pagination['page'];$pages=(int)$pagination['page_count'];$total=(int)$pagination['total'];$perPage=(int)$pagination['per_page'];
    $query=$_GET;unset($query['p'],$query['per_page']);
    $url=static function(int $targetPage,int $targetPerPage)use($query):string{$q=$query;$q['p']=$targetPage;$q['per_page']=$targetPerPage;return '?'.http_build_query($q);};
    ob_start(); ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
        <div class="small text-muted">Showing <?php echo $total?((($page-1)*$perPage)+1):0; ?>–<?php echo min($page*$perPage,$total); ?> of <?php echo $total; ?></div>
        <div class="d-flex align-items-center gap-2"><label class="small text-muted mb-0">Rows</label><select class="form-select form-select-sm" style="width:auto" onchange="window.location.href=this.value"><?php foreach([10,25,50,100,250,500]as$size): ?><option value="<?php echo h($url(1,$size)); ?>" <?php echo $size===$perPage?'selected':''; ?>><?php echo $size; ?></option><?php endforeach; ?></select>
        <div class="btn-group btn-group-sm"><a class="btn btn-outline-secondary <?php echo $page<=1?'disabled':''; ?>" href="<?php echo h($url(max(1,$page-1),$perPage)); ?>">Previous</a><span class="btn btn-outline-secondary disabled"><?php echo $page; ?> / <?php echo $pages; ?></span><a class="btn btn-outline-secondary <?php echo $page>=$pages?'disabled':''; ?>" href="<?php echo h($url(min($pages,$page+1),$perPage)); ?>">Next</a></div></div>
    </div><?php return (string)ob_get_clean();
}

function admin_table_record_count(array $table, string $singular = 'record', string $plural = 'records'): string
{
    $total=(int)($table['pagination']['total']??count($table['rows']??[]));
    return '<div class="small text-muted mb-2">'.h((string)$total).' '.h($total===1?$singular:$plural).'</div>';
}

function admin_table_heading(string $key, array $column, string $sortKey, string $sortDir): string
{
    $label = (string)($column['label'] ?? $key);
    return !empty($column['sortable']) ? admin_sort_link($key, $label, $sortKey, $sortDir) : h($label);
}

function admin_table_filter(string $key, array $column, array $filters): string
{
    $type = (string)($column['filter'] ?? '');
    if ($type === '') return '';
    $value = (string)($filters[$key] ?? '');
    $formAttribute = !empty($column['form']) ? ' form="' . h((string)$column['form']) . '"' : '';
    if ($type === 'select') {
        $html='<select class="form-select form-select-sm"'.$formAttribute.' name="'.h($key).'"><option value="">All</option>';
        foreach ((array)($column['options'] ?? []) as $optionValue=>$label) $html.='<option value="'.h((string)$optionValue).'"'.($value===(string)$optionValue?' selected':'').'>'.h((string)$label).'</option>';
        return $html.'</select>';
    }
    $inputType = $type === 'date' ? 'date' : 'text';
    return '<input class="form-control form-control-sm"'.$formAttribute.' type="'.$inputType.'" name="'.h($key).'" value="'.h($value).'" placeholder="'.h((string)($column['placeholder'] ?? 'Search')).'">';
}

/** Render a safely escaped table value according to its configured data type. */
function admin_table_value($value, string $type = 'text', string $empty = '—'): string
{
    $value = trim((string)$value);
    if ($value === '') return h($empty);
    if ($type === 'email') {
        return filter_var($value, FILTER_VALIDATE_EMAIL)
            ? '<a href="mailto:' . h($value) . '">' . h($value) . '</a>' : h($value);
    }
    if ($type === 'phone') {
        $telephoneSource = strpos(ltrim($value), '+') === 0 ? preg_replace('/\(0\)/', '', $value, 1) : $value;
        $telephone = preg_replace('/[^0-9+]/', '', (string)$telephoneSource) ?: '';
        return $telephone !== '' ? '<a href="tel:' . h($telephone) . '">' . h($value) . '</a>' : h($value);
    }
    if ($type === 'url') {
        $url = filter_var($value, FILTER_VALIDATE_URL) && preg_match('~^https?://~i', $value) ? $value : '';
        return $url !== '' ? '<a href="' . h($url) . '" target="_blank" rel="noopener">' . h($value) . '</a>' : h($value);
    }
    if ($type === 'postcode_map') {
        $mapUrl = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($value);
        return '<a href="' . h($mapUrl) . '" target="_blank" rel="noopener" title="Open in Google Maps">' . h($value) . '</a>';
    }
    return h($value);
}

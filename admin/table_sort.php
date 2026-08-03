<?php
declare(strict_types=1);

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

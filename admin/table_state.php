<?php
declare(strict_types=1);

// Navigation contexts are session-bound IDs, never caller-supplied return URLs.
function admin_table_state_keys(): array
{
    return array_merge([
        'q','search','sort','dir','p','per_page','page','view','tab','panel','scope','group','folder','storage',
        'category','status','type','name','date_from','date_to','filter_id','filter_sent','filter_status',
        'filter_subject','filter_to','entry_class','entry_sort','entry_dir','event_sort','event_dir',
        'tx_search','tx_type','tx_sort','tx_dir','page_id','event_id','horse_id','venue_id','news_id',
        'element_id','section_id','award_id','winners_for','source_id','template','organiser','return_query',
    ], $GLOBALS['adminTableFilterKeys'] ?? []);
}

function admin_table_state_query(array $query): array
{
    $keys = array_flip(admin_table_state_keys());
    return array_filter($query, static fn($value, $key): bool => is_scalar($value)
        && (isset($keys[$key]) || str_ends_with((string)$key, '_filter') || preg_match('/^(?:tx|entry)_/', (string)$key))
        && strlen((string)$value) <= 2000, ARRAY_FILTER_USE_BOTH);
}

function admin_table_state_context(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{24}$/', $token)) return null;
    $context = $_SESSION['admin_table_contexts'][$token] ?? null;
    if (!$context || (int)$context['user_id'] !== (int)($GLOBALS['currentUser']['id'] ?? 0)) return null;
    return $context;
}

function admin_table_state_merge_url(string $url, array $context, string $requestPath): string
{
    $parts = parse_url($url);
    if ($parts === false || isset($parts['host']) || isset($parts['scheme']) || str_starts_with($url, '//')) return $url;
    $path = $parts['path'] ?? '';
    if ($path === '') $path = $requestPath;
    if (!str_starts_with($path, '/')) $path = dirname($requestPath) . '/' . $path;
    // Only restore the original list. Never carry filters into an unrelated page.
    if ($path !== $context['path']) return $url;
    parse_str($parts['query'] ?? '', $query);
    if (isset($query['_table_clear']) || isset($query['_table_filter'])) return $url;
    if (isset($query['edit']) || isset($query['mode']) || isset($query['id'])) return $url;
    unset($query['_table_context']);
    $query = array_replace($context['query'], $query);
    return $path . ($query ? '?' . http_build_query($query) : '')
        . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
}

function admin_table_state_init(): void
{
    $rawToken = $_POST['_table_context'] ?? $_GET['_table_context'] ?? '';
    $token = is_string($rawToken) ? $rawToken : '';
    $context = admin_table_state_context($token);
    $path = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $GLOBALS['adminTableIncomingToken'] = $context ? $token : '';
    if (!$context) return;
    if ($context['path'] === $path && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
        && empty($_GET['_table_filter']) && empty($_GET['_table_clear'])) {
        $_GET = array_replace($context['query'], $_GET);
    }
    if (!empty($_GET['_table_clear'])) return;
    header_register_callback(static function () use ($context, $path, $token): void {
        foreach (headers_list() as $header) {
            if (stripos($header, 'Location:') !== 0) continue;
            $location = trim(substr($header, 9));
            $restored = admin_table_state_merge_url($location, $context, $path);
            if ($restored === $location) {
                $parts = parse_url($location);
                $destination = $parts['path'] ?? '';
                if ($destination !== '' && !str_starts_with($destination, '/')) $destination = dirname($path) . '/' . $destination;
                // Keep the origin through multi-step saves within this admin area.
                if ($parts !== false && !isset($parts['scheme']) && !isset($parts['host'])
                    && !str_starts_with($location, '//') && dirname($destination) === dirname($path)) {
                    parse_str($parts['query'] ?? '', $query);
                    if (!isset($query['_table_clear']) && !isset($query['_table_filter'])) {
                        $query['_table_context'] = $token;
                        $restored = $destination . '?' . http_build_query($query)
                            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
                    }
                }
            }
            if ($restored !== $location) header('Location: ' . $restored, true);
        }
    });
}

function admin_table_state_config(): array
{
    $path = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $token = bin2hex(random_bytes(12));
    $query = admin_table_state_query($_GET);
    $_SESSION['admin_table_contexts'][$token] = [
        'path'=>$path, 'query'=>$query, 'user_id'=>(int)($GLOBALS['currentUser']['id'] ?? 0),
    ];
    // Bound session growth, retaining independent navigation contexts for open tabs.
    if (count($_SESSION['admin_table_contexts']) > 100) {
        $_SESSION['admin_table_contexts'] = array_slice($_SESSION['admin_table_contexts'], -100, null, true);
    }
    $incoming = (string)($GLOBALS['adminTableIncomingToken'] ?? '');
    return ['candidate'=>$token, 'incoming'=>$incoming,
        'origin'=>($incoming ? admin_table_state_context($incoming) : null),
        'query'=>$query, 'userId'=>(int)($GLOBALS['currentUser']['id'] ?? 0)];
}

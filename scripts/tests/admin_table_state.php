<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/admin/table_state.php';

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$currentUser = ['id'=>7];
$_SESSION = [];
$_SERVER['SCRIPT_NAME'] = '/admin/people.php';
$_GET = ['q'=>'Anne & Bob', 'status_filter'=>'active', 'sort'=>'name', 'dir'=>'desc', 'p'=>'2', 'per_page'=>'25',
    'edit'=>'42', 'action'=>'delete', 'csrf'=>'secret', 'invalid'=>['array']];
$config = admin_table_state_config();
$context = admin_table_state_context($config['candidate']);
$assert($context !== null, 'Context must be session-bound.');
$assert(!isset($context['query']['edit']) && !isset($context['query']['action']) && !isset($context['query']['csrf']), 'Editor/actions/security data must not be saved.');
$assert($context['query']['q'] === 'Anne & Bob', 'Filter text must round-trip.');
$url = admin_table_state_merge_url('people.php', $context, '/admin/person_edit.php');
parse_str(parse_url($url, PHP_URL_QUERY), $query);
$assert($query['q'] === 'Anne & Bob' && $query['p'] === '2', 'Save redirect must retain filters and page.');
$assert(admin_table_state_merge_url('people.php?p=1', $context, '/admin/person_edit.php') === '/admin/people.php?' . http_build_query(array_replace($context['query'], ['p'=>'1'])), 'Explicit redirect values must win.');
foreach (['https://example.com/people.php', '//example.com/people.php', 'users.php', 'people.php?edit=42'] as $url) {
    $assert(admin_table_state_merge_url($url, $context, '/admin/person_edit.php') === $url, 'Unrelated destinations must not change.');
}
$currentUser = ['id'=>8];
$assert(admin_table_state_context($config['candidate']) === null, 'Another account must not reuse a context.');
$currentUser = ['id'=>7];
$assert(admin_table_state_context('../../invalid') === null, 'Malformed context must be rejected.');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['_table_context'=>$config['candidate']];
admin_table_state_init();
$assert($_GET['q'] === 'Anne & Bob', 'Back link must restore the original query before page queries run.');
$_GET = ['_table_context'=>$config['candidate'], '_table_filter'=>'1', 'q'=>''];
admin_table_state_init();
$assert($_GET['q'] === '' && !isset($_GET['status_filter']), 'A changed/cleared filter form must not restore stale filters.');
$GLOBALS['adminTableFilterKeys'] = ['custom_name'];
$assert(admin_table_state_query(['custom_name'=>'value']) === ['custom_name'=>'value'], 'Shared column keys must be captured.');
for ($i = 0; $i < 105; $i++) admin_table_state_config();
$assert(count($_SESSION['admin_table_contexts']) <= 100, 'Session growth must be bounded.');
echo "Admin table navigation/state tests passed.\n";

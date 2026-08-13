<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$basePath = $basePath === '/' ? '' : $basePath;
$siteBase = $basePath;
$siteSettings = getSiteSettings($pdo);
$pages = fetchPages($pdo, true) ?: defaultPages();
$navTree = buildNavTree($pages);
$navItemEventsUrl = $basePath . '/events';
$isLoggedIn = !empty($currentUser);
$canViewAdmin = in_array(strtolower((string)($currentUser['role'] ?? '')), ['superadmin','admin','organiser'], true);
$basketCount = count($_SESSION['basket'] ?? []);
$from = (string)($_GET['from'] ?? '/');
$level = (int)($currentUser['level'] ?? 0);
$groups = fetchHelpGroups($pdo, true);
$contextGroupIds = helpMatchingGroupIds($groups, $from);
$articles = fetchHelpArticles($pdo, $level, true);
$contextual = array_values(array_filter($articles, static fn(array $a): bool => $a['group_id'] !== null && in_array((int)$a['group_id'], $contextGroupIds, true)));
$global = array_values(array_filter($articles, static fn(array $a): bool => $a['group_id'] === null || !empty($a['is_global'])));
$keywords = [];
foreach ($articles as $article) foreach (preg_split('/\s*,\s*/', (string)$article['keywords']) ?: [] as $keyword) if ($keyword !== '') $keywords[strtolower($keyword)] = $keyword;
?>
<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Help | <?php echo h((string)$siteSettings['hero_title']); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
<style>
:root{--green:#146118;--cream:#f7f8f1;--text-main:#0c2a12}body{background:var(--cream);color:var(--text-main);font-family:Manrope,system-ui,sans-serif}.help-card{background:#fff;border:1px solid rgba(0,0,0,.06);border-radius:16px;box-shadow:0 12px 34px rgba(15,47,31,.07)}.keyword{border:1px solid #b9cbb9;background:#fff;color:#146118;border-radius:999px;padding:.3rem .7rem}.keyword:hover{background:#eaf3e8}.help-empty{border:1px dashed #b9cbb9;border-radius:12px;background:#f8fbf7}.accordion-button:not(.collapsed){color:#104d18;background:#edf6ea}.help-hidden{display:none!important}
</style><?php include __DIR__ . '/views/header_styles.php'; ?></head><body>
<?php include __DIR__ . '/views/header.php'; ?>
<main class="py-5"><div class="container">
<div class="help-card p-4 p-lg-5 mb-4"><div class="d-flex justify-content-between flex-wrap gap-3"><div><p class="text-uppercase small fw-bold text-success mb-1">Help centre</p><h1 class="fw-bold mb-2">How can we help?</h1><p class="text-muted mb-0">Search instructions available for your account level.</p></div><?php if($canViewAdmin):?><a class="btn btn-outline-success align-self-start" href="<?php echo h($basePath); ?>/admin/help.php">Manage help</a><?php endif;?></div>
<div class="input-group input-group-lg mt-4"><span class="input-group-text bg-white"><i class="fa-solid fa-magnifying-glass"></i></span><input id="help-search" class="form-control" type="search" placeholder="Search help, for example entry history" aria-label="Search help"></div>
<?php if($keywords):?><div class="d-flex flex-wrap gap-2 mt-3" aria-label="Popular keywords"><?php foreach(array_slice(array_values($keywords),0,18) as $keyword):?><button class="keyword small" type="button" data-keyword="<?php echo h($keyword); ?>"><?php echo h($keyword); ?></button><?php endforeach;?></div><?php endif;?></div>

<?php
function render_help_articles(array $items, string $id): void { ?>
<div class="accordion" id="<?php echo h($id); ?>"><?php foreach($items as $i=>$article): $cid=$id.'-'.$i; ?><div class="accordion-item help-article" data-search="<?php echo h(strtolower(strip_tags((string)$article['title'].' '.(string)$article['summary'].' '.(string)$article['keywords'].' '.(string)$article['body_html']))); ?>"><h2 class="accordion-header"><button class="accordion-button collapsed fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo h($cid); ?>"><?php echo h($article['title']); ?></button></h2><div id="<?php echo h($cid); ?>" class="accordion-collapse collapse"><div class="accordion-body"><?php if(trim((string)$article['summary'])!==''):?><p class="lead fs-6 text-muted"><?php echo h($article['summary']); ?></p><?php endif;?><?php echo render_wysiwyg((string)$article['body_html']); ?></div></div></div><?php endforeach;?></div><?php }
?>
<section class="help-card p-4 mb-4 help-section"><p class="text-uppercase small fw-bold text-success mb-1">On this page</p><h2 class="h3 fw-bold">Tasks you can do here</h2><?php if($contextual): render_help_articles($contextual,'context-help'); else:?><div class="help-empty p-3 text-muted">There is no specific help for this page yet. Try the global help below or use search.</div><?php endif;?></section>
<section class="help-card p-4 help-section"><p class="text-uppercase small fw-bold text-success mb-1">Available everywhere</p><h2 class="h3 fw-bold">General help</h2><?php if($global): render_help_articles($global,'global-help'); else:?><div class="help-empty p-3 text-muted">No general help articles have been published yet.</div><?php endif;?></section>
<div id="help-no-results" class="alert alert-info mt-4 d-none">No help matched that search.</div>
</div></main><?php include __DIR__ . '/views/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script><script>
(()=>{const input=document.querySelector('#help-search'),articles=[...document.querySelectorAll('.help-article')],empty=document.querySelector('#help-no-results');function filter(value){const q=value.trim().toLowerCase();let shown=0;articles.forEach(a=>{const ok=!q||a.dataset.search.includes(q);a.classList.toggle('help-hidden',!ok);if(ok)shown++});empty.classList.toggle('d-none',shown>0||!q)}input.addEventListener('input',()=>filter(input.value));document.querySelectorAll('[data-keyword]').forEach(b=>b.addEventListener('click',()=>{input.value=b.dataset.keyword;filter(input.value);input.focus()}));})();
</script></body></html>

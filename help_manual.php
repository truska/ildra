<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$role = strtolower((string)($currentUser['role'] ?? ''));
if (!in_array($role, ['superadmin', 'admin', 'manager', 'organiser'], true)) {
    header('Location: account');
    exit;
}
$canEditHelpArticles = in_array($role, ['superadmin', 'admin'], true) || (int)($currentUser['level'] ?? 0) >= 4;

ensureHelpTables($pdo);
$stmt = $pdo->prepare("SELECT a.*, g.name AS group_name, g.description AS group_description, g.display_order AS group_order
    FROM help_articles a
    LEFT JOIN help_groups g ON g.id = a.group_id
    WHERE a.is_published = 1
      AND a.include_in_admin_manual = 1
      AND a.min_user_level <= :level
      AND (a.max_user_level IS NULL OR a.max_user_level >= :level)
      AND (a.group_id IS NULL OR g.is_active = 1)
    ORDER BY COALESCE(g.display_order, 2147483647), COALESCE(g.name, 'General help'), a.display_order, a.title");
$stmt->execute([':level' => (int)($currentUser['level'] ?? 0)]);
$articles = $stmt->fetchAll() ?: [];
$sections = [];
foreach ($articles as $article) {
    $groupName = trim((string)($article['group_name'] ?? '')) ?: 'General help';
    if (!isset($sections[$groupName])) {
        $sections[$groupName] = [
            'description' => (string)($article['group_description'] ?? ''),
            'articles' => [],
        ];
    }
    $sections[$groupName]['articles'][] = $article;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Manual · ILDRA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <style>
        :root{--green:#0f2d17;--light:#f5f7f3;--accent:#1f7c24}html{scroll-behavior:smooth}body{background:var(--light);color:#142018}.manual-shell{max-width:1000px}.manual-header,.manual-section{background:#fff;border:1px solid rgba(15,45,23,.09);border-radius:14px;box-shadow:0 10px 30px rgba(15,45,23,.07)}.manual-kicker{color:var(--accent);letter-spacing:.08em}.contents a{color:#165e1c}.manual-article{scroll-margin-top:1rem}.manual-article+.manual-article{border-top:1px solid #dfe8dd}.manual-body img{max-width:100%;height:auto}.manual-hidden{display:none!important}.manual-section-title{color:var(--green)}.manual-article-tool{display:inline-flex;align-items:center;justify-content:center;gap:.35rem;min-height:36px;padding:.35rem .7rem;border:1px solid rgba(20,97,24,.35);border-radius:999px;background:#fff;color:#146118;font-size:.82rem;font-weight:700;text-decoration:none;white-space:nowrap;box-shadow:0 4px 12px rgba(15,45,23,.1)}.manual-article-tool svg{width:14px;height:14px;fill:currentColor}.manual-article-tool:hover,.manual-article-tool:focus-visible{background:#146118;color:#fff}
        @media print{body{background:#fff;font-size:11pt}.manual-shell{max-width:none}.manual-header,.manual-section{border:0;box-shadow:none;padding-left:0!important;padding-right:0!important}.manual-actions,.manual-search,.manual-article-actions{display:none!important}.manual-section{break-before:page}.manual-section:first-of-type{break-before:auto}.manual-article{break-inside:avoid}.manual-article a{color:inherit;text-decoration:none}}
    </style>
</head>
<body>
<main class="manual-shell container py-4 py-lg-5">
    <header class="manual-header p-4 p-lg-5 mb-4">
        <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start">
            <div><div class="manual-kicker small fw-bold text-uppercase mb-1">ILDRA</div><h1 class="fw-bold mb-2">Admin Manual</h1><p class="text-muted mb-0">Live guidance for administering the website.</p></div>
            <div class="manual-actions d-flex gap-2"><button class="btn btn-success" type="button" onclick="window.print()">Print / Save as PDF</button><button class="btn btn-outline-secondary" type="button" onclick="window.close()">Close</button></div>
        </div>
        <div class="manual-search mt-4"><label class="form-label fw-semibold" for="manual-search">Search this manual</label><input class="form-control form-control-lg" id="manual-search" type="search" placeholder="Search topics and instructions"></div>
        <?php if ($sections): ?><nav class="contents mt-4" aria-label="Manual contents"><h2 class="h5">Contents</h2><ol class="mb-0"><?php foreach ($sections as $groupName => $section): $sectionId = 'section-' . substr(sha1($groupName), 0, 10); ?><li><a href="#<?php echo h($sectionId); ?>"><?php echo h($groupName); ?></a> <span class="text-muted">(<?php echo count($section['articles']); ?>)</span></li><?php endforeach; ?></ol></nav><?php endif; ?>
    </header>

    <?php if (!$sections): ?>
        <div class="alert alert-info">No published help articles are currently included in the Admin Manual. Edit an article in Admin → Help and select <strong>Include in Admin Manual</strong>.</div>
    <?php endif; ?>
    <?php foreach ($sections as $groupName => $section): $sectionId = 'section-' . substr(sha1($groupName), 0, 10); ?>
        <section class="manual-section p-4 p-lg-5 mb-4" id="<?php echo h($sectionId); ?>">
            <h2 class="manual-section-title fw-bold"><?php echo h($groupName); ?></h2>
            <?php if (trim($section['description']) !== ''): ?><p class="text-muted"><?php echo h($section['description']); ?></p><?php endif; ?>
            <?php foreach ($section['articles'] as $article): ?>
                <article class="manual-article py-4" data-search="<?php echo h(strtolower(strip_tags((string)$article['title'].' '.(string)$article['summary'].' '.(string)$article['keywords'].' '.(string)$article['body_html']))); ?>">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <h3 class="h4 fw-bold"><?php echo h($article['title']); ?></h3>
                        <?php if ($canEditHelpArticles): ?>
                            <div class="manual-article-actions flex-shrink-0">
                                <a class="manual-article-tool" href="admin/help_edit.php?id=<?php echo (int)$article['id']; ?>" target="_blank" rel="noopener" title="Edit this help article" aria-label="Edit this help article"><svg viewBox="0 0 512 512" aria-hidden="true"><path d="M471.6 21.7c-28.9-28.9-75.7-28.9-104.6 0L344.9 43.8l123.3 123.3 22.1-22.1c28.9-28.9 28.9-75.7 0-104.6L471.6 21.7zM322.3 66.4 48.8 339.9c-8.2 8.2-14.3 18.3-17.8 29.3L.9 464.7c-3.2 10.1-.5 21.2 7 28.7s18.6 10.2 28.7 7l95.5-30.1c11-3.5 21.1-9.6 29.3-17.8L434.9 189 322.3 66.4z"/></svg><span>Edit</span></a>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (trim((string)$article['summary']) !== ''): ?><p class="lead fs-6 text-muted"><?php echo h($article['summary']); ?></p><?php endif; ?>
                    <div class="manual-body"><?php echo render_wysiwyg((string)$article['body_html']); ?></div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
    <div id="manual-no-results" class="alert alert-info d-none">No manual topics matched that search.</div>
</main>
<script>
(()=>{const input=document.getElementById('manual-search');if(!input)return;const articles=[...document.querySelectorAll('.manual-article')],sections=[...document.querySelectorAll('.manual-section')],empty=document.getElementById('manual-no-results');input.addEventListener('input',()=>{const query=input.value.trim().toLowerCase();let shown=0;articles.forEach(article=>{const match=!query||article.dataset.search.includes(query);article.classList.toggle('manual-hidden',!match);if(match)shown++});sections.forEach(section=>section.classList.toggle('manual-hidden',!section.querySelector('.manual-article:not(.manual-hidden)')));empty.classList.toggle('d-none',shown>0||!query)})})();
</script>
</body>
</html>

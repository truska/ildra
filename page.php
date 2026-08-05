<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$basePath = $basePath === '' ? '' : $basePath;
$siteBase = $basePath ?: '';
$pathSlug = '';

// Helper for PHP 7 compatibility (str_starts_with is PHP 8)
$startsWith = function (?string $haystack, string $needle): bool {
    return $haystack !== null && strpos($haystack, $needle) === 0;
};

// Prefer deriving the slug from the URL path; fall back to query params if needed.
if (isset($_SERVER['REQUEST_URI'])) {
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $uri = $uri ? ltrim($uri, '/') : '';
    $basePrefix = ltrim((string)$basePath, '/');
    if ($basePrefix !== '' && $startsWith($uri, $basePrefix . '/')) {
        $uri = substr($uri, strlen($basePrefix) + 1);
    }
    if ($startsWith($uri, 'pages/')) {
        $pathSlug = trim(substr($uri, strlen('pages/')), '/');
    } else {
        $pos = strpos($uri, 'pages/');
        if ($pos !== false) {
            $pathSlug = trim(substr($uri, $pos + strlen('pages/')), '/');
        }
    }
}
if ($pathSlug === '') {
    $pathSlug = trim($_GET['path'] ?? $_GET['page'] ?? $_GET['slug'] ?? '');
}

$page = null;
if ($pathSlug !== '') {
    if ($pdo) {
        $stmt = $pdo->prepare("SELECT * FROM pages WHERE slug = :slug AND is_published = 1 LIMIT 1");
        $stmt->execute([':slug' => $pathSlug]);
        $page = $stmt->fetch() ?: null;
    } else {
        foreach (defaultPages() as $candidate) {
            if (strcasecmp((string)($candidate['slug'] ?? ''), $pathSlug) === 0) {
                $page = $candidate;
                break;
            }
        }
    }
}
if ($page && $pathSlug !== '' && strcasecmp((string)($page['slug'] ?? ''), $pathSlug) !== 0 && $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM pages WHERE slug = :slug LIMIT 1");
    $stmt->execute([':slug' => $pathSlug]);
    $page = $stmt->fetch() ?: null;
}

$siteSettings = getSiteSettings($pdo);
$pages = fetchPages($pdo, true);
if (!$pages) {
    $pages = defaultPages();
}
$navTree = buildNavTree($pages);
$isLoggedIn = !empty($currentUser);
$canViewAdmin = in_array(strtolower((string)($currentUser['role'] ?? '')), ['superadmin', 'admin', 'organiser'], true);
$pageFromList = null;
if ($pathSlug !== '') {
    foreach ($pages as $candidate) {
        if (strcasecmp((string)($candidate['slug'] ?? ''), $pathSlug) === 0) {
            $pageFromList = $candidate;
            break;
        }
    }
    if ($pageFromList) {
        $page = $pageFromList;
    }
}


if (!$page && $pathSlug && $pdo) {
    // Allow admins to preview unpublished if logged in
    if ($canViewAdmin) {
        $stmt = $pdo->prepare("SELECT * FROM pages WHERE slug = :slug LIMIT 1");
        $stmt->execute([':slug' => $pathSlug]);
        $page = $stmt->fetch() ?: null;
    }
}

$renderPage = $pageFromList ?? $page;
$dynamicSections = $renderPage ? page_dynamic_sections($pdo, $renderPage, $basePath) : ['before_content' => '', 'after_body' => ''];

if (!$renderPage) {
    http_response_code(404);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $renderPage ? h($renderPage['title']) . ' | ' . h($siteSettings['hero_title']) : 'Page not found'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <style>
        :root {
            --green: #146118;
            --green-alt: #1f7c24;
            --cream: #f7f8f1;
            --text-main: #0c2a12;
            --muted: #476146;
        }
        body {
            background: var(--cream);
            color: var(--text-main);
            font-family: 'Manrope', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            line-height: 1.7;
        }
        .page-hero {
            background: linear-gradient(120deg, rgba(20, 97, 24, 0.9), rgba(20, 97, 24, 0.75)), url('<?php echo h($siteSettings['background_image_url']); ?>') center/cover no-repeat;
            color: #fff;
            padding: 2.5rem 0;
            position: relative;
            overflow: hidden;
        }
        .page-hero::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 25% 20%, rgba(255,255,255,0.12), transparent 32%);
            z-index: 0;
        }
        .page-hero .container { position: relative; z-index: 2; }
        .card-soft {
            border-radius: 18px;
            border: 1px solid rgba(0, 0, 0, 0.04);
            box-shadow: 0 18px 48px rgba(0, 0, 0, 0.08);
            background: #fff;
        }
        .page-body {
            color: #476146;
        }
        .page-body > :last-child {
            margin-bottom: 0;
        }
    </style>
    <?php include __DIR__ . '/views/header_styles.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/views/header.php'; ?>

    <header class="py-3" style="background: #f5f7ef; border-bottom: 1px solid rgba(0,0,0,0.05);">
        <div class="container">
            <p class="mb-1 text-uppercase small fw-bold text-muted"><?php echo h($siteSettings['hero_subtitle']); ?></p>
            <h1 class="fw-bold mb-1" style="color: var(--text-main);"><?php echo $renderPage ? h($renderPage['title']) : 'Page not found'; ?></h1>
            <?php if ($renderPage): ?>
                <div class="text-muted"><?php echo h($renderPage['excerpt'] ?? ''); ?></div>
            <?php else: ?>
                <div class="text-muted">We could not find that page.</div>
            <?php endif; ?>
        </div>
    </header>

    <main class="pt-0 pb-5">
        <div class="container">
            <?php echo $dynamicSections['before_content']; ?>

            <div class="row g-4 mt-2">
                <div class="col-lg-8">
                    <div class="card-soft p-4">
                        <?php if ($renderPage): ?>
                            <div class="lead mb-3"><?php echo h($renderPage['excerpt'] ?? ''); ?></div>
                            <div class="page-body"><?php echo (string)($renderPage['body_html'] ?? ''); ?></div>
                            <?php echo $dynamicSections['after_body']; ?>
                        <?php else: ?>
                            <p class="mb-0">Try another page from the navigation above.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card-soft p-3">
                        <div class="fw-bold mb-2">More pages</div>
                        <div class="list-group list-group-flush">
                            <?php foreach ($pages as $p): ?>
                                <a class="list-group-item list-group-item-action" href="<?php echo h($basePath); ?>/pages/<?php echo h($p['slug']); ?>">
                                    <div class="fw-semibold mb-1"><?php echo h($p['title']); ?></div>
                                    <div class="small text-muted"><?php echo h($p['excerpt']); ?></div>
                                </a>
                            <?php endforeach; ?>
                            <?php if (!$pages): ?>
                                <div class="list-group-item text-muted small">No pages published yet.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-soft p-3 mt-3">
                        <div class="fw-bold mb-2">Back to home</div>
                        <a class="btn btn-outline-success w-100" href="<?php echo h($basePath); ?>/">ILDRA Home</a>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/views/footer.php'; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>

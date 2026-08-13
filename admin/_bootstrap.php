<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

if (!$currentUser) {
    header('Location: ../index.php');
    exit;
}

// Admin area must be inaccessible while impersonating a front-end user.
if (!empty($_SESSION['act_as_original_user'])) {
    $_SESSION['flash_alerts'] = [
        [
            'type' => 'warning',
            'message' => 'You are acting as another user. Exit impersonation to access the admin area.',
        ],
    ];
    header('Location: ../account');
    exit;
}
$currentRole = strtolower((string)($currentUser['role'] ?? ''));
$canAccessAdmin = in_array($currentRole, ['superadmin', 'admin', 'organiser'], true);
if (!$canAccessAdmin) {
    header('Location: ../index.php');
    exit;
}

$adminBase = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin'), '/');
$adminBase = $adminBase === '' ? '/admin' : $adminBase;
$siteBase = rtrim(dirname($adminBase), '/');

$adminNavItems = fetchAdminMenuItems($pdo, true);

$manualFileName = trim((string)($siteSettingsBootstrap['admin_manual_filename'] ?? ''));
$manualFileName = ltrim($manualFileName, '/');
$adminManualHref = $manualFileName !== '' ? ($adminBase . '/' . $manualFileName) : null;

function admin_active(string $key, string $current): string
{
    return $key === $current ? 'active' : '';
}

function admin_layout_start(string $title, string $activeKey): void
{
    global $adminNavItems, $adminBase, $currentUser, $currentRole, $siteBase, $adminManualHref, $_basketDebug, $alerts, $successMessage;
    $userEmail = h($currentUser['email'] ?? '');
    $userRole = h($currentUser['role'] ?? '');
    $roleKey = strtolower((string)($currentRole ?? $currentUser['role'] ?? ''));
    $adminNavTree = buildAdminMenuTree($adminNavItems, $roleKey);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($title); ?> · ILDRA Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <script src="https://kit.fontawesome.com/3e4371248d.js" crossorigin="anonymous"></script>
	    <style>
        :root {
            --nav-bg: #0f2d17;
            --nav-active: #1f7c24;
            --surface: #ffffff;
            --border-soft: rgba(0,0,0,0.06);
            --text-main: #0f1f0f;
            --text-muted: #6b776c;
        }
        body {
            background: #f5f7f3;
            color: var(--text-main);
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        body.admin-nav-open {
            overflow: hidden;
        }
        .admin-shell {
            display: grid;
            grid-template-columns: 260px 1fr;
            min-height: 100vh;
        }
        .admin-mobilebar {
            display: none;
        }
        .admin-sidebar {
            background: var(--nav-bg);
            color: #f1fff0;
            padding: 1.5rem 1rem;
        }
        .admin-sidebar .brand {
            font-weight: 800;
            letter-spacing: 0.02em;
            margin-bottom: 1.25rem;
        }
        .admin-sidebar .nav-link {
            color: #d9e6d8;
            border-radius: 10px;
            margin-bottom: 0.35rem;
            font-weight: 600;
        }
        .admin-sidebar .nav-link.active {
            background: var(--nav-active);
            color: #fff;
        }
        .admin-nav-section { margin-bottom: 0.35rem; }
        .admin-nav-section summary {
            list-style: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .admin-nav-section summary::-webkit-details-marker { display: none; }
        .admin-nav-section summary::after {
            content: '';
            width: 0.48rem;
            height: 0.48rem;
            border-right: 2px solid currentColor;
            border-bottom: 2px solid currentColor;
            transform: rotate(45deg);
            transition: transform 0.18s ease;
        }
        .admin-nav-section[open] summary::after { transform: rotate(-135deg); }
        .admin-nav-section summary.active {
            background: rgba(255,255,255,0.1);
            color: #fff;
        }
        .admin-nav-children {
            margin: -0.15rem 0 0.35rem 0.7rem;
            padding-left: 0.65rem;
            border-left: 1px solid rgba(255,255,255,0.18);
        }
        .admin-nav-children .nav-link {
            font-size: 0.92rem;
            padding-top: 0.4rem;
            padding-bottom: 0.4rem;
        }
        .admin-content {
            padding: 1.5rem;
        }
        .admin-page-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
            justify-content: flex-start;
        }
        .btn.has-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
        }
        .btn.has-icon .btn-icon {
            line-height: 1;
        }
        .btn.has-icon .btn-label {
            display: inline;
        }
        .btn-group-mobile,
        .btn-row-mobile {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .btn-row-mobile > * {
            min-width: 0;
        }
        .admin-menu-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            border: 1px solid rgba(255,255,255,0.18);
            border-radius: 12px;
            background: rgba(255,255,255,0.08);
            color: #fff;
            font-size: 1.1rem;
        }
        .admin-menu-toggle:hover,
        .admin-menu-toggle:focus {
            background: rgba(255,255,255,0.16);
            color: #fff;
        }
        .admin-sidebar-close {
            display: none;
        }
        .admin-sidebar-overlay {
            display: none;
        }
	        .card-soft {
	            background: var(--surface);
	            border: 1px solid var(--border-soft);
	            border-radius: 14px;
	            box-shadow: 0 16px 46px rgba(15, 47, 31, 0.08);
	        }
	        .table-sm td, .table-sm th { vertical-align: middle; }
            .admin-data-table {
                border-color: rgba(15, 47, 31, 0.08);
            }
            .admin-data-table thead th {
                background: #eef4ec;
                color: var(--text-main);
                border-bottom-color: rgba(15, 47, 31, 0.12);
                font-size: 0.84rem;
                letter-spacing: 0.01em;
                white-space: nowrap;
            }
            .admin-data-table tbody tr:hover td {
                background: rgba(31, 124, 36, 0.035);
            }
            .admin-table-filter-row th {
                background: #f8faf6;
                padding-top: 0.45rem;
                padding-bottom: 0.45rem;
            }
            .admin-table-filter {
                display: grid;
                gap: 0.35rem;
            }
            .admin-table-filter .form-control,
            .admin-table-filter .form-select {
                min-height: 34px;
                padding: 0.32rem 0.5rem;
                border-radius: 6px;
                font-size: 0.82rem;
            }
            .admin-table-filter-actions {
                display: flex;
                justify-content: flex-end;
                gap: 0.35rem;
                white-space: nowrap;
            }
	        .sort-link { display: inline-flex; align-items: center; gap: 0.35rem; }
	        .sort-arrow { display: inline-block; width: 1ch; text-align: center; color: #888; }
            @media (max-width: 991.98px) {
                .admin-shell {
                    display: block;
                    min-height: 100vh;
                }
                .admin-mobilebar {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    gap: 1rem;
                    position: sticky;
                    top: 0;
                    z-index: 1040;
                    padding: 0.85rem 1rem;
                    background: var(--nav-bg);
                    color: #f1fff0;
                    box-shadow: 0 8px 24px rgba(15, 47, 31, 0.18);
                }
                .admin-mobilebar .brand {
                    font-weight: 800;
                    letter-spacing: 0.02em;
                }
                .admin-mobilebar .meta {
                    font-size: 0.8rem;
                    color: rgba(241,255,240,0.75);
                }
                .admin-sidebar {
                    position: fixed;
                    top: 0;
                    left: 0;
                    bottom: 0;
                    z-index: 1050;
                    width: min(82vw, 320px);
                    max-width: 320px;
                    overflow-y: auto;
                    transform: translateX(-100%);
                    transition: transform 0.22s ease;
                    box-shadow: 18px 0 40px rgba(0,0,0,0.24);
                }
                body.admin-nav-open .admin-sidebar {
                    transform: translateX(0);
                }
                .admin-sidebar-close {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    width: 40px;
                    height: 40px;
                    border: 1px solid rgba(255,255,255,0.16);
                    border-radius: 10px;
                    background: rgba(255,255,255,0.08);
                    color: #fff;
                }
                .admin-sidebar-header {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    gap: 1rem;
                }
                .admin-sidebar-overlay {
                    position: fixed;
                    inset: 0;
                    z-index: 1045;
                    background: rgba(6, 16, 9, 0.5);
                }
                body.admin-nav-open .admin-sidebar-overlay {
                    display: block;
                }
                .admin-content {
                    padding: 1rem;
                }
                .admin-content h3 {
                    font-size: 1.4rem;
                    line-height: 1.2;
                }
                .card-soft {
                    border-radius: 12px;
                }
                .admin-page-actions {
                    width: 100%;
                }
                .admin-page-actions > .btn,
                .admin-page-actions > a.btn,
                .btn-row-mobile > .btn,
                .btn-row-mobile > a.btn,
                .btn-group-mobile > .btn,
                .btn-group-mobile > a.btn {
                    flex: 1 1 auto;
                }
            }
            @media (max-width: 575.98px) {
                .btn.has-icon {
                    min-width: 44px;
                    padding-left: 0.7rem;
                    padding-right: 0.7rem;
                }
                .btn.has-icon .btn-label {
                    display: none;
                }
            }
	    </style>
	</head>
	<body>
        <?php include __DIR__ . '/../views/development_banner.php'; ?>
        <div class="admin-mobilebar">
            <button class="admin-menu-toggle" type="button" aria-label="Open admin menu" data-admin-menu-toggle>
                <i class="fa-solid fa-bars"></i>
            </button>
            <div class="flex-grow-1 min-w-0">
                <div class="brand">ILDRA Admin</div>
                <div class="meta"><?php echo $userEmail; ?> (<?php echo $userRole; ?>)</div>
            </div>
        </div>
        <div class="admin-sidebar-overlay" data-admin-menu-close></div>
        <div class="admin-shell">
        <aside class="admin-sidebar" id="admin-sidebar">
            <div class="admin-sidebar-header">
                <div class="brand">
                    ILDRA Admin
                </div>
                <button class="admin-sidebar-close" type="button" aria-label="Close admin menu" data-admin-menu-close>
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="mb-3 small text-muted">Signed in as<br><?php echo $userEmail; ?> (<?php echo $userRole; ?>)</div>
            <nav class="nav flex-column">
                <?php foreach ($adminNavTree as $item): ?>
                    <?php
                    $children = $item['children'] ?? [];
                    $itemKey = (string)($item['menu_key'] ?? '');
                    $sectionActive = $itemKey === $activeKey;
                    foreach ($children as $child) {
                        if ((string)($child['menu_key'] ?? '') === $activeKey) {
                            $sectionActive = true;
                            break;
                        }
                    }
                    ?>
                    <?php if ($children): ?>
                        <details class="admin-nav-section" <?php echo $sectionActive ? 'open' : ''; ?>>
                            <summary class="nav-link <?php echo $sectionActive ? 'active' : ''; ?>"><?php echo h((string)($item['label'] ?? 'Section')); ?></summary>
                            <div class="admin-nav-children">
                                <?php foreach ($children as $child): ?>
                                    <?php $childKey = (string)($child['menu_key'] ?? ''); ?>
                                    <a class="nav-link <?php echo admin_active($childKey, $activeKey); ?>" href="<?php echo h(adminMenuHref($child, $adminBase)); ?>"><?php echo h((string)($child['label'] ?? '')); ?></a>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php else: ?>
                        <a class="nav-link <?php echo admin_active($itemKey, $activeKey); ?>" href="<?php echo h(adminMenuHref($item, $adminBase)); ?>"><?php echo h((string)($item['label'] ?? '')); ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
                <a class="nav-link mt-3" href="<?php echo h($siteBase); ?>/">View site</a>
                <a class="nav-link" href="../?logout=1">Logout</a>
                <div class="mt-3 pt-2 border-top border-success border-opacity-25">
                    <?php if ($adminManualHref): ?>
                        <a class="nav-link" href="<?php echo h($adminManualHref); ?>" target="_blank" rel="noopener">
                            <i class="fa-solid fa-file-pdf me-2"></i>Manual
                        </a>
                    <?php else: ?>
                        <span class="nav-link disabled text-muted"><i class="fa-solid fa-file-pdf me-2"></i>Manual not set</span>
                    <?php endif; ?>
                </div>
            </nav>
        </aside>
        <main class="admin-content">
            <?php include __DIR__ . '/../views/sql_errors_bar.php'; ?>
            <h3 class="fw-bold mb-3"><?php echo h($title); ?></h3>
            <?php
            // Ensure alert variables exist for the included view
            $alerts = $alerts ?? [];
            $successMessage = $successMessage ?? null;
            include __DIR__ . '/../views/alerts.php';
            ?>
    <?php
}

function admin_layout_end(): void
{
    ?>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script>
        (function() {
            const body = document.body;
            const openButtons = document.querySelectorAll('[data-admin-menu-toggle]');
            const closeButtons = document.querySelectorAll('[data-admin-menu-close]');
            const sidebar = document.getElementById('admin-sidebar');
            if (!body || !sidebar) return;

            const openMenu = () => body.classList.add('admin-nav-open');
            const closeMenu = () => body.classList.remove('admin-nav-open');

            openButtons.forEach((button) => {
                button.addEventListener('click', openMenu);
            });
            closeButtons.forEach((button) => {
                button.addEventListener('click', closeMenu);
            });
            sidebar.querySelectorAll('a').forEach((link) => {
                link.addEventListener('click', closeMenu);
            });
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    closeMenu();
                }
            });
            window.addEventListener('resize', () => {
                if (window.innerWidth >= 992) {
                    closeMenu();
                }
            });
        })();
        (function() {
            let timer;
            function submitControl(control) {
                const form = control.form || (control.getAttribute('form') ? document.getElementById(control.getAttribute('form')) : null);
                if (form) form.requestSubmit ? form.requestSubmit() : form.submit();
            }
            document.querySelectorAll('.admin-table-filter-row select').forEach(select => {
                select.addEventListener('change', () => submitControl(select));
            });
            document.querySelectorAll('.admin-table-filter-row input[type="text"], .admin-table-filter-row input[type="search"]').forEach(input => {
                input.addEventListener('input', () => {
                    clearTimeout(timer);
                    const value = input.value.trim();
                    if (value.length === 0) return submitControl(input);
                    if (value.length < 3) return;
                    timer = setTimeout(() => submitControl(input), 450);
                });
            });
        })();
    </script>
    <?php render_password_reveal_assets(); ?>
</body>
</html>
    <?php
}

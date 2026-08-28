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
$canAccessAdmin = in_array($currentRole, ['superadmin', 'admin', 'manager', 'organiser'], true);
if (!$canAccessAdmin) {
    header('Location: ../index.php');
    exit;
}

$adminBase = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin'), '/');
$adminBase = $adminBase === '' ? '/admin' : $adminBase;
$siteBase = rtrim(dirname($adminBase), '/');

$adminNavItems = fetchAdminMenuItems($pdo, true);

$adminManualHref = ($siteBase ?: '') . '/help_manual.php';

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
            transition: grid-template-columns 0.2s ease;
        }
        body.admin-nav-collapsed .admin-shell { grid-template-columns: 76px 1fr; }
        .admin-mobilebar {
            display: none;
        }
        .admin-sidebar {
            background: var(--nav-bg);
            color: #f1fff0;
            padding: 1.5rem 1rem;
            overflow-x: hidden;
            transition: padding 0.2s ease;
        }
        .admin-sidebar-header { display:flex; align-items:center; justify-content:space-between; gap:.5rem; }
        .admin-sidebar-brand { border:0; padding:0; background:none; color:inherit; font:inherit; text-align:left; white-space:nowrap; }
        .admin-sidebar-collapse { display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; flex:0 0 36px; border:1px solid rgba(255,255,255,.16); border-radius:8px; background:rgba(255,255,255,.08); color:#fff; }
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
            display:flex;
            align-items:center;
            gap:.65rem;
            white-space:nowrap;
        }
        .admin-nav-icon { width:1.25rem; flex:0 0 1.25rem; text-align:center; }
        body.admin-nav-collapsed .admin-sidebar { padding-left:.6rem; padding-right:.6rem; }
        body.admin-nav-collapsed .admin-sidebar-brand,
        body.admin-nav-collapsed .admin-sidebar-meta,
        body.admin-nav-collapsed .admin-nav-label,
        body.admin-nav-collapsed .admin-nav-section summary::after { display:none; }
        body.admin-nav-collapsed .admin-sidebar-header { justify-content:center; }
        body.admin-nav-collapsed .admin-sidebar .nav-link { justify-content:center; padding-left:.65rem; padding-right:.65rem; }
        body.admin-nav-collapsed .admin-nav-section .admin-nav-children { display:none; }
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
            justify-content: flex-start;
        }
        .admin-nav-section summary::-webkit-details-marker { display: none; }
        .admin-nav-section summary::after {
            content: '';
            margin-left: auto;
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
            padding-bottom: 5rem;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }
        .admin-utility-footer {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem;
            position: fixed;
            right: 0;
            bottom: 0;
            left: 260px;
            z-index: 1020;
            padding: 0.55rem 1.5rem;
            background: var(--nav-bg);
            color: #f1fff0;
            box-shadow: 0 -6px 20px rgba(15,47,31,0.14);
            transition: left 0.2s ease;
        }
        body.admin-nav-collapsed .admin-utility-footer { left: 76px; }
        .admin-utility-footer-link {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.35rem 0.65rem;
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 8px;
            color: #f1fff0;
            font-size: 0.9rem;
            font-weight: 600;
            text-decoration: none;
        }
        .admin-utility-footer-link:hover,
        .admin-utility-footer-link:focus {
            background: rgba(255,255,255,0.12);
            color: #fff;
        }
        .admin-inline-reorder-handle { border:0; background:transparent; color:#146118; padding:.35rem .55rem; border-radius:6px; cursor:grab; touch-action:none; }
        .admin-inline-reorder-handle:active { cursor:grabbing; }
        .admin-inline-reorder-dragging { opacity:.55; box-shadow:0 4px 14px rgba(15,45,23,.16); }
        .admin-inline-reorder-column { width:42px; }
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
                .admin-content { padding-bottom: 1.5rem; }
                .admin-utility-footer {
                    position: static;
                    margin: auto -1.5rem -1.5rem;
                    box-shadow: none;
                }
                body.admin-nav-collapsed .admin-shell { display:block; }
                body.admin-nav-collapsed .admin-utility-footer { left: auto; }
                body.admin-nav-collapsed .admin-sidebar { padding:1.5rem 1rem; }
                body.admin-nav-collapsed .admin-sidebar-brand,
                body.admin-nav-collapsed .admin-sidebar-meta,
                body.admin-nav-collapsed .admin-nav-label { display:initial; }
                body.admin-nav-collapsed .admin-sidebar-header { justify-content:space-between; }
                body.admin-nav-collapsed .admin-sidebar .nav-link { justify-content:flex-start; padding-left:.5rem; padding-right:.5rem; }
                body.admin-nav-collapsed .admin-nav-section .admin-nav-children { display:block; }
                .admin-sidebar-collapse { display:none; }
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
            @media (max-width: 767.98px) {
                .admin-inline-reorder-controls,
                .admin-inline-reorder-column { display:none!important; }
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
                <button class="brand admin-sidebar-brand" type="button" data-admin-collapse title="Collapse or expand admin menu">ILDRA Admin</button>
                <button class="admin-sidebar-collapse" type="button" data-admin-collapse aria-label="Collapse admin menu" title="Collapse admin menu"><i class="fa-solid fa-bars"></i></button>
                <button class="admin-sidebar-close" type="button" aria-label="Close admin menu" data-admin-menu-close>
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="mb-3 small text-muted admin-sidebar-meta">Signed in as<br><?php echo $userEmail; ?> (<?php echo $userRole; ?>)</div>
            <nav class="nav flex-column">
                <a class="nav-link mb-2" href="<?php echo h($siteBase); ?>/" target="_blank" rel="noopener">
                    <i class="fa-solid fa-house admin-nav-icon" aria-hidden="true"></i><span class="admin-nav-label">Site home</span>
                </a>
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
                            <summary class="nav-link <?php echo $sectionActive ? 'active' : ''; ?>" title="<?php echo h((string)($item['label'] ?? 'Section')); ?>"><i class="<?php echo h((string)($item['icon_class'] ?? 'fa-solid fa-circle')); ?> admin-nav-icon" aria-hidden="true"></i><span class="admin-nav-label"><?php echo h((string)($item['label'] ?? 'Section')); ?></span></summary>
                            <div class="admin-nav-children">
                                <?php foreach ($children as $child): ?>
                                    <?php $childKey = (string)($child['menu_key'] ?? ''); ?>
                                    <a class="nav-link <?php echo admin_active($childKey, $activeKey); ?>" href="<?php echo h(adminMenuHref($child, $adminBase)); ?>" title="<?php echo h((string)($child['label'] ?? '')); ?>"><i class="<?php echo h((string)($child['icon_class'] ?? 'fa-solid fa-circle')); ?> admin-nav-icon" aria-hidden="true"></i><span class="admin-nav-label"><?php echo h((string)($child['label'] ?? '')); ?></span></a>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php else: ?>
                        <a class="nav-link <?php echo admin_active($itemKey, $activeKey); ?>" href="<?php echo h(adminMenuHref($item, $adminBase)); ?>" title="<?php echo h((string)($item['label'] ?? '')); ?>"><i class="<?php echo h((string)($item['icon_class'] ?? 'fa-solid fa-circle')); ?> admin-nav-icon" aria-hidden="true"></i><span class="admin-nav-label"><?php echo h((string)($item['label'] ?? '')); ?></span></a>
                    <?php endif; ?>
                <?php endforeach; ?>
                <a class="nav-link" href="../?logout=1" title="Logout"><i class="fa-solid fa-right-from-bracket admin-nav-icon"></i><span class="admin-nav-label">Logout</span></a>
                <div class="mt-3 pt-2 border-top border-success border-opacity-25">
                    <a class="nav-link" href="<?php echo h($adminManualHref); ?>" target="_blank" rel="noopener">
                        <i class="fa-solid fa-book-open admin-nav-icon"></i><span class="admin-nav-label">Manual</span>
                    </a>
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
    $helpContext = (string)($_SERVER['REQUEST_URI'] ?? '/admin/index.php');
    $contextualHelpHref = '../help?from=' . rawurlencode($helpContext);
    ?>
        <footer class="admin-utility-footer" aria-label="Admin utilities">
            <a class="admin-utility-footer-link" href="<?php echo h($contextualHelpHref); ?>" target="_blank" rel="noopener">
                <i class="fa-solid fa-circle-question" aria-hidden="true"></i>
                <span>Help for this page</span>
                <span class="visually-hidden"> (opens in a new tab)</span>
            </a>
        </footer>
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

            const collapseButtons = document.querySelectorAll('[data-admin-collapse]');
            const savedCollapsed = window.localStorage.getItem('ildra-admin-nav-collapsed') === '1';
            if (savedCollapsed && window.innerWidth >= 992) body.classList.add('admin-nav-collapsed');
            const toggleCollapsed = () => {
                if (window.innerWidth < 992) return;
                body.classList.toggle('admin-nav-collapsed');
                const collapsed = body.classList.contains('admin-nav-collapsed');
                window.localStorage.setItem('ildra-admin-nav-collapsed', collapsed ? '1' : '0');
                collapseButtons.forEach(button => button.setAttribute('aria-label', collapsed ? 'Expand admin menu' : 'Collapse admin menu'));
            };
            collapseButtons.forEach(button => button.addEventListener('click', toggleCollapsed));
            sidebar.querySelectorAll('.admin-nav-section summary').forEach(summary => {
                summary.addEventListener('click', event => {
                    if (window.innerWidth >= 992 && body.classList.contains('admin-nav-collapsed')) {
                        event.preventDefault();
                        toggleCollapsed();
                    }
                });
            });

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
        (function() {
            if (window.innerWidth < 768) return;
            document.querySelectorAll('table[data-admin-inline-reorder]').forEach(table => {
                const body = table.tBodies[0];
                const rows = body ? [...body.querySelectorAll('tr[data-order-id]')] : [];
                if (!body || rows.length < 2) return;
                const wrapper = table.closest('.table-responsive') || table;
                const controls = document.createElement('div');
                controls.className = 'admin-inline-reorder-controls d-none d-md-flex flex-wrap align-items-center gap-2 mb-3';
                controls.innerHTML = '<button class="btn btn-sm btn-outline-success" type="button" data-reorder-start><i class="fa-solid fa-arrow-down-up-across-line me-1"></i>Reorder</button><span class="d-none align-items-center gap-2" data-reorder-active><span class="small text-muted">Drag the handles, then use the existing Save button.</span><button class="btn btn-sm btn-outline-secondary" type="button" data-reorder-cancel>Cancel</button></span>';
                wrapper.parentNode.insertBefore(controls, wrapper);
                const headingRows = table.tHead ? [...table.tHead.rows] : [];
                headingRows.forEach(row => { const cell=document.createElement('th'); cell.className='admin-inline-reorder-column d-none d-md-table-cell'; row.insertBefore(cell,row.firstChild); });
                rows.forEach(row => { const cell=document.createElement('td');cell.className='admin-inline-reorder-column d-none d-md-table-cell';cell.innerHTML='<button class="admin-inline-reorder-handle" type="button" disabled aria-label="Move row"><i class="fa-solid fa-grip-vertical"></i></button>';row.insertBefore(cell,row.firstChild); });
                const start=controls.querySelector('[data-reorder-start]'), active=controls.querySelector('[data-reorder-active]'), cancel=controls.querySelector('[data-reorder-cancel]'), handles=[...body.querySelectorAll('.admin-inline-reorder-handle')];
                let dragged=null, original=[];
                const orderInput=row=>row.querySelector('[data-display-order]');
                const renumber=()=>[...body.querySelectorAll('tr[data-order-id]')].forEach((row,index)=>{const input=orderInput(row);if(input)input.value=String((index+1)*10);});
                const finish=()=>{if(!dragged)return;dragged.classList.remove('admin-inline-reorder-dragging');dragged=null;renumber();};
                start.addEventListener('click',()=>{original=[...body.querySelectorAll('tr[data-order-id]')].map(row=>({row,value:orderInput(row)?.value||''}));handles.forEach(handle=>handle.disabled=false);start.classList.add('d-none');active.classList.remove('d-none');active.classList.add('d-flex');});
                cancel.addEventListener('click',()=>{original.forEach(item=>{body.appendChild(item.row);const input=orderInput(item.row);if(input)input.value=item.value;});finish();handles.forEach(handle=>handle.disabled=true);active.classList.add('d-none');active.classList.remove('d-flex');start.classList.remove('d-none');});
                handles.forEach(handle=>{handle.addEventListener('pointerdown',event=>{if(handle.disabled)return;dragged=handle.closest('tr[data-order-id]');dragged.classList.add('admin-inline-reorder-dragging');handle.setPointerCapture(event.pointerId);event.preventDefault();});handle.addEventListener('pointermove',event=>{if(!dragged)return;const target=document.elementFromPoint(event.clientX,event.clientY)?.closest('tr[data-order-id]');if(!target||target===dragged||target.parentElement!==body)return;const rect=target.getBoundingClientRect();body.insertBefore(dragged,event.clientY<rect.top+rect.height/2?target:target.nextSibling);});handle.addEventListener('pointerup',finish);handle.addEventListener('pointercancel',finish);});
            });
        })();
    </script>
    <?php render_password_reveal_assets(); ?>
</body>
</html>
    <?php
}

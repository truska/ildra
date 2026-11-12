<?php
declare(strict_types=1);

/** Each data type has its own restore/purge rules; never accept table names from requests. */
function canManageArchives(string $role): bool
{
    return in_array(strtolower($role), ['developer', 'superadmin', 'admin'], true);
}

function archivedPagePurgeBlockers(PDO $pdo, int $pageId): array
{
    $lock = $pdo->inTransaction() ? ' FOR UPDATE' : '';
    $stmt = $pdo->prepare('SELECT title FROM pages WHERE destination_page_id=? AND id<>? ORDER BY title' . $lock);
    $stmt->execute([$pageId, $pageId]);
    $blockers = [];
    if ($stmt->fetchColumn() !== false) $blockers[] = 'Other pages link to this page as their destination. Change those destinations first (including archived pages).';
    // Uploaded files may also be linked in HTML elsewhere. Do not delete them implicitly.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM media_batches b JOIN media_batch_images i ON i.batch_id=b.id
        WHERE (b.owner_type='page' AND b.owner_id=?) OR
        (b.owner_type='page_content_element' AND b.owner_id IN (SELECT id FROM page_content_elements WHERE page_id=?))");
    $stmt->execute([$pageId, $pageId]);
    if ((int)$stmt->fetchColumn() > 0) $blockers[] = 'This page or its content sections still has uploaded images or banners. Restore as draft, review and remove those images, then archive again before purging.';
    return $blockers;
}

function fetchArchivedPages(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM pages WHERE is_archived=1 ORDER BY updated_at DESC,id DESC')->fetchAll();
}

function manageArchivedPage(PDO $pdo, int $pageId, string $action, array &$alerts): bool
{
    if ($pageId <= 0 || !in_array($action, ['restore', 'purge'], true)) {
        $alerts[] = ['type'=>'danger', 'message'=>'Invalid archive action.'];
        return false;
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM pages WHERE id=? FOR UPDATE');
        $stmt->execute([$pageId]);
        $page = $stmt->fetch();
        if (!$page || !(int)$page['is_archived']) throw new RuntimeException('This page is no longer archived. Refresh the list.');
        if ($page['slug'] === 'home') throw new RuntimeException('The home page cannot be changed through Archives.');
        if ($action === 'restore') {
            $destinationId = (int)($page['destination_page_id'] ?? 0);
            if ($destinationId > 0) {
                $stmt = $pdo->prepare('SELECT id FROM pages WHERE id=? AND is_archived=0 AND is_published=1 AND destination_page_id IS NULL FOR UPDATE');
                $stmt->execute([$destinationId]);
                if (!$stmt->fetchColumn()) throw new RuntimeException('Restore or review the destination page first. This page links to a destination that is no longer available as published content.');
            }
            $pdo->prepare('UPDATE pages SET is_archived=0,is_published=0,updated_at=NOW() WHERE id=? AND is_archived=1')->execute([$pageId]);
        } else {
            $blockers = archivedPagePurgeBlockers($pdo, $pageId);
            if ($blockers) throw new RuntimeException(implode(' ', $blockers));
            // Empty image containers and sections belong only to this page. Shared library assets are retained.
            $pdo->prepare("DELETE FROM media_batches WHERE owner_type='page_content_element' AND owner_id IN (SELECT id FROM page_content_elements WHERE page_id=?)")->execute([$pageId]);
            $pdo->prepare("DELETE FROM media_batches WHERE owner_type='page' AND owner_id=?")->execute([$pageId]);
            $pdo->prepare('DELETE FROM page_content_elements WHERE page_id=?')->execute([$pageId]);
            $pdo->prepare('DELETE FROM pages WHERE id=? AND is_archived=1')->execute([$pageId]);
        }
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $alerts[] = ['type'=>'danger', 'message'=>$e instanceof PDOException ? 'The page could not be changed. It may still be referenced by another record. Nothing was changed.' : $e->getMessage()];
        return false;
    }
}

function fetchArchivedAnnouncements(PDO $pdo): array
{
    return $pdo->query("SELECT * FROM announcements WHERE is_archived=1 AND announcement_type IN ('manual','super') ORDER BY updated_at DESC,id DESC")->fetchAll();
}

function manageArchivedAnnouncement(PDO $pdo, int $id, string $action, array &$alerts): bool
{
    if ($id <= 0 || !in_array($action, ['restore','purge'], true)) {
        $alerts[] = ['type'=>'danger', 'message'=>'Invalid archive action.'];
        return false;
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM announcements WHERE id=? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row || !(int)$row['is_archived'] || !in_array($row['announcement_type'], ['manual','super'], true)) {
            throw new RuntimeException('This announcement is no longer archived. Refresh the list.');
        }
        if ($action === 'restore') {
            $pdo->prepare('UPDATE announcements SET is_archived=0,enabled=0,updated_at=NOW() WHERE id=? AND is_archived=1')->execute([$id]);
        } else {
            // Stop legacy site settings re-importing this notice after permanent deletion.
            if (!empty($row['legacy_key'])) {
                $pdo->prepare("INSERT INTO site_settings (setting_key,setting_value,updated_at) VALUES (?,'1',NOW()) ON DUPLICATE KEY UPDATE setting_value='1',updated_at=NOW()")
                    ->execute(['announcement_legacy_purged_' . $row['legacy_key']]);
            }
            // Library images belong to the shared library, not this notice.
            $pdo->prepare('DELETE FROM announcements WHERE id=? AND is_archived=1')->execute([$id]);
        }
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $alerts[] = ['type'=>'danger', 'message'=>$e instanceof PDOException ? 'The announcement could not be changed. Nothing was changed.' : $e->getMessage()];
        return false;
    }
}

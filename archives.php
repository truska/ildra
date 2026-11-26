<?php
declare(strict_types=1);

/** Each data type has its own restore/purge rules; never accept table names from requests. */
function canManageArchives(string $role): bool
{
    return in_array(strtolower($role), ['developer', 'superadmin', 'admin'], true);
}

/** Fixed record types only; owning logins are never deleted with a rider/horse. */
function archivedSubjectTable(string $type): string
{
    return match ($type) { 'people'=>'people', 'horses'=>'horses', default=>throw new RuntimeException('Invalid archive type.') };
}

function fetchArchivedSubjects(PDO $pdo, string $type): array
{
    $table=archivedSubjectTable($type);
    $name=$type==='people' ? "TRIM(CONCAT(COALESCE(r.first_name,''),' ',COALESCE(r.last_name,'')))" : 'r.name';
    return $pdo->query("SELECT r.*, $name AS archive_name,u.email AS owner_email FROM `$table` r LEFT JOIN users u ON u.id=r.owner_user_id WHERE r.is_archived=1 ORDER BY r.updated_at DESC,r.id DESC")->fetchAll();
}

/** Find references even in nested basket/booking JSON, including withdrawn entries. */
function archivedSubjectJsonReferences(mixed $value, array $keys, int $id): bool
{
    if (!is_array($value)) return false;
    foreach ($value as $key=>$item) {
        if (in_array((string)$key,$keys,true) && (is_int($item) || is_string($item)) && ctype_digit((string)$item) && (int)$item===$id) return true;
        if (is_array($item) && archivedSubjectJsonReferences($item,$keys,$id)) return true;
    }
    return false;
}

/** Schema metadata supplies identifiers; request values never select tables/columns. */
function archivedSubjectPurgeBlockers(PDO $pdo, string $type, int $id): array
{
    $table=archivedSubjectTable($type);
    $entity=$type==='people'?'person':'horse';
    $keys=$type==='people'?['person_id','member_id','rider_id','_person_id']:['horse_id'];
    $blockers=[];$lock=$pdo->inTransaction()?' FOR UPDATE':'';
    if ($type==='horses' && $id===1) $blockers[]='The system horse cannot be permanently deleted.';
    if ($type==='people') {
        $s=$pdo->prepare('SELECT p.id FROM people p JOIN users u ON u.id=p.owner_user_id WHERE p.id=? AND u.is_protected=1 AND LOWER(TRIM(p.email))=LOWER(TRIM(u.email))'.$lock);
        $s->execute([$id]);
        if ($s->fetchColumn()!==false) $blockers[]='The protected account holder’s Person record cannot be permanently deleted.';
    }
    $columns=$pdo->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
    $catalog=[];
    foreach ($columns as $column) $catalog[$column['TABLE_NAME']][]=$column['COLUMN_NAME'];
    // Column-name conventions cover operational tables and retained legacy backups.
    // Explicit foreign keys also cover references with a different naming convention.
    $references=[];
    foreach ($catalog as $related=>$names) foreach ($names as $column) {
        $pattern=$type==='people'? '/(?:^|_)(?:person|member|rider)_id$/' : '/(?:^|_)horse_id$/';
        if (preg_match($pattern,$column)) $references[$related][$column]=true;
    }
    $s=$pdo->prepare('SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME=? AND REFERENCED_COLUMN_NAME=\'id\'');
    $s->execute([$table]);
    foreach ($s as $reference) $references[$reference['TABLE_NAME']][$reference['COLUMN_NAME']]=true;
    foreach ($references as $related=>$names) {
        foreach (array_keys($names) as $column) {
            $quotedTable=str_replace('`','``',$related);$quotedColumn=str_replace('`','``',$column);
            $s=$pdo->prepare("SELECT `$quotedColumn` FROM `$quotedTable` WHERE `$quotedColumn`=? LIMIT 1".$lock);$s->execute([$id]);
            if ($s->fetchColumn()!==false) { $blockers[]='Linked records remain in '.str_replace('_',' ',$related).'.'; break; }
        }
    }
    if (isset($catalog['share_requests'])) {
        $s=$pdo->prepare('SELECT id FROM share_requests WHERE entity_type=? AND entity_id=? LIMIT 1'.$lock);$s->execute([$entity,$id]);
        if ($s->fetchColumn()!==false) $blockers[]='Sharing requests still reference this record.';
    }
    if (isset($catalog['media_batches'])) {
        $s=$pdo->prepare('SELECT id FROM media_batches WHERE owner_type=? AND owner_id=? LIMIT 1'.$lock);$s->execute([$entity,$id]);
        if ($s->fetchColumn()!==false) $blockers[]='Media containers still reference this record.';
    }
    foreach (['baskets'=>'basket_json','booking_items'=>'metadata','finance_transactions'=>'metadata','coupon_audit'=>'metadata','email_campaign_recipients'=>'merge_json'] as $related=>$column) {
        if (!in_array($column,$catalog[$related]??[],true)) continue;
        $s=$pdo->query("SELECT `$column` FROM `$related` WHERE `$column` IS NOT NULL".$lock);
        foreach ($s as $row) {
            $json=(string)$row[$column];
            if (trim($json)==='') continue;
            try { $value=json_decode($json,true,512,JSON_THROW_ON_ERROR); }
            catch (JsonException $e) { $blockers[]='A record in '.str_replace('_',' ',$related).' needs review before references can be checked.'; break; }
            if (archivedSubjectJsonReferences($value,$keys,$id)) { $blockers[]='Linked records remain in '.str_replace('_',' ',$related).'.'; break; }
        }
    }
    return array_values(array_unique($blockers));
}

function manageArchivedSubject(PDO $pdo, string $type, int $id, string $action, array $actor, string $confirmation, array &$alerts): bool
{
    try {
        $table=archivedSubjectTable($type);
        if (!canManageArchives((string)($actor['role']??'')) || (int)($actor['id']??0)<=0) throw new RuntimeException('You do not have permission to manage these archives.');
        if ($id<=0 || !in_array($action,['restore','purge'],true)) throw new RuntimeException('Invalid archive action.');
        if ($type==='horses' && $id===1) throw new RuntimeException('The system horse cannot be changed through Archives.');
        if ($action==='purge' && $confirmation!=='DELETE') throw new RuntimeException('Type DELETE in capital letters to confirm permanent deletion.');
        $pdo->beginTransaction();
        $s=$pdo->prepare("SELECT * FROM `$table` WHERE id=? FOR UPDATE");$s->execute([$id]);$row=$s->fetch();
        if (!$row || empty($row['is_archived'])) throw new RuntimeException('This record is no longer archived. Refresh the list.');
        if ($action==='restore') {
            $s=$pdo->prepare('SELECT id FROM users WHERE id=? AND is_archived=0 FOR UPDATE');$s->execute([(int)$row['owner_user_id']]);
            if (!$s->fetchColumn()) throw new RuntimeException('Restore the owning login account before restoring this record.');
            $pdo->prepare("UPDATE `$table` SET is_archived=0,updated_at=NOW() WHERE id=? AND is_archived=1")->execute([$id]);
        } else {
            $blockers=archivedSubjectPurgeBlockers($pdo,$type,$id);
            if ($blockers) throw new RuntimeException(implode(' ',$blockers));
            $pdo->prepare("DELETE FROM `$table` WHERE id=? AND is_archived=1")->execute([$id]);
        }
        $pdo->commit();
        if (function_exists('adminAuditLog')) adminAuditLog($pdo,'archives.'.$action,$actor,$type==='people'?'person':'horse',$id,['owner_user_id'=>(int)$row['owner_user_id']]);
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $alerts[]=['type'=>'danger','message'=>$e instanceof PDOException?'This record could not be changed. It may still have linked records. Nothing was changed.':$e->getMessage()];
        return false;
    }
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

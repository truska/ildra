<?php
declare(strict_types=1);

/** Logbook validity uses the existing annual-product rules and event year. */
function entryHorseQualification(PDO $pdo,int $id,string $onDate,array $settings,bool $lock=false): array
{
    $suffix=$lock?' FOR UPDATE':'';$hasLogbook=false;
    $s=$pdo->prepare('SELECT valid_year,status,purchased_at FROM horse_logbook_purchases WHERE horse_id=?'.$suffix);$s->execute([$id]);
    foreach ($s as $row) if (annual_product_covers_year($row,'valid_year',(int)substr($onDate,0,4),$settings)) $hasLogbook=true;
    $s=$pdo->prepare("SELECT id FROM external_recognition_applications WHERE subject_type='horse' AND horse_id=? AND status='verified' AND valid_until>=?".$suffix);$s->execute([$id,$onDate]);
    return ['logbook'=>$hasLogbook,'recognised'=>$hasLogbook || $s->fetchColumn()!==false];
}

function entryRiderCanChooseHorse(PDO $pdo,int $uid,int $personId,string $onDate): bool
{
    $person=fetchPersonForUserById($pdo,$uid,$personId);
    if (!$person || !empty($person['is_archived'])) return false;
    foreach (fetchMemberships($pdo) as $membership) if ((int)($membership['member_id']??0)===$personId && ($membership['status']??'')==='active' && !empty($membership['allows_ride_entries'])) return true;
    return !empty(externalRecognitionIds($pdo,'rider',[$personId],$onDate)[$personId]);
}

/** Only names/eligibility and ID groups leave this helper; private horse data stays private. */
function entryHorseCatalogue(PDO $pdo,int $uid,array $people,string $onDate,array $settings): array
{
    ensureHorsesTables($pdo);ensureHorseLogbookTables($pdo);ensureShareTables($pdo);ensureExternalRecognitionTables($pdo);
    $s=$pdo->prepare('SELECT horse_id,status,revoked_at FROM user_horse_links WHERE user_id=?');$s->execute([$uid]);$denied=[];$remembered=[];
    foreach ($s as $link) {
        if ($link['status']!=='approved' || !empty($link['revoked_at'])) $denied[(int)$link['horse_id']]=true;
        else $remembered[(int)$link['horse_id']]=true;
    }
    $rows=$pdo->query('SELECT h.id,h.name,h.owner_user_id FROM horses h JOIN users u ON u.id=h.owner_user_id WHERE h.id<>1 AND h.is_archived=0 AND u.is_archived=0 ORDER BY h.name,h.id')->fetchAll();
    $ids=array_map(static fn(array $row):int=>(int)$row['id'],$rows);$logbooks=[];$recognised=[];
    if ($ids) {
        $recognised=externalRecognitionIds($pdo,'horse',$ids,$onDate);
        $in=implode(',',array_fill(0,count($ids),'?'));
        $s=$pdo->prepare("SELECT horse_id,valid_year,status,purchased_at FROM horse_logbook_purchases WHERE horse_id IN ($in)");$s->execute($ids);
        foreach ($s as $row) if (annual_product_covers_year($row,'valid_year',(int)substr($onDate,0,4),$settings)) $logbooks[(int)$row['horse_id']]=true;
    }
    $horses=[];$owners=[];$own=[];
    foreach ($rows as $row) {
        $id=(int)$row['id'];$owner=(int)$row['owner_user_id'];
        if (isset($denied[$id]) && $owner!==$uid) continue;
        if (empty($logbooks[$id]) && empty($recognised[$id])) continue;
        $horses[]=['id'=>$id,'name'=>(string)$row['name'],'logbook'=>!empty($logbooks[$id]),'recognised'=>true];$owners[$id]=$owner;
        if ($owner===$uid) $own[]=$id;
    }
    $links=$pdo->query("SELECT user_id,horse_id FROM user_horse_links WHERE status='approved' AND revoked_at IS NULL")->fetchAll();$linked=[];
    foreach ($links as $link) if (isset($owners[(int)$link['horse_id']])) $linked[(int)$link['user_id']][(int)$link['horse_id']]=true;
    $ownByPerson=[];$linkedByPerson=[];
    foreach ($people as $person) {
        $pid=(int)$person['id'];$owner=(int)$person['owner_user_id'];$ownByPerson[$pid]=[];$linkedByPerson[$pid]=[];
        foreach ($horses as $horse) {
            $id=$horse['id'];
            if ($owners[$id]===$owner) $ownByPerson[$pid][]=$id;
            elseif (isset($linked[$owner][$id]) || isset($remembered[$id])) $linkedByPerson[$pid][]=$id;
        }
    }
    return ['horses'=>$horses,'own_by_person'=>$ownByPerson,'linked_by_person'=>$linkedByPerson,'remembered'=>array_values(array_unique(array_merge($own,array_map('intval',array_keys($remembered)))))];
}

/** Automatic select-only link on selection; never transfer ownership or create a horse. */
function entryRememberHorse(PDO $pdo,int $uid,int $personId,int $horseId,string $onDate,array $settings): bool
{
    ensureShareTables($pdo);ensureHorseLogbookTables($pdo);ensureExternalRecognitionTables($pdo);
    if ($horseId<=1 || !entryRiderCanChooseHorse($pdo,$uid,$personId,$onDate)) throw new RuntimeException('Select an eligible rider and a horse with a valid logbook.');
    $pdo->beginTransaction();
    try {
        $s=$pdo->prepare("SELECT p.id FROM people p WHERE p.id=? AND p.is_archived=0 AND (p.owner_user_id=? OR EXISTS (SELECT 1 FROM user_person_links l WHERE l.person_id=p.id AND l.user_id=? AND l.status='approved' AND l.revoked_at IS NULL)) FOR UPDATE");$s->execute([$personId,$uid,$uid]);
        if (!$s->fetchColumn()) throw new RuntimeException('This rider is no longer available on your account.');
        $s=$pdo->prepare('SELECT h.owner_user_id FROM horses h JOIN users u ON u.id=h.owner_user_id WHERE h.id=? AND h.id<>1 AND h.is_archived=0 AND u.is_archived=0 FOR UPDATE');$s->execute([$horseId]);$owner=$s->fetchColumn();
        if ($owner===false || !entryHorseQualification($pdo,$horseId,$onDate,$settings,true)['recognised']) throw new RuntimeException('This horse no longer has a valid logbook or recognition for this event.');
        $created=false;
        if ((int)$owner!==$uid) {
            $s=$pdo->prepare('SELECT status,revoked_at FROM user_horse_links WHERE user_id=? AND horse_id=? FOR UPDATE');$s->execute([$uid,$horseId]);$link=$s->fetch();
            if ($link && ($link['status']!=='approved' || !empty($link['revoked_at']))) throw new RuntimeException('Access to this horse was previously removed. Please contact an administrator.');
            if (!$link) { $pdo->prepare("INSERT INTO user_horse_links(user_id,horse_id,permission,status) VALUES (?,?,'select_only','approved')")->execute([$uid,$horseId]);$created=true; }
        }
        $pdo->commit();return $created;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack();throw $e; }
}

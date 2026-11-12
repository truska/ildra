<?php
declare(strict_types=1);

function ridePricingReady(?PDO $pdo): bool
{
    if (!$pdo) return false;
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('ride_classes','ride_scheme_classes','ride_pricing_state','event_pricing_locks')");
    if ((int)$stmt->fetchColumn() !== 4) return false;
    return (bool)$pdo->query("SELECT 1 FROM ride_pricing_state WHERE migration_key='master-ride-classes-v1'")->fetchColumn();
}

function ridePricingSchema(PDO $pdo): void
{
    $sql = file_get_contents(__DIR__.'/migrations/2026-11-10-master-ride-classes.sql');
    foreach (preg_split('/;\s*(?:\R|$)/', $sql) as $statement) {
        if (trim($statement) !== '') $pdo->exec($statement);
    }
}

function rideTypeIds(PDO $pdo): array
{
    $ids=array_map('intval',$pdo->query("SELECT id FROM event_types WHERE LOWER(TRIM(name))='ride' OR form_profile='ride'")->fetchAll(PDO::FETCH_COLUMN));
    if(ridePricingReady($pdo)) {
        foreach($pdo->query("SELECT migration_key FROM ride_pricing_state WHERE migration_key LIKE 'ride-type-id:%'")->fetchAll(PDO::FETCH_COLUMN) as $key)$ids[]=(int)substr($key,13);
    }
    return array_values(array_unique($ids));
}

function isMasterRideScheme(PDO $pdo, int $schemeId): bool
{
    if (!ridePricingReady($pdo)) return false;
    $stmt=$pdo->prepare('SELECT 1 FROM ride_scheme_classes WHERE scheme_id=:id LIMIT 1');$stmt->execute([':id'=>$schemeId]);
    if($stmt->fetchColumn())return true;
    $types=rideTypeIds($pdo);if(!$types)return false;
    $stmt=$pdo->prepare('SELECT 1 FROM pricing_scheme_event_types WHERE scheme_id=:id AND event_type_id IN ('.implode(',',$types).') LIMIT 1');
    $stmt->execute([':id'=>$schemeId]);return (bool)$stmt->fetchColumn();
}

function fetchMasterRideClasses(?PDO $pdo, bool $publicOnly = false): array
{
    if (!ridePricingReady($pdo)) return [];
    return $pdo->query('SELECT * FROM ride_classes'.($publicOnly?' WHERE is_active=1 AND show_on_public=1':'').' ORDER BY sort_order,id')->fetchAll();
}

function fetchMasterSchemeRows(PDO $pdo, int $schemeId, bool $includeInactive = false): array
{
    $stmt = $pdo->prepare('SELECT c.*,s.sort_order AS sort_order,c.id AS master_class_id FROM ride_scheme_classes s JOIN ride_classes c ON c.id=s.class_id WHERE s.scheme_id=:id'.($includeInactive?'':' AND c.is_active=1').' ORDER BY s.sort_order,c.sort_order,c.id');
    $stmt->execute([':id'=>$schemeId]);
    return $stmt->fetchAll();
}

function saveMasterRideClass(PDO $pdo, array $data, array &$alerts): ?int
{
    $id = max(0,(int)($data['class_id']??0));
    $name = trim((string)($data['class_name']??''));
    $code = trim((string)($data['class_code']??''));
    $group = strtoupper(trim((string)($data['class_group']??'')));
    $eligibility = (string)($data['rider_eligibility']??'all');
    $price = trim((string)($data['price']??''));
    $foreign = trim((string)($data['foreign_recognition_price']??''));
    $distance = trim((string)($data['distance_km']??''));
    if ($name==='' || mb_strlen($name)>190 || $code==='' || mb_strlen($code)>32 || !in_array($group,['PR','VPR','CTR','ER','OTHER'],true)
        || !in_array($eligibility,['all','junior','senior'],true) || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D',$price)
        || ($foreign!=='' && !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D',$foreign)) || ($distance!=='' && (!ctype_digit($distance) || (int)$distance<1 || (int)$distance>1000))) {
        $alerts[]=['type'=>'danger','message'=>'Enter a class name, unique code, valid prices, group, rider eligibility and optional distance (1–1000 km).'];
        return null;
    }
    try {
        $dupe=$pdo->prepare('SELECT id FROM ride_classes WHERE class_code=:code AND id<>:id');
        $dupe->execute([':code'=>$code,':id'=>$id]);
        if ($dupe->fetchColumn()) throw new RuntimeException('That class code is already in use.');
        $params=[':name'=>$name,':code'=>$code,':grp'=>$group,':distance'=>$distance===''?null:(int)$distance,
            ':price'=>$price,':foreign'=>!empty($data['is_member_price'])&&$foreign!==''?$foreign:null,
            ':member'=>!empty($data['is_member_price'])?1:0,':junior'=>!empty($data['is_junior_ride'])?1:0,
            ':eligibility'=>$eligibility,':sort'=>max(0,(int)($data['sort_order']??10)),
            ':active'=>!empty($data['is_active'])?1:0,':public'=>!empty($data['show_on_public'])?1:0];
        if ($id) {
            $params[':id']=$id;
            $sql='UPDATE ride_classes SET class_name=:name,class_code=:code,class_group=:grp,distance_km=:distance,price=:price,foreign_recognition_price=:foreign,is_member_price=:member,is_junior_ride=:junior,rider_eligibility=:eligibility,sort_order=:sort,is_active=:active,show_on_public=:public,updated_at=NOW() WHERE id=:id';
        } else {
            $sql='INSERT INTO ride_classes(class_name,class_code,class_group,distance_km,price,foreign_recognition_price,is_member_price,is_junior_ride,rider_eligibility,sort_order,is_active,show_on_public) VALUES(:name,:code,:grp,:distance,:price,:foreign,:member,:junior,:eligibility,:sort,:active,:public)';
        }
        $pdo->prepare($sql)->execute($params);
        return $id ?: (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        $alerts[]=['type'=>'danger','message'=>$e instanceof RuntimeException && !$e instanceof PDOException?$e->getMessage():'Could not save ride class.'];
        return null;
    }
}

function saveMasterRideScheme(PDO $pdo, array $data, array &$alerts, ?int $schemeId): ?int
{
    $name=trim((string)($data['name']??''));
    $types=array_values(array_unique(array_filter(array_map('intval',(array)($data['event_type_ids']??[])))));
    $rideTypes=rideTypeIds($pdo);
    $ids=array_values(array_unique(array_filter(array_map('intval',(array)($data['master_class_ids']??[])))));
    if ($name==='' || mb_strlen($name)>190 || !$ids || !$types || array_diff($types,$rideTypes)) {
        $alerts[]=['type'=>'danger','message'=>'Enter a scheme name, select Ride, and enable at least one master ride class. Ride schemes cannot also contain non-ride event types.'];
        return null;
    }
    try {
        $pdo->beginTransaction();
        $rows=$pdo->query('SELECT id,is_active,sort_order FROM ride_classes ORDER BY sort_order,id FOR UPDATE')->fetchAll();
        $valid=array_column($rows,null,'id');
        $existingIds=[];
        if($schemeId){$existing=$pdo->prepare('SELECT class_id FROM ride_scheme_classes WHERE scheme_id=:id FOR UPDATE');$existing->execute([':id'=>$schemeId]);$existingIds=array_map('intval',$existing->fetchAll(PDO::FETCH_COLUMN));}
        foreach ($ids as $id) if (!isset($valid[$id]) || (!$valid[$id]['is_active'] && !in_array($id,$existingIds,true))) throw new RuntimeException('Select active master classes only.');
        if ($schemeId) {
            $stmt=$pdo->prepare('SELECT id FROM pricing_schemes WHERE id=:id FOR UPDATE');$stmt->execute([':id'=>$schemeId]);
            if (!$stmt->fetchColumn()) throw new RuntimeException('Pricing scheme no longer exists.');
            $pdo->prepare('UPDATE pricing_schemes SET name=:name,updated_at=NOW() WHERE id=:id')->execute([':name'=>$name,':id'=>$schemeId]);
        } else {
            $pdo->prepare('INSERT INTO pricing_schemes(name) VALUES(:name)')->execute([':name'=>$name]);$schemeId=(int)$pdo->lastInsertId();
        }
        $pdo->prepare('DELETE FROM pricing_scheme_event_types WHERE scheme_id=:id')->execute([':id'=>$schemeId]);
        $ins=$pdo->prepare('INSERT INTO pricing_scheme_event_types(scheme_id,event_type_id) VALUES(:scheme,:type)');
        foreach($types as $type)$ins->execute([':scheme'=>$schemeId,':type'=>$type]);
        $defaults=array_map('intval',(array)($data['default_for_type_ids']??[]));
        $pdo->prepare('UPDATE event_types SET default_pricing_scheme_id=NULL WHERE default_pricing_scheme_id=:id')->execute([':id'=>$schemeId]);
        $set=$pdo->prepare('UPDATE event_types SET default_pricing_scheme_id=:scheme WHERE id=:id');
        foreach(array_intersect($defaults,$types) as $type)$set->execute([':scheme'=>$schemeId,':id'=>$type]);
        $pdo->prepare('DELETE FROM ride_scheme_classes WHERE scheme_id=:id')->execute([':id'=>$schemeId]);
        $ins=$pdo->prepare('INSERT INTO ride_scheme_classes(scheme_id,class_id,sort_order) VALUES(:scheme,:class,:sort)');
        foreach($ids as $id)$ins->execute([':scheme'=>$schemeId,':class'=>$id,':sort'=>max(0,(int)($data['master_sort'][$id]??$valid[$id]['sort_order']))]);
        $pdo->commit();return $schemeId;
    } catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        $alerts[]=['type'=>'danger','message'=>$e instanceof RuntimeException && !$e instanceof PDOException?$e->getMessage():'Could not save ride pricing scheme.'];return null;
    }
}

function eventPricingOpeningTime(array $event): ?int
{
    if (($event['status']??'')!=='published') return null;
    $times=[];
    if (!empty($event['entry_open_at'])) $times[]=strtotime((string)$event['entry_open_at']);
    elseif (!empty($event['event_date'])) $times[]=strtotime((string)$event['event_date'].' -1 month');
    if (!empty($event['non_member_entry_open_at'])) $times[]=strtotime((string)$event['non_member_entry_open_at']);
    return $times ? min($times) : null;
}

/** Stored per-event reopening time; older events use a fixed six-hour gap until saved. */
function eventLateEntryOpeningTime(array $event): ?int
{
    $zone = new DateTimeZone('Europe/London');
    try {
        if (!empty($event['late_entry_open_at'])) return (new DateTimeImmutable((string)$event['late_entry_open_at'], $zone))->getTimestamp();
        $close = $event['entry_close_at'] ?? null;
        if (!$close && !empty($event['event_date'])) $close = $event['event_date'] . ' -1 week 23:59:59';
        return $close ? (new DateTimeImmutable((string)$close, $zone))->getTimestamp() + 6 * 3600 : null;
    } catch (Exception $e) { return null; }
}

function eventLateEntryWindowOpen(array $event, ?int $now = null): bool
{
    if (($event['status'] ?? '') !== 'published' || empty($event['late_entries_enabled'])) return false;
    $now ??= time();
    $open = eventPricingOpeningTime($event);
    if ($open !== null && $now < $open) return false;
    $zone = new DateTimeZone('Europe/London');
    try {
        $close = !empty($event['entry_close_at']) ? (new DateTimeImmutable((string)$event['entry_close_at'], $zone))->getTimestamp() : null;
        if ($close === null && !empty($event['event_date'])) $close = (new DateTimeImmutable((string)$event['event_date'].' -1 week 23:59:59', $zone))->getTimestamp();
        $lateClose = !empty($event['late_entry_close_at']) ? (new DateTimeImmutable((string)$event['late_entry_close_at'], $zone))->getTimestamp() : null;
        $reopen = eventLateEntryOpeningTime($event);
        return $close !== null && $reopen !== null && $lateClose !== null && $reopen > $close && $now >= $reopen && $now < $lateClose;
    } catch (Exception $e) { return false; }
}

function eventPricingEntriesOpen(array $event, ?int $now = null): bool
{
    if (($event['status']??'')!=='published') return false;
    $now ??= time();
    $open=eventPricingOpeningTime($event);
    if ($open!==null && $now<$open) return false;
    $close=!empty($event['entry_close_at'])?strtotime((string)$event['entry_close_at']):null;
    if ($close===null && !empty($event['event_date'])) $close=strtotime((string)$event['event_date'].' -1 week 23:59:59');
    if ($close===null || $now<$close) return true;
    return eventLateEntryWindowOpen($event, $now);
}

function eventPricingHasEntries(PDO $pdo, int $eventId): bool
{
    // Include withdrawn/cancelled entries: their historical financial records still matter.
    $stmt=$pdo->prepare('SELECT 1 FROM booking_items WHERE event_id=:id LIMIT 1');
    $stmt->execute([':id'=>$eventId]);return (bool)$stmt->fetchColumn();
}

function eventPricingLocked(?PDO $pdo, int $eventId): bool
{
    if (!$pdo || !ridePricingReady($pdo) || $eventId<=0) return false;
    $stmt=$pdo->prepare('SELECT * FROM events WHERE id=:id');$stmt->execute([':id'=>$eventId]);$event=$stmt->fetch();
    if(!$event)return false;
    // Old event_pricing_locks records are deliberately ignored; dates can be corrected
    // while closed and without any received entries.
    return eventPricingHasEntries($pdo,$eventId) || eventPricingEntriesOpen($event);
}

function eventPricingComparableRows(array $rows): array
{
    return array_map(static function(array $row): array {
        $result=[];
        foreach(['class_name','class_code','class_group','rider_eligibility'] as $key)$result[$key]=(string)($row[$key]??'');
        foreach(['sort_order','is_member_price','is_junior_ride','enabled'] as $key)$result[$key]=(int)($row[$key]??0);
        $result['price']=number_format((float)($row['price']??0),2,'.','');
        $result['foreign_recognition_price']=($row['foreign_recognition_price']??'')===''?null:number_format((float)$row['foreign_recognition_price'],2,'.','');
        return $result;
    },array_values($rows));
}

function matchMasterRideClass(array $row, array $masters): array
{
    $code=mb_strtolower(trim(preg_replace('/^CRT\b/','CTR',(string)($row['class_code']??''))));
    $matches=array_filter($masters,static fn($m)=>$code!=='' && mb_strtolower(trim($m['class_code']))===$code);
    if(!$matches && strtoupper((string)($row['class_group']??''))==='PR') {
        $matches=array_filter($masters,static fn($m)=>$m['class_group']==='PR' && (int)$m['is_member_price']===(int)$row['is_member_price'] && $m['rider_eligibility']===($row['rider_eligibility']??'senior'));
    }
    if(count($matches)!==1)throw new RuntimeException('Class “'.($row['class_name']??$code).'” does not match one active master ride class. Select a current pricing scheme.');
    return reset($matches);
}

function resolveRideSchemeEventRows(PDO $pdo, int $schemeId, array $postedRows): array
{
    $masters=fetchMasterSchemeRows($pdo,$schemeId);
    if(!$masters)throw new RuntimeException('The selected ride scheme has no active classes.');
    $result=[];$seen=[];
    foreach($postedRows as $row){
        $master=matchMasterRideClass($row,$masters);
        if(isset($seen[$master['id']]))throw new RuntimeException('A ride class was selected more than once.');
        $seen[$master['id']]=true;
        $master['sort_order']=$row['sort_order'];$master['enabled']=$row['enabled'];
        $result[]=$master;
    }
    return $result;
}

function preserveRideEventSnapshotRows(array $existing, array $postedRows, array $postedKeys): array
{
    $byKey=[];foreach($existing as $row)$byKey['id'.$row['id']]=$row;
    $result=[];
    foreach(array_values($postedRows) as $i=>$row){
        $key=$postedKeys[$i]??'';
        if(!isset($byKey[$key]))throw new RuntimeException('Use Apply Template to select current master ride classes.');
        $saved=$byKey[$key];$saved['sort_order']=$row['sort_order'];$saved['enabled']=$row['enabled'];$result[]=$saved;
    }
    return $result;
}

<?php
declare(strict_types=1);

function normaliseMasterRideRow(array $row): array
{
    foreach(['class_name','class_code'] as $key) {
        $row[$key]=preg_replace('/^CRT\b/', 'CTR', (string)($row[$key]??''));
    }
    if (str_contains($row['class_name'],'[Platinum Final') && !str_ends_with($row['class_name'],']')) $row['class_name'].=']';
    if (!preg_match('/^\d{1,8}\.\d{2}$/D',(string)$row['price']) || trim($row['class_name'])==='' || trim($row['class_code'])==='' || mb_strlen($row['class_code'])>32
        || !in_array($row['rider_eligibility'],['all','junior','senior'],true)) throw new RuntimeException('Invalid master row '.($row['id']??'?').'.');
    preg_match('/\b(\d+)\s*km\b/i',$row['class_name'],$distance);
    $row['distance_km']=isset($distance[1])?(int)$distance[1]:null;
    return $row;
}

function buildRidePricingMigrationPlan(PDO $pdo, int $masterSchemeId): array
{
    $schemes=$pdo->query('SELECT * FROM pricing_schemes ORDER BY id')->fetchAll();
    $rows=$pdo->query('SELECT * FROM pricing_scheme_rows ORDER BY scheme_id,sort_order,id')->fetchAll();
    $maps=$pdo->query('SELECT * FROM pricing_scheme_event_types ORDER BY scheme_id,event_type_id')->fetchAll();
    $types=$pdo->query('SELECT * FROM event_types ORDER BY id')->fetchAll();
    $sourceSha=hash('sha256',json_encode([$schemes,$rows,$maps,$types],JSON_THROW_ON_ERROR));
    $rideIds=array_map('intval',array_column(array_filter($types,static fn($r)=>strtolower(trim($r['name']))==='ride' || ($r['form_profile']??'')==='ride'),'id'));
    if(!$rideIds)throw new RuntimeException('Ride event type not found.');
    $rideSchemes=[];
    foreach($maps as $map)if(in_array((int)$map['event_type_id'],$rideIds,true))$rideSchemes[(int)$map['scheme_id']]=true;
    if(!isset($rideSchemes[$masterSchemeId]))throw new RuntimeException('The chosen master scheme must apply to Ride.');
    foreach($maps as $map)if(isset($rideSchemes[(int)$map['scheme_id']]) && !in_array((int)$map['event_type_id'],$rideIds,true))throw new RuntimeException('Separate mixed ride/non-ride scheme '.$map['scheme_id'].' before migration.');
    $masters=[];$codes=[];
    foreach($rows as $row)if((int)$row['scheme_id']===$masterSchemeId){
        $normal=normaliseMasterRideRow($row);
        $code=mb_strtolower($normal['class_code']);
        if(isset($codes[$code]))throw new RuntimeException('Duplicate master class code: '.$normal['class_code']);
        $codes[$code]=true;$masters[(int)$row['id']]=$normal;
    }
    if(!$masters)throw new RuntimeException('Master scheme has no classes.');
    $selections=[];$changes=[];$seen=[];
    foreach($rows as $row){
        $sid=(int)$row['scheme_id'];if(!isset($rideSchemes[$sid]))continue;
        if($sid===$masterSchemeId){$match=$masters[(int)$row['id']];}
        else {
            $code=mb_strtolower(trim(preg_replace('/^CRT\b/','CTR',(string)($row['class_code']??''))));
            $candidates=array_filter($masters,static fn($m)=>$code!=='' && mb_strtolower(trim($m['class_code']))===$code);
            // PR rows historically had no codes. Membership + age uniquely identify their master variants.
            if(!$candidates && strtoupper((string)$row['class_group'])==='PR')$candidates=array_filter($masters,static fn($m)=>$m['class_group']==='PR' && (int)$m['is_member_price']===(int)$row['is_member_price'] && $m['rider_eligibility']===$row['rider_eligibility']);
            if(count($candidates)!==1)throw new RuntimeException('Ambiguous/unmatched class in scheme '.$sid.': '.$row['class_name'].'. No data changed.');
            $match=reset($candidates);
        }
        $key=$sid.':'.$match['id'];if(isset($seen[$key]))throw new RuntimeException('Duplicate selection of '.$match['class_name'].' in scheme '.$sid);
        $seen[$key]=true;
        $selections[]=['scheme_id'=>$sid,'source_master_id'=>(int)$match['id'],'sort_order'=>(int)$row['sort_order']];
        if($row['price']!==$match['price'])$changes[]=['scheme_id'=>$sid,'class'=>$row['class_name'],'old_price'=>$row['price'],'master_price'=>$match['price']];
    }
    foreach(array_keys($rideSchemes) as $sid)if(!array_filter($selections,static fn($r)=>$r['scheme_id']===$sid))throw new RuntimeException('Ride scheme '.$sid.' has no selected classes.');
    return ['source_sha256'=>$sourceSha,'master_scheme_id'=>$masterSchemeId,'masters'=>array_values($masters),'selections'=>$selections,'price_changes'=>$changes,'ride_scheme_ids'=>array_keys($rideSchemes)];
}

function applyRidePricingMigration(PDO $pdo, int $masterSchemeId, string $expectedSha): array
{
    if(ridePricingReady($pdo))return ['already_applied'=>true,'source_sha256'=>$pdo->query("SELECT source_sha256 FROM ride_pricing_state WHERE migration_key='master-ride-classes-v1'")->fetchColumn()];
    $plan=buildRidePricingMigrationPlan($pdo,$masterSchemeId);
    if(!hash_equals($plan['source_sha256'],$expectedSha))throw new RuntimeException('Pricing configuration changed since preview. Review a fresh preview.');
    // DDL is intentionally outside the data transaction (MariaDB implicitly commits DDL).
    ridePricingSchema($pdo);
    $lock=$pdo->query("SELECT GET_LOCK('master-ride-classes-v1',10)")->fetchColumn();
    if((int)$lock!==1)throw new RuntimeException('Another master-price migration is running.');
    try {
        $applied=$pdo->query("SELECT source_sha256 FROM ride_pricing_state WHERE migration_key='master-ride-classes-v1'")->fetchColumn();
        if($applied)return ['already_applied'=>true,'source_sha256'=>$applied];
        $pdo->beginTransaction();
        foreach(['pricing_schemes','pricing_scheme_rows','pricing_scheme_event_types','event_types'] as $table)$pdo->query('SELECT * FROM '.$table.' FOR UPDATE')->fetchAll();
        $plan=buildRidePricingMigrationPlan($pdo,$masterSchemeId);
        if(!hash_equals($plan['source_sha256'],$expectedSha))throw new RuntimeException('Pricing configuration changed since preview.');
        if((int)$pdo->query('SELECT COUNT(*) FROM ride_classes')->fetchColumn()>0)throw new RuntimeException('Unregistered master data already exists; manual review required.');
        $insert=$pdo->prepare('INSERT INTO ride_classes(source_key,class_name,class_code,class_group,distance_km,price,foreign_recognition_price,is_member_price,is_junior_ride,rider_eligibility,sort_order) VALUES(:source,:name,:code,:grp,:distance,:price,:foreign,:member,:junior,:eligibility,:sort)');
        $ids=[];
        foreach($plan['masters'] as $row){
            $insert->execute([':source'=>'initial-ride-row-'.$row['id'],':name'=>$row['class_name'],':code'=>$row['class_code'],':grp'=>$row['class_group'],':distance'=>$row['distance_km'],':price'=>$row['price'],':foreign'=>$row['foreign_recognition_price'],':member'=>$row['is_member_price'],':junior'=>$row['is_junior_ride'],':eligibility'=>$row['rider_eligibility'],':sort'=>$row['sort_order']]);
            $ids[(int)$row['id']]=(int)$pdo->lastInsertId();
        }
        $insert=$pdo->prepare('INSERT INTO ride_scheme_classes(scheme_id,class_id,sort_order) VALUES(:scheme,:class,:sort)');
        foreach($plan['selections'] as $row)$insert->execute([':scheme'=>$row['scheme_id'],':class'=>$ids[$row['source_master_id']],':sort'=>$row['sort_order']]);
        $pdo->prepare("INSERT INTO ride_pricing_state(migration_key,source_sha256) VALUES('master-ride-classes-v1',:sha)")->execute([':sha'=>$expectedSha]);
        $typeMarker=$pdo->prepare('INSERT INTO ride_pricing_state(migration_key,source_sha256) VALUES(:key,:sha)');
        foreach(rideTypeIds($pdo) as $type)$typeMarker->execute([':key'=>'ride-type-id:'.$type,':sha'=>$expectedSha]);
        $pdo->commit();return ['already_applied'=>false,'classes'=>count($ids),'selections'=>count($plan['selections']),'source_sha256'=>$expectedSha];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    finally{$pdo->query("SELECT RELEASE_LOCK('master-ride-classes-v1')");}
}

<?php
declare(strict_types=1);
require_once __DIR__.'/cms.php';

/** Read existing commerce configuration without schema changes inside transactions. */
function migrationCommerceSettings(PDO $pdo,bool $lock=false):array
{
    $settings=defaultSiteSettings();
    $s=$pdo->query("SELECT setting_key,setting_value FROM site_settings WHERE setting_key IN ('membership_next_year_from','purchasing_paused_at','purchasing_paused_seconds','purchasing_pause_message')".($lock?' FOR UPDATE':''));
    foreach($s as $row)$settings[$row['setting_key']]=$row['setting_value'];
    return $settings;
}

function migrationLogbookOffer(PDO $pdo,bool $lock=false,?array $settings=null):?array
{
    $settings??=migrationCommerceSettings($pdo,$lock);
    $row=$pdo->query("SELECT * FROM horse_logbook_types WHERE status='published' ORDER BY id LIMIT 1".($lock?' FOR UPDATE':''))->fetch();
    if(!$row)return null;
    $row['valid_year']=horse_logbook_purchase_year($settings);return $row;
}

function migrationLogbookQuote(?array $offer):string
{
    return $offer?hash('sha256',json_encode([(int)$offer['id'],(int)$offer['valid_year'],number_format((float)$offer['cost'],2,'.',''),$offer['name']],JSON_THROW_ON_ERROR)):'';
}

function migrationHorseChoices(PDO $pdo,int $uid,bool $lock=false):array
{
    $s=$pdo->prepare('SELECT * FROM migration_horse_choices WHERE user_id=? ORDER BY horse_id'.($lock?' FOR UPDATE':''));$s->execute([$uid]);$rows=[];
    foreach($s as $row)$rows[(int)$row['horse_id']]=$row;return $rows;
}

function migrationHorseNameHash(array $horse):string
{
    return hash('sha256',json_encode([(int)$horse['id'],(int)$horse['owner_user_id'],(string)$horse['name']],JSON_THROW_ON_ERROR));
}

function migrationHasLogbook(PDO $pdo,int $hid,int $year,bool $lock=false):bool
{
    $s=$pdo->prepare('SELECT id FROM horse_logbook_purchases WHERE horse_id=? AND valid_year=? LIMIT 1'.($lock?' FOR UPDATE':''));$s->execute([$hid,$year]);return (bool)$s->fetchColumn();
}

function migrationHorseChoiceValid(PDO $pdo,array $horse,?array $choice,?array $offer):bool
{
    if(!$choice)return false;
    if($choice['choice']==='keep')return trim($horse['name'])!=='' && hash_equals($choice['record_hash'],migrationHorseNameHash($horse));
    if($choice['choice']!=='logbook' || !$offer || (int)$choice['logbook_type_id']!==(int)$offer['id'] || (int)$choice['logbook_year']!==(int)$offer['valid_year'])return false;
    if(!hash_equals($choice['record_hash'],migrationHorseHash($horse)) || migrationHorseErrors($horse,migrationHorseQualifications($pdo)))return false;
    return migrationHasLogbook($pdo,(int)$horse['id'],(int)$offer['valid_year']) || number_format((float)$choice['logbook_price'],2,'.','')===number_format((float)$offer['cost'],2,'.','');
}

function migrationHorseChoicesValid(PDO $pdo,int $uid,array $state,bool $lock=false):bool
{
    $horses=migrationHorses($pdo,$uid,$lock);
    if($state['horses_status']!==($horses?'reviewed':'none'))return false;
    $choices=migrationHorseChoices($pdo,$uid,$lock);$offer=migrationLogbookOffer($pdo,$lock);
    foreach($horses as $horse)if(!migrationHorseChoiceValid($pdo,$horse,$choices[(int)$horse['id']]??null,$offer))return false;
    return true;
}

/** Database baskets are authoritative. Never adopt another signed-in account's basket. */
function migrationReadBasket(PDO $pdo,int $uid):array
{
    $s=$pdo->prepare('SELECT basket_json,user_id FROM baskets WHERE session_id=? FOR UPDATE');$s->execute([session_id()]);$row=$s->fetch();
    if($row && !empty($row['user_id']) && (int)$row['user_id']!==$uid)throw new RuntimeException('This basket belongs to another account. Sign out and reopen your invitation in a separate browser.');
    $items=$row?json_decode((string)$row['basket_json'],true):[];
    if(!is_array($items))throw new RuntimeException('Your basket needs review before adding a logbook.');
    foreach($items as $item)if(!is_array($item))throw new RuntimeException('Your basket contains an invalid item and needs review.');
    return $items;
}

function migrationWriteBasket(PDO $pdo,int $uid,array $items,array $settings,bool $refreshClock=true):?int
{
    if(!$pdo->inTransaction() || session_id()==='')throw new RuntimeException('A signed-in basket transaction is required.');
    if(!$items){$pdo->prepare('DELETE FROM baskets WHERE session_id=?')->execute([session_id()]);return null;}
    $s=$pdo->prepare('INSERT INTO baskets (session_id,user_id,basket_json,last_added_at,updated_at) VALUES (?,?,?,FROM_UNIXTIME(?),NOW()) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),basket_json=VALUES(basket_json),last_added_at=IF(?=1,VALUES(last_added_at),last_added_at),updated_at=NOW()');
    $s->execute([session_id(),$uid,json_encode(array_values($items),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),purchasingBasketClock($settings),(int)$refreshClock]);
    $s=$pdo->prepare('SELECT UNIX_TIMESTAMP(last_added_at) FROM baskets WHERE session_id=?');$s->execute([session_id()]);$value=$s->fetchColumn();return $value===false||$value===null?null:(int)$value;
}

/** Remove only this horse's unpaid logbook lines; preserve bookings, memberships and paid history. */
function migrationRemoveHorseBasketLines(PDO $pdo,int $uid,int $hid,array $items):array
{
    $keep=static fn($item)=>!(($item['booking_type']??'')==='horse_logbook' && (int)($item['horse_id']??0)===$hid);
    $s=$pdo->prepare('SELECT session_id,basket_json FROM baskets WHERE user_id=? AND session_id<>? FOR UPDATE');$s->execute([$uid,session_id()]);
    foreach($s as $row){
        $old=json_decode((string)$row['basket_json'],true);if(!is_array($old))throw new RuntimeException('An existing basket needs review.');
        foreach($old as $item)if(!is_array($item))throw new RuntimeException('An existing basket contains an invalid item.');
        $new=array_values(array_filter($old,$keep));
        if($new!==$old){
            if($new)$pdo->prepare('UPDATE baskets SET basket_json=?,updated_at=NOW() WHERE session_id=? AND user_id=?')->execute([json_encode($new,JSON_THROW_ON_ERROR),$row['session_id'],$uid]);
            else $pdo->prepare('DELETE FROM baskets WHERE session_id=? AND user_id=?')->execute([$row['session_id'],$uid]);
        }
    }
    return array_values(array_filter($items,$keep));
}

function migrationLogbookBasketItem(array $horse,array $choice,array $offer):array
{
    return ['id'=>$choice['basket_item_id'],'booking_type'=>'horse_logbook','logbook_type_id'=>(int)$offer['id'],'logbook_year'=>(int)$offer['valid_year'],'horse_id'=>(int)$horse['id'],'horse_name'=>$horse['name'],'membership_name'=>$offer['name'],'event_title'=>'Horse logbook: '.$horse['name'],'class_label'=>'Horse logbook '.$offer['valid_year'],'price'=>$offer['cost'],'migration_user_id'=>(int)$horse['owner_user_id']];
}

/** A single decision saves horse, basket, note and evidence atomically, then returns to the same list. */
function migrationChooseHorse(PDO $pdo,int $uid,int $hid,string $choice,array $data):array
{
    migrationAssertOwnSession($pdo,$uid);
    if(!in_array($choice,['logbook','keep','archive'],true))throw new RuntimeException('Choose a valid horse option.');
    $pdo->beginTransaction();
    try{
        $state=migrationState($pdo,$uid,true);migrationLockOwner($pdo,$uid);
        if(!migrationPending($state) || $state['current_step']!=='horses')throw new RuntimeException('Return to the Horses stage before making this choice.');
        $s=$pdo->prepare('SELECT * FROM horses WHERE id=? AND owner_user_id=? AND id<>1 AND is_archived=0 FOR UPDATE');$s->execute([$hid,$uid]);$horse=$s->fetch();
        if(!$horse)throw new RuntimeException('This horse is not available to manage in your account.');
        $settings=migrationCommerceSettings($pdo,true);$offer=$choice==='logbook'?migrationLogbookOffer($pdo,true,$settings):null;
        $errors=[];$name=isset($data['name'])&&is_string($data['name'])?trim($data['name']):trim((string)$horse['name']);
        if($choice==='keep' && ($name==='' || mb_strlen($name)>255))$errors['name']='Confirm the horse name (up to 255 characters).';
        if($choice==='logbook'){
            foreach(migrationHorseErrors($data,migrationHorseQualifications($pdo)) as $field=>$message)$errors['horses['.$hid.']['.$field.']']=$message;
            if(purchasingBlocked($settings,$_SESSION['user']??null))throw new RuntimeException(purchasingMessage($settings));
            if(!$offer)throw new RuntimeException('No logbook is currently available. You can keep the horse without purchasing for now.');
            if(!hash_equals(migrationLogbookQuote($offer),(string)($data['logbook_quote']??'')))throw new RuntimeException('The logbook year or price changed. Review this page before adding it.');
        }
        if($choice==='archive' && empty($data['archive_confirmed']))$errors['archive_confirmed']='Confirm that you want to archive this horse.';
        if($errors){$pdo->rollBack();return $errors;}
        $items=migrationReadBasket($pdo,$uid);$items=migrationRemoveHorseBasketLines($pdo,$uid,$hid,$items);
        if($choice==='logbook'){
            $values=array_map(static fn($field)=>trim((string)$data[$field]),array_keys(migrationHorseFields()));
            $pdo->prepare('UPDATE horses SET name=?,owner_name=?,breed=?,colour=?,sex=?,height_cm=?,qualification_id=?,year_of_birth=?,passport_number=? WHERE id=? AND owner_user_id=?')->execute([...$values,$hid,$uid]);
        }elseif($choice==='keep'){
            $pdo->prepare('UPDATE horses SET name=? WHERE id=? AND owner_user_id=?')->execute([$name,$hid,$uid]);
        }else{
            $s=$pdo->prepare('SELECT email FROM users WHERE id=?');$s->execute([$uid]);$email=(string)$s->fetchColumn();
            appendRecordNote($pdo,'horse',$hid,$email,'migration_horse_archived','Account holder confirmed removal during onboarding. Horse archived; existing history retained and unpaid logbook basket items removed.',$uid,bin2hex(random_bytes(16)),['horse_name'=>$horse['name'],'confirmation'=>'Remove this horse from the system (archive)','previous_choice'=>migrationHorseChoices($pdo,$uid)[$hid]['choice']??null]);
            $pdo->prepare('UPDATE horses SET is_archived=1 WHERE id=? AND owner_user_id=?')->execute([$hid,$uid]);
        }
        $s=$pdo->prepare('SELECT * FROM horses WHERE id=?');$s->execute([$hid]);$horse=$s->fetch();
        $pdo->prepare('DELETE FROM migration_horse_reviews WHERE user_id=? AND horse_id=?')->execute([$uid,$hid]);
        if($choice==='logbook')$pdo->prepare('INSERT INTO migration_horse_reviews (user_id,horse_id,confirmed_at,record_hash) VALUES (?,?,NOW(),?)')->execute([$uid,$hid,migrationHorseHash($horse)]);
        $id='mig-log-'.bin2hex(random_bytes(12));
        $record=['choice'=>$choice,'basket_item_id'=>$id];
        if($choice==='logbook' && !migrationHasLogbook($pdo,$hid,(int)$offer['valid_year'],true))$items[]=migrationLogbookBasketItem($horse,$record,$offer);
        $s=$pdo->prepare('INSERT INTO migration_horse_choices (user_id,horse_id,choice,confirmed_at,record_hash,logbook_type_id,logbook_year,logbook_price,basket_item_id) VALUES (?,?,?,NOW(),?,?,?,?,?) ON DUPLICATE KEY UPDATE choice=VALUES(choice),confirmed_at=VALUES(confirmed_at),record_hash=VALUES(record_hash),logbook_type_id=VALUES(logbook_type_id),logbook_year=VALUES(logbook_year),logbook_price=VALUES(logbook_price),basket_item_id=VALUES(basket_item_id)');
        $s->execute([$uid,$hid,$choice,$choice==='keep'?migrationHorseNameHash($horse):migrationHorseHash($horse),$offer?(int)$offer['id']:null,$offer?(int)$offer['valid_year']:null,$offer?$offer['cost']:null,$choice==='logbook'?$id:null]);
        $basketClock=migrationWriteBasket($pdo,$uid,$items,$settings,$choice==='logbook');
        $pdo->prepare("UPDATE migration_enrolments SET horses_status='pending',shares_status='deferred' WHERE user_id=?")->execute([$uid]);
        $pdo->commit();$_SESSION['basket']=$items;$_SESSION['basket_last_added']=$basketClock;return [];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Rename independently of the logbook decision, retaining valid decision evidence. */
function migrationRenameHorse(PDO $pdo,int $uid,int $hid,array $data):array
{
    migrationAssertOwnSession($pdo,$uid);
    $pdo->beginTransaction();
    try{
        $state=migrationState($pdo,$uid,true);migrationLockOwner($pdo,$uid);
        if(!migrationPending($state) || $state['current_step']!=='horses')throw new RuntimeException('Return to the Horses stage before changing a name.');
        $s=$pdo->prepare('SELECT * FROM horses WHERE id=? AND owner_user_id=? AND id<>1 AND is_archived=0 FOR UPDATE');$s->execute([$hid,$uid]);$horse=$s->fetch();
        if(!$horse)throw new RuntimeException('This horse is not available to manage in your account.');
        $name=is_string($data['name']??null)?trim($data['name']):'';
        if($name==='' || mb_strlen($name)>255){$pdo->rollBack();return ['name'=>'Enter the horse name (up to 255 characters).'];}
        $choices=migrationHorseChoices($pdo,$uid,true);$choice=$choices[$hid]??null;
        $valid=migrationHorseChoiceValid($pdo,$horse,$choice,migrationLogbookOffer($pdo,true));
        $pdo->prepare('UPDATE horses SET name=? WHERE id=? AND owner_user_id=?')->execute([$name,$hid,$uid]);$horse['name']=$name;
        if($valid){
            $hash=$choice['choice']==='keep'?migrationHorseNameHash($horse):migrationHorseHash($horse);
            $pdo->prepare('UPDATE migration_horse_choices SET record_hash=? WHERE user_id=? AND horse_id=?')->execute([$hash,$uid,$hid]);
            if($choice['choice']==='logbook')$pdo->prepare('UPDATE migration_horse_reviews SET record_hash=? WHERE user_id=? AND horse_id=?')->execute([$hash,$uid,$hid]);
        }
        $s=$pdo->prepare('SELECT session_id,basket_json FROM baskets WHERE user_id=? FOR UPDATE');$s->execute([$uid]);$currentItems=null;
        foreach($s->fetchAll() as $basket){
            $items=json_decode((string)$basket['basket_json'],true);
            if(!is_array($items))throw new RuntimeException('Your basket needs review before changing the horse name.');
            $changed=false;
            foreach($items as &$item)if(($item['booking_type']??'')==='horse_logbook' && (int)($item['horse_id']??0)===$hid){$item['horse_name']=$name;$changed=true;}
            unset($item);
            if($changed)$pdo->prepare('UPDATE baskets SET basket_json=?,updated_at=NOW() WHERE session_id=? AND user_id=?')->execute([json_encode($items,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$basket['session_id'],$uid]);
            if($basket['session_id']===session_id())$currentItems=$items;
        }
        $pdo->commit();if($currentItems!==null)$_SESSION['basket']=$currentItems;return [];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Rebuild saved unpaid onboarding selections after logout/expiry, only while onboarding is unfinished. */
function migrationSyncLogbookBasket(PDO $pdo,int $uid):void
{
    migrationAssertOwnSession($pdo,$uid);$pdo->beginTransaction();
    try{
        $state=migrationState($pdo,$uid,true);
        if(!migrationPending($state)){$pdo->commit();return;}
        migrationLockOwner($pdo,$uid);$settings=migrationCommerceSettings($pdo,true);$offer=migrationLogbookOffer($pdo,true,$settings);$choices=migrationHorseChoices($pdo,$uid,true);$items=migrationReadBasket($pdo,$uid);$before=$items;
        $s=$pdo->prepare('SELECT * FROM horses WHERE owner_user_id=? AND id<>1 FOR UPDATE');$s->execute([$uid]);$horses=[];foreach($s as $horse)$horses[(int)$horse['id']]=$horse;
        foreach($choices as $hid=>$choice){
            if($choice['choice']!=='logbook')continue;
            $horse=$horses[$hid]??null;
            $items=migrationRemoveHorseBasketLines($pdo,$uid,$hid,$items);
            if($horse && !$horse['is_archived'] && migrationHorseChoiceValid($pdo,$horse,$choice,$offer) && !migrationHasLogbook($pdo,$hid,(int)$choice['logbook_year'],true))$items[]=migrationLogbookBasketItem($horse,$choice,$offer);
        }
        if($items!==$before)$basketClock=migrationWriteBasket($pdo,$uid,$items,$settings);
        $pdo->commit();$_SESSION['basket']=$items;if($items!==$before)$_SESSION['basket_last_added']=$basketClock;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Published, purchasable memberships only; no fallback products or schema changes. */
function migrationMembershipOffers(PDO $pdo):array
{
    return array_values(array_filter($pdo->query("SELECT * FROM membership_types WHERE status='published' ORDER BY display_order,name,id")->fetchAll(),static fn($row)=>!membershipIsAdminAllocated($row)));
}

function migrationMembershipQuote(array $offer,int $year):string
{
    return hash('sha256',json_encode([(int)$offer['id'],$year,$offer['name'],number_format((float)$offer['cost'],2,'.','')],JSON_THROW_ON_ERROR));
}

/** Called inside the Person save transaction, so details and basket succeed together. */
function migrationAddMembership(PDO $pdo,int $uid,array $person,array $details,array $data,?array &$basket,?int &$clock):array
{
    $type=(int)($data['membership_type_id']??0);
    $s=$pdo->prepare("SELECT * FROM membership_types WHERE id=? AND status='published' FOR UPDATE");$s->execute([$type]);$offer=$s->fetch();
    if(!$offer || membershipIsAdminAllocated($offer))return ['membership_type_id'=>'Choose an available membership type.'];
    $settings=migrationCommerceSettings($pdo,true);$year=membership_purchase_year($settings);
    if(!hash_equals(migrationMembershipQuote($offer,$year),(string)($data['membership_quotes'][$type]??'')))return ['membership_type_id'=>'The membership year or price changed. Review the selection and add it again.'];
    if(purchasingBlocked($settings,$_SESSION['user']??null))throw new RuntimeException(purchasingMessage($settings));
    $mid=(int)$person['id'];
    $s=$pdo->prepare('SELECT id FROM membership_purchases WHERE member_id=? AND membership_year=? LIMIT 1 FOR UPDATE');$s->execute([$mid,$year]);
    if($s->fetchColumn())return ['membership_type_id'=>'You already have a membership for '.$year.'. Choose Next to continue.'];
    $items=migrationReadBasket($pdo,$uid);
    // One unpaid membership per person/year, including earlier sessions. Repeated Add changes the selection.
    $keep=static fn($item)=>!(($item['booking_type']??'')==='membership' && (int)($item['member_id']??0)===$mid && (int)($item['membership_year']??0)===$year);
    $s=$pdo->prepare('SELECT session_id,basket_json FROM baskets WHERE user_id=? AND session_id<>? FOR UPDATE');$s->execute([$uid,session_id()]);
    foreach($s->fetchAll() as $row){
        $old=json_decode($row['basket_json'],true,512,JSON_THROW_ON_ERROR);
        if(!is_array($old))throw new RuntimeException('An existing basket needs review.');
        foreach($old as $item)if(!is_array($item))throw new RuntimeException('An existing basket needs review.');
        $new=array_values(array_filter($old,$keep));
        if($new!==$old)$pdo->prepare('UPDATE baskets SET basket_json=?,updated_at=NOW() WHERE session_id=? AND user_id=?')->execute([json_encode($new,JSON_THROW_ON_ERROR),$row['session_id'],$uid]);
    }
    $items=array_values(array_filter($items,$keep));$name=trim($details['first_name'].' '.$details['last_name']);
    $items[]=['id'=>'mig-mem-'.bin2hex(random_bytes(12)),'booking_type'=>'membership','membership_type_id'=>(int)$offer['id'],'membership_year'=>$year,'member_id'=>$mid,'member_number'=>$person['member_number']??null,'member_name'=>$name,'membership_name'=>$offer['name'],'event_title'=>'Membership: '.$offer['name'],'class_label'=>'Membership: '.$offer['name'],'rider_name'=>$name,'horse_name'=>'','contact_email'=>$_SESSION['user']['email']??'','contact_phone'=>$details['phone']??'','price'=>$offer['cost'],'migration_user_id'=>$uid];
    $clock=migrationWriteBasket($pdo,$uid,$items,$settings);$basket=$items;return [];
}

/** Resume unpaid onboarding memberships from a previous signed-in session. */
function migrationRestoreMembershipBasket(PDO $pdo,int $uid):void
{
    if(!migrationPending(migrationState($pdo,$uid)))return;
    migrationAssertOwnSession($pdo,$uid);
    $pdo->beginTransaction();
    try{
        $state=migrationState($pdo,$uid,true);migrationLockOwner($pdo,$uid);
        if(!migrationPending($state)){$pdo->rollBack();return;}
        $items=migrationReadBasket($pdo,$uid);$changed=false;
        $s=$pdo->prepare('SELECT session_id,basket_json FROM baskets WHERE user_id=? AND session_id<>? FOR UPDATE');$s->execute([$uid,session_id()]);
        foreach($s->fetchAll() as $row){
            $old=json_decode($row['basket_json'],true,512,JSON_THROW_ON_ERROR);if(!is_array($old))throw new RuntimeException('An existing basket needs review.');$remaining=[];
            foreach($old as $item){
                if(!is_array($item))throw new RuntimeException('An existing basket needs review.');
                if(($item['booking_type']??'')==='membership' && (int)($item['migration_user_id']??0)===$uid && (int)($item['member_id']??0)===(int)$state['person_id']){
                    $duplicate=false;foreach($items as $current)if(($current['booking_type']??'')==='membership' && (int)($current['member_id']??0)===(int)$item['member_id'] && (int)($current['membership_year']??0)===(int)$item['membership_year'])$duplicate=true;
                    if(!$duplicate)$items[]=$item;$changed=true;
                }else $remaining[]=$item;
            }
            if($remaining!==$old)$pdo->prepare('UPDATE baskets SET basket_json=?,updated_at=NOW() WHERE session_id=? AND user_id=?')->execute([json_encode($remaining,JSON_THROW_ON_ERROR),$row['session_id'],$uid]);
        }
        $clock=$changed?migrationWriteBasket($pdo,$uid,$items,migrationCommerceSettings($pdo)):null;
        $pdo->commit();if($changed){$_SESSION['basket']=$items;$_SESSION['basket_last_added']=$clock;}
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

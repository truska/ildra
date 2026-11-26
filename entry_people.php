<?php
declare(strict_types=1);

class EntryPersonEmailException extends RuntimeException {}

/** Email-first rider reuse. No ownership transfers or contact-detail disclosure. */
function entryPersonErrors(array $data): array
{
    $errors=[];
    foreach (['first_name'=>'First name','last_name'=>'Surname','email'=>'Email address','phone'=>'Phone number','address'=>'Address','postcode'=>'Postcode','junior_or_senior'=>'Junior or Senior','emergency_contact_name'=>'Emergency contact name','emergency_contact_phone'=>'Emergency contact phone'] as $field=>$label) {
        if (trim((string)($data[$field]??''))==='') $errors[$field]=$label.' is required.';
    }
    foreach (['first_name'=>120,'last_name'=>120,'email'=>255,'phone'=>60,'address'=>4000,'postcode'=>40,'emergency_contact_name'=>255,'emergency_contact_phone'=>60] as $field=>$length) {
        if (mb_strlen((string)($data[$field]??''))>$length) $errors[$field]='Please use no more than '.$length.' characters.';
    }
    if (!filter_var(trim((string)($data['email']??'')),FILTER_VALIDATE_EMAIL)) $errors['email']='Enter a valid email address.';
    if (!in_array($data['junior_or_senior']??'',['Junior','Senior'],true)) $errors['junior_or_senior']='Select Junior or Senior.';
    $dob=trim((string)($data['dob']??''));
    if ($dob!=='' && ($error=rider_birth_date_error($dob))) $errors['dob']=$error;
    if (($data['junior_or_senior']??'')==='Junior' && $dob==='') $errors['dob']='Date of birth is required for juniors.';
    return $errors;
}

function entryResolvePerson(PDO $pdo, int $uid, string $email, ?array $details=null): array
{
    $email=strtolower(trim($email));
    if ($uid<=0) throw new RuntimeException('Sign in before adding a rider.');
    if (strlen($email)>255 || !filter_var($email,FILTER_VALIDATE_EMAIL)) return ['status'=>'error','errors'=>['email'=>'Enter a valid email address.']];
    // All schema preparation precedes the transaction; savePersonForUser performs DDL.
    ensureMembershipTables($pdo);ensureShareTables($pdo);
    $database=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $lock='entry-person:'.substr(hash('sha256',$database.'|'.$email),0,50);
    $s=$pdo->prepare('SELECT GET_LOCK(?,5)');$s->execute([$lock]);
    if ((int)$s->fetchColumn()!==1) throw new RuntimeException('This rider is being updated. Please try again.');
    try {
        $pdo->beginTransaction();
        $s=$pdo->prepare('SELECT id FROM users WHERE id=? AND is_archived=0 FOR UPDATE');$s->execute([$uid]);
        if (!$s->fetchColumn()) throw new RuntimeException('Sign in to an active account before adding a rider.');
        $s=$pdo->prepare('SELECT id,first_name,last_name,is_archived FROM users WHERE LOWER(TRIM(email))=? FOR UPDATE');$s->execute([$email]);$users=$s->fetchAll();
        $s=$pdo->prepare('SELECT p.id,p.owner_user_id,p.is_archived,u.is_archived AS owner_archived,u.id AS owner_exists FROM people p LEFT JOIN users u ON u.id=p.owner_user_id WHERE LOWER(TRIM(p.email))=? FOR UPDATE');$s->execute([$email]);$people=$s->fetchAll();
        if (count($people)>1 || count($users)>1) throw new EntryPersonEmailException('More than one record uses this email address. Please contact an administrator so the correct rider can be identified.');
        if (($users && !empty($users[0]['is_archived'])) || ($people && (!empty($people[0]['is_archived']) || !empty($people[0]['owner_archived']) || empty($people[0]['owner_exists'])))) throw new EntryPersonEmailException('This email belongs to an archived or unavailable record. Please contact an administrator.');
        if ($people) {
            $personId=(int)$people[0]['id'];$owner=(int)$people[0]['owner_user_id'];
        } elseif ($users) {
            // A login without a Person gets one canonical rider owned by that login.
            $owner=(int)$users[0]['id'];
            if (trim((string)$users[0]['first_name'])==='' || trim((string)$users[0]['last_name'])==='') throw new EntryPersonEmailException('This account needs its rider details completed by an administrator.');
            // Imported/account-holder riders can exist with an empty or changed Person email.
            $s=$pdo->prepare('SELECT id FROM people WHERE owner_user_id=? AND is_archived=0 AND LOWER(TRIM(first_name))=? AND LOWER(TRIM(last_name))=? FOR UPDATE');
            $s->execute([$owner,strtolower(trim($users[0]['first_name'])),strtolower(trim($users[0]['last_name']))]);$canonical=$s->fetchAll(PDO::FETCH_COLUMN);
            if (count($canonical)>1) throw new EntryPersonEmailException('This account has multiple matching riders. Please contact an administrator.');
            if ($canonical) $personId=(int)$canonical[0];
            else {
                $s=$pdo->prepare('INSERT INTO people(owner_user_id,first_name,last_name,email,is_archived) VALUES (?,?,?,?,0)');
                $s->execute([$owner,trim($users[0]['first_name']),trim($users[0]['last_name']),$email]);$personId=(int)$pdo->lastInsertId();
            }
        } elseif ($details===null) {
            $pdo->commit();return ['status'=>'details','email'=>$email];
        } else {
            $details=normalise_postcode_fields($details);
            $details['email']=$email;$errors=entryPersonErrors($details);
            if ($errors) { $pdo->rollBack();return ['status'=>'error','errors'=>$errors]; }
            $owner=$uid;$dob=trim((string)($details['dob']??''));$classification=$details['junior_or_senior'];
            if ($dob!=='' && (new DateTimeImmutable($dob))->diff(new DateTimeImmutable(date('Y').'-01-01'))->y<18) $classification='Junior';
            $s=$pdo->prepare('INSERT INTO people(owner_user_id,first_name,last_name,email,phone,address,postcode,junior_or_senior,dob,emergency_contact_name,emergency_contact_phone,general_email_opt_in,ride_notice_opt_in,renewal_reminder_opt_in,is_archived) VALUES (?,?,?,?,?,?,?,?,?,?,?,0,0,0,0)');
            $s->execute([$uid,trim($details['first_name']),trim($details['last_name']),$email,trim($details['phone']),trim($details['address']),trim($details['postcode']),$classification,$dob?:null,trim($details['emergency_contact_name']),trim($details['emergency_contact_phone'])]);$personId=(int)$pdo->lastInsertId();
        }
        if ($owner!==$uid) {
            $s=$pdo->prepare('SELECT id,status,revoked_at FROM user_person_links WHERE user_id=? AND person_id=? FOR UPDATE');$s->execute([$uid,$personId]);$link=$s->fetch();
            if ($link && (($link['status']??'')!=='approved' || !empty($link['revoked_at']))) throw new EntryPersonEmailException('Access to this rider was previously removed. Please contact the managing account or an administrator.');
            if (!$link) $pdo->prepare("INSERT INTO user_person_links(user_id,person_id,permission,status) VALUES (?,?,'select_only','approved')")->execute([$uid,$personId]);
        }
        $pdo->commit();
        return ['status'=>'ready','person_id'=>$personId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally {
        $s=$pdo->prepare('SELECT RELEASE_LOCK(?)');$s->execute([$lock]);
    }
}

/** Export missing-field flags only; linked riders' private contact values stay masked. */
function entryPeopleWithContactGaps(PDO $pdo,array $people): array
{
    if (!$people) return [];
    $ids=array_map(static fn($p)=>(int)$p['id'],$people);
    $in=implode(',',array_fill(0,count($ids),'?'));
    $stmt=$pdo->prepare("SELECT id,TRIM(COALESCE(phone,''))='' AS phone,
        TRIM(COALESCE(emergency_contact_name,''))='' AS emergency_contact_name,
        TRIM(COALESCE(emergency_contact_phone,''))='' AS emergency_contact_phone
        FROM people WHERE id IN ($in)");$stmt->execute($ids);$gaps=[];
    foreach ($stmt as $row) $gaps[(int)$row['id']]=[
        'phone'=>(bool)$row['phone'],'emergency_contact_name'=>(bool)$row['emergency_contact_name'],
        'emergency_contact_phone'=>(bool)$row['emergency_contact_phone'],
    ];
    foreach ($people as &$person) $person['contact_missing']=$gaps[(int)$person['id']]??[];
    unset($person);return $people;
}

/** Consent fills gaps on the canonical record; existing values are never overwritten. */
function entryUpdateRiderContact(PDO $pdo,int $userId,int $personId,array $details,bool $consent): bool
{
    if (!$consent || $userId<=0 || $personId<=0) return false;
    ensureShareTables($pdo);
    $values=[];
    foreach (['phone'=>60,'emergency_contact_name'=>255,'emergency_contact_phone'=>60] as $field=>$limit) {
        $value=trim((string)($details[$field]??''));
        if (mb_strlen($value)>$limit) throw new RuntimeException('Rider contact details are too long.');
        $values[]=$value;
    }
    $stmt=$pdo->prepare("UPDATE people p SET
        p.phone=CASE WHEN TRIM(COALESCE(p.phone,''))='' THEN COALESCE(NULLIF(?,''),p.phone) ELSE p.phone END,
        p.emergency_contact_name=CASE WHEN TRIM(COALESCE(p.emergency_contact_name,''))='' THEN COALESCE(NULLIF(?,''),p.emergency_contact_name) ELSE p.emergency_contact_name END,
        p.emergency_contact_phone=CASE WHEN TRIM(COALESCE(p.emergency_contact_phone,''))='' THEN COALESCE(NULLIF(?,''),p.emergency_contact_phone) ELSE p.emergency_contact_phone END,
        p.updated_at=NOW()
        WHERE p.id=? AND p.is_archived=0 AND (p.owner_user_id=? OR EXISTS (
            SELECT 1 FROM user_person_links l WHERE l.person_id=p.id AND l.user_id=?
            AND l.status='approved' AND l.revoked_at IS NULL
        )) AND ((TRIM(COALESCE(p.phone,''))='' AND ?<>'') OR
            (TRIM(COALESCE(p.emergency_contact_name,''))='' AND ?<>'') OR
            (TRIM(COALESCE(p.emergency_contact_phone,''))='' AND ?<>''))");
    $stmt->execute([...$values,$personId,$userId,$userId,...$values]);
    return $stmt->rowCount()>0;
}

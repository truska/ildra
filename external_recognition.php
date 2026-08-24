<?php
declare(strict_types=1);

function ensureExternalRecognitionTables(?PDO $pdo): void
{
    if (!$pdo) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS recognised_organisations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(20) NOT NULL,
        name VARCHAR(190) NOT NULL,
        country_code CHAR(2) NOT NULL,
        verification_email VARCHAR(190) NULL,
        is_approved TINYINT(1) NOT NULL DEFAULT 1,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_recognised_org_code (code)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS external_recognition_applications (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        subject_type ENUM('rider','horse') NOT NULL,
        person_id INT UNSIGNED NULL,
        horse_id INT UNSIGNED NULL,
        organisation_id INT UNSIGNED NULL,
        other_organisation_name VARCHAR(190) NULL,
        organisation_country_code CHAR(2) NOT NULL,
        credential_number VARCHAR(100) NOT NULL,
        valid_until DATE NOT NULL,
        status ENUM('pending','awaiting_verification','verified','rejected','expired','withdrawn') NOT NULL DEFAULT 'pending',
        applicant_notes TEXT NULL,
        admin_notes TEXT NULL,
        verification_token_hash CHAR(64) NULL,
        verification_requested_at DATETIME NULL,
        verified_at DATETIME NULL,
        reviewed_by_user_id INT UNSIGNED NULL,
        reviewed_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_recognition_user (user_id),
        INDEX idx_recognition_person (person_id,status,valid_until),
        INDEX idx_recognition_horse (horse_id,status,valid_until),
        INDEX idx_recognition_status (status),
        UNIQUE KEY uniq_recognition_token (verification_token_hash)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

function fetchRecognisedOrganisations(?PDO $pdo, bool $activeOnly = true): array
{
    if (!$pdo) return [];
    ensureExternalRecognitionTables($pdo);
    $sql = 'SELECT * FROM recognised_organisations' . ($activeOnly ? ' WHERE is_active=1 AND is_approved=1' : '') . ' ORDER BY name,id';
    return $pdo->query($sql)->fetchAll() ?: [];
}

function fetchExternalRecognitionsForUser(?PDO $pdo, int $userId): array
{
    if (!$pdo || $userId <= 0) return [];
    ensureExternalRecognitionTables($pdo);
    $stmt = $pdo->prepare("SELECT a.*,o.code organisation_code,o.name organisation_name,
        TRIM(CONCAT(COALESCE(p.first_name,''),' ',COALESCE(p.last_name,''))) person_name,h.name horse_name
        FROM external_recognition_applications a
        LEFT JOIN recognised_organisations o ON o.id=a.organisation_id
        LEFT JOIN people p ON p.id=a.person_id
        LEFT JOIN horses h ON h.id=a.horse_id
        WHERE a.user_id=:uid ORDER BY a.created_at DESC,a.id DESC");
    $stmt->execute([':uid'=>$userId]);
    return $stmt->fetchAll() ?: [];
}

function saveExternalRecognitionApplication(PDO $pdo, int $userId, array $data, array &$alerts): bool
{
    ensureExternalRecognitionTables($pdo);
    $subjectType = ($data['subject_type'] ?? '') === 'horse' ? 'horse' : 'rider';
    $subjectId = (int)($data['subject_id'] ?? 0);
    $organisationId = (int)($data['organisation_id'] ?? 0);
    $otherName = trim((string)($data['other_organisation_name'] ?? ''));
    $country = strtoupper(trim((string)($data['organisation_country_code'] ?? '')));
    $credential = trim((string)($data['credential_number'] ?? ''));
    $validUntil = trim((string)($data['valid_until'] ?? ''));
    if ($subjectId <= 0 || $credential === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validUntil)) {
        $alerts[]=['type'=>'danger','message'=>'Select a rider or horse and enter the external number and expiry date.']; return false;
    }
    if ($organisationId <= 0 && ($otherName === '' || !preg_match('/^[A-Z]{2}$/', $country))) {
        $alerts[]=['type'=>'danger','message'=>'Select an organisation or provide its name and two-letter country code.']; return false;
    }
    if ($organisationId > 0) {
        $stmt=$pdo->prepare('SELECT country_code FROM recognised_organisations WHERE id=:id AND is_active=1');
        $stmt->execute([':id'=>$organisationId]); $country=(string)($stmt->fetchColumn() ?: '');
        if ($country === '') { $alerts[]=['type'=>'danger','message'=>'Select a valid organisation.']; return false; }
        $otherName = '';
    }
    $table = $subjectType === 'rider' ? 'people' : 'horses';
    $stmt=$pdo->prepare("SELECT id FROM $table WHERE id=:id AND owner_user_id=:uid AND is_archived=0");
    $stmt->execute([':id'=>$subjectId,':uid'=>$userId]);
    if (!$stmt->fetchColumn()) { $alerts[]=['type'=>'danger','message'=>'Select one of your saved '.($subjectType==='rider'?'people':'horses').'.']; return false; }
    $stmt=$pdo->prepare("INSERT INTO external_recognition_applications
        (user_id,subject_type,person_id,horse_id,organisation_id,other_organisation_name,organisation_country_code,credential_number,valid_until,status,applicant_notes)
        VALUES (:uid,:type,:person,:horse,:org,:other,:country,:number,:valid_until,'pending',:notes)");
    $stmt->execute([':uid'=>$userId,':type'=>$subjectType,':person'=>$subjectType==='rider'?$subjectId:null,
        ':horse'=>$subjectType==='horse'?$subjectId:null,':org'=>$organisationId?:null,':other'=>$otherName?:null,
        ':country'=>$country,':number'=>$credential,':valid_until'=>$validUntil,':notes'=>trim((string)($data['applicant_notes']??''))?:null]);
    return true;
}

function externalRecognitionIds(PDO $pdo, string $subjectType, array $ids, string $onDate): array
{
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn(int $id):bool=>$id>0)));
    if (!$ids) return [];
    ensureExternalRecognitionTables($pdo);
    $field=$subjectType==='horse'?'horse_id':'person_id';
    $in=implode(',',array_fill(0,count($ids),'?'));
    $stmt=$pdo->prepare("SELECT DISTINCT $field FROM external_recognition_applications
        WHERE subject_type=? AND $field IN ($in) AND status='verified' AND valid_until>=?");
    $stmt->execute(array_merge([$subjectType],$ids,[$onDate]));
    return array_fill_keys(array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN)?:[]),true);
}

function fetchExternalRecognitionApplications(?PDO $pdo): array
{
    if (!$pdo) return [];
    ensureExternalRecognitionTables($pdo);
    return $pdo->query("SELECT a.*,o.code organisation_code,o.name organisation_name,o.verification_email,
        TRIM(CONCAT(COALESCE(p.first_name,''),' ',COALESCE(p.last_name,''))) person_name,h.name horse_name,u.email applicant_email
        FROM external_recognition_applications a
        LEFT JOIN recognised_organisations o ON o.id=a.organisation_id
        LEFT JOIN people p ON p.id=a.person_id LEFT JOIN horses h ON h.id=a.horse_id LEFT JOIN users u ON u.id=a.user_id
        ORDER BY FIELD(a.status,'pending','awaiting_verification','verified','rejected','expired','withdrawn'),a.created_at DESC")->fetchAll() ?: [];
}

function saveRecognisedOrganisation(PDO $pdo, array $data, array &$alerts): bool
{
    ensureExternalRecognitionTables($pdo);
    $id=(int)($data['organisation_id']??0); $code=strtoupper(trim((string)($data['code']??'')));
    $name=trim((string)($data['name']??'')); $country=strtoupper(trim((string)($data['country_code']??'')));
    $email=trim((string)($data['verification_email']??''));
    if($code===''||$name===''||!preg_match('/^[A-Z]{2}$/',$country)||($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))){$alerts[]=['type'=>'danger','message'=>'Enter a code, name, two-letter country code and valid verification email.'];return false;}
    if($id>0){$stmt=$pdo->prepare('UPDATE recognised_organisations SET code=:code,name=:name,country_code=:country,verification_email=:email,is_approved=:approved,is_active=:active WHERE id=:id');}
    else{$stmt=$pdo->prepare('INSERT INTO recognised_organisations(code,name,country_code,verification_email,is_approved,is_active) VALUES(:code,:name,:country,:email,:approved,:active)');}
    $params=[':code'=>$code,':name'=>$name,':country'=>$country,':email'=>$email?:null,':approved'=>!empty($data['is_approved'])?1:0,':active'=>!empty($data['is_active'])?1:0];if($id>0)$params[':id']=$id;
    try{$stmt->execute($params);return true;}catch(PDOException $e){$alerts[]=['type'=>'danger','message'=>'The organisation could not be saved. Check that its code is unique.'];return false;}
}

function reviewExternalRecognition(PDO $pdo, int $applicationId, string $status, int $reviewerId, string $notes=''): bool
{
    if(!in_array($status,['verified','rejected','withdrawn'],true))return false;
    ensureExternalRecognitionTables($pdo);
    $stmt=$pdo->prepare("UPDATE external_recognition_applications SET status=:status,admin_notes=:notes,reviewed_by_user_id=:reviewer,reviewed_at=NOW(),verified_at=IF(:status2='verified',NOW(),verified_at),verification_token_hash=NULL WHERE id=:id");
    return $stmt->execute([':status'=>$status,':status2'=>$status,':notes'=>trim($notes)?:null,':reviewer'=>$reviewerId,':id'=>$applicationId]);
}

function requestExternalRecognitionVerification(PDO $pdo, int $applicationId, int $reviewerId, string $baseUrl, array &$alerts): bool
{
    ensureExternalRecognitionTables($pdo);
    $stmt=$pdo->prepare("SELECT a.*,o.name organisation_name,o.verification_email,
        TRIM(CONCAT(COALESCE(p.first_name,''),' ',COALESCE(p.last_name,''))) person_name,h.name horse_name
        FROM external_recognition_applications a JOIN recognised_organisations o ON o.id=a.organisation_id
        LEFT JOIN people p ON p.id=a.person_id LEFT JOIN horses h ON h.id=a.horse_id WHERE a.id=:id AND o.is_active=1 AND o.is_approved=1");
    $stmt->execute([':id'=>$applicationId]);$row=$stmt->fetch();
    $email=trim((string)($row['verification_email']??''));
    if(!$row||!filter_var($email,FILTER_VALIDATE_EMAIL)){$alerts[]=['type'=>'danger','message'=>'This application needs a listed organisation with a valid verification email.'];return false;}
    $token=bin2hex(random_bytes(24));$hash=hash('sha256',$token);$baseUrl=rtrim($baseUrl,'/');$url=$baseUrl.'/recognition_verify.php?t='.rawurlencode($token);
    $subjectName=trim((string)($row['person_name']?:$row['horse_name']));$subjectType=(string)$row['subject_type'];
    $subject='External '.$subjectType.' credential verification request';
    $html='<p>Please verify the following credential presented to the Irish Long Distance Riding Association.</p>'
        .'<p><strong>'.h(ucfirst($subjectType)).':</strong> '.h($subjectName).'<br><strong>Organisation:</strong> '.h((string)$row['organisation_name']).'<br><strong>Credential number:</strong> '.h((string)$row['credential_number']).'<br><strong>Valid until:</strong> '.h(format_display_date($row['valid_until'])).'</p>'
        .'<p><a href="'.h($url).'">Review this verification request</a></p><p>This link expires after 14 days.</p>';
    $text="Please verify this external $subjectType credential.\n\n$subjectType: $subjectName\nOrganisation: ".$row['organisation_name']."\nCredential: ".$row['credential_number']."\nValid until: ".$row['valid_until']."\n\nReview: $url\n";
    if(!send_logged_email($pdo,$email,$subject,$html,$text,['type'=>'external_recognition_verification','application_id'=>$applicationId])){$alerts[]=['type'=>'danger','message'=>'The verification email could not be sent. Check the email log.'];return false;}
    $upd=$pdo->prepare("UPDATE external_recognition_applications SET status='awaiting_verification',verification_token_hash=:hash,verification_requested_at=NOW(),reviewed_by_user_id=:reviewer,reviewed_at=NOW() WHERE id=:id");
    return $upd->execute([':hash'=>$hash,':reviewer'=>$reviewerId,':id'=>$applicationId]);
}

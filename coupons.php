<?php
declare(strict_types=1);

/** Coupon issuance, validation and redemption. All times are UK application time. */
function ensureCouponTables(?PDO $pdo): void
{
    if (!$pdo) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS coupons (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        coupon_type ENUM('manual','ride_helper','marketing') NOT NULL DEFAULT 'manual',
        code VARCHAR(32) NOT NULL UNIQUE,
        title VARCHAR(160) NOT NULL,
        message_html MEDIUMTEXT NULL,
        terms_html MEDIUMTEXT NULL,
        amount DECIMAL(12,2) NOT NULL,
        valid_from DATETIME NOT NULL,
        valid_until DATETIME NOT NULL,
        event_id INT UNSIGNED NULL,
        usage_limit INT UNSIGNED NOT NULL DEFAULT 1,
        use_count INT UNSIGNED NOT NULL DEFAULT 0,
        per_person_limit INT UNSIGNED NOT NULL DEFAULT 0,
        can_convert_to_credit TINYINT(1) NOT NULL DEFAULT 1,
        recipient_email VARCHAR(190) NULL,
        issued_to_member_id INT UNSIGNED NULL,
        issued_event_id INT UNSIGNED NULL,
        status ENUM('active','cancelled','expired') NOT NULL DEFAULT 'active',
        created_by_user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_coupons_active (status,valid_from,valid_until),
        INDEX idx_coupons_event (event_id),
        INDEX idx_coupons_type (coupon_type)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS coupon_redemptions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        coupon_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        member_id INT UNSIGNED NULL,
        booking_ref VARCHAR(80) NULL,
        applied_amount DECIMAL(12,2) NOT NULL,
        credit_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        status ENUM('reserved','redeemed','released') NOT NULL DEFAULT 'reserved',
        reserved_until DATETIME NULL,
        created_at DATETIME NOT NULL,
        redeemed_at DATETIME NULL,
        INDEX idx_coupon_redemptions_coupon (coupon_id,status),
        INDEX idx_coupon_redemptions_person (coupon_id,member_id,status),
        INDEX idx_coupon_redemptions_user (coupon_id,user_id,status)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS coupon_audit (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        coupon_id INT UNSIGNED NOT NULL,
        actor_user_id INT UNSIGNED NULL,
        action VARCHAR(80) NOT NULL,
        notes TEXT NULL,
        metadata MEDIUMTEXT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_coupon_audit_coupon (coupon_id,created_at)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

function coupon_code(): string
{
    return strtoupper(substr(bin2hex(random_bytes(6)), 0, 4) . '-' . substr(bin2hex(random_bytes(6)), 0, 4));
}

function coupon_log(?PDO $pdo, int $couponId, ?int $actorId, string $action, string $notes = '', array $metadata = []): void
{
    if (!$pdo || $couponId <= 0) return;
    ensureCouponTables($pdo);
    $stmt=$pdo->prepare('INSERT INTO coupon_audit (coupon_id,actor_user_id,action,notes,metadata,created_at) VALUES (:coupon,:actor,:action,:notes,:metadata,NOW())');
    $stmt->execute([':coupon'=>$couponId,':actor'=>$actorId?:null,':action'=>$action,':notes'=>$notes?:null,':metadata'=>$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE):null]);
}

function coupon_find(?PDO $pdo, string $code): ?array
{
    if (!$pdo) return null;
    ensureCouponTables($pdo);
    $stmt=$pdo->prepare('SELECT * FROM coupons WHERE code=:code LIMIT 1');
    $stmt->execute([':code'=>strtoupper(trim($code))]);
    return $stmt->fetch() ?: null;
}

function coupon_eligible_basket_total(array $basket, ?int $eventId = null): float
{
    $total=0.0;
    foreach($basket as $item) {
        if (in_array((string)($item['booking_type'] ?? ''), ['membership','horse_logbook'], true)) continue;
        if ($eventId !== null && (int)($item['event_id'] ?? 0) !== $eventId) continue;
        $total += max(0, price_to_number($item['price'] ?? 0));
    }
    return round($total,2);
}

function coupon_validate_for_basket(?PDO $pdo, string $code, array $basket, int $userId, ?int $memberId = null): array
{
    $coupon=coupon_find($pdo,$code);
    if(!$coupon) return ['ok'=>false,'message'=>'Coupon code was not found.'];
    $now=date('Y-m-d H:i:s');
    if(($coupon['status']??'')!=='active'||$now<(string)$coupon['valid_from']||$now>(string)$coupon['valid_until']) return ['ok'=>false,'message'=>'This coupon is not currently valid.'];
    if((int)$coupon['use_count'] >= (int)$coupon['usage_limit']) return ['ok'=>false,'message'=>'This coupon has already been used.'];
    $eligible=coupon_eligible_basket_total($basket, !empty($coupon['event_id'])?(int)$coupon['event_id']:null);
    if($eligible<=0) return ['ok'=>false,'message'=>'This coupon does not apply to an item in this basket.'];
    if((int)$coupon['per_person_limit']>0 && $pdo) {
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id=:coupon AND status IN ('reserved','redeemed') AND ".($memberId?'member_id=:person':'user_id=:user'));
        $stmt->execute($memberId?[':coupon'=>$coupon['id'],':person'=>$memberId]:[':coupon'=>$coupon['id'],':user'=>$userId]);
        if((int)$stmt->fetchColumn()>=(int)$coupon['per_person_limit']) return ['ok'=>false,'message'=>'This coupon has already been used by this person.'];
    }
    return ['ok'=>true,'coupon'=>$coupon,'eligible_total'=>$eligible,'discount'=>round(min((float)$coupon['amount'],$eligible),2)];
}

function coupon_create(?PDO $pdo, array $data, ?int $actorId, array &$alerts): int|false
{
    if(!$pdo){$alerts[]=['type'=>'danger','message'=>'Database unavailable.'];return false;} ensureCouponTables($pdo);
    $type=in_array($data['coupon_type']??'', ['manual','ride_helper','marketing'],true)?$data['coupon_type']:'manual';
    $amount=round(max(0,price_to_number($data['amount']??0)),2); $title=trim((string)($data['title']??''));
    $from=trim((string)($data['valid_from']??'')); $until=trim((string)($data['valid_until']??''));
    if($title===''||$amount<=0||$from===''||$until===''||$until<$from){$alerts[]=['type'=>'danger','message'=>'Title, positive value and a valid date range are required.'];return false;}
    $code=strtoupper(trim((string)($data['code']??''))) ?: coupon_code();
    try {$stmt=$pdo->prepare('INSERT INTO coupons (coupon_type,code,title,message_html,terms_html,amount,valid_from,valid_until,event_id,usage_limit,per_person_limit,can_convert_to_credit,recipient_email,issued_to_member_id,issued_event_id,status,created_by_user_id,created_at,updated_at) VALUES (:type,:code,:title,:message,:terms,:amount,:from,:until,:event,:limit,:person_limit,:convert,:recipient,:member,:issued_event,\'active\',:actor,NOW(),NOW())');
        $stmt->execute([':type'=>$type,':code'=>$code,':title'=>$title,':message'=>trim((string)($data['message_html']??''))?:null,':terms'=>trim((string)($data['terms_html']??''))?:null,':amount'=>$amount,':from'=>$from,':until'=>$until,':event'=>(int)($data['event_id']??0)?:null,':limit'=>$type==='marketing'?max(1,(int)($data['usage_limit']??1)):1,':person_limit'=>$type==='marketing'?max(0,(int)($data['per_person_limit']??1)):0,':convert'=>empty($data['event_id'])?1:0,':recipient'=>trim((string)($data['recipient_email']??''))?:null,':member'=>(int)($data['issued_to_member_id']??0)?:null,':issued_event'=>(int)($data['issued_event_id']??0)?:null,':actor'=>$actorId?:null]);
        $id=(int)$pdo->lastInsertId(); coupon_log($pdo,$id,$actorId,'issued','Coupon issued',['type'=>$type,'code'=>$code]); return $id;
    } catch(PDOException $e){$alerts[]=['type'=>'danger','message'=>'Could not create coupon. The code may already be in use.'];return false;}
}

function coupon_redeem(?PDO $pdo, string $code, array $basket, int $userId, string $bookingRef, array &$alerts): ?array
{
    if(!$pdo || $userId<=0) return null;
    try {
        ensureCouponTables($pdo); $pdo->beginTransaction();
        $stmt=$pdo->prepare('SELECT * FROM coupons WHERE code=:code FOR UPDATE'); $stmt->execute([':code'=>strtoupper(trim($code))]); $coupon=$stmt->fetch();
        $now=date('Y-m-d H:i:s');
        if(!$coupon||$coupon['status']!=='active'||$now<$coupon['valid_from']||$now>$coupon['valid_until']||(int)$coupon['use_count']>=(int)$coupon['usage_limit']) throw new RuntimeException('Coupon is no longer available.');
        $eligible=coupon_eligible_basket_total($basket,!empty($coupon['event_id'])?(int)$coupon['event_id']:null);
        if($eligible<=0) throw new RuntimeException('Coupon no longer applies to this basket.');
        $applied=round(min((float)$coupon['amount'],$eligible),2); $remaining=round(max(0,(float)$coupon['amount']-$applied),2);
        $pdo->prepare('UPDATE coupons SET use_count=use_count+1,updated_at=NOW() WHERE id=:id')->execute([':id'=>$coupon['id']]);
        $pdo->prepare("INSERT INTO coupon_redemptions (coupon_id,user_id,booking_ref,applied_amount,credit_amount,status,created_at,redeemed_at) VALUES (:coupon,:user,:ref,:applied,:credit,'redeemed',NOW(),NOW())")->execute([':coupon'=>$coupon['id'],':user'=>$userId,':ref'=>$bookingRef,':applied'=>$applied,':credit'=>$remaining]);
        $pdo->commit(); coupon_log($pdo,(int)$coupon['id'],$userId,'redeemed','Applied to '.$bookingRef,['applied'=>$applied,'credit'=>$remaining]);
        return ['coupon'=>$coupon,'applied'=>$applied,'credit'=>$remaining];
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); $alerts[]=['type'=>'danger','message'=>$e->getMessage()?:'Coupon could not be redeemed.']; return null; }
}

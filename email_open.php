<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$token=trim((string)($_GET['t']??''));
if($pdo&&preg_match('/^[a-f0-9]{40}$/',$token)){
    try{
        $pdo->beginTransaction();
        $stmt=$pdo->prepare('SELECT id,campaign_id,first_opened_at FROM email_campaign_recipients WHERE tracking_token=:token LIMIT 1 FOR UPDATE');
        $stmt->execute([':token'=>$token]);$row=$stmt->fetch();
        if($row){
            $first=empty($row['first_opened_at']);
            $pdo->prepare('UPDATE email_campaign_recipients SET first_opened_at=COALESCE(first_opened_at,NOW()),last_opened_at=NOW(),open_count=open_count+1 WHERE id=:id')->execute([':id'=>(int)$row['id']]);
            if($first)$pdo->prepare('UPDATE email_campaigns SET opened_count=opened_count+1 WHERE id=:id')->execute([':id'=>(int)$row['campaign_id']]);
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();}
}
header('Content-Type: image/gif');header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');

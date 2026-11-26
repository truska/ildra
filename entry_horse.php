<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';require_once __DIR__.'/entry_horses.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');
if (!$currentUser || !$pdo || ($_SERVER['REQUEST_METHOD']??'GET')!=='POST') { http_response_code($currentUser?405:401);echo json_encode(['ok'=>false,'message'=>'Sign in and use the horse selector.']);exit; }
try {
    $event=fetchEventById($pdo,(int)($_POST['event_id']??0));
    if (!$event || ($event['archive_state']??'active')!=='active' || (($event['status']??'')!=='published' && !roleCanAccessAdmin((string)$currentUser['role'])) || isThirdPartyEvent($event)) throw new RuntimeException('This event is unavailable.');
    $id=(int)($_POST['horse_id']??0);
    $created=entryRememberHorse($pdo,(int)$currentUser['id'],(int)($_POST['person_id']??0),$id,(string)$event['event_date'],getSiteSettings($pdo));
    if ($created) adminAuditLog($pdo,'entries.horse_linked',$currentUser,'horse',$id);
    echo json_encode(['ok'=>true,'horse_id'=>$id]);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'message'=>$e instanceof RuntimeException && !($e instanceof PDOException)?$e->getMessage():'The horse could not be linked. Please try again.']);
}

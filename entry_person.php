<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/entry_people.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex');
if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST' || !$currentUser || !$pdo) {
    http_response_code($currentUser?405:401);echo json_encode(['status'=>'error','message'=>'Sign in and use the rider form.']);exit;
}
$window=$_SESSION['entry_person_requests']??['start'=>time(),'count'=>0];
if (time()-(int)$window['start']>600) $window=['start'=>time(),'count'=>0];
$_SESSION['entry_person_requests']=['start'=>$window['start'],'count'=>(int)$window['count']+1];
if ((int)$window['count']>=50) { http_response_code(429);echo json_encode(['status'=>'error','message'=>'Please wait a few minutes before checking more riders.']);exit; }
try {
    if (!in_array($_POST['stage']??'email',['email','details'],true)) throw new RuntimeException('Reload the rider form and try again.');
    $result=entryResolvePerson($pdo,(int)$currentUser['id'],(string)($_POST['email']??''),($_POST['stage']??'')==='details'?$_POST:null);
} catch (Throwable $e) {
    $result=$e instanceof EntryPersonEmailException ? ['status'=>'error','errors'=>['email'=>$e->getMessage()]] : ['status'=>'error','message'=>$e instanceof RuntimeException && !($e instanceof PDOException)?$e->getMessage():'The rider could not be added. Please try again.'];
    if ($e instanceof PDOException) error_log('Entry rider lookup failed ('.$e->getCode().').');
}
if (($result['status']??'')==='ready') adminAuditLog($pdo,'entries.person_added',$currentUser,'person',(int)$result['person_id']);
echo json_encode($result,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);

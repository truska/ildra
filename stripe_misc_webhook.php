<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$payload=(string)file_get_contents('php://input');
$signature=(string)($_SERVER['HTTP_STRIPE_SIGNATURE']??'');
$stripe=stripe_config($config);
if(!stripe_verify_webhook_signature($stripe,$payload,$signature)){http_response_code(400);exit('Invalid signature');}
$event=json_decode($payload,true);
if(!is_array($event)){http_response_code(400);exit('Invalid payload');}
if(($event['type']??'')==='checkout.session.completed'){miscPaymentComplete($pdo,(array)($event['data']['object']??[]),$alerts);}
http_response_code(200); echo 'ok';

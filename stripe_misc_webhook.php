<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$payload=(string)file_get_contents('php://input');
$signature=(string)($_SERVER['HTTP_STRIPE_SIGNATURE']??'');
$stripe=stripe_config($config);
if(!stripe_verify_webhook_signature($stripe,$payload,$signature)){http_response_code(400);exit('Invalid signature');}
$event=json_decode($payload,true);
if(!is_array($event)){http_response_code(400);exit('Invalid payload');}
if(($event['type']??'')==='checkout.session.completed'){
    $session=(array)($event['data']['object']??[]);
    if(!empty($session['metadata']['misc_payment_request_id'])&&($session['payment_status']??'')==='paid'&&!miscPaymentComplete($pdo,$session,$alerts,$stripe)){http_response_code(500);exit('Payment could not be recorded');}
}
http_response_code(200); echo 'ok';

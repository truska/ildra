<?php
declare(strict_types=1);
// Deliberately independent of interactive login, CSRF and onboarding redirects.
require_once __DIR__.'/db.php';
require_once __DIR__.'/email.php';
require_once __DIR__.'/email_ses_feedback.php';
date_default_timezone_set('Europe/London');
header('Cache-Control: no-store');header('Content-Type: text/plain; charset=utf-8');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);header('Allow: POST');exit('POST required');}
$private=email_environment_config();$topics=$private['ses_feedback']['topic_arns']??[];
if(!is_array($topics)||!$topics){http_response_code(404);exit('Not configured');}
$body=file_get_contents('php://input',false,null,0,262145);
if($body===false || strlen($body)>262144){http_response_code(413);exit('Payload too large');}
try{
    $message=json_decode($body,true,32,JSON_THROW_ON_ERROR);
    if(!is_array($message))throw new InvalidArgumentException('Invalid payload.');
    emailSnsVerify($message,$topics);
    if($message['Type']==='SubscriptionConfirmation'){
        emailSnsFetch(emailSnsConfirmationUrl($message));echo 'Subscription confirmed';exit;
    }
    $event=json_decode($message['Message'],true,32,JSON_THROW_ON_ERROR);
    if(!is_array($event))throw new InvalidArgumentException('Invalid SES event.');
    $alerts=[];$pdo=createPdo(require __DIR__.'/config.php',$alerts);
    if(!$pdo)throw new RuntimeException('Database unavailable.');
    ensureEmailDeliveryTables($pdo);
    emailSesRecordEvent($pdo,$event,$message['TopicArn']);
    echo 'OK';
}catch(InvalidArgumentException|JsonException $e){http_response_code(400);echo 'Invalid notification';}
catch(Throwable $e){error_log('SES feedback processing failed: '.get_class($e));http_response_code(503);echo 'Please retry';}

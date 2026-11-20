<?php
declare(strict_types=1);
require_once __DIR__.'/email_delivery_status.php';

function emailSnsTopicRegion(string $arn):string
{
    if(!preg_match('/^arn:aws:sns:([a-z]{2}(?:-[a-z]+)+-\d):\d{12}:[A-Za-z0-9_-]{1,256}$/D',$arn,$m))throw new InvalidArgumentException('Invalid SNS topic.');
    return $m[1];
}

function emailSnsStringToSign(array $message):string
{
    $type=$message['Type']??'';
    $fields=match($type){'Notification'=>['Message','MessageId',...(array_key_exists('Subject',$message)?['Subject']:[]),'Timestamp','TopicArn','Type'],'SubscriptionConfirmation'=>['Message','MessageId','SubscribeURL','Timestamp','Token','TopicArn','Type'],default=>throw new InvalidArgumentException('Unsupported SNS message.')};
    $out='';foreach($fields as $field){if(!isset($message[$field])||!is_string($message[$field])||$message[$field]==='')throw new InvalidArgumentException('Missing SNS field.');$out.=$field."\n".$message[$field]."\n";}return $out;
}

function emailSnsCertificateUrl(string $url,string $region):bool
{
    return preg_match('~^https://sns\.'.preg_quote($region,'~').'\.amazonaws\.com/SimpleNotificationService-[A-Za-z0-9_-]+\.pem$~D',$url)===1;
}

/** HTTPS only, exact AWS endpoint checked by caller, no redirects, bounded reads. */
function emailSnsFetch(string $url):string
{
    $context=stream_context_create(['http'=>['timeout'=>6,'follow_location'=>0,'max_redirects'=>0,'ignore_errors'=>false],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
    $data=@file_get_contents($url,false,$context,0,65537);
    if($data===false || strlen($data)>65536 || !preg_match('~^HTTP/\S+ 2[0-9]{2}\b~',(string)($http_response_header[0]??'')))throw new RuntimeException('AWS verification endpoint unavailable.');
    return $data;
}

/** Topic allowlisting and certificate origin checks precede every network request. */
function emailSnsVerify(array $message,array $allowedTopics,?callable $fetch=null):void
{
    $topic=$message['TopicArn']??null;
    if(!is_string($topic)||!in_array($topic,$allowedTopics,true))throw new InvalidArgumentException('Unapproved SNS topic.');
    $region=emailSnsTopicRegion($topic);$signed=emailSnsStringToSign($message);
    $url=$message['SigningCertURL']??'';
    if(!is_string($url)||!emailSnsCertificateUrl($url,$region))throw new InvalidArgumentException('Invalid SNS signing certificate URL.');
    $algorithm=match($message['SignatureVersion']??''){'1'=>OPENSSL_ALGO_SHA1,'2'=>OPENSSL_ALGO_SHA256,default=>throw new InvalidArgumentException('Unsupported SNS signature version.')};
    $signature=is_string($message['Signature']??null)?base64_decode($message['Signature'],true):false;
    if($signature===false)throw new InvalidArgumentException('Invalid SNS signature.');
    $pem=($fetch??'emailSnsFetch')($url);$certificate=openssl_x509_read($pem);
    if(!$certificate)throw new InvalidArgumentException('Invalid SNS certificate.');
    $info=openssl_x509_parse($certificate);$now=time();
    if(!$info || $now<($info['validFrom_time_t']??PHP_INT_MAX) || $now>($info['validTo_time_t']??0))throw new InvalidArgumentException('Expired SNS certificate.');
    if(openssl_verify($signed,$signature,$certificate,$algorithm)!==1)throw new InvalidArgumentException('Invalid SNS signature.');
}

function emailSnsConfirmationUrl(array $message):string
{
    $region=emailSnsTopicRegion($message['TopicArn']);$url=$message['SubscribeURL'];$parts=parse_url($url);
    if(!$parts || ($parts['scheme']??'')!=='https' || ($parts['host']??'')!=='sns.'.$region.'.amazonaws.com' || isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || !in_array($parts['path']??'',['','/'],true))throw new InvalidArgumentException('Invalid SNS confirmation URL.');
    parse_str($parts['query']??'',$query);
    if(($query['Action']??'')!=='ConfirmSubscription' || ($query['TopicArn']??'')!==$message['TopicArn'] || ($query['Token']??'')!==$message['Token'])throw new InvalidArgumentException('Invalid SNS confirmation parameters.');
    return $url;
}

function emailSesEventRows(array $event):array
{
    $type=$event['eventType']??$event['notificationType']??'';
    $section=match($type){'Delivery'=>'delivery','DeliveryDelay'=>'deliveryDelay','Bounce'=>'bounce','Complaint'=>'complaint','Reject'=>'reject','Rendering Failure'=>'failure','Send'=>'send',default=>null};
    if($section===null)return [];
    $mail=$event['mail']??[];$messageId=$mail['messageId']??'';
    if(!is_string($messageId)||!preg_match('/^[A-Za-z0-9._-]{1,190}$/D',$messageId))throw new InvalidArgumentException('Missing SES message ID.');
    $data=$event[$section]??[];
    $timestamp=$data['timestamp']??$mail['timestamp']??'';
    if(!is_string($timestamp)||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|[+-]\d\d:\d\d)$/D',$timestamp))throw new InvalidArgumentException('Invalid SES event timestamp.');
    try{$at=(new DateTimeImmutable($timestamp))->setTimezone(new DateTimeZone('Europe/London'))->format('Y-m-d H:i:s');}catch(Exception $e){throw new InvalidArgumentException('Invalid SES event timestamp.');}
    $recipients=match($type){'Delivery'=>$data['recipients']??[],'Bounce'=>$data['bouncedRecipients']??[],'Complaint'=>$data['complainedRecipients']??[],'DeliveryDelay'=>$data['delayedRecipients']??[],default=>$mail['destination']??[]};
    if(!is_array($recipients))throw new InvalidArgumentException('Invalid SES recipients.');
    $rows=[];
    foreach($recipients as $recipient){
        $address=is_array($recipient)?($recipient['emailAddress']??''):$recipient;
        if(!is_string($address)||strlen($address)>255||!filter_var($address,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Invalid SES recipient.');
        $status=match($type){'Delivery'=>'delivered','DeliveryDelay'=>'delayed','Bounce'=>'bounced','Complaint'=>'complaint','Reject'=>'rejected','Rendering Failure'=>'rendering_failed','Send'=>'accepted'};
        if($type==='Bounce' && (($recipient['status']??'')==='5.1.1' || ($data['bounceSubType']??'')==='NoEmail'))$status='invalid';
        $details=[];foreach(['bounceType','bounceSubType','complaintFeedbackType','delayType','smtpResponse','reason','errorMessage'] as $key)if(isset($data[$key]) && is_scalar($data[$key]))$details[]=$key.': '.(string)$data[$key];
        if(is_array($recipient))foreach(['status','diagnosticCode'] as $key)if(isset($recipient[$key])&&is_scalar($recipient[$key]))$details[]=$key.': '.$recipient[$key];
        $detail=mb_substr(implode(' · ',$details),0,4000);$address=strtolower($address);
        $rows[]=['provider_message_id'=>$messageId,'recipient'=>$address,'event_type'=>$status,'detail'=>$detail,'occurred_at'=>$at,'event_key'=>hash('sha256',json_encode([$messageId,$type,$timestamp,$address,$detail],JSON_THROW_ON_ERROR))];
    }
    return $rows;
}

/** Only verified SNS payloads reach this function. Raw bodies and unsubscribe links are not stored. */
function emailSesRecordEvent(PDO $pdo,array $event,string $topic):int
{
    $rows=emailSesEventRows($event);if(!$rows)return 0;
    $account=explode(':',$topic)[4]??'';
    if((string)($event['mail']['sendingAccountId']??'')!==$account)throw new InvalidArgumentException('Unexpected SES sending account.');
    $pdo->beginTransaction();
    try{
        $log=null;$id=0;$mail=$event['mail'];$tags=$mail['tags']??[];
        $logId=$tags['cms_log_id'][0]??null;$token=$tags['cms_token'][0]??null;$site=$tags['cms_site'][0]??null;
        if(!$logId)foreach(($mail['headers']??[]) as $header){$name=strtolower((string)($header['name']??''));if($name==='x-cms-log-id')$logId=$header['value']??null;if($name==='x-cms-bounce-token')$token=$header['value']??null;if($name==='x-cms-site')$site=$header['value']??null;}
        if(is_scalar($logId)&&ctype_digit((string)$logId)){
            $s=$pdo->prepare("SELECT * FROM email_log WHERE id=? AND provider='ses_smtp' FOR UPDATE");$s->execute([(int)$logId]);$candidate=$s->fetch();
            $meta=$candidate?json_decode($candidate['meta_json']??'',true):null;
            if($candidate && is_string($token)&&is_string($site)&&!empty($meta['bounce_token'])&&hash_equals($meta['bounce_token'],$token)&&hash_equals((string)($meta['site_code']??''),$site))$log=$candidate;
        }
        if(!$log){$s=$pdo->prepare("SELECT el.* FROM email_log el JOIN email_delivery_transport t ON t.email_log_id=el.id WHERE t.provider_message_id=? AND el.provider='ses_smtp' FOR UPDATE");$s->execute([$rows[0]['provider_message_id']]);$log=$s->fetch()?:null;}
        if($log){
            $id=(int)$log['id'];$allowed=[];foreach(['to_email','cc','bcc'] as $field)foreach(explode(',',(string)($log[$field]??'')) as $address)$allowed[]=strtolower(trim($address));
            foreach($rows as $row)if(!in_array($row['recipient'],$allowed,true))throw new InvalidArgumentException('SES event recipient does not match the sent email.');
        }
        $s=$pdo->prepare('INSERT INTO email_delivery_events (event_key,email_log_id,provider_message_id,recipient,event_type,detail,occurred_at) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE email_log_id=COALESCE(email_log_id,VALUES(email_log_id))');
        foreach($rows as $row)$s->execute([$row['event_key'],$id?:null,$row['provider_message_id'],$row['recipient'],$row['event_type'],$row['detail'],$row['occurred_at']]);
        $pdo->commit();return count($rows);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

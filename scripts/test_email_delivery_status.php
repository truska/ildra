<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/email.php';
require_once dirname(__DIR__).'/email_ses_feedback.php';
require_once dirname(__DIR__).'/views/email_delivery_panel.php';
function deliveryCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function deliveryReject(callable $fn,string $why):void{try{$fn();}catch(InvalidArgumentException $e){return;}throw new RuntimeException($why);}
$topic='arn:aws:sns:eu-north-1:123456789012:ildra-test';
$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
$csr=openssl_csr_new(['commonName'=>'sns.eu-north-1.amazonaws.com'],$key);
$certificate=openssl_csr_sign($csr,null,$key,1);openssl_x509_export($certificate,$pem);
$message=['Type'=>'Notification','Message'=>'{"fixture":true}','MessageId'=>'test-sns-message','Timestamp'=>gmdate('Y-m-d\TH:i:s\Z'),'TopicArn'=>$topic,'SignatureVersion'=>'2','SigningCertURL'=>'https://sns.eu-north-1.amazonaws.com/SimpleNotificationService-fixture.pem'];
$sign=static function(array $m)use($key):array{openssl_sign(emailSnsStringToSign($m),$sig,$key,$m['SignatureVersion']==='2'?OPENSSL_ALGO_SHA256:OPENSSL_ALGO_SHA1);$m['Signature']=base64_encode($sig);return $m;};
$fetches=0;$fetch=static function(string $url)use($pem,&$fetches):string{$fetches++;return $pem;};
$message=$sign($message);emailSnsVerify($message,[$topic],$fetch);
$changed=$message;$changed['Message']='tampered';deliveryReject(fn()=>emailSnsVerify($changed,[$topic],$fetch),'Tampered signature accepted');
$before=$fetches;deliveryReject(fn()=>emailSnsVerify($message,[], $fetch),'Unapproved topic accepted');deliveryCheck($fetches===$before,'Unapproved topic fetched certificate');
foreach(['http://sns.eu-north-1.amazonaws.com/SimpleNotificationService-fixture.pem','https://sns.eu-north-1.amazonaws.com.evil.test/SimpleNotificationService-fixture.pem','https://sns.eu-north-1.amazonaws.com:443/SimpleNotificationService-fixture.pem','https://sns.eu-north-1.amazonaws.com/SimpleNotificationService-fixture.pem?x=1','https://127.0.0.1/cert.pem','https://sns.eu-west-1.amazonaws.com/SimpleNotificationService-fixture.pem'] as $url){$changed=$message;$changed['SigningCertURL']=$url;deliveryReject(fn()=>emailSnsVerify($changed,[$topic],$fetch),'Unsafe certificate URL accepted');}
$message['SignatureVersion']='1';emailSnsVerify($sign($message),[$topic],$fetch);
$confirm=$message;$confirm['Type']='SubscriptionConfirmation';$confirm['Token']='fixture-token';$confirm['SubscribeURL']='https://sns.eu-north-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn='.rawurlencode($topic).'&Token=fixture-token';
emailSnsVerify($sign($confirm),[$topic],$fetch);deliveryCheck(emailSnsConfirmationUrl($confirm)===$confirm['SubscribeURL'],'Confirmation rejected');
$bad=$confirm;$bad['SubscribeURL']=str_replace('fixture-token','wrong-token',$bad['SubscribeURL']);deliveryReject(fn()=>emailSnsConfirmationUrl($bad),'Wrong confirmation token accepted');
$meta=['bounce_token'=>'fixture-random-token','site_code'=>'ildra'];
$email=['id'=>1,'provider'=>'ses_smtp','status'=>'sent','to_email'=>'actual@example.test','cc'=>'copy@example.test','bcc'=>'','meta_json'=>json_encode($meta)];
$event=['eventType'=>'Delivery','mail'=>['messageId'=>'fixture-ses-id','timestamp'=>'2026-07-01T12:00:00Z','sendingAccountId'=>'123456789012','destination'=>['actual@example.test','copy@example.test'],'tags'=>['cms_log_id'=>['1'],'cms_site'=>['ildra'],'cms_token'=>['fixture-random-token']]],'delivery'=>['timestamp'=>'2026-07-01T12:00:05Z','recipients'=>['actual@example.test'],'smtpResponse'=>'250 Accepted']];
$rows=emailSesEventRows($event);deliveryCheck($rows[0]['occurred_at']==='2026-07-01 13:00:05','BST conversion failed');
deliveryCheck(emailDeliverySummary($email,$rows)['type']==='partial','Partial delivery called complete');
$all=$rows;$all[]=array_replace($rows[0],['recipient'=>'copy@example.test']);deliveryCheck(emailDeliverySummary($email,$all)['type']==='delivered','Complete delivery not recognised');
$all[]=array_replace($rows[0],['event_type'=>'delayed']);deliveryCheck(emailDeliverySummary($email,$all)['type']==='delivered','Delayed report downgraded delivery');
$bounce=$event;$bounce['eventType']='Bounce';$bounce['bounce']=['timestamp'=>'2026-07-01T12:02:00Z','bounceType'=>'Permanent','bounceSubType'=>'General','bouncedRecipients'=>[['emailAddress'=>'actual@example.test','status'=>'5.1.1','diagnosticCode'=>'smtp; 550 No such user']]];
deliveryCheck(emailSesEventRows($bounce)[0]['event_type']==='invalid','Explicit missing mailbox not labelled invalid');
$bounce['bounce']['bouncedRecipients'][0]['status']='5.7.1';deliveryCheck(emailSesEventRows($bounce)[0]['event_type']==='bounced','Policy bounce falsely labelled invalid');
$unknown=emailDeliverySummary($email,[]);deliveryCheck($unknown['label']==='Delivery not confirmed','Acceptance falsely labelled delivered');
$redirect=$email;$redirect['meta_json']=json_encode($meta+['email_test_redirect'=>['enabled'=>true,'original_to'=>'original@example.test']]);
ob_start();emailDeliveryRenderPanel($redirect,['events'=>$rows]);$html=ob_get_clean();deliveryCheck(str_contains($html,'Original recipient: original@example.test') && str_contains($html,'does not confirm delivery to the original recipient'),'Redirect attribution missing');
$unsafe=$rows;$unsafe[0]['detail']='<script>alert(1)</script>';ob_start();emailDeliveryRenderPanel($email,['events'=>$unsafe]);$html=ob_get_clean();deliveryCheck(!str_contains($html,'<script>'),'Feedback HTML was not escaped');
deliveryCheck(emailDeliverySmtpReceipt('250 Ok 010201-test-message-id')['message_id']==='010201-test-message-id','SES SMTP ID missing');

if(in_array('--mysql',$argv,true)){
    require_once dirname(__DIR__).'/db.php';$alerts=[];$pdo=createPdo(require dirname(__DIR__,2).'/private/config.php',$alerts);if(!$pdo)throw new RuntimeException('Database unavailable');
    $pdo->exec('CREATE TEMPORARY TABLE delivery_fixture_seed LIKE email_log');$pdo->exec('CREATE TEMPORARY TABLE email_log LIKE delivery_fixture_seed');$pdo->exec('DROP TEMPORARY TABLE delivery_fixture_seed');
    foreach(explode(';',file_get_contents(dirname(__DIR__).'/migrations/2026-11-17-email-delivery-status.sql')) as $sql)if(trim($sql)!=='')$pdo->exec(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE IF NOT EXISTS',$sql));
    foreach(['email_log','email_delivery_transport','email_delivery_events'] as $table)deliveryCheck(str_contains($pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1],'CREATE TEMPORARY TABLE'),'Non-temporary table');
    $s=$pdo->prepare('INSERT INTO email_log (id,status,provider,to_email,cc,subject,meta_json) VALUES (1,?,?,?,?,?,?)');$s->execute(['sent','ses_smtp',$email['to_email'],$email['cc'],'Fixture only',$email['meta_json']]);
    emailSesRecordEvent($pdo,$event,$topic);emailSesRecordEvent($pdo,$event,$topic);
    deliveryCheck((int)$pdo->query('SELECT COUNT(*) FROM email_delivery_events')->fetchColumn()===1,'Duplicate SNS report duplicated event');
    deliveryCheck((int)$pdo->query('SELECT email_log_id FROM email_delivery_events')->fetchColumn()===1,'Early event not linked by token');
    $bad=$event;$bad['delivery']['recipients']=['original@example.test'];deliveryReject(fn()=>emailSesRecordEvent($pdo,$bad,$topic),'Original redirected recipient accepted');
    $bad=$event;$bad['mail']['sendingAccountId']='999999999999';deliveryReject(fn()=>emailSesRecordEvent($pdo,$bad,$topic),'Wrong AWS account accepted');
    $unlinked=$event;unset($unlinked['mail']['tags']);$unlinked['mail']['messageId']='fixture-late-receipt';emailSesRecordEvent($pdo,$unlinked,$topic);
    deliveryCheck((int)$pdo->query('SELECT COUNT(*) FROM email_delivery_events WHERE email_log_id IS NULL')->fetchColumn()===1,'Unmatched report lost');
    emailDeliveryRecordTransport($pdo,1,['message_id'=>'fixture-late-receipt','response'=>'250 Ok fixture-late-receipt'],750);
    deliveryCheck((int)$pdo->query('SELECT COUNT(*) FROM email_delivery_events WHERE email_log_id IS NULL')->fetchColumn()===0,'Event/receipt race not reconciled');
    $data=emailDeliveryData($pdo,[1]);deliveryCheck(count($data[1]['events'])===2 && (int)$data[1]['transport']['duration_ms']===750,'Detail data missing');
    deliveryCheck($pdo->query('SELECT status FROM email_log')->fetchColumn()==='sent','Feedback altered legacy submission state');
}
if(in_array('--smtp',$argv,true)){
    if(!function_exists('pcntl_fork'))throw new RuntimeException('pcntl required for local SMTP fixture');
    foreach([true,false,null] as $accepted){
        $server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$server)throw new RuntimeException('Fixture listener failed');
        $port=(int)substr(strrchr(stream_socket_get_name($server,false),':'),1);$pid=pcntl_fork();
        if($pid===-1)throw new RuntimeException('Fixture fork failed');
        if($pid===0){
            $client=stream_socket_accept($server,5);if(!$client)exit(2);stream_set_timeout($client,5);fwrite($client,"220 Fixture\r\n");
            while(($line=fgets($client))!==false){
                $line=rtrim($line,"\r\n");
                if(str_starts_with($line,'EHLO'))fwrite($client,"250 Fixture\r\n");
                elseif(str_starts_with($line,'MAIL FROM') || str_starts_with($line,'RCPT TO'))fwrite($client,"250 OK\r\n");
                elseif($line==='DATA'){
                    fwrite($client,"354 Begin\r\n");while(($data=fgets($client))!==false && rtrim($data,"\r\n")!=='.'){}
                    if($accepted!==null)fwrite($client,$accepted?"250 Ok fixture-accepted-id\r\n":"550 Message rejected\r\n");break;
                }else exit(3);
            }
            fclose($client);fclose($server);exit(0);
        }
        fclose($server);$thrown=false;$receipt=[];
        try{$receipt=smtp_send_mail(['email_smtp_host'=>'127.0.0.1','email_smtp_port'=>$port,'email_smtp_secure'=>'none'],'fixture@example.test','Fixture','recipient@example.test',[],[],'Fixture only','<p>Fixture</p>','Fixture','fixture@example.test');}
        catch(RuntimeException $e){$thrown=true;if($accepted===null)deliveryCheck($e instanceof EmailSubmissionUnconfirmed,'Lost DATA acceptance not labelled uncertain');}
        pcntl_waitpid($pid,$waitStatus);deliveryCheck(pcntl_wexitstatus($waitStatus)===0,'SMTP fixture failed');
        deliveryCheck($accepted?!$thrown&&($receipt['message_id']??'')==='fixture-accepted-id':$thrown,'DATA acceptance/QUIT disconnect misclassified');
    }
}
if(($fixtureArg=array_search('--fixtures',$argv,true))!==false){
    $directory=realpath($argv[$fixtureArg+1]??'');if(!$directory || !str_starts_with($directory,'/tmp/'))throw new RuntimeException('Private /tmp fixture directory required');
    foreach(['accepted'=>[],'partial'=>$rows,'delivered'=>array_slice($all,0,2),'invalid'=>emailSesEventRows($bounce)] as $name=>$events){
        ob_start();echo '<!doctype html><html><head><meta charset="utf-8"></head><body>';emailDeliveryRenderPanel($redirect,['events'=>$events,'transport'=>['provider_message_id'=>'fixture-ses-id','duration_ms'=>750,'smtp_response'=>'250 Ok fixture-ses-id']]);echo '</body></html>';$html=ob_get_clean();file_put_contents($directory.'/'.$name.'.html',$html);chmod($directory.'/'.$name.'.html',0600);
    }
}
echo "PASS: SNS signatures and topic/certificate restrictions, confirmation URLs, recipient outcomes, duplicate/out-of-order events, redirect attribution, escaping, SES IDs".(in_array('--mysql',$argv,true)?', temporary-table ingestion and receipt races':'').". No real emails sent.\n";

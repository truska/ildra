<?php
declare(strict_types=1);

class EmailSubmissionUnconfirmed extends RuntimeException {}

/** Email delivery observability, independent of campaigns and account migration. */
function ensureEmailDeliveryTables(PDO $pdo):void
{
    if($pdo->inTransaction())throw new RuntimeException('Prepare email delivery tables outside transactions.');
    static $prepared; $prepared??=new WeakMap();
    if(isset($prepared[$pdo]))return;
    foreach(explode(';',file_get_contents(__DIR__.'/migrations/2026-11-17-email-delivery-status.sql')) as $sql)if(trim($sql)!=='')$pdo->exec($sql);
    $prepared[$pdo]=true;
}

function emailDeliverySmtpReceipt(string $response):array
{
    $response=substr(trim($response),0,2000);$id=null;
    if(preg_match('/^250[ -]Ok\s+<?([A-Za-z0-9][A-Za-z0-9._-]{8,189})>?(?:\s|$)/i',$response,$m))$id=$m[1];
    return ['response'=>$response,'message_id'=>$id];
}

function emailDeliveryRecordTransport(PDO $pdo,int $id,array $receipt,int $duration):void
{
    $s=$pdo->prepare('INSERT INTO email_delivery_transport (email_log_id,provider_message_id,duration_ms,smtp_response) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE provider_message_id=VALUES(provider_message_id),duration_ms=VALUES(duration_ms),smtp_response=VALUES(smtp_response)');
    $s->execute([$id,$receipt['message_id']??null,max(0,$duration),$receipt['response']??null]);
    // Events can arrive before the SMTP response is recorded. Preserve them and attach now.
    if(!empty($receipt['message_id'])){
        $s=$pdo->prepare("SELECT * FROM email_log WHERE id=? AND provider='ses_smtp'");$s->execute([$id]);$email=$s->fetch();
        if($email){
            $allowed=[];foreach(['to_email','cc','bcc'] as $field)foreach(explode(',',(string)($email[$field]??'')) as $recipient)$allowed[]=strtolower(trim($recipient));
            foreach(array_unique($allowed) as $recipient)if($recipient!=='')$pdo->prepare('UPDATE email_delivery_events SET email_log_id=? WHERE provider_message_id=? AND recipient=? AND email_log_id IS NULL')->execute([$id,$receipt['message_id'],$recipient]);
        }
    }
}

function emailDeliveryData(PDO $pdo,array $ids):array
{
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn($id)=>$id>0)));if(!$ids)return [];
    $in=implode(',',array_fill(0,count($ids),'?'));$result=[];
    $s=$pdo->prepare("SELECT * FROM email_delivery_transport WHERE email_log_id IN ($in)");$s->execute($ids);
    foreach($s as $row)$result[(int)$row['email_log_id']]['transport']=$row;
    $s=$pdo->prepare("SELECT * FROM email_delivery_events WHERE email_log_id IN ($in) ORDER BY occurred_at,id");$s->execute($ids);
    foreach($s as $row)$result[(int)$row['email_log_id']]['events'][]=$row;
    return $result;
}

function emailDeliveryEventLabel(string $type):string
{
    return match($type){'accepted'=>'Accepted','delivered'=>'Delivered','delayed'=>'Delivery delayed','bounced'=>'Bounced','invalid'=>'Invalid address','complaint'=>'Spam complaint','rejected'=>'Rejected','rendering_failed'=>'Rendering failed',default=>ucfirst($type)};
}

function emailDeliveryBadge(string $type):string
{
    return match($type){'delivered'=>'bg-success-subtle text-success','invalid','bounced','rejected','rendering_failed','complaint','failed'=>'bg-danger-subtle text-danger','delayed','partial','uncertain'=>'bg-warning-subtle text-warning',default=>'bg-secondary-subtle text-secondary'};
}

function emailDeliverySubmissionLabel(array $email):string
{
    $meta=json_decode((string)($email['meta_json']??''),true);
    if(!empty($meta['submission_uncertain']))return 'Submission unconfirmed';
    return ($email['status']??'')==='sent'?(($email['provider']??'')==='ses_smtp'?'Accepted by Amazon SES':'Accepted by sending system'):ucfirst((string)($email['status']??''));
}

/** Outcomes apply to actual envelope recipients, including redirected delivery, never the original address. */
function emailDeliverySummary(array $email,array $events):array
{
    $recipients=[];
    foreach(['to_email','cc','bcc'] as $field)foreach(explode(',',(string)($email[$field]??'')) as $recipient){$recipient=strtolower(trim($recipient));if($recipient!=='')$recipients[$recipient]='unknown';}
    $ranks=['unknown'=>0,'accepted'=>1,'delayed'=>2,'delivered'=>3,'rendering_failed'=>4,'rejected'=>4,'bounced'=>5,'invalid'=>5,'complaint'=>6];
    foreach($events as $event){$to=strtolower($event['recipient']);$type=$event['event_type'];if(isset($recipients[$to]) && ($ranks[$type]??0)>=($ranks[$recipients[$to]]??0))$recipients[$to]=$type;}
    $counts=array_count_values($recipients);unset($counts['unknown'],$counts['accepted']);
    if(!$counts)return ['type'=>'unknown','label'=>($email['status']??'')==='sent'?'Delivery not confirmed':'','detail'=>''];
    if(count($counts)===1 && array_sum($counts)===count($recipients)){$type=array_key_first($counts);return ['type'=>$type,'label'=>emailDeliveryEventLabel($type),'detail'=>count($recipients)>1?count($recipients).' recipients':''];}
    $parts=[];foreach($counts as $type=>$count)$parts[]=$count.' '.strtolower(emailDeliveryEventLabel($type));
    $unknown=count($recipients)-array_sum($counts);if($unknown)$parts[]=$unknown.' unconfirmed';
    return ['type'=>'partial','label'=>'Mixed recipient status','detail'=>implode('; ',$parts)];
}

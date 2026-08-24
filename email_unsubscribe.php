<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$token=trim((string)($_GET['t']??$_POST['t']??''));$recipient=null;$done=false;$category='general';
if($pdo&&preg_match('/^[a-f0-9]{40}$/',$token)){
    $stmt=$pdo->prepare('SELECT r.*,c.name campaign_name,c.category FROM email_campaign_recipients r JOIN email_campaigns c ON c.id=r.campaign_id WHERE r.unsubscribe_token=:token LIMIT 1');$stmt->execute([':token'=>$token]);$recipient=$stmt->fetch()?:null;
}
if($recipient)$category=(string)$recipient['category'];
if($recipient&&($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&$category!=='operational'){
    $column=$category==='ride_notice'?'ride_notice_opt_in':'general_email_opt_in';
    $normalized=strtolower(trim((string)$recipient['email']));
    $pdo->prepare("UPDATE users SET {$column}=0,updated_at=NOW() WHERE LOWER(TRIM(email))=:email")->execute([':email'=>$normalized]);
    $pdo->prepare("UPDATE people SET {$column}=0,updated_at=NOW() WHERE LOWER(TRIM(email))=:email")->execute([':email'=>$normalized]);
    $pdo->prepare("UPDATE email_campaign_recipients r JOIN email_campaigns c ON c.id=r.campaign_id SET r.status='unsubscribed',r.error_message='Preference withdrawn before delivery.' WHERE r.email_normalized=:email AND r.status='pending' AND c.category=:category")->execute([':email'=>$normalized,':category'=>$category]);$done=true;
}
$siteSettings=$siteSettingsBootstrap??defaultSiteSettings();$headerIsHome=false;$navTree=$navTree??[];
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Email preferences</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><main class="container py-5" style="max-width:680px"><div class="card shadow-sm"><div class="card-body p-4 p-md-5"><h1 class="h3">Email preferences</h1><?php if(!$recipient): ?><div class="alert alert-warning mt-3">This email preference link is invalid or has expired.</div><?php elseif($category==='operational'): ?><div class="alert alert-info mt-3">This was an essential message about an event or service connected to you. Operational messages are not part of marketing subscriptions.</div><?php elseif($done): ?><div class="alert alert-success mt-3">You have been unsubscribed from <?php echo $category==='ride_notice'?'the weekly Ride Notice':'general news, announcements and renewal reminders'; ?>.</div><?php else: ?><p>This will unsubscribe <strong><?php echo h((string)$recipient['email']); ?></strong> from <?php echo $category==='ride_notice'?'the weekly Ride Notice':'general news, announcements and renewal reminders'; ?>.</p><form method="post"><input type="hidden" name="t" value="<?php echo h($token); ?>"><button class="btn btn-danger">Unsubscribe</button></form><?php endif; ?></div></div></main></body></html>

<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
date_default_timezone_set('Europe/London');
require dirname(__DIR__).'/db.php';
require dirname(__DIR__).'/helpers.php';
require dirname(__DIR__).'/cms.php';
require dirname(__DIR__).'/ride_pricing_migration.php';
$options=getopt('', ['scheme-id:','apply','expect-sha:']);
$config=require dirname(__DIR__).'/config.php';$alerts=[];$pdo=createPdo($config,$alerts);
if(!$pdo){fwrite(STDERR,"Database unavailable.\n");exit(1);}
try {
    $id=(int)($options['scheme-id']??0);
    if($id<=0)throw new RuntimeException('Specify --scheme-id=ID for the authoritative all-classes Ride scheme. Default operation is preview only.');
    if(ridePricingReady($pdo)){
        echo "Master ride pricing is already active. No data changed; later master edits are preserved.\n";exit;
    }
    $plan=buildRidePricingMigrationPlan($pdo,$id);
    echo 'Database: '.$pdo->query('SELECT DATABASE()')->fetchColumn()."\n";
    foreach($plan['masters'] as $row)echo $row['class_group'].' | '.$row['class_name'].' | '.$row['rider_eligibility'].' | '.($row['is_member_price']?'Member':'Non-member').' | GBP '.$row['price']."\n";
    echo count($plan['masters']).' master classes; '.count($plan['ride_scheme_ids']).' ride schemes; '.count($plan['selections'])." selections.\n";
    foreach($plan['price_changes'] as $row)echo 'Scheme '.$row['scheme_id'].': '.$row['class'].' GBP '.$row['old_price'].' -> '.$row['master_price']."\n";
    echo 'Preview SHA: '.$plan['source_sha256']."\n";
    if(!isset($options['apply'])){echo "PREVIEW ONLY. Back up the database, then rerun with --apply --expect-sha=<preview SHA>.\n";exit;}
    $expected=(string)($options['expect-sha']??'');
    if(!preg_match('/^[a-f0-9]{64}$/D',$expected))throw new RuntimeException('Applying requires --expect-sha from the reviewed preview.');
    echo json_encode(applyRidePricingMigration($pdo,$id,$expected),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}

<?php
require dirname(__DIR__, 2) . '/db.php';
require dirname(__DIR__, 2) . '/testing_reset.php';
$c=require dirname(__DIR__, 3) . '/private/config.php';$a=[];$p=createPdo($c,$a);
$tables=array_unique(array_merge(testingResetTables(false),['users','people','site_settings']));
$existing=$p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as $t) if(in_array($t,$existing,true)) $p->exec("CREATE TEMPORARY TABLE `$t` AS SELECT * FROM `$t`");
$p->exec("INSERT INTO horses (id,owner_user_id,name,is_archived) SELECT 1,0,'Not Registered',0 WHERE NOT EXISTS (SELECT 1 FROM horses WHERE id=1)");
$systemHorse=$p->query('SELECT * FROM horses WHERE id=1')->fetch();
$identities=testingResetIdentities($p);$choices=[];
foreach($identities as $u) $choices[$u['id']]=$u['suggested'] ?? ($u['people'][0]['id'] ?? 0);
$users=$p->query('SELECT * FROM users')->fetchAll();$events=$p->query('SELECT * FROM events')->fetchAll();
foreach([true,false] as $calendar){
 $plan=testingResetPlan($p,$calendar,$choices);$p->beginTransaction();testingResetApply($p,$plan);
 foreach(testingResetCounts($p,$plan) as $t=>$n) if($n!==0)throw new Exception('Remaining rows: '.$t);
 if($p->query('SELECT * FROM horses WHERE id=1')->fetch()!==$systemHorse)throw new Exception('System horse changed');
 if($p->query('SELECT * FROM users')->fetchAll()!==$users)throw new Exception('Users changed');
 if($calendar && $p->query('SELECT * FROM events')->fetchAll()!==$events)throw new Exception('Calendar changed');
 if(!$calendar && $p->query('SELECT COUNT(*) FROM events')->fetchColumn()!=0)throw new Exception('Events remain');
 if((int)$p->query('SELECT COUNT(*) FROM people')->fetchColumn()!==count($plan['keep_people']))throw new Exception('Wrong retained people');
 $p->rollBack();echo ($calendar?'Keep calendar':'Full reset').": passed using temporary tables\n";
}
try{testingResetPlan($p,true,[]);throw new Exception('Missing identities accepted');}catch(RuntimeException $e){echo "Missing identity choices rejected: passed\n";}

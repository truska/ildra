<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/db.php';
require dirname(__DIR__).'/auth.php';
require dirname(__DIR__).'/account_migration.php';
try{
    if(count($argv)>2 || (isset($argv[1]) && !in_array($argv[1],['--check','--apply'],true)))throw new RuntimeException('Usage: php scripts/prepare_account_migration.php [--check|--apply]');
    $alerts=[];$pdo=createPdo(require dirname(__DIR__,2).'/private/config.php',$alerts);if(!$pdo)throw new RuntimeException('Database unavailable.');
    $config=migrationRolloutConfig();$database=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if(!migrationProductionEnvironmentAllowed(array_replace($config,['enabled'=>true]),(string)realpath(dirname(__DIR__)),$database,(string)($config['host']??'')))throw new RuntimeException('Configure the destination root, database, approved hostname/origin and delivery mode in private/account-migration.php first.');
    echo 'Destination: '.$config['host'].'; database: '.$database."\n";
    if(($argv[1]??'--check')==='--apply'){
        ensureAuthTables($pdo);ensureMigrationTables($pdo);
        echo "Additive schema and initial draft rows prepared. No account enrolled, imported or emailed.\n";
    }else{
        foreach(['migration_campaigns','migration_enrolments','migration_invitations','migration_page_copy','record_notes','migration_horse_reviews','migration_horse_choices'] as $table){
            $s=$pdo->prepare('SHOW TABLES LIKE ?');$s->execute([$table]);echo $table.': '.($s->fetchColumn()?'present':'missing')."\n";
        }
        echo "Check only. Take the destination backup before --apply.\n";
    }
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}

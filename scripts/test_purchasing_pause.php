<?php
declare(strict_types=1);
require __DIR__.'/../purchasing.php';
function checkPause(bool $ok, string $description): void {
    if (!$ok) throw new RuntimeException($description);
}
$open = ['purchasing_paused_at'=>0, 'purchasing_paused_seconds'=>0];
$paused = ['purchasing_paused_at'=>1000, 'purchasing_paused_seconds'=>0];
foreach ([null, ['role'=>'user'], ['role'=>'organiser'], ['role'=>'manager']] as $user) {
    checkPause(purchasingBlocked($paused,$user), 'Lower roles and guests must be blocked');
    checkPause(!purchasingBlocked($open,$user), 'Open purchasing must remain available');
}
foreach (['admin','superadmin','developer','Admin'] as $role) checkPause(!purchasingBlocked($paused,['role'=>$role]), 'Administrator bypass');
checkPause(purchasingBasketClock($paused, 1600)===1000, 'Clock freezes even for an offline basket');
$resumed = ['purchasing_paused_at'=>0, 'purchasing_paused_seconds'=>600];
checkPause(purchasingBasketClock($resumed,1600)===1000,'Resume preserves remaining time');
checkPause(purchasingBasketClock($resumed,1660)===1060,'Countdown resumes normally');
$second = ['purchasing_paused_at'=>1700,'purchasing_paused_seconds'=>600];
checkPause(purchasingBasketClock($second,2000)===1100,'Repeated pauses preserve prior elapsed maintenance');
checkPause(purchasingMessage(['purchasing_pause_message'=>'  '])!=='','Empty message uses default');
checkPause(purchasingMessage(['purchasing_pause_message'=>'Custom notice'])==='Custom notice','Custom message retained');
echo "Purchasing pause role and timer checks passed.\n";

if (in_array('--mysql', $argv, true)) {
    require __DIR__.'/../cms.php';
    $config = require __DIR__.'/../config.php'; $db = $config['db'];
    $pdo = new PDO('mysql:host='.$db['host'].';port='.($db['port']??3306).';dbname='.$db['name'].';charset=utf8mb4', $db['user'], $db['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    // Temporary names shadow real tables for this connection only.
    $pdo->exec("CREATE TEMPORARY TABLE site_settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT,updated_at DATETIME)");
    $pdo->exec("CREATE TEMPORARY TABLE baskets(session_id VARCHAR(190) PRIMARY KEY,basket_json TEXT,last_added_at DATETIME)");
    $pdo->exec("INSERT INTO baskets VALUES('fixture','[1]',FROM_UNIXTIME(UNIX_TIMESTAMP()-100))");
    savePurchasingPause($pdo,true,'Test maintenance');
    $read = fn() => $pdo->query('SELECT setting_key,setting_value FROM site_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    checkPause(purchasingPaused($read()),'Pause persisted');
    $pdo->exec("UPDATE site_settings SET setting_value=UNIX_TIMESTAMP()-600 WHERE setting_key='purchasing_paused_at'");
    checkPause(tidyExpiredBaskets($pdo,300)===0,'Tidy preserves paused baskets');
    savePurchasingPause($pdo,false,'Test maintenance');
    checkPause(!purchasingPaused($read()),'Resume persisted');
    checkPause((int)$read()['purchasing_paused_seconds']>=600,'Elapsed pause persisted');
    checkPause(tidyExpiredBaskets($pdo,300)===0,'Offline basket survives resume');
    $pdo->exec("UPDATE baskets SET last_added_at=FROM_UNIXTIME(UNIX_TIMESTAMP()-1000)");
    checkPause(tidyExpiredBaskets($pdo,300)===1,'Tidy expires basket after remaining active time');
    echo "Temporary-table maintenance and expiry checks passed. No persistent data changed.\n";
}

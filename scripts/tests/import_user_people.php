<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/import_user_people.php';
function verifyImport(bool $ok): void { if (!$ok) throw new RuntimeException('Import assertion failed.'); }
$a=[];$p=createPdo(require dirname(__DIR__,3).'/private/config.php',$a);if(!$p)throw new RuntimeException('Database unavailable');
foreach (['users','people'] as $t) {
    $p->exec("CREATE TEMPORARY TABLE import_test_seed LIKE `$t`");
    $p->exec("CREATE TEMPORARY TABLE `$t` LIKE import_test_seed");
    $p->exec("DROP TEMPORARY TABLE import_test_seed");
    verifyImport(str_contains($p->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1],'CREATE TEMPORARY TABLE'));
}
$r=['email'=>'fixture@example.com','first_name'=>'Test','last_name'=>'Person','row'=>2];
verifyImport(previewAccountCsv($p,[$r])[0]['user']===null);
$p->exec("INSERT INTO users (id,email,first_name,last_name,password_hash,role_id,is_protected) VALUES (123,'fixture@example.com','Test','Person','unchanged',4,1)");
$before=$p->query('SELECT * FROM users')->fetch();
$plan=previewAccountCsv($p,[$r]);verifyImport($plan[0]['user']===$before && $plan[0]['person']===null);
$p->exec("INSERT INTO people (owner_user_id,email,first_name,last_name) VALUES (123,'fixture@example.com','Test','Person')");
verifyImport(previewAccountCsv($p,[$r])[0]['person']!==null);
$p->exec("INSERT INTO people (owner_user_id,email,first_name,last_name) VALUES (123,'other@example.com','Other','Person')");
try { previewAccountCsv($p,[$r]);throw new LogicException('Ambiguous people accepted'); } catch (RuntimeException $e) {}
verifyImport($p->query('SELECT * FROM users')->fetch()===$before);
$path=dirname(__DIR__,3).'/private/import-parser-fixture-'.bin2hex(random_bytes(5)).'.csv';
try {
    file_put_contents($path,"\xEF\xBB\xBF\"Email Address\",\"First Name\",Surname\n FIXTURE@EXAMPLE.COM ,Test,Person\n");chmod($path,0600);
    verifyImport(readAccountCsv($path)[0]['email']==='fixture@example.com');
    file_put_contents($path,"fixture@example.com,Test,Person\n",FILE_APPEND);
    try { readAccountCsv($path);throw new LogicException('Duplicate accepted'); } catch (RuntimeException $e) {}
} finally { unlink($path); }
echo "PASS: BOM/header handling, normalisation, duplicates, preserved user, rerun matching and ownership conflicts (temporary tables only).\n";

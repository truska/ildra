<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Execute the unchanged real backup runner against a PDO double and /tmp only.
$directory=sys_get_temp_dir().'/reset-backup-fixture-'.bin2hex(random_bytes(6));
mkdir($directory,0700);mkdir($directory.'/web',0700);mkdir($directory.'/private',0700);
$source=file_get_contents(dirname(__DIR__,2).'/testing_reset.php');
$source=substr($source,strpos($source,'function testingResetRun('));
$source=str_replace('__DIR__',var_export($directory.'/web',true),$source);
eval('namespace ResetBackupFixture; use \\PDO; use \\RuntimeException; use \\Throwable; '.$source);
class ResetBackupStatement extends PDOStatement {
    private int $index=0;
    public function __construct(private array $rows) {}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed { return $this->rows[$this->index++]??false; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array { return $this->rows; }
}
class ResetBackupPDO extends PDO {
    public bool $transaction=false;
    public bool $committed=false;
    public bool $rolledBack=false;
    public string $engine='InnoDB';
    public function __construct() {}
    public function beginTransaction():bool { $this->transaction=true;return true; }
    public function inTransaction():bool { return $this->transaction; }
    public function commit():bool { $this->committed=true;$this->transaction=false;return true; }
    public function rollBack():bool { $this->rolledBack=true;$this->transaction=false;return true; }
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false {
        if ($query==='SHOW TABLE STATUS') return new ResetBackupStatement([['Name'=>'fixture','Engine'=>$this->engine]]);
        if ($query==='SHOW CREATE TABLE `fixture`') return new ResetBackupStatement([['fixture','CREATE TABLE fixture(id INT)']]);
        if (str_starts_with($query,'SELECT * FROM `fixture`')) return new ResetBackupStatement([['id'=>1,'value'=>'offline fixture']]);
        throw new RuntimeException('Unexpected fake database query');
    }
}
$stub=<<<'PHPSTUB'
namespace ResetBackupFixture;
function testingResetPlan($pdo,$calendar,$choices):array { return ['keep_calendar'=>true,'delete'=>[]]; }
function testingResetCounts($pdo,$plan):array { return ['fixture'=>1]; }
function testingResetApply($pdo,$plan):void {
    $GLOBALS['offline_apply_calls']++;
    $files=glob($GLOBALS['offline_backup_directory'].'/*.jsonl');
    if (count($files)!==1) throw new \RuntimeException('Backup missing before apply');
    $lines=file($files[0],FILE_IGNORE_NEW_LINES);
    if (count($lines)!==3 || json_decode($lines[2],true)['row']['value']!=='offline fixture') throw new \RuntimeException('Incomplete backup before apply');
    if ((fileperms($files[0])&0777)!==0600) throw new \RuntimeException('Backup not private');
}
PHPSTUB;
eval($stub);
$GLOBALS['offline_apply_calls']=0;$GLOBALS['offline_backup_directory']=$directory.'/private/testing-reset-backups';
$plan=['keep_calendar'=>true,'delete'=>[]];
try {
    $pdo=new ResetBackupPDO();
    \ResetBackupFixture\testingResetRun($pdo,$plan,42,[],['fixture'=>99]);
    throw new LogicException('Stale counts accepted');
} catch (RuntimeException $e) {
    if (!$pdo->rolledBack || $GLOBALS['offline_apply_calls']) throw new LogicException('Stale counts reached apply');
}
foreach (glob($GLOBALS['offline_backup_directory'].'/*') as $file) unlink($file);
try {
    $pdo=new ResetBackupPDO();$pdo->engine='MyISAM';
    \ResetBackupFixture\testingResetRun($pdo,$plan,42,[],['fixture'=>1]);
    throw new LogicException('Nontransactional engine accepted');
} catch (RuntimeException $e) {
    if (!$pdo->rolledBack || $GLOBALS['offline_apply_calls']) throw new LogicException('Nontransactional engine reached apply');
}
foreach (glob($GLOBALS['offline_backup_directory'].'/*') as $file) unlink($file);
$pdo=new ResetBackupPDO();
\ResetBackupFixture\testingResetRun($pdo,$plan,42,[],['fixture'=>1]);
if (!$pdo->committed || $GLOBALS['offline_apply_calls']!==1) throw new LogicException('Backed-up offline runner failed');
foreach (glob($GLOBALS['offline_backup_directory'].'/*') as $file) unlink($file);
rmdir($GLOBALS['offline_backup_directory']);
// A file in place of the directory prevents backup creation before any apply.
file_put_contents($GLOBALS['offline_backup_directory'],'blocked');
try {
    $pdo=new ResetBackupPDO();
    @\ResetBackupFixture\testingResetRun($pdo,$plan,42,[],['fixture'=>1]);
    throw new LogicException('Backup failure accepted');
} catch (RuntimeException $e) {
    if ($GLOBALS['offline_apply_calls']!==1 || $pdo->committed) throw new LogicException('Backup failure reached apply');
} finally {
    unlink($GLOBALS['offline_backup_directory']);rmdir($directory.'/private');rmdir($directory.'/web');rmdir($directory);
}
echo "PASS: real backup runner rejects stale counts, nontransactional tables and backup failure; full private backup precedes apply. PDO/apply doubles and /tmp only; no database/reset.\n";

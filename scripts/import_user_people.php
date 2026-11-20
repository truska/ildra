<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/db.php';
require dirname(__DIR__) . '/migration_rollout.php';

/** Strict delivery validation; original files remain outside the website. */
function readAccountCsv(string $path): array
{
    $real = realpath($path);
    $private = realpath(dirname(__DIR__, 2) . '/private');
    if (!$real || !$private || !str_starts_with($real, $private . '/')) throw new RuntimeException('CSV must be inside the private directory.');
    $f = fopen($real, 'r');
    if (!$f) throw new RuntimeException('Cannot open CSV.');
    try {
        $prefix = fread($f, 3);
        if ($prefix !== "\xEF\xBB\xBF") rewind($f);
        $headers = fgetcsv($f, 0, ',', '"', '');
        $map = ['email address'=>'email', 'email'=>'email', 'first name'=>'first_name', 'first_name'=>'first_name', 'surname'=>'last_name', 'last_name'=>'last_name'];
        $headers = array_map(static fn($v) => $map[strtolower(trim($v))] ?? '', $headers ?: []);
        $sorted = $headers; sort($sorted);
        if ($sorted !== ['email','first_name','last_name']) throw new RuntimeException('Expected email, first name and surname headers.');
        $rows = []; $seen = []; $line = 1;
        while (($values = fgetcsv($f, 0, ',', '"', '')) !== false) {
            ++$line;
            if ($values === [null]) continue;
            if (count($values) !== 3) throw new RuntimeException("Invalid column count at row $line.");
            $r = array_combine($headers, array_map('trim', $values));
            $r['email'] = strtolower($r['email']);
            if (!filter_var($r['email'], FILTER_VALIDATE_EMAIL) || strlen($r['email']) > 255
                || $r['first_name'] === '' || $r['last_name'] === ''
                || !preg_match('//u', implode('', $r))
                || mb_strlen($r['first_name']) > 120 || mb_strlen($r['last_name']) > 120) throw new RuntimeException("Invalid email/name at row $line.");
            if (isset($seen[$r['email']])) throw new RuntimeException("Duplicate normalised email at row $line.");
            $seen[$r['email']] = true; $r['row'] = $line; $rows[] = $r;
        }
        if (!$rows) throw new RuntimeException('CSV is empty.');
        return $rows;
    } finally { fclose($f); }
}

function previewAccountCsv(PDO $pdo, array $rows): array
{
    $plan = [];
    foreach ($rows as $r) {
        $s = $pdo->prepare('SELECT * FROM users WHERE LOWER(TRIM(email))=?'); $s->execute([$r['email']]); $users = $s->fetchAll();
        if (count($users) > 1) throw new RuntimeException('Ambiguous account at row '.$r['row']);
        $u = $users[0] ?? null;
        if ($u && (!empty($u['is_archived']) || $u['first_name'] !== $r['first_name'] || $u['last_name'] !== $r['last_name'])) throw new RuntimeException('Existing account needs review at row '.$r['row']);
        $s = $pdo->prepare('SELECT * FROM people WHERE LOWER(TRIM(email))=?' . ($u ? ' OR owner_user_id='.(int)$u['id'] : ''));
        $s->execute([$r['email']]); $people = $s->fetchAll();
        $person = null;
        if ($people) {
            if (!$u || count($people) !== 1) throw new RuntimeException('Person ownership needs review at row '.$r['row']);
            $person = $people[0];
            if ((int)$person['owner_user_id'] !== (int)$u['id'] || strtolower(trim((string)$person['email'])) !== $r['email']
                || $person['first_name'] !== $r['first_name'] || $person['last_name'] !== $r['last_name'] || !empty($person['is_archived'])) throw new RuntimeException('Person match needs review at row '.$r['row']);
        }
        $plan[] = ['source'=>$r,'user'=>$u,'person'=>$person];
    }
    return $plan;
}

function runAccountCsv(array $argv): void
{
    $path = $argv[1] ?? '';
    $apply = ($argv[2] ?? '') === '--apply';
    if (!$path || (isset($argv[2]) && !$apply)) throw new RuntimeException('Usage: php scripts/import_user_people.php /private/file.csv [--apply]');
    $rows = readAccountCsv($path);
    $alerts = []; $pdo = createPdo(require dirname(__DIR__, 2).'/private/config.php', $alerts);
    if (!$pdo) throw new RuntimeException('Database unavailable.');
    $workspace = migrationAuthorizeImport($pdo);
    $plan = previewAccountCsv($pdo, $rows);
    foreach ($plan as $p) echo json_encode(['row'=>$p['source']['row'],'email'=>$p['source']['email'],'user'=>$p['user'] ? 'preserve' : 'create ordinary User','person'=>$p['person'] ? 'preserve' : 'create'], JSON_THROW_ON_ERROR)."\n";
    if (!$apply) return;
    if ((int)$pdo->query("SELECT GET_LOCK('ildra-import-reset',0)")->fetchColumn() !== 1) throw new RuntimeException('Another import/reset is running.');
    $backup = null; $file = null;
    try {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); $pdo->beginTransaction();
        $tables = $pdo->query('SHOW TABLE STATUS')->fetchAll();
        foreach ($tables as $t) {
            if ($t['Engine'] !== 'InnoDB') throw new RuntimeException('Backup requires InnoDB tables.');
            $name = str_replace('`','``',$t['Name']);
            $s = $pdo->query("SELECT * FROM `$name` FOR UPDATE"); while ($s->fetch()) {}
        }
        if (previewAccountCsv($pdo, $rows) !== $plan) throw new RuntimeException('Data changed after preview. Rerun.');
        $dir = dirname(__DIR__, 2).'/private/import-workspace-backups';
        $backup = $dir.'/accounts-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(8)).'.jsonl';
        $file = fopen($backup, 'x');
        if (!$file || !chmod($backup,0600)) throw new RuntimeException('Cannot create private backup.');
        $write = static function(array $r) use ($file): void {
            $line = json_encode($r,JSON_THROW_ON_ERROR)."\n";
            if (fwrite($file,$line) !== strlen($line)) throw new RuntimeException('Backup write failed.');
        };
        $write(['format'=>'ildra-account-import-v1','source_sha256'=>hash_file('sha256',$path),'created_at'=>date(DATE_ATOM)]);
        foreach ($tables as $t) {
            $name = str_replace('`','``',$t['Name']);
            $write(['table'=>$t['Name'],'schema'=>$pdo->query("SHOW CREATE TABLE `$name`")->fetch(PDO::FETCH_NUM)[1]]);
            foreach ($pdo->query("SELECT * FROM `$name`") as $r) $write(['table'=>$t['Name'],'row'=>$r]);
        }
        $write(['backup_complete'=>true]);
        if (!fflush($file) || !fsync($file)) throw new RuntimeException('Backup flush failed.');
        fclose($file); $file = null;
        $role = $pdo->query("SELECT id FROM roles WHERE name='user'")->fetchColumn();
        if (!$role) throw new RuntimeException('Ordinary User role missing.');
        $result = [];
        foreach ($plan as $p) {
            $r = $p['source']; $uid = (int)($p['user']['id'] ?? 0); $pid = (int)($p['person']['id'] ?? 0);
            if (!$uid) {
                $s = $pdo->prepare('INSERT INTO users (email,first_name,last_name,password_hash,role_id,general_email_opt_in,ride_notice_opt_in,renewal_reminder_opt_in) VALUES (?,?,?,?,?,0,0,0)');
                $s->execute([$r['email'],$r['first_name'],$r['last_name'],password_hash(bin2hex(random_bytes(48)),PASSWORD_DEFAULT),$role]); $uid = (int)$pdo->lastInsertId();
            }
            if (!$pid) {
                $s = $pdo->prepare('INSERT INTO people (owner_user_id,email,first_name,last_name,general_email_opt_in,ride_notice_opt_in,renewal_reminder_opt_in) VALUES (?,?,?,?,0,0,0)');
                $s->execute([$uid,$r['email'],$r['first_name'],$r['last_name']]); $pid = (int)$pdo->lastInsertId();
            }
            if ($p['user']) {
                $s = $pdo->prepare('SELECT * FROM users WHERE id=?'); $s->execute([$uid]);
                if ($s->fetch() !== $p['user']) throw new RuntimeException('Existing user changed; rolling back.');
            }
            $result[] = ['source'=>$r,'user_id'=>$uid,'person_id'=>$pid,'created_user'=>!$p['user'],'created_person'=>!$p['person']];
        }
        // A private receipt anchors source rows to immutable target IDs for later reconciliation.
        $receipt = $backup.'.receipt.json';
        $f = fopen($receipt,'x');
        if (!$f || !chmod($receipt,0600)) throw new RuntimeException('Cannot create private receipt.');
        $json = json_encode(['source_sha256'=>hash_file('sha256',$path),'backup'=>basename($backup),'records'=>$result],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
        try { if (fwrite($f,$json) !== strlen($json) || !fflush($f) || !fsync($f)) throw new RuntimeException('Receipt write failed.'); } finally { fclose($f); }
        $pdo->commit();
        echo 'Committed: '.count(array_filter($result,static fn($r)=>$r['created_user'])).' new users, '.count(array_filter($result,static fn($r)=>$r['created_person']))." new people. Backup: $backup\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (is_resource($file)) fclose($file);
        if (isset($receipt) && is_file($receipt)) unlink($receipt);
        throw $e;
    } finally { $pdo->query("SELECT RELEASE_LOCK('ildra-import-reset')"); }
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try { runAccountCsv($argv); } catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
}

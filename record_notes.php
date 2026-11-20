<?php
declare(strict_types=1);

function ensureRecordNotesTables(PDO $pdo): void
{
    if ($pdo->inTransaction()) throw new RuntimeException('Prepare notes schema outside a transaction.');
    $sql=file_get_contents(__DIR__.'/migrations/2026-11-17-internal-record-notes.sql');
    foreach(explode(';',$sql) as $statement) if(trim($statement)!=='') $pdo->exec($statement);
    $pdo->exec("INSERT IGNORE INTO migration_page_copy (id,introduction,closure_explanation) VALUES (1,'','')");
}

function recordNotesCanRead(string $role): bool
{
    return in_array(strtolower($role),['developer','superadmin','admin'],true);
}

/** Append inside the caller's archive transaction; there is no public edit/delete endpoint. */
function appendRecordNote(PDO $pdo, string $type, int $id, string $email, string $action, string $note, int $actor, string $eventKey, array $metadata=[]): void
{
    if (!$pdo->inTransaction() || !in_array($type,['user','person','horse'],true) || $id<=0 || $actor<=0 || trim($note)==='' || !preg_match('/\A[a-f0-9]{32}\z/D',$eventKey)) throw new RuntimeException('Invalid internal note context.');
    $s=$pdo->prepare('INSERT INTO record_notes (created_at,record_type,record_id,email,action,note,author_kind,actor_user_id,event_key,metadata_json) VALUES (NOW(),?,?,?,?,?,?,?,?,?)');
    $s->execute([$type,$id,$email,$action,$note,'user',$actor,$eventKey,json_encode($metadata,JSON_THROW_ON_ERROR)]);
}

function recordNotesFind(PDO $pdo, array $filters, int $offset=0): array
{
    $where=[];$params=[];
    if (($filters['email']??'')!=='') { $where[]='email LIKE ?';$params[]='%'.$filters['email'].'%'; }
    foreach(['record_type','action'] as $field) if(($filters[$field]??'')!=='') { $where[]=$field.'=?';$params[]=$filters[$field]; }
    if ((int)($filters['record_id']??0)>0) { $where[]='record_id=?';$params[]=(int)$filters['record_id']; }
    foreach(['from'=>'>=','to'=>'<='] as $field=>$op) if (($filters[$field]??'')!=='') {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$filters[$field]);
        if (!$date || $date->format('Y-m-d')!==$filters[$field]) throw new RuntimeException('Enter valid filter dates.');
        $where[]='created_at '.$op.' ?';$params[]=$filters[$field].($field==='from'?' 00:00:00':' 23:59:59');
    }
    $s=$pdo->prepare('SELECT * FROM record_notes'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY id DESC LIMIT 101 OFFSET '.max(0,$offset));
    $s->execute($params);return $s->fetchAll();
}

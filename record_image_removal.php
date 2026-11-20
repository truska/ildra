<?php
declare(strict_types=1);

/** Remove only the image owned/selected by this content record, retaining it. */
function removeContentRecordImage(PDO $pdo, string $kind, int $id, string $scope = ''): bool
{
    if ($id <= 0) return false;
    $configuration = match ($kind) {
        'award' => ['table'=>'award_catalog', 'file'=>'legacy_image_filename', 'asset'=>'image_asset_id', 'section'=>'awards', 'sizes'=>'sm'],
        'advertising' => ['table'=>'advertising', 'file'=>'image', 'asset'=>null, 'section'=>'advertising', 'sizes'=>'original,md,sm'],
        'task_screenshot' => ['table'=>'dev_task_messages', 'file'=>'image_filename', 'asset'=>null, 'section'=>'dev-tasks', 'sizes'=>'original,lg,sm'],
        'announcement' => ['table'=>'announcements', 'file'=>null, 'asset'=>'asset_id', 'section'=>null, 'sizes'=>''],
        default => throw new InvalidArgumentException('Unknown image owner.'),
    };
    if ($kind === 'announcement' && !in_array($scope, ['manual', 'super'], true)) return false;
    $table=$configuration['table'];$fileColumn=$configuration['file'];$assetColumn=$configuration['asset'];
    if ($kind === 'task_screenshot' && (int)$scope <= 0) return false;
    $where='id=?'.($kind==='announcement'?' AND announcement_type=? AND is_archived=0':($kind==='task_screenshot'?' AND task_id=?':''));
    $parameters=in_array($kind,['announcement','task_screenshot'],true)?[$id,$scope]:[$id];
    if ($pdo->inTransaction()) throw new RuntimeException('Image removal requires its own transaction.');
    $filename='';$removeFiles=false;
    $pdo->beginTransaction();
    try {
        $statement=$pdo->prepare("SELECT * FROM `$table` WHERE $where FOR UPDATE");$statement->execute($parameters);$record=$statement->fetch();
        if (!$record || (empty($record[$fileColumn??'']) && empty($record[$assetColumn??'']))) {$pdo->rollBack();return false;}
        $updates=[];
        if ($fileColumn) $updates[]="`$fileColumn`=NULL";
        if ($assetColumn) $updates[]="`$assetColumn`=".($kind==='announcement'?'0':'NULL');
        if ($kind==='announcement') $updates[]='revision='.$pdo->quote(bin2hex(random_bytes(16)));
        $statement=$pdo->prepare("UPDATE `$table` SET ".implode(',',$updates)." WHERE $where");$statement->execute($parameters);
        if ($fileColumn) {
            $filename=(string)($record[$fileColumn]??'');
            if ($filename!=='' && $filename===basename($filename)) {
                $statement=$pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$fileColumn`=?");$statement->execute([$filename]);
                $removeFiles=(int)$statement->fetchColumn()===0;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    // Library assets are references, not record-owned files: never delete them here.
    if ($removeFiles) mediaBatchDeleteFiles(['section'=>$configuration['section']], ['filename'=>$filename,'available_sizes'=>$configuration['sizes']]);
    return true;
}

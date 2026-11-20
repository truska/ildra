<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/db.php';
require dirname(__DIR__,2).'/helpers.php';
require dirname(__DIR__,2).'/image_upload.php';
require dirname(__DIR__,2).'/admin/media_image_actions.php';
ini_set('session.save_path','/tmp');session_start();
function recordImageCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$a=[];$pdo=createPdo(require dirname(__DIR__,2).'/config.php',$a);if(!$pdo)throw new RuntimeException('Database unavailable');
foreach(['award_catalog','advertising','announcements','asset_library','dev_task_messages'] as $table){
    $pdo->exec("CREATE TEMPORARY TABLE record_image_seed LIKE `$table`");
    $pdo->exec("CREATE TEMPORARY TABLE `$table` LIKE record_image_seed");$pdo->exec('DROP TEMPORARY TABLE record_image_seed');
    recordImageCheck(str_contains($pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1],'CREATE TEMPORARY TABLE'),'Non-temporary table');
}
$root=sys_get_temp_dir().'/record-images-'.bin2hex(random_bytes(8));
$source=file_get_contents(dirname(__DIR__,2).'/media_batches.php');$a=strpos($source,'function mediaBatchDeleteFiles(');$b=strpos($source,'function mediaBatchDeleteImage(',$a);
$delete=str_replace('__DIR__',var_export($root,true),substr($source,$a,$b-$a));
$source=file_get_contents(dirname(__DIR__,2).'/record_image_removal.php');$a=strpos($source,'function removeContentRecordImage(');
eval('namespace RecordImageFixture; use \\PDO; use \\Throwable; use \\RuntimeException; use \\InvalidArgumentException; '.$delete.substr($source,$a));
function recordImageFiles(string $root,string $section,string $filename,array $sizes):void{foreach($sizes as $size){$dir="$root/filestore/images/$section/$size";if(!is_dir($dir))mkdir($dir,0700,true);file_put_contents($dir.'/'.$filename,'synthetic');}}
try{
    $pdo->exec("INSERT INTO asset_library(id,name,asset_type,filename) VALUES (37,'Shared fixture','image','shared.jpg')");
    recordImageFiles($root,'library','shared.jpg',['original','sm']);
    $pdo->exec("INSERT INTO award_catalog(id,name,image_asset_id,legacy_image_filename) VALUES (1,'Award fixture',37,'award.jpg'),(2,'Shared award fixture',NULL,'award.jpg'),(3,'Library only',37,NULL)");
    recordImageFiles($root,'awards','award.jpg',['sm']);
    recordImageCheck(\RecordImageFixture\removeContentRecordImage($pdo,'award',1),'Award removal failed');
    $row=$pdo->query('SELECT * FROM award_catalog WHERE id=1')->fetch();recordImageCheck(empty($row['image_asset_id'])&&empty($row['legacy_image_filename'])&&$row['name']==='Award fixture','Award content not preserved');
    recordImageCheck(is_file($root.'/filestore/images/awards/sm/award.jpg'),'Shared uploaded file deleted prematurely');
    recordImageCheck(\RecordImageFixture\removeContentRecordImage($pdo,'award',2),'Last award removal failed');
    recordImageCheck(!is_file($root.'/filestore/images/awards/sm/award.jpg'),'Unused award upload retained');
    recordImageCheck(\RecordImageFixture\removeContentRecordImage($pdo,'award',3),'Library-only award removal failed');
    recordImageCheck((int)$pdo->query('SELECT COUNT(*) FROM asset_library')->fetchColumn()===1&&is_file($root.'/filestore/images/library/original/shared.jpg'),'Shared library asset deleted');
    recordImageCheck(!\RecordImageFixture\removeContentRecordImage($pdo,'award',3),'Repeat removal succeeded');
    $pdo->exec("INSERT INTO advertising(id,name,image,url) VALUES (1,'Ad fixture','ad.jpg','https://example.invalid')");recordImageFiles($root,'advertising','ad.jpg',['original','md','sm']);
    recordImageCheck(\RecordImageFixture\removeContentRecordImage($pdo,'advertising',1),'Advertising removal failed');
    foreach(['original','md','sm'] as $size)recordImageCheck(!is_file($root.'/filestore/images/advertising/'.$size.'/ad.jpg'),'Advertising rendition retained');
    recordImageCheck($pdo->query('SELECT url FROM advertising WHERE id=1')->fetchColumn()==='https://example.invalid','Ad record changed');
    foreach(['manual','super'] as $i=>$type){$id=$i+1;$pdo->prepare('INSERT INTO announcements(id,announcement_type,title,body_html,asset_id,revision) VALUES (?,?,?,?,37,?)')->execute([$id,$type,'Announcement fixture','Keep this text','old-revision']);
        recordImageCheck(!\RecordImageFixture\removeContentRecordImage($pdo,'announcement',$id,$type==='manual'?'super':'manual'),'Wrong announcement tab could modify image');
        recordImageCheck(\RecordImageFixture\removeContentRecordImage($pdo,'announcement',$id,$type),'Announcement removal failed');
        $row=$pdo->query('SELECT * FROM announcements WHERE id='.$id)->fetch();recordImageCheck((int)$row['asset_id']===0&&$row['revision']!=='old-revision'&&$row['body_html']==='Keep this text','Announcement state incorrect');
    }
    $pdo->exec("INSERT INTO dev_task_messages(id,task_id,user_id,author_name,message,image_filename) VALUES (1,7,1,'Fixture','Keep this message','screenshot.jpg')");recordImageFiles($root,'dev-tasks','screenshot.jpg',['original','lg','sm']);
    recordImageCheck(!\RecordImageFixture\removeContentRecordImage($pdo,'task_screenshot',1,'8'),'Wrong task could remove screenshot');
    recordImageCheck(\RecordImageFixture\removeContentRecordImage($pdo,'task_screenshot',1,'7'),'Screenshot removal failed');
    recordImageCheck($pdo->query('SELECT message FROM dev_task_messages WHERE id=1')->fetchColumn()==='Keep this message','Message was deleted');
    recordImageCheck(!is_file($root.'/filestore/images/dev-tasks/original/screenshot.jpg'),'Screenshot original retained');
    $_SERVER['REQUEST_METHOD']='POST';$_POST=['action'=>'remove_record_image','csrf'=>'wrong'];$alerts=[];
    recordImageCheck(admin_handle_record_image_delete($pdo,'award',1,'fixture.php',$alerts)&&http_response_code()===403&&count($alerts)===1,'Record image handler accepted invalid CSRF');
    $_POST=[];
    if (!empty($argv[1])) {
        $directory=$argv[1];if(!is_dir($directory)||!str_starts_with(realpath($directory),'/tmp/'))throw new RuntimeException('Pass a private /tmp fixture directory.');
        foreach(['award','advertising','announcement','task_screenshot'] as $kind){
            $context=$kind==='announcement'?['id'=>1,'settings_section'=>'manual']:($kind==='task_screenshot'?['task_id'=>7,'message_id'=>1]:[]);
            $html='<form id="parent"><input name="title" required value="">'.admin_record_image_delete_button($kind,1).'</form>'.admin_record_image_delete_form($kind,1,$context,$kind==='announcement');
            file_put_contents($directory.'/'.$kind.'.html',$html);
        }
    }
    echo "PASS: Award/Advertising uploads and sizes, Manual/Super announcement image detachment, Library/shared-file retention, screenshot/task scope and record preservation. Temporary tables and /tmp files only.\n";
}finally{if(is_dir($root)){$files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($root);}}

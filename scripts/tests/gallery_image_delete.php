<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__,2).'/db.php';
require dirname(__DIR__,2).'/helpers.php';
require dirname(__DIR__,2).'/image_upload.php';
require dirname(__DIR__,2).'/media_batches.php';
require dirname(__DIR__,2).'/admin/media_image_actions.php';
ini_set('session.save_path','/tmp');session_start();
function galleryCheck(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
$a=[];$pdo=createPdo(require dirname(__DIR__,2).'/config.php',$a);if(!$pdo)throw new RuntimeException('Database unavailable.');
foreach(['media_batches','media_batch_images'] as $table){
    $pdo->exec("CREATE TEMPORARY TABLE delete_fixture_seed LIKE `$table`");
    $pdo->exec("CREATE TEMPORARY TABLE `$table` LIKE delete_fixture_seed");
    $pdo->exec('DROP TEMPORARY TABLE delete_fixture_seed');
    galleryCheck(str_contains($pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1],'CREATE TEMPORARY TABLE'),'Unsafe fixture table');
}
$root=sys_get_temp_dir().'/gallery-delete-'.bin2hex(random_bytes(8));
$source=file_get_contents(dirname(__DIR__,2).'/media_batches.php');
$start=strpos($source,'function mediaBatchDeleteFiles(');$end=strpos($source,'function mediaBatchReplaceImage(',$start);
$functions=str_replace('__DIR__',var_export($root,true),substr($source,$start,$end-$start));
// Execute the real deletion functions with only the storage root replaced, so
// unlink can touch synthetic /tmp files exclusively, never public uploads.
eval('namespace GalleryDeleteFixture; use \\PDO; '.$functions);
try {
    foreach(['events','venues','ride-notes','news','page-elements','banners'] as $index=>$section){
        $batchId=$index+1;
        $pdo->prepare('INSERT INTO media_batches(id,purpose,owner_type,owner_id,name,section) VALUES (?,?,?,?,?,?)')->execute([$batchId,'fixture-'.$index,'fixture',$batchId,'Fixture',$section]);
        $batch=['id'=>$batchId,'section'=>$section];$filename='fixture-'.$index.'.jpg';
        $pdo->prepare('INSERT INTO media_batch_images(batch_id,filename,available_sizes,archived) VALUES (?,?,?,?)')->execute([$batchId,$filename,'original,lg,sm,xs',$index%2]);$imageId=(int)$pdo->lastInsertId();
        foreach(['original','lg','sm','xs'] as $size){$dir=$root.'/filestore/images/'.$section.'/'.$size;mkdir($dir,0700,true);file_put_contents($dir.'/'.$filename,'synthetic fixture');file_put_contents($dir.'/untouched.jpg','synthetic neighbour');}
        galleryCheck(!\GalleryDeleteFixture\mediaBatchDeleteImage($pdo,['id'=>$batchId+100,'section'=>$section],$imageId),'Another gallery could delete the image');
        galleryCheck(is_file($root.'/filestore/images/'.$section.'/original/'.$filename),'Cross-gallery request removed a file');
        galleryCheck(\GalleryDeleteFixture\mediaBatchDeleteImage($pdo,$batch,$imageId),'Valid delete failed');
        foreach(['original','lg','sm','xs'] as $size){galleryCheck(!file_exists($root.'/filestore/images/'.$section.'/'.$size.'/'.$filename),'Generated size retained');galleryCheck(is_file($root.'/filestore/images/'.$section.'/'.$size.'/untouched.jpg'),'Neighbour file deleted');}
        galleryCheck(!\GalleryDeleteFixture\mediaBatchDeleteImage($pdo,$batch,$imageId),'Repeated delete succeeded');
    }
    $_SERVER['REQUEST_METHOD']='POST';$_POST=['action'=>'delete_image','image_id'=>99,'csrf'=>'wrong'];$alerts=[];
    galleryCheck(admin_handle_image_delete($pdo,['id'=>1],'fixture.php',$alerts) && count($alerts)===1 && http_response_code()===403,'Missing CSRF did not fail closed');
    $_POST=['action'=>'save'];$alerts=[];galleryCheck(!admin_handle_image_delete($pdo,['id'=>1],'fixture.php',$alerts),'Gallery save intercepted as delete');
    $batch=['id'=>1];$image=['id'=>99];
    $html=admin_image_delete_button($batch,$image).admin_image_delete_forms($batch,[$image],['event_id'=>7]);
    $document=new DOMDocument();@$document->loadHTML($html);$xpath=new DOMXPath($document);
    galleryCheck($xpath->query('//button[@form="delete-gallery-image-1-99"]')->length===1,'Delete button has wrong form');
    galleryCheck($xpath->query('//form[@data-confirm-button="Delete permanently"]//input[@name="csrf"]')->length===1,'No confirmed CSRF-protected delete form');
    echo "PASS: gallery ownership, active/archived image removal, all stored sizes, neighbour preservation, repeated deletes, CSRF and independent confirmation forms. Temporary tables and /tmp files only.\n";
} finally {
    if(is_dir($root)){
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($root);
    }
}

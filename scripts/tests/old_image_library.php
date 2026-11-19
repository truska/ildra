<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/db.php';
require dirname(__DIR__,2).'/cms.php';
require dirname(__DIR__,2).'/old_image_library.php';
require dirname(__DIR__,2).'/media_batches.php';
$alerts=[];$pdo=createPdo(require dirname(__DIR__,3).'/private/config.php',$alerts);
if(!$pdo)throw new RuntimeException('Database unavailable');
$root=sys_get_temp_dir().'/old-library-test-'.bin2hex(random_bytes(5));
define('OLD_LIBRARY_TEST_ROOT',$root);define('OLD_LIBRARY_TEST_THUMBS',$root.'/thumb');
$section='library-test-'.bin2hex(random_bytes(5));$destination=dirname(__DIR__,2).'/filestore/images/'.$section;
function checkLibrary(bool $ok,string $label): void { if(!$ok)throw new RuntimeException($label);echo "PASS $label\n"; }
function removeLibraryFixture(string $path): void { if(is_dir($path)){foreach(new FilesystemIterator($path) as $f)removeLibraryFixture($f->getPathname());rmdir($path);}elseif(is_file($path))unlink($path); }
try {
    foreach(['incoming','originals','thumb'] as $dir)mkdir($root.'/'.$dir,0770,true);
    foreach(['original','sm'] as $dir)mkdir($destination.'/'.$dir,0775,true);
    $pdo->exec('CREATE TEMPORARY TABLE old_image_library (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,source_hash CHAR(64) UNIQUE,original_filename VARCHAR(255),filename VARCHAR(100),used_count INT DEFAULT 0,deleted TINYINT DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TEMPORARY TABLE media_batch_images (id INT AUTO_INCREMENT PRIMARY KEY,batch_id INT,filename VARCHAR(255),original_filename VARCHAR(255),title VARCHAR(255),alt_text VARCHAR(255),available_sizes VARCHAR(100),display_order INT)');
    $pdo->exec('CREATE TEMPORARY TABLE site_settings (setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT)');
    $image=imagecreatetruecolor(1200,1800);imagejpeg($image,$root.'/incoming/Test Ride portrait.jpg');imagedestroy($image);touch($root.'/incoming/Test Ride portrait.jpg',time()-60);
    file_put_contents($root.'/incoming/Invalid.jpg','not an image');touch($root.'/incoming/Invalid.jpg',time()-60);
    oldLibraryProcess($pdo);$row=$pdo->query('SELECT * FROM old_image_library')->fetch();
    checkLibrary((bool)$row && $row['original_filename']==='Test Ride portrait.jpg','process original filename');
    $info=getimagesize($root.'/thumb/'.$row['filename']);checkLibrary(max($info[0],$info[1])<=240,'bounded portrait thumbnail');
    oldLibraryProcess($pdo);checkLibrary((int)$pdo->query('SELECT COUNT(*) FROM old_image_library')->fetchColumn()===1,'repeat processing skips duplicates and rejects invalid image');
    $image=imagecreatetruecolor(600,300);imagepng($image,$root.'/incoming/Second Ride.png');imagedestroy($image);touch($root.'/incoming/Second Ride.png',time()-60);
    oldLibraryProcess($pdo);checkLibrary((int)$pdo->query('SELECT COUNT(*) FROM old_image_library')->fetchColumn()===2,'later batch added');
    oldLibraryAttach($pdo,(int)$row['id'],['id'=>123,'section'=>$section],['original'=>null,'sm'=>300]);
    $attached=$pdo->query('SELECT * FROM media_batch_images WHERE batch_id=123 ORDER BY id DESC LIMIT 1')->fetch();
    checkLibrary(hash_file('sha256',$destination.'/original/'.$attached['filename'])===$row['source_hash'],'attached original copied byte for byte');
    unlink($root.'/originals/'.$row['filename']);$pdo->exec('UPDATE old_image_library SET deleted=1 WHERE id='.(int)$row['id']);
    checkLibrary(is_file($destination.'/original/'.$attached['filename']),'attached copy survives library deletion');
    oldLibraryProcess($pdo);checkLibrary((int)$pdo->query('SELECT COUNT(*) FROM old_image_library')->fetchColumn()===2,'deleted fingerprint prevents reimport');
    $pdo->exec("INSERT INTO site_settings(setting_key,setting_value) VALUES('old_image_library_enabled','0')");
    try {oldLibraryAttach($pdo,2,['id'=>123,'section'=>$section],['original'=>null]);throw new RuntimeException('disabled library accepted');}catch(RuntimeException $e){checkLibrary($e->getMessage()==='The old image library is switched off.','disabled library blocks attachment');}
} finally {removeLibraryFixture($root);removeLibraryFixture($destination);}

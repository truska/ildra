<?php
declare(strict_types=1);
require_once __DIR__.'/image_upload.php';

function oldLibraryRoot(): string { return PHP_SAPI==='cli' && defined('OLD_LIBRARY_TEST_ROOT') ? OLD_LIBRARY_TEST_ROOT : dirname(__DIR__).'/private/old-image-library'; }
function oldLibraryThumbRoot(): string { return PHP_SAPI==='cli' && defined('OLD_LIBRARY_TEST_THUMBS') ? OLD_LIBRARY_TEST_THUMBS : __DIR__.'/filestore/images/old-library/thumb'; }
function oldLibraryEnabled(PDO $pdo): bool { return (string)(getSiteSettings($pdo)['old_image_library_enabled']??'1')==='1'; }
function oldLibraryEnsure(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS old_image_library (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        source_hash CHAR(64) NOT NULL UNIQUE,
        original_filename VARCHAR(255) NOT NULL,
        filename VARCHAR(100) NOT NULL,
        used_count INT UNSIGNED NOT NULL DEFAULT 0,
        deleted TINYINT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}
/** Decode trusted server files through the same limits as uploads, without accepting arbitrary paths. */
function oldLibraryDecode(string $path, ?string &$error): ?array
{
    if (!extension_loaded('gd')) { $error='GD is required.'; return null; }
    if (!is_file($path) || is_link($path) || filesize($path)>50*1024*1024) { $error='Unreadable file or file larger than 50 MB.'; return null; }
    $info=@getimagesize($path);
    if (!$info || $info[0]*$info[1]>40000000) { $error='Invalid image or more than 40 million pixels.'; return null; }
    $extensions=[IMAGETYPE_JPEG=>'jpg',IMAGETYPE_PNG=>'png',IMAGETYPE_GIF=>'gif',IMAGETYPE_WEBP=>'webp'];
    $ext=$extensions[$info[2]]??'';
    if (!$ext) { $error='Only JPG, PNG, GIF and WebP images are supported.'; return null; }
    $image=match($ext) {'jpg'=>@imagecreatefromjpeg($path),'png'=>@imagecreatefrompng($path),'gif'=>@imagecreatefromgif($path),'webp'=>@imagecreatefromwebp($path)};
    if (!$image) { $error='Unable to decode image.'; return null; }
    return ['image'=>$image,'width'=>$info[0],'height'=>$info[1],'extension'=>$ext];
}
function oldLibraryProcess(PDO $pdo): array
{
    oldLibraryEnsure($pdo);
    $lock=fopen(oldLibraryRoot().'/process.lock','c');
    if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Processing is already running. Try again shortly.');
    $messages=[];$count=0;
    try {
        $find=$pdo->prepare('SELECT id FROM old_image_library WHERE source_hash=?');
        foreach (new DirectoryIterator(oldLibraryRoot().'/incoming') as $file) {
            if ($file->isDot() || !$file->isFile() || $file->isLink() || str_starts_with($file->getFilename(),'.')) continue;
            if (!preg_match('/\.(jpe?g|png|gif|webp)$/i',$file->getFilename())) continue;
            // Ignore files still being transferred; FTP uploads should use temporary names until complete.
            if (time()-$file->getMTime()<30) continue;
            $hash=hash_file('sha256',$file->getPathname());$find->execute([$hash]);if ($find->fetchColumn()) continue;
            if ($count>=12) { $messages[]='More files remain. Press Process new images again.'; break; }
            $error=null;$source=oldLibraryDecode($file->getPathname(),$error);
            if (!$source) { if(count($messages)<25)$messages[]=$file->getFilename().': '.$error; continue; }
            ++$count;
            $name=$hash.'.'.$source['extension'];
            $original=oldLibraryRoot().'/originals/'.$name;
            $thumb=oldLibraryThumbRoot().'/'.$name;
            $width=max(1,(int)floor(240*min(1,$source['width']/$source['height'])));
            $canvas=image_upload_resize($source['image'],$source['width'],$source['height'],$width);
            try {
                if (!copy($file->getPathname(),$original) || hash_file('sha256',$original)!==$hash || !image_upload_save($canvas,$thumb,$source['extension'])) throw new RuntimeException('Unable to save original or thumbnail.');
                chmod($original,0660);chmod($thumb,0664);
                $pdo->prepare('INSERT INTO old_image_library(source_hash,original_filename,filename) VALUES(?,?,?)')->execute([$hash,mb_substr($file->getFilename(),0,255),$name]);
                $messages[]='Added: '.$file->getFilename();
            } catch (Throwable $e) { @unlink($original);@unlink($thumb);$messages[]=$file->getFilename().': '.$e->getMessage(); }
            finally { if($canvas!==$source['image']) imagedestroy($canvas);imagedestroy($source['image']); }
        }
    } finally { flock($lock,LOCK_UN);fclose($lock); }
    return $messages?:['No new completed image files found.'];
}
function oldLibraryAttach(PDO $pdo,int $id,array $batch,array $sizes): void
{
    if (!oldLibraryEnabled($pdo)) throw new RuntimeException('The old image library is switched off.');
    $stmt=$pdo->prepare('SELECT * FROM old_image_library WHERE id=? AND deleted=0');$stmt->execute([$id]);$row=$stmt->fetch();
    if (!$row) throw new RuntimeException('Image no longer available.');
    $error=null;$source=oldLibraryDecode(oldLibraryRoot().'/originals/'.basename($row['filename']),$error);
    if (!$source) throw new RuntimeException($error?:'Original unavailable.');
    $filename=image_upload_slug(pathinfo($row['original_filename'],PATHINFO_FILENAME)).'-'.bin2hex(random_bytes(6)).'.'.$source['extension'];
    $paths=[];
    try {
        foreach($sizes as $size=>$width) {
            $path=__DIR__.'/filestore/images/'.image_upload_section($batch['section']).'/'.$size.'/'.$filename;
            $paths[]=$path;
            if($size==='original') $saved=copy(oldLibraryRoot().'/originals/'.basename($row['filename']),$path);
            else { $canvas=image_upload_resize($source['image'],$source['width'],$source['height'],$width);$saved=image_upload_save($canvas,$path,$source['extension']);if($canvas!==$source['image'])imagedestroy($canvas); }
            if(!$saved)throw new RuntimeException('Could not save image to its destination.');chmod($path,0664);
        }
        $pdo->beginTransaction();
        $order=$pdo->prepare('SELECT COALESCE(MAX(display_order),0)+10 FROM media_batch_images WHERE batch_id=?');$order->execute([$batch['id']]);
        $title=trim(str_replace(['_','-'],' ',pathinfo($row['original_filename'],PATHINFO_FILENAME)));
        $pdo->prepare('INSERT INTO media_batch_images(batch_id,filename,original_filename,title,alt_text,available_sizes,display_order) VALUES(?,?,?,?,?,?,?)')->execute([$batch['id'],$filename,$row['original_filename'],$title,$title,implode(',',array_keys($sizes)),$order->fetchColumn()]);
        $pdo->prepare('UPDATE old_image_library SET used_count=used_count+1 WHERE id=?')->execute([$id]);$pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack();foreach($paths as $path)@unlink($path);throw $e; }
    finally { imagedestroy($source['image']); }
}

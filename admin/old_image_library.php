<?php
declare(strict_types=1);
require __DIR__.'/_bootstrap.php';
require_once dirname(__DIR__).'/old_image_library.php';
if (!in_array(strtolower((string)$currentUser['role']),['developer','superadmin','admin','manager'],true)) { http_response_code(403);exit; }
oldLibraryEnsure($pdo);
$target=(string)($_GET['target']??'');
$eventId=max(0,(int)($_GET['event_id']??0));
$venueId=max(0,(int)($_GET['venue_id']??0));
$picker=in_array($target,['event','ride_notes','venue'],true);
$ownerId=$target==='venue'?$venueId:$eventId;
$owner=$picker?($target==='venue'?fetchVenueById($pdo,$ownerId):fetchEventById($pdo,$ownerId)):null;
if (($target!==''&&!$picker) || ($picker&&(!$owner||!oldLibraryEnabled($pdo)))) { http_response_code(404);exit; }
$ownerName=(string)($owner[$target==='venue'?'name':'title']??'');
$confirmError='';
$return=match($target) {
    'venue'=>'venue_images.php?venue_id='.$venueId,
    'event'=>'event_edit.php?id='.$eventId,
    'ride_notes'=>'ride_notes.php?event_id='.$eventId,
    default=>'index.php',
};
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        $action=(string)($_POST['action']??'');
        if ($picker && $action==='attach') {
            $purpose=match($target){'venue'=>'venue_images','event'=>'event_images',default=>'ride_notes_images'};
            $section=match($target){'venue'=>'venues','event'=>'events',default=>'ride-notes'};
            $batch=mediaBatchGetOrCreate($pdo,$purpose,$target==='venue'?'venue':'event',$ownerId,'Images: '.$ownerName,$section);
            oldLibraryAttach($pdo,(int)($_POST['image_id']??0),$batch,$target==='event'?['original'=>null,'lg'=>1200,'sm'=>300,'xs'=>150]:['original'=>null,'lg'=>1400,'md'=>800,'sm'=>400,'xs'=>180]);
            $_SESSION['flash_success']='Library image copied into '.match($target){'venue'=>'the venue.','event'=>'the event.',default=>'Rider Notes.'};header('Location: '.$return);exit;
        } elseif (!$picker && $action==='process') {
            foreach(oldLibraryProcess($pdo) as $message)$alerts[]=['type'=>'info','message'=>$message];
        } elseif (!$picker && $action==='setting') {
            if(saveSiteSettings($pdo,['old_image_library_enabled'=>empty($_POST['enabled'])?'0':'1'],$alerts))
                $alerts[]=['type'=>'success','message'=>'Library availability updated.'];
        } elseif (!$picker && $action==='delete') {
            if (($_POST['confirm']??'')!=='DELETE') { $confirmError='Type DELETE to remove the selected library copies.';throw new RuntimeException($confirmError); }
            $ids=array_slice(array_filter(array_map('intval',(array)($_POST['selected']??[]))),0,24);
            foreach($ids as $id) {
                $s=$pdo->prepare('SELECT filename FROM old_image_library WHERE id=? AND deleted=0');$s->execute([$id]);$name=$s->fetchColumn();if(!$name)continue;
                // Keep the fingerprint so a later scan cannot reintroduce a removed image.
                $pdo->prepare('UPDATE old_image_library SET deleted=1 WHERE id=?')->execute([$id]);
                foreach([oldLibraryRoot().'/originals/',dirname(__DIR__).'/filestore/images/old-library/thumb/'] as $folder) {
                    $path=$folder.basename($name);if(is_file($path)&&!unlink($path))throw new RuntimeException('A library file could not be removed.');
                }
            }
            $alerts[]=['type'=>'success','message'=>'Selected library copies removed. Attached images are retained.'];
        } else throw new RuntimeException('Unknown library action.');
    } catch(Throwable $e) { $alerts[]=['type'=>'danger','message'=>$e->getMessage()]; }
}
$q=mb_substr(trim((string)($_GET['q']??'')),0,150);$page=max(1,(int)($_GET['page']??1));
$where='deleted=0';$args=[];
if($q!=='') { $where.=" AND original_filename LIKE ? ESCAPE '!'";$args[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$q).'%'; }
$s=$pdo->prepare('SELECT COUNT(*) FROM old_image_library WHERE '.$where);$s->execute($args);$total=(int)$s->fetchColumn();$page=min($page,max(1,(int)ceil($total/24)));
$s=$pdo->prepare('SELECT * FROM old_image_library WHERE '.$where.' ORDER BY id DESC LIMIT 24 OFFSET '.(($page-1)*24));$s->execute($args);$images=$s->fetchAll();
$urlArgs=['target'=>$target,'event_id'=>$eventId,'venue_id'=>$venueId,'q'=>$q];
admin_layout_start($picker?'Choose from Old Library':'Manage Old Library','old_image_library');
echo render_back_navigation($return,$picker?'Back without selecting':'Back to Admin','Library navigation');
?>
<div class="card-soft p-4 mb-3"><h1 class="h4"><?php echo $picker?'Choose from Old Library':'Manage Old Library'; ?></h1>
<?php if($picker): ?><p>Choose an image for <?php echo h($ownerName); ?>. A separate copy will be added.</p>
<?php else: ?>
<p>Add JPG, PNG, GIF or WebP files through VS Code or FTP to <code><?php echo h(oldLibraryRoot().'/incoming/'); ?></code>, then process new images. You can do this whenever you add more files.</p>
<p class="small text-muted">Processes up to 12 new files per click; repeat until finished. Identical file contents are skipped. Files changed within the last 30 seconds are left for the next run. Finish FTP transfers before processing.</p>
<form method="post" class="mb-3"><?php echo csrf_input(); ?><button class="btn btn-success" name="action" value="process">Process new images</button></form>
<form method="post"><?php echo csrf_input(); ?><input type="hidden" name="action" value="setting"><label class="form-check"><input class="form-check-input" type="checkbox" name="enabled" value="1" <?php echo oldLibraryEnabled($pdo)?'checked':''; ?>> Offer Old Library in Events, Rider Notes and Venues</label><button class="btn btn-outline-primary mt-2">Save availability</button></form>
<p class="small text-muted mt-2 mb-0">Switching this off prevents library selection. Previously attached images remain available.</p>
<?php endif; ?></div>
<div class="card-soft p-4">
<form method="get" class="d-flex gap-2 mb-3"><input type="hidden" name="target" value="<?php echo h($target); ?>"><input type="hidden" name="event_id" value="<?php echo $eventId; ?>"><input type="hidden" name="venue_id" value="<?php echo $venueId; ?>"><label class="flex-grow-1">Search original filename<input class="form-control" name="q" value="<?php echo h($q); ?>" placeholder="For example, a ride name"></label><button class="btn btn-primary align-self-end">Search</button></form>
<p><?php echo $total; ?> image<?php echo $total===1?'':'s'; ?> found.</p>
<?php if(!$picker): ?><form method="post"><?php echo csrf_input(); ?><input type="hidden" name="action" value="delete"><label class="d-block mb-3 p-2"><input type="checkbox" onchange="this.form.querySelectorAll('input[name=&quot;selected[]&quot;]').forEach(input=>input.checked=this.checked)"> Select all images on this page for removal</label><?php endif; ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1rem">
<?php foreach($images as $image): ?>
<div class="border rounded p-2"><img loading="lazy" decoding="async" width="240" height="180" style="width:100%;height:160px;object-fit:contain" src="<?php echo h(image_upload_public_path('old-library','thumb',$image['filename'])); ?>" alt="<?php echo h($image['original_filename']); ?>"><div class="small my-2" style="overflow-wrap:anywhere"><?php echo h($image['original_filename']); ?></div><div class="small text-muted mb-2"><?php echo $image['used_count']?'Used '.$image['used_count'].' time(s)':'Not yet used'; ?></div>
<?php if($picker): ?><form method="post"><?php echo csrf_input(); ?><input type="hidden" name="image_id" value="<?php echo (int)$image['id']; ?>"><button class="btn btn-success w-100" name="action" value="attach">Use this image</button></form>
<?php else: ?><label class="d-block p-2"><input type="checkbox" name="selected[]" value="<?php echo (int)$image['id']; ?>" <?php echo in_array((int)$image['id'],array_map('intval',(array)($_POST['selected']??[])),true)?'checked':''; ?>> Select for removal</label><?php endif; ?></div>
<?php endforeach; ?></div>
<?php if(!$picker): ?><div class="mt-4"><label>Type DELETE to remove selected library copies<?php if($confirmError): ?><span class="d-block text-danger" id="confirm-error"><?php echo h($confirmError); ?></span><?php endif; ?><input class="form-control <?php echo $confirmError?'is-invalid':''; ?>" name="confirm" value="<?php echo h((string)($_POST['confirm']??'')); ?>" autocomplete="off" <?php echo $confirmError?'aria-invalid="true" aria-describedby="confirm-error"':''; ?>></label><p class="small text-muted">Incoming source files are retained. Removed images will be skipped on future processing runs. Copies already attached to the site are unaffected.</p><button class="btn btn-outline-danger">Remove selected library copies</button></div></form><?php endif; ?>
<nav class="d-flex justify-content-between mt-4" aria-label="Library pages"><?php if($page>1): ?><a class="btn btn-outline-primary" href="?<?php echo h(http_build_query($urlArgs+['page'=>$page-1])); ?>">Previous</a><?php endif; ?><span>Page <?php echo $page; ?> of <?php echo max(1,(int)ceil($total/24)); ?></span><?php if($page*24<$total): ?><a class="btn btn-outline-primary" href="?<?php echo h(http_build_query($urlArgs+['page'=>$page+1])); ?>">Next</a><?php endif; ?></nav></div>
<?php admin_layout_end(); ?>

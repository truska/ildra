<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/helpers.php';
require dirname(__DIR__,2).'/image_upload.php';
require dirname(__DIR__,2).'/media_batches.php';
require dirname(__DIR__,2).'/admin/media_image_actions.php';
ini_set('session.save_path','/tmp');session_start();
$directory=$argv[1]??'';
if(!is_dir($directory)||!str_starts_with(realpath($directory),'/tmp/'))throw new RuntimeException('Pass a private /tmp fixture directory.');
$batch=$eventImageBatch=['id'=>41,'section'=>'fixture'];
$image=['id'=>82,'filename'=>'synthetic.jpg','available_sizes'=>'original,sm,xs','display_order'=>10,'archived'=>0,'title'=>'Fixture','alt_text'=>'Fixture','caption'=>''];
$index=$i=0;
foreach(['event_edit','ride_notes','venue_images','news_images','element_images','banner_images'] as $page){
    $source=file_get_contents(dirname(__DIR__,2).'/admin/'.$page.'.php');
    $start=strpos($source,'<tr data-order-id=');$end=strpos($source,'<?php endforeach',$start);
    if($start===false||$end===false)throw new RuntimeException('Gallery row not found: '.$page);
    ob_start();eval('?>'.substr($source,$start,$end-$start));$row=ob_get_clean();
    $action=$page==='event_edit'?'delete_event_image':'delete_image';
    $html='<form id="unsaved-gallery"><input name="title" required value=""><input name="action" value="save"><table><tbody>'.$row.'</tbody></table></form>';
    $html.=admin_image_delete_forms($batch,[$image],['event_id'=>7],$action);
    file_put_contents($directory.'/'.$page.'.html',$html);
}
$source=file_get_contents(dirname(__DIR__,2).'/admin/third_party_event_edit.php');
if(!str_contains($source,'admin_image_delete_button($batch, $images[0])'))throw new RuntimeException('Missing third-party event delete');
file_put_contents($directory.'/third_party_event_edit.html','<form id="unsaved-gallery"><input name="title" required value="">'.admin_image_delete_button($batch,$image).'</form>'.admin_image_delete_forms($batch,[$image],['event_id'=>7]));
echo "PASS: actual gallery delete rows rendered into synthetic fixtures.\n";

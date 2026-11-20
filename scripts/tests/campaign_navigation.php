<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/helpers.php';
require dirname(__DIR__,2).'/admin/campaign_navigation.php';
function navCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$_GET=[];$_POST=[];
navCheck(str_contains(file_get_contents(dirname(__DIR__,2).'/admin/_bootstrap.php'),"'/../views/back_navigation_styles.php'"),'Admin must load only Back styles');
navCheck(!str_contains(file_get_contents(dirname(__DIR__,2).'/admin/_bootstrap.php'),"'/../views/header_styles.php'"),'Full public stylesheet returned to admin');
navCheck(campaign_admin_back_link(['campaign_type'=>'announcement'])[0]==='campaigns.php?tab=manual','Manual parent');
foreach(['event_entries_open_members','event_entries_open_non_members','event_entries_closing','weekly_ride_notice'] as $type)navCheck(campaign_admin_back_link(['campaign_type'=>$type])[0]==='campaigns.php?tab=automatic','Automatic parent');
navCheck(campaign_admin_back_link(['campaign_type'=>'ride_notes','event_id'=>12])[0]==='ride_notes.php?event_id=12','Rider Notes parent');
navCheck(campaign_template_back_link(['template_key'=>'weekly_ride_notice'])[0]==='campaigns.php?tab=automatic','Automation template parent');
navCheck(campaign_template_back_link(['template_key'=>'custom'])[0]==='campaigns.php?view=templates','Manual template parent');
foreach(['https://evil.test','//evil.test/path','/admin/campaigns.php','../campaigns.php',"email.php\r\nLocation: https://evil.test",'campaigns.php?view=edit&id[]=1','campaigns.php?view=edit&id=0','campaigns.php#fragment','javascript:alert(1)','email.php?view=unknown','ride_notes.php?event_id=-1',[],null] as $bad)navCheck(campaign_navigation_return($bad)===null,'Unsafe destination accepted');
$history='email.php?page=3&sort=to&dir=asc&filter_to=fixture%40example.invalid&filter_status=failed';
navCheck(campaign_navigation_return($history)===$history,'History filters lost');
$_GET=['return'=>'campaigns.php?view=edit&id=37'];
navCheck(campaign_template_back_link(['template_key'=>'weekly_ride_notice'])[0]==='campaigns.php?view=edit&id=37','Campaign-to-template return lost');
navCheck(campaign_navigation_with_return('campaigns.php?view=template_edit&template_id=4')==='campaigns.php?view=template_edit&template_id=4&return=campaigns.php%3Fview%3Dedit%26id%3D37','Save origin not carried');
$_GET=['return'=>'campaigns.php?view=template_select'];navCheck(campaign_admin_back_link([])[0]==='campaigns.php?view=template_select','Picker return lost');
$_GET=[];
$scenarios=[
 'manual'=>['manual',null], 'automatic'=>['automatic',null], 'templates'=>['templates',null],
 'migration'=>['migration',null], 'history'=>['history',null],
 'picker'=>['manual',['campaigns.php?tab=manual','Back to Manual campaigns']],
 'new-from-template'=>['manual',['campaigns.php?view=template_select','Back to Choose a template']],
 'manual-edit'=>['manual',['campaigns.php?tab=manual','Back to Manual campaigns']],
 'automatic-edit'=>['automatic',['campaigns.php?tab=automatic','Back to Automatic campaigns']],
 'template-edit'=>['templates',['campaigns.php?view=templates','Back to Templates']],
 'automated-template'=>['automatic',['campaigns.php?tab=automatic','Back to Automatic campaigns']],
 'campaign-template'=>['automatic',['campaigns.php?view=edit&id=37','Back to Campaign']],
 'email-detail'=>['history',[$history,'Back to Email history']],
 'campaign-email-detail'=>['history',['campaigns.php?view=edit&id=37','Back to Campaign']],
 'settings'=>['history',['email.php','Back to Email history']],
];
foreach($scenarios as $key=>[$active,$back]){
    ob_start();render_campaign_navigation($active,'developer',$back);$html=ob_get_clean();
    navCheck(substr_count($html,'aria-current="page"')===1,'Active tab count: '.$key);
    navCheck(substr_count($html,'page-back-link')===($back?1:0),'Back button count: '.$key);
    if(isset($argv[1]) && is_dir($argv[1]) && str_starts_with(realpath($argv[1]),'/tmp/')){
        ob_start();require dirname(__DIR__,2).'/views/back_navigation_styles.php';$styles=ob_get_clean();
        file_put_contents($argv[1].'/'.$key.'.html','<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">'.$styles.'</head><body><main>'.$html.'<button class="btn btn-success">Fixture green action</button><a class="btn btn-outline-secondary" href="#">Fixture secondary action</a></main></body></html>');
    }
}
ob_start();render_campaign_navigation('manual','manager');$html=ob_get_clean();navCheck(!str_contains($html,'migration_campaign.php'),'Restricted tab exposed');
echo "PASS: campaign parents, picker/editor/save returns, history filters, restricted tabs, one active tab/back button and unsafe return rejection. Offline; no database or sends.\n";

<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require dirname(__DIR__,2).'/helpers.php';
ini_set('session.save_path','/tmp');session_start();
function postcodeCheck(bool $ok,string $message):void { if (!$ok) throw new RuntimeException($message); }
foreach (['bt12 3ab'=>'BT12 3AB','a65 f4e2'=>'A65 F4E2','00123'=>'00123',' BT-1! 1aa. '=>'BT1 1AA',"ab\t12\n!"=>'AB12','éß£-'=>'','a  1'=>'A  1'] as $input=>$expected) postcodeCheck(normalise_postcode((string)$input)===$expected,'Incorrect normalization: '.$input);
$data=['postcode'=>'bt1- 1aa!','company_postcode'=>'a65 f4e2','horses'=>[4=>['postcode'=>'xy1! 2zz']],'postal_code'=>'00123','first_name'=>'Mixed Case!','email'=>'Mixed@Example.test','settings'=>['label'=>'postcode!']];
$normalised=normalise_postcode_fields($data);
postcodeCheck($normalised['postcode']==='BT1 1AA' && $normalised['company_postcode']==='A65 F4E2' && $normalised['horses'][4]['postcode']==='XY1 2ZZ' && $normalised['postal_code']==='00123','Nested/prefixed field normalization failed');
postcodeCheck($normalised['first_name']===$data['first_name'] && $normalised['email']===$data['email'] && $normalised['settings']===$data['settings'],'Unrelated data changed');
$_SERVER['SCRIPT_NAME']='/admin/person_edit.php';
$html='<html><body><form method="post"><input name="postcode"></form></body></html>';
$rendered=shared_form_response($html);
postcodeCheck(str_contains($rendered,'src="/assets/postcodes.js"') && str_contains($rendered,'name="csrf"'),'Shared asset/CSRF injection failed');
postcodeCheck(substr_count(shared_form_response($rendered),'data-postcode-formatter')===1,'Formatter injected twice');
postcodeCheck(postcode_response_assets('{"status":"ready"}')==='{"status":"ready"}','Non-HTML response changed');
$_SERVER['SCRIPT_NAME']='/subsite/admin/venues.php';
postcodeCheck(str_contains(postcode_response_assets($html),'src="/subsite/assets/postcodes.js"'),'Subdirectory asset path failed');
echo "PASS: uppercase ASCII postcode formatting, spaces/leading zeros, nested/prefixed POST fields, unrelated values, global HTML/CSRF assets, idempotence and JSON/subdirectory handling. No database writes.\n";

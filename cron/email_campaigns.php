<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only.');}
require dirname(__DIR__).'/bootstrap.php';
if(!$pdo){fwrite(STDERR,"Database unavailable.\n");exit(1);}
$lock=fopen(sys_get_temp_dir().'/ildra-email-campaigns.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){fwrite(STDOUT,"Campaign processor already running.\n");exit(0);}
$results=processDueEmailCampaigns($pdo);
if(!$results){fwrite(STDOUT,"No due campaign batches.\n");}
foreach($results as $id=>$result)fwrite(STDOUT,sprintf("Campaign %d: sent=%d failed=%d%s\n",$id,(int)$result['sent'],(int)$result['failed'],!empty($result['blocked'])?' blocked='.$result['blocked']:''));
flock($lock,LOCK_UN);fclose($lock);

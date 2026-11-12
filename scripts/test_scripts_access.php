<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function accessCheck(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
function accessProcess(array $command,?array $environment=null): array {
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$environment);
    accessCheck(is_resource($process),'Cannot start fixture process');fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($process),$out,$err];
}
function accessHttp(string $url,string $method='GET'): array {
    $context=stream_context_create(['http'=>['method'=>$method,'ignore_errors'=>true,'timeout'=>3,'follow_location'=>0,'content'=>$method==='POST'?'fixture=1':'','header'=>$method==='POST'?"Content-Type: application/x-www-form-urlencoded\r\n":'']]);
    $body=@file_get_contents($url,false,$context);$headers=$http_response_header??[];
    preg_match('/\s(\d{3})\s/',$headers[0]??'',$match);
    return [(int)($match[1]??0),(string)$body];
}
$root=dirname(__DIR__);$temporary=sys_get_temp_dir().'/ildra-script-access-'.bin2hex(random_bytes(6));mkdir($temporary,0700);
$apache=null;$apachePipes=[];
try {
    $paths=[];$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__,FilesystemIterator::SKIP_DOTS));
    foreach($iterator as$file)if($file->isFile() && $file->getExtension()==='php')$paths[]=$file->getPathname();sort($paths);
    $guard='/\A(?:#![^\n]*\n)?<\?php\s*(?:declare\(strict_types=1\);\s*)?(?:namespace\s*\{\s*)?if\s*\(\s*PHP_SAPI\s*!==\s*[\'\"]cli[\'\"]\s*\)\s*\{\s*http_response_code\(404\);\s*exit;\s*\}/';
    foreach($paths as$index=>$path) {
        $source=file_get_contents($path);accessCheck((bool)preg_match($guard,$source,$match),'Missing early CLI guard: '.basename($path));
        // Run only the actual guard prefix in CLI, never an operational script body.
        $prefix=$match[0];$namespace=str_contains($prefix,'namespace');
        $probe=$temporary.'/guard-'.$index.'.php';file_put_contents($probe,$prefix."\necho 'CLI_ALLOWED';".($namespace?"\n}":''));
        [$exit,$out,$err]=accessProcess([PHP_BINARY,'-n',$probe]);
        accessCheck($exit===0 && $out==='CLI_ALLOWED','CLI guard blocked command-line use: '.basename($path).' '.$err);
        // -n disables extension/config side effects; the real script must exit before dependencies.
        [$exit,$out,$err]=accessProcess(['/usr/bin/php-cgi','-n'],['REDIRECT_STATUS'=>'1','GATEWAY_INTERFACE'=>'CGI/1.1','REQUEST_METHOD'=>'GET','SCRIPT_FILENAME'=>$path,'SCRIPT_NAME'=>'/scripts/'.basename($path),'SERVER_PROTOCOL'=>'HTTP/1.1','SERVER_NAME'=>'localhost','SERVER_PORT'=>'80']);
        accessCheck(str_contains($out,'Status: 404'),'CGI execution not denied: '.basename($path).' '.$err);
        $parts=preg_split('/\r?\n\r?\n/',$out,2);
        accessCheck(trim($parts[1]??'')==='','CGI guard emitted script output: '.basename($path));
    }
    // Real Apache + actual .htaccess files, serving inert canaries instead of application scripts.
    $fixture=$temporary.'/www';mkdir($fixture);mkdir($fixture.'/scripts');mkdir($fixture.'/scripts/tests');
    copy($root.'/.htaccess',$fixture.'/.htaccess');copy(__DIR__.'/.htaccess',$fixture.'/scripts/.htaccess');
    file_put_contents($fixture.'/public.txt','PUBLIC_CONTROL');
    foreach(['process_bounces.php','migrate_auth_throttle.php','tests/test.php','tests/fixture.cjs']as$file)file_put_contents($fixture.'/scripts/'.$file,'DENY_CANARY');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$error,$message);accessCheck((bool)$socket,'Cannot reserve fixture port');$address=stream_socket_get_name($socket,false);fclose($socket);$port=(int)substr(strrchr($address,':'),1);
    $configuration="ServerRoot \"$temporary\"\nPidFile \"$temporary/httpd.pid\"\nListen 127.0.0.1:$port\nServerName localhost\nLoadModule mpm_event_module /usr/lib/apache2/modules/mod_mpm_event.so\nLoadModule authz_core_module /usr/lib/apache2/modules/mod_authz_core.so\nLoadModule rewrite_module /usr/lib/apache2/modules/mod_rewrite.so\nLoadModule dir_module /usr/lib/apache2/modules/mod_dir.so\nErrorLog \"$temporary/httpd-error.log\"\nDocumentRoot \"$fixture\"\n<Directory \"$fixture\">\nAllowOverride All\nOptions FollowSymLinks\nRequire all granted\n</Directory>\n";
    $conf=$temporary.'/httpd.conf';file_put_contents($conf,$configuration);
    [$exit,$out,$err]=accessProcess(['/usr/sbin/apache2','-t','-f',$conf]);accessCheck($exit===0,'Apache config invalid: '.$err);
    $apache=proc_open(['/usr/sbin/apache2','-X','-f',$conf],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$apachePipes);fclose($apachePipes[0]);
    $base='http://127.0.0.1:'.$port;$ready=false;
    for($attempt=0;$attempt<40;$attempt++){[$status,$body]=accessHttp($base.'/public.txt');if($status===200){$ready=true;break;}usleep(50000);}
    accessCheck($ready && $body==='PUBLIC_CONTROL','Apache fixture did not start: '.(@file_get_contents($temporary.'/httpd-error.log')?:''));
    foreach(['/scripts','/scripts/','/scripts/process_bounces.php','/scripts/process_bounces.php/path-info','/scripts/migrate_auth_throttle.php','/scripts/tests/test.php','/scripts/tests/fixture.cjs','/scripts/.htaccess','/SCRIPTS/tests/fixture.cjs','/%73cripts/process_bounces.php']as$path)foreach(['GET','HEAD','POST']as$method){
        [$status,$body]=accessHttp($base.$path,$method);accessCheck(in_array($status,[403,404],true) && !str_contains($body,'DENY_CANARY'),'Scripts HTTP request allowed: '.$method.' '.$path.' status='.$status);
    }
    // The directory authorization must also work without mod_rewrite handling the root rule.
    file_put_contents($fixture.'/.htaccess',"DirectoryIndex index.php\n");
    foreach(['/scripts/','/scripts/process_bounces.php','/scripts/tests/fixture.cjs']as$path){[$status,$body]=accessHttp($base.$path);accessCheck($status===403 && !str_contains($body,'DENY_CANARY'),'Directory deny failed without root rewrite');}
    echo 'Scripts access passed: '.count($paths)." real PHP files reject CGI execution before output/dependencies; CLI guard prefixes remain usable; Apache denies script directories, PHP/static fixtures, path-info, encoded/case variants and GET/HEAD/POST while public files remain available. No operational scripts, mailbox processing or database work executed.\n";
} finally {
    if(is_resource($apache)){proc_terminate($apache);foreach($apachePipes as$pipe)if(is_resource($pipe))fclose($pipe);proc_close($apache);}
    if(is_dir($temporary)){
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as$file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($temporary);
    }
}

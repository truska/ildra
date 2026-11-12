<?php
declare(strict_types=1);
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404);exit; }
    require dirname(__DIR__).'/auth.php';
    final class OriginFixturePDO extends PDO {
        public int $queries=0;
        public bool $known=true;
        public function __construct() {}
        public function prepare(string $query,array $options=[]): PDOStatement|false {
            $this->queries++;
            if (!str_contains($query,'SELECT id FROM users')) throw new RuntimeException('Unexpected fixture query.');
            return new OriginFixtureStatement($this->known);
        }
    }
    final class OriginFixtureStatement extends PDOStatement {
        public function __construct(private bool $known) {}
        public function execute(?array $params=null): bool { return true; }
        public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed { return $this->known ? ['id'=>42] : false; }
    }
    function originCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
}
namespace OriginMailFixture {
    // Offline transport/token/throttle doubles: no real email subsystem or database connection is loaded.
    final class State {
        public static array $mail=[];
        public static array $tokens=[];
        public static array $reservations=[];
        public static bool $throttled=false;
        public static function reset(): void { self::$mail=[];self::$tokens=[];self::$reservations=[];self::$throttled=false; }
    }
    function createPasswordResetToken(\PDO $pdo,int $userId,int $ttl): string { State::$tokens[]=['reset',$ttl];return 'fixture:+/?#&='; }
    function createMagicLoginToken(\PDO $pdo,int $userId,int $ttl): string { State::$tokens[]=['magic',$ttl];return 'fixture:+/?#&='; }
    function authThrottleEmailRequest(\PDO $pdo,string $scope,string $email,array &$alerts): bool {
        State::$reservations[]=[$scope,$email];
        if(State::$throttled) { $alerts[]=['type'=>'warning','message'=>\authThrottleRetryMessage(60)];return false; }
        return true;
    }
    function getSiteSettings(\PDO $pdo): array { return ['hero_title'=>'Fixture']; }
    function getEmailSettings(\PDO $pdo): array { return []; }
    function wrap_user_email_html(array $site,array $settings,string $html): string { return $html; }
    function wrap_user_email_text(array $site,array $settings,string $text): string { return $text; }
    function email_cta_button_html(string $url,string $label): string { return '<a href="'.\h($url).'">'.\h($label).'</a>'; }
    function send_logged_email(\PDO $pdo,string $email,string $subject,string $html,string $text,array $metadata): void { State::$mail[]=compact('email','subject','html','text','metadata'); }
}
namespace {
    // Execute the real handler bodies in an isolated namespace. Only the production mail include
    // is omitted, so the above doubles make real token issuance/email transport impossible.
    $source=file_get_contents(dirname(__DIR__).'/auth.php');
    foreach(['handlePasswordResetRequest','handleMagicLinkRequest'] as $name) {
        $start=strpos($source,'function '.$name.'(');$end=strpos($source,"\nfunction ",$start+1);
        $body=substr($source,$start,$end-$start);
        $body=str_replace("require_once __DIR__ . '/email.php';",'/* Offline email fixture. */',$body,$count);
        originCheck($count===1,'Handler mail include changed; review fixture isolation.');
        eval('namespace OriginMailFixture; use \\PDO; use \\Throwable; '.$body);
    }
    $approved=[
        'ildra.witecanvas.com'=>'https://ildra.witecanvas.com',
        'enduranceridingireland.com'=>'https://enduranceridingireland.com',
        'dev-ildra.witecanvas.com'=>'https://dev-ildra.witecanvas.com',
    ];
    originCheck(authEmailOrigins()===$approved,'Central origin map changed; update reviewed cases.');
    $bad=['evil.example','enduranceridingireland.com.evil.example','evil.enduranceridingireland.com','www.enduranceridingireland.com','evil.example@enduranceridingireland.com','enduranceridingireland.com@evil.example','https://enduranceridingireland.com','enduranceridingireland.com:443','enduranceridingireland.com:80','enduranceridingireland.com.',' enduranceridingireland.com','enduranceridingireland.com ','enduranceridingireland.com/path','enduranceridingireland.com?x=1','enduranceridingireland%2ecom',"enduranceridingireland.com\r\nX-Host: evil.example","enduranceridingireland.com\0.evil.example",'enduranceridingireland．com','',null,[],42];
    foreach($bad as $host) {
        $server=['HTTP_HOST'=>$host,'SERVER_NAME'=>'enduranceridingireland.com','HTTP_X_FORWARDED_HOST'=>'enduranceridingireland.com','HTTP_FORWARDED'=>'host=enduranceridingireland.com;proto=https'];
        originCheck(authEmailOrigin($server)===null,'Unknown/deceptive authority accepted');
        foreach(['reset','magic'] as $flow) foreach([true,false] as $known) {
            \OriginMailFixture\State::reset();$pdo=new OriginFixturePDO();$pdo->known=$known;
            $_SERVER=$server;$_POST=['email'=>$known?'known@example.invalid':'unknown@example.invalid'];$alerts=[];$success='stale';
            if($flow==='reset')\OriginMailFixture\handlePasswordResetRequest($pdo,$alerts,$success);else \OriginMailFixture\handleMagicLinkRequest($pdo,[],$alerts,$success);
            originCheck(count($alerts)===1 && $success===null,'Rejected host did not fail uniformly');
            originCheck(!$pdo->queries && !\OriginMailFixture\State::$tokens && !\OriginMailFixture\State::$mail && !\OriginMailFixture\State::$reservations,'Rejected host reached lookup/token/email/throttle flow');
        }
    }
    originCheck(authEmailOrigin(['SERVER_NAME'=>'enduranceridingireland.com'])===null,'Missing Host fell back to server name');
    foreach($approved as $host=>$origin) foreach([$host,strtoupper($host)] as $authority) foreach(['reset','magic'] as $flow) {
        \OriginMailFixture\State::reset();$pdo=new OriginFixturePDO();
        $_SERVER=['HTTP_HOST'=>$authority,'HTTPS'=>'off','HTTP_X_FORWARDED_PROTO'=>'http','HTTP_X_FORWARDED_HOST'=>'evil.example','HTTP_FORWARDED'=>'host=evil.example','SCRIPT_NAME'=>'/evil/path/account.php'];
        $_POST=['email'=>'known@example.invalid'];$alerts=[];$success=null;
        if($flow==='reset')\OriginMailFixture\handlePasswordResetRequest($pdo,$alerts,$success);else \OriginMailFixture\handleMagicLinkRequest($pdo,[],$alerts,$success);
        originCheck(!$alerts && count(\OriginMailFixture\State::$mail)===1,'Approved flow failed');
        $url=$origin.'/account?'.$flow.'='.rawurlencode('fixture:+/?#&=');
        $mail=\OriginMailFixture\State::$mail[0];
        originCheck(str_contains($mail['html'],h($url)) && str_contains($mail['text'],$url),'Email does not contain fixed HTTPS origin/correct encoded token');
        originCheck(!str_contains($mail['html'],'evil.example') && !str_contains($mail['text'],'evil.example'),'Forwarded Host leaked into email');
        originCheck(\OriginMailFixture\State::$tokens===[[$flow,$flow==='reset'?3600:900]],'Token expiry changed');
        $knownMessage=$success;
        \OriginMailFixture\State::reset();$pdo->known=false;$_POST=['email'=>'unknown@example.invalid'];$alerts=[];$success=null;
        if($flow==='reset')\OriginMailFixture\handlePasswordResetRequest($pdo,$alerts,$success);else \OriginMailFixture\handleMagicLinkRequest($pdo,[],$alerts,$success);
        originCheck(!$alerts && $success===$knownMessage && !\OriginMailFixture\State::$tokens && !\OriginMailFixture\State::$mail && count(\OriginMailFixture\State::$reservations)===1,'Unknown-account privacy/budget changed');
        \OriginMailFixture\State::reset();\OriginMailFixture\State::$throttled=true;$pdo->known=true;$_POST=['email'=>'known@example.invalid'];$alerts=[];$success=null;
        if($flow==='reset')\OriginMailFixture\handlePasswordResetRequest($pdo,$alerts,$success);else \OriginMailFixture\handleMagicLinkRequest($pdo,[],$alerts,$success);
        originCheck(count($alerts)===1 && !\OriginMailFixture\State::$tokens && !\OriginMailFixture\State::$mail,'Throttle bypassed on approved host');
    }
    echo "Authentication email origins passed: all approved domains, fixed HTTPS URLs in both email formats, deceptive/unknown/missing Host rejection before token/mail/lookup, forwarded-header isolation, token TTLs, throttling and account privacy. Offline fixtures only; no emails sent.\n";
}

<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2).'/auth.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
class PreferenceFixture extends PDO {
    public array $writes=[];
    public string $category='general';
    public function __construct() {}
    public function prepare(string $query, array $options=[]): PDOStatement|false { return new PreferenceStatement($this,$query); }
}
class PreferenceStatement extends PDOStatement {
    public function __construct(private PreferenceFixture $fixture, private string $sql) {}
    public function execute(?array $params=null): bool { if (str_starts_with($this->sql,'UPDATE')) $this->fixture->writes[]=[$this->sql,$params]; return true; }
    public function fetch(int $mode=PDO::FETCH_DEFAULT, int $cursorOrientation=PDO::FETCH_ORI_NEXT, int $cursorOffset=0): mixed {
        if (str_contains($this->sql,'FROM users')) return ['email'=>'fixture@example.test','password_hash'=>'','is_protected'=>0];
        return ['email'=>'fixture@example.test','category'=>$this->fixture->category];
    }
}
$pdo=new PreferenceFixture();
foreach ([0,1] as $choice) {
    $alerts=[];
    $data=['first_name'=>'Test','last_name'=>'Person','email'=>'fixture@example.test','ride_notice_opt_in'=>$choice,'general_email_opt_in'=>1-$choice,'renewal_reminder_opt_in'=>1-$choice];
    $result=updateOwnUserDetails($pdo,123,$data,$alerts);
    foreach (['general_email_opt_in','ride_notice_opt_in','renewal_reminder_opt_in'] as $key) check($result[$key]===$choice,'Account preference must set '.$key);
    $params=$pdo->writes[array_key_last($pdo->writes)][1];
    foreach ([':general_opt_in',':ride_notice_opt_in',':renewal_opt_in'] as $key) check($params[$key]===$choice,'Persisted account preferences must agree');
}
$source=file_get_contents(dirname(__DIR__,2).'/email_unsubscribe.php');
$source=str_replace(["require __DIR__ . '/bootstrap.php';", 'declare(strict_types=1);'],'',$source);
function defaultSiteSettings(): array { return []; }
function renderPreference(PreferenceFixture $pdo,string $source,string $category,string $method,bool $preview=false): string {
    $pdo->category=$category; $pdo->writes=[];
    $_GET=['t'=>str_repeat('a',40),'preview'=>$preview?'1':'0'];$_POST=[];$_SERVER['REQUEST_METHOD']=$method;
    ob_start(); eval('?>'.$source); return ob_get_clean();
}
foreach (['general','ride_notice','renewal_reminder'] as $category) {
    $html=renderPreference($pdo,$source,$category,'GET'); check(!$pdo->writes,'GET must not unsubscribe');
    check(str_contains($html,'Ride Reminders and Updates'),'Single preference label');
    $html=renderPreference($pdo,$source,$category,'POST');check(count($pdo->writes)===3,'Old links unsubscribe users, people and pending deliveries');
    foreach (array_slice($pdo->writes,0,2) as [$sql]) foreach (['general_email_opt_in=0','ride_notice_opt_in=0','renewal_reminder_opt_in=0'] as $assignment) check(str_contains($sql,$assignment),'Unsubscribe clears all three');
    check(str_contains($pdo->writes[2][0],"IN ('general','ride_notice','renewal_reminder')"),'Pending deliveries in all three categories cancelled');
    check(str_contains($html,'You have been unsubscribed'),'Confirmation displayed');
}
renderPreference($pdo,$source,'operational','POST');check(!$pdo->writes,'Essential messages unaffected');
renderPreference($pdo,$source,'general','POST',true);check(!$pdo->writes,'Preview cannot unsubscribe');
echo "Email preference regression checks passed.\n";

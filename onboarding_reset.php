<?php
declare(strict_types=1);
require_once __DIR__.'/import_workspace.php';

function onboardingResetModes(): array
{
    return [
        'keep_users'=>['label'=>'Reset onboarding data — keep users','phrase'=>'RESET ONBOARDING DATA'],
        'replace_users'=>['label'=>'Reset onboarding data and remove users','phrase'=>'DELETE UNPROTECTED USERS'],
    ];
}

function onboardingResetPlan(PDO $pdo, int $keepId, string $mode): array
{
    if (!isset(onboardingResetModes()[$mode])) throw new RuntimeException('Choose one of the two onboarding reset options.');
    $plan=importWorkspacePlan($pdo,$keepId);
    $plan['onboarding_mode']=$mode;
    $plan['snapshot_tables']=['users'];
    $plan['pause_campaigns']=isset($plan['updates']['email_campaigns']);
    if ($mode==='keep_users') {
        unset($plan['delete']['users']);
        // Retained users still own authored content and organiser assignments.
        $plan['updates']=[];
    }
    return $plan;
}

/** All deletion/recreation is part of the enclosing backed-up transaction. */
function onboardingResetApply(PDO $pdo, array $plan): void
{
    if (!$pdo->inTransaction()) throw new RuntimeException('Reset requires a transaction.');
    $mode=$plan['onboarding_mode']??'';
    if (!isset(onboardingResetModes()[$mode])) throw new RuntimeException('Invalid onboarding reset plan.');
    if ($mode==='replace_users') {importWorkspaceApply($pdo,$plan);return;}
    $users=$pdo->query('SELECT * FROM users ORDER BY id')->fetchAll();
    $protected=array_values(array_filter($users,static fn(array $user):bool=>(int)$user['id']===(int)$plan['keep_user'] && !empty($user['is_protected'])));
    if (count($protected)!==1) throw new RuntimeException('Protected account is missing.');
    testingResetApply($pdo,$plan);
    importWorkspacePauseCampaigns($pdo,!empty($plan['pause_campaigns']));
    foreach (testingResetCounts($pdo,$plan) as $count) if ($count!==0) throw new RuntimeException('Reset left operational records behind.');
    // Fresh identities only: no contact details, preferences, memberships or old IDs.
    $pdo->exec("INSERT INTO people(owner_user_id,first_name,last_name,email,general_email_opt_in,ride_notice_opt_in,renewal_reminder_opt_in,is_archived)
        SELECT id,COALESCE(first_name,''),COALESCE(last_name,''),email,0,0,0,0 FROM users WHERE is_archived=0 ORDER BY id");
    if ($pdo->query('SELECT * FROM users ORDER BY id')->fetchAll()!==$users) throw new RuntimeException('Retained-user verification failed.');
    $expected=(int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_archived=0')->fetchColumn();
    if ((int)$pdo->query('SELECT COUNT(*) FROM people')->fetchColumn()!==$expected) throw new RuntimeException('Fresh Person records could not be verified.');
}

function onboardingResetRun(PDO $pdo, array $actor, array $config, string $host, array $preview): string
{
    $mode=$preview['plan']['onboarding_mode']??'';
    if (!isset(onboardingResetModes()[$mode])) throw new RuntimeException('Invalid onboarding reset preview.');
    return importWorkspaceRun($pdo,$actor,$config,$host,$preview,$mode);
}

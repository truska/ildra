<?php
declare(strict_types=1);

/** Editable introductory HTML for the guided account setup screens. */
function onboardingCopyDefinitions(): array
{
    return [
        'password' => ['Password', '<p>Choose a password for your account and enter it again to confirm.</p>'],
        'user' => ['User settings', '<p>Your login email stays the same during setup.</p>'],
        'person' => ['Person settings', ''],
        'horses' => ['Horses', '<p>You purchased a logbook for one or more of your horses last year. You can add logbooks to your basket again now as part of the onboarding process.</p><p>Please click the appropriate button below for each horse to proceed.</p>'],
        'shares' => ['Shares', '<p>To follow.</p>'],
        'finish' => ['Finish', '<p>Your password, user, person and horse choices have been saved. You have reached the Shares placeholder. Choose Finish to complete your migration. Any memberships and logbooks in your basket can be paid for afterwards.</p>'],
        'thanks' => ['Thank you', '<p>Thank you for migrating to our new system. Your account setup is complete.</p>'],
        'welcome' => ['Welcome', ''],
    ];
}

function onboardingCopyHtml(string $key, array $settings): string
{
    $definition = onboardingCopyDefinitions()[$key] ?? null;
    if (!$definition) return '';
    return sanitize_rich_html((string)($settings['onboarding_copy_' . $key] ?? $definition[1]));
}

function onboardingFlowDefaults(): array
{
    return ['onboarding_horses_enabled'=>'1', 'onboarding_shares_enabled'=>'1', 'onboarding_show_blank_pages'=>'0'];
}

/** Read only: safe to use while saving setup progress inside a transaction. */
function onboardingFlowSettings(?PDO $pdo): array
{
    $settings=onboardingFlowDefaults();
    if (!$pdo) return $settings;
    $rows=$pdo->query("SELECT setting_key,setting_value FROM site_settings WHERE setting_key IN ('onboarding_horses_enabled','onboarding_shares_enabled','onboarding_show_blank_pages')");
    foreach($rows as $row)$settings[$row['setting_key']]=(string)$row['setting_value']==='1'?'1':'0';
    return $settings;
}

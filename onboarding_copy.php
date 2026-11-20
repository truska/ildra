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
    ];
}

function onboardingCopyHtml(string $key, array $settings): string
{
    $definition = onboardingCopyDefinitions()[$key] ?? null;
    if (!$definition) return '';
    return sanitize_rich_html((string)($settings['onboarding_copy_' . $key] ?? $definition[1]));
}

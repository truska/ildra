<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/db.php';
require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/help_support.php';
$alerts = [];
$pdo = createPdo(require dirname(__DIR__, 2) . '/config.php', $alerts);
if (!$pdo) throw new RuntimeException('Database unavailable.');
// All writes, including default seeding, are confined to temporary tables.
foreach (['account_intro_modals', 'help_groups', 'help_articles'] as $table) {
    $schema = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
    // MariaDB temporary tables cannot reference another temporary table by FK.
    $schema = preg_replace('/,\n\s*CONSTRAINT[^\n]+/', '', $schema);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $schema));
}
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$originalBody = '<p>Existing horse information already edited by an administrator.</p>';
$pdo->prepare('INSERT INTO account_intro_modals (view_key,heading,body_html,is_active) VALUES (?,?,?,1)')->execute(['horses', 'Existing horse heading', $originalBody]);
ensureHelpTables($pdo);
ensureHelpTables($pdo);
$definitions = screenGuidanceDefinitions();
foreach ($definitions as $key => $definition) {
    $assert(strlen($key) <= 30, 'Guidance keys must fit the existing database column.');
    $assert(isset(screenGuidanceGroups()[$definition['group']]), 'Every item needs an editor tab.');
}
$assert((int)$pdo->query('SELECT COUNT(*) FROM account_intro_modals')->fetchColumn() === count($definitions), 'Seeding must not duplicate guidance.');
$horsePopup = fetchAccountIntroModal($pdo, 'horses');
$assert($horsePopup['heading'] === 'Existing horse heading' && $horsePopup['body_html'] === $originalBody, 'Existing popups must survive seeding unchanged.');
$assert(str_contains(screenGuidanceHtml(null, 'logbook_about'), 'Buy Logbook'), 'Safe default guidance must work without a database.');
$assert(fetchScreenGuidance($pdo, 'unknown') === null, 'Unknown guidance must not render.');
$about = ['heading' => 'Choosing a logbook', 'body_html' => '<p>Edited guidance.</p><script>alert(1)</script><p><a href="javascript:alert(1)">Unsafe link</a></p>', 'is_active' => 1];
$assert(saveAccountIntroModals($pdo, ['intros' => ['logbook_about' => $about]], $alerts), 'A scoped save must succeed: ' . json_encode($alerts));
$saved = fetchScreenGuidance($pdo, 'logbook_about');
$assert($saved['heading'] === 'Choosing a logbook' && str_contains($saved['body_html'], 'Edited guidance.'), 'Saved guidance must appear in the frontend reader.');
$assert(!str_contains($saved['body_html'], '<script') && !str_contains($saved['body_html'], 'javascript:'), 'Guidance HTML must be sanitised.');
$assert(fetchAccountIntroModal($pdo, 'horses')['body_html'] === $originalBody, 'Saving logbook guidance must not change the horse popup.');
$assert(saveAccountIntroModals($pdo, ['intros' => ['horse_add_intro' => ['body_html' => '<p>New horse introduction.</p>', 'is_active' => 1]]], $alerts), 'Inline guidance must not require an unused heading field.');
$assert(screenGuidanceHtml($pdo, 'horse_add_intro') === '<p>New horse introduction.</p>', 'Add Horse must read the single saved copy.');
$before = fetchScreenGuidance($pdo, 'logbook_about');
$errors = [];
$assert(!saveAccountIntroModals($pdo, ['intros' => ['logbook_about' => ['heading' => 'Must not be saved', 'body_html' => '<p>Valid first item.</p>', 'is_active' => 1], 'horses' => ['heading' => 'Missing body', 'body_html' => '<p><br></p>', 'is_active' => 1]]], $errors), 'Invalid content must block the whole save.');
$assert(fetchScreenGuidance($pdo, 'logbook_about') === $before, 'Validation errors must not partially save earlier items.');
$errors = [];
$assert(!saveAccountIntroModals($pdo, ['intros' => ['unknown' => $about]], $errors), 'Unknown keys must not be saved.');
$assert(saveAccountIntroModals($pdo, ['intros' => ['logbook_about' => array_replace($about, ['is_active' => 0])]], $alerts), 'Guidance visibility must be editable.');
$assert(fetchScreenGuidance($pdo, 'logbook_about') === null && screenGuidanceHtml($pdo, 'logbook_about') === '', 'Disabled guidance must not render.');
$assert(fetchAccountIntroModal($pdo, 'horses')['body_html'] === $originalBody, 'Visibility changes must not affect unrelated content.');
echo "Screen guidance: defaults, preservation, scoped saves, frontend reads, sanitisation, validation and visibility passed.\n";

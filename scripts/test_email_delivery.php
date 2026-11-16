<?php
declare(strict_types=1);
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
}
namespace DeliveryFixture {
    use PDO;
    use RuntimeException;
    final class State {
        public static array $settings = [];
        public static array $private = [];
        public static array $log = [];
        public static array $sent = [];
        public static bool $fail = false;
        public static array $saved = [];
    }
    final class OfflinePDO extends PDO {
        public function __construct() {}
        public function prepare(string $query, array $options = []): \PDOStatement|false { return new OfflineStatement(); }
    }
    final class OfflineStatement extends \PDOStatement {
        public function execute(?array $params = null): bool { State::$saved[$params[':k']] = $params[':v']; return true; }
    }
    function email_environment_config(): array { return State::$private; }
    function getEmailSettings(?PDO $pdo): array { return State::$settings; }
    function ensureEmailTables(PDO $pdo): void {}
    function logEmail(PDO $pdo, array $row): int { State::$log = $row; return 1; }
    function updateEmailLog(PDO $pdo, int $id, array $row): void { State::$log = array_replace(State::$log, $row); }
    function emailDebugSnapshot(array $settings, array $meta): array { return []; }
    function defaultSiteSettings(): array { return ['hero_title' => 'Fixture']; }
    function admin_layout_start(...$args): void {}
    function admin_layout_end(): void {}
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
    function smtp_send_mail(...$args): void {
        State::$sent[] = $args;
        if (State::$fail) throw new RuntimeException('Fixture SMTP failure');
    }
    function php_mail_send(...$args): void { throw new RuntimeException('Unexpected PHP mail fallback'); }
    function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

    // Load actual sending/routing functions into this namespace. No production config,
    // database or transport is loaded: every connection and log write stays in memory.
    $source = file_get_contents(dirname(__DIR__) . '/email.php');
    foreach (['defaultEmailSettings', 'emailDeliveryTypes', 'emailDeliveryRoutes', 'emailDeliverySystem',
        'emailSesSettings', 'emailSesReady', 'send_logged_email', 'email_site_code',
        'email_generate_bounce_token', 'email_bounce_sender', 'email_message_id',
        'email_safe_debug_error', 'parseEmailList', 'saveEmailSettings'] as $name) {
        $start = strpos($source, 'function ' . $name . '(');
        check($start !== false, 'Missing production function ' . $name);
        $end = strpos($source, "\nfunction ", $start + 1);
        eval('namespace DeliveryFixture; use \\PDO; use \\RuntimeException; use \\Throwable; ' . substr($source, $start, $end - $start));
    }
    $base = array_replace(defaultEmailSettings(), [
        'email_enabled' => '1', 'email_smtp_host' => 'truska.invalid',
        'email_smtp_username' => 'truska-user', 'email_smtp_password' => 'fixture-truska-secret',
        'email_from_email' => 'truska@example.test', 'email_from_name' => 'Truska sender',
        'email_reply_to' => 'contact@example.test', 'email_ses_from_email' => 'ses@example.test',
        'email_ses_from_name' => 'SES sender', 'email_cc_default' => 'cc@example.test',
        'email_bcc_default' => 'bcc@example.test',
    ]);
    State::$private = ['ses' => ['username' => 'ses-user', 'password' => 'fixture-ses-secret']];
    State::$settings = $base;
    $pdo = new OfflinePDO();
    $send = static function (array $meta): bool {
        global $pdo;
        State::$sent = []; State::$log = [];
        return send_logged_email($pdo, 'recipient@example.test', 'Fixture', '<p>Fixture</p>', 'Fixture', $meta, [['filename' => 'fixture.txt', 'content' => 'fixture']], ['additional@example.test']);
    };
    check($send(['kind' => 'password_reset']), 'Legacy send failed');
    check(State::$sent[0][0]['email_smtp_host'] === 'truska.invalid', 'Legacy transport changed');
    check(State::$log['provider'] === 'smtp', 'Legacy provider changed');
    foreach (emailDeliveryTypes() as $kind => $label) {
        State::$settings = array_replace($base, ['email_delivery_routes' => json_encode([$kind => 'ses'])]);
        foreach (['kind', 'type'] as $key) {
            check($send([$key => $kind]), 'SES route failed: ' . $kind);
            $sent = State::$sent[0];
            check($sent[0]['email_smtp_host'] === 'email-smtp.eu-north-1.amazonaws.com', 'Wrong SES endpoint');
            check($sent[0]['email_smtp_username'] === 'ses-user', 'Credentials mixed');
            check($sent[1] === 'ses@example.test' && $sent[9] === 'ses@example.test', 'SES sender/envelope incorrect');
            check($sent[0]['email_reply_to'] === 'contact@example.test', 'Reply-to lost');
            check(count($sent[4]) === 2 && count($sent[5]) === 1 && count($sent[11]) === 1, 'CC/BCC/attachment lost');
            check(State::$log['provider'] === 'ses_smtp', 'SES provider missing from log');
            check(!str_contains(State::$log['meta_json'], 'fixture-ses-secret'), 'Secret leaked');
        }
    }
    State::$settings = array_replace($base, ['email_delivery_default' => 'ses', 'email_delivery_routes' => '{"magic_login":"truska"}']);
    check($send(['kind' => 'unknown']), 'Unknown kind should use default');
    check(State::$log['provider'] === 'ses_smtp', 'Default ignored');
    check($send(['kind' => 'magic_login']), 'Override failed');
    check(State::$sent[0][0]['email_smtp_host'] === 'truska.invalid', 'Explicit Truska ignored');
    check($send(['type' => 'test', 'delivery_system' => 'ses']), 'Explicit SES test failed');
    check(State::$log['provider'] === 'ses_smtp', 'Test system ignored');
    State::$settings['email_test_redirect_enabled'] = '1';
    State::$settings['email_test_redirect_to'] = 'redirect@example.test';
    foreach (['truska', 'ses'] as $system) {
        check($send(['type' => 'test', 'delivery_system' => $system]), 'Redirect send failed');
        check(State::$sent[0][3] === 'redirect@example.test' && State::$sent[0][4] === [] && State::$sent[0][5] === [], 'Redirect leaked recipients');
    }
    State::$fail = true;
    check(!$send(['type' => 'test', 'delivery_system' => 'ses']), 'SMTP failure ignored');
    check(count(State::$sent) === 1 && State::$log['status'] === 'failed', 'Failed SES fell back or was not logged');
    State::$fail = false; State::$private = [];
    check(!$send(['type' => 'test', 'delivery_system' => 'ses']), 'Missing SES credentials accepted');
    check(State::$sent === [] && State::$log['status'] === 'failed', 'Unconfigured SES attempted transport');
    check(!$send(['type' => 'test', 'delivery_system' => 'invalid']), 'Invalid system accepted');
    State::$settings['email_enabled'] = '0';
    check(!$send(['kind' => 'magic_login']) && State::$sent === [], 'Disabled email sent');
    State::$settings = $base;
    $alerts = [];
    check(!saveEmailSettings($pdo, ['email_delivery_routes' => '{"password_reset":"ses"}'], $alerts), 'Unconfigured SES route saved');
    check(State::$saved === [], 'Invalid routes wrote settings');
    State::$private = ['ses' => ['username' => 'ses-user', 'password' => 'fixture-ses-secret']];
    $alerts = [];
    check(saveEmailSettings($pdo, ['email_delivery_routes' => '{"password_reset":"ses"}'], $alerts), 'Valid routes not saved');
    check(json_decode(State::$saved['email_delivery_routes'], true)['password_reset'] === 'ses', 'Saved route incorrect');
    check(State::$saved['email_smtp_password'] === '' && !str_contains(json_encode(State::$saved), 'fixture-ses-secret'), 'Secrets persisted');
    State::$saved = []; $alerts = [];
    check(!saveEmailSettings($pdo, ['email_delivery_routes' => '{"password_reset":"invalid"}'], $alerts) && State::$saved === [], 'Invalid provider saved');
    $alerts = [];
    check(!saveEmailSettings($pdo, ['email_delivery_routes' => '{"unknown":"ses"}'], $alerts), 'Unknown message type saved');
    // Render the actual settings template offline and inspect its form controls.
    $template = file_get_contents(dirname(__DIR__) . '/admin/email.php');
    $start = strpos($template, "    // Keep field validation beside its control");
    $end = strpos($template, "    exit;", $start);
    $emailSettings = $base; $adminBase = '/admin'; $testResult = null;
    $alerts = [['type' => 'danger', 'fields' => ['email_reply_to'], 'message' => 'Reply-to must be a valid email address.']];
    $emailSettings['email_reply_to'] = 'invalid-address';
    ob_start();
    eval('namespace DeliveryFixture; ' . substr($template, $start, $end - $start));
    $html = ob_get_clean();
    $dom = new \DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new \DOMXPath($dom);
    check($xpath->query('//input[@id="email_reply_to" and @aria-invalid="true" and @aria-describedby="email_reply_to_error" and @value="invalid-address"]')->length === 1, 'Invalid field lost its value or accessible error association');
    check($xpath->query('//input[@id="email_reply_to" and contains(@class,"is-invalid")]')->length === 1, 'Invalid field border missing');
    check($xpath->query('//div[@id="email_reply_to_error" and @role="alert"]')->length === 1, 'Inline warning missing');
    check($xpath->query('//input[@id="email_reply_to"]/preceding-sibling::*[2][@id="email_reply_to_error"]')->length === 1, 'Warning must appear above the field label');
    check($xpath->query('//input[@id="email_from_email" and @aria-invalid]')->length === 0, 'Unrelated field marked invalid');
    check($xpath->query('//select[@name="email_delivery_default"]/option')->length === 2, 'Default system options missing');
    check($xpath->query('//select[@name="test_system"]/option')->length === 2, 'Test system options missing');
    foreach (emailDeliveryTypes() as $kind => $label) {
        check($xpath->query('//select[@name="email_routes[' . $kind . ']"]/option')->length === 3, 'Routing control missing: ' . $kind);
    }
    check($xpath->query('//input[@name="email_ses_region" and @value="eu-north-1"]')->length === 1, 'SES region missing');
    check(!str_contains($html, 'fixture-ses-secret') && !str_contains($html, 'fixture-truska-secret'), 'Settings template exposed a password');
    echo "PASS: email routing, separate credentials/senders, defaults, all message kinds, tests, redirect, logs, attachments and failure isolation. No emails sent.\n";
}

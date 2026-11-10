<?php
declare(strict_types=1);

function purchasingPaused(array $settings): bool
{
    return (int)($settings['purchasing_paused_at'] ?? 0) > 0;
}

function purchasingBlocked(array $settings, ?array $user): bool
{
    return purchasingPaused($settings) && !in_array(strtolower((string)($user['role'] ?? '')), ['admin', 'superadmin', 'developer'], true);
}

function purchasingMessage(array $settings): string
{
    return trim((string)($settings['purchasing_pause_message'] ?? '')) ?: 'Purchasing is temporarily unavailable for maintenance. Please try again later.';
}

// Basket timestamps use a clock which excludes every maintenance interval.
function purchasingBasketClock(array $settings, ?int $now = null): int
{
    $now ??= time();
    return (purchasingPaused($settings) ? min($now, (int)$settings['purchasing_paused_at']) : $now)
        - (int)($settings['purchasing_paused_seconds'] ?? 0);
}

function savePurchasingPause(PDO $pdo, bool $paused, string $message): void
{
    // Ensure a lockable row exists even on the first use after deployment.
    $pdo->exec("INSERT IGNORE INTO site_settings(setting_key,setting_value,updated_at) VALUES ('purchasing_paused_at','0',NOW()),('purchasing_paused_seconds','0',NOW())");
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('purchasing_paused_at','purchasing_paused_seconds') FOR UPDATE");
        $state = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $now = time();
        $started = (int)($state['purchasing_paused_at'] ?? 0);
        $elapsed = (int)($state['purchasing_paused_seconds'] ?? 0);
        if (!$paused && $started > 0) $elapsed += max(0, $now - $started);
        $values = ['purchasing_paused_at' => $paused ? ($started ?: $now) : 0,
            'purchasing_paused_seconds' => $elapsed, 'purchasing_pause_message' => trim($message)];
        $write = $pdo->prepare("INSERT INTO site_settings(setting_key,setting_value,updated_at) VALUES(:key,:value,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()");
        foreach ($values as $key => $value) $write->execute([':key'=>$key, ':value'=>(string)$value]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

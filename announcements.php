<?php
declare(strict_types=1);

/** Strict local dates prevent invalid schedules silently rolling into another day. */
function announcement_date(string $value): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Europe/London'));
    return $date && $date->format('Y-m-d') === $value ? $date : null;
}

function manual_announcement_visible(array $settings, ?DateTimeImmutable $now = null): bool
{
    if (empty($settings['announcement_enabled']) || trim((string)($settings['announcement_title'] ?? '')) === '') return false;
    $now = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('Europe/London'));
    $from = announcement_date((string)($settings['announcement_from'] ?? ''));
    $to = announcement_date((string)($settings['announcement_to'] ?? ''));
    return $from && $to && $from <= $to && $now >= $from && $now < $to->modify('+1 day');
}

function announcement_colour(string $value, string $fallback): string
{
    return preg_match('/^#[0-9a-f]{6}$/i', $value) ? $value : $fallback;
}

/** Select one notice: nearest closing deadline, otherwise most recent opening. */
function event_announcement(array $events, ?DateTimeImmutable $now = null): ?array
{
    $zone = new DateTimeZone('Europe/London');
    $now = $now ?? new DateTimeImmutable('now', $zone);
    $notices = [];
    foreach ($events as $event) {
        if (($event['status'] ?? '') !== 'published' || (int)($event['id'] ?? 0) <= 0) continue;
        if (!empty($event['capacity_enabled']) && (int)($event['capacity_limit'] ?? 0) > 0 && (int)($event['entry_count'] ?? 0) >= (int)$event['capacity_limit']) continue;
        try {
            $eventDate = announcement_date((string)($event['event_date'] ?? ''));
            if (!$eventDate || $eventDate->format('Y-m-d') < $now->setTimezone($zone)->format('Y-m-d')) continue;
            // Match the fallback windows used by the public event listing.
            $member = !empty($event['entry_open_at']) ? new DateTimeImmutable($event['entry_open_at'], $zone) : $eventDate->modify('-1 month');
            $nonMember = !empty($event['non_member_entry_open_at']) ? new DateTimeImmutable($event['non_member_entry_open_at'], $zone) : $member->modify('+1 week');
            $close = !empty($event['entry_close_at']) ? new DateTimeImmutable($event['entry_close_at'], $zone) : $eventDate->modify('-1 week')->setTime(23, 59, 59);
        } catch (Exception $e) { continue; }
        if ($now >= $close) continue;
        $base = ['event' => $event];
        if ($now->getTimestamp() >= $close->getTimestamp() - 48 * 3600 && min($member, $nonMember) <= $now) {
            $notices[] = $base + ['kind' => 'closing', 'time' => $close->getTimestamp(), 'text' => $event['title'] . ' — Entries close at ' . $close->format('H:i') . ' on ' . $close->format('l j F')];
            continue;
        }
        $windows = $member == $nonMember ? [[$member, 'members and non-members']] : [[$member, 'members'], [$nonMember, 'non-members']];
        foreach ($windows as [$open, $audience]) {
            if ($now >= $open && $now->getTimestamp() < $open->getTimestamp() + 48 * 3600) {
                $notices[] = $base + ['kind' => 'opening', 'time' => $open->getTimestamp(), 'text' => $event['title'] . ' — Entries OPEN for ' . $audience];
            }
        }
    }
    usort($notices, static function (array $a, array $b): int {
        if ($a['kind'] !== $b['kind']) return $a['kind'] === 'closing' ? -1 : 1;
        $order = $a['kind'] === 'closing' ? $a['time'] <=> $b['time'] : $b['time'] <=> $a['time'];
        return $order ?: (int)$a['event']['id'] <=> (int)$b['event']['id'];
    });
    return $notices[0] ?? null;
}

/** Reuse the normal announcement's inclusive UK date window. */
function super_announcement_visible(array $settings, ?DateTimeImmutable $now = null): bool
{
    $normal = [];
    foreach ($settings as $key => $value) {
        if (str_starts_with($key, 'super_announcement_')) $normal[substr($key, 6)] = $value;
    }
    return manual_announcement_visible($normal, $now);
}

function super_announcement_token(array $settings): string
{
    return hash('sha256', implode('|', [
        $settings['super_announcement_from'] ?? '',
        $settings['super_announcement_to'] ?? '',
        $settings['super_announcement_revision'] ?? '1',
    ]));
}

/** Run on the management screen (or deployment CLI), preserving legacy notices. */
function ensure_announcements_table(PDO $pdo, array $settings): void
{
    // Web requests must not depend on access to the restricted migrations directory.
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS announcements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    announcement_type VARCHAR(10) NOT NULL,
    legacy_key VARCHAR(10) NULL UNIQUE,
    display_order INT NOT NULL DEFAULT 0,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    show_from DATE NULL,
    show_to DATE NULL,
    title VARCHAR(255) NOT NULL DEFAULT '',
    heading VARCHAR(255) NOT NULL DEFAULT '',
    subheading VARCHAR(255) NOT NULL DEFAULT '',
    body_html MEDIUMTEXT NULL,
    asset_id INT UNSIGNED NOT NULL DEFAULT 0,
    image_side VARCHAR(5) NOT NULL DEFAULT 'left',
    bg_colour VARCHAR(7) NOT NULL DEFAULT '#0c4522',
    text_colour VARCHAR(7) NOT NULL DEFAULT '#ffffff',
    revision VARCHAR(64) NOT NULL DEFAULT '1',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX announcement_schedule (announcement_type, enabled, show_from, show_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
    );
    $fields = ['enabled', 'title', 'heading', 'subheading', 'body_html', 'asset_id', 'image_side', 'bg_colour', 'text_colour', 'revision'];
    foreach (['manual' => 'announcement_', 'super' => 'super_announcement_'] as $type => $prefix) {
        if (trim((string)($settings[$prefix . 'title'] ?? '')) === '') continue;
        $row = ['announcement_type' => $type, 'legacy_key' => $type];
        foreach ($fields as $field) $row[$field] = $settings[$prefix . $field] ?? ($field === 'revision' ? '1' : '');
        foreach (['from', 'to'] as $field) $row['show_' . $field] = announcement_date((string)($settings[$prefix . $field] ?? '')) ? $settings[$prefix . $field] : null;
        $columns = implode(',', array_keys($row));
        $params = implode(',', array_fill(0, count($row), '?'));
        $stmt = $pdo->prepare("INSERT INTO announcements ($columns) VALUES ($params) ON DUPLICATE KEY UPDATE legacy_key=VALUES(legacy_key)");
        $stmt->execute(array_values($row));
    }
}

/** One active notice per type; lowest order, latest start, then newest ID wins. */
function select_announcement(array $rows, string $type, ?DateTimeImmutable $now = null): ?array
{
    $eligible = array_values(array_filter($rows, static function (array $row) use ($type, $now): bool {
        return ($row['announcement_type'] ?? '') === $type && manual_announcement_visible([
            'announcement_enabled' => $row['enabled'], 'announcement_title' => $row['title'],
            'announcement_from' => $row['show_from'] ?? '', 'announcement_to' => $row['show_to'] ?? '',
        ], $now);
    }));
    usort($eligible, static fn(array $a, array $b): int => ((int)$a['display_order'] <=> (int)$b['display_order'])
        ?: strcmp($b['show_from'], $a['show_from']) ?: ((int)$b['id'] <=> (int)$a['id']));
    return $eligible[0] ?? null;
}

/** Adapt stored notices to the existing public templates without changing event notices. */
function announcement_public_settings(?PDO $pdo, array $settings): array
{
    if (!$pdo) return $settings;
    try { $rows = $pdo->query('SELECT * FROM announcements')->fetchAll(PDO::FETCH_ASSOC); }
    catch (PDOException $e) {
        if ($e->getCode() === '42S02') return $settings; // Pre-migration deployment.
        throw $e;
    }
    foreach (['manual' => 'announcement_', 'super' => 'super_announcement_'] as $type => $prefix) {
        $row = select_announcement($rows, $type);
        $settings[$prefix . 'enabled'] = $row ? '1' : '0';
        if (!$row) continue;
        foreach (['title', 'heading', 'subheading', 'body_html', 'asset_id', 'image_side', 'bg_colour', 'text_colour', 'revision'] as $field) $settings[$prefix . $field] = $row[$field];
        $settings[$prefix . 'from'] = $row['show_from'];
        $settings[$prefix . 'to'] = $row['show_to'];
        // Separate records must not share a dismissal token, even with matching dates.
        if (empty($row['legacy_key'])) $settings[$prefix . 'revision'] = $row['id'] . ':' . $row['revision'];
    }
    return $settings;
}

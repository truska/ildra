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

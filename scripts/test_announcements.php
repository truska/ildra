<?php
declare(strict_types=1);
require dirname(__DIR__) . '/announcements.php';
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function at(string $value): DateTimeImmutable { return new DateTimeImmutable($value, new DateTimeZone('Europe/London')); }
$manual = ['announcement_enabled'=>'1', 'announcement_title'=>'Test', 'announcement_from'=>'2026-10-24', 'announcement_to'=>'2026-10-25'];
check(!manual_announcement_visible($manual, at('2026-10-23 23:59:59')), 'Manual starts too early');
check(manual_announcement_visible($manual, at('2026-10-24 00:00:00')), 'Manual start not inclusive');
check(manual_announcement_visible($manual, at('2026-10-25 23:59:59')), 'Manual end not inclusive');
check(!manual_announcement_visible($manual, at('2026-10-26 00:00:00')), 'Manual did not expire');
check(!manual_announcement_visible(array_replace($manual, ['announcement_enabled'=>'0']), at('2026-10-24')), 'Disabled manual shown');
check(announcement_date('2026-02-30') === null, 'Invalid date accepted');
check(!manual_announcement_visible(array_replace($manual, ['announcement_to'=>'2026-10-23']), at('2026-10-24')), 'Reversed dates accepted');
check(announcement_colour('red;display:none', '#ffffff') === '#ffffff', 'Unsafe colour accepted');
$event = ['id'=>1,'title'=>'Forest Ride','status'=>'published','event_date'=>'2026-11-15','entry_open_at'=>'2026-10-20 10:00:00','non_member_entry_open_at'=>'2026-10-23 10:00:00','entry_close_at'=>'2026-11-01 18:00:00'];
check(event_announcement([$event], at('2026-10-20 09:59:59')) === null, 'Opening starts early');
check(str_contains(event_announcement([$event], at('2026-10-20 10:00:00'))['text'], 'OPEN for members'), 'Member opening missing');
check(event_announcement([$event], at('2026-10-22 10:00:00')) === null, 'Opening exceeds 48 hours');
check(str_contains(event_announcement([$event], at('2026-10-23 10:00:00'))['text'], 'OPEN for non-members'), 'Non-member opening missing');
check(event_announcement([$event], at('2026-10-30 17:59:59')) === null, 'Closing starts early');
check(event_announcement([$event], at('2026-10-30 18:00:00'))['kind'] === 'closing', 'Closing window not inclusive');
check(event_announcement([$event], at('2026-11-01 18:00:00')) === null, 'Closed event shown');
$combined = array_replace($event, ['non_member_entry_open_at'=>$event['entry_open_at']]);
check(str_contains(event_announcement([$combined], at('2026-10-20 10:00:00'))['text'], 'members and non-members'), 'Simultaneous openings not combined');
$opening = array_replace($event, ['id'=>2, 'entry_open_at'=>'2026-10-30 19:00:00', 'non_member_entry_open_at'=>'2026-11-03 10:00:00', 'entry_close_at'=>'2026-11-10 18:00:00']);
check(event_announcement([$opening, $event], at('2026-10-30 20:00:00'))['event']['id'] === 1, 'Closing does not beat opening');
$laterClose = array_replace($event, ['id'=>3, 'entry_close_at'=>'2026-11-02 10:00:00']);
check(event_announcement([$laterClose, $event], at('2026-10-31 20:00:00'))['event']['id'] === 1, 'Nearest deadline not selected');
$recent = array_replace($event, ['id'=>4,'entry_open_at'=>'2026-10-21 10:00:00']);
check(event_announcement([$event,$recent], at('2026-10-21 11:00:00'))['event']['id'] === 4, 'Newest opening not selected');
check(event_announcement([array_replace($event,['status'=>'draft'])], at('2026-10-20 11:00:00')) === null, 'Draft event shown');
check(event_announcement([array_replace($event,['capacity_enabled'=>1,'capacity_limit'=>10,'entry_count'=>10])], at('2026-10-20 11:00:00')) === null, 'Full event shown');
$dst = array_replace($event, ['entry_open_at'=>'2026-10-24 10:00:00']);
check(event_announcement([$dst], at('2026-10-26 08:59:59')) !== null, 'DST window ended early');
check(event_announcement([$dst], at('2026-10-26 09:00:00')) === null, 'DST window not exactly 48 hours');
// When a newer notice expires, an older closing notice may still qualify.
check(event_announcement([$opening,$event], at('2026-11-01 18:00:00'))['event']['id'] === 2, 'Remaining opening did not resume');
echo "Announcement schedule, audience, priority, expiry, capacity and DST checks passed.\n";

$super = [];
foreach ($manual as $key => $value) $super['super_' . $key] = $value;
check(super_announcement_visible($super, at('2026-10-24')), 'Super notice not visible in window');
check(!super_announcement_visible($super, at('2026-10-26')), 'Super notice did not expire');
check(!super_announcement_visible(array_replace($super, ['super_announcement_enabled'=>'0']), at('2026-10-24')), 'Disabled super notice shown');
$token = super_announcement_token($super);
check($token === super_announcement_token(array_replace($super, ['super_announcement_body_html'=>'Edited copy'])), 'Copy edit unexpectedly resets hidden notices');
check($token !== super_announcement_token(array_replace($super, ['super_announcement_revision'=>'reset'])), 'Explicit reset does not reset hidden notices');
check($token !== super_announcement_token(array_replace($super, ['super_announcement_to'=>'2026-10-28'])), 'New schedule does not reset hidden notices');
echo "Super announcement schedule and dismissal reset checks passed.\n";

-- Add the live current-events section to the existing Ride Calendar CMS page.
INSERT INTO page_content_elements (page_id, name, heading, anchor_slug, body_html, content_type, layout, display_order, show_on_web, archived)
SELECT p.id, 'Current events calendar', 'Current events', 'current-events', NULL, 'current_events_calendar', 'text_only', 100, 1, 0
FROM pages p
WHERE p.slug = 'ride-calendar'
  AND NOT EXISTS (
      SELECT 1 FROM page_content_elements e
      WHERE e.page_id = p.id AND e.content_type = 'current_events_calendar'
  );

-- Move the existing FAQ display into a reusable dynamic content section.
INSERT INTO page_content_elements
    (page_id, name, heading, anchor_slug, body_html, content_type, layout, display_order, show_on_web, archived)
SELECT
    p.id, 'FAQs', NULL, 'faqs', NULL, 'faqs', 'text_only', 100, 1, 0
FROM pages p
WHERE (p.nav_group = 'faqs' OR p.slug IN ('faq', 'faqs'))
  AND NOT EXISTS (
      SELECT 1
      FROM page_content_elements e
      WHERE e.page_id = p.id
        AND e.content_type = 'faqs'
  );

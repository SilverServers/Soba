-- Migration 021: Seed default SEO options
INSERT IGNORE INTO options (option_key, option_value) VALUES
    ('seo_separator',            ' | '),
    ('seo_og_image',             ''),
    ('seo_homepage_title',       ''),
    ('seo_homepage_description', ''),
    ('seo_pattern_page',         '{title} | {site_name}'),
    ('seo_pattern_content_type', '{title} | {content_type_label} | {site_name}'),
    ('seo_pattern_archive',      '{content_type_label} | {site_name}'),
    ('seo_pattern_home',         '{site_name} | {tagline}'),
    ('seo_org_type',             'Organization'),
    ('seo_org_name',             ''),
    ('seo_org_url',              ''),
    ('seo_org_logo',             ''),
    ('seo_robots_txt',           'User-agent: *\nAllow: /\n\nSitemap: {site_url}/sitemap.xml'),
    ('seo_ping_on_publish',      '0');

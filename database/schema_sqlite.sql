CREATE TABLE IF NOT EXISTS categories (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS channels (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  youtube_channel_id TEXT NOT NULL UNIQUE,
  title TEXT NOT NULL,
  thumbnail_url TEXT NULL,
  subscriber_count INTEGER NULL,
  subscribers_hidden INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS videos (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  youtube_video_id TEXT NOT NULL UNIQUE,
  channel_id INTEGER NOT NULL,
  title TEXT NOT NULL,
  description TEXT NULL,
  view_count INTEGER NOT NULL DEFAULT 0,
  duration_seconds INTEGER NULL,
  video_type TEXT NOT NULL DEFAULT 'video',
  published_at TEXT NULL,
  youtube_url TEXT NOT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS videos_channel_id_idx ON videos(channel_id);
CREATE INDEX IF NOT EXISTS videos_view_count_idx ON videos(view_count);

CREATE TABLE IF NOT EXISTS leads (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL UNIQUE,
  category_id INTEGER NOT NULL,
  channel_id INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'discovered',
  unsubscribe_token TEXT NOT NULL UNIQUE,
  first_seen_at TEXT NOT NULL,
  last_seen_at TEXT NOT NULL,
  last_contacted_at TEXT NULL,
  unsubscribed_at TEXT NULL,
  notes TEXT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
  FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS leads_category_id_idx ON leads(category_id);
CREATE INDEX IF NOT EXISTS leads_channel_id_idx ON leads(channel_id);
CREATE INDEX IF NOT EXISTS leads_status_idx ON leads(status);

CREATE TABLE IF NOT EXISTS lead_sources (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  lead_id INTEGER NOT NULL,
  video_id INTEGER NOT NULL,
  source_url TEXT NOT NULL,
  found_context TEXT NULL,
  created_at TEXT NOT NULL,
  UNIQUE (lead_id, video_id),
  FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS lead_sources_video_id_idx ON lead_sources(video_id);

CREATE TABLE IF NOT EXISTS scrape_jobs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  niche TEXT NOT NULL,
  keywords TEXT NOT NULL,
  category_id INTEGER NOT NULL,
  min_views INTEGER NOT NULL DEFAULT 0,
  max_views INTEGER NULL,
  max_subscribers INTEGER NULL,
  video_type TEXT NOT NULL DEFAULT 'both',
  max_pages INTEGER NOT NULL DEFAULT 1,
  region_code TEXT NULL,
  relevance_language TEXT NULL,
  order_by TEXT NOT NULL DEFAULT 'relevance',
  published_after TEXT NULL,
  status TEXT NOT NULL DEFAULT 'pending',
  next_page_token TEXT NULL,
  pages_processed INTEGER NOT NULL DEFAULT 0,
  videos_checked INTEGER NOT NULL DEFAULT 0,
  emails_found INTEGER NOT NULL DEFAULT 0,
  error_message TEXT NULL,
  started_at TEXT NULL,
  finished_at TEXT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS scrape_jobs_status_idx ON scrape_jobs(status);

CREATE TABLE IF NOT EXISTS campaigns (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  category_id INTEGER NOT NULL,
  product_name TEXT NOT NULL,
  commission TEXT NOT NULL,
  subject TEXT NOT NULL,
  body_text TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'draft',
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS campaigns_category_id_idx ON campaigns(category_id);

CREATE TABLE IF NOT EXISTS email_queue (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  campaign_id INTEGER NOT NULL,
  lead_id INTEGER NOT NULL,
  recipient TEXT NOT NULL,
  subject TEXT NOT NULL,
  body_text TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'queued',
  attempts INTEGER NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  scheduled_at TEXT NOT NULL,
  sent_at TEXT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  UNIQUE (campaign_id, lead_id),
  FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
  FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS email_queue_status_idx ON email_queue(status, scheduled_at);

CREATE TABLE IF NOT EXISTS email_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  campaign_id INTEGER NOT NULL,
  lead_id INTEGER NOT NULL,
  event_type TEXT NOT NULL,
  meta TEXT NULL,
  created_at TEXT NOT NULL,
  FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
  FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS email_events_campaign_id_idx ON email_events(campaign_id);

CREATE TABLE IF NOT EXISTS suppression_list (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL UNIQUE,
  reason TEXT NULL,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key TEXT PRIMARY KEY,
  setting_value TEXT NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS channels (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  youtube_channel_id VARCHAR(80) NOT NULL UNIQUE,
  title VARCHAR(255) NOT NULL,
  thumbnail_url TEXT NULL,
  subscriber_count BIGINT UNSIGNED NULL,
  subscribers_hidden TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS videos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  youtube_video_id VARCHAR(40) NOT NULL UNIQUE,
  channel_id INT UNSIGNED NOT NULL,
  title VARCHAR(500) NOT NULL,
  description LONGTEXT NULL,
  view_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  duration_seconds INT UNSIGNED NULL,
  video_type VARCHAR(20) NOT NULL DEFAULT 'video',
  published_at DATETIME NULL,
  youtube_url VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX videos_channel_id_idx (channel_id),
  INDEX videos_view_count_idx (view_count),
  CONSTRAINT videos_channel_id_fk FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(254) NOT NULL UNIQUE,
  category_id INT UNSIGNED NOT NULL,
  channel_id INT UNSIGNED NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'discovered',
  unsubscribe_token VARCHAR(64) NOT NULL UNIQUE,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  last_contacted_at DATETIME NULL,
  unsubscribed_at DATETIME NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX leads_category_id_idx (category_id),
  INDEX leads_channel_id_idx (channel_id),
  INDEX leads_status_idx (status),
  CONSTRAINT leads_category_id_fk FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
  CONSTRAINT leads_channel_id_fk FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lead_sources (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_id INT UNSIGNED NOT NULL,
  video_id INT UNSIGNED NOT NULL,
  source_url VARCHAR(255) NOT NULL,
  found_context TEXT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY lead_sources_unique (lead_id, video_id),
  INDEX lead_sources_video_id_idx (video_id),
  CONSTRAINT lead_sources_lead_id_fk FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  CONSTRAINT lead_sources_video_id_fk FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scrape_jobs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id VARCHAR(40) NULL,
  niche VARCHAR(180) NOT NULL,
  keywords VARCHAR(500) NOT NULL,
  include_terms TEXT NULL,
  exclude_terms TEXT NULL,
  match_mode VARCHAR(20) NOT NULL DEFAULT 'any',
  category_id INT UNSIGNED NOT NULL,
  min_views BIGINT UNSIGNED NOT NULL DEFAULT 0,
  max_views BIGINT UNSIGNED NULL,
  max_subscribers BIGINT UNSIGNED NULL,
  video_type VARCHAR(20) NOT NULL DEFAULT 'both',
  max_pages INT UNSIGNED NOT NULL DEFAULT 1,
  region_code VARCHAR(5) NULL,
  relevance_language VARCHAR(8) NULL,
  order_by VARCHAR(30) NOT NULL DEFAULT 'relevance',
  published_after DATETIME NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'pending',
  next_page_token VARCHAR(100) NULL,
  pages_processed INT UNSIGNED NOT NULL DEFAULT 0,
  videos_checked INT UNSIGNED NOT NULL DEFAULT 0,
  emails_found INT UNSIGNED NOT NULL DEFAULT 0,
  error_message TEXT NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX scrape_jobs_batch_id_idx (batch_id),
  INDEX scrape_jobs_status_idx (status),
  CONSTRAINT scrape_jobs_category_id_fk FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaigns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(180) NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  product_name VARCHAR(180) NOT NULL,
  commission VARCHAR(80) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  body_text LONGTEXT NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'draft',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX campaigns_category_id_idx (category_id),
  CONSTRAINT campaigns_category_id_fk FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_queue (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT UNSIGNED NOT NULL,
  lead_id INT UNSIGNED NOT NULL,
  recipient VARCHAR(254) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  body_text LONGTEXT NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'queued',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  scheduled_at DATETIME NOT NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY email_queue_campaign_lead_unique (campaign_id, lead_id),
  INDEX email_queue_status_idx (status, scheduled_at),
  CONSTRAINT email_queue_campaign_id_fk FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
  CONSTRAINT email_queue_lead_id_fk FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT UNSIGNED NOT NULL,
  lead_id INT UNSIGNED NOT NULL,
  event_type VARCHAR(40) NOT NULL,
  meta TEXT NULL,
  created_at DATETIME NOT NULL,
  INDEX email_events_campaign_id_idx (campaign_id),
  CONSTRAINT email_events_campaign_id_fk FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
  CONSTRAINT email_events_lead_id_fk FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS suppression_list (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(254) NOT NULL UNIQUE,
  reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value TEXT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

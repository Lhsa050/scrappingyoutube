<?php

declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/SettingsRepository.php';
require_once __DIR__ . '/UpdaterService.php';
require_once __DIR__ . '/EmailExtractor.php';
require_once __DIR__ . '/YouTubeClient.php';
require_once __DIR__ . '/LeadRepository.php';
require_once __DIR__ . '/CampaignRepository.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/ScrapeService.php';

Config::load(dirname(__DIR__));
date_default_timezone_set(Config::timezone());
Database::migrate();

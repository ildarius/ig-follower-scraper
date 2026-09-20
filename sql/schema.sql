-- ig-follower-scraper schema
-- Database: sunny_kratom
-- Run in HeidiSQL: File -> Load SQL file -> this file -> F9
--
-- utf8mb4 throughout. Instagram full names contain emoji, and utf8 (3 byte) silently
-- truncates them or throws "Incorrect string value" on insert.

CREATE DATABASE IF NOT EXISTS `sunny_kratom`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `sunny_kratom`;

-- ---------------------------------------------------------------------------
-- One row per Instagram account we have ever seen, plus its follow state.
-- This table IS the follow queue for the instagram-follower Playwright script.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ig_accounts` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ig_user_id`          VARCHAR(32)     NOT NULL,
  `username`            VARCHAR(255)    NOT NULL,
  `full_name`           VARCHAR(255)        NULL,
  `profile_pic_url`     TEXT                NULL,
  `is_verified`         TINYINT(1)      NOT NULL DEFAULT 0,
  `is_private`          TINYINT(1)      NOT NULL DEFAULT 0,
  `first_seen_at`       DATETIME        NOT NULL,
  `last_seen_at`        DATETIME        NOT NULL,

  -- Follow state. Written by the instagram-follower Playwright script,
  -- never reset by a re-scrape.
  `follow_status`       ENUM('pending','followed','requested','already_following','error','skipped')
                        NOT NULL DEFAULT 'pending',
  `follow_attempted_at` DATETIME            NULL COMMENT 'America/New_York wall time, written by PHP not MySQL',
  `follow_note`         VARCHAR(255)        NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ig_user_id` (`ig_user_id`),
  KEY `idx_username` (`username`),
  KEY `idx_queue` (`follow_status`, `is_private`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Which source account each profile was found under, and in which direction.
-- Separate from ig_accounts so the same profile can be a follower of one
-- account and a following of another without duplicating the profile row.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ig_relations` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source_username` VARCHAR(255)    NOT NULL,
  `relation`        ENUM('FOLLOWER','FOLLOWING') NOT NULL,
  `ig_user_id`      VARCHAR(32)     NOT NULL,
  `scraped_at`      DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_relation` (`source_username`, `relation`, `ig_user_id`),
  KEY `idx_ig_user_id` (`ig_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- One row per Apify actor run. cursor_offset makes the import resumable:
-- a browser request that times out mid-import can be re-issued and picks up
-- where it stopped instead of re-inserting from zero.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ig_scrape_runs` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_id`        VARCHAR(128)    NOT NULL,
  `apify_run_id`    VARCHAR(64)         NULL,
  `dataset_id`      VARCHAR(64)         NULL,
  `source_username` VARCHAR(255)    NOT NULL,
  `data_to_scrape`  VARCHAR(32)     NOT NULL,
  `results_limit`   INT UNSIGNED        NULL,
  `status`          VARCHAR(32)     NOT NULL DEFAULT 'CREATED',
  `started_at`      DATETIME        NOT NULL,
  `finished_at`     DATETIME            NULL,
  `items_total`     INT UNSIGNED    NOT NULL DEFAULT 0,
  `items_imported`  INT UNSIGNED    NOT NULL DEFAULT 0,
  `cursor_offset`   INT UNSIGNED    NOT NULL DEFAULT 0,
  `error`           TEXT                NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_apify_run_id` (`apify_run_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Every dataset item exactly as Apify returned it, unparsed.
-- The mapped tables above only keep the fields we currently use. This one keeps
-- the whole payload so a question we have not thought of yet can be answered
-- without paying for another scrape.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ig_raw_items` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id`      BIGINT UNSIGNED NOT NULL,
  `ig_user_id`  VARCHAR(32)         NULL,
  `payload`     JSON            NOT NULL,
  `imported_at` DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_run_user` (`run_id`, `ig_user_id`),
  KEY `idx_raw_ig_user_id` (`ig_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Profile statistics from memo23/instagram-followers-count-scraper.
-- Kept separate from ig_accounts because it is refreshed on its own schedule
-- and costs money per row, while ig_accounts is the follow queue.
-- One row per account; re-enriching updates in place.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ig_profile_stats` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ig_user_id`      VARCHAR(32)     NOT NULL,
  `username`        VARCHAR(255)    NOT NULL,
  `user_full_name`  VARCHAR(255)        NULL,
  `followers_count` INT UNSIGNED        NULL,
  `follows_count`   INT UNSIGNED        NULL,
  `posts_count`     INT UNSIGNED        NULL,
  `is_private`      TINYINT(1)          NULL,
  `is_verified`     TINYINT(1)          NULL,
  `is_business`     TINYINT(1)          NULL,
  `biography`       TEXT                NULL,
  `external_url`    TEXT                NULL,
  `public_email`    VARCHAR(255)        NULL,
  `public_phone`    VARCHAR(64)         NULL,
  `category`        VARCHAR(255)        NULL,
  `fb_id`           VARCHAR(64)         NULL,
  `location_id`     VARCHAR(64)         NULL,
  `account_type`    INT                 NULL,
  `profile_pic`     TEXT                NULL,
  `user_url`        VARCHAR(255)        NULL,
  `scraped_at`      DATETIME            NULL,
  `run_id`          BIGINT UNSIGNED     NULL,
  `enriched_at`     DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_stats_user` (`ig_user_id`),
  KEY `idx_username` (`username`),
  KEY `idx_followers` (`followers_count`),
  KEY `idx_posts` (`posts_count`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ig_scrape_runs gains a `kind` so follower runs and profile-enrichment runs
-- can share one table: 'followers' or 'profiles'.
-- ALTER TABLE `ig_scrape_runs` ADD COLUMN `kind` VARCHAR(16) NOT NULL DEFAULT 'followers' AFTER `actor_id`;

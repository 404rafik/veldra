<?php

declare(strict_types=1);

namespace Veldra\Database;

/**
 * Database schema migrator and aggregation engine.
 *
 * Creates all custom tables on plugin activation.
 * Runs nightly aggregation from raw → summary tables.
 * Handles retention-based pruning of raw data.
 */
class Migrator
{
    private const TABLE_PAGEVIEWS = 'veldra_pageviews';
    private const TABLE_EVENTS    = 'veldra_events';
    private const TABLE_SUMMARY   = 'veldra_daily_summary';

    /**
     * Called on plugin activation. Creates or upgrades tables.
     */
    public static function activate(): void
    {
        self::create_tables();
    }

    /**
     * Called on plugin deactivation. Cleans up cron jobs.
     */
    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled('veldra_daily_aggregate');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'veldra_daily_aggregate');
        }
    }

    /**
     * Create all custom database tables.
     */
    public static function create_tables(): void
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $tables = [
            self::pageviews_table_sql($wpdb->prefix, $charset_collate),
            self::events_table_sql($wpdb->prefix, $charset_collate),
            self::summary_table_sql($wpdb->prefix, $charset_collate),
        ];

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach ($tables as $sql) {
            dbDelta($sql);
        }
    }

    /**
     * SQL for the core pageviews table.
     */
    private static function pageviews_table_sql(string $prefix, string $charset_collate): string
    {
        $table = $prefix . self::TABLE_PAGEVIEWS;

        return "CREATE TABLE {$table} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            date          DATE            NOT NULL,
            path          VARCHAR(2048)   NOT NULL,
            session_hash  CHAR(64)        NOT NULL COMMENT 'Daily-salted SHA-256, never stored raw IP',
            referrer_host VARCHAR(255)    DEFAULT NULL,
            country_code  CHAR(2)         DEFAULT NULL,
            city          VARCHAR(100)    DEFAULT NULL,
            device_type   ENUM('desktop','mobile','tablet') NOT NULL DEFAULT 'desktop',
            browser_family VARCHAR(50)    DEFAULT NULL,
            utm_source    VARCHAR(255)    DEFAULT NULL,
            utm_medium    VARCHAR(255)    DEFAULT NULL,
            utm_campaign  VARCHAR(255)    DEFAULT NULL,
            entry_ms      BIGINT UNSIGNED NOT NULL COMMENT 'Page-load timestamp (epoch ms), used to match heartbeats',
            duration_ms   INT UNSIGNED    DEFAULT NULL COMMENT 'Session duration in ms, set by exit heartbeat',
            is_bounce     TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 if session had 1 pageview and duration < 30s',
            PRIMARY KEY (id),
            INDEX idx_session_entry (session_hash, entry_ms),
            INDEX idx_date (date)
        ) {$charset_collate} ENGINE=InnoDB;";
    }

    /**
     * SQL for the custom goal events table.
     */
    private static function events_table_sql(string $prefix, string $charset_collate): string
    {
        $table = $prefix . self::TABLE_EVENTS;

        return "CREATE TABLE {$table} (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            date         DATE            NOT NULL,
            event_name   VARCHAR(100)    NOT NULL,
            path         VARCHAR(2048)   NOT NULL,
            session_hash CHAR(64)        NOT NULL,
            PRIMARY KEY (id),
            INDEX idx_date_event (date, event_name)
        ) {$charset_collate} ENGINE=InnoDB;";
    }

    /**
     * SQL for the anonymous daily summary table.
     *
     * This table contains NO session_hash and NO individual identifiers.
     * It is genuinely anonymous under GDPR Recital 26 and is retained indefinitely.
     */
    private static function summary_table_sql(string $prefix, string $charset_collate): string
    {
        $table = $prefix . self::TABLE_SUMMARY;

        return "CREATE TABLE {$table} (
            date           DATE                                NOT NULL,
            path           VARCHAR(2048)                       NOT NULL DEFAULT '',
            country_code   CHAR(2)                             NOT NULL DEFAULT '',
            device_type    ENUM('desktop','mobile','tablet','') NOT NULL DEFAULT '',
            browser_family VARCHAR(50)                         NOT NULL DEFAULT '',
            referrer_host  VARCHAR(255)                        NOT NULL DEFAULT '',
            utm_source     VARCHAR(255)                        NOT NULL DEFAULT '',
            utm_campaign   VARCHAR(255)                        NOT NULL DEFAULT '',
            sessions       INT UNSIGNED                        NOT NULL DEFAULT 0,
            pageviews      INT UNSIGNED                        NOT NULL DEFAULT 0,
            bounces        INT UNSIGNED                        NOT NULL DEFAULT 0,
            total_duration_ms BIGINT UNSIGNED                   NOT NULL DEFAULT 0,
            PRIMARY KEY (date, path(255), country_code, device_type, browser_family, referrer_host(100), utm_source(100), utm_campaign(100)),
            INDEX idx_date (date)
        ) {$charset_collate} ENGINE=InnoDB;";
    }

    /**
     * Run the nightly aggregation + pruning pipeline.
     *
     * This is called by WP-Cron at 00:15 UTC daily.
     * Sequence: aggregate yesterday's raw data → delete expired raw rows.
     * BOTH operations must complete. Never disableable.
     */
    public static function run_aggregation(): void
    {
        global $wpdb;

        $prefix        = $wpdb->prefix;
        $pageviews_tbl = $prefix . self::TABLE_PAGEVIEWS;
        $events_tbl    = $prefix . self::TABLE_EVENTS;
        $summary_tbl   = $prefix . self::TABLE_SUMMARY;

        $yesterday = current_time('Y-m-d', true); // Today at ~00:15 UTC, but we aggregate "the day before"

        // 1. Aggregate pageviews into daily summary (yesterday's data)
        // Use date_sub to get yesterday
        $agg_date = gmdate('Y-m-d', strtotime('-1 day'));

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // Session-level stats: total pageviews and max duration per session
        // A bounce = session with exactly 1 pageview AND (duration < 30s OR unknown)
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$summary_tbl}
                (date, path, country_code, device_type, browser_family, referrer_host, utm_source, utm_campaign, sessions, pageviews, bounces, total_duration_ms)
            SELECT
                p.date,
                COALESCE(p.path, ''),
                COALESCE(p.country_code, ''),
                COALESCE(p.device_type, ''),
                COALESCE(p.browser_family, ''),
                COALESCE(p.referrer_host, ''),
                COALESCE(p.utm_source, ''),
                COALESCE(p.utm_campaign, ''),
                COUNT(DISTINCT p.session_hash) AS sessions,
                COUNT(*) AS pageviews,
                COUNT(DISTINCT CASE WHEN s.total_pv = 1 AND (p.duration_ms IS NULL OR p.duration_ms < 30000) THEN p.session_hash END) AS bounces,
                COALESCE(SUM(p.duration_ms), 0) AS total_duration_ms
            FROM {$pageviews_tbl} p
            INNER JOIN (
                SELECT session_hash, date, COUNT(*) AS total_pv
                FROM {$pageviews_tbl}
                WHERE date = %s
                GROUP BY session_hash, date
            ) s ON p.session_hash = s.session_hash AND p.date = s.date
            WHERE p.date = %s
            GROUP BY p.date, p.path, p.country_code, p.device_type, p.browser_family, p.referrer_host, p.utm_source, p.utm_campaign
            ON DUPLICATE KEY UPDATE
                sessions          = VALUES(sessions),
                pageviews         = VALUES(pageviews),
                bounces           = VALUES(bounces),
                total_duration_ms = VALUES(total_duration_ms)",
            $agg_date,
            $agg_date
        ));

        // 2. Determine retention period based on license tier
        // Free = 90 days, Premium = 13 months
        // Check for premium license key
        $is_premium  = (bool) get_option('veldra_premium_key', false);
        $retain_days = $is_premium ? 395 : 90; // 13 months ≈ 395 days

        $cutoff = gmdate('Y-m-d', strtotime("-{$retain_days} days"));

        // 3. Delete expired raw pageviews
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$pageviews_tbl} WHERE date < %s",
            $cutoff
        ));

        // 4. Delete expired events
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$events_tbl} WHERE date < %s",
            $cutoff
        ));
        // phpcs:enable

        // 5. Sync to cloud if premium license is configured
        $premium_sync = new \Veldra\Cloud\PremiumSync();
        $premium_sync->sync();
    }
}

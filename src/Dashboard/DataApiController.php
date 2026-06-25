<?php

declare(strict_types=1);

namespace Veldra\Dashboard;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Internal REST API endpoints for the dashboard charts.
 *
 * Route: GET /wp-json/veldra/v1/data
 * Returns aggregated data from veldra_daily_summary — never queries raw tables.
 *
 * @internal Only used by the WP-Admin dashboard.
 */
class DataApiController
{
    private const ROUTE_NAMESPACE = 'veldra/v1';
    private const ROUTE_DATA      = '/data';
    private const MAX_RANGE_DAYS  = 3650; // 10 years (supports multi-year comparisons)

    /**
     * Register the data API route.
     */
    public function register_routes(): void
    {
        register_rest_route(self::ROUTE_NAMESPACE, self::ROUTE_DATA, [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_data'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => [
                'start' => [
                    'required'          => true,
                    'type'              => 'string',
                    'format'            => 'date',
                ],
                'end'   => [
                    'required'          => true,
                    'type'              => 'string',
                    'format'            => 'date',
                ],
            ],
        ]);
    }

    /**
     * Check that the request comes from an admin user.
     */
    public function check_permission(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Handle a data request for the dashboard.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_data(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $start = sanitize_text_field($request->get_param('start'));
        $end   = sanitize_text_field($request->get_param('end'));

        // Validate and constrain date range
        $start_ts = strtotime($start);
        $end_ts   = strtotime($end);

        if (!$start_ts || !$end_ts || $end_ts < $start_ts) {
            return new WP_REST_Response(['error' => 'Invalid date range'], 400);
        }

        $days_diff = ($end_ts - $start_ts) / DAY_IN_SECONDS;
        if ($days_diff > self::MAX_RANGE_DAYS) {
            return new WP_REST_Response(['error' => 'Date range too large'], 400);
        }

        $summary_tbl = $wpdb->prefix . 'veldra_daily_summary';

        // 1. Daily traffic overview
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $traffic = $wpdb->get_results($wpdb->prepare(
            "SELECT date, SUM(sessions) AS visits, SUM(pageviews) AS pageviews
            FROM {$summary_tbl}
            WHERE date >= %s AND date <= %s AND path = ''
            GROUP BY date
            ORDER BY date ASC",
            $start,
            $end
        ));

        // 2. Top content (by pageviews)
        $top_content = $wpdb->get_results($wpdb->prepare(
            "SELECT path, SUM(sessions) AS visits, SUM(pageviews) AS pageviews
            FROM {$summary_tbl}
            WHERE date >= %s AND date <= %s AND path != ''
            GROUP BY path
            ORDER BY pageviews DESC
            LIMIT 20",
            $start,
            $end
        ));

        // 3. Referrers
        $referrers = $wpdb->get_results($wpdb->prepare(
            "SELECT referrer_host, SUM(sessions) AS visits, SUM(pageviews) AS pageviews
            FROM {$summary_tbl}
            WHERE date >= %s AND date <= %s AND referrer_host != ''
            GROUP BY referrer_host
            ORDER BY pageviews DESC
            LIMIT 15",
            $start,
            $end
        ));

        // 4. Device breakdown
        $devices = $wpdb->get_results($wpdb->prepare(
            "SELECT device_type, SUM(sessions) AS visits, SUM(pageviews) AS pageviews
            FROM {$summary_tbl}
            WHERE date >= %s AND date <= %s AND device_type != ''
            GROUP BY device_type
            ORDER BY pageviews DESC",
            $start,
            $end
        ));

        // 5. Countries
        $countries = $wpdb->get_results($wpdb->prepare(
            "SELECT country_code, SUM(sessions) AS visits, SUM(pageviews) AS pageviews
            FROM {$summary_tbl}
            WHERE date >= %s AND date <= %s AND country_code != ''
            GROUP BY country_code
            ORDER BY pageviews DESC
            LIMIT 20",
            $start,
            $end
        ));
        // phpcs:enable

        // 6. Overview totals
        $overview = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(DISTINCT date) AS days,
                    COALESCE(SUM(sessions), 0) AS total_visits,
                    COALESCE(SUM(pageviews), 0) AS total_pageviews,
                    COALESCE(SUM(bounces), 0) AS total_bounces,
                    COALESCE(SUM(total_duration_ms), 0) AS total_duration_ms
            FROM {$summary_tbl}
            WHERE date >= %s AND date <= %s AND path = ''",
            $start,
            $end
        ));

        // Compute derived metrics
        $bounce_rate    = $overview && $overview->total_visits > 0
            ? round(($overview->total_bounces / $overview->total_visits) * 100, 1)
            : 0.0;
        $avg_duration_s = $overview && $overview->total_visits > 0
            ? round($overview->total_duration_ms / $overview->total_visits / 1000, 1)
            : 0.0;

        return new WP_REST_Response([
            'traffic'      => $traffic,
            'content'      => $top_content,
            'referrers'    => $referrers,
            'devices'      => $devices,
            'countries'    => $countries,
            'overview'     => $overview,
            'bounce_rate'  => $bounce_rate,
            'avg_duration' => $avg_duration_s,
        ]);
    }
}

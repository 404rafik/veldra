<?php

declare(strict_types=1);

namespace Veldra\Cloud;

/**
 * Premium-tier cloud sync client.
 *
 * Sends aggregated daily analytics data from the WordPress site's
 * veldra_daily_summary table to the Veldra EU cloud endpoint.
 * Only pre-aggregated, anonymised data is transmitted — never raw pageviews.
 *
 * The cloud endpoint stores data in PostgreSQL (Hetzner Frankfurt / OVH France).
 */
class PremiumSync
{
    /**
     * Default cloud endpoint URL (configurable).
     * Hetzner Frankfurt primary — OVH Gravelines failover.
     */
    private const DEFAULT_ENDPOINT = 'https://cloud.veldra.dev/api/v1/track';

    /**
     * Sync yesterday's aggregated data to the cloud.
     *
     * Called by the nightly WP-Cron job after local aggregation completes.
     *
     * @return array{success: bool, message: string}
     */
    public function sync(): array
    {
        $premium_key = get_option('veldra_premium_key', '');

        if (empty($premium_key)) {
            return [
                'success' => false,
                'message' => 'No premium license key configured.',
            ];
        }

        $endpoint = get_option('veldra_cloud_endpoint', self::DEFAULT_ENDPOINT);

        if (!is_string($endpoint) || $endpoint === '') {
            $endpoint = self::DEFAULT_ENDPOINT;
        }

        $yesterday = gmdate('Y-m-d', strtotime('-1 day'));
        $data = $this->get_aggregated_data($yesterday);

        if (empty($data)) {
            return [
                'success' => true,
                'message' => 'No data to sync for ' . $yesterday,
            ];
        }

        $payload = [
            'site_url' => get_site_url(),
            'date'     => $yesterday,
            'data'     => $data,
        ];

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $premium_key,
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 30,
            'blocking' => true,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => 'Sync failed: ' . $response->get_error_message(),
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code >= 200 && $status_code < 300) {
            // Update last sync timestamp
            update_option('veldra_last_sync', current_time('mysql'));

            return [
                'success' => true,
                'message' => "Synced " . count($data) . " rows for {$yesterday}.",
            ];
        }

        $body = wp_remote_retrieve_body($response);

        return [
            'success' => false,
            'message' => "Cloud endpoint returned {$status_code}: " . substr($body, 0, 200),
        ];
    }

    /**
     * Get aggregated data from the summary table for a given date.
     *
     * @param string $date The date in Y-m-d format.
     * @return array<int, array<string, mixed>>
     */
    private function get_aggregated_data(string $date): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'veldra_daily_summary';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT path, country_code, device_type, browser_family,
                    referrer_host, utm_source, utm_campaign,
                    sessions, pageviews, bounces
            FROM {$table}
            WHERE date = %s",
            $date
        ), ARRAY_A);
        // phpcs:enable

        return is_array($results) ? $results : [];
    }
}

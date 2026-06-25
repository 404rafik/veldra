<?php

declare(strict_types=1);

namespace Veldra\Tracker;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST API endpoint for tracking pageviews and events.
 *
 * Route: POST /wp-json/veldra/v1/track
 * Rate-limited: max 10 requests per IP per 10 seconds.
 * Returns 204 No Content on success — no data echoed to client.
 */
class RestEndpoint
{
    private const ROUTE_NAMESPACE = 'veldra/v1';
    private const ROUTE_TRACK     = '/track';
    private const RATE_LIMIT_KEY  = 'veldra_rate_limit_';
    private const RATE_MAX        = 10;
    private const RATE_WINDOW     = 10; // seconds

    private SessionHasher $hasher;
    private GeoResolver   $geo;

    public function __construct()
    {
        $this->hasher = new SessionHasher();
        $this->geo    = new GeoResolver();
    }

    /**
     * Register the REST API route.
     */
    public function register_routes(): void
    {
        register_rest_route(self::ROUTE_NAMESPACE, self::ROUTE_TRACK, [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_track'],
            'permission_callback' => '__return_true',
            'args'                => $this->get_args_schema(),
        ]);
    }

    /**
     * Handle an incoming tracking request.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_track(WP_REST_Request $request): WP_REST_Response
    {
        // 1. Rate limit check
        $client_ip = $this->get_client_ip();
        if ($this->is_rate_limited($client_ip)) {
            return new WP_REST_Response(null, 429);
        }

        // 2. Validate payload
        $params = $request->get_json_params();
        if (!is_array($params)) {
            return new WP_REST_Response(null, 400);
        }

        $path       = $this->sanitize_path($params['path'] ?? '');
        $referrer   = $this->sanitize_referrer($params['referrer'] ?? '');
        $user_agent = $this->get_user_agent();
        $entry_ms   = (int) ($params['ts'] ?? 0);
        $duration_ms = isset($params['duration_ms']) ? (int) $params['duration_ms'] : null;

        // If this is a heartbeat (duration_ms present), UPDATE the existing pageview row
        if ($duration_ms !== null) {
            $this->handle_heartbeat($entry_ms, $user_agent, $client_ip, $duration_ms);
            return new WP_REST_Response(null, 204);
        }

        if (empty($path)) {
            return new WP_REST_Response(null, 400);
        }

        // 3. Geolocate (IP read transiently, never stored)
        $geo_data = $this->geo->resolve($client_ip);

        // 4. Session hash (IP + UA + daily salt, never stored raw)
        $session_hash = $this->hasher->hash($client_ip, $user_agent);

        // 5. Parse device info from UA
        $device_info = $this->parse_device($user_agent);

        // 6. Parse UTM parameters
        $utm = $this->parse_utm($params);

        // 7. Persist
        $this->store_pageview(
            $path,
            $session_hash,
            $referrer,
            $geo_data['country_code'],
            $geo_data['city'],
            $device_info['device_type'],
            $device_info['browser_family'],
            $utm['source'],
            $utm['medium'],
            $utm['campaign'],
            $entry_ms
        );

        return new WP_REST_Response(null, 204);
    }

    /**
     * Store a pageview record in the database.
     */
    private function store_pageview(
        string $path,
        string $session_hash,
        string $referrer_host,
        string $country_code,
        string $city,
        string $device_type,
        string $browser_family,
        string $utm_source,
        string $utm_medium,
        string $utm_campaign,
        int $entry_ms
    ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'veldra_pageviews';

        $wpdb->insert(
            $table,
            [
                'date'           => current_time('Y-m-d'),
                'path'           => $path,
                'session_hash'   => $session_hash,
                'referrer_host'  => $referrer_host,
                'country_code'   => $country_code,
                'city'           => $city,
                'device_type'    => $device_type,
                'browser_family' => $browser_family,
                'utm_source'     => $utm_source,
                'utm_medium'     => $utm_medium,
                'utm_campaign'   => $utm_campaign,
                'entry_ms'       => $entry_ms,
            ],
            [
                '%s', '%s', '%s', '%s', '%s',
                '%s', '%s', '%s', '%s', '%s', '%s', '%d',
            ]
        );
    }

    /**
     * Handle a heartbeat request — match by session_hash + entry_ms and set duration/bounce.
     */
    private function handle_heartbeat(int $entry_ms, string $user_agent, string $client_ip, int $duration_ms): void
    {
        global $wpdb;

        $table        = $wpdb->prefix . 'veldra_pageviews';
        $session_hash = $this->hasher->hash($client_ip, $user_agent);
        $is_bounce    = ($duration_ms < 30000) ? 1 : 0;

        // Best-effort UPDATE — if the initial pageview arrived already (likely) the
        // heartbeat matches on session_hash + entry_ms. If the INSERT raced the
        // heartbeat (edge case on very fast navigations), we INSERT the row here.
        $updated = $wpdb->update(
            $table,
            [
                'duration_ms' => $duration_ms,
                'is_bounce'   => $is_bounce,
            ],
            [
                'session_hash' => $session_hash,
                'entry_ms'     => $entry_ms,
            ],
            ['%d', '%d'],
            ['%s', '%d']
        );

        // No row matched — likely a race condition where the heartbeat arrived first.
        // Insert a minimal row with the duration data so we don't lose the heartbeat.
        if ($updated === 0) {
            $wpdb->insert(
                $table,
                [
                    'date'         => current_time('Y-m-d'),
                    'path'         => '(heartbeat)',
                    'session_hash' => $session_hash,
                    'entry_ms'     => $entry_ms,
                    'duration_ms'  => $duration_ms,
                    'is_bounce'    => $is_bounce,
                ],
                ['%s', '%s', '%s', '%d', '%d', '%d']
            );
        }
    }

    /**
     * Check rate limit using transient-based token bucket.
     */
    private function is_rate_limited(string $ip): bool
    {
        $key    = self::RATE_LIMIT_KEY . $ip;
        $count  = (int) get_transient($key);

        if ($count >= self::RATE_MAX) {
            return true;
        }

        if ($count === 0) {
            set_transient($key, 1, self::RATE_WINDOW);
        } else {
            set_transient($key, $count + 1, self::RATE_WINDOW);
        }

        return false;
    }

    /**
     * Get the client IP from the request, respecting reverse proxy headers.
     */
    private function get_client_ip(): string
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])));
            return trim((string) $ips[0]);
        }

        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            return sanitize_text_field(wp_unslash($_SERVER['HTTP_X_REAL_IP']));
        }

        if (!empty($_SERVER['REMOTE_ADDR'])) {
            return sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        return '127.0.0.1';
    }

    /**
     * Get the User-Agent string.
     */
    private function get_user_agent(): string
    {
        if (!empty($_SERVER['HTTP_USER_AGENT'])) {
            return sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']));
        }

        return '';
    }

    /**
     * Sanitize and truncate the path.
     */
    private function sanitize_path(string $path): string
    {
        $clean = sanitize_text_field(wp_unslash($path));
        return substr($clean, 0, 2048);
    }

    /**
     * Extract the host from a referrer URL.
     */
    private function sanitize_referrer(string $referrer): string
    {
        $host = wp_parse_url($referrer, PHP_URL_HOST);
        return is_string($host) ? substr($host, 0, 255) : '';
    }

    /**
     * Parse device type and browser family from User-Agent.
     *
     * @return array{device_type: string, browser_family: string}
     */
    private function parse_device(string $ua): array
    {
        $ua_lower = strtolower($ua);

        // Device type
        if (preg_match('/android.+mobile|iphone|ipod|blackberry|opera mini|iemobile/i', $ua_lower)) {
            $device_type = 'mobile';
        } elseif (preg_match('/ipad|android(?!.*mobile)|tablet|kindle/i', $ua_lower)) {
            $device_type = 'tablet';
        } else {
            $device_type = 'desktop';
        }

        // Browser family
        $browser = 'Unknown';
        if (str_contains($ua_lower, 'edg/') || str_contains($ua_lower, 'edge/')) {
            $browser = 'Edge';
        } elseif (str_contains($ua_lower, 'opr/') || str_contains($ua_lower, 'opera')) {
            $browser = 'Opera';
        } elseif (preg_match('/(?:firefox\/)/i', $ua_lower)) {
            $browser = 'Firefox';
        } elseif (preg_match('/(?:chrome\/|chromium\/)/i', $ua_lower) && !str_contains($ua_lower, 'edg/')) {
            $browser = 'Chrome';
        } elseif (preg_match('/(?:safari\/)/i', $ua_lower) && !preg_match('/(?:chrome|chromium)/i', $ua_lower)) {
            $browser = 'Safari';
        }

        return [
            'device_type'    => $device_type,
            'browser_family' => $browser,
        ];
    }

    /**
     * Parse UTM parameters from the tracking payload.
     *
     * @param array<string, mixed> $params
     * @return array{source: string, medium: string, campaign: string}
     */
    private function parse_utm(array $params): array
    {
        return [
            'source'   => $this->sanitize_utm($params['utm_source'] ?? ''),
            'medium'   => $this->sanitize_utm($params['utm_medium'] ?? ''),
            'campaign' => $this->sanitize_utm($params['utm_campaign'] ?? ''),
        ];
    }

    private function sanitize_utm(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        return substr(sanitize_text_field($value), 0, 255);
    }

    /**
     * Argument schema for the REST endpoint.
     *
     * @return array<string, array<string, mixed>>
     */
    private function get_args_schema(): array
    {
        return [
            'path' => [
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'referrer' => [
                'type'              => 'string',
                'sanitize_callback' => 'esc_url_raw',
            ],
            'title' => [
                'type' => 'string',
            ],
            'screen' => [
                'type' => 'string',
            ],
            'viewport' => [
                'type' => 'string',
            ],
            'utm_source' => [
                'type' => 'string',
            ],
            'utm_medium' => [
                'type' => 'string',
            ],
            'utm_campaign' => [
                'type' => 'string',
            ],
            'ts' => [
                'required'          => true,
                'type'              => 'integer',
            ],
            'duration_ms' => [
                'required'          => false,
                'type'              => 'integer',
            ],
        ];
    }
}

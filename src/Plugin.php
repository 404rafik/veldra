<?php

declare(strict_types=1);

namespace Veldra;

use Veldra\Tracker\RestEndpoint;
use Veldra\Database\Migrator;
use Veldra\Dashboard\AdminPage;
use Veldra\Dashboard\DataApiController;
use Veldra\Cloud\PremiumSync;

/**
 * Main plugin bootstrap.
 *
 * Registers all hooks and initializes components.
 * Follows the singleton pattern — one instance per request.
 */
class Plugin
{
    private static ?Plugin $instance = null;

    /** @var array<string, object> Registered components */
    private array $components = [];

    public static function get_instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize all plugin components.
     */
    public function init(): void
    {
        $this->register_components();
        $this->register_hooks();
    }

    /**
     * Register internal component instances.
     */
    private function register_components(): void
    {
        $this->components['rest_endpoint'] = new RestEndpoint();
        $this->components['data_api'] = new DataApiController();
        $this->components['admin_page'] = new AdminPage();
        $this->components['premium_sync'] = new PremiumSync();
    }

    /**
     * Register WordPress hooks.
     */
    private function register_hooks(): void
    {
        // REST API
        add_action('rest_api_init', [$this->components['rest_endpoint'], 'register_routes']);
        add_action('rest_api_init', [$this->components['data_api'], 'register_routes']);

        // Admin
        add_action('admin_menu', [$this->components['admin_page'], 'register_menu']);
        add_action('admin_enqueue_scripts', [$this->components['admin_page'], 'enqueue_assets']);

        // Tracking script injection
        add_action('wp_footer', [$this, 'inject_tracker'], 100);

        // Cron schedules
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);
        add_action('veldra_daily_aggregate', [Migrator::class, 'run_aggregation']);

        // Schedule daily cron on init if not already scheduled
        if (!wp_next_scheduled('veldra_daily_aggregate')) {
            wp_schedule_event(strtotime('00:15:00 UTC'), 'veldra_daily', 'veldra_daily_aggregate');
        }
    }

    /**
     * Inject the tracking script into the frontend footer.
     */
    public function inject_tracker(): void
    {
        $tracker_url = plugins_url('build/tracker.min.js', VELDRA_PLUGIN_FILE);

        // Pass REST URL and site config to the script
        $config = [
            'endpoint' => rest_url('veldra/v1/track'),
            'site'     => parse_url(get_site_url(), PHP_URL_HOST),
        ];

        printf(
            '<script data-veldra-ignore>window.VELDRA_CONFIG=%s;</script>' . "\n",
            wp_json_encode($config)
        );
        printf(
            '<script src="%s" defer data-veldra-tracker></script>' . "\n",
            esc_url($tracker_url)
        );
    }

    /**
     * Add custom cron schedules.
     *
     * @param array<string, array<string, int|string>> $schedules
     * @return array<string, array<string, int|string>>
     */
    public function add_cron_schedules(array $schedules): array
    {
        $schedules['veldra_daily'] = [
            'interval' => DAY_IN_SECONDS,
            'display'  => __('Once Daily (00:15 UTC)', 'veldra'),
        ];
        return $schedules;
    }

    /**
     * Get a registered component by name.
     */
    public function get_component(string $name): ?object
    {
        return $this->components[$name] ?? null;
    }
}

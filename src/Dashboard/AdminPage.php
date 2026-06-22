<?php

declare(strict_types=1);

namespace Veldra\Dashboard;

/**
 * WP-Admin dashboard page for Veldra analytics.
 *
 * Registers the admin menu, enqueues assets, and renders the
 * main analytics dashboard screen. All charts are rendered
 * client-side using Chart.js.
 */
class AdminPage
{
    private const PAGE_SLUG  = 'veldra-dashboard';
    private const SETTINGS_SLUG = 'veldra-settings';

    /**
     * Register the admin menu pages.
     */
    public function register_menu(): void
    {
        add_menu_page(
            __('Veldra Analytics', 'veldra'),
            __('Veldra', 'veldra'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_dashboard'],
            'dashicons-chart-area',
            30
        );

        add_submenu_page(
            self::PAGE_SLUG,
            __('Settings', 'veldra'),
            __('Settings', 'veldra'),
            'manage_options',
            self::SETTINGS_SLUG,
            [$this, 'render_settings']
        );
    }

    /**
     * Enqueue dashboard assets on our admin pages.
     *
     * @param string $hook The current admin page hook.
     */
    public function enqueue_assets(string $hook): void
    {
        if (!str_contains($hook, self::PAGE_SLUG) && !str_contains($hook, self::SETTINGS_SLUG)) {
            return;
        }

        // Chart.js (self-hosted)
        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js',
            [],
            '4.4.7',
            true
        );

        // Dashboard JS
        wp_enqueue_script(
            'veldra-dashboard',
            plugins_url('build/dashboard.min.js', VELDRA_PLUGIN_FILE),
            ['chart-js'],
            VELDRA_VERSION,
            true
        );

        // Pass data URL to JS
        wp_localize_script('veldra-dashboard', 'VELDRA_ADMIN', [
            'dataUrl' => rest_url('veldra/v1/data'),
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);

        // Dashboard CSS
        wp_enqueue_style(
            'veldra-dashboard',
            plugins_url('assets/admin/dashboard.css', VELDRA_PLUGIN_FILE),
            [],
            VELDRA_VERSION
        );
    }

    /**
     * Render the main analytics dashboard.
     */
    public function render_dashboard(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions.', 'veldra'));
        }

        ?>
        <div class="wrap veldra-dashboard">
            <h1><?php echo esc_html__('Veldra Analytics', 'veldra'); ?></h1>

            <!-- Date Range Selector -->
            <div class="veldra-controls">
                <select id="veldra-range" class="veldra-select">
                    <option value="7"><?php esc_html_e('Last 7 days', 'veldra'); ?></option>
                    <option value="30" selected><?php esc_html_e('Last 30 days', 'veldra'); ?></option>
                    <option value="90"><?php esc_html_e('Last 90 days', 'veldra'); ?></option>
                    <option value="custom"><?php esc_html_e('Custom range', 'veldra'); ?></option>
                </select>
                <div id="veldra-custom-range" class="veldra-custom-range" style="display:none;">
                    <input type="date" id="veldra-start" />
                    <input type="date" id="veldra-end" />
                    <button id="veldra-apply" class="button button-secondary">
                        <?php esc_html_e('Apply', 'veldra'); ?>
                    </button>
                </div>
            </div>

            <!-- Overview Cards -->
            <div class="veldra-cards">
                <div class="veldra-card">
                    <span class="veldra-card-label"><?php esc_html_e('Visitors', 'veldra'); ?></span>
                    <span class="veldra-card-value" id="veldra-visitors">—</span>
                </div>
                <div class="veldra-card">
                    <span class="veldra-card-label"><?php esc_html_e('Pageviews', 'veldra'); ?></span>
                    <span class="veldra-card-value" id="veldra-pageviews">—</span>
                </div>
                <div class="veldra-card">
                    <span class="veldra-card-label"><?php esc_html_e('Bounce Rate', 'veldra'); ?></span>
                    <span class="veldra-card-value" id="veldra-bounce">—%</span>
                </div>
                <div class="veldra-card">
                    <span class="veldra-card-label"><?php esc_html_e('Avg. Duration', 'veldra'); ?></span>
                    <span class="veldra-card-value" id="veldra-duration">—</span>
                </div>
            </div>

            <!-- Traffic Overview Chart -->
            <div class="veldra-chart-container">
                <h2><?php esc_html_e('Traffic Overview', 'veldra'); ?></h2>
                <canvas id="veldra-traffic-chart"></canvas>
            </div>

            <!-- Charts Grid -->
            <div class="veldra-grid">
                <div class="veldra-chart-container">
                    <h2><?php esc_html_e('Top Content', 'veldra'); ?></h2>
                    <div id="veldra-top-content" class="veldra-table-container"></div>
                </div>
                <div class="veldra-chart-container">
                    <h2><?php esc_html_e('Referrers', 'veldra'); ?></h2>
                    <div id="veldra-referrers" class="veldra-table-container"></div>
                </div>
                <div class="veldra-chart-container">
                    <h2><?php esc_html_e('Devices', 'veldra'); ?></h2>
                    <canvas id="veldra-devices-chart"></canvas>
                </div>
                <div class="veldra-chart-container">
                    <h2><?php esc_html_e('Countries', 'veldra'); ?></h2>
                    <div id="veldra-countries" class="veldra-table-container"></div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the settings page.
     */
    public function render_settings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions.', 'veldra'));
        }

        // Handle form submission
        if (isset($_POST['veldra_save_settings']) && check_admin_referer('veldra_settings')) {
            $this->save_settings();
        }

        $mmdb_path  = get_option('veldra_mmdb_path', '');
        $premium_key = get_option('veldra_premium_key', '');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Veldra Settings', 'veldra'); ?></h1>

            <form method="post">
                <?php wp_nonce_field('veldra_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th><label for="mmdb_path"><?php esc_html_e('GeoIP Database Path', 'veldra'); ?></label></th>
                        <td>
                            <input type="text" id="mmdb_path" name="mmdb_path"
                                   value="<?php echo esc_attr($mmdb_path); ?>" class="regular-text"
                                   placeholder="/path/to/GeoLite2-City.mmdb" />
                            <p class="description">
                                <?php esc_html_e('Path to the MaxMind GeoLite2 City database. Leave empty to disable geolocation.', 'veldra'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="premium_key"><?php esc_html_e('Premium License Key', 'veldra'); ?></label></th>
                        <td>
                            <input type="text" id="premium_key" name="premium_key"
                                   value="<?php echo esc_attr($premium_key); ?>" class="regular-text" />
                            <p class="description">
                                <?php esc_html_e('Enter your Veldra Premium key to unlock cloud offloading and extended retention.', 'veldra'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="veldra_save_settings" class="button button-primary">
                        <?php esc_html_e('Save Settings', 'veldra'); ?>
                    </button>
                </p>
            </form>

            <!-- Compliance Status Widget -->
            <hr />
            <h2><?php esc_html_e('Compliance Status', 'veldra'); ?></h2>
            <?php $this->render_compliance_widget(); ?>
        </div>
        <?php
    }

    /**
     * Save settings from the settings form.
     */
    private function save_settings(): void
    {
        // Sanitize and save MMDB path
        if (isset($_POST['mmdb_path'])) {
            $path = sanitize_text_field(wp_unslash($_POST['mmdb_path']));
            update_option('veldra_mmdb_path', $path);
        }

        // Sanitize and save premium key
        if (isset($_POST['premium_key'])) {
            $key = sanitize_text_field(wp_unslash($_POST['premium_key']));
            update_option('veldra_premium_key', $key);
        }

        echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'veldra') . '</p></div>';
    }

    /**
     * Render the compliance status widget.
     */
    private function render_compliance_widget(): void
    {
        $mmdb_set    = !empty(get_option('veldra_mmdb_path', ''));
        $dpa_ready   = get_option('veldra_dpa_generated', false);
        ?>
        <div class="veldra-compliance">
            <ul>
                <li>
                    <span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span>
                    <?php esc_html_e('Cookie-free tracking: Active — no consent banner required', 'veldra'); ?>
                </li>
                <li>
                    <span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span>
                    <?php esc_html_e('Data residency: EU (self-hosted, no third-party routing)', 'veldra'); ?>
                </li>
                <li>
                    <?php if ($mmdb_set) : ?>
                        <span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span>
                    <?php else : ?>
                        <span class="dashicons dashicons-warning" style="color:#f56e28;"></span>
                    <?php endif; ?>
                    <?php esc_html_e('Geolocation database', 'veldra'); ?>
                </li>
                <li>
                    <?php if ($dpa_ready) : ?>
                        <span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span>
                    <?php else : ?>
                        <span class="dashicons dashicons-dismiss" style="color:#dc3232;"></span>
                        <a href="<?php echo esc_url(admin_url('admin-post.php?action=veldra_generate_dpa')); ?>">
                            <?php esc_html_e('Generate DPA', 'veldra'); ?>
                        </a>
                    <?php endif; ?>
                </li>
            </ul>
        </div>
        <?php
    }
}

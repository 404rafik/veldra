<?php
/**
 * Veldra — Privacy-first WordPress Analytics
 *
 * @package           Veldra
 * @author            Veldra Team
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Veldra
 * Plugin URI:        https://veldra.dev
 * Description:       Cookie-free, GDPR-compliant website analytics for WordPress. Zero personal data collection, EU-hosted cloud endpoint.
 * Version:           1.0.0
 * Requires PHP:      8.2
 * Requires WP:       6.4
 * Author:            Veldra
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       veldra
 */

declare(strict_types=1);

namespace Veldra;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

define('VELDRA_PLUGIN_FILE', __FILE__);
define('VELDRA_VERSION', '1.0.0');

// Autoload
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Bootstrap
add_action('plugins_loaded', function (): void {
    $plugin = Plugin::get_instance();
    $plugin->init();
});

// Activation / deactivation
register_activation_hook(__FILE__, [Database\Migrator::class, 'activate']);
register_deactivation_hook(__FILE__, [Database\Migrator::class, 'deactivate']);

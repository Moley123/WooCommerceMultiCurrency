<?php
/**
 * Plugin Name: Vignette Currency Converter
 * Plugin URI: https://github.com/Moley123/WooCommerceMultiCurrency
 * Description: Multi-currency support for vignette products with ExchangeRate-API integration
 * Version: 1.0.1
 * Author: Mark Lebrett
 * Author URI: https://marklebrett.co.uk
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: vignette-currency-converter
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('VCC_VERSION', '1.0.0');
define('VCC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VCC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('VCC_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', 'vcc_woocommerce_missing_notice');
    return;
}

function vcc_woocommerce_missing_notice() {
    ?>
    <div class="error">
        <p><?php _e('Vignette Currency Converter requires WooCommerce to be installed and active.', 'vignette-currency-converter'); ?></p>
    </div>
    <?php
}

// Include required files
require_once VCC_PLUGIN_DIR . 'includes/class-currency-api.php';
require_once VCC_PLUGIN_DIR . 'includes/class-currency-converter.php';
require_once VCC_PLUGIN_DIR . 'includes/class-admin-settings.php';
require_once VCC_PLUGIN_DIR . 'includes/class-frontend-display.php';

/**
 * Main plugin class
 */
class Vignette_Currency_Converter {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        // Initialize plugin components
        add_action('plugins_loaded', array($this, 'init'));

        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    public function init() {
        // Load text domain
        load_plugin_textdomain('vignette-currency-converter', false, dirname(VCC_PLUGIN_BASENAME) . '/languages');

        // Initialize classes
        VCC_Currency_API::get_instance();
        VCC_Currency_Converter::get_instance();
        VCC_Admin_Settings::get_instance();
        VCC_Frontend_Display::get_instance();
    }

    public function activate() {
        // Set default options
        $default_options = array(
            'api_provider' => 'exchangerate-api',
            'api_key' => '',
            'base_currency' => 'GBP',
            'enabled_currencies' => array('GBP', 'EUR', 'USD', 'CHF', 'CAD', 'AUD', 'JPY'),
            'cache_duration' => 12, // hours
            'show_currency_selector' => 'yes',
            'enable_markup' => 'no',
            'default_markup_type' => 'percentage',
            'default_markup_value' => 0,
        );

        if (!get_option('vcc_settings')) {
            add_option('vcc_settings', $default_options);
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    public function deactivate() {
        // Clean up transients
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_vcc_rate_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_vcc_rate_%'");

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    public function enqueue_frontend_assets() {
        if (is_product() || is_shop() || is_product_category() || is_product_tag()) {
            wp_enqueue_style(
                'vcc-frontend',
                VCC_PLUGIN_URL . 'assets/css/frontend.css',
                array(),
                VCC_VERSION
            );

            wp_enqueue_script(
                'vcc-currency-selector',
                VCC_PLUGIN_URL . 'assets/js/currency-selector.js',
                array('jquery'),
                VCC_VERSION,
                true
            );

            wp_localize_script('vcc-currency-selector', 'vccData', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('vcc_currency_switch'),
            ));
        }
    }

    public function enqueue_admin_assets($hook) {
        if ('woocommerce_page_vcc-settings' === $hook || 'post.php' === $hook || 'post-new.php' === $hook) {
            wp_enqueue_style(
                'vcc-admin',
                VCC_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                VCC_VERSION
            );
        }
    }
}

// Declare HPOS (High-Performance Order Storage) compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Initialize the plugin
function vcc_init() {
    return Vignette_Currency_Converter::get_instance();
}

// Start the plugin
vcc_init();

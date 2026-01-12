<?php
/**
 * Currency API Handler
 * Handles communication with ExchangeRate-API
 */

if (!defined('ABSPATH')) {
    exit;
}

class VCC_Currency_API {

    private static $instance = null;
    private $api_url = 'https://v6.exchangerate-api.com/v6/';
    private $settings;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->settings = get_option('vcc_settings', array());
    }

    /**
     * Get exchange rate from one currency to another
     *
     * @param string $from Source currency code
     * @param string $to Target currency code
     * @return float|WP_Error Exchange rate or error
     */
    public function get_exchange_rate($from, $to) {
        // Check cache first
        $cached_rate = $this->get_cached_rate($from, $to);
        if (false !== $cached_rate) {
            return $cached_rate;
        }

        // Get API key
        $api_key = isset($this->settings['api_key']) ? $this->settings['api_key'] : '';

        if (empty($api_key)) {
            return new WP_Error('no_api_key', __('ExchangeRate-API key not configured.', 'vignette-currency-converter'));
        }

        // Build API URL
        $url = $this->api_url . $api_key . '/pair/' . strtoupper($from) . '/' . strtoupper($to);

        // Make API request
        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        ));

        // Check for errors
        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Check API response
        if (!isset($data['result']) || $data['result'] !== 'success') {
            $error_message = isset($data['error-type']) ? $data['error-type'] : 'Unknown error';
            return new WP_Error('api_error', $error_message);
        }

        // Get conversion rate
        $rate = isset($data['conversion_rate']) ? floatval($data['conversion_rate']) : 0;

        if ($rate > 0) {
            // Cache the rate
            $this->cache_rate($from, $to, $rate);
            return $rate;
        }

        return new WP_Error('invalid_rate', __('Invalid exchange rate received.', 'vignette-currency-converter'));
    }

    /**
     * Get all exchange rates for a base currency
     *
     * @param string $base Base currency code
     * @return array|WP_Error Array of rates or error
     */
    public function get_all_rates($base) {
        // Check cache first
        $cache_key = 'vcc_all_rates_' . strtoupper($base);
        $cached_rates = get_transient($cache_key);

        if (false !== $cached_rates) {
            return $cached_rates;
        }

        // Get API key
        $api_key = isset($this->settings['api_key']) ? $this->settings['api_key'] : '';

        if (empty($api_key)) {
            return new WP_Error('no_api_key', __('ExchangeRate-API key not configured.', 'vignette-currency-converter'));
        }

        // Build API URL
        $url = $this->api_url . $api_key . '/latest/' . strtoupper($base);

        // Make API request
        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        ));

        // Check for errors
        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Check API response
        if (!isset($data['result']) || $data['result'] !== 'success') {
            $error_message = isset($data['error-type']) ? $data['error-type'] : 'Unknown error';
            return new WP_Error('api_error', $error_message);
        }

        // Get conversion rates
        $rates = isset($data['conversion_rates']) ? $data['conversion_rates'] : array();

        if (!empty($rates)) {
            // Cache the rates
            $cache_duration = isset($this->settings['cache_duration']) ? intval($this->settings['cache_duration']) : 12;
            set_transient($cache_key, $rates, $cache_duration * HOUR_IN_SECONDS);
            return $rates;
        }

        return new WP_Error('invalid_response', __('Invalid API response.', 'vignette-currency-converter'));
    }

    /**
     * Convert amount from one currency to another
     *
     * @param float $amount Amount to convert
     * @param string $from Source currency code
     * @param string $to Target currency code
     * @return float|WP_Error Converted amount or error
     */
    public function convert($amount, $from, $to) {
        // If same currency, return original amount
        if (strtoupper($from) === strtoupper($to)) {
            return floatval($amount);
        }

        $rate = $this->get_exchange_rate($from, $to);

        if (is_wp_error($rate)) {
            return $rate;
        }

        return floatval($amount) * $rate;
    }

    /**
     * Cache exchange rate
     *
     * @param string $from Source currency
     * @param string $to Target currency
     * @param float $rate Exchange rate
     */
    private function cache_rate($from, $to, $rate) {
        $cache_key = 'vcc_rate_' . strtoupper($from) . '_' . strtoupper($to);
        $cache_duration = isset($this->settings['cache_duration']) ? intval($this->settings['cache_duration']) : 12;
        set_transient($cache_key, $rate, $cache_duration * HOUR_IN_SECONDS);
    }

    /**
     * Get cached exchange rate
     *
     * @param string $from Source currency
     * @param string $to Target currency
     * @return float|false Cached rate or false
     */
    private function get_cached_rate($from, $to) {
        $cache_key = 'vcc_rate_' . strtoupper($from) . '_' . strtoupper($to);
        return get_transient($cache_key);
    }

    /**
     * Clear all cached rates
     */
    public function clear_cache() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_vcc_rate_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_vcc_rate_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_vcc_all_rates_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_vcc_all_rates_%'");
    }

    /**
     * Test API connection
     *
     * @return bool|WP_Error True if successful, error otherwise
     */
    public function test_connection() {
        $result = $this->get_exchange_rate('USD', 'EUR');

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }
}

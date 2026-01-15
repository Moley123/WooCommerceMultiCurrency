<?php
/**
 * Geolocation Helper
 * Handles IP-based location detection and country-to-currency mapping
 */

if (!defined('ABSPATH')) {
    exit;
}

class VCC_Geolocation {

    private static $instance = null;
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
     * Get country code from IP address using ipapi.co
     *
     * @param string $ip IP address (optional, defaults to current user's IP)
     * @return string|false Country code (e.g., 'GB', 'DE') or false on failure
     */
    public function get_country_from_ip($ip = null) {
        // Get user's IP if not provided
        if (empty($ip)) {
            $ip = $this->get_user_ip();
        }

        // Don't attempt geolocation for local/private IPs
        if ($this->is_local_ip($ip)) {
            return false;
        }

        // Check cache first (cache for 24 hours per IP)
        $cache_key = 'vcc_geo_' . md5($ip);
        $cached_country = get_transient($cache_key);

        if (false !== $cached_country) {
            return $cached_country;
        }

        // Make API request to ipapi.co
        $url = "https://ipapi.co/{$ip}/json/";
        $response = wp_remote_get($url, array(
            'timeout' => 5,
            'sslverify' => true,
        ));

        // Check for errors
        if (is_wp_error($response)) {
            error_log('VCC Geolocation: API request failed - ' . $response->get_error_message());
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            error_log('VCC Geolocation: API returned status ' . $status_code);
            return false;
        }

        // Parse response
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data['country_code'])) {
            error_log('VCC Geolocation: No country code in response');
            return false;
        }

        $country_code = strtoupper($data['country_code']);

        // Cache the result for 24 hours
        set_transient($cache_key, $country_code, 24 * HOUR_IN_SECONDS);

        return $country_code;
    }

    /**
     * Map country code to currency code
     *
     * @param string $country_code ISO 3166-1 alpha-2 country code (e.g., 'GB', 'DE')
     * @return string Currency code (e.g., 'GBP', 'EUR') or 'GBP' as default
     */
    public function country_to_currency($country_code) {
        $country_code = strtoupper($country_code);

        // Country to currency mapping
        $mapping = array(
            // Europe - EUR (Eurozone)
            'AT' => 'EUR', // Austria
            'BE' => 'EUR', // Belgium
            'CY' => 'EUR', // Cyprus
            'EE' => 'EUR', // Estonia
            'FI' => 'EUR', // Finland
            'FR' => 'EUR', // France
            'DE' => 'EUR', // Germany
            'GR' => 'EUR', // Greece
            'IE' => 'EUR', // Ireland
            'IT' => 'EUR', // Italy
            'LV' => 'EUR', // Latvia
            'LT' => 'EUR', // Lithuania
            'LU' => 'EUR', // Luxembourg
            'MT' => 'EUR', // Malta
            'NL' => 'EUR', // Netherlands
            'PT' => 'EUR', // Portugal
            'SK' => 'EUR', // Slovakia
            'SI' => 'EUR', // Slovenia
            'ES' => 'EUR', // Spain

            // United Kingdom
            'GB' => 'GBP', // United Kingdom
            'UK' => 'GBP', // United Kingdom (alternative)

            // Switzerland
            'CH' => 'CHF', // Switzerland

            // United States
            'US' => 'USD', // United States

            // Canada
            'CA' => 'CAD', // Canada

            // Australia
            'AU' => 'AUD', // Australia

            // Japan
            'JP' => 'JPY', // Japan

            // Additional European countries (non-Euro)
            'DK' => 'EUR', // Denmark (default to EUR for simplicity)
            'SE' => 'EUR', // Sweden (default to EUR for simplicity)
            'NO' => 'EUR', // Norway (default to EUR for simplicity)
            'PL' => 'EUR', // Poland (default to EUR for simplicity)
            'CZ' => 'EUR', // Czech Republic (default to EUR for simplicity)
            'HU' => 'EUR', // Hungary (default to EUR for simplicity)
            'RO' => 'EUR', // Romania (default to EUR for simplicity)
            'BG' => 'EUR', // Bulgaria (default to EUR for simplicity)
            'HR' => 'EUR', // Croatia
        );

        // Allow filtering of country-to-currency mapping
        $mapping = apply_filters('vcc_country_to_currency_mapping', $mapping);

        // Return mapped currency or default to GBP
        return isset($mapping[$country_code]) ? $mapping[$country_code] : 'GBP';
    }

    /**
     * Auto-detect currency based on user's location
     *
     * @return string Currency code (e.g., 'GBP', 'EUR')
     */
    public function auto_detect_currency() {
        // Check if auto-detection is enabled
        $auto_detect_enabled = isset($this->settings['enable_auto_detection'])
            && $this->settings['enable_auto_detection'] === 'yes';

        if (!$auto_detect_enabled) {
            return 'GBP'; // Default
        }

        // Get country from IP
        $country = $this->get_country_from_ip();

        if (false === $country) {
            return 'GBP'; // Default fallback
        }

        // Map country to currency
        $currency = $this->country_to_currency($country);

        // Allow filtering of auto-detected currency
        return apply_filters('vcc_auto_detected_currency', $currency, $country);
    }

    /**
     * Get user's IP address
     *
     * @return string IP address
     */
    private function get_user_ip() {
        // Check for CloudFlare IP first
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }

        // Check for forwarded IP
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip_list = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ip_list[0]);
        }

        // Standard remote address
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
    }

    /**
     * Check if IP is local/private
     *
     * @param string $ip IP address
     * @return bool True if local/private IP
     */
    private function is_local_ip($ip) {
        // Local/private IP ranges
        $local_ips = array(
            '127.0.0.1',
            'localhost',
            '::1',
        );

        if (in_array($ip, $local_ips)) {
            return true;
        }

        // Check for private IP ranges
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        return false;
    }

    /**
     * Clear geolocation cache
     */
    public function clear_cache() {
        global $wpdb;

        // Delete all transients that start with vcc_geo_
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_vcc_geo_%'
             OR option_name LIKE '_transient_timeout_vcc_geo_%'"
        );
    }
}

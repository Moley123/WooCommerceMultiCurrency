<?php
/**
 * VCC Analytics
 * Logs currency switch and checkout events with masked IP, geolocation,
 * device type, page URL and user ID.
 */

if (!defined('ABSPATH')) {
    exit;
}

class VCC_Analytics {

    private static $instance = null;
    private $table_name;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'vcc_currency_events';
        $this->init_hooks();
    }

    private function init_hooks() {
        // Daily cleanup of old events
        add_action('vcc_analytics_cleanup', array($this, 'cleanup_old_events'));
        if (!wp_next_scheduled('vcc_analytics_cleanup')) {
            wp_schedule_event(time(), 'daily', 'vcc_analytics_cleanup');
        }

        // Log currency at checkout
        add_action('woocommerce_checkout_order_processed', array($this, 'log_checkout_event'), 10, 1);

        // Admin AJAX: clear analytics data
        add_action('wp_ajax_vcc_clear_analytics', array($this, 'ajax_clear_analytics'));
    }

    // -------------------------------------------------------------------------
    // Table management
    // -------------------------------------------------------------------------

    /**
     * Create the events table. Called on plugin activation.
     */
    public function create_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id          bigint(20)   NOT NULL AUTO_INCREMENT,
            event_type  varchar(20)  NOT NULL DEFAULT 'switch',
            currency_from varchar(10) NOT NULL DEFAULT '',
            currency_to   varchar(10) NOT NULL DEFAULT '',
            ip_masked   varchar(60)  NOT NULL DEFAULT '',
            country     varchar(100) NOT NULL DEFAULT '',
            city        varchar(100) NOT NULL DEFAULT '',
            page_url    varchar(1000) NOT NULL DEFAULT '',
            device_type varchar(20)  NOT NULL DEFAULT '',
            user_id     bigint(20)   NOT NULL DEFAULT 0,
            created_at  datetime     NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_event_type  (event_type),
            KEY idx_currency_to (currency_to),
            KEY idx_created_at  (created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // -------------------------------------------------------------------------
    // Logging
    // -------------------------------------------------------------------------

    /**
     * Log a currency switch event (called from the AJAX handler).
     *
     * @param string $currency_from  Previous currency code
     * @param string $currency_to    Newly selected currency code
     * @param string $page_url       Page where the switch happened
     */
    public function log_switch_event($currency_from, $currency_to, $page_url = '') {
        $this->log_event('switch', $currency_from, $currency_to, $page_url);
    }

    /**
     * Log the currency used at checkout.
     * Hooked to woocommerce_checkout_order_processed.
     *
     * @param int $order_id
     */
    public function log_checkout_event($order_id) {
        $currency = 'GBP';

        if (isset($_SESSION['vcc_selected_currency'])) {
            $currency = sanitize_text_field($_SESSION['vcc_selected_currency']);
        } elseif (isset($_COOKIE['vcc_selected_currency'])) {
            $currency = sanitize_text_field($_COOKIE['vcc_selected_currency']);
        }

        // Only log non-GBP checkouts (GBP is the default, not interesting)
        if ($currency === 'GBP') {
            return;
        }

        $page_url = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : '';
        $this->log_event('checkout', 'GBP', $currency, $page_url);
    }

    /**
     * Core log method — inserts one row into the events table.
     */
    private function log_event($event_type, $currency_from, $currency_to, $page_url = '') {
        global $wpdb;

        // Skip if analytics logging is disabled
        $settings = get_option('vcc_settings', array());
        if (isset($settings['analytics_enabled']) && $settings['analytics_enabled'] === 'no') {
            return;
        }

        $ip       = $this->get_real_ip();
        $location = $this->get_location($ip);

        $wpdb->insert(
            $this->table_name,
            array(
                'event_type'   => sanitize_text_field($event_type),
                'currency_from' => sanitize_text_field($currency_from),
                'currency_to'  => sanitize_text_field($currency_to),
                'ip_masked'    => $this->mask_ip($ip),
                'country'      => $location['country'],
                'city'         => $location['city'],
                'page_url'     => esc_url_raw(substr($page_url, 0, 1000)),
                'device_type'  => $this->get_device_type(),
                'user_id'      => (int) get_current_user_id(),
                'created_at'   => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s')
        );
    }

    // -------------------------------------------------------------------------
    // IP helpers
    // -------------------------------------------------------------------------

    /**
     * Get the real visitor IP, checking common proxy / CDN headers first.
     */
    private function get_real_ip() {
        $headers = array(
            'HTTP_CF_CONNECTING_IP',   // Cloudflare
            'HTTP_X_FORWARDED_FOR',    // Generic proxy (may be comma-list)
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        );

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = trim(explode(',', $_SERVER[$header])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        // Fall back to REMOTE_ADDR even if private (e.g. local dev)
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }

    /**
     * Mask an IP address so only the first octet (IPv4) or segment (IPv6) is kept.
     * IPv4: 81.123.45.67  → 81.*.*.*
     * IPv6: 2001:db8::1   → 2001:*:*:*:*:*:*:*
     */
    private function mask_ip($ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts  = explode(':', $ip);
            $masked = array_fill(0, 8, '*');
            $masked[0] = isset($parts[0]) ? $parts[0] : '*';
            return implode(':', $masked);
        }

        $parts = explode('.', $ip);
        return (isset($parts[0]) ? $parts[0] : '0') . '.*.*.*';
    }

    /**
     * Look up country + city for an IP address using ip-api.com.
     * Results are cached in a transient for 24 hours (separate from the
     * geolocation transient used for currency auto-detection).
     *
     * @param  string $ip
     * @return array  { country: string, city: string }
     */
    private function get_location($ip) {
        if ($ip === '0.0.0.0' || $ip === '127.0.0.1') {
            return array('country' => '', 'city' => '');
        }

        $transient_key = 'vcc_analytics_ip_' . md5($ip);
        $cached = get_transient($transient_key);
        if ($cached !== false) {
            return $cached;
        }

        $response = wp_remote_get(
            'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,country,city',
            array('timeout' => 3)
        );

        $location = array('country' => '', 'city' => '');

        if (!is_wp_error($response)) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($data) && $data['status'] === 'success') {
                $location = array(
                    'country' => sanitize_text_field($data['country'] ?? ''),
                    'city'    => sanitize_text_field($data['city']    ?? ''),
                );
            }
        }

        // Cache 24 h — ip-api.com free tier allows 45 req/min
        set_transient($transient_key, $location, DAY_IN_SECONDS);

        return $location;
    }

    /**
     * Detect whether the request came from a mobile or desktop device.
     */
    private function get_device_type() {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        return preg_match('/Mobile|Android|iPhone|iPad|Tablet/i', $ua) ? 'mobile' : 'desktop';
    }

    // -------------------------------------------------------------------------
    // Data access
    // -------------------------------------------------------------------------

    /**
     * Fetch paginated events.
     *
     * @param  int    $page     1-based page number
     * @param  int    $per_page Rows per page
     * @param  string $type     Filter by event_type ('switch'|'checkout'|'' for all)
     * @return array  { rows: array, total: int }
     */
    public function get_events($page = 1, $per_page = 25, $type = '') {
        global $wpdb;

        $page     = max(1, (int) $page);
        $per_page = max(1, (int) $per_page);
        $offset   = ($page - 1) * $per_page;

        $where = $type ? $wpdb->prepare('WHERE event_type = %s', $type) : '';

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} {$where}");

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        return array('rows' => $rows ?: array(), 'total' => $total);
    }

    /**
     * Aggregate summary statistics.
     *
     * @return array
     */
    public function get_summary() {
        global $wpdb;

        $total_switches = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE event_type = 'switch'"
        );

        $total_checkouts = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE event_type = 'checkout'"
        );

        $by_currency = $wpdb->get_results(
            "SELECT currency_to, COUNT(*) AS cnt
             FROM {$this->table_name}
             WHERE event_type = 'switch'
             GROUP BY currency_to
             ORDER BY cnt DESC
             LIMIT 10",
            ARRAY_A
        );

        $by_country = $wpdb->get_results(
            "SELECT country, COUNT(*) AS cnt
             FROM {$this->table_name}
             WHERE event_type = 'switch' AND country != ''
             GROUP BY country
             ORDER BY cnt DESC
             LIMIT 10",
            ARRAY_A
        );

        $oldest = $wpdb->get_var("SELECT MIN(created_at) FROM {$this->table_name}");
        $newest = $wpdb->get_var("SELECT MAX(created_at) FROM {$this->table_name}");

        return array(
            'total_switches'  => $total_switches,
            'total_checkouts' => $total_checkouts,
            'by_currency'     => $by_currency ?: array(),
            'by_country'      => $by_country  ?: array(),
            'oldest'          => $oldest,
            'newest'          => $newest,
        );
    }

    /**
     * Delete all rows from the events table.
     */
    public function clear_all_events() {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$this->table_name}");
    }

    /**
     * Delete events older than the configured retention period.
     * Called daily by WP-Cron.
     */
    public function cleanup_old_events() {
        global $wpdb;

        $settings       = get_option('vcc_settings', array());
        $retention_days = isset($settings['analytics_retention_days'])
            ? (int) $settings['analytics_retention_days']
            : 90;

        if ($retention_days <= 0) {
            return; // 0 = keep forever
        }

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $retention_days
            )
        );
    }

    // -------------------------------------------------------------------------
    // AJAX
    // -------------------------------------------------------------------------

    public function ajax_clear_analytics() {
        check_ajax_referer('vcc_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'vignette-currency-converter')));
        }

        $this->clear_all_events();
        wp_send_json_success(array('message' => __('Analytics data cleared', 'vignette-currency-converter')));
    }
}

<?php
/**
 * Frontend Display
 * Handles customer-facing currency selector and price display
 */

if (!defined('ABSPATH')) {
    exit;
}

class VCC_Frontend_Display {

    private static $instance = null;
    private $converter;
    private $settings;
    private $selected_currency;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->converter = VCC_Currency_Converter::get_instance();
        $this->settings = get_option('vcc_settings', array());
        $this->init_hooks();
        $this->init_session();
    }

    private function init_hooks() {
        // Modify WooCommerce price display
        add_filter('woocommerce_get_price_html', array($this, 'modify_price_display'), 10, 2);
        add_filter('woocommerce_cart_item_price', array($this, 'modify_cart_price'), 10, 3);
        add_filter('woocommerce_cart_item_subtotal', array($this, 'modify_cart_price'), 10, 3);

        // Add currency selector
        $position = isset($this->settings['selector_position']) ? $this->settings['selector_position'] : 'before_add_to_cart';

        switch ($position) {
            case 'before_add_to_cart':
                add_action('woocommerce_before_add_to_cart_button', array($this, 'render_currency_selector'));
                break;
            case 'after_add_to_cart':
                add_action('woocommerce_after_add_to_cart_button', array($this, 'render_currency_selector'));
                break;
            case 'before_price':
                add_action('woocommerce_single_product_summary', array($this, 'render_currency_selector'), 9);
                break;
        }

        // Also show on shop and archive pages
        add_action('woocommerce_before_shop_loop', array($this, 'render_currency_selector_archive'), 15);

        // AJAX handler for currency change
        add_action('wp_ajax_vcc_change_currency', array($this, 'ajax_change_currency'));
        add_action('wp_ajax_nopriv_vcc_change_currency', array($this, 'ajax_change_currency'));

        // Add currency info to cart
        add_action('woocommerce_cart_totals_before_order_total', array($this, 'display_cart_currency_info'));
    }

    /**
     * Initialize session for storing selected currency
     * Priority: Manual override (session) > Auto-detection (IP) > Default (GBP)
     */
    private function init_session() {
        if (!session_id() && !headers_sent()) {
            session_start();
        }

        // Priority 1: Check if user manually selected currency (session + manual_override flag)
        if (isset($_SESSION['vcc_selected_currency']) && isset($_SESSION['vcc_manual_override'])) {
            // User manually chose currency - respect it for this session
            $this->selected_currency = $_SESSION['vcc_selected_currency'];
            return;
        }

        // Priority 2: Check session (may be auto-detected from previous page load)
        if (isset($_SESSION['vcc_selected_currency'])) {
            $this->selected_currency = $_SESSION['vcc_selected_currency'];
            return;
        }

        // Priority 3: Check cookie (fallback)
        if (isset($_COOKIE['vcc_selected_currency'])) {
            $this->selected_currency = sanitize_text_field($_COOKIE['vcc_selected_currency']);
            $_SESSION['vcc_selected_currency'] = $this->selected_currency;
            return;
        }

        // Priority 4: Auto-detect from IP geolocation (if enabled)
        $geolocation = VCC_Geolocation::get_instance();
        $auto_detected_currency = $geolocation->auto_detect_currency();

        if ($auto_detected_currency && $auto_detected_currency !== 'GBP') {
            // Auto-detected currency
            $this->selected_currency = $auto_detected_currency;
            $_SESSION['vcc_selected_currency'] = $auto_detected_currency;
            // Don't set manual_override flag - this is automatic
            return;
        }

        // Priority 5: Default to GBP
        $this->selected_currency = 'GBP';
        $_SESSION['vcc_selected_currency'] = 'GBP';
    }

    /**
     * Get currently selected currency
     */
    public function get_selected_currency() {
        return $this->selected_currency;
    }

    /**
     * Modify price display on product pages
     */
    public function modify_price_display($price_html, $product) {
        // Only modify if currency selector is enabled
        if (!$this->is_currency_selector_enabled()) {
            return $price_html;
        }

        // Get selected currency
        $currency = $this->get_selected_currency();

        // Get product ID and check if it's a variation
        $product_id = $product->get_id();
        $parent_id = $product->get_parent_id(); // 0 for simple products, parent ID for variations

        // For variations, get source currency from parent, source price from variation
        if ($parent_id > 0) {
            $source_currency = get_post_meta($parent_id, '_vcc_source_currency', true);
            $source_price = get_post_meta($product_id, '_vcc_source_price', true);
        } else {
            // Simple product - both from same product
            $source_currency = get_post_meta($product_id, '_vcc_source_currency', true);
            $source_price = get_post_meta($product_id, '_vcc_source_price', true);
        }

        // If selected currency matches source currency, show source price with markup
        if (!empty($source_currency) && !empty($source_price) && $currency === $source_currency) {
            // Apply markup to source price
            $final_source_price = $this->converter->apply_markup($product_id, $source_price);

            $symbol = $this->converter->get_currency_symbol($currency);
            $formatted_price = $this->format_price($final_source_price, $symbol, $currency);
            return '<span class="vcc-converted-price vcc-source-price" data-source-price="' . esc_attr($final_source_price) . '" data-currency="' . esc_attr($currency) . '">' . $formatted_price . '</span>';
        }

        // If GBP is selected, show WooCommerce price (already in GBP)
        if ($currency === 'GBP') {
            return $price_html;
        }

        // Otherwise, convert GBP to selected currency
        $gbp_price = $product->get_price();

        if (empty($gbp_price)) {
            return $price_html;
        }

        // Convert to selected currency
        $converted_price = $this->converter->convert_from_gbp($gbp_price, $currency);

        if (is_wp_error($converted_price)) {
            return $price_html; // Show original on error
        }

        // Format converted price
        $symbol = $this->converter->get_currency_symbol($currency);
        $formatted_price = $this->format_price($converted_price, $symbol, $currency);

        return '<span class="vcc-converted-price" data-gbp-price="' . esc_attr($gbp_price) . '" data-currency="' . esc_attr($currency) . '">' . $formatted_price . '</span>';
    }

    /**
     * Modify cart item prices
     */
    public function modify_cart_price($price_html, $cart_item, $cart_item_key) {
        // Only modify if currency selector is enabled
        if (!$this->is_currency_selector_enabled()) {
            return $price_html;
        }

        $currency = $this->get_selected_currency();
        $product = $cart_item['data'];

        // Get product ID and check if it's a variation
        $product_id = $product->get_id();
        $parent_id = $product->get_parent_id(); // 0 for simple products, parent ID for variations

        // For variations, get source currency from parent, source price from variation
        if ($parent_id > 0) {
            $source_currency = get_post_meta($parent_id, '_vcc_source_currency', true);
            $source_price = get_post_meta($product_id, '_vcc_source_price', true);
        } else {
            // Simple product - both from same product
            $source_currency = get_post_meta($product_id, '_vcc_source_currency', true);
            $source_price = get_post_meta($product_id, '_vcc_source_price', true);
        }

        // Check if selected currency matches source currency
        if (!empty($source_currency) && !empty($source_price) && $currency === $source_currency) {
            // Apply markup to source price
            $final_source_price = $this->converter->apply_markup($product_id, $source_price);

            $symbol = $this->converter->get_currency_symbol($currency);
            return $this->format_price($final_source_price, $symbol, $currency);
        }

        if ($currency === 'GBP') {
            return $price_html;
        }

        // Get product
        $gbp_price = $product->get_price();

        if (empty($gbp_price)) {
            return $price_html;
        }

        // Convert to selected currency
        $converted_price = $this->converter->convert_from_gbp($gbp_price, $currency);

        if (is_wp_error($converted_price)) {
            return $price_html;
        }

        $symbol = $this->converter->get_currency_symbol($currency);
        return $this->format_price($converted_price, $symbol, $currency);
    }

    /**
     * Render currency selector on product page
     */
    public function render_currency_selector() {
        if (!$this->is_currency_selector_enabled() || !is_product()) {
            return;
        }

        $this->render_selector();
    }

    /**
     * Render currency selector on archive pages
     */
    public function render_currency_selector_archive() {
        if (!$this->is_currency_selector_enabled()) {
            return;
        }

        $this->render_selector();
    }

    /**
     * Render the actual selector HTML
     */
    private function render_selector() {
        $currencies = $this->converter->get_available_currencies();
        $selected = $this->get_selected_currency();
        ?>
        <div class="vcc-currency-selector-wrapper">
            <label for="vcc-currency-selector" class="vcc-currency-label">
                <?php _e('Display prices in:', 'vignette-currency-converter'); ?>
            </label>
            <select id="vcc-currency-selector" class="vcc-currency-selector">
                <?php foreach ($currencies as $currency) : ?>
                    <option value="<?php echo esc_attr($currency); ?>" <?php selected($selected, $currency); ?>>
                        <?php echo esc_html($this->get_currency_name($currency)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="vcc-loading" style="display: none;"><?php _e('Updating...', 'vignette-currency-converter'); ?></span>
        </div>
        <?php
    }

    /**
     * Display currency info in cart
     */
    public function display_cart_currency_info() {
        $currency = $this->get_selected_currency();

        if ($currency === 'GBP') {
            return;
        }

        echo '<tr class="vcc-currency-info">';
        echo '<th colspan="2" style="text-align: center; font-size: 12px; color: #666;">';
        printf(
            __('Prices displayed in %s. Payment will be processed in GBP.', 'vignette-currency-converter'),
            esc_html($this->get_currency_name($currency))
        );
        echo '</th>';
        echo '</tr>';
    }

    /**
     * AJAX handler for currency change
     */
    public function ajax_change_currency() {
        check_ajax_referer('vcc_currency_switch', 'nonce');

        $currency = isset($_POST['currency']) ? sanitize_text_field($_POST['currency']) : 'GBP';
        $manual_override = isset($_POST['manual_override']) && $_POST['manual_override'] === 'true';

        // Validate currency
        $available_currencies = $this->converter->get_available_currencies();

        if (!in_array($currency, $available_currencies)) {
            wp_send_json_error(array('message' => __('Invalid currency', 'vignette-currency-converter')));
        }

        // Store in session and cookie
        $_SESSION['vcc_selected_currency'] = $currency;
        setcookie('vcc_selected_currency', $currency, time() + (86400 * 30), '/'); // 30 days

        // If this is a manual override, store flag in session (not cookie)
        // This tells auto-detection to respect user's choice for this session
        if ($manual_override) {
            $_SESSION['vcc_manual_override'] = true;
        } else {
            unset($_SESSION['vcc_manual_override']);
        }

        $this->selected_currency = $currency;

        wp_send_json_success(array(
            'message' => __('Currency updated', 'vignette-currency-converter'),
            'currency' => $currency,
            'manual_override' => $manual_override,
        ));
    }

    /**
     * Format price with currency symbol
     */
    private function format_price($amount, $symbol, $currency) {
        $decimals = in_array($currency, array('JPY')) ? 0 : 2;
        $formatted = number_format($amount, $decimals);

        // Different formats for different currencies
        if (in_array($currency, array('USD', 'CAD', 'AUD'))) {
            return $symbol . $formatted;
        } elseif ($currency === 'EUR') {
            return '€' . $formatted;
        } elseif ($currency === 'GBP') {
            return '£' . $formatted;
        } elseif ($currency === 'JPY') {
            return '¥' . $formatted;
        } else {
            return $formatted . ' ' . $currency;
        }
    }

    /**
     * Get currency name
     */
    private function get_currency_name($currency) {
        $names = array(
            'GBP' => 'British Pound (£)',
            'EUR' => 'Euro (€)',
            'USD' => 'US Dollar ($)',
            'CHF' => 'Swiss Franc (CHF)',
            'CAD' => 'Canadian Dollar (CA$)',
            'AUD' => 'Australian Dollar (A$)',
            'JPY' => 'Japanese Yen (¥)',
        );

        return isset($names[$currency]) ? $names[$currency] : $currency;
    }

    /**
     * Check if currency selector should be displayed
     *
     * Selector is shown when:
     * - show_currency_selector is enabled
     * AND NOT (auto_detection is enabled AND hide_selector_when_autodetect is enabled)
     */
    private function is_currency_selector_enabled() {
        $show_selector = isset($this->settings['show_currency_selector'])
            && $this->settings['show_currency_selector'] === 'yes';

        if (!$show_selector) {
            return false;
        }

        // Check if we should hide selector when auto-detection is on
        $auto_detect_enabled = isset($this->settings['enable_auto_detection'])
            && $this->settings['enable_auto_detection'] === 'yes';

        $hide_when_autodetect = isset($this->settings['hide_selector_when_autodetect'])
            && $this->settings['hide_selector_when_autodetect'] === 'yes';

        // Hide selector if both auto-detection and hide setting are enabled
        if ($auto_detect_enabled && $hide_when_autodetect) {
            return false;
        }

        return true;
    }
}

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
        // Priority 999 ensures we run AFTER other plugins (like WCEPO) so our wrapper stays on the outside
        add_filter('woocommerce_get_price_html', array($this, 'modify_price_display'), 999, 2);
        add_filter('woocommerce_cart_item_price', array($this, 'modify_cart_price'), 999, 3);
        add_filter('woocommerce_cart_item_subtotal', array($this, 'modify_cart_price'), 999, 3);

        // Cart and checkout totals
        add_filter('woocommerce_cart_subtotal', array($this, 'modify_cart_total_display'), 999, 3);
        add_filter('woocommerce_cart_total', array($this, 'modify_simple_total_display'), 999);
        add_filter('woocommerce_cart_totals_order_total_html', array($this, 'modify_order_total_html'), 999);

        // Mini-cart total
        add_filter('woocommerce_widget_shopping_cart_total', array($this, 'modify_simple_total_display'), 999);

        // Checkout notice
        add_action('woocommerce_review_order_after_order_total', array($this, 'display_checkout_currency_info'));

        // Global currency selector in footer (works on all pages)
        add_action('wp_footer', array($this, 'render_global_currency_selector'));
        add_action('wp_footer', array($this, 'render_currency_popup'), 20);

        // Shortcode for flexible placement: [vcc_currency_selector]
        add_shortcode('vcc_currency_selector', array($this, 'shortcode_currency_selector'));

        // WordPress widget
        add_action('widgets_init', array($this, 'register_widget'));

        // AJAX handler for currency change
        add_action('wp_ajax_vcc_change_currency', array($this, 'ajax_change_currency'));
        add_action('wp_ajax_nopriv_vcc_change_currency', array($this, 'ajax_change_currency'));

        // Add currency info to cart totals
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

        // Get GBP price for data attributes
        $gbp_price = $product->get_price();

        // If selected currency matches source currency, show source price with markup
        if (!empty($source_currency) && !empty($source_price) && $currency === $source_currency) {
            // Apply markup to source price
            $final_source_price = $this->converter->apply_markup($product_id, $source_price);

            $symbol = $this->converter->get_currency_symbol($currency);
            $formatted_price = $this->format_price($final_source_price, $symbol, $currency);

            // Include all data attributes for JavaScript
            return '<span class="vcc-converted-price vcc-source-price" ' .
                'data-product-id="' . esc_attr($product_id) . '" ' .
                'data-source-currency="' . esc_attr($source_currency) . '" ' .
                'data-source-price="' . esc_attr($source_price) . '" ' .
                'data-gbp-price="' . esc_attr($gbp_price) . '" ' .
                'data-currency="' . esc_attr($currency) . '">' .
                $formatted_price . '</span>';
        }

        // If GBP is selected, wrap in span with data attributes for instant switching
        if ($currency === 'GBP') {
            // Extract price value from HTML
            if (empty($gbp_price)) {
                return $price_html;
            }

            $symbol = $this->converter->get_currency_symbol('GBP');
            $formatted_price = $this->format_price($gbp_price, $symbol, 'GBP');

            return '<span class="vcc-converted-price" ' .
                'data-product-id="' . esc_attr($product_id) . '" ' .
                'data-source-currency="' . esc_attr($source_currency ?: '') . '" ' .
                'data-source-price="' . esc_attr($source_price ?: '') . '" ' .
                'data-gbp-price="' . esc_attr($gbp_price) . '" ' .
                'data-currency="GBP">' .
                $formatted_price . '</span>';
        }

        // Otherwise, convert GBP to selected currency
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

        // Include all data attributes for JavaScript
        return '<span class="vcc-converted-price" ' .
            'data-product-id="' . esc_attr($product_id) . '" ' .
            'data-source-currency="' . esc_attr($source_currency ?: '') . '" ' .
            'data-source-price="' . esc_attr($source_price ?: '') . '" ' .
            'data-gbp-price="' . esc_attr($gbp_price) . '" ' .
            'data-currency="' . esc_attr($currency) . '">' .
            $formatted_price . '</span>';
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

        // Get GBP price for data attributes
        $gbp_price = $product->get_price();

        // Check if selected currency matches source currency
        if (!empty($source_currency) && !empty($source_price) && $currency === $source_currency) {
            // Apply markup to source price
            $final_source_price = $this->converter->apply_markup($product_id, $source_price);

            $symbol = $this->converter->get_currency_symbol($currency);
            $formatted_price = $this->format_price($final_source_price, $symbol, $currency);

            // Include all data attributes for JavaScript
            return '<span class="vcc-converted-price vcc-cart-price" ' .
                'data-product-id="' . esc_attr($product_id) . '" ' .
                'data-source-currency="' . esc_attr($source_currency) . '" ' .
                'data-source-price="' . esc_attr($source_price) . '" ' .
                'data-gbp-price="' . esc_attr($gbp_price) . '" ' .
                'data-currency="' . esc_attr($currency) . '">' .
                $formatted_price . '</span>';
        }

        if ($currency === 'GBP') {
            if (empty($gbp_price)) {
                return $price_html;
            }

            $symbol = $this->converter->get_currency_symbol('GBP');
            $formatted_price = $this->format_price($gbp_price, $symbol, 'GBP');

            return '<span class="vcc-converted-price vcc-cart-price" ' .
                'data-product-id="' . esc_attr($product_id) . '" ' .
                'data-source-currency="' . esc_attr($source_currency ?: '') . '" ' .
                'data-source-price="' . esc_attr($source_price ?: '') . '" ' .
                'data-gbp-price="' . esc_attr($gbp_price) . '" ' .
                'data-currency="GBP">' .
                $formatted_price . '</span>';
        }

        // Get product
        if (empty($gbp_price)) {
            return $price_html;
        }

        // Convert to selected currency
        $converted_price = $this->converter->convert_from_gbp($gbp_price, $currency);

        if (is_wp_error($converted_price)) {
            return $price_html;
        }

        $symbol = $this->converter->get_currency_symbol($currency);
        $formatted_price = $this->format_price($converted_price, $symbol, $currency);

        // Include all data attributes for JavaScript
        return '<span class="vcc-converted-price vcc-cart-price" ' .
            'data-product-id="' . esc_attr($product_id) . '" ' .
            'data-source-currency="' . esc_attr($source_currency ?: '') . '" ' .
            'data-source-price="' . esc_attr($source_price ?: '') . '" ' .
            'data-gbp-price="' . esc_attr($gbp_price) . '" ' .
            'data-currency="' . esc_attr($currency) . '">' .
            $formatted_price . '</span>';
    }

    /**
     * Render the currency trigger — a clickable text label that opens the popup.
     * No <select>, no label. Popup is rendered separately via render_currency_popup().
     *
     * @param string $wrapper_class Additional CSS class for the wrapper div
     */
    private function render_selector($wrapper_class = '') {
        $selected     = $this->get_selected_currency();
        $all          = VCC_Currency_Converter::get_all_currencies();
        $symbol       = isset($all[$selected]) ? $all[$selected]['symbol'] : $selected;
        $trigger_text = ($symbol !== $selected) ? $symbol . ' ' . $selected : $selected;
        ?>
        <div class="vcc-currency-selector-wrapper <?php echo esc_attr($wrapper_class); ?>">
            <span class="vcc-currency-trigger" data-vcc-trigger
                  data-current-currency="<?php echo esc_attr($selected); ?>"
                  role="button" tabindex="0"
                  aria-haspopup="dialog" aria-label="<?php esc_attr_e('Select currency', 'vignette-currency-converter'); ?>">
                <?php echo esc_html($trigger_text); ?>
            </span>
        </div>
        <?php
    }

    /**
     * Render the currency selection popup modal.
     * Called once via wp_footer (priority 20). Shared by all trigger instances.
     */
    public function render_currency_popup() {
        if (!$this->is_currency_selector_enabled()) return;

        static $rendered = false;
        if ($rendered) return;
        $rendered = true;

        $currencies = $this->converter->get_available_currencies();
        $selected   = $this->get_selected_currency();
        $all        = VCC_Currency_Converter::get_all_currencies();
        ?>
        <div class="vcc-popup-overlay" id="vcc-popup-overlay" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e('Select currency', 'vignette-currency-converter'); ?>" style="display:none;">
            <div class="vcc-popup">
                <div class="vcc-popup-header">
                    <span class="vcc-popup-title"><?php _e('Select currency', 'vignette-currency-converter'); ?></span>
                    <button class="vcc-popup-close" aria-label="<?php esc_attr_e('Close', 'vignette-currency-converter'); ?>">&times;</button>
                </div>
                <div class="vcc-popup-grid">
                    <?php foreach ($currencies as $currency) :
                        $flag   = isset($all[$currency]) ? $all[$currency]['flag'] : '';
                        $name   = isset($all[$currency]) ? $all[$currency]['name'] : $currency;
                        $symbol = isset($all[$currency]) ? $all[$currency]['symbol'] : $currency;
                        $is_selected = ($currency === $selected);
                    ?>
                    <div class="vcc-popup-option<?php echo $is_selected ? ' vcc-selected' : ''; ?>"
                         data-currency="<?php echo esc_attr($currency); ?>"
                         role="option" tabindex="0"
                         aria-selected="<?php echo $is_selected ? 'true' : 'false'; ?>">
                        <span class="vcc-popup-flag"><?php echo esc_html($flag); ?></span>
                        <span class="vcc-popup-name"><?php echo esc_html($name); ?></span>
                        <strong class="vcc-popup-symbol"><?php echo esc_html($symbol); ?></strong>
                        <span class="vcc-popup-check">&#10003;</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Display currency info in cart totals table
     */
    public function display_cart_currency_info() {
        $currency = $this->get_selected_currency();
        if ($currency === 'GBP') return;

        $stripe_enabled = VCC_Stripe_Integration::get_instance()->is_enabled();
        $name = $this->get_currency_name($currency);

        echo '<tr class="vcc-currency-info">';
        echo '<th colspan="2" style="text-align:center;font-size:12px;color:#666;">';
        if ($stripe_enabled) {
            printf(__('You will be charged in %s.', 'vignette-currency-converter'), esc_html($name));
        } else {
            printf(__('Prices displayed in %s. Payment will be processed in GBP.', 'vignette-currency-converter'), esc_html($name));
        }
        echo '</th></tr>';
    }

    /**
     * Modify cart subtotal display
     */
    public function modify_cart_total_display($cart_subtotal, $compound, $cart) {
        $currency = $this->get_selected_currency();
        if ($currency === 'GBP') {
            return $cart_subtotal;
        }

        $raw_subtotal = $cart->get_subtotal();
        $converted = $this->converter->convert_from_gbp($raw_subtotal, $currency);

        if (is_wp_error($converted)) {
            return $cart_subtotal;
        }

        $symbol = $this->converter->get_currency_symbol($currency);
        return '<span class="vcc-converted-price vcc-cart-total" data-gbp-price="' . esc_attr($raw_subtotal) . '" data-currency="' . esc_attr($currency) . '">'
            . $this->format_price($converted, $symbol, $currency) . '</span>';
    }

    /**
     * Modify cart/mini-cart total display
     */
    public function modify_simple_total_display($total) {
        $currency = $this->get_selected_currency();
        if ($currency === 'GBP') {
            return $total;
        }

        if (!function_exists('WC') || !WC()->cart) {
            return $total;
        }

        $raw_total = WC()->cart->get_total('edit');
        $converted = $this->converter->convert_from_gbp($raw_total, $currency);

        if (is_wp_error($converted)) {
            return $total;
        }

        $symbol = $this->converter->get_currency_symbol($currency);
        return '<span class="vcc-converted-price vcc-cart-total" data-gbp-price="' . esc_attr($raw_total) . '" data-currency="' . esc_attr($currency) . '">'
            . $this->format_price($converted, $symbol, $currency) . '</span>';
    }

    /**
     * Modify order total HTML in cart/checkout totals table
     */
    public function modify_order_total_html($total_html) {
        $currency = $this->get_selected_currency();
        if ($currency === 'GBP') {
            return $total_html;
        }

        if (!function_exists('WC') || !WC()->cart) {
            return $total_html;
        }

        $raw_total = WC()->cart->get_total('edit');
        $converted = $this->converter->convert_from_gbp($raw_total, $currency);

        if (is_wp_error($converted)) {
            return $total_html;
        }

        $symbol = $this->converter->get_currency_symbol($currency);
        $formatted = $this->format_price($converted, $symbol, $currency);

        return '<strong><span class="vcc-converted-price vcc-order-total" data-gbp-price="' . esc_attr($raw_total) . '" data-currency="' . esc_attr($currency) . '">'
            . $formatted . '</span></strong>';
    }

    /**
     * Display currency info on checkout order review
     */
    public function display_checkout_currency_info() {
        $currency = $this->get_selected_currency();
        if ($currency === 'GBP') return;

        $stripe_enabled = VCC_Stripe_Integration::get_instance()->is_enabled();
        $name = $this->get_currency_name($currency);

        echo '<tr class="vcc-currency-info">';
        echo '<th colspan="2" style="text-align:center;font-size:12px;color:#666;padding:8px 0;">';
        if ($stripe_enabled) {
            printf(__('Your payment will be charged in %s via Stripe.', 'vignette-currency-converter'), esc_html($name));
        } else {
            printf(__('Prices shown in %s for reference. Payment is processed in GBP.', 'vignette-currency-converter'), esc_html($name));
        }
        echo '</th></tr>';
    }

    /**
     * Render global currency selector in page footer (fixed position)
     */
    public function render_global_currency_selector() {
        if (!$this->is_currency_selector_enabled()) {
            return;
        }

        $position = isset($this->settings['selector_position']) ? $this->settings['selector_position'] : 'fixed_footer';

        if ($position === 'shortcode_only') {
            return;
        }

        $this->render_selector('vcc-global-selector');
    }

    /**
     * Shortcode handler: [vcc_currency_selector]
     */
    public function shortcode_currency_selector($atts) {
        if (!$this->is_currency_selector_enabled()) {
            return '';
        }

        ob_start();
        $this->render_selector('vcc-inline-selector');
        return ob_get_clean();
    }

    /**
     * Register the currency selector widget
     */
    public function register_widget() {
        register_widget('VCC_Currency_Selector_Widget');
    }

    /**
     * AJAX handler for currency change
     */
    public function ajax_change_currency() {
        check_ajax_referer('vcc_currency_switch', 'nonce');

        $currency        = isset($_POST['currency'])      ? sanitize_text_field($_POST['currency'])      : 'GBP';
        $currency_from   = isset($_POST['currency_from']) ? sanitize_text_field($_POST['currency_from']) : '';
        $page_url        = isset($_POST['page_url'])      ? esc_url_raw($_POST['page_url'])              : '';
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

        // Log the switch event
        if ($manual_override && class_exists('VCC_Analytics')) {
            VCC_Analytics::get_instance()->log_switch_event($currency_from, $currency, $page_url);
        }

        wp_send_json_success(array(
            'message' => __('Currency updated', 'vignette-currency-converter'),
            'currency' => $currency,
            'manual_override' => $manual_override,
        ));
    }

    /**
     * Format price with currency symbol
     * Prefix currencies (symbol before): GBP, EUR, USD, CAD, AUD
     * Suffix currencies (code/symbol after with space): all others
     */
    private function format_price($amount, $symbol, $currency) {
        $formatted = number_format($amount, 2);

        $prefix_currencies = array('GBP', 'EUR', 'USD', 'CAD', 'AUD');
        if (in_array($currency, $prefix_currencies)) {
            return $symbol . $formatted;
        }

        // Suffix: "250.00 CHF", "250.00 NOK", "250.00 zł", etc.
        return $formatted . ' ' . $symbol;
    }

    /**
     * Get currency display name (for notices and info text)
     */
    private function get_currency_name($currency) {
        $all = VCC_Currency_Converter::get_all_currencies();
        if (isset($all[$currency])) {
            return $all[$currency]['flag'] . ' ' . $all[$currency]['name'] . ' (' . $currency . ')';
        }
        return $currency;
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

/**
 * Currency Selector Widget
 * Allows placing the currency selector in any widget area (e.g. header, sidebar)
 */
class VCC_Currency_Selector_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'vcc_currency_selector',
            __('Currency Selector', 'vignette-currency-converter'),
            array('description' => __('Display a currency selector dropdown. Also available as shortcode: [vcc_currency_selector]', 'vignette-currency-converter'))
        );
    }

    public function widget($args, $instance) {
        echo $args['before_widget'];

        if (!empty($instance['title'])) {
            echo $args['before_title'] . apply_filters('widget_title', esc_html($instance['title'])) . $args['after_title'];
        }

        echo do_shortcode('[vcc_currency_selector]');

        echo $args['after_widget'];
    }

    public function form($instance) {
        $title = !empty($instance['title']) ? $instance['title'] : '';
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">
                <?php esc_html_e('Title:', 'vignette-currency-converter'); ?>
            </label>
            <input class="widefat"
                   id="<?php echo esc_attr($this->get_field_id('title')); ?>"
                   name="<?php echo esc_attr($this->get_field_name('title')); ?>"
                   type="text"
                   value="<?php echo esc_attr($title); ?>" />
        </p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        $instance = array();
        $instance['title'] = !empty($new_instance['title']) ? sanitize_text_field($new_instance['title']) : '';
        return $instance;
    }
}

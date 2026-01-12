<?php
/**
 * Currency Converter
 * Handles currency conversion logic and WooCommerce product integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class VCC_Currency_Converter {

    private static $instance = null;
    private $api;
    private $settings;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->api = VCC_Currency_API::get_instance();
        $this->settings = get_option('vcc_settings', array());
        $this->init_hooks();
    }

    private function init_hooks() {
        // Add product meta boxes
        add_action('add_meta_boxes', array($this, 'add_product_meta_boxes'));

        // Save meta AND update prices - priority 20 runs AFTER WooCommerce (priority 10)
        add_action('woocommerce_process_product_meta', array($this, 'save_and_update_product'), 20);

        // Add custom columns to product list
        add_filter('manage_edit-product_columns', array($this, 'add_product_columns'));
        add_action('manage_product_posts_custom_column', array($this, 'render_product_columns'), 10, 2);
    }

    /**
     * Add meta boxes to product edit screen
     */
    public function add_product_meta_boxes() {
        add_meta_box(
            'vcc_product_currency',
            __('Vignette Currency Settings', 'vignette-currency-converter'),
            array($this, 'render_product_meta_box'),
            'product',
            'side',
            'high'
        );
    }

    /**
     * Render product meta box
     */
    public function render_product_meta_box($post) {
        wp_nonce_field('vcc_product_meta', 'vcc_product_meta_nonce');

        $source_currency = get_post_meta($post->ID, '_vcc_source_currency', true);
        $source_price = get_post_meta($post->ID, '_vcc_source_price', true);
        $auto_update = get_post_meta($post->ID, '_vcc_auto_update', true);

        $enabled_currencies = isset($this->settings['enabled_currencies'])
            ? $this->settings['enabled_currencies']
            : array('GBP', 'EUR', 'USD', 'CHF', 'CAD', 'AUD', 'JPY');
        ?>
        <div class="vcc-product-meta">
            <p>
                <label for="vcc_source_currency">
                    <strong><?php _e('Source Currency', 'vignette-currency-converter'); ?></strong>
                </label>
                <select name="vcc_source_currency" id="vcc_source_currency" style="width: 100%;">
                    <option value=""><?php _e('Same as shop (GBP)', 'vignette-currency-converter'); ?></option>
                    <?php foreach ($enabled_currencies as $currency) : ?>
                        <option value="<?php echo esc_attr($currency); ?>" <?php selected($source_currency, $currency); ?>>
                            <?php echo esc_html($currency); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="description">
                    <?php _e('The original currency this vignette is sold in.', 'vignette-currency-converter'); ?>
                </span>
            </p>

            <p>
                <label for="vcc_source_price">
                    <strong><?php _e('Source Price', 'vignette-currency-converter'); ?></strong>
                </label>
                <input
                    type="number"
                    name="vcc_source_price"
                    id="vcc_source_price"
                    value="<?php echo esc_attr($source_price); ?>"
                    step="0.01"
                    min="0"
                    style="width: 100%;"
                    placeholder="e.g., 40.00"
                />
                <span class="description">
                    <?php _e('Price in the source currency. Will be converted to GBP.', 'vignette-currency-converter'); ?>
                </span>
            </p>

            <p>
                <label>
                    <input
                        type="checkbox"
                        name="vcc_auto_update"
                        value="yes"
                        <?php checked($auto_update, 'yes'); ?>
                    />
                    <?php _e('Auto-update GBP price when exchange rates change', 'vignette-currency-converter'); ?>
                </label>
            </p>

            <?php if (!empty($source_currency) && !empty($source_price)) : ?>
                <p class="vcc-conversion-info">
                    <strong><?php _e('Current Conversion:', 'vignette-currency-converter'); ?></strong><br>
                    <?php
                    $gbp_price = $this->convert_to_gbp($source_price, $source_currency);
                    if (!is_wp_error($gbp_price)) {
                        echo sprintf(
                            __('%s %s = £%s GBP', 'vignette-currency-converter'),
                            esc_html($source_price),
                            esc_html($source_currency),
                            number_format($gbp_price, 2)
                        );
                    } else {
                        echo '<span style="color: red;">' . esc_html($gbp_price->get_error_message()) . '</span>';
                    }
                    ?>
                </p>
            <?php endif; ?>
        </div>

        <style>
            .vcc-product-meta p { margin-bottom: 15px; }
            .vcc-product-meta .description { display: block; margin-top: 5px; font-size: 12px; }
            .vcc-conversion-info {
                padding: 10px;
                background: #f0f0f1;
                border-left: 3px solid #2271b1;
                margin-top: 15px;
            }
        </style>
        <?php
    }

    /**
     * Save product meta and update GBP price
     * Called on woocommerce_process_product_meta with priority 20
     */
    public function save_and_update_product($post_id) {
        // First, save the custom meta fields
        $this->save_product_meta($post_id);

        // Then, update the GBP price based on saved meta
        $this->update_gbp_price($post_id);
    }

    /**
     * Save product meta
     */
    public function save_product_meta($post_id) {
        // Check nonce
        if (!isset($_POST['vcc_product_meta_nonce']) || !wp_verify_nonce($_POST['vcc_product_meta_nonce'], 'vcc_product_meta')) {
            return;
        }

        // Check autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Save source currency
        if (isset($_POST['vcc_source_currency'])) {
            update_post_meta($post_id, '_vcc_source_currency', sanitize_text_field($_POST['vcc_source_currency']));
        }

        // Save source price
        if (isset($_POST['vcc_source_price'])) {
            $source_price = floatval($_POST['vcc_source_price']);
            update_post_meta($post_id, '_vcc_source_price', $source_price);
        }

        // Save auto-update setting
        $auto_update = isset($_POST['vcc_auto_update']) ? 'yes' : 'no';
        update_post_meta($post_id, '_vcc_auto_update', $auto_update);
    }

    /**
     * Update GBP price when product is saved
     */
    public function update_gbp_price($post_id) {
        $source_currency = get_post_meta($post_id, '_vcc_source_currency', true);
        $source_price = get_post_meta($post_id, '_vcc_source_price', true);

        // If no source currency set, don't convert
        if (empty($source_currency) || empty($source_price)) {
            return;
        }

        // Convert to GBP
        $gbp_price = $this->convert_to_gbp($source_price, $source_currency);

        if (!is_wp_error($gbp_price)) {
            // Update the regular price
            update_post_meta($post_id, '_regular_price', $gbp_price);
            update_post_meta($post_id, '_price', $gbp_price);

            // Store conversion info
            update_post_meta($post_id, '_vcc_last_conversion_rate', $gbp_price / $source_price);
            update_post_meta($post_id, '_vcc_last_conversion_date', current_time('mysql'));
        }
    }

    /**
     * Convert price from source currency to GBP
     */
    public function convert_to_gbp($amount, $from_currency) {
        // If already GBP, return as-is
        if (strtoupper($from_currency) === 'GBP') {
            return floatval($amount);
        }

        return $this->api->convert($amount, $from_currency, 'GBP');
    }

    /**
     * Convert price from GBP to target currency
     */
    public function convert_from_gbp($amount, $to_currency) {
        // If target is GBP, return as-is
        if (strtoupper($to_currency) === 'GBP') {
            return floatval($amount);
        }

        return $this->api->convert($amount, 'GBP', $to_currency);
    }

    /**
     * Add custom columns to product list
     */
    public function add_product_columns($columns) {
        $new_columns = array();

        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;

            if ($key === 'price') {
                $new_columns['vcc_source_currency'] = __('Source Currency', 'vignette-currency-converter');
                $new_columns['vcc_source_price'] = __('Source Price', 'vignette-currency-converter');
            }
        }

        return $new_columns;
    }

    /**
     * Render custom product columns
     */
    public function render_product_columns($column, $post_id) {
        switch ($column) {
            case 'vcc_source_currency':
                $source_currency = get_post_meta($post_id, '_vcc_source_currency', true);
                echo $source_currency ? esc_html($source_currency) : '—';
                break;

            case 'vcc_source_price':
                $source_price = get_post_meta($post_id, '_vcc_source_price', true);
                $source_currency = get_post_meta($post_id, '_vcc_source_currency', true);

                if ($source_price && $source_currency) {
                    echo esc_html($source_currency . ' ' . number_format($source_price, 2));
                } else {
                    echo '—';
                }
                break;
        }
    }

    /**
     * Get list of available currencies
     */
    public function get_available_currencies() {
        $currencies = isset($this->settings['enabled_currencies'])
            ? $this->settings['enabled_currencies']
            : array('GBP', 'EUR', 'USD', 'CHF', 'CAD', 'AUD', 'JPY');

        return apply_filters('vcc_available_currencies', $currencies);
    }

    /**
     * Get currency symbol
     */
    public function get_currency_symbol($currency) {
        $symbols = array(
            'GBP' => '£',
            'EUR' => '€',
            'USD' => '$',
            'CHF' => 'CHF',
            'CAD' => 'CA$',
            'AUD' => 'A$',
            'JPY' => '¥',
        );

        return isset($symbols[$currency]) ? $symbols[$currency] : $currency;
    }
}

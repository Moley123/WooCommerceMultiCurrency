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

        // Variation hooks for variable products
        add_action('woocommerce_variation_options_pricing', array($this, 'render_variation_fields'), 10, 3);
        add_action('woocommerce_save_product_variation', array($this, 'save_variation_meta'), 10, 2);

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

            <hr style="margin: 15px 0; border: 0; border-top: 1px solid #ddd;">

            <p>
                <strong><?php _e('Markup Settings', 'vignette-currency-converter'); ?></strong>
                <span class="description" style="display: block; margin-top: 5px;">
                    <?php _e('Override global markup for this product', 'vignette-currency-converter'); ?>
                </span>
            </p>

            <p>
                <label>
                    <input
                        type="checkbox"
                        name="vcc_use_custom_markup"
                        id="vcc_use_custom_markup"
                        value="yes"
                        <?php checked(get_post_meta($post->ID, '_vcc_use_custom_markup', true), 'yes'); ?>
                    />
                    <?php _e('Use custom markup for this product', 'vignette-currency-converter'); ?>
                </label>
            </p>

            <div id="vcc-custom-markup-fields" style="<?php echo get_post_meta($post->ID, '_vcc_use_custom_markup', true) === 'yes' ? '' : 'display:none;'; ?>">
                <p>
                    <label for="vcc_markup_type">
                        <strong><?php _e('Markup Type', 'vignette-currency-converter'); ?></strong>
                    </label>
                    <select name="vcc_markup_type" id="vcc_markup_type" style="width: 100%;">
                        <option value="percentage" <?php selected(get_post_meta($post->ID, '_vcc_markup_type', true) ?: 'percentage', 'percentage'); ?>>
                            <?php _e('Percentage (%)', 'vignette-currency-converter'); ?>
                        </option>
                        <option value="fixed" <?php selected(get_post_meta($post->ID, '_vcc_markup_type', true), 'fixed'); ?>>
                            <?php _e('Fixed Amount (in source currency)', 'vignette-currency-converter'); ?>
                        </option>
                    </select>
                </p>

                <p>
                    <label for="vcc_markup_value">
                        <strong><?php _e('Markup Value', 'vignette-currency-converter'); ?></strong>
                    </label>
                    <input
                        type="number"
                        name="vcc_markup_value"
                        id="vcc_markup_value"
                        value="<?php echo esc_attr(get_post_meta($post->ID, '_vcc_markup_value', true)); ?>"
                        step="0.01"
                        min="0"
                        style="width: 100%;"
                        placeholder="e.g., 20 for 20% or 5.00 for fixed"
                    />
                    <span class="description">
                        <?php _e('Enter markup value (e.g., 20 for 20% or 5.00 for fixed amount)', 'vignette-currency-converter'); ?>
                    </span>
                </p>
            </div>

            <hr style="margin: 15px 0; border: 0; border-top: 1px solid #ddd;">

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
                    // Apply markup to source price
                    $final_source_price = $this->apply_markup($post->ID, $source_price);
                    $gbp_price = $this->convert_to_gbp($final_source_price, $source_currency);

                    if (!is_wp_error($gbp_price)) {
                        // Show source price
                        echo sprintf(
                            __('Base: %s %s', 'vignette-currency-converter'),
                            esc_html(number_format($source_price, 2)),
                            esc_html($source_currency)
                        );

                        // Show markup if applied
                        if ($final_source_price != $source_price) {
                            echo '<br>';
                            echo sprintf(
                                __('With Markup: %s %s', 'vignette-currency-converter'),
                                esc_html(number_format($final_source_price, 2)),
                                esc_html($source_currency)
                            );
                        }

                        // Show GBP conversion
                        echo '<br>';
                        echo '<strong>' . sprintf(
                            __('= £%s GBP', 'vignette-currency-converter'),
                            number_format($gbp_price, 2)
                        ) . '</strong>';
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

        <script>
        jQuery(document).ready(function($) {
            // Toggle custom markup fields
            $('#vcc_use_custom_markup').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#vcc-custom-markup-fields').slideDown();
                } else {
                    $('#vcc-custom-markup-fields').slideUp();
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Render variation-specific currency fields
     * Hooked to woocommerce_variation_options_pricing
     */
    public function render_variation_fields($loop, $variation_data, $variation) {
        $variation_id = $variation->ID;
        $parent_id = wp_get_post_parent_id($variation_id);

        // Get parent's source currency
        $parent_currency = get_post_meta($parent_id, '_vcc_source_currency', true);

        // Get variation's source price
        $variation_source_price = get_post_meta($variation_id, '_vcc_source_price', true);

        // Get variation markup settings
        $use_custom_markup = get_post_meta($variation_id, '_vcc_use_custom_markup', true);
        $markup_type = get_post_meta($variation_id, '_vcc_markup_type', true) ?: 'percentage';
        $markup_value = get_post_meta($variation_id, '_vcc_markup_value', true);

        ?>
        <div class="vcc-variation-fields" style="padding: 10px 12px; background: #f9f9f9; border: 1px solid #ddd; margin: 10px 0;">
            <h4 style="margin-top: 0;"><?php _e('Vignette Currency Settings', 'vignette-currency-converter'); ?></h4>

            <?php if (!empty($parent_currency)) : ?>
                <p style="margin-bottom: 10px;">
                    <strong><?php _e('Source Currency (from parent):', 'vignette-currency-converter'); ?></strong>
                    <span style="display: inline-block; padding: 3px 8px; background: #2271b1; color: white; border-radius: 3px; font-size: 11px;">
                        <?php echo esc_html($parent_currency); ?>
                    </span>
                </p>

                <p class="form-row form-row-full">
                    <label>
                        <?php _e('Source Price', 'vignette-currency-converter'); ?>
                        <input
                            type="number"
                            name="vcc_variation_source_price[<?php echo $loop; ?>]"
                            value="<?php echo esc_attr($variation_source_price); ?>"
                            step="0.01"
                            min="0"
                            placeholder="e.g., 15.00"
                            style="width: 100%;"
                        />
                    </label>
                    <span class="description">
                        <?php printf(__('Price in %s (will be converted to GBP)', 'vignette-currency-converter'), esc_html($parent_currency)); ?>
                    </span>
                </p>

                <p class="form-row form-row-full">
                    <label>
                        <input
                            type="checkbox"
                            name="vcc_variation_use_custom_markup[<?php echo $loop; ?>]"
                            value="yes"
                            class="vcc-variation-custom-markup-checkbox"
                            data-loop="<?php echo $loop; ?>"
                            <?php checked($use_custom_markup, 'yes'); ?>
                        />
                        <?php _e('Use custom markup for this variation', 'vignette-currency-converter'); ?>
                    </label>
                </p>

                <div class="vcc-variation-markup-fields-<?php echo $loop; ?>" style="<?php echo $use_custom_markup === 'yes' ? '' : 'display:none;'; ?>">
                    <p class="form-row form-row-first">
                        <label>
                            <?php _e('Markup Type', 'vignette-currency-converter'); ?>
                            <select name="vcc_variation_markup_type[<?php echo $loop; ?>]" style="width: 100%;">
                                <option value="percentage" <?php selected($markup_type, 'percentage'); ?>>
                                    <?php _e('Percentage (%)', 'vignette-currency-converter'); ?>
                                </option>
                                <option value="fixed" <?php selected($markup_type, 'fixed'); ?>>
                                    <?php _e('Fixed Amount', 'vignette-currency-converter'); ?>
                                </option>
                            </select>
                        </label>
                    </p>

                    <p class="form-row form-row-last">
                        <label>
                            <?php _e('Markup Value', 'vignette-currency-converter'); ?>
                            <input
                                type="number"
                                name="vcc_variation_markup_value[<?php echo $loop; ?>]"
                                value="<?php echo esc_attr($markup_value); ?>"
                                step="0.01"
                                min="0"
                                placeholder="e.g., 20"
                                style="width: 100%;"
                            />
                        </label>
                    </p>
                </div>

                <?php if (!empty($variation_source_price)) :
                    // Calculate preview
                    $final_price = $this->apply_markup($variation_id, $variation_source_price);
                    $gbp_price = $this->convert_to_gbp($final_price, $parent_currency);

                    if (!is_wp_error($gbp_price)) :
                ?>
                    <p class="form-row form-row-full" style="background: #fff; padding: 8px; border-left: 3px solid #2271b1; margin-top: 10px;">
                        <strong><?php _e('Preview:', 'vignette-currency-converter'); ?></strong><br>
                        <?php
                        echo esc_html($parent_currency) . ' ' . number_format($variation_source_price, 2);

                        if ($final_price != $variation_source_price) {
                            echo ' + markup = ' . esc_html($parent_currency) . ' ' . number_format($final_price, 2);
                        }

                        echo ' → <strong>£' . number_format($gbp_price, 2) . ' GBP</strong>';
                        ?>
                    </p>
                <?php
                    endif;
                endif;
                ?>

            <?php else : ?>
                <p style="color: #666; font-style: italic;">
                    <?php _e('Please set a Source Currency on the parent product first.', 'vignette-currency-converter'); ?>
                </p>
            <?php endif; ?>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('.vcc-variation-custom-markup-checkbox').on('change', function() {
                var loop = $(this).data('loop');
                var $fields = $('.vcc-variation-markup-fields-' + loop);

                if ($(this).is(':checked')) {
                    $fields.slideDown();
                } else {
                    $fields.slideUp();
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Save variation meta data
     * Hooked to woocommerce_save_product_variation
     */
    public function save_variation_meta($variation_id, $i) {
        // Save variation source price
        if (isset($_POST['vcc_variation_source_price'][$i])) {
            $source_price = floatval($_POST['vcc_variation_source_price'][$i]);
            update_post_meta($variation_id, '_vcc_source_price', $source_price);
        }

        // Save variation markup settings
        $use_custom_markup = isset($_POST['vcc_variation_use_custom_markup'][$i]) ? 'yes' : 'no';
        update_post_meta($variation_id, '_vcc_use_custom_markup', $use_custom_markup);

        if (isset($_POST['vcc_variation_markup_type'][$i])) {
            update_post_meta($variation_id, '_vcc_markup_type', sanitize_text_field($_POST['vcc_variation_markup_type'][$i]));
        }

        if (isset($_POST['vcc_variation_markup_value'][$i])) {
            $markup_value = floatval($_POST['vcc_variation_markup_value'][$i]);
            update_post_meta($variation_id, '_vcc_markup_value', $markup_value);
        }

        // Update variation GBP price
        $this->update_variation_gbp_price($variation_id);
    }

    /**
     * Update variation GBP price based on source price and parent currency
     */
    public function update_variation_gbp_price($variation_id) {
        // Get source price from variation
        $source_price = get_post_meta($variation_id, '_vcc_source_price', true);

        if (empty($source_price)) {
            return;
        }

        // Get source currency from parent product
        $variation = wc_get_product($variation_id);
        if (!$variation) {
            return;
        }

        $parent_id = $variation->get_parent_id();
        if (!$parent_id) {
            return;
        }

        $source_currency = get_post_meta($parent_id, '_vcc_source_currency', true);

        if (empty($source_currency)) {
            return;
        }

        // Apply markup (checks variation, then parent, then global)
        $final_source_price = $this->apply_markup($variation_id, $source_price);

        // Convert to GBP
        $gbp_price = $this->convert_to_gbp($final_source_price, $source_currency);

        if (!is_wp_error($gbp_price)) {
            // Update variation prices
            update_post_meta($variation_id, '_regular_price', $gbp_price);
            update_post_meta($variation_id, '_price', $gbp_price);

            // Store conversion info
            update_post_meta($variation_id, '_vcc_last_conversion_rate', $gbp_price / $final_source_price);
            update_post_meta($variation_id, '_vcc_last_conversion_date', current_time('mysql'));

            // Sync variation price with parent
            WC_Product_Variable::sync($parent_id);
        }
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

        // Save markup settings
        $use_custom_markup = isset($_POST['vcc_use_custom_markup']) ? 'yes' : 'no';
        update_post_meta($post_id, '_vcc_use_custom_markup', $use_custom_markup);

        if (isset($_POST['vcc_markup_type'])) {
            update_post_meta($post_id, '_vcc_markup_type', sanitize_text_field($_POST['vcc_markup_type']));
        }

        if (isset($_POST['vcc_markup_value'])) {
            $markup_value = floatval($_POST['vcc_markup_value']);
            update_post_meta($post_id, '_vcc_markup_value', $markup_value);
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

        // Apply markup to source price if enabled
        $final_source_price = $this->apply_markup($post_id, $source_price);

        // Convert to GBP (with markup already applied)
        $gbp_price = $this->convert_to_gbp($final_source_price, $source_currency);

        if (!is_wp_error($gbp_price)) {
            // Update the regular price
            update_post_meta($post_id, '_regular_price', $gbp_price);
            update_post_meta($post_id, '_price', $gbp_price);

            // Store conversion info
            update_post_meta($post_id, '_vcc_last_conversion_rate', $gbp_price / $final_source_price);
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
     * Apply markup to source price
     * Priority: Variation custom markup > Parent product markup > Global markup
     *
     * @param int $post_id Product or Variation ID
     * @param float $source_price Original source price
     * @return float Price with markup applied
     */
    public function apply_markup($post_id, $source_price) {
        $parent_id = 0;

        // Check if this is a variation
        $product = wc_get_product($post_id);
        if ($product && $product->get_parent_id() > 0) {
            $parent_id = $product->get_parent_id();
        }

        // Priority 1: Check variation/product custom markup
        $use_custom_markup = get_post_meta($post_id, '_vcc_use_custom_markup', true);

        if ($use_custom_markup === 'yes') {
            // Use variation/product-specific markup
            $markup_type = get_post_meta($post_id, '_vcc_markup_type', true) ?: 'percentage';
            $markup_value = floatval(get_post_meta($post_id, '_vcc_markup_value', true));
        } elseif ($parent_id > 0) {
            // Priority 2: For variations, check parent product markup
            $parent_custom_markup = get_post_meta($parent_id, '_vcc_use_custom_markup', true);

            if ($parent_custom_markup === 'yes') {
                $markup_type = get_post_meta($parent_id, '_vcc_markup_type', true) ?: 'percentage';
                $markup_value = floatval(get_post_meta($parent_id, '_vcc_markup_value', true));
            } else {
                // Priority 3: Fall back to global markup
                return $this->apply_global_markup($source_price);
            }
        } else {
            // Priority 3: Simple product without custom markup - use global
            return $this->apply_global_markup($source_price);
        }

        // If markup value is 0, return original price
        if ($markup_value <= 0) {
            return $source_price;
        }

        // Apply markup based on type
        if ($markup_type === 'percentage') {
            // Apply percentage markup (e.g., 20% = multiply by 1.20)
            return $source_price * (1 + ($markup_value / 100));
        } else {
            // Apply fixed amount markup (add to source price)
            return $source_price + $markup_value;
        }
    }

    /**
     * Apply global markup settings
     *
     * @param float $source_price Original source price
     * @return float Price with global markup applied
     */
    private function apply_global_markup($source_price) {
        // Use global markup settings
        $enable_markup = isset($this->settings['enable_markup']) ? $this->settings['enable_markup'] : 'no';

        // If global markup is not enabled, return original price
        if ($enable_markup !== 'yes') {
            return $source_price;
        }

        $markup_type = isset($this->settings['default_markup_type']) ? $this->settings['default_markup_type'] : 'percentage';
        $markup_value = isset($this->settings['default_markup_value']) ? floatval($this->settings['default_markup_value']) : 0;

        // If markup value is 0, return original price
        if ($markup_value <= 0) {
            return $source_price;
        }

        // Apply markup based on type
        if ($markup_type === 'percentage') {
            return $source_price * (1 + ($markup_value / 100));
        } else {
            return $source_price + $markup_value;
        }
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

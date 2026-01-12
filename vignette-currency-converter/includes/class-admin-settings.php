<?php
/**
 * Admin Settings
 * Handles plugin settings page and configuration
 */

if (!defined('ABSPATH')) {
    exit;
}

class VCC_Admin_Settings {

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
        $this->init_hooks();
    }

    private function init_hooks() {
        // Add settings page to WooCommerce menu
        add_action('admin_menu', array($this, 'add_settings_page'));

        // Register settings
        add_action('admin_init', array($this, 'register_settings'));

        // AJAX handlers
        add_action('wp_ajax_vcc_test_api', array($this, 'ajax_test_api'));
        add_action('wp_ajax_vcc_clear_cache', array($this, 'ajax_clear_cache'));
        add_action('wp_ajax_vcc_update_all_prices', array($this, 'ajax_update_all_prices'));
    }

    /**
     * Add settings page to admin menu
     */
    public function add_settings_page() {
        add_submenu_page(
            'woocommerce',
            __('Vignette Currency Converter', 'vignette-currency-converter'),
            __('Currency Converter', 'vignette-currency-converter'),
            'manage_woocommerce',
            'vcc-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('vcc_settings_group', 'vcc_settings', array($this, 'sanitize_settings'));
    }

    /**
     * Sanitize settings before saving
     */
    public function sanitize_settings($input) {
        $sanitized = array();

        // API provider
        $sanitized['api_provider'] = isset($input['api_provider']) ? sanitize_text_field($input['api_provider']) : 'exchangerate-api';

        // API key
        $sanitized['api_key'] = isset($input['api_key']) ? sanitize_text_field($input['api_key']) : '';

        // Base currency
        $sanitized['base_currency'] = isset($input['base_currency']) ? sanitize_text_field($input['base_currency']) : 'GBP';

        // Enabled currencies
        $sanitized['enabled_currencies'] = isset($input['enabled_currencies']) && is_array($input['enabled_currencies'])
            ? array_map('sanitize_text_field', $input['enabled_currencies'])
            : array('GBP', 'EUR', 'USD', 'CHF', 'CAD', 'AUD', 'JPY');

        // Cache duration
        $sanitized['cache_duration'] = isset($input['cache_duration']) ? intval($input['cache_duration']) : 12;

        // Show currency selector
        $sanitized['show_currency_selector'] = isset($input['show_currency_selector']) ? 'yes' : 'no';

        // Selector position
        $sanitized['selector_position'] = isset($input['selector_position']) ? sanitize_text_field($input['selector_position']) : 'before_add_to_cart';

        // Markup settings
        $sanitized['enable_markup'] = isset($input['enable_markup']) ? 'yes' : 'no';
        $sanitized['default_markup_type'] = isset($input['default_markup_type']) ? sanitize_text_field($input['default_markup_type']) : 'percentage';
        $sanitized['default_markup_value'] = isset($input['default_markup_value']) ? floatval($input['default_markup_value']) : 0;

        return $sanitized;
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        // Check if settings were saved
        if (isset($_GET['settings-updated'])) {
            add_settings_error(
                'vcc_messages',
                'vcc_message',
                __('Settings saved successfully.', 'vignette-currency-converter'),
                'updated'
            );
        }

        $this->settings = get_option('vcc_settings', array());
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php settings_errors('vcc_messages'); ?>

            <form method="post" action="options.php">
                <?php
                settings_fields('vcc_settings_group');
                ?>

                <table class="form-table">
                    <tbody>
                        <!-- API Configuration -->
                        <tr>
                            <th colspan="2">
                                <h2><?php _e('API Configuration', 'vignette-currency-converter'); ?></h2>
                            </th>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="api_key"><?php _e('ExchangeRate-API Key', 'vignette-currency-converter'); ?></label>
                            </th>
                            <td>
                                <input
                                    type="text"
                                    id="api_key"
                                    name="vcc_settings[api_key]"
                                    value="<?php echo esc_attr($this->settings['api_key'] ?? ''); ?>"
                                    class="regular-text"
                                    placeholder="Enter your API key"
                                />
                                <p class="description">
                                    <?php
                                    printf(
                                        __('Get your free API key from %s', 'vignette-currency-converter'),
                                        '<a href="https://www.exchangerate-api.com/" target="_blank">ExchangeRate-API.com</a>'
                                    );
                                    ?>
                                </p>
                                <button type="button" id="vcc-test-api" class="button">
                                    <?php _e('Test API Connection', 'vignette-currency-converter'); ?>
                                </button>
                                <span id="vcc-test-result"></span>
                            </td>
                        </tr>

                        <!-- Currency Settings -->
                        <tr>
                            <th colspan="2">
                                <h2><?php _e('Currency Settings', 'vignette-currency-converter'); ?></h2>
                            </th>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="base_currency"><?php _e('Base Currency', 'vignette-currency-converter'); ?></label>
                            </th>
                            <td>
                                <input
                                    type="text"
                                    id="base_currency"
                                    name="vcc_settings[base_currency]"
                                    value="<?php echo esc_attr($this->settings['base_currency'] ?? 'GBP'); ?>"
                                    class="small-text"
                                    readonly
                                />
                                <p class="description">
                                    <?php _e('Your shop\'s base currency (GBP). All prices will be stored in this currency.', 'vignette-currency-converter'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label><?php _e('Enabled Currencies', 'vignette-currency-converter'); ?></label>
                            </th>
                            <td>
                                <?php
                                $available_currencies = array('GBP', 'EUR', 'USD', 'CHF', 'CAD', 'AUD', 'JPY');
                                $enabled_currencies = $this->settings['enabled_currencies'] ?? array('GBP', 'EUR', 'USD', 'CHF', 'CAD', 'AUD', 'JPY');

                                foreach ($available_currencies as $currency) {
                                    $checked = in_array($currency, $enabled_currencies);
                                    $disabled = ($currency === 'GBP') ? 'disabled' : '';
                                    ?>
                                    <label style="display: inline-block; margin-right: 15px;">
                                        <input
                                            type="checkbox"
                                            name="vcc_settings[enabled_currencies][]"
                                            value="<?php echo esc_attr($currency); ?>"
                                            <?php checked($checked); ?>
                                            <?php echo $disabled; ?>
                                        />
                                        <?php echo esc_html($currency); ?>
                                    </label>
                                    <?php
                                }
                                ?>
                                <p class="description">
                                    <?php _e('Select which currencies customers can use. GBP is always enabled.', 'vignette-currency-converter'); ?>
                                </p>
                            </td>
                        </tr>

                        <!-- Cache Settings -->
                        <tr>
                            <th colspan="2">
                                <h2><?php _e('Cache Settings', 'vignette-currency-converter'); ?></h2>
                            </th>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="cache_duration"><?php _e('Cache Duration', 'vignette-currency-converter'); ?></label>
                            </th>
                            <td>
                                <input
                                    type="number"
                                    id="cache_duration"
                                    name="vcc_settings[cache_duration]"
                                    value="<?php echo esc_attr($this->settings['cache_duration'] ?? 12); ?>"
                                    min="1"
                                    max="168"
                                    class="small-text"
                                />
                                <span><?php _e('hours', 'vignette-currency-converter'); ?></span>
                                <p class="description">
                                    <?php _e('How long to cache exchange rates. Recommended: 12 hours.', 'vignette-currency-converter'); ?>
                                </p>
                                <button type="button" id="vcc-clear-cache" class="button">
                                    <?php _e('Clear Cache Now', 'vignette-currency-converter'); ?>
                                </button>
                                <span id="vcc-clear-cache-result"></span>
                            </td>
                        </tr>

                        <!-- Display Settings -->
                        <tr>
                            <th colspan="2">
                                <h2><?php _e('Display Settings', 'vignette-currency-converter'); ?></h2>
                            </th>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="show_currency_selector"><?php _e('Show Currency Selector', 'vignette-currency-converter'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input
                                        type="checkbox"
                                        id="show_currency_selector"
                                        name="vcc_settings[show_currency_selector]"
                                        value="yes"
                                        <?php checked($this->settings['show_currency_selector'] ?? 'yes', 'yes'); ?>
                                    />
                                    <?php _e('Allow customers to select their preferred currency', 'vignette-currency-converter'); ?>
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="selector_position"><?php _e('Selector Position', 'vignette-currency-converter'); ?></label>
                            </th>
                            <td>
                                <select id="selector_position" name="vcc_settings[selector_position]">
                                    <option value="before_add_to_cart" <?php selected($this->settings['selector_position'] ?? 'before_add_to_cart', 'before_add_to_cart'); ?>>
                                        <?php _e('Before Add to Cart Button', 'vignette-currency-converter'); ?>
                                    </option>
                                    <option value="after_add_to_cart" <?php selected($this->settings['selector_position'] ?? '', 'after_add_to_cart'); ?>>
                                        <?php _e('After Add to Cart Button', 'vignette-currency-converter'); ?>
                                    </option>
                                    <option value="before_price" <?php selected($this->settings['selector_position'] ?? '', 'before_price'); ?>>
                                        <?php _e('Before Price', 'vignette-currency-converter'); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>

                        <!-- Markup Settings -->
                        <tr>
                            <th colspan="2">
                                <h2><?php _e('Markup Settings', 'vignette-currency-converter'); ?></h2>
                            </th>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="enable_markup"><?php _e('Enable Markup', 'vignette-currency-converter'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input
                                        type="checkbox"
                                        id="enable_markup"
                                        name="vcc_settings[enable_markup]"
                                        value="yes"
                                        <?php checked($this->settings['enable_markup'] ?? 'no', 'yes'); ?>
                                    />
                                    <?php _e('Add markup to vignette prices', 'vignette-currency-converter'); ?>
                                </label>
                                <p class="description">
                                    <?php _e('Apply a default markup to all products. Can be overridden per product.', 'vignette-currency-converter'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="default_markup_type"><?php _e('Default Markup Type', 'vignette-currency-converter'); ?></label>
                            </th>
                            <td>
                                <select id="default_markup_type" name="vcc_settings[default_markup_type]">
                                    <option value="percentage" <?php selected($this->settings['default_markup_type'] ?? 'percentage', 'percentage'); ?>>
                                        <?php _e('Percentage (%)', 'vignette-currency-converter'); ?>
                                    </option>
                                    <option value="fixed" <?php selected($this->settings['default_markup_type'] ?? '', 'fixed'); ?>>
                                        <?php _e('Fixed Amount (£)', 'vignette-currency-converter'); ?>
                                    </option>
                                </select>
                                <p class="description">
                                    <?php _e('Choose whether to apply markup as a percentage or fixed amount.', 'vignette-currency-converter'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="default_markup_value"><?php _e('Default Markup Value', 'vignette-currency-converter'); ?></label>
                            </th>
                            <td>
                                <input
                                    type="number"
                                    id="default_markup_value"
                                    name="vcc_settings[default_markup_value]"
                                    value="<?php echo esc_attr($this->settings['default_markup_value'] ?? 0); ?>"
                                    step="0.01"
                                    min="0"
                                    class="small-text"
                                />
                                <span id="vcc-markup-unit">
                                    <?php
                                    $markup_type = $this->settings['default_markup_type'] ?? 'percentage';
                                    echo $markup_type === 'percentage' ? '%' : '£';
                                    ?>
                                </span>
                                <p class="description">
                                    <?php _e('Default markup value. Examples: 20 for 20% or 5.00 for £5.00', 'vignette-currency-converter'); ?>
                                </p>
                            </td>
                        </tr>

                        <!-- Bulk Actions -->
                        <tr>
                            <th colspan="2">
                                <h2><?php _e('Bulk Actions', 'vignette-currency-converter'); ?></h2>
                            </th>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label><?php _e('Update All Prices', 'vignette-currency-converter'); ?></label>
                            </th>
                            <td>
                                <button type="button" id="vcc-update-all-prices" class="button">
                                    <?php _e('Update All Product Prices from Exchange Rates', 'vignette-currency-converter'); ?>
                                </button>
                                <p class="description">
                                    <?php _e('Recalculate GBP prices for all products with source currencies using current exchange rates.', 'vignette-currency-converter'); ?>
                                </p>
                                <div id="vcc-update-progress" style="display: none; margin-top: 10px;">
                                    <progress id="vcc-progress-bar" value="0" max="100" style="width: 100%;"></progress>
                                    <p id="vcc-progress-text"></p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button(__('Save Settings', 'vignette-currency-converter')); ?>
            </form>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Test API connection
            $('#vcc-test-api').on('click', function() {
                var $button = $(this);
                var $result = $('#vcc-test-result');

                $button.prop('disabled', true).text('<?php _e('Testing...', 'vignette-currency-converter'); ?>');
                $result.html('');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'vcc_test_api',
                        api_key: $('#api_key').val(),
                        nonce: '<?php echo wp_create_nonce('vcc_admin_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $result.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                        } else {
                            $result.html('<span style="color: red;">✗ ' + response.data.message + '</span>');
                        }
                    },
                    error: function() {
                        $result.html('<span style="color: red;">✗ <?php _e('Connection failed', 'vignette-currency-converter'); ?></span>');
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('<?php _e('Test API Connection', 'vignette-currency-converter'); ?>');
                    }
                });
            });

            // Clear cache
            $('#vcc-clear-cache').on('click', function() {
                var $button = $(this);
                var $result = $('#vcc-clear-cache-result');

                $button.prop('disabled', true);
                $result.html('');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'vcc_clear_cache',
                        nonce: '<?php echo wp_create_nonce('vcc_admin_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $result.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                            setTimeout(function() { $result.html(''); }, 3000);
                        }
                    },
                    complete: function() {
                        $button.prop('disabled', false);
                    }
                });
            });

            // Update all prices
            $('#vcc-update-all-prices').on('click', function() {
                if (!confirm('<?php _e('This will update GBP prices for all products. Continue?', 'vignette-currency-converter'); ?>')) {
                    return;
                }

                var $button = $(this);
                var $progress = $('#vcc-update-progress');
                var $progressBar = $('#vcc-progress-bar');
                var $progressText = $('#vcc-progress-text');

                $button.prop('disabled', true);
                $progress.show();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'vcc_update_all_prices',
                        nonce: '<?php echo wp_create_nonce('vcc_admin_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $progressBar.val(100);
                            $progressText.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                        } else {
                            $progressText.html('<span style="color: red;">✗ ' + response.data.message + '</span>');
                        }
                    },
                    complete: function() {
                        $button.prop('disabled', false);
                        setTimeout(function() { $progress.hide(); }, 5000);
                    }
                });
            });

            // Update markup unit when markup type changes
            $('#default_markup_type').on('change', function() {
                var unit = $(this).val() === 'percentage' ? '%' : '£';
                $('#vcc-markup-unit').text(unit);
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX: Test API connection
     */
    public function ajax_test_api() {
        check_ajax_referer('vcc_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'vignette-currency-converter')));
        }

        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';

        if (empty($api_key)) {
            wp_send_json_error(array('message' => __('Please enter an API key', 'vignette-currency-converter')));
        }

        // Temporarily update settings for test
        $temp_settings = get_option('vcc_settings', array());
        $temp_settings['api_key'] = $api_key;
        update_option('vcc_settings', $temp_settings);

        $api = VCC_Currency_API::get_instance();
        $result = $api->test_connection();

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array('message' => __('API connection successful!', 'vignette-currency-converter')));
    }

    /**
     * AJAX: Clear cache
     */
    public function ajax_clear_cache() {
        check_ajax_referer('vcc_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'vignette-currency-converter')));
        }

        $api = VCC_Currency_API::get_instance();
        $api->clear_cache();

        wp_send_json_success(array('message' => __('Cache cleared successfully', 'vignette-currency-converter')));
    }

    /**
     * AJAX: Update all prices
     */
    public function ajax_update_all_prices() {
        check_ajax_referer('vcc_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'vignette-currency-converter')));
        }

        $converter = VCC_Currency_Converter::get_instance();

        // Get all products with source currency
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_vcc_source_currency',
                    'compare' => 'EXISTS',
                ),
            ),
        );

        $products = get_posts($args);
        $updated = 0;

        foreach ($products as $product) {
            $source_currency = get_post_meta($product->ID, '_vcc_source_currency', true);
            $source_price = get_post_meta($product->ID, '_vcc_source_price', true);

            if (!empty($source_currency) && !empty($source_price)) {
                $gbp_price = $converter->convert_to_gbp($source_price, $source_currency);

                if (!is_wp_error($gbp_price)) {
                    update_post_meta($product->ID, '_regular_price', $gbp_price);
                    update_post_meta($product->ID, '_price', $gbp_price);
                    update_post_meta($product->ID, '_vcc_last_conversion_date', current_time('mysql'));
                    $updated++;
                }
            }
        }

        wp_send_json_success(array(
            'message' => sprintf(
                __('Updated %d product(s) successfully', 'vignette-currency-converter'),
                $updated
            ),
        ));
    }
}

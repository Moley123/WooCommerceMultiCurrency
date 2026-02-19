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
        add_action('wp_ajax_vcc_run_analytics_cleanup', array($this, 'ajax_run_analytics_cleanup'));
    }

    /**
     * Add settings page under the shared EMEL WP Plugins top-level menu.
     * Creates the top-level menu only if no other EMEL plugin has done so already.
     */
    public function add_settings_page() {
        $parent_slug = 'emel-wp-plugins';

        if ( ! isset( $GLOBALS['admin_page_hooks'][ $parent_slug ] ) ) {
            add_menu_page(
                'EMEL WP Plugins',
                'EMEL WP Plugins',
                'manage_options',
                $parent_slug,
                '__return_null',
                'dashicons-admin-plugins',
                58
            );
            remove_submenu_page( $parent_slug, $parent_slug );
        }

        add_submenu_page(
            $parent_slug,
            __('Vignette Currency Converter', 'vignette-currency-converter'),
            __('Currency Converter', 'vignette-currency-converter'),
            'manage_options',
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

        // Ensure GBP is always included (it's the base currency)
        if (!in_array('GBP', $sanitized['enabled_currencies'])) {
            $sanitized['enabled_currencies'][] = 'GBP';
        }

        // Cache duration
        $sanitized['cache_duration'] = isset($input['cache_duration']) ? intval($input['cache_duration']) : 12;

        // Show currency selector
        $sanitized['show_currency_selector'] = isset($input['show_currency_selector']) ? 'yes' : 'no';

        // Auto-detection settings
        $sanitized['enable_auto_detection'] = isset($input['enable_auto_detection']) ? 'yes' : 'no';
        $sanitized['hide_selector_when_autodetect'] = isset($input['hide_selector_when_autodetect']) ? 'yes' : 'no';

        // Selector position
        $sanitized['selector_position'] = isset($input['selector_position']) ? sanitize_text_field($input['selector_position']) : 'fixed_footer';

        // Markup settings
        $sanitized['enable_markup'] = isset($input['enable_markup']) ? 'yes' : 'no';
        $sanitized['default_markup_type'] = isset($input['default_markup_type']) ? sanitize_text_field($input['default_markup_type']) : 'percentage';
        $sanitized['default_markup_value'] = isset($input['default_markup_value']) ? floatval($input['default_markup_value']) : 0;

        // Stripe multi-currency
        $sanitized['stripe_multicurrency_enabled'] = isset($input['stripe_multicurrency_enabled']) ? 'yes' : 'no';

        // Analytics
        $sanitized['analytics_enabled']        = isset($input['analytics_enabled']) ? 'yes' : 'no';
        $valid_retention = array(7, 14, 30, 90, 180, 365, 0);
        $retention = isset($input['analytics_retention_days']) ? (int) $input['analytics_retention_days'] : 90;
        $sanitized['analytics_retention_days'] = in_array($retention, $valid_retention) ? $retention : 90;

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
                                $all_currencies     = VCC_Currency_Converter::get_all_currencies();
                                $enabled_currencies = $this->settings['enabled_currencies'] ?? array_keys($all_currencies);

                                // GBP always included — hidden input covers the disabled checkbox
                                ?>
                                <input type="hidden" name="vcc_settings[enabled_currencies][]" value="GBP" />

                                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:8px;margin-bottom:12px;">
                                <?php foreach ($all_currencies as $code => $meta) :
                                    $checked   = in_array($code, $enabled_currencies);
                                    $disabled  = ($code === 'GBP') ? 'disabled' : '';
                                ?>
                                    <label style="display:flex;align-items:center;gap:6px;padding:6px 8px;background:#f9f9f9;border:1px solid #e0e0e0;border-radius:4px;cursor:pointer;">
                                        <input
                                            type="checkbox"
                                            name="vcc_settings[enabled_currencies][]"
                                            value="<?php echo esc_attr($code); ?>"
                                            <?php checked($checked); ?>
                                            <?php echo $disabled; ?>
                                            style="margin:0;"
                                        />
                                        <span style="font-size:18px;line-height:1;"><?php echo esc_html($meta['flag']); ?></span>
                                        <span><strong><?php echo esc_html($code); ?></strong> — <?php echo esc_html($meta['name']); ?> <span style="color:#999;">(<?php echo esc_html($meta['symbol']); ?>)</span></span>
                                    </label>
                                <?php endforeach; ?>
                                </div>

                                <!-- Custom currencies -->
                                <p style="margin-top:8px;margin-bottom:4px;font-weight:600;"><?php _e('Add custom currency:', 'vignette-currency-converter'); ?></p>
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                    <input
                                        type="text"
                                        id="vcc-custom-currency-input"
                                        placeholder="e.g. AED, SGD, TRY"
                                        maxlength="3"
                                        style="width:100px;text-transform:uppercase;"
                                    />
                                    <button type="button" id="vcc-add-custom-currency" class="button">
                                        <?php _e('Add', 'vignette-currency-converter'); ?>
                                    </button>
                                </div>
                                <div id="vcc-custom-currencies-list" style="margin-top:8px;display:flex;flex-wrap:wrap;gap:6px;">
                                <?php
                                // Render any already-saved custom currencies (codes not in the predefined list)
                                $predefined_codes = array_keys($all_currencies);
                                foreach ($enabled_currencies as $code) :
                                    if (!in_array($code, $predefined_codes) && $code !== 'GBP') :
                                ?>
                                    <span class="vcc-currency-tag" data-currency="<?php echo esc_attr($code); ?>" style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;background:#2271b1;color:#fff;border-radius:20px;font-size:12px;">
                                        <?php echo esc_html($code); ?>
                                        <input type="hidden" name="vcc_settings[enabled_currencies][]" value="<?php echo esc_attr($code); ?>" class="vcc-custom-currency-hidden" />
                                        <span class="vcc-currency-tag-remove" data-currency="<?php echo esc_attr($code); ?>" style="cursor:pointer;font-size:14px;line-height:1;">&times;</span>
                                    </span>
                                <?php
                                    endif;
                                endforeach;
                                ?>
                                </div>

                                <p class="description" style="margin-top:8px;">
                                    <?php _e('GBP is always enabled (base currency). Custom codes must be valid ISO 4217 currency codes supported by ExchangeRate-API.', 'vignette-currency-converter'); ?>
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
                                <label for="enable_auto_detection"><?php _e('Auto-Detect Currency', 'vignette-currency-converter'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input
                                        type="checkbox"
                                        id="enable_auto_detection"
                                        name="vcc_settings[enable_auto_detection]"
                                        value="yes"
                                        <?php checked($this->settings['enable_auto_detection'] ?? 'no', 'yes'); ?>
                                    />
                                    <?php _e('Automatically detect customer currency from IP address', 'vignette-currency-converter'); ?>
                                </label>
                                <p class="description">
                                    <?php _e('Uses ipapi.co (free tier: 1,000 requests/day). Detected currency is cached for 24 hours per IP.', 'vignette-currency-converter'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="hide_selector_when_autodetect"><?php _e('Hide Selector (Auto-Detect)', 'vignette-currency-converter'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input
                                        type="checkbox"
                                        id="hide_selector_when_autodetect"
                                        name="vcc_settings[hide_selector_when_autodetect]"
                                        value="yes"
                                        <?php checked($this->settings['hide_selector_when_autodetect'] ?? 'no', 'yes'); ?>
                                    />
                                    <?php _e('Hide currency selector when auto-detection is enabled', 'vignette-currency-converter'); ?>
                                </label>
                                <p class="description">
                                    <?php _e('When enabled, customers will see prices in their detected currency without seeing the selector dropdown.', 'vignette-currency-converter'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="selector_position"><?php _e('Selector Position', 'vignette-currency-converter'); ?></label>
                            </th>
                            <td>
                                <select id="selector_position" name="vcc_settings[selector_position]">
                                    <option value="fixed_footer" <?php selected($this->settings['selector_position'] ?? 'fixed_footer', 'fixed_footer'); ?>>
                                        <?php _e('Fixed Position (Bottom-Right Corner)', 'vignette-currency-converter'); ?>
                                    </option>
                                    <option value="shortcode_only" <?php selected($this->settings['selector_position'] ?? '', 'shortcode_only'); ?>>
                                        <?php _e('Shortcode / Widget Only', 'vignette-currency-converter'); ?>
                                    </option>
                                </select>
                                <p class="description">
                                    <?php _e('Use shortcode <code>[vcc_currency_selector]</code> or the "Currency Selector" widget to place the selector anywhere (e.g. header, navigation menu, sidebar).', 'vignette-currency-converter'); ?>
                                </p>
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

                        <!-- Stripe Multi-Currency -->
                        <tr>
                            <th colspan="2">
                                <h2><?php _e('Stripe Multi-Currency', 'vignette-currency-converter'); ?></h2>
                            </th>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="stripe_multicurrency_enabled"><?php _e('Enable Stripe Multi-Currency', 'vignette-currency-converter'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input
                                        type="checkbox"
                                        id="stripe_multicurrency_enabled"
                                        name="vcc_settings[stripe_multicurrency_enabled]"
                                        value="yes"
                                        <?php checked($this->settings['stripe_multicurrency_enabled'] ?? 'no', 'yes'); ?>
                                    />
                                    <?php _e('Charge customers in their selected currency via Stripe', 'vignette-currency-converter'); ?>
                                </label>
                                <p class="description">
                                    <?php _e('When enabled, the order currency and total are converted to the customer\'s selected currency at checkout. Stripe charges the customer in that currency and settles to your GBP bank account automatically (Stripe applies their standard FX conversion fee, typically ~2%).', 'vignette-currency-converter'); ?>
                                </p>
                                <p class="description">
                                    <strong><?php _e('Requirements:', 'vignette-currency-converter'); ?></strong>
                                    <?php _e('WooCommerce Stripe Gateway plugin must be installed and active. Your Stripe account must have the target currencies enabled (most are enabled by default).', 'vignette-currency-converter'); ?>
                                </p>
                                <p class="description" style="color:#d63638;">
                                    <?php _e('Note: When enabled, the GBP amount shown in WooCommerce orders will reflect the converted currency total, not the original GBP price. The original GBP amount is stored in the order meta for reference.', 'vignette-currency-converter'); ?>
                                </p>
                            </td>
                        </tr>

                        <!-- Analytics Settings -->
                        <tr>
                            <th colspan="2">
                                <h2><?php _e('Analytics', 'vignette-currency-converter'); ?></h2>
                            </th>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="analytics_enabled"><?php _e('Enable Analytics Logging', 'vignette-currency-converter'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input
                                        type="checkbox"
                                        id="analytics_enabled"
                                        name="vcc_settings[analytics_enabled]"
                                        value="yes"
                                        <?php checked($this->settings['analytics_enabled'] ?? 'yes', 'yes'); ?>
                                    />
                                    <?php _e('Log currency switch and checkout events', 'vignette-currency-converter'); ?>
                                </label>
                                <p class="description">
                                    <?php _e('Records every currency change (masked IP, country, city, page, device). IP geolocation uses ip-api.com and is cached for 24 hours.', 'vignette-currency-converter'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="analytics_retention_days"><?php _e('Data Retention', 'vignette-currency-converter'); ?></label>
                            </th>
                            <td>
                                <select id="analytics_retention_days" name="vcc_settings[analytics_retention_days]">
                                    <?php
                                    $retention = (int) ($this->settings['analytics_retention_days'] ?? 90);
                                    $options = array(
                                        7   => __('7 days',   'vignette-currency-converter'),
                                        14  => __('14 days',  'vignette-currency-converter'),
                                        30  => __('30 days',  'vignette-currency-converter'),
                                        90  => __('90 days',  'vignette-currency-converter'),
                                        180 => __('180 days', 'vignette-currency-converter'),
                                        365 => __('1 year',   'vignette-currency-converter'),
                                        0   => __('Keep forever', 'vignette-currency-converter'),
                                    );
                                    foreach ($options as $days => $label) {
                                        printf(
                                            '<option value="%d" %s>%s</option>',
                                            $days,
                                            selected($retention, $days, false),
                                            esc_html($label)
                                        );
                                    }
                                    ?>
                                </select>
                                <p class="description">
                                    <?php _e('Events older than this are deleted automatically each day.', 'vignette-currency-converter'); ?>
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

            <?php $this->render_analytics_section(); ?>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Custom currency add/remove
            var $input  = $('#vcc-custom-currency-input');
            var $addBtn = $('#vcc-add-custom-currency');
            var $list   = $('#vcc-custom-currencies-list');

            // Collect codes already rendered as predefined checkboxes
            var predefinedCodes = [];
            $('input[name="vcc_settings[enabled_currencies][]"]:not(.vcc-custom-currency-hidden)').each(function() {
                predefinedCodes.push($(this).val().toUpperCase());
            });

            function addCustomTag(code) {
                code = code.toUpperCase().trim().replace(/[^A-Z]/g, '');
                if (!code || code.length !== 3) {
                    alert('<?php _e('Please enter a valid 3-letter ISO currency code (e.g. AED, SGD)', 'vignette-currency-converter'); ?>');
                    return;
                }
                if (predefinedCodes.indexOf(code) !== -1) {
                    alert('<?php _e('This currency is already in the list above — just tick its checkbox.', 'vignette-currency-converter'); ?>');
                    return;
                }
                if ($('.vcc-currency-tag[data-currency="' + code + '"]').length) {
                    return; // already added
                }
                var $tag = $(
                    '<span class="vcc-currency-tag" data-currency="' + code + '" ' +
                    'style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;background:#2271b1;color:#fff;border-radius:20px;font-size:12px;">' +
                    code +
                    '<input type="hidden" name="vcc_settings[enabled_currencies][]" value="' + code + '" class="vcc-custom-currency-hidden" />' +
                    '<span class="vcc-currency-tag-remove" data-currency="' + code + '" style="cursor:pointer;font-size:14px;line-height:1;">&times;</span>' +
                    '</span>'
                );
                $list.append($tag);
            }

            $addBtn.on('click', function() {
                addCustomTag($input.val());
                $input.val('').focus();
            });

            $input.on('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); addCustomTag($input.val()); $input.val('').focus(); }
            });

            $(document).on('click', '.vcc-currency-tag-remove', function() {
                $(this).closest('.vcc-currency-tag').remove();
            });

            // Markup unit label
            $('#default_markup_type').on('change', function() {
                $('#vcc-markup-unit').text($(this).val() === 'percentage' ? '%' : '£');
            });

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

        });
        </script>
        <?php
    }

    /**
     * Render the analytics summary + events table below the settings form.
     */
    private function render_analytics_section() {
        if (!class_exists('VCC_Analytics')) return;

        $analytics = VCC_Analytics::get_instance();
        $summary   = $analytics->get_summary();

        // Pagination
        $current_page = isset($_GET['vcc_analytics_page']) ? max(1, (int) $_GET['vcc_analytics_page']) : 1;
        $per_page     = 25;
        $type_filter  = isset($_GET['vcc_analytics_type']) ? sanitize_text_field($_GET['vcc_analytics_type']) : '';
        $data         = $analytics->get_events($current_page, $per_page, $type_filter);
        $total_pages  = $data['total'] > 0 ? (int) ceil($data['total'] / $per_page) : 1;

        $nonce = wp_create_nonce('vcc_admin_nonce');
        ?>
        <hr style="margin: 30px 0;" />
        <h2><?php _e('Currency Usage Analytics', 'vignette-currency-converter'); ?></h2>

        <!-- Summary cards -->
        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:24px;">
            <div style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:16px 24px;min-width:160px;text-align:center;">
                <div style="font-size:28px;font-weight:700;color:#2271b1;"><?php echo number_format($summary['total_switches']); ?></div>
                <div style="font-size:12px;color:#666;margin-top:4px;"><?php _e('Currency Switches', 'vignette-currency-converter'); ?></div>
            </div>
            <div style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:16px 24px;min-width:160px;text-align:center;">
                <div style="font-size:28px;font-weight:700;color:#46b450;"><?php echo number_format($summary['total_checkouts']); ?></div>
                <div style="font-size:12px;color:#666;margin-top:4px;"><?php _e('Non-GBP Checkouts', 'vignette-currency-converter'); ?></div>
            </div>
            <?php if (!empty($summary['by_currency'])) : $top = $summary['by_currency'][0]; ?>
            <div style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:16px 24px;min-width:160px;text-align:center;">
                <div style="font-size:28px;font-weight:700;color:#333;"><?php echo esc_html($top['currency_to']); ?></div>
                <div style="font-size:12px;color:#666;margin-top:4px;"><?php _e('Most Switched To', 'vignette-currency-converter'); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($summary['oldest']) : ?>
            <div style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:16px 24px;min-width:200px;text-align:center;">
                <div style="font-size:13px;font-weight:600;color:#333;"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($summary['oldest']))); ?></div>
                <div style="font-size:11px;color:#666;">→ <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($summary['newest']))); ?></div>
                <div style="font-size:12px;color:#666;margin-top:4px;"><?php _e('Date Range', 'vignette-currency-converter'); ?></div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Top currencies + countries side by side -->
        <?php if (!empty($summary['by_currency']) || !empty($summary['by_country'])) : ?>
        <div style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:24px;">
            <?php if (!empty($summary['by_currency'])) : ?>
            <div style="flex:1;min-width:220px;">
                <h3 style="margin:0 0 8px;"><?php _e('Top Currencies', 'vignette-currency-converter'); ?></h3>
                <table class="widefat fixed striped" style="max-width:300px;">
                    <thead><tr>
                        <th><?php _e('Currency', 'vignette-currency-converter'); ?></th>
                        <th style="text-align:right;"><?php _e('Switches', 'vignette-currency-converter'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($summary['by_currency'] as $row) : ?>
                        <tr>
                            <td><?php echo esc_html($row['currency_to']); ?></td>
                            <td style="text-align:right;"><?php echo number_format((int)$row['cnt']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (!empty($summary['by_country'])) : ?>
            <div style="flex:1;min-width:220px;">
                <h3 style="margin:0 0 8px;"><?php _e('Top Countries', 'vignette-currency-converter'); ?></h3>
                <table class="widefat fixed striped" style="max-width:300px;">
                    <thead><tr>
                        <th><?php _e('Country', 'vignette-currency-converter'); ?></th>
                        <th style="text-align:right;"><?php _e('Switches', 'vignette-currency-converter'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($summary['by_country'] as $row) : ?>
                        <tr>
                            <td><?php echo esc_html($row['country']); ?></td>
                            <td style="text-align:right;"><?php echo number_format((int)$row['cnt']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Events table controls -->
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
            <div style="display:flex;gap:8px;align-items:center;">
                <strong><?php _e('Recent Events', 'vignette-currency-converter'); ?></strong>
                <?php
                $base_url   = admin_url('admin.php?page=vcc-settings');
                $filter_url = add_query_arg(array('vcc_analytics_page' => 1), $base_url);
                ?>
                <a href="<?php echo esc_url(add_query_arg('vcc_analytics_type', '', $filter_url)); ?>"
                   style="<?php echo !$type_filter ? 'font-weight:600;' : ''; ?>">
                    <?php _e('All', 'vignette-currency-converter'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('vcc_analytics_type', 'switch', $filter_url)); ?>"
                   style="<?php echo $type_filter === 'switch' ? 'font-weight:600;' : ''; ?>">
                    <?php _e('Switches', 'vignette-currency-converter'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('vcc_analytics_type', 'checkout', $filter_url)); ?>"
                   style="<?php echo $type_filter === 'checkout' ? 'font-weight:600;' : ''; ?>">
                    <?php _e('Checkouts', 'vignette-currency-converter'); ?>
                </a>
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
                <button type="button" id="vcc-run-cleanup" class="button">
                    <?php _e('Run Cleanup Now', 'vignette-currency-converter'); ?>
                </button>
                <button type="button" id="vcc-clear-analytics" class="button button-secondary"
                        style="color:#d63638;border-color:#d63638;">
                    <?php _e('Clear All Data', 'vignette-currency-converter'); ?>
                </button>
                <span id="vcc-analytics-result"></span>
            </div>
        </div>

        <!-- Events table -->
        <?php if (empty($data['rows'])) : ?>
            <p style="color:#666;"><?php _e('No events recorded yet.', 'vignette-currency-converter'); ?></p>
        <?php else : ?>
        <table class="widefat fixed striped" style="font-size:13px;">
            <thead><tr>
                <th style="width:140px;"><?php _e('Date / Time', 'vignette-currency-converter'); ?></th>
                <th style="width:80px;"><?php _e('Type', 'vignette-currency-converter'); ?></th>
                <th style="width:100px;"><?php _e('From → To', 'vignette-currency-converter'); ?></th>
                <th style="width:120px;"><?php _e('Location', 'vignette-currency-converter'); ?></th>
                <th style="width:80px;"><?php _e('IP', 'vignette-currency-converter'); ?></th>
                <th style="width:70px;"><?php _e('Device', 'vignette-currency-converter'); ?></th>
                <th><?php _e('Page', 'vignette-currency-converter'); ?></th>
                <th style="width:60px;"><?php _e('User', 'vignette-currency-converter'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($data['rows'] as $row) : ?>
                <tr>
                    <td><?php echo esc_html(date_i18n('d M Y H:i', strtotime($row['created_at']))); ?></td>
                    <td>
                        <?php if ($row['event_type'] === 'checkout') : ?>
                            <span style="background:#46b450;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">checkout</span>
                        <?php else : ?>
                            <span style="background:#2271b1;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">switch</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($row['currency_from']) : ?>
                            <?php echo esc_html($row['currency_from']); ?> → <?php echo esc_html($row['currency_to']); ?>
                        <?php else : ?>
                            <?php echo esc_html($row['currency_to']); ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $parts = array_filter(array($row['city'], $row['country']));
                        echo esc_html(implode(', ', $parts) ?: '—');
                        ?>
                    </td>
                    <td style="font-family:monospace;font-size:11px;"><?php echo esc_html($row['ip_masked'] ?: '—'); ?></td>
                    <td><?php echo esc_html($row['device_type'] ?: '—'); ?></td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?php if ($row['page_url']) : ?>
                            <a href="<?php echo esc_url($row['page_url']); ?>" target="_blank" title="<?php echo esc_attr($row['page_url']); ?>">
                                <?php echo esc_html(parse_url($row['page_url'], PHP_URL_PATH) ?: $row['page_url']); ?>
                            </a>
                        <?php else : ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        if ($row['user_id'] > 0) {
                            $user = get_userdata($row['user_id']);
                            echo $user ? esc_html($user->user_login) : '#' . (int)$row['user_id'];
                        } else {
                            echo 'guest';
                        }
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ($total_pages > 1) : ?>
        <div style="margin-top:12px;display:flex;gap:6px;align-items:center;">
            <?php
            $page_base = add_query_arg(
                array('vcc_analytics_type' => $type_filter ?: false),
                $base_url
            );
            for ($p = 1; $p <= $total_pages; $p++) :
                $url = add_query_arg('vcc_analytics_page', $p, $page_base);
                if ($p === $current_page) :
            ?>
                <span style="padding:4px 10px;background:#2271b1;color:#fff;border-radius:3px;"><?php echo $p; ?></span>
            <?php else : ?>
                <a href="<?php echo esc_url($url); ?>" style="padding:4px 10px;background:#f0f0f0;border-radius:3px;text-decoration:none;"><?php echo $p; ?></a>
            <?php
                endif;
            endfor;
            ?>
            <span style="color:#666;font-size:12px;">
                <?php printf(
                    __('Showing %d of %d events', 'vignette-currency-converter'),
                    count($data['rows']),
                    $data['total']
                ); ?>
            </span>
        </div>
        <?php endif; ?>

        <?php endif; // end if rows ?>

        <script>
        jQuery(document).ready(function($) {
            var nonce = '<?php echo esc_js($nonce); ?>';

            $('#vcc-clear-analytics').on('click', function() {
                if (!confirm('<?php esc_js(_e('Delete all analytics data permanently? This cannot be undone.', 'vignette-currency-converter')); ?>')) return;
                var $btn = $(this).prop('disabled', true);
                $.post(ajaxurl, { action: 'vcc_clear_analytics', nonce: nonce }, function(r) {
                    if (r.success) {
                        $('#vcc-analytics-result').html('<span style="color:green;">✓ ' + r.data.message + '</span>');
                        setTimeout(function() { window.location.reload(); }, 1000);
                    }
                }).always(function() { $btn.prop('disabled', false); });
            });

            $('#vcc-run-cleanup').on('click', function() {
                var $btn = $(this).prop('disabled', true);
                $.post(ajaxurl, { action: 'vcc_run_analytics_cleanup', nonce: nonce }, function(r) {
                    if (r.success) {
                        $('#vcc-analytics-result').html('<span style="color:green;">✓ ' + r.data.message + '</span>');
                        setTimeout(function() { window.location.reload(); }, 1000);
                    }
                }).always(function() { $btn.prop('disabled', false); });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX: Run analytics cleanup immediately (respects retention setting).
     */
    public function ajax_run_analytics_cleanup() {
        check_ajax_referer('vcc_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'vignette-currency-converter')));
        }
        VCC_Analytics::get_instance()->cleanup_old_events();
        wp_send_json_success(array('message' => __('Cleanup complete', 'vignette-currency-converter')));
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
     * AJAX: Update all prices (simple products and variations)
     */
    public function ajax_update_all_prices() {
        check_ajax_referer('vcc_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'vignette-currency-converter')));
        }

        $converter = VCC_Currency_Converter::get_instance();
        $updated = 0;

        // Update simple products with source currency
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

        foreach ($products as $product) {
            $source_currency = get_post_meta($product->ID, '_vcc_source_currency', true);
            $source_price = get_post_meta($product->ID, '_vcc_source_price', true);

            // Only update simple products (variable products don't have source prices)
            if (!empty($source_currency) && !empty($source_price)) {
                // Apply markup
                $final_source_price = $converter->apply_markup($product->ID, $source_price);
                $gbp_price = $converter->convert_to_gbp($final_source_price, $source_currency);

                if (!is_wp_error($gbp_price)) {
                    update_post_meta($product->ID, '_regular_price', $gbp_price);
                    update_post_meta($product->ID, '_price', $gbp_price);
                    update_post_meta($product->ID, '_vcc_last_conversion_date', current_time('mysql'));
                    $updated++;
                }
            }
        }

        // Update variations with source prices
        $variation_args = array(
            'post_type' => 'product_variation',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_vcc_source_price',
                    'compare' => 'EXISTS',
                ),
            ),
        );

        $variations = get_posts($variation_args);

        foreach ($variations as $variation) {
            $source_price = get_post_meta($variation->ID, '_vcc_source_price', true);

            if (!empty($source_price)) {
                // Use the converter's variation update method (handles parent currency lookup)
                $converter->update_variation_gbp_price($variation->ID);
                $updated++;
            }
        }

        wp_send_json_success(array(
            'message' => sprintf(
                __('Updated %d product(s) and variation(s) successfully', 'vignette-currency-converter'),
                $updated
            ),
        ));
    }
}

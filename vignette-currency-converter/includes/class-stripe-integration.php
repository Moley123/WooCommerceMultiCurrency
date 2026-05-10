<?php
/**
 * Stripe Multi-Currency Integration
 *
 * When enabled, converts the WooCommerce order currency and total to the
 * customer's selected display currency before payment is processed.
 * Stripe (and the WooCommerce Stripe Gateway) then charges the customer in
 * that currency. Stripe handles the FX conversion and settles to your GBP
 * bank account automatically.
 *
 * Requirements:
 *  - WooCommerce Stripe Gateway plugin installed and active
 *  - "Enable Stripe multi-currency" toggled on in plugin settings
 *
 * Exchange rate is locked at the moment the order is created (checkout submit),
 * not at cart/display time, to avoid stale-rate issues.
 */

if (!defined('ABSPATH')) {
    exit;
}

class VCC_Stripe_Integration {

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

        if ($this->is_enabled()) {
            $this->init_hooks();
        }
    }

    /**
     * Whether Stripe multi-currency charging is enabled in settings
     */
    public function is_enabled() {
        return isset($this->settings['stripe_multicurrency_enabled'])
            && $this->settings['stripe_multicurrency_enabled'] === 'yes';
    }

    private function init_hooks() {
        // Convert order currency + total AFTER order is created, BEFORE payment is processed
        add_action('woocommerce_checkout_order_created', array($this, 'convert_order_for_stripe'), 10, 1);

        // Show conversion info panel in WooCommerce admin order screen
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_order_currency_info'), 10, 1);

        // Add conversion note to order confirmation and admin emails
        add_action('woocommerce_email_order_meta', array($this, 'add_email_currency_info'), 10, 3);
    }

    /**
     * Convert the order's currency and total to the customer's selected currency.
     * Called on woocommerce_checkout_order_created — order exists, payment not yet taken.
     *
     * @param WC_Order $order
     */
    public function convert_order_for_stripe($order) {
        // Resolve selected currency from WC session or PHP session
        $selected_currency = null;

        if (function_exists('WC') && WC()->session) {
            $selected_currency = WC()->session->get('vcc_selected_currency');
        }

        if (!$selected_currency && isset($_SESSION['vcc_selected_currency'])) {
            $selected_currency = sanitize_text_field($_SESSION['vcc_selected_currency']);
        }

        // Nothing to do if GBP or no currency detected
        if (!$selected_currency || $selected_currency === 'GBP') {
            return;
        }

        // Idempotency — bail if this order has already been converted
        if ($order->get_meta('_vcc_charged_currency')) {
            return;
        }

        // Only proceed if the Stripe gateway is active
        if (!$this->is_stripe_active()) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('VCC Stripe: Stripe gateway not active — skipping currency conversion for order ' . $order->get_id());
            }
            return;
        }

        $gbp_total = floatval($order->get_total());

        if ($gbp_total <= 0) {
            return;
        }

        // Get live exchange rate at checkout time (locks in the rate)
        $api  = VCC_Currency_API::get_instance();
        $rate = $api->get_exchange_rate('GBP', $selected_currency);

        if (is_wp_error($rate) || !$rate) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('VCC Stripe: Could not get exchange rate for ' . $selected_currency . ' — order ' . $order->get_id() . ' stays in GBP');
            }
            return; // Fall back to GBP silently
        }

        $rate = floatval($rate);

        // Convert each line item, fee and shipping row at the locked rate so that
        // displays in the admin order screen and emails show converted amounts
        // (not GBP figures with a swapped currency symbol).
        foreach ($order->get_items('line_item') as $item) {
            $item->set_subtotal(round(floatval($item->get_subtotal()) * $rate, 2));
            $item->set_total(round(floatval($item->get_total()) * $rate, 2));
            $this->scale_item_taxes($item, $rate);
            $item->save();
        }

        foreach ($order->get_items('fee') as $fee) {
            $fee->set_total(round(floatval($fee->get_total()) * $rate, 2));
            $this->scale_item_taxes($fee, $rate);
            $fee->save();
        }

        foreach ($order->get_items('shipping') as $shipping) {
            $shipping->set_total(round(floatval($shipping->get_total()) * $rate, 2));
            $this->scale_item_taxes($shipping, $rate);
            $shipping->save();
        }

        // Recompute the order total from the converted lines so subtotal + fees == total.
        $converted_total = 0.0;
        foreach ($order->get_items(array('line_item', 'fee', 'shipping')) as $line) {
            $converted_total += floatval($line->get_total());
            if (is_callable(array($line, 'get_total_tax'))) {
                $converted_total += floatval($line->get_total_tax());
            }
        }
        $converted_total = round($converted_total, 2);

        // Store original GBP values in order meta for accounting / refund reference
        $order->update_meta_data('_vcc_original_gbp_total',  $gbp_total);
        $order->update_meta_data('_vcc_original_currency',   'GBP');
        $order->update_meta_data('_vcc_exchange_rate',       $rate);
        $order->update_meta_data('_vcc_charged_currency',    $selected_currency);
        $order->update_meta_data('_vcc_charged_total',       $converted_total);
        $order->update_meta_data('_vcc_rate_locked_at',      current_time('mysql'));

        // Update the order — this is what the Stripe gateway reads
        $order->set_currency($selected_currency);
        $order->set_total($converted_total);
        $order->save();

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'VCC Stripe: Order %d — £%.2f GBP → %.2f %s (rate: %.6f, locked at %s)',
                $order->get_id(),
                $gbp_total,
                $converted_total,
                $selected_currency,
                $rate,
                current_time('mysql')
            ));
        }
    }

    /**
     * Multiply each entry in an order item's subtotal/total tax arrays by $rate.
     * No-op when the item carries no taxes (the common case for this store).
     */
    private function scale_item_taxes($item, $rate) {
        if (!is_callable(array($item, 'get_taxes')) || !is_callable(array($item, 'set_taxes'))) {
            return;
        }

        $taxes = $item->get_taxes();
        if (empty($taxes) || !is_array($taxes)) {
            return;
        }

        foreach (array('subtotal', 'total') as $key) {
            if (!empty($taxes[$key]) && is_array($taxes[$key])) {
                foreach ($taxes[$key] as $rate_id => $amount) {
                    $taxes[$key][$rate_id] = round(floatval($amount) * $rate, 2);
                }
            }
        }

        $item->set_taxes($taxes);
    }

    /**
     * Check whether the WooCommerce Stripe Gateway is installed and enabled
     */
    private function is_stripe_active() {
        if (!function_exists('WC') || !WC()->payment_gateways) {
            return false;
        }

        $gateways = WC()->payment_gateways->payment_gateways();

        return isset($gateways['stripe']) && $gateways['stripe']->enabled === 'yes';
    }

    /**
     * Display currency conversion info box in the admin order screen
     *
     * @param WC_Order $order
     */
    public function display_order_currency_info($order) {
        $original_gbp     = $order->get_meta('_vcc_original_gbp_total');
        $charged_currency = $order->get_meta('_vcc_charged_currency');
        $charged_total    = $order->get_meta('_vcc_charged_total');
        $exchange_rate    = $order->get_meta('_vcc_exchange_rate');
        $rate_locked_at   = $order->get_meta('_vcc_rate_locked_at');

        if (!$original_gbp || !$charged_currency) {
            return;
        }

        $all_currencies = VCC_Currency_Converter::get_all_currencies();
        $flag           = isset($all_currencies[$charged_currency]) ? $all_currencies[$charged_currency]['flag'] : '';
        $name           = isset($all_currencies[$charged_currency]) ? $all_currencies[$charged_currency]['name'] : $charged_currency;
        ?>
        <div class="vcc-order-currency-info" style="margin-top:15px;padding:12px 15px;background:#f0f7ff;border-left:4px solid #2271b1;border-radius:2px;">
            <strong style="display:block;margin-bottom:6px;">
                <?php _e('Multi-Currency Charge', 'vignette-currency-converter'); ?>
                <span style="font-weight:normal;font-size:11px;color:#666;"> — <?php _e('Vignette Currency Converter', 'vignette-currency-converter'); ?></span>
            </strong>
            <table style="font-size:13px;border-collapse:collapse;">
                <tr>
                    <td style="padding:2px 12px 2px 0;color:#666;"><?php _e('Charged in:', 'vignette-currency-converter'); ?></td>
                    <td><strong><?php echo esc_html($flag . ' ' . $charged_currency . ' — ' . $name); ?></strong></td>
                </tr>
                <tr>
                    <td style="padding:2px 12px 2px 0;color:#666;"><?php _e('Charged amount:', 'vignette-currency-converter'); ?></td>
                    <td><strong><?php echo esc_html($charged_currency . ' ' . number_format(floatval($charged_total), 2)); ?></strong></td>
                </tr>
                <tr>
                    <td style="padding:2px 12px 2px 0;color:#666;"><?php _e('Original GBP total:', 'vignette-currency-converter'); ?></td>
                    <td><?php echo esc_html('£' . number_format(floatval($original_gbp), 2)); ?></td>
                </tr>
                <tr>
                    <td style="padding:2px 12px 2px 0;color:#666;"><?php _e('Exchange rate:', 'vignette-currency-converter'); ?></td>
                    <td><?php echo esc_html('1 GBP = ' . number_format(floatval($exchange_rate), 4) . ' ' . $charged_currency); ?></td>
                </tr>
                <tr>
                    <td style="padding:2px 12px 2px 0;color:#666;"><?php _e('Rate locked at:', 'vignette-currency-converter'); ?></td>
                    <td><?php echo esc_html($rate_locked_at); ?></td>
                </tr>
            </table>
        </div>
        <?php
    }

    /**
     * Add currency conversion note to order emails
     *
     * @param WC_Order $order
     * @param bool     $sent_to_admin
     * @param bool     $plain_text
     */
    public function add_email_currency_info($order, $sent_to_admin, $plain_text) {
        $original_gbp     = $order->get_meta('_vcc_original_gbp_total');
        $charged_currency = $order->get_meta('_vcc_charged_currency');
        $charged_total    = $order->get_meta('_vcc_charged_total');

        if (!$original_gbp || !$charged_currency) {
            return;
        }

        $message = sprintf(
            __('Payment charged in %s %s (equivalent to £%s GBP at the exchange rate locked at checkout).', 'vignette-currency-converter'),
            esc_html($charged_currency),
            number_format(floatval($charged_total), 2),
            number_format(floatval($original_gbp), 2)
        );

        if ($plain_text) {
            echo "\n" . wp_strip_all_tags($message) . "\n";
        } else {
            echo '<p style="font-size:12px;color:#555;margin-top:10px;">' . $message . '</p>';
        }
    }
}

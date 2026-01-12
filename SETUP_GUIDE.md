# Vignette Currency Converter - Setup Guide

Complete guide to get your vignette multi-currency plugin up and running.

## Quick Start (5 Minutes)

### Step 1: Get Your API Key

1. Go to https://www.exchangerate-api.com
2. Click "Get Free Key"
3. Enter your email address
4. Check your email for the API key
5. Copy the API key (it looks like: `a1b2c3d4e5f6g7h8i9j0`)

### Step 2: Install the Plugin

1. **Upload to WordPress**
   ```bash
   # If you have SSH access:
   cd /path/to/wordpress/wp-content/plugins/
   # Copy the vignette-currency-converter folder here
   ```

   Or via WordPress Admin:
   - Compress the `vignette-currency-converter` folder as a .zip file
   - Go to WordPress Admin > Plugins > Add New > Upload Plugin
   - Upload the .zip file
   - Click "Install Now"

2. **Activate**
   - Go to Plugins
   - Find "Vignette Currency Converter"
   - Click "Activate"

### Step 3: Configure the Plugin

1. **Go to Settings**
   - WordPress Admin > WooCommerce > Currency Converter

2. **Enter API Key**
   - Paste your ExchangeRate-API key
   - Click "Test API Connection" to verify
   - Should see green checkmark: "✓ API connection successful!"

3. **Configure Currencies**
   - Enabled currencies are checked by default: GBP, EUR, USD, CHF, CAD, AUD, JPY
   - Uncheck any you don't want to offer
   - GBP is always enabled (your base currency)

4. **Set Cache Duration**
   - Default is 12 hours (recommended)
   - Lower = more API calls, more up-to-date rates
   - Higher = fewer API calls, less up-to-date rates

5. **Display Settings**
   - Check "Show Currency Selector" to allow customers to choose currency
   - Select position: "Before Add to Cart Button" (recommended)

6. **Save Settings**
   - Click "Save Settings" at the bottom

## Step 4: Set Up Your First Vignette Product

### Example: Swiss Vignette

1. **Create Product**
   - Go to Products > Add New
   - Product name: "Swiss Highway Vignette 2024"
   - Product description: Add your description

2. **Set Regular WooCommerce Settings**
   - Product type: Simple product
   - Virtual: Check (if it's digital/virtual)
   - Set any other WooCommerce settings as normal

3. **Configure Vignette Currency Settings**
   - Look for the "Vignette Currency Settings" box on the right side
   - **Source Currency**: Select "CHF" (Swiss Franc)
   - **Source Price**: Enter "40.00" (the price in CHF)
   - **Auto-update**: Check this box
   - You'll see: "CHF 40.00 = £35.20 GBP" (example conversion)

4. **Publish Product**
   - Click "Publish"
   - The plugin automatically sets the WooCommerce price to the converted GBP amount

### More Examples

**Austrian Vignette (EUR)**
- Source Currency: EUR
- Source Price: 96.40
- Auto-converted to: ~£82.00 GBP

**Romanian Vignette (RON)**
- If you need Romanian Leu, you can add it via code (see Advanced section)
- Source Currency: RON
- Source Price: 80.00

## Step 5: Test Customer Experience

1. **View Your Product**
   - Go to the product page on your site (frontend)
   - You should see the currency selector dropdown

2. **Test Currency Switching**
   - Select "Euro (€)" from dropdown
   - Page reloads
   - Price now shows in EUR instead of GBP

3. **Test Cart**
   - Add product to cart
   - View cart
   - Prices show in selected currency
   - Notice: "Prices displayed in EUR. Payment will be processed in GBP."

4. **Reset to GBP**
   - Select "British Pound (£)" from dropdown
   - Back to original GBP prices

## Common Use Cases

### Use Case 1: Multiple Countries' Vignettes

**Switzerland (CHF)**
- Product: Swiss Vignette
- Source: CHF 40.00
- Displays as: £35.20

**Austria (EUR)**
- Product: Austrian Vignette
- Source: EUR 96.40
- Displays as: £82.00

**Slovenia (EUR)**
- Product: Slovenian Vignette
- Source: EUR 15.00
- Displays as: £12.80

### Use Case 2: Seasonal Price Updates

1. Source vignette prices change (e.g., Swiss vignette goes from CHF 40 to CHF 42)
2. Edit product
3. Update "Source Price" to 42.00
4. Save
5. Plugin automatically recalculates GBP price using current exchange rate

### Use Case 3: Bulk Price Update After Exchange Rate Changes

1. Go to WooCommerce > Currency Converter
2. Scroll to "Bulk Actions"
3. Click "Update All Product Prices from Exchange Rates"
4. All products with source currencies get recalculated with latest rates

## Troubleshooting

### API Not Working

**Error: "ExchangeRate-API key not configured"**
- Solution: Make sure you pasted the API key in settings and clicked "Save Settings"

**Error: "Invalid API key"**
- Solution: Check that you copied the complete API key from your email
- Try getting a new key from ExchangeRate-API.com

**Error: "API quota exceeded"**
- Solution: Free plan is 1,500 requests/month
- With caching, you should rarely hit this
- Check "Clear Cache Now" isn't being clicked too often
- Consider upgrading API plan if needed

### Currency Selector Not Showing

**Check:**
1. Is "Show Currency Selector" checked in settings?
2. Are you viewing a product page or shop page?
3. Is JavaScript enabled in your browser?
4. Check browser console for errors (F12 > Console)

### Prices Not Converting

**Check:**
1. Did you set Source Currency and Source Price in product settings?
2. Is the API key valid? Test it in settings.
3. Check if cache needs clearing (Settings > Clear Cache Now)

### Conversion Seems Wrong

**Check:**
1. Exchange rates change daily - is the cache old?
2. Clear cache and update product to get latest rate
3. Verify source price is correct

## Advanced Usage

### Adding More Currencies

Add this to your theme's `functions.php`:

```php
add_filter('vcc_available_currencies', 'add_custom_currencies');
function add_custom_currencies($currencies) {
    $currencies[] = 'RON'; // Romanian Leu
    $currencies[] = 'HUF'; // Hungarian Forint
    $currencies[] = 'CZK'; // Czech Koruna
    return $currencies;
}
```

Then add the currency symbols in `includes/class-currency-converter.php`:

```php
// Find get_currency_symbol() method and add:
'RON' => 'RON',
'HUF' => 'Ft',
'CZK' => 'Kč',
```

### Changing Cache Duration via Code

```php
add_filter('option_vcc_settings', 'modify_vcc_cache_duration');
function modify_vcc_cache_duration($settings) {
    $settings['cache_duration'] = 24; // 24 hours
    return $settings;
}
```

### Custom Currency Selector Position

```php
// Remove default position
remove_action('woocommerce_before_add_to_cart_button', array(VCC_Frontend_Display::get_instance(), 'render_currency_selector'));

// Add to custom position
add_action('your_custom_hook', array(VCC_Frontend_Display::get_instance(), 'render_currency_selector'));
```

## Maintenance

### Weekly
- Check that products are displaying correctly
- Verify currency selector is working

### Monthly
- Review API usage (check ExchangeRate-API dashboard)
- Update product prices if source prices changed
- Consider running bulk price update if rates changed significantly

### Quarterly
- Review enabled currencies - remove unused ones to reduce API calls
- Check for plugin updates

## Best Practices

1. **Set Auto-update on Products**
   - Check "Auto-update GBP price" for all vignette products
   - Ensures prices stay current with exchange rates

2. **Use Reasonable Cache Duration**
   - 12-24 hours is recommended
   - Daily rate changes are usually minor
   - Hourly updates waste API calls

3. **Monitor API Usage**
   - Free plan: 1,500 requests/month
   - With 10 products and 12-hour cache: ~40 requests/day = 1,200/month
   - Leave buffer for traffic spikes

4. **Keep Source Prices Updated**
   - When official vignette prices change, update source prices
   - Plugin will automatically recalculate

5. **Test Before Seasons**
   - Before high-traffic seasons (summer travel), test everything
   - Clear cache and update prices for fresh rates

## Getting Help

- **Documentation**: See README.md
- **API Documentation**: https://www.exchangerate-api.com/docs
- **WooCommerce Docs**: https://woocommerce.com/documentation/

## Next Steps

Now that your plugin is set up:

1. ✅ Create all your vignette products with source currencies
2. ✅ Test the currency selector on your site
3. ✅ Test the complete purchase flow
4. ✅ Monitor for a few days to ensure everything works smoothly
5. ✅ Consider setting up automatic backups

Happy selling! 🚗🛣️

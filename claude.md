# WooCommerce Currency Converter Plugin

## Project Overview

A custom WordPress/WooCommerce plugin designed for a vignette reseller business that handles multi-currency conversion with markup support. The plugin converts vignette prices from various source currencies (CHF, EUR, etc.) to GBP (base shop currency) and allows customers to view prices in their preferred currency.

**Plugin Name:** Vignette Currency Converter
**Version:** 1.0.2
**Base Currency:** GBP (British Pounds)
**API:** ExchangeRate-API (free tier)

## Business Context

The client resells European vignettes (road toll passes) sourced from different countries in various currencies. The plugin:
- Converts source prices (e.g., CHF 40, EUR 15) to GBP for WooCommerce storage
- Allows optional markup (percentage or fixed) before conversion
- Displays prices to customers in their selected currency
- Supports both simple products and variable products with variations

## Architecture

### Core Conversion Flow

```
Source Currency (e.g., EUR 15.00)
    ↓
Apply Markup (e.g., 20% = EUR 18.00)
    ↓
Convert to GBP (stored in WooCommerce: £15.36)
    ↓
Display to Customer (convert GBP → selected currency)
```

**Exception:** If customer selects the source currency, show original source price with markup (skip double conversion).

### Variable Products Architecture

```
Variable Product (Parent)
├── Source Currency: EUR (set once)
├── Markup Settings (optional, inheritable)
└── Variations (Children)
    ├── Variation #1: Car 1 week
    │   ├── Source Price: EUR 15.00
    │   ├── Custom Markup: (optional override)
    │   └── Stored GBP Price: £12.80
    ├── Variation #2: Van 1 week
    │   ├── Source Price: EUR 20.00
    │   └── Stored GBP Price: £17.05
    └── Variation #3: Motorbike 1 year
        ├── Source Price: EUR 58.00
        └── Stored GBP Price: £49.40
```

## Project Structure

```
vignette-currency-converter/
├── vignette-currency-converter.php    # Main plugin file
├── includes/
│   ├── class-currency-api.php         # ExchangeRate-API integration
│   ├── class-currency-converter.php   # Core conversion logic & product integration
│   ├── class-frontend-display.php     # Customer-facing UI (currency selector)
│   └── class-admin-settings.php       # Admin settings page
└── assets/
    └── css/
        └── admin.css                   # Admin styling
```

## Key Features

### 1. Multi-Currency Support
- **Supported Currencies:** GBP, EUR, USD, CHF, CAD, AUD, JPY
- **Base Currency:** GBP (stored in WooCommerce)
- **Display Currency:** Customer selectable via dropdown

### 2. Markup System
- **Types:** Percentage (%) or Fixed Amount
- **Scope:** Global default, per-product override, per-variation override
- **Priority:** Variation > Parent Product > Global
- **Application:** Applied to SOURCE price before GBP conversion

### 3. Product Support
- ✅ Simple products
- ✅ Variable products (parent holds currency, variations hold prices)
- ✅ Per-variation pricing and markup

### 4. Exchange Rate Management
- **Caching:** 12-hour WordPress transients
- **API Limit:** 1,500 requests/month (free tier)
- **Manual Actions:** Clear cache, bulk update prices

### 5. HPOS Compatibility
- Declared compatible with WooCommerce High-Performance Order Storage (8.2+)

## File Details

### `vignette-currency-converter.php`
**Purpose:** Main plugin bootstrap file

**Key Functions:**
- Plugin activation/deactivation
- Class autoloading
- HPOS compatibility declaration
- Default settings initialization

**Important Code:**
```php
// HPOS compatibility (lines 156-161)
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables', __FILE__, true
        );
    }
});
```

### `includes/class-currency-api.php`
**Purpose:** Handles API communication with ExchangeRate-API

**Key Methods:**
- `get_exchange_rate($from, $to)` - Fetches rate (cache-first)
- `convert($amount, $from, $to)` - Converts amount between currencies
- `get_cached_rate()` - Retrieves from WordPress transients
- `cache_rate()` - Stores rates (12-hour expiry)
- `clear_cache()` - Removes all cached rates

**Caching Strategy:**
```php
// Cache key format
$cache_key = 'vcc_rate_' . strtolower($from) . '_' . strtolower($to);

// Cache duration
define('VCC_CACHE_DURATION', 12 * HOUR_IN_SECONDS); // 12 hours
```

### `includes/class-currency-converter.php`
**Purpose:** Core conversion logic and WooCommerce integration

**Key Hooks:**
- `add_meta_boxes` - Adds product meta box (simple & variable parents)
- `woocommerce_process_product_meta` (priority 20) - Saves product data
- `woocommerce_variation_options_pricing` - Renders variation fields
- `woocommerce_save_product_variation` - Saves variation data

**Key Methods:**

**Simple Products:**
- `render_product_meta_box()` - Displays source currency/price/markup fields
- `save_product_meta()` - Saves meta fields
- `update_gbp_price()` - Converts source → GBP and updates WooCommerce price

**Variable Products:**
- `render_variation_fields()` - Displays variation-specific fields
- `save_variation_meta()` - Saves variation source price and markup
- `update_variation_gbp_price()` - Converts variation price to GBP

**Markup Application:**
- `apply_markup($post_id, $source_price)` - Applies markup with priority system
- `apply_global_markup($source_price)` - Helper for global markup

**Hook Priority Note:**
```php
// Priority 20 ensures meta is saved BEFORE price calculation
add_action('woocommerce_process_product_meta',
    array($this, 'save_and_update_product'), 20);
```

### `includes/class-frontend-display.php`
**Purpose:** Customer-facing currency selector and price display

**Key Methods:**
- `render_currency_selector()` - Displays dropdown (supports shortcode)
- `modify_price_display()` - Converts product prices for display
- `modify_cart_price()` - Converts cart item prices

**Variation Handling:**
```php
// Check if variation
$parent_id = $product->get_parent_id(); // 0 for simple, parent ID for variations

if ($parent_id > 0) {
    // Get currency from parent, price from variation
    $source_currency = get_post_meta($parent_id, '_vcc_source_currency', true);
    $source_price = get_post_meta($product_id, '_vcc_source_price', true);
} else {
    // Simple product - both from same product
    $source_currency = get_post_meta($product_id, '_vcc_source_currency', true);
    $source_price = get_post_meta($product_id, '_vcc_source_price', true);
}
```

### `includes/class-admin-settings.php`
**Purpose:** Admin settings page and bulk operations

**Settings Sections:**
1. API Configuration (ExchangeRate-API key)
2. Enabled Currencies
3. Markup Settings (global defaults)
4. Tools (clear cache, bulk update)

**AJAX Actions:**
- `ajax_clear_cache()` - Clears all rate cache
- `ajax_update_all_prices()` - Recalculates all product/variation prices

**Bulk Update Logic:**
```php
// Updates both simple products AND variations
// Simple products: query 'product' post type with _vcc_source_currency
// Variations: query 'product_variation' post type with _vcc_source_price
```

## Meta Fields Reference

### Product Meta (Simple Products & Variable Parents)
- `_vcc_source_currency` - Source currency code (e.g., 'EUR', 'CHF')
- `_vcc_source_price` - Original price in source currency (simple products only)
- `_vcc_auto_update` - Auto-update on rate changes ('yes'/'no')
- `_vcc_use_custom_markup` - Use product-specific markup ('yes'/'no')
- `_vcc_markup_type` - Markup type ('percentage' or 'fixed')
- `_vcc_markup_value` - Markup value (float)
- `_vcc_last_conversion_rate` - Last used exchange rate
- `_vcc_last_conversion_date` - Last conversion timestamp

### Variation Meta (Product Variations)
- `_vcc_source_price` - Variation price in parent's source currency
- `_vcc_use_custom_markup` - Use variation-specific markup ('yes'/'no')
- `_vcc_markup_type` - Variation markup type ('percentage' or 'fixed')
- `_vcc_markup_value` - Variation markup value (float)
- `_vcc_last_conversion_rate` - Last used exchange rate
- `_vcc_last_conversion_date` - Last conversion timestamp

**Note:** Variations inherit `_vcc_source_currency` from parent product.

## Settings Reference

**Option Name:** `vcc_settings`

**Structure:**
```php
array(
    'api_key' => 'your-exchangerate-api-key',
    'enabled_currencies' => array('GBP', 'EUR', 'USD', 'CHF', 'CAD', 'AUD', 'JPY'),
    'enable_currency_selector' => 'yes',
    'enable_markup' => 'yes',
    'default_markup_type' => 'percentage',
    'default_markup_value' => 20.00
)
```

## Usage Guide

### Setting Up Simple Products

1. Edit product in WordPress admin
2. In "Vignette Currency Settings" meta box:
   - **Source Currency:** Select currency (e.g., CHF)
   - **Source Price:** Enter price (e.g., 40.00)
   - **Markup Settings:** (optional) Enable custom markup
3. Save product
4. WooCommerce "Regular price" auto-updates to GBP equivalent

### Setting Up Variable Products

1. Edit variable product
2. In "Vignette Currency Settings" meta box:
   - **Source Currency:** Select currency (e.g., EUR) - applies to all variations
   - Leave **Source Price** empty (not used for variable products)
   - **Markup Settings:** (optional) Set parent-level markup
3. Go to "Variations" tab
4. For each variation:
   - Expand variation
   - Find "Vignette Currency Settings" section
   - **Source Price:** Enter variation price (e.g., 15.00)
   - **Custom Markup:** (optional) Override parent/global markup
   - View live preview: EUR 15.00 → £12.80 GBP
5. Save variations

### Markup Priority Example

**Scenario:** Car 1 week variation with EUR 15.00 source price

**Global Settings:** 10% markup enabled

**Priority 1 - Variation Custom Markup:**
```
Variation has custom markup: 20%
Result: EUR 15.00 + 20% = EUR 18.00 → £15.36 GBP
```

**Priority 2 - Parent Product Markup:**
```
No variation markup, but parent has custom markup: 15%
Result: EUR 15.00 + 15% = EUR 17.25 → £14.72 GBP
```

**Priority 3 - Global Markup:**
```
No variation or parent markup, use global: 10%
Result: EUR 15.00 + 10% = EUR 16.50 → £14.08 GBP
```

### Customer Currency Selection

**Shortcode:**
```php
[vcc_currency_selector]
```

**Behavior:**
- Dropdown shows all enabled currencies
- Selection stored in session
- All product prices dynamically convert
- Cart/checkout prices convert (products only, not totals yet)

**Source Currency Display:**
- If customer selects EUR and product source is EUR
- Shows original EUR price + markup
- Avoids double conversion (EUR → GBP → EUR)

## Known Limitations & Future Work

### Current Limitations

1. **Cart Totals Not Converting** (Deferred)
   - Individual product prices convert
   - Subtotal, total, fees, shipping still show GBP only
   - Requires hooks: `woocommerce_cart_subtotal`, `woocommerce_cart_total`

2. **No API Fallback** (Deferred)
   - If API fails after cache expires (12 hours), conversions fail
   - Options discussed but not implemented:
     - Store last-known-good rates permanently
     - Manual rate override capability
     - Email alerts on API failures

3. **Payment Processing**
   - Prices display in selected currency but Stripe still charges in GBP
   - Customer's bank/card handles GBP conversion
   - Multi-currency payment gateway integration not implemented

### Resolved Issues

1. ✅ **Admin Price Not Saving** - Fixed with hook priority 20
2. ✅ **Wrong Frontend CHF Price** - Fixed by checking source currency match
3. ✅ **HPOS Incompatibility Warning** - Fixed with FeaturesUtil declaration
4. ✅ **Variable Products Unsupported** - Implemented full variation support

## Development History

### v1.0.0 - Initial Release
- Basic currency conversion (Source → GBP → Display)
- ExchangeRate-API integration
- 12-hour caching
- Simple product support
- Frontend currency selector

### v1.0.1 - HPOS & Markup
- Added HPOS compatibility declaration
- Implemented markup feature (global + per-product)
- Fixed admin save hook priority issue
- Fixed frontend source currency display

### v1.0.2 - Variable Products (Current)
- Full variable product support
- Per-variation pricing and markup
- Variation admin UI with live preview
- Updated frontend to handle parent/variation logic
- Updated bulk price updater for variations
- Markup priority system (variation > parent > global)

## Testing Checklist

### Simple Product Testing
- [ ] Set source currency and price in admin
- [ ] Verify GBP price auto-updates
- [ ] Check markup calculation preview
- [ ] Frontend: Select source currency, verify original price shown
- [ ] Frontend: Select different currency, verify conversion
- [ ] Add to cart, verify price displays in selected currency

### Variable Product Testing
- [ ] Set source currency on parent product
- [ ] Add source prices to all variations
- [ ] Verify live preview shows correct conversions
- [ ] Test variation-specific markup override
- [ ] Frontend: Select variations, verify prices change
- [ ] Cart: Verify each variation shows correct price

### Bulk Operations
- [ ] Clear cache, verify rates refresh
- [ ] Update all prices, verify count includes variations
- [ ] Check both simple products and variations updated

### Edge Cases
- [ ] Product with no source currency (should not convert)
- [ ] Variation without parent currency (should show error)
- [ ] API failure scenario (should show original price)
- [ ] Zero or negative source price
- [ ] Markup value of 0 (should not apply markup)

## Support & Troubleshooting

### Common Issues

**Price not converting:**
- Check source currency is set
- Check source price is not empty
- Verify API key is configured
- Try clearing cache

**Wrong price displayed:**
- Check markup settings (global vs custom)
- Verify conversion preview in admin
- Check if source currency matches selected currency

**Variations not working:**
- Ensure parent has source currency set
- Check variation has source price
- Verify WooCommerce variation sync ran

### Debug Information

**Check Cached Rates:**
```php
// In WordPress, check transients like:
get_transient('vcc_rate_eur_gbp');
```

**Check Product Meta:**
```php
get_post_meta($product_id, '_vcc_source_currency', true);
get_post_meta($product_id, '_vcc_source_price', true);
```

**API Response Format:**
```json
{
    "result": "success",
    "base_code": "EUR",
    "target_code": "GBP",
    "conversion_rate": 0.8534
}
```

## API Reference

**ExchangeRate-API Endpoints:**
- URL: `https://v6.exchangerate-api.com/v6/{API_KEY}/pair/{FROM}/{TO}`
- Free Tier: 1,500 requests/month
- No credit card required
- Documentation: https://www.exchangerate-api.com/docs

## Code Standards

- WordPress Coding Standards
- Proper escaping (`esc_html`, `esc_attr`, `esc_url`)
- Nonce verification for all form submissions
- Capability checks (`manage_woocommerce`)
- Transients for caching
- WP_Error for error handling

## Git Workflow

**Branch:** `claude/vignette-currency-converter-7KJla`

**Commit Message Style:**
```
Brief summary of change

Detailed explanation of what changed and why.
- Bullet points for key changes
- Technical details
- User-facing impact
```

**Recent Commits:**
1. Add WooCommerce variable product and variation support
2. Bump version to 1.0.2
3. Add HPOS compatibility and implement markup feature
4. Fix critical admin and frontend currency conversion issues

---

**Last Updated:** 2026-01-13
**Maintained By:** Claude (AI Assistant)
**Project Owner:** Vignette Reseller Business

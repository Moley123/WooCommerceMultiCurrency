# WCEPO Integration Guide

## Vignette Currency Converter - External Plugin Integration

This guide explains how to integrate with the Vignette Currency Converter (VCC) plugin from external plugins like WooCommerce Extra Product Options (WCEPO).

**Plugin Version:** 1.1.0+
**Last Updated:** January 15, 2026

---

## Overview

VCC v1.1.0 introduced comprehensive external plugin support with:
- Public API methods for accessing exchange rates
- Dual JavaScript event system (jQuery + Native DOM)
- Filter hooks for customization
- Global JavaScript object for client-side integration

---

## Quick Start

### 1. Check if VCC is Active

```php
if (class_exists('VCC_Currency_Converter')) {
    // VCC is active - proceed with integration
    $vcc = VCC_Currency_Converter::get_instance();
}
```

### 2. Get Current Currency

```php
$frontend = VCC_Frontend_Display::get_instance();
$current_currency = $frontend->get_selected_currency(); // Returns 'EUR', 'GBP', etc.
```

### 3. Convert Prices

```php
$converter = VCC_Currency_Converter::get_instance();

// Convert from GBP to target currency
$converted_price = $converter->convert_from_gbp(10.00, 'EUR');

// Get public exchange rate
$rate = $converter->get_public_exchange_rate('GBP', 'EUR');
```

### 4. Listen for Currency Changes (JavaScript)

```javascript
// jQuery event (recommended for WP plugins)
$(document).on('vcc_currency_changed', function(event, data) {
    console.log('Currency changed to:', data.currency);
    console.log('Previous currency:', data.previousCurrency);
    console.log('Available rates:', data.rates);

    // Update your plugin's prices here
    updateWCEPOPrices(data.currency, data.rates);
});

// Native DOM event (for modern JavaScript)
document.addEventListener('vcc_currency_changed', function(event) {
    var data = event.detail;
    console.log('Currency changed to:', data.currency);
});
```

---

## Public API Methods

### PHP Server-Side API

#### Get Selected Currency

```php
$frontend = VCC_Frontend_Display::get_instance();
$currency = $frontend->get_selected_currency();
// Returns: 'GBP', 'EUR', 'USD', 'CHF', 'CAD', 'AUD', 'JPY'
```

#### Convert Price from GBP

```php
$converter = VCC_Currency_Converter::get_instance();
$converted_price = $converter->convert_from_gbp($amount, $to_currency);

// Example:
$gbp_price = 10.00;
$eur_price = $converter->convert_from_gbp($gbp_price, 'EUR');
// Returns: 11.73 (or WP_Error if conversion fails)
```

#### Get Exchange Rate

```php
$converter = VCC_Currency_Converter::get_instance();
$rate = $converter->get_public_exchange_rate($from, $to);

// Example:
$rate = $converter->get_public_exchange_rate('GBP', 'EUR');
// Returns: 1.173 (or WP_Error)
```

#### Get All Exchange Rates

```php
$converter = VCC_Currency_Converter::get_instance();
$rates = $converter->get_all_exchange_rates('GBP');

// Returns array:
// [
//     'GBP' => 1.0,
//     'EUR' => 1.173,
//     'USD' => 1.247,
//     'CHF' => 1.082,
//     ...
// ]
```

#### Get Currency Symbol

```php
$converter = VCC_Currency_Converter::get_instance();
$symbol = $converter->get_currency_symbol('EUR');
// Returns: '€'
```

#### Get Product Currency Data

```php
$converter = VCC_Currency_Converter::get_instance();
$data = $converter->get_product_currency_data($product_id);

// Returns array:
// [
//     'product_id' => 123,
//     'source_currency' => 'EUR',
//     'source_price' => 15.00,
//     'gbp_price' => 12.80,
//     'has_markup' => true,
//     'source_price_with_markup' => 18.00
// ]
```

---

### JavaScript Client-Side API

VCC exposes a global `window.VCC` object with the following methods:

#### Get Current Currency

```javascript
var currency = VCC.getCurrentCurrency();
// Returns: 'EUR', 'GBP', etc.
```

#### Get Exchange Rate

```javascript
var rate = VCC.getExchangeRate('GBP', 'EUR');
// Returns: 1.173 (or null if not available)
```

#### Convert Amount

```javascript
var converted = VCC.convertAmount(10.00, 'GBP', 'EUR');
// Returns: 11.73 (or null if conversion fails)
```

#### Format Price

```javascript
var formatted = VCC.formatPrice(11.73, 'EUR');
// Returns: '€11.73'
```

#### Access Exchange Rates

```javascript
var rates = VCC.rates;
// Object: { 'EUR': 1.173, 'USD': 1.247, ... }
```

#### Access Currency Symbols

```javascript
var symbols = VCC.symbols;
// Object: { 'EUR': '€', 'USD': '$', 'GBP': '£', ... }
```

---

## Event System

### JavaScript Events

VCC dispatches events when currency changes, allowing your plugin to react instantly.

#### Event Data Structure

```javascript
{
    currency: 'EUR',              // New selected currency
    previousCurrency: 'GBP',      // Previously selected currency
    rates: {                       // All exchange rates (from GBP)
        'EUR': 1.173,
        'USD': 1.247,
        'CHF': 1.082,
        ...
    },
    symbols: {                     // Currency symbols
        'EUR': '€',
        'USD': '$',
        ...
    }
}
```

#### jQuery Event (Recommended)

```javascript
$(document).on('vcc_currency_changed', function(event, data) {
    // Update your plugin's prices
    var newCurrency = data.currency;
    var rate = data.rates[newCurrency];

    // Example: Update WCEPO extra option prices
    $('.wcepo-extra-option-price').each(function() {
        var $el = $(this);
        var gbpPrice = parseFloat($el.data('gbp-price'));
        var convertedPrice = gbpPrice * rate;
        $el.text(data.symbols[newCurrency] + convertedPrice.toFixed(2));
    });
});
```

#### Native DOM Event

```javascript
document.addEventListener('vcc_currency_changed', function(event) {
    var data = event.detail;
    console.log('Currency:', data.currency);
    console.log('Rate:', data.rates[data.currency]);
});
```

---

## Filter Hooks

VCC provides WordPress filter hooks for customization:

### `vcc_get_exchange_rate`

Override or provide custom exchange rates.

```php
add_filter('vcc_get_exchange_rate', function($rate, $from, $to) {
    // Return custom rate or null to use default
    if ($from === 'GBP' && $to === 'EUR') {
        return 1.20; // Custom fixed rate
    }
    return $rate; // Use default
}, 10, 3);
```

### `vcc_all_exchange_rates`

Modify bulk exchange rates before passing to JavaScript.

```php
add_filter('vcc_all_exchange_rates', function($rates, $base_currency) {
    // Add custom rates or modify existing
    $rates['CUSTOM'] = 1.5;
    return $rates;
}, 10, 2);
```

### `vcc_country_to_currency_mapping`

Customize country-to-currency mapping for auto-detection.

```php
add_filter('vcc_country_to_currency_mapping', function($mapping) {
    // Override country mappings
    $mapping['DK'] = 'DKK'; // Denmark → DKK instead of EUR
    return $mapping;
});
```

### `vcc_auto_detected_currency`

Override auto-detected currency based on country.

```php
add_filter('vcc_auto_detected_currency', function($currency, $country) {
    // Custom logic
    if ($country === 'CH') {
        return 'EUR'; // Use EUR for Switzerland instead of CHF
    }
    return $currency;
}, 10, 2);
```

### `vcc_product_currency_data`

Modify product data before passing to JavaScript.

```php
add_filter('vcc_product_currency_data', function($data, $product_id) {
    // Add custom fields
    $data['custom_field'] = get_post_meta($product_id, '_custom_field', true);
    return $data;
}, 10, 2);
```

---

## Integration Best Practices

### 1. Avoid Double Conversion

**Problem:** Your plugin converts prices that VCC already converted.

**Solution:** Check if price is already converted before applying your own conversion.

```php
// BAD - Double conversion
$product_price = $product->get_price(); // £10 (GBP)
$my_converted_price = convert_to_eur($product_price); // Wrong!

// GOOD - Use VCC methods
$converter = VCC_Currency_Converter::get_instance();
$gbp_price = $product->get_price();
$eur_price = $converter->convert_from_gbp($gbp_price, 'EUR');
```

### 2. Cache Exchange Rates

**Problem:** Fetching exchange rates multiple times per page load.

**Solution:** Use VCC's cached rates via `get_all_exchange_rates()`.

```javascript
// BAD - Multiple API calls
var rate1 = VCC.getExchangeRate('GBP', 'EUR');
var rate2 = VCC.getExchangeRate('GBP', 'USD');

// GOOD - Use pre-loaded rates
var rates = VCC.rates;
var eurRate = rates['EUR'];
var usdRate = rates['USD'];
```

### 3. Handle Currency State Consistently

**Problem:** Your plugin and VCC have different selected currencies.

**Solution:** Always use VCC as the source of truth.

```php
// Get currency from VCC, not from your own storage
$frontend = VCC_Frontend_Display::get_instance();
$currency = $frontend->get_selected_currency();
```

### 4. Respect Manual Overrides

**Problem:** Auto-detection overrides user's manual choice.

**Solution:** VCC handles this automatically. Just listen to events and update your prices.

```javascript
// VCC handles manual override logic internally
// Your plugin just needs to respond to currency changes
$(document).on('vcc_currency_changed', function(event, data) {
    updateMyPrices(data.currency);
});
```

### 5. Update Prices Instantly

**Problem:** Prices don't update when currency changes (still using old method with page reload).

**Solution:** Listen to `vcc_currency_changed` event and update DOM.

```javascript
$(document).on('vcc_currency_changed', function(event, data) {
    // Update all your plugin's price elements
    $('.my-plugin-price').each(function() {
        var $el = $(this);
        var gbpPrice = parseFloat($el.data('gbp-price'));
        var convertedPrice = VCC.convertAmount(gbpPrice, 'GBP', data.currency);
        var formatted = VCC.formatPrice(convertedPrice, data.currency);
        $el.html(formatted);
    });
});
```

---

## Example: WCEPO Integration

### Scenario

WCEPO adds extra product options with prices. When customer changes currency, WCEPO option prices should update instantly.

### Implementation

#### Step 1: Store GBP Price in Data Attribute

```php
// In WCEPO option rendering
$gbp_price = 5.00; // Extra option price in GBP
$converter = VCC_Currency_Converter::get_instance();
$frontend = VCC_Frontend_Display::get_instance();
$current_currency = $frontend->get_selected_currency();

// Convert to current currency
$converted_price = $converter->convert_from_gbp($gbp_price, $current_currency);
$symbol = $converter->get_currency_symbol($current_currency);

echo '<span class="wcepo-option-price" data-gbp-price="' . $gbp_price . '">';
echo $symbol . number_format($converted_price, 2);
echo '</span>';
```

#### Step 2: Update on Currency Change (JavaScript)

```javascript
$(document).on('vcc_currency_changed', function(event, data) {
    $('.wcepo-option-price').each(function() {
        var $price = $(this);
        var gbpPrice = parseFloat($price.data('gbp-price'));

        // Convert using VCC
        var convertedPrice = VCC.convertAmount(gbpPrice, 'GBP', data.currency);
        var formatted = VCC.formatPrice(convertedPrice, data.currency);

        // Update DOM
        $price.html(formatted);
    });
});
```

---

## Troubleshooting

### Event Not Firing

**Problem:** `vcc_currency_changed` event not received.

**Solution:**
1. Check VCC version (must be 1.1.0+)
2. Verify VCC is active: `if (typeof VCC !== 'undefined')`
3. Check browser console for JavaScript errors
4. Ensure jQuery is loaded before your script

### Rates Not Available

**Problem:** `VCC.rates` is empty or undefined.

**Solution:**
1. Check if on product page (rates only loaded on product/shop pages)
2. Verify ExchangeRate-API key is configured in VCC settings
3. Check VCC debug mode: `console.log(VCC.rates)`

### Currency Mismatch

**Problem:** Your plugin shows different currency than VCC.

**Solution:**
```javascript
// Always use VCC as source of truth
var currentCurrency = VCC.getCurrentCurrency();
```

### Double Conversion

**Problem:** Prices converted twice (once by VCC, once by your plugin).

**Solution:**
- Use VCC's `convert_from_gbp()` method
- OR listen to VCC events and use pre-converted prices
- Don't apply your own conversion on top of VCC's

---

## API Reference Summary

### PHP Methods

| Method | Parameters | Returns | Description |
|--------|------------|---------|-------------|
| `get_selected_currency()` | - | `string` | Current currency code |
| `convert_from_gbp($amount, $to)` | `float, string` | `float\|WP_Error` | Convert GBP to currency |
| `get_public_exchange_rate($from, $to)` | `string, string` | `float\|WP_Error` | Get exchange rate |
| `get_all_exchange_rates($base)` | `string` | `array` | Get all rates |
| `get_currency_symbol($currency)` | `string` | `string` | Get symbol (€, $, £) |
| `get_product_currency_data($id)` | `int` | `array` | Get product meta |

### JavaScript Methods

| Method | Parameters | Returns | Description |
|--------|------------|---------|-------------|
| `VCC.getCurrentCurrency()` | - | `string` | Current currency |
| `VCC.getExchangeRate(from, to)` | `string, string` | `float\|null` | Exchange rate |
| `VCC.convertAmount(amt, from, to)` | `float, string, string` | `float\|null` | Convert amount |
| `VCC.formatPrice(amt, currency)` | `float, string` | `string` | Format with symbol |

### Events

| Event | Type | Data | Description |
|-------|------|------|-------------|
| `vcc_currency_changed` | jQuery | `{currency, previousCurrency, rates, symbols}` | Currency changed |
| `vcc_currency_changed` | Native DOM | Same (in `event.detail`) | Currency changed |

### Filter Hooks

| Hook | Parameters | Returns | Description |
|------|------------|---------|-------------|
| `vcc_get_exchange_rate` | `$rate, $from, $to` | `float\|null` | Override rate |
| `vcc_all_exchange_rates` | `$rates, $base` | `array` | Modify bulk rates |
| `vcc_country_to_currency_mapping` | `$mapping` | `array` | Country mappings |
| `vcc_auto_detected_currency` | `$currency, $country` | `string` | Override detection |
| `vcc_product_currency_data` | `$data, $product_id` | `array` | Product data |

---

## Support

For issues or questions:
- GitHub: https://github.com/Moley123/WooCommerceMultiCurrency
- Plugin Version Required: 1.1.0+

---

**Version:** 1.0
**Last Updated:** January 15, 2026
**Compatible With:** VCC 1.1.0+

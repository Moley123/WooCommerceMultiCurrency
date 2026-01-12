# Vignette Currency Converter

A WordPress/WooCommerce plugin for multi-currency support designed specifically for vignette resellers. Convert prices from source currencies (like CHF for Swiss vignettes) to GBP, and allow customers to view prices in their preferred currency.

## Features

- **Source Currency Conversion**: Set the original currency for each vignette product (CHF, EUR, etc.) and automatically convert to GBP
- **Customer Currency Selection**: Allow customers to view prices in their preferred currency
- **ExchangeRate-API Integration**: Uses reliable ExchangeRate-API for accurate, real-time exchange rates
- **Smart Caching**: Cache exchange rates for 12 hours to minimize API calls and improve performance
- **Automatic Price Updates**: Bulk update all product prices when exchange rates change
- **WooCommerce Integration**: Seamlessly integrates with WooCommerce product pages, shop pages, and cart
- **Responsive Design**: Mobile-friendly currency selector

## Supported Currencies

- GBP (British Pound) - Base currency
- EUR (Euro)
- USD (US Dollar)
- CHF (Swiss Franc)
- CAD (Canadian Dollar)
- AUD (Australian Dollar)
- JPY (Japanese Yen)

## Installation

1. **Upload Plugin**
   - Download the `vignette-currency-converter` folder
   - Upload to `/wp-content/plugins/` directory
   - Or install via WordPress admin: Plugins > Add New > Upload Plugin

2. **Activate Plugin**
   - Go to Plugins in WordPress admin
   - Find "Vignette Currency Converter"
   - Click "Activate"

3. **Configure Settings**
   - Go to WooCommerce > Currency Converter
   - Enter your ExchangeRate-API key (get free key at https://www.exchangerate-api.com)
   - Configure enabled currencies
   - Set cache duration (default: 12 hours)
   - Save settings

## Usage

### Setting Up Vignette Products

1. **Create/Edit Product**
   - Go to Products > Add New (or edit existing product)
   - In the "Vignette Currency Settings" meta box:
     - Select "Source Currency" (e.g., CHF for Swiss vignette)
     - Enter "Source Price" (e.g., 40.00)
     - Check "Auto-update GBP price" if desired
   - The plugin will automatically convert to GBP and set the WooCommerce price

2. **Example**
   - Swiss Vignette: Source = CHF, Source Price = 40.00
   - Plugin converts: CHF 40.00 → £35.20 GBP (example rate)
   - WooCommerce stores £35.20 as the product price

### Customer Experience

1. **Currency Selector**
   - Customers see a dropdown on product pages
   - Can select their preferred currency (EUR, USD, CHF, etc.)
   - Prices automatically update to selected currency

2. **Price Display**
   - Prices shown in selected currency
   - Example: Customer selects EUR, sees "€47.20" instead of "£40.00"

3. **Cart & Checkout**
   - Prices displayed in selected currency
   - Note shown: "Prices displayed in EUR. Payment will be processed in GBP."

## Admin Features

### Settings Page (WooCommerce > Currency Converter)

- **API Configuration**
  - ExchangeRate-API key
  - Test API connection button

- **Currency Settings**
  - Base currency (GBP - fixed)
  - Enable/disable specific currencies

- **Cache Settings**
  - Set cache duration (1-168 hours)
  - Clear cache button

- **Display Settings**
  - Show/hide currency selector
  - Currency selector position (before price, before add to cart, after add to cart)

- **Bulk Actions**
  - Update all product prices from current exchange rates

### Product List Columns

The admin product list shows:
- Source Currency
- Source Price
- Regular Price (converted GBP price)

## API Information

### ExchangeRate-API

**Free Plan:**
- 1,500 requests/month
- 165+ currencies
- No credit card required
- Sign up: https://www.exchangerate-api.com

**Caching:**
- Default: 12 hours
- Reduces API calls significantly
- Example: With 10 products, only ~20 API calls per day

## Technical Details

### File Structure

```
vignette-currency-converter/
├── vignette-currency-converter.php (Main plugin file)
├── includes/
│   ├── class-currency-api.php (ExchangeRate-API integration)
│   ├── class-currency-converter.php (Conversion logic)
│   ├── class-admin-settings.php (Admin settings page)
│   └── class-frontend-display.php (Customer-facing UI)
├── assets/
│   ├── css/
│   │   └── frontend.css (Frontend styles)
│   └── js/
│       └── currency-selector.js (Frontend JavaScript)
└── README.md
```

### Hooks & Filters

**Actions:**
- `woocommerce_before_add_to_cart_button` - Currency selector display
- `woocommerce_after_add_to_cart_button` - Alternative selector position
- `woocommerce_single_product_summary` - Before price selector position

**Filters:**
- `woocommerce_get_price_html` - Price display modification
- `woocommerce_cart_item_price` - Cart price display
- `vcc_available_currencies` - Customize available currencies

### Database

**Options:**
- `vcc_settings` - Plugin settings

**Transients (Cache):**
- `vcc_rate_{FROM}_{TO}` - Individual exchange rates
- `vcc_all_rates_{BASE}` - All rates for base currency

**Product Meta:**
- `_vcc_source_currency` - Source currency code
- `_vcc_source_price` - Price in source currency
- `_vcc_auto_update` - Auto-update setting
- `_vcc_last_conversion_rate` - Last used conversion rate
- `_vcc_last_conversion_date` - Last conversion date

## Requirements

- WordPress 5.8 or higher
- WooCommerce 5.0 or higher
- PHP 7.4 or higher
- ExchangeRate-API key (free)

## Frequently Asked Questions

**Q: Do I need a credit card for the API?**
A: No, ExchangeRate-API offers a free plan with no credit card required.

**Q: How often are exchange rates updated?**
A: Exchange rates are cached for 12 hours by default (configurable). The plugin fetches new rates from the API when the cache expires.

**Q: Will customers pay in their selected currency?**
A: No, customers will pay in GBP (your base currency). The currency selector only changes the price display for customer convenience.

**Q: Can I add more currencies?**
A: Yes, you can use the `vcc_available_currencies` filter in your theme's functions.php to add more currencies supported by ExchangeRate-API.

**Q: What happens if the API is down?**
A: The plugin uses cached rates as a fallback. If both the API and cache are unavailable, prices will display in GBP only.

## Changelog

### Version 1.0.0
- Initial release
- ExchangeRate-API integration
- Source currency to GBP conversion
- Customer currency selector
- Admin settings page
- Product meta fields
- 12-hour caching
- Support for 7 major currencies

## Support

For issues, questions, or feature requests:
- GitHub: https://github.com/Moley123/WooCommerceMultiCurrency
- Create an issue on GitHub

## License

GPL v2 or later

## Credits

Developed for vignette resellers to simplify multi-currency pricing.

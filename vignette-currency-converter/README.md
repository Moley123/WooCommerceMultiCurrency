# Vignette Currency Converter

A WordPress/WooCommerce plugin for multi-currency support designed specifically for vignette resellers. Convert prices from source currencies (like CHF for Swiss vignettes) to GBP, and allow customers to view prices in their preferred currency.

## Features

- **Source Currency Conversion**: Set the original currency for each vignette product (CHF, EUR, etc.) and automatically convert to GBP
- **Customer Currency Selection**: Allow customers to view prices in their preferred currency from any page
- **Flag + Name Selector**: Currency dropdown shows `🇬🇧 GBP — British Pound (£)` style labels
- **Full European Currency Set**: GBP, EUR, USD, CHF, NOK, SEK, DKK, PLN, CZK, HUF, RON, BGN, CAD, AUD — all manageable from admin
- **Dynamic Currency Management**: Add any ISO 4217 currency code from admin settings; remove custom currencies with one click
- **Stripe Multi-Currency Charging**: Optional toggle — charge customers in their selected currency via the WooCommerce Stripe Gateway; Stripe settles to your GBP account automatically
- **Global Currency Selector**: Fixed-position selector available on every page
- **Shortcode Support**: `[vcc_currency_selector]` — place the selector anywhere in your theme
- **WordPress Widget**: Add the currency selector to any widget area (e.g. OceanWP Top Bar, Header Right) via Appearance → Widgets
- **Full Journey Consistency**: Cart, mini-cart, and checkout all display prices in the customer's selected currency
- **Instant Price Updates**: Currency changes apply instantly with no page reload, including WCEPO option-driven price changes
- **ExchangeRate-API Integration**: Uses reliable ExchangeRate-API for accurate, real-time exchange rates
- **Smart Caching**: Cache exchange rates for 12 hours to minimize API calls and improve performance
- **Automatic Price Updates**: Bulk update all product prices when exchange rates change
- **WCEPO Compatible**: Full compatibility with WooCommerce Extra Product Options via priority-999 filters and JS fallback
- **Responsive Design**: Mobile-friendly currency selector (full-width bar on mobile)

## Supported Currencies

| Flag | Code | Name | Symbol |
|------|------|------|--------|
| 🇬🇧 | GBP | British Pound *(base)* | £ |
| 🇪🇺 | EUR | Euro | € |
| 🇺🇸 | USD | US Dollar | $ |
| 🇨🇭 | CHF | Swiss Franc | CHF |
| 🇳🇴 | NOK | Norwegian Krone | NOK |
| 🇸🇪 | SEK | Swedish Krona | SEK |
| 🇩🇰 | DKK | Danish Krone | DKK |
| 🇵🇱 | PLN | Polish Zloty | zł |
| 🇨🇿 | CZK | Czech Koruna | Kč |
| 🇭🇺 | HUF | Hungarian Forint | Ft |
| 🇷🇴 | RON | Romanian Leu | lei |
| 🇧🇬 | BGN | Bulgarian Lev | лв |
| 🇨🇦 | CAD | Canadian Dollar | CA$ |
| 🇦🇺 | AUD | Australian Dollar | A$ |

Any additional ISO 4217 currency code can be added via the admin settings custom currency input.

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
   - A currency selector is available on every page (fixed bottom-right by default)
   - Can also be placed in a widget area or via shortcode (e.g. OceanWP header/top bar)
   - Prices update instantly across the entire page with no reload

2. **Price Display**
   - Prices shown in selected currency everywhere: product pages, shop, cart, checkout
   - Example: Customer selects EUR, sees "€47.20" instead of "£40.00"

3. **Cart & Checkout**
   - Cart line items, subtotals, and order total all display in the selected currency
   - Mini-cart refreshes automatically after currency change
   - Notice shown at checkout: "Prices shown in EUR. Payment processed in GBP."

### Adding the Currency Selector to Your Header (OceanWP)

1. Go to **Appearance → Customize → Top Bar** and enable the Top Bar
2. Go to **Appearance → Widgets**
3. Find the **"Top Bar Right"** (or Left) widget area
4. Add the **"Currency Selector"** widget
5. Save

Alternatively, use the shortcode `[vcc_currency_selector]` in any Custom HTML widget or page builder block.

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
  - Currency selector position: `Fixed Footer` (floating on all pages) or `Shortcode/Widget Only` (manual placement)
  - Use `[vcc_currency_selector]` shortcode or the Currency Selector widget for manual placement

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
- `wp_footer` - Renders global currency selector on every frontend page
- `widgets_init` - Registers `VCC_Currency_Selector_Widget`
- `woocommerce_review_order_after_order_total` - Checkout currency notice
- `woocommerce_cart_totals_before_order_total` - Cart currency notice

**Filters:**
- `woocommerce_get_price_html` (priority 999) - Product price display
- `woocommerce_cart_item_price` (priority 999) - Cart line item price
- `woocommerce_cart_item_subtotal` (priority 999) - Cart line item subtotal
- `woocommerce_cart_subtotal` (priority 999) - Cart subtotal total
- `woocommerce_cart_total` (priority 999) - Cart grand total
- `woocommerce_cart_totals_order_total_html` (priority 999) - Order total HTML
- `woocommerce_widget_shopping_cart_total` (priority 999) - Mini-cart total
- `vcc_available_currencies` - Customize available currencies
- `vcc_country_to_currency_mapping` - Customize country-to-currency mappings
- `vcc_auto_detected_currency` - Override auto-detected currency
- `vcc_get_exchange_rate` - Provide custom exchange rates
- `vcc_all_exchange_rates` - Filter bulk rate responses
- `vcc_product_currency_data` - Modify product data passed to JavaScript

**Shortcodes:**
- `[vcc_currency_selector]` - Renders the currency selector inline

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

### Version 1.5.0
- Added: Lightweight analytics logging — every currency switch and non-GBP checkout is recorded (masked IP, country, city, page URL, device type, user)
- Added: Analytics section in admin settings with summary stats, top currencies/countries tables, and paginated events table
- Added: Data retention setting (7–365 days or forever), daily auto-cleanup via WP-Cron, Clear All Data and Run Cleanup Now buttons
- Added: Analytics can be disabled without uninstalling the plugin

### Version 1.4.2
- Changed: Selecting a currency now reloads the page after saving the session, ensuring all prices are server-rendered in the correct currency (eliminates WCEPO re-render race condition)

### Version 1.4.1
- Fixed: Currencies whose symbol equals their code (CHF, NOK, SEK, etc.) no longer display as `CHF CHF` — trigger shows just the code when symbol and code are identical
- Fixed: Selecting or resetting a product variant now re-applies currency conversion to the freshly-rendered price
- Fixed: MutationObserver now watches variation price containers so DOM-driven re-renders are caught
- Fixed: Prices now convert on page load when a non-GBP currency is already in session (geolocation auto-set, returning visitor)

### Version 1.4.0
- Currency selector redesigned as a plain-text trigger (`£ GBP`) that opens a centered popup modal on click — no more `<select>` dropdown
- Popup shows all currencies in a 2-column grid: emoji flag, full name, symbol; selected currency highlighted with checkmark
- Popup closes via × button, backdrop click, or Escape key; keyboard-accessible throughout
- Cart and checkout notices are now Stripe-aware — "You will be charged in [currency]" when Stripe multi-currency is on, "Payment will be processed in GBP" when off
- Mobile: popup slides up from bottom as a full-width sheet with single-column grid
- Dark mode support for popup panel

### Version 1.3.0
- Stripe multi-currency charging — toggleable; charges customers in selected currency, settles to GBP bank account
- Exchange rate locked at checkout order creation; stored in order meta for accounting
- Admin order panel shows full conversion details (charged currency, amount, rate, timestamp)
- Order emails include charged currency note
- Currency selector now shows flag emoji + full name + symbol (e.g. `🇬🇧 GBP — British Pound (£)`)
- Full European currency list: NOK, SEK, DKK, PLN, CZK, HUF, RON, BGN added; JPY removed
- Admin currency management redesigned with styled checkbox grid + custom currency add/remove

### Version 1.2.0
- Global currency selector available on every page (fixed footer, shortcode, or widget)
- `[vcc_currency_selector]` shortcode and WordPress widget for flexible placement (e.g. OceanWP Top Bar)
- Cart, mini-cart, and checkout prices now display in the customer's selected currency
- Checkout notice: "Prices shown in X. Payment processed in GBP."
- MutationObserver re-applies currency conversion when WCEPO re-renders prices
- Fixed: Fatal error `get_id() on string` in `enqueue_frontend_assets()`
- Fixed: Prices not updating — missing data attributes on price elements
- Fixed: GBP disappearing from currency selector after saving admin settings
- Fixed: WCEPO compatibility — WCEPO fallback now always runs on every currency change
- Raised filter priority to 999 to run after WCEPO and other plugins

### Version 1.1.0
- Instant currency switching (no page reload)
- Auto-detection via IP geolocation
- Public API methods for external plugin integration
- Session-based manual currency override
- Dual event system (jQuery + native DOM)
- WCEPO integration support

### Version 1.0.4
- Variable product support with per-variation pricing and markup overrides

### Version 1.0.3
- Markup feature (percentage and fixed amount) with global and per-product overrides

### Version 1.0.2
- HPOS (High-Performance Order Storage) compatibility

### Version 1.0.1
- Fixed product meta save timing issues

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

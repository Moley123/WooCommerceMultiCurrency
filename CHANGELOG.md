# Changelog

All notable changes to the Vignette Currency Converter plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0] - 2026-02-16

### Added
- **Stripe Multi-Currency Charging**: New toggleable feature in admin settings. When enabled, the WooCommerce order currency and total are converted to the customer's selected display currency at checkout. The WooCommerce Stripe Gateway then charges the customer in that currency. Stripe settles to your GBP bank account automatically.
- **Exchange rate locking**: Rate is locked at the moment the order is created (checkout submit), not at cart or display time, preventing stale-rate issues.
- **Order meta for accounting**: Original GBP total, exchange rate, charged currency, charged total, and rate-locked timestamp stored as order meta (`_vcc_original_gbp_total`, `_vcc_exchange_rate`, `_vcc_charged_currency`, `_vcc_charged_total`, `_vcc_rate_locked_at`).
- **Admin order panel**: Multi-currency charge details shown in a dedicated panel on the WooCommerce order edit screen.
- **Email currency note**: Order confirmation and admin emails include a line noting the charged currency and original GBP equivalent.
- **Flag + currency name selector**: Currency dropdown now shows `🇬🇧 GBP — British Pound (£)` style labels instead of plain currency codes.
- **Full European currency list**: Replaced the previous 7-currency hardcoded list with a comprehensive European-focused set — GBP, EUR, USD, CHF, NOK, SEK, DKK, PLN, CZK, HUF, RON, BGN, CAD, AUD. Removed JPY.
- **Dynamic currency management**: Admin settings now show all currencies as a styled checkbox grid. Custom currencies (any valid ISO 4217 code) can be added via text input and appear as removable tags; they persist across settings saves.
- **Central currency registry**: New `VCC_Currency_Converter::get_all_currencies()` static method — single source of truth for flags, names, and symbols used by admin UI, frontend selector, and price formatting.

### Changed
- **Price formatting** unified across PHP and JS: prefix currencies (GBP, EUR, USD, CAD, AUD) show symbol before amount; all others show amount followed by symbol/code (e.g. `250.00 CHF`, `250.00 NOK`, `250.00 zł`).
- **Default enabled currencies** on fresh install now uses the full European currency list instead of the previous 7-currency set.
- **`get_currency_symbol()`** now sourced from `get_all_currencies()` registry, covering all 14 predefined currencies.
- **`get_available_currencies()`** default falls back to full registry if no settings saved yet.
- **JS `formatPrice()`** updated with explicit prefix/suffix logic matching PHP; JPY special-case removed.

### Technical
- New file: `includes/class-stripe-integration.php`
- `VCC_Stripe_Integration::get_instance()` initialised in main plugin `init()` method
- Stripe class hooks: `woocommerce_checkout_order_created` (priority 10), `woocommerce_admin_order_data_after_billing_address`, `woocommerce_email_order_meta`
- Admin custom currency JS: add via text input + Enter/button, remove via × tag, persisted as `vcc_settings[enabled_currencies][]` hidden inputs
- `stripe_multicurrency_enabled` added to settings sanitization

## [1.2.0] - 2026-02-16

### Added
- **Global Currency Selector**: Currency selector now renders on every page as a fixed-position widget (bottom-right), no longer limited to product pages
- **`[vcc_currency_selector]` Shortcode**: Place the currency selector anywhere in your theme — page templates, header, footer, or any content area
- **WordPress Widget** (`VCC_Currency_Selector_Widget`): Add the currency selector to any registered widget area (e.g. OceanWP Top Bar, Header Right) via Appearance → Widgets
- **Cart & Checkout Currency Consistency**: Prices in cart line items, cart totals, order review table, and mini-cart now all display in the customer's selected currency
- **Checkout Currency Notice**: Informational notice at checkout — "Prices shown in X. Payment processed in GBP." — so customers understand the billing currency
- **WooCommerce Fragment Refresh**: Changing currency now triggers a live mini-cart refresh via WooCommerce's built-in AJAX fragment system
- **MutationObserver**: Automatically re-applies currency conversion when WCEPO or other plugins re-render price elements after option changes
- **Cart Page Live Refresh**: Changing currency on the cart page automatically recalculates cart totals

### Fixed
- **Fatal error** (`Call to a member function get_id() on string`) in `enqueue_frontend_assets()` when the global `$product` variable was a string instead of a `WC_Product` object
- **Prices not updating on currency change** — price elements were missing required `data-product-id`, `data-source-currency`, `data-source-price`, `data-gbp-price`, and `data-currency` attributes needed by JavaScript
- **WCEPO compatibility** — WooCommerce Extra Product Options plugin was replacing our price wrapper HTML entirely; WCEPO fallback selector now always runs on every currency change (not only when `vcc-converted-price` elements are absent)
- **GBP missing from frontend currency selector** — GBP checkbox in admin settings is correctly disabled (base currency cannot be deselected), but disabled HTML checkboxes do not submit with forms; fixed by adding a hidden input to ensure GBP is always included in `enabled_currencies` on save

### Changed
- **Filter priority** raised from `10` to `999` on all `woocommerce_get_price_html`, `woocommerce_cart_item_price`, and `woocommerce_cart_item_subtotal` filters to ensure our wrapper runs after WCEPO and other plugins
- **Asset loading** expanded from product/shop/category/tag pages only to all non-admin pages, enabling currency conversion on cart, checkout, and custom pages
- **Currency selector binding** changed from `#vcc-currency-selector` (single element) to `[data-vcc-selector]` attribute (multiple instances), all synced on change
- **Selector position options** updated: `fixed_footer` (automatic floating selector on all pages, default) and `shortcode_only` (manual placement only via shortcode or widget)
- **JavaScript** rewritten to v1.2.0 with multi-instance selector sync, always-on WCEPO fallback, and MutationObserver integration

### Technical
- New PHP filters: `woocommerce_cart_subtotal`, `woocommerce_cart_total`, `woocommerce_cart_totals_order_total_html`, `woocommerce_widget_shopping_cart_total`
- New PHP action: `woocommerce_review_order_after_order_total` for checkout currency notice
- `wp_footer` action renders global selector HTML on every frontend page
- `widgets_init` registers `VCC_Currency_Selector_Widget`
- Cart product data now populated from `WC()->cart` items during asset localization
- `vccData` now includes `is_cart` and `is_checkout` flags for context-aware JS behaviour

## [1.1.0] - 2026-01-15

### Added
- **Instant Currency Switching**: Replaced full page reload with JavaScript DOM manipulation for instant price updates
- **Auto-Detection**: Automatic currency detection based on customer IP address using ipapi.co
- **Geolocation Class**: New `VCC_Geolocation` class with country-to-currency mapping for 50+ countries
- **Public API Methods**: External plugins (like WCEPO) can now access exchange rates via public methods
  - `get_public_exchange_rate($from, $to)` - Get specific exchange rate
  - `get_all_exchange_rates($base)` - Get all rates for JavaScript
  - `get_product_currency_data($product_id)` - Get product meta for frontend
- **Dual Event System**: Currency changes now dispatch both jQuery and Native DOM events for maximum compatibility
- **Admin Settings**:
  - Enable/disable auto-detection
  - Hide currency selector when auto-detection is enabled
- **Filter Hooks** for external plugin integration:
  - `vcc_country_to_currency_mapping` - Customize country mappings
  - `vcc_auto_detected_currency` - Override auto-detected currency
  - `vcc_get_exchange_rate` - External plugins can provide custom rates
  - `vcc_all_exchange_rates` - Filter bulk rate responses
  - `vcc_product_currency_data` - Modify product data for JavaScript

### Changed
- **Currency Selector JavaScript**: Complete rewrite with event-driven architecture
  - No page reload required - prices update instantly (< 100ms)
  - Exchange rates pre-loaded on page for offline calculation
  - Exposed globally as `window.VCC` and `$.VCC` for external access
- **Session Management**: Manual currency overrides now session-based (not permanent cookie)
- **Frontend Display**: Auto-detection respects user manual selections for entire session
- **Data Localization**: Exchange rates, symbols, and product data passed to JavaScript via `wp_localize_script()`

### Improved
- **User Experience**: Currency changes are now instant with no page disruption
- **Mobile Performance**: Async session updates (fire-and-forget, non-blocking)
- **External Plugin Compatibility**: WCEPO and other plugins can integrate via events and public API
- **Geolocation Caching**: Country detection cached for 24 hours per IP to minimize API calls

### Technical
- Added ipapi.co integration (free tier: 1,000 requests/day)
- Event system: `vcc_currency_changed` fired with full context data
- Manual override flag stored in session for auto-detection bypass
- Hide selector logic: `auto_detect + hide_selector` both required

## [1.0.4] - 2026-01-14

### Added
- WooCommerce variable product support
- Per-variation source pricing
- Variation-specific markup overrides
- Markup priority system: Variation > Parent > Global

### Changed
- Frontend price display updated to handle variations
- Cart price display updated for variation support
- Bulk price updater now includes product variations

## [1.0.3] - 2026-01-13

### Added
- Markup feature (percentage and fixed amount)
- Global markup defaults with per-product overrides
- Markup applied to source price before GBP conversion

### Fixed
- Admin source price not saving to GBP correctly
- Frontend showing wrong price for source currency
- Hook priority issue with `woocommerce_process_product_meta`

## [1.0.2] - 2026-01-12

### Added
- HPOS (High-Performance Order Storage) compatibility declaration
- FeaturesUtil integration for WooCommerce 8.2+

### Fixed
- "Incompatible with WooCommerce features" warning

## [1.0.1] - 2026-01-11

### Fixed
- Product meta save timing issue
- Price conversion not triggering on product save

## [1.0.0] - 2026-01-10

### Added
- Initial plugin release
- ExchangeRate-API integration
- Multi-currency support (GBP, EUR, USD, CHF, CAD, AUD, JPY)
- Source currency to GBP conversion
- Customer currency selector dropdown
- 12-hour exchange rate caching
- Admin settings page
- Product-level source currency and price settings

[1.3.0]: https://github.com/Moley123/WooCommerceMultiCurrency/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/Moley123/WooCommerceMultiCurrency/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/Moley123/WooCommerceMultiCurrency/compare/v1.0.4...v1.1.0
[1.0.4]: https://github.com/Moley123/WooCommerceMultiCurrency/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/Moley123/WooCommerceMultiCurrency/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/Moley123/WooCommerceMultiCurrency/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/Moley123/WooCommerceMultiCurrency/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/Moley123/WooCommerceMultiCurrency/releases/tag/v1.0.0

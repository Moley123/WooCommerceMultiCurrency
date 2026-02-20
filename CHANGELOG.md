# Changelog

All notable changes to the Vignette Currency Converter plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.5.1] - 2026-02-20

### Added
- **Auto-switch tracking**: IP geolocation-based currency auto-detection is now logged separately as `event_type = 'auto_switch'` in the analytics table. Auto-switches are deduplicated per session (only the first auto-detection is logged, not every page refresh).
- **Analytics dashboard tabs**: Settings and Analytics are now separate tabs on the admin page for better organization. Analytics data no longer appears below the settings form.
- **Auto Switches summary card**: Analytics dashboard now shows Manual Switches (blue), Auto Switches (orange), and Non-GBP Checkouts (green) as separate summary cards.
- **Manual/Auto filter links**: Events table filter links changed from "All / Switches / Checkouts" to "All / Manual / Auto / Checkouts" for granular filtering.
- **Auto-switch badge**: Events table rows with `event_type = 'auto_switch'` display an orange "auto" badge instead of blue "manual".

### Changed
- **Summary card labels**: "Currency Switches" card renamed to "Manual Switches" to distinguish from auto-detected switches.
- **Top Currencies/Countries aggregates**: Now include both manual and auto switches (all `event_type IN ('switch', 'auto_switch')` rows).
- **From → To display**: Auto-switch events show `GBP → [detected currency]` in the events table (GBP is the base/default currency).

### Technical
- New method: `VCC_Analytics::log_auto_switch_event($currency_to)` — logs with `event_type = 'auto_switch'`, `currency_from = 'GBP'`
- `VCC_Frontend_Display::auto_detect_currency()` now calls analytics logging after storing the detected currency in session, only if the session value is new
- `VCC_Analytics::get_summary()` updated to count `total_auto_switches` separately and include both event types in currency/country aggregates
- `VCC_Admin_Settings::render_settings_page()` implements tab navigation using `?tab=` query parameter; conditionally renders settings form or analytics dashboard based on active tab

## [1.5.0] - 2026-02-19

### Added
- **Analytics logging**: Every manual currency switch and every non-GBP checkout is recorded in a new `wp_vcc_currency_events` database table.
- **Per-event data**: event type (switch/checkout), currency switched from/to, masked IP (`81.*.*.*`), country, city (via ip-api.com, cached 24 h per IP in a separate transient), page URL, device type (mobile/desktop), user ID (0 for guests).
- **Analytics section in admin settings**: Summary cards (total switches, non-GBP checkouts, most popular currency, date range), top-10 currencies and countries tables, paginated events table (25 rows/page) with type/switch/checkout filter links.
- **Data retention setting**: Choose 7, 14, 30, 90, 180, 365 days, or keep forever. Cleanup runs automatically via daily WP-Cron.
- **Clear All Data button**: Truncates the events table immediately (with confirmation prompt).
- **Run Cleanup Now button**: Applies the retention window on demand without waiting for the cron run.
- **Analytics enable/disable toggle**: Can turn off logging without uninstalling.
- **DB table auto-provisioned**: `dbDelta` runs on every plugin init when the DB version changes, so updates on manually-uploaded installs also get the table.

### Technical
- New file: `includes/class-analytics.php` — `VCC_Analytics` singleton
- `ajax_change_currency()` now accepts `currency_from` and `page_url` POST params and calls `VCC_Analytics::log_switch_event()`
- JS `updateSession()` now passes `currency_from` (previous selection) and `page_url` (`window.location.href`) in the AJAX payload
- WP-Cron event `vcc_analytics_cleanup` registered on init; unscheduled on plugin deactivation

## [1.4.2] - 2026-02-17

### Changed
- **Page reload on currency change**: Selecting a currency now saves the preference to the PHP session and reloads the page. This ensures all prices, cart totals, and checkout figures are server-rendered in the correct currency, eliminating the race condition where WCEPO re-renders could overwrite client-side price updates.

## [1.4.1] - 2026-02-17

### Fixed
- **Trigger text deduplication**: Currencies whose symbol equals their code (CHF, NOK, SEK, DKK, PLN, CZK, HUF, RON, BGN) no longer show as `CHF CHF`. The trigger now shows just the code (`CHF`) when symbol and code are identical, and `symbol code` (e.g. `£ GBP`) when they differ.
- **Variant selection not updating price**: Added `found_variation` and `reset_data` WooCommerce event listeners so prices are re-converted 150 ms after WooCommerce re-renders the variation price HTML.
- **Variation price containers not observed**: MutationObserver now also watches `.woocommerce-variation-price` and `.woocommerce-variation-add-to-cart` so DOM-driven re-renders are caught.
- **WCEPO fallback using wrong product**: `wrapAndUpdateWCEPOPrice()` now resolves product ID from the nearest `form.variations_form[data-product_id]` or `.product[data-product_id]` ancestor before falling back to the first known key.
- **Prices not converting on page load for returning visitors**: On init, if the session already holds a non-GBP currency (geolocation auto-set or returning visitor), `updateAllPrices` now fires after 200 ms so prices are immediately shown in the correct currency without requiring a manual switch.

## [1.4.0] - 2026-02-17

### Added
- **Currency selector popup**: The currency selector is now a lightweight plain-text trigger (e.g. `£ GBP`) that opens a centered modal popup on click. No more `<select>` dropdown.
- **Popup design**: 2-column grid of currency options, each showing an emoji flag, full currency name, and symbol. Selected currency is highlighted with a blue tint and a checkmark (✓).
- **Accessibility**: Popup uses `role="dialog"`, `aria-modal="true"`, `aria-selected` on options, `tabindex`/keyboard navigation (Enter, Space to open/select; Escape to close).
- **Multiple close methods**: × button in popup header, clicking the backdrop overlay, or pressing Escape all close the popup.
- **Stripe-aware cart/checkout notices**: Cart and checkout now show context-sensitive messages — "You will be charged in [currency]" when Stripe multi-currency is enabled, or "Payment will be processed in GBP" when disabled.

### Changed
- **Selector trigger**: Replaced `<select>` element and "Currency:" label with a transparent `<span data-vcc-trigger>` that inherits the surrounding theme's font and colour — fits naturally into header nav bars, widget areas, and shortcode placements.
- **Popup rendered once**: Popup HTML is output once via `wp_footer` (priority 20) and shared by all trigger instances on the page.
- **CSS rewrite**: Removed all old fixed-position box, label, and `<select>` styles. New stylesheet covers trigger, popup overlay, popup panel, 2-column currency grid, selected state, and dark mode.
- **JS rewrite**: Replaced `change` event on `[data-vcc-selector]` with click/keyboard handlers on `[data-vcc-trigger]`, `.vcc-popup-option`, `.vcc-popup-close`, and `#vcc-popup-overlay`. All trigger instances sync on currency change.
- **Mobile popup**: On screens ≤ 480 px the popup slides up from the bottom (sheet style), full-width, single-column grid.

### Technical
- `render_selector()` now outputs `<span class="vcc-currency-trigger" data-vcc-trigger>` instead of `<select>`
- `render_currency_popup()` method added to `VCC_Frontend_Display`; hooked to `wp_footer` at priority 20
- JS: new methods `openPopup()`, `closePopup()`, `selectCurrency()`, `updateTriggerText()` replace old `handleCurrencyChange()`
- `body.vcc-popup-open` class added while popup is visible (used to prevent body scroll)

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

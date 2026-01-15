# Changelog

All notable changes to the Vignette Currency Converter plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.1.0]: https://github.com/Moley123/WooCommerceMultiCurrency/compare/v1.0.4...v1.1.0
[1.0.4]: https://github.com/Moley123/WooCommerceMultiCurrency/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/Moley123/WooCommerceMultiCurrency/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/Moley123/WooCommerceMultiCurrency/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/Moley123/WooCommerceMultiCurrency/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/Moley123/WooCommerceMultiCurrency/releases/tag/v1.0.0

# Vignette Currency Converter - Selector Improvements Implementation Plan

**Date:** January 14, 2026
**Version:** 1.0
**Status:** Requirements Analysis

---

## Executive Summary

This document outlines the technical approach and considerations for three interconnected improvements to the VCC currency selector:

1. **Issue #1:** Replace full-page `location.reload()` with JavaScript price updates
2. **Issue #2:** Auto-detect customer location and set currency automatically
3. **Issue #3:** Add setting to hide selector when auto-detection is enabled

Additionally, these improvements must maintain compatibility with WCEPO integration requirements.

---

## Current State Analysis

### Current Currency Selector Flow

**File:** `/vignette-currency-converter/assets/js/currency-selector.js`

```
User selects currency
    ↓
AJAX request to vcc_change_currency
    ↓
Session/Cookie updated
    ↓
location.reload() → Full page reload
    ↓
Page reloads with new prices calculated server-side
```

**Current Limitations:**
- Full page reload is slow and disrupts user experience
- No JavaScript events exposed for external plugins (e.g., WCEPO)
- No automatic detection of customer location
- No way to hide selector during auto-detection

### Current Public API Methods

The following methods are currently available and used by WCEPO:

**VCC_Frontend_Display:**
```php
public function get_selected_currency()
```
- Returns currently selected currency string
- Used by WCEPO to determine display currency

**VCC_Currency_Converter:**
```php
public function convert_from_gbp($amount, $to_currency)
public function get_currency_symbol($currency)
```
- Used by WCEPO for price conversions and formatting

**VCC_Currency_API:**
```php
public function get_exchange_rate($from, $to)
```
- Current implementation only internal, not exposed for external use
- WCEPO may need access to exchange rates for its own calculations

### Session/Cookie Management

**Current Implementation:**
```php
// Frontend Display - init_session() method
if (isset($_SESSION['vcc_selected_currency'])) {
    $this->selected_currency = $_SESSION['vcc_selected_currency'];
} elseif (isset($_COOKIE['vcc_selected_currency'])) {
    $this->selected_currency = $_COOKIE['vcc_selected_currency'];
} else {
    $this->selected_currency = 'GBP'; // Default
}

// AJAX handler - ajax_change_currency() method
$_SESSION['vcc_selected_currency'] = $currency;
setcookie('vcc_selected_currency', $currency, time() + (86400 * 30), '/');
```

---

## Issue #1: Replace location.reload() with JavaScript Price Updates

### Technical Approach Options

#### **Option A: Frontend-Only DOM Manipulation (Recommended)**

**Approach:** Update prices on-page without server call, using cached exchange rates

**Advantages:**
- Fastest UX (no page reload, no additional server calls)
- Completely client-side after initial page load
- Works offline after first load
- Minimal server load
- Best for mobile users

**Disadvantages:**
- Requires pre-loading all exchange rates in frontend JavaScript
- Stale rates if rates change during session (mitigated by periodic refresh)
- Need to handle markup calculations client-side
- Complex if variation pricing varies

**Implementation Steps:**
1. **Pre-load rates** - Add PHP endpoint to return all available exchange rates as JSON
2. **Localize script** - Use `wp_localize_script()` to pass rates and product data to JS
3. **DOM traversal** - Update all `.vcc-converted-price` elements with new calculation
4. **Dispatch event** - Trigger custom `vcc_currency_changed` event for external plugins
5. **Handle edge cases:**
   - Products with markup
   - Variations with different prices
   - Source currency matching (EUR→EUR shows original)

**JavaScript Structure:**
```javascript
var VCC = {
    // Cached rates loaded on page
    rates: window.vccData.rates || {},
    markupRules: window.vccData.markupRules || {},

    changeCurrency: function(newCurrency) {
        // Update DOM
        this.updatePrices(newCurrency);

        // Update session/cookie (AJAX)
        this.updateSession(newCurrency);

        // Dispatch event for external plugins
        $(document).trigger('vcc_currency_changed', [newCurrency]);
    },

    updatePrices: function(currency) {
        $('.vcc-converted-price').each(function() {
            // Calculate new price
            // Update element
        });
    }
};
```

---

#### **Option B: AJAX with Partial Rendering**

**Approach:** Keep session update via AJAX but return HTML fragments instead of reloading

**Advantages:**
- Cleaner separation between frontend and backend
- Ensures calculations always use current state
- Easy to maintain consistency with backend logic
- Handles complex scenarios better

**Disadvantages:**
- Still requires server round-trip (slower than Option A)
- More complex AJAX handling
- Need to identify which elements to replace

**Implementation Steps:**
1. Modify AJAX endpoint to return JSON with updated prices instead of just success/currency
2. Use JavaScript to selectively update DOM elements
3. Keep session update on server
4. Dispatch event after DOM update

**Response Structure:**
```json
{
    "success": true,
    "currency": "EUR",
    "prices": {
        "product_123": "€47.20",
        "product_456": "€85.50"
    },
    "cartItems": {
        "key_1": "€47.20",
        "key_2": "€85.50"
    }
}
```

---

#### **Option C: Hybrid (Frontend Primary, Server Backup)**

**Approach:** Option A for common scenarios, fallback to AJAX if needed

**Advantages:**
- Best performance in most cases
- Handles edge cases better than pure frontend
- Flexible and progressive enhancement

**Disadvantages:**
- More complex to implement and maintain
- Harder to debug user issues
- Higher JS complexity

---

### Recommendation for Issue #1

**Go with Option A (Frontend-Only) for these reasons:**

1. **Performance:** Instant updates with no page reload or server call
2. **User Experience:** Smooth, fast currency switching
3. **WCEPO Compatibility:** Event-driven architecture allows WCEPO to react to changes
4. **Scalability:** Reduces server load, better for high-traffic periods
5. **Mobile-Friendly:** Especially important for vignette buyers (often on-the-go)

**Fallback Strategy:**
- If rate calculation fails on frontend, show GBP price with indicator
- Periodic background refresh of rates (every 60 seconds) to catch stale rates

---

## Issue #2: Auto-Detect Customer Location

### Technical Approach Options

#### **Option A: IP-Based Geolocation (Recommended)**

**API Options:**

**1. MaxMind GeoIP2 (Recommended)**
- Free tier: 20 queries/month
- Paid tier: $0.10 per 1,000 queries (very affordable)
- Accuracy: ~99.99% for country level
- No API key required for free tier (uses user IP via wp_remote_get())

**2. IP-API.com**
- Free tier: 45 requests/minute (rate limited)
- Paid tier: $0.50-1.50 per 1,000 requests
- Accuracy: ~95% country level
- Simple integration

**3. GeoLocation Service (Browser API)**
- User-based geolocation (GPS/WiFi)
- More accurate but requires user permission
- Works only on HTTPS

**Implementation Approach:**
```php
// Server-side: Get customer country from IP
$ip = VCC_Geolocation::get_customer_ip();
$country = VCC_Geolocation::get_country_from_ip($ip);
$currency = VCC_Geolocation::map_country_to_currency($country);

// Send to frontend with session cookie
setcookie('vcc_geolocation_currency', $currency, ...);
```

**Advantages:**
- Works for all customers (no permission needed)
- Accurate at country level
- Can be cached in database per IP
- Works on HTTP and HTTPS
- Works for non-logged-in users

**Disadvantages:**
- Not 100% accurate (can have issues with VPNs)
- Requires external API or GeoIP database
- Slight latency for first-time visitors (can be async)
- May need rate limiting

---

#### **Option B: User Account/Billing Address**

**Implementation:**
```php
// For logged-in users: Get country from billing address
$country = $customer->get_billing_country();
$currency = VCC_Location::map_country_to_currency($country);
```

**Advantages:**
- 100% accurate (user provided)
- No external API needed
- Works offline

**Disadvantages:**
- Only for logged-in users
- Not every user completes billing address before browsing
- Can't auto-detect for guests or partial registrations

---

#### **Option C: Browser Geolocation API**

**Implementation:**
```javascript
// Request user permission (HTTPS only)
navigator.geolocation.getCurrentPosition(function(position) {
    // Send coordinates to backend for reverse geocoding
});
```

**Advantages:**
- Very accurate (GPS/WiFi level)
- No API calls needed from server (client-side)
- Works with Option A

**Disadvantages:**
- Requires user permission (intrusive)
- HTTPS only
- Not all browsers support
- Users often deny permission
- Privacy concerns

---

#### **Option D: Combination (Recommended)**

**Priority:**
1. **Session Memory** - Check if user already selected currency (don't override)
2. **Billing Address** - For logged-in users with billing country set
3. **IP Geolocation** - For all users (with fallback)
4. **Browser Geolocation** - Optional enhanced accuracy if user opts in
5. **Default** - GBP if all else fails

**Flow Diagram:**
```
User visits site
    ↓
Check session (already selected?)
    ├─ YES → Use selected currency (don't override)
    ├─ NO → Proceed to auto-detect
    ↓
Is user logged in AND has billing country?
    ├─ YES → Use billing country
    ├─ NO → Proceed to IP check
    ↓
Perform IP geolocation lookup
    ├─ Success → Get country & map to currency
    ├─ Fail → Default to GBP
    ↓
Set currency cookie + dispatch event
```

---

### Geolocation Data Structure

**Currency Mapping Table:**
```php
private $country_to_currency = array(
    'CH' => 'CHF', // Switzerland
    'AT' => 'EUR', // Austria
    'DE' => 'EUR', // Germany
    'FR' => 'EUR', // France
    'ES' => 'EUR', // Spain
    'IT' => 'EUR', // Italy
    'GB' => 'GBP', // United Kingdom
    'IE' => 'GBP', // Ireland
    'US' => 'USD', // United States
    'CA' => 'CAD', // Canada
    'AU' => 'AUD', // Australia
    'JP' => 'JPY', // Japan
    // ... more countries
);
```

**Admin Setting:**
```php
// New setting in vcc_settings:
'enable_auto_detection' => 'yes',
'auto_detection_method' => 'ip', // or 'billing_address' or 'hybrid'
'override_user_selection' => 'no', // Don't override if user already selected
'geolocation_api_provider' => 'maxmind', // or 'ip-api', etc.
'geolocation_api_key' => '',
```

---

### Recommendation for Issue #2

**Go with Option D (Combination) with this priority:**

1. Use session first (don't force auto-detection if user already selected)
2. Billing address for logged-in users
3. IP geolocation as primary auto-detection
4. Fall back to GBP

**API Provider:** MaxMind GeoIP2 (free tier sufficient for most sites)

---

## Issue #3: Add Setting to Hide Selector When Auto-Detection Enabled

### Implementation Requirements

**Admin Setting:**
```php
// In VCC_Admin_Settings class
'show_selector_with_auto_detection' => 'yes', // Show selector even with auto-detect enabled
```

**When to Hide:**
- Auto-detection is enabled (`enable_auto_detection` = 'yes')
- AND `show_selector_with_auto_detection` = 'no'
- Then: Don't display currency selector HTML

**Current Selector Display Logic:**
```php
// In VCC_Frontend_Display class
private function render_selector() {
    // ... renders dropdown
}
```

**Modified Logic:**
```php
private function should_show_selector() {
    // Check if selector is enabled at all
    if (!$this->is_currency_selector_enabled()) {
        return false;
    }

    // Check if auto-detection is enabled and hiding selector is enabled
    $auto_detection = $this->settings['enable_auto_detection'] ?? 'no';
    $show_with_auto_detect = $this->settings['show_selector_with_auto_detection'] ?? 'yes';

    if ($auto_detection === 'yes' && $show_with_auto_detect === 'no') {
        return false;
    }

    return true;
}
```

**Use Cases:**
- **Case 1 (Default):** Auto-detect + show selector = User can see detected currency, but also manually select other currencies
- **Case 2 (Simplified UX):** Auto-detect + hide selector = User gets auto-detected currency, power users can enable selector via shortcode or override cookie

---

## WCEPO Integration Requirements

### Current WCEPO Integration Points

**Methods WCEPO Calls:**
1. `VCC_Frontend_Display::get_selected_currency()` - Get current currency
2. `VCC_Currency_Converter::convert_from_gbp()` - Convert prices to display currency
3. `VCC_Currency_Converter::get_currency_symbol()` - Format prices

**WCEPO Needs These Enhancements:**
1. **JavaScript Events** - Alert when currency changes
2. **Public get_exchange_rate()** - Access exchange rates for its own calculations
3. **Consistent Currency State** - Ensure both plugins agree on current currency

### Proposed WCEPO Compatibility Changes

#### **1. Add JavaScript Event System**

**Current (None):**
```javascript
// WCEPO has no way to know when currency changes
```

**Proposed:**
```javascript
// Dispatch native DOM event when currency changes
document.addEventListener('vcc_currency_changed', function(event) {
    console.log('New currency:', event.detail.currency);
    // WCEPO can listen and update its own state
});

// jQuery event for backward compatibility
$(document).on('vcc_currency_changed', function(e, currency) {
    // Alternative listener
});
```

**Implementation Points:**
- After session update (AJAX response)
- After DOM price updates (Option A)
- In `ajax_change_currency()` method

---

#### **2. Expose Public get_exchange_rate() Method**

**Current (Private):**
```php
public function get_exchange_rate($from, $to) { /* ... */ }
```

**Proposed - Add Public Wrapper:**
```php
class VCC_Currency_API {
    public function get_exchange_rate($from, $to) {
        // Existing implementation
    }

    // New public method for external plugins
    public function get_public_exchange_rate($from, $to) {
        // Validate parameters
        // Return rate with transient cache
        return $this->get_exchange_rate($from, $to);
    }
}

// Usage by WCEPO
$api = VCC_Currency_API::get_instance();
$rate = $api->get_public_exchange_rate('GBP', 'EUR');
```

**Alternative - REST API Endpoint:**
```php
// Add REST endpoint for WCEPO
add_action('rest_api_init', function() {
    register_rest_route('vcc/v1', '/exchange-rate', array(
        'methods' => 'GET',
        'callback' => array($this, 'rest_get_exchange_rate'),
        'permission_callback' => '__return_true', // Public read-only
    ));
});
```

---

#### **3. Ensure Currency State Consistency**

**Problem:** Both plugins might have different currency selected if not synchronized

**Solution:**
```php
// Create getter that both plugins use
class VCC_Currency_State {
    public static function get_current_currency() {
        // Single source of truth
        if (!session_id()) session_start();

        return isset($_SESSION['vcc_selected_currency'])
            ? $_SESSION['vcc_selected_currency']
            : 'GBP';
    }

    public static function set_current_currency($currency) {
        if (!session_id()) session_start();
        $_SESSION['vcc_selected_currency'] = $currency;
    }
}

// Both VCC and WCEPO use this
$currency = VCC_Currency_State::get_current_currency();
```

---

#### **4. Add Filter Hooks for WCEPO Integration**

**Before Currency Change:**
```php
$currency = apply_filters('vcc_before_currency_change', $new_currency);
```

**After Currency Change:**
```php
do_action('vcc_after_currency_change', $new_currency, $old_currency);
```

**Before Price Conversion:**
```php
$converted = apply_filters('vcc_before_price_conversion', $converted_price, $original_price, $from_currency, $to_currency);
```

---

### WCEPO Compatibility Checklist

- [ ] JavaScript events dispatched when currency changes
- [ ] `get_exchange_rate()` method exposed publicly
- [ ] Consistent currency session state
- [ ] Filter hooks for currency change events
- [ ] Documentation of integration points
- [ ] Testing with WCEPO to verify no conflicts
- [ ] Version compatibility noted

---

## Implementation Roadmap

### Phase 1: Groundwork (Foundation)

**Files to Modify:**
- `includes/class-currency-api.php` - Expose public get_exchange_rate()
- `includes/class-currency-converter.php` - Add event hooks and filters
- `includes/class-frontend-display.php` - Add currency state wrapper

**Tasks:**
1. Create `VCC_Currency_State` class
2. Add filter/action hooks
3. Expose `get_public_exchange_rate()`
4. Add documentation for WCEPO integration

**Time Estimate:** 2-3 hours

---

### Phase 2: Issue #1 - JavaScript Price Updates

**Files to Create/Modify:**
- `assets/js/currency-selector.js` - Rewrite with event system
- `includes/class-currency-converter.php` - Add AJAX endpoint for rate pre-loading
- `includes/class-frontend-display.php` - Add localized script data

**Tasks:**
1. Create rate pre-loader endpoint
2. Rewrite currency-selector.js (Option A approach)
3. Add custom event dispatching
4. Handle edge cases (markup, variations, source currency)
5. Update session/cookie via AJAX (async, non-blocking)

**Time Estimate:** 6-8 hours

---

### Phase 3: Issue #2 - Auto-Detection

**Files to Create/Modify:**
- New: `includes/class-geolocation.php` - Create geolocation helper
- `includes/class-admin-settings.php` - Add auto-detection settings
- `includes/class-frontend-display.php` - Initialize auto-detection
- `vignette-currency-converter.php` - Enqueue geolocation script

**Tasks:**
1. Implement geolocation helper (IP-based)
2. Create country-to-currency mapping
3. Add admin settings for geolocation
4. Initialize auto-detection on page load (hook into wp_enqueue_scripts)
5. Handle user override (don't override if session already set)
6. Add unit tests

**Time Estimate:** 5-6 hours

---

### Phase 4: Issue #3 - Hide Selector Setting

**Files to Modify:**
- `includes/class-admin-settings.php` - Add setting checkbox
- `includes/class-frontend-display.php` - Modify render_selector() logic

**Tasks:**
1. Add checkbox setting to admin page
2. Modify `should_show_selector()` logic
3. Test with various combinations
4. Update documentation

**Time Estimate:** 1-2 hours

---

### Phase 5: Testing & Documentation

**Tasks:**
1. Unit tests for each component
2. Integration tests with WCEPO
3. E2E testing (user flows)
4. Update README.md and claude.md
5. Add code comments for WCEPO integration points
6. Create integration guide for WCEPO developers

**Time Estimate:** 4-5 hours

---

### Phase 6: Release

**Tasks:**
1. Bump version to 1.1.0
2. Update CHANGELOG
3. Create release notes
4. Commit and push
5. Create GitHub release

**Time Estimate:** 1 hour

---

## Questions That Need Answers

### Business/Product Questions

1. **Auto-Detection Preference:**
   - What's the primary use case - simplify UX for casual users or keep flexibility?
   - Should auto-detection be enabled by default or opt-in?

2. **Geolocation Accuracy Requirements:**
   - Is country-level accuracy sufficient or need city-level?
   - Acceptable error rate? (98% vs 99.9%)

3. **Privacy Considerations:**
   - Should there be a notice about IP geolocation?
   - Privacy policy implications?

4. **VPN/Proxy Handling:**
   - If user is on VPN from UK but travels to Germany, which currency?
   - Any special handling needed?

### Technical Questions

5. **MaxMind API Cost:**
   - Budget for paid tier if free tier insufficient?
   - Or use open-source GeoIP database instead?

6. **Exchange Rate Caching:**
   - Keep 12-hour cache or make shorter for Option A (frontend updates)?
   - Need to update rates more frequently with auto-detection?

7. **Variation Pricing Complexity:**
   - Are variation prices/markups complex enough to require server-side calculation?
   - Or is frontend-only calculation sufficient for accuracy?

8. **Mobile App Consideration:**
   - Will this plugin be used by any mobile apps that might call the API?
   - Need to document API contract?

### WCEPO Integration Questions

9. **Conflict Resolution:**
   - If WCEPO also has currency selection, how should conflicts be handled?
   - Should one plugin take precedence?

10. **Rate Exposure:**
    - Is it acceptable to expose exchange rates via public API endpoint (REST)?
    - Any security concerns?

11. **Event Format:**
    - Preference for native DOM events vs jQuery events vs both?

12. **Backward Compatibility:**
    - Need to maintain compatibility with older WCEPO versions?
    - Version requirements?

---

## Potential Integration Concerns with WCEPO

### 1. Currency State Mismatch

**Problem:** VCC and WCEPO might have different selected currency

**Impact:** Prices shown incorrectly if both plugins interpret currency differently

**Mitigation:**
- Use shared session state via `VCC_Currency_State` class
- Dispatch events when currency changes
- Document the expected behavior

---

### 2. Double Conversion Risk

**Problem:** WCEPO might convert prices that are already converted by VCC

**Example:**
- Product: GBP £40
- VCC converts: GBP £40 → EUR €47
- WCEPO converts again: EUR €47 → USD $51 (WRONG!)

**Mitigation:**
- Add filter hook before WCEPO conversion
- Pass metadata about already-converted prices
- Clear documentation on conversion flow

**Recommended Filter:**
```php
$converted = apply_filters('vcc_before_price_conversion',
    $price,  // The price to convert
    array(
        'source_price' => $source_price,
        'source_currency' => $source_currency,
        'is_vcc_converted' => true,
    )
);
```

---

### 3. API Call Overhead

**Problem:** Two plugins making independent API calls for same rates

**Impact:** Wasted API quota, slower performance

**Mitigation:**
- WCEPO should use VCC's exposed `get_public_exchange_rate()` instead of its own API
- Leverage VCC's caching (12 hours)
- Reduce total API calls significantly

---

### 4. Event Ordering Issues

**Problem:** Multiple event listeners might fire in unexpected order

**Example:**
- VCC changes currency → fires event
- WCEPO listener fires and changes something else
- Other WCEPO logic expects original state

**Mitigation:**
- Use WordPress action hooks with priority system
- Document event order expectations
- Use `do_action()` for sequential operations
- Provide both pre and post event hooks

---

### 5. Session Lifecycle Conflicts

**Problem:** Both plugins manipulating session might cause issues

**Example:**
- Session expires mid-checkout
- Both plugins try to refresh currency
- Conflicting state updates

**Mitigation:**
- Use `VCC_Currency_State` wrapper class
- Document session key usage
- Add transactional updates if needed

---

### 6. Browser Cache / Stale Prices

**Problem:** With Option A (frontend-only), prices might become stale if rates change

**Impact:** During high-volatility periods, customer sees outdated prices

**Mitigation:**
- Implement periodic rate refresh (60-second interval)
- Show warning icon if rates are older than threshold
- Provide manual refresh button
- Document the limitation

---

## Recommended Final Approach

### Summary of Recommendations

| Issue | Recommended Approach | Rationale |
|-------|----------------------|-----------|
| **#1: Page Reload** | **Option A (Frontend-Only)** | Best UX, minimal server load, event-driven for WCEPO |
| **#2: Auto-Detection** | **Option D (Hybrid)** | Priority: Session → Billing → IP → Default |
| **#3: Hide Selector** | **Standard Checkbox** | Simple, admin-configured, paired with auto-detection |
| **WCEPO Integration** | **Event-Driven + Public API** | Loosely coupled, extensible, future-proof |

---

### Implementation Priority

**Must Have (MVP):**
1. JavaScript event system (for WCEPO compatibility)
2. Frontend price updates (Issue #1)
3. Admin setting to show/hide selector (Issue #3)
4. IP-based auto-detection (Issue #2 - basic)

**Should Have (Phase 2):**
1. Billing address-based detection
2. Periodic rate refresh mechanism
3. Public exchange rate API
4. Comprehensive WCEPO integration guide

**Nice to Have (Future):**
1. Browser geolocation API option
2. Analytics dashboard for auto-detection effectiveness
3. Detailed logging for debugging
4. A/B testing auto-detection vs selector-only

---

## Risk Assessment

| Risk | Probability | Impact | Mitigation |
|------|-------------|--------|-----------|
| Frontend price calculation errors | Medium | High | Thorough unit tests, server-side validation |
| Geolocation API unreliable | Medium | Medium | Fallback to GBP, use multiple providers |
| WCEPO conflicts | Low | High | Event hooks, shared state, documentation |
| Performance degradation | Low | Medium | Rate limiting, caching, async operations |
| User confusion with auto-detection | Medium | Low | Clear UI messaging, easy override |

---

## Conclusion

This implementation plan provides a clear roadmap for addressing the three currency selector issues while maintaining and enhancing WCEPO integration. The recommended approaches prioritize:

1. **User Experience** - Fast, smooth currency switching without page reloads
2. **Developer Integration** - Event-driven architecture for extensibility
3. **Reliability** - Graceful fallbacks and clear error handling
4. **Performance** - Minimal server load, smart caching

The phased approach allows incremental development and testing, with MVP features delivering immediate value while laying groundwork for future enhancements.

---

**Next Steps:**
1. Clarify answers to questions listed above
2. Review approach with WCEPO team if possible
3. Set up development environment
4. Begin Phase 1 implementation (Groundwork)


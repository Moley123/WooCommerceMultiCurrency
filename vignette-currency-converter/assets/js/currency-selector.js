/**
 * Vignette Currency Converter - Frontend JavaScript
 * Version 1.1.0 - Instant currency switching without page reload
 */

(function($) {
    'use strict';

    var VCC = {
        currentCurrency: null,
        previousCurrency: null,
        rates: {},
        symbols: {},
        products: {},

        /**
         * Initialize the currency converter
         */
        init: function() {
            // Load data from localized script
            if (typeof vccData !== 'undefined') {
                this.currentCurrency = vccData.current_currency || 'GBP';
                this.previousCurrency = this.currentCurrency;
                this.rates = vccData.rates || {};
                this.symbols = vccData.symbols || {};
                this.products = vccData.products || {};
            }

            this.bindEvents();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Handle currency selector change
            $(document).on('change', '#vcc-currency-selector', this.handleCurrencyChange.bind(this));
        },

        /**
         * Handle currency change event
         */
        handleCurrencyChange: function(e) {
            e.preventDefault();

            var $selector = $(e.currentTarget);
            var currency = $selector.val();

            if (currency === this.currentCurrency) {
                return; // No change
            }

            this.previousCurrency = this.currentCurrency;
            this.currentCurrency = currency;

            // Update all prices on page instantly
            this.updateAllPrices(currency);

            // Update session/cookie asynchronously (non-blocking)
            this.updateSession(currency);

            // Dispatch events for external plugins (WCEPO)
            this.dispatchCurrencyChangeEvent(currency);
        },

        /**
         * Update all prices on the page
         */
        updateAllPrices: function(currency) {
            var self = this;

            // Update all converted price elements
            $('.vcc-converted-price').each(function() {
                var $element = $(this);
                self.updatePriceElement($element, currency);
            });

            // Update WooCommerce variation prices if on product page
            this.updateVariationPrices(currency);
        },

        /**
         * Update individual price element
         */
        updatePriceElement: function($element, currency) {
            var self = this;
            var productId = $element.data('product-id');
            var variationId = $element.data('variation-id');
            var sourceCurrency = $element.data('source-currency');
            var sourcePrice = parseFloat($element.data('source-price'));
            var gbpPrice = parseFloat($element.data('gbp-price'));

            // Get effective product ID (variation or simple product)
            var effectiveId = variationId || productId;

            // Get product data
            var productData = this.products[effectiveId] || {};

            // Use data attributes if available, otherwise fall back to product data
            if (!sourceCurrency && productData.source_currency) {
                sourceCurrency = productData.source_currency;
            }
            if (isNaN(sourcePrice) && productData.source_price) {
                sourcePrice = parseFloat(productData.source_price);
            }
            if (isNaN(gbpPrice) && productData.gbp_price) {
                gbpPrice = parseFloat(productData.gbp_price);
            }

            var newPrice, formattedPrice;

            // Check if selected currency matches source currency
            if (sourceCurrency && sourceCurrency === currency && !isNaN(sourcePrice)) {
                // Show original source price (with markup already applied)
                var sourcePriceWithMarkup = productData.source_price_with_markup || sourcePrice;
                newPrice = sourcePriceWithMarkup;
                formattedPrice = this.formatPrice(newPrice, currency);
            } else if (currency === 'GBP' && !isNaN(gbpPrice)) {
                // Show GBP price directly
                newPrice = gbpPrice;
                formattedPrice = this.formatPrice(newPrice, currency);
            } else if (!isNaN(gbpPrice) && this.rates[currency]) {
                // Convert from GBP to selected currency
                newPrice = gbpPrice * this.rates[currency];
                formattedPrice = this.formatPrice(newPrice, currency);
            } else {
                // Fallback - keep existing price
                return;
            }

            // Update the element's text
            $element.html(formattedPrice);

            // Store updated price in data attribute for future reference
            $element.data('current-price', newPrice);
            $element.data('current-currency', currency);
        },

        /**
         * Update WooCommerce variation prices
         */
        updateVariationPrices: function(currency) {
            var self = this;

            // Update variation price display
            $('.woocommerce-variation-price .price').each(function() {
                var $priceElement = $(this).find('.vcc-converted-price');
                if ($priceElement.length) {
                    self.updatePriceElement($priceElement, currency);
                }
            });

            // Update price range display for variable products
            $('.woocommerce-variation-add-to-cart .price').each(function() {
                var $priceElement = $(this).find('.vcc-converted-price');
                if ($priceElement.length) {
                    self.updatePriceElement($priceElement, currency);
                }
            });
        },

        /**
         * Format price with currency symbol
         */
        formatPrice: function(amount, currency) {
            var symbol = this.symbols[currency] || currency;
            var formatted = amount.toFixed(2);

            // Format based on currency position (symbol before or after)
            if (currency === 'EUR') {
                return symbol + formatted;
            } else if (currency === 'USD' || currency === 'GBP' || currency === 'CAD' || currency === 'AUD') {
                return symbol + formatted;
            } else if (currency === 'CHF') {
                return formatted + ' ' + symbol;
            } else if (currency === 'JPY') {
                return symbol + Math.round(amount); // No decimals for JPY
            }

            // Default format
            return symbol + formatted;
        },

        /**
         * Update session/cookie asynchronously
         */
        updateSession: function(currency) {
            // Update session via AJAX (non-blocking, fire-and-forget)
            $.ajax({
                url: vccData.ajax_url,
                type: 'POST',
                data: {
                    action: 'vcc_change_currency',
                    currency: currency,
                    nonce: vccData.nonce,
                    manual_override: true // Mark as manual user selection
                },
                success: function(response) {
                    if (!response.success) {
                        console.warn('VCC: Failed to update session', response);
                    }
                },
                error: function() {
                    console.warn('VCC: Session update request failed');
                }
            });
        },

        /**
         * Dispatch currency change event (dual format for compatibility)
         */
        dispatchCurrencyChangeEvent: function(currency) {
            var eventData = {
                currency: currency,
                previousCurrency: this.previousCurrency,
                rates: this.rates,
                symbols: this.symbols
            };

            // jQuery event (for jQuery-based plugins like WCEPO)
            $(document).trigger('vcc_currency_changed', [eventData]);

            // Native DOM event (for modern JavaScript)
            if (typeof CustomEvent !== 'undefined') {
                var event = new CustomEvent('vcc_currency_changed', {
                    detail: eventData,
                    bubbles: true,
                    cancelable: true
                });
                document.dispatchEvent(event);
            }

            // Console log for debugging
            if (vccData.debug) {
                console.log('VCC: Currency changed', eventData);
            }
        },

        /**
         * Get current currency
         */
        getCurrentCurrency: function() {
            return this.currentCurrency;
        },

        /**
         * Get exchange rate
         */
        getExchangeRate: function(from, to) {
            if (from === to) {
                return 1.0;
            }

            // Convert via GBP if direct rate not available
            if (from === 'GBP' && this.rates[to]) {
                return this.rates[to];
            }

            if (to === 'GBP' && this.rates[from]) {
                return 1.0 / this.rates[from];
            }

            // Convert via GBP: from -> GBP -> to
            if (this.rates[from] && this.rates[to]) {
                var gbpToFrom = 1.0 / this.rates[from];
                var gbpToTo = this.rates[to];
                return gbpToFrom * gbpToTo;
            }

            return null;
        },

        /**
         * Convert amount between currencies
         */
        convertAmount: function(amount, from, to) {
            var rate = this.getExchangeRate(from, to);
            if (rate === null) {
                return null;
            }
            return amount * rate;
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        VCC.init();

        // Expose VCC object globally for external plugins
        window.VCC = VCC;
    });

    // Also expose via jQuery
    $.VCC = VCC;

})(jQuery);

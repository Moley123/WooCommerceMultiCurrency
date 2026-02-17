/**
 * Vignette Currency Converter - Frontend JavaScript
 * Version 1.4.0 - Currency popup selector, Stripe-aware notices
 */

(function($) {
    'use strict';

    var VCC = {
        currentCurrency: null,
        previousCurrency: null,
        rates: {},
        symbols: {},
        products: {},
        debug: false,
        _observerTimeout: null,
        priceObserver: null,

        /**
         * Initialize the currency converter
         */
        init: function() {
            if (typeof vccData !== 'undefined') {
                this.currentCurrency = vccData.current_currency || 'GBP';
                this.previousCurrency = this.currentCurrency;
                this.rates = vccData.rates || {};
                this.symbols = vccData.symbols || {};
                this.products = vccData.products || {};
                this.debug = vccData.debug || false;
            }

            this.bindEvents();
            this.initMutationObserver();
        },

        /**
         * Bind event handlers for popup trigger, popup options, and close controls
         */
        bindEvents: function() {
            var self = this;

            // Open popup when any trigger is clicked
            $(document).on('click keydown', '[data-vcc-trigger]', function(e) {
                if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
                e.preventDefault();
                self.openPopup();
            });

            // Close popup — × button
            $(document).on('click', '.vcc-popup-close', function() {
                self.closePopup();
            });

            // Close popup — clicking the overlay backdrop
            $(document).on('click', '#vcc-popup-overlay', function(e) {
                if ($(e.target).is('#vcc-popup-overlay')) {
                    self.closePopup();
                }
            });

            // Close popup — Escape key
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') self.closePopup();
            });

            // Select a currency from the popup
            $(document).on('click keydown', '.vcc-popup-option', function(e) {
                if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
                e.preventDefault();
                var currency = $(this).data('currency');
                if (currency) self.selectCurrency(currency);
            });
        },

        /**
         * Open the currency selection popup
         */
        openPopup: function() {
            $('#vcc-popup-overlay').fadeIn(150);
            $('body').addClass('vcc-popup-open');
        },

        /**
         * Close the currency selection popup
         */
        closePopup: function() {
            $('#vcc-popup-overlay').fadeOut(150);
            $('body').removeClass('vcc-popup-open');
        },

        /**
         * Handle a currency selection from the popup
         */
        selectCurrency: function(currency) {
            if (currency === this.currentCurrency) {
                this.closePopup();
                return;
            }

            this.closePopup();
            this.previousCurrency = this.currentCurrency;
            this.currentCurrency = currency;

            // Update popup selected state
            $('.vcc-popup-option').removeClass('vcc-selected').attr('aria-selected', 'false');
            $('.vcc-popup-option[data-currency="' + currency + '"]')
                .addClass('vcc-selected').attr('aria-selected', 'true');

            // Update all trigger text instances
            this.updateTriggerText(currency);

            // Clear WCEPO processed markers so prices get re-updated
            $('[data-vcc-processed-currency]').removeData('vcc-processed-currency');

            // Update all prices on page instantly
            this.updateAllPrices(currency);

            // Update session/cookie asynchronously (non-blocking)
            this.updateSession(currency);

            // Dispatch events for external plugins (WCEPO)
            this.dispatchCurrencyChangeEvent(currency);
        },

        /**
         * Update the text shown on all trigger instances
         */
        updateTriggerText: function(currency) {
            var symbol = this.symbols[currency] || currency;
            var text   = symbol + ' ' + currency;
            $('[data-vcc-trigger]').text(text).attr('data-current-currency', currency);
        },

        /**
         * Watch for WCEPO DOM mutations and re-apply currency conversion
         * WCEPO replaces price HTML when product options change - we catch that here
         */
        initMutationObserver: function() {
            if (typeof MutationObserver === 'undefined') {
                return;
            }

            var self = this;
            var targets = document.querySelectorAll('.summary .price, .wcepo-price-wrapper, .product-info .price, .entry-summary .price');

            if (targets.length === 0) {
                return;
            }

            this.priceObserver = new MutationObserver(function() {
                clearTimeout(self._observerTimeout);
                self._observerTimeout = setTimeout(function() {
                    if (self.currentCurrency && self.currentCurrency !== 'GBP') {
                        $('[data-vcc-processed-currency]').removeData('vcc-processed-currency');
                        self.applyWCEPOFallback(self.currentCurrency);
                    }
                }, 50);
            });

            targets.forEach(function(target) {
                self.priceObserver.observe(target, {
                    childList: true,
                    subtree: true,
                    characterData: true
                });
            });
        },

        /**
         * Update all prices on the page
         * Two-pass: our wrapped elements first, then WCEPO fallback always
         */
        updateAllPrices: function(currency) {
            var self = this;
            var debug = this.debug;

            if (debug) {
                console.log('[VCC Debug] updateAllPrices called with currency:', currency);
            }

            // Pass 1: Update elements with our vcc-converted-price wrapper
            var $elements = $('.vcc-converted-price');

            if (debug) {
                console.log('[VCC Debug] .vcc-converted-price elements found:', $elements.length);
            }

            $elements.each(function() {
                self.updatePriceElement($(this), currency);
            });

            // Pass 2: Always run WCEPO fallback (handles prices WCEPO has overwritten)
            this.applyWCEPOFallback(currency);

            // Pass 3: Variation prices
            this.updateVariationPrices(currency);
        },

        /**
         * WCEPO fallback - targets price elements not wrapped by our class
         * Runs on every currency change, not just when our elements are missing
         */
        applyWCEPOFallback: function(currency) {
            var self = this;
            var debug = this.debug;

            var selectors = [
                '.wcepo-price-wrapper .woocommerce-Price-amount',
                '.summary .price .woocommerce-Price-amount',
                '.entry-summary .price .woocommerce-Price-amount'
            ].join(', ');

            $(selectors).each(function() {
                var $element = $(this);

                // Skip if already wrapped by our class
                if ($element.closest('.vcc-converted-price').length) {
                    return;
                }

                // Skip if already processed for this currency in this cycle
                if ($element.data('vcc-processed-currency') === currency) {
                    return;
                }

                if (debug) {
                    console.log('[VCC Debug] WCEPO fallback updating element:', this);
                }

                self.wrapAndUpdateWCEPOPrice($element, currency);
                $element.data('vcc-processed-currency', currency);
            });
        },

        /**
         * Update individual price element (has our vcc-converted-price class)
         */
        updatePriceElement: function($element, currency) {
            var productId = $element.data('product-id');
            var variationId = $element.data('variation-id');
            var sourceCurrency = $element.data('source-currency');
            var sourcePrice = parseFloat($element.data('source-price'));
            var gbpPrice = parseFloat($element.data('gbp-price'));

            var effectiveId = variationId || productId;
            var productData = this.products[effectiveId] || {};

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

            if (sourceCurrency && sourceCurrency === currency && !isNaN(sourcePrice)) {
                var sourcePriceWithMarkup = productData.source_price_with_markup || sourcePrice;
                newPrice = sourcePriceWithMarkup;
                formattedPrice = this.formatPrice(newPrice, currency);
            } else if (currency === 'GBP' && !isNaN(gbpPrice)) {
                newPrice = gbpPrice;
                formattedPrice = this.formatPrice(newPrice, currency);
            } else if (!isNaN(gbpPrice) && this.rates[currency]) {
                newPrice = gbpPrice * this.rates[currency];
                formattedPrice = this.formatPrice(newPrice, currency);
            } else {
                return;
            }

            $element.html(formattedPrice);
            $element.data('current-price', newPrice);
            $element.data('current-currency', currency);
        },

        /**
         * Update WooCommerce variation prices
         */
        updateVariationPrices: function(currency) {
            var self = this;

            $('.woocommerce-variation-price .price').each(function() {
                var $priceElement = $(this).find('.vcc-converted-price');
                if ($priceElement.length) {
                    self.updatePriceElement($priceElement, currency);
                }
            });

            $('.woocommerce-variation-add-to-cart .price').each(function() {
                var $priceElement = $(this).find('.vcc-converted-price');
                if ($priceElement.length) {
                    self.updatePriceElement($priceElement, currency);
                }
            });
        },

        /**
         * Update a WCEPO price element that doesn't have our wrapper class.
         * WCEPO renders: <span class="woocommerce-Price-amount"><bdi>£29.95</bdi></span>
         * We calculate the correct price and update the <bdi> content.
         */
        wrapAndUpdateWCEPOPrice: function($priceElement, currency) {
            var debug = this.debug;

            var productId = null;
            if (typeof this.products === 'object' && Object.keys(this.products).length > 0) {
                productId = Object.keys(this.products)[0];
            }

            if (!productId || !this.products[productId]) {
                if (debug) {
                    console.warn('[VCC] No product data for WCEPO fallback');
                }
                return;
            }

            var productData = this.products[productId];
            var gbpPrice = productData.gbp_price;
            var sourceCurrency = productData.source_currency;
            var sourcePrice = productData.source_price;

            var newPrice, formattedPrice;

            if (sourceCurrency && sourceCurrency === currency && sourcePrice) {
                newPrice = productData.source_price_with_markup || sourcePrice;
            } else if (currency === 'GBP') {
                newPrice = gbpPrice;
            } else if (this.rates[currency]) {
                newPrice = gbpPrice * this.rates[currency];
            } else {
                if (debug) {
                    console.warn('[VCC Debug] No rate available for', currency);
                }
                return;
            }

            formattedPrice = this.formatPrice(newPrice, currency);

            // Update just the <bdi> content to preserve WCEPO's wrapper structure
            var $bdi = $priceElement.find('bdi');
            if ($bdi.length) {
                $bdi.html(formattedPrice);
            } else {
                $priceElement.html(formattedPrice);
            }
        },

        /**
         * Format price with currency symbol.
         * Prefix currencies (symbol before number): GBP, EUR, USD, CAD, AUD
         * Suffix currencies (symbol/code after number): all others
         * Symbols come from vccData.symbols which is populated server-side.
         */
        formatPrice: function(amount, currency) {
            var symbol = this.symbols[currency] || currency;
            var formatted = parseFloat(amount).toFixed(2);

            var prefixCurrencies = ['GBP', 'EUR', 'USD', 'CAD', 'AUD'];
            if (prefixCurrencies.indexOf(currency) !== -1) {
                return symbol + formatted;
            }

            // Suffix: "250.00 CHF", "250.00 NOK", "250.00 zł", etc.
            return formatted + ' ' + symbol;
        },

        /**
         * Update session/cookie asynchronously, then refresh WooCommerce fragments
         * so mini-cart, cart totals, and checkout update to the new currency
         */
        updateSession: function(currency) {
            var self = this;

            $.ajax({
                url: vccData.ajax_url,
                type: 'POST',
                data: {
                    action: 'vcc_change_currency',
                    currency: currency,
                    nonce: vccData.nonce,
                    manual_override: true
                },
                success: function(response) {
                    if (response.success) {
                        // Refresh mini-cart (WooCommerce fragments)
                        $(document.body).trigger('wc_fragment_refresh');

                        // Refresh checkout order review table
                        if (typeof vccData !== 'undefined' && vccData.is_checkout) {
                            $(document.body).trigger('update_checkout');
                        }

                        // Refresh cart totals on cart page
                        if (typeof vccData !== 'undefined' && vccData.is_cart) {
                            $('[name="update_cart"]').prop('disabled', false).trigger('click');
                        }
                    } else {
                        console.warn('VCC: Failed to update session', response);
                    }
                },
                error: function() {
                    console.warn('VCC: Session update request failed');
                }
            });
        },

        /**
         * Dispatch currency change event (jQuery + Native DOM for WCEPO compatibility)
         */
        dispatchCurrencyChangeEvent: function(currency) {
            var eventData = {
                currency: currency,
                previousCurrency: this.previousCurrency,
                rates: this.rates,
                symbols: this.symbols
            };

            $(document).trigger('vcc_currency_changed', [eventData]);

            if (typeof CustomEvent !== 'undefined') {
                document.dispatchEvent(new CustomEvent('vcc_currency_changed', {
                    detail: eventData,
                    bubbles: true,
                    cancelable: true
                }));
            }

            if (this.debug) {
                console.log('VCC: Currency changed', eventData);
            }
        },

        /**
         * Public API for external plugins
         */
        getCurrentCurrency: function() {
            return this.currentCurrency;
        },

        getExchangeRate: function(from, to) {
            if (from === to) return 1.0;
            if (from === 'GBP' && this.rates[to]) return this.rates[to];
            if (to === 'GBP' && this.rates[from]) return 1.0 / this.rates[from];
            if (this.rates[from] && this.rates[to]) return (1.0 / this.rates[from]) * this.rates[to];
            return null;
        },

        convertAmount: function(amount, from, to) {
            var rate = this.getExchangeRate(from, to);
            return rate !== null ? amount * rate : null;
        }
    };

    $(document).ready(function() {
        VCC.init();
        window.VCC = VCC;
    });

    $.VCC = VCC;

})(jQuery);

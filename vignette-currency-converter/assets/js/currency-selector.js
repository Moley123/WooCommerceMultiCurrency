/**
 * Vignette Currency Converter - Frontend JavaScript
 */

(function($) {
    'use strict';

    var VCC = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Handle currency selector change
            $(document).on('change', '#vcc-currency-selector', this.handleCurrencyChange);
        },

        handleCurrencyChange: function(e) {
            e.preventDefault();

            var $selector = $(this);
            var $wrapper = $selector.closest('.vcc-currency-selector-wrapper');
            var $loading = $wrapper.find('.vcc-loading');
            var currency = $selector.val();

            // Show loading indicator
            $selector.prop('disabled', true);
            $loading.show();

            // Send AJAX request to update currency
            $.ajax({
                url: vccData.ajax_url,
                type: 'POST',
                data: {
                    action: 'vcc_change_currency',
                    currency: currency,
                    nonce: vccData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Reload page to update all prices
                        window.location.reload();
                    } else {
                        alert(response.data.message || 'Error updating currency');
                        $selector.prop('disabled', false);
                        $loading.hide();
                    }
                },
                error: function() {
                    alert('Error updating currency. Please try again.');
                    $selector.prop('disabled', false);
                    $loading.hide();
                }
            });
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        VCC.init();
    });

})(jQuery);

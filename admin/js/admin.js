/**
 * Printify SureCart Sync Admin JavaScript
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Handle product sync button click
        $('#printify-surecart-sync-button').on('click', function() {
            // Show status and hide previous results
            $('#printify-surecart-sync-status').show();
            $('#printify-surecart-sync-results').hide();
            
            // Disable the button during sync
            $(this).prop('disabled', true);
            
            // Make AJAX request
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'printify_surecart_sync_products',
                    nonce: printifySureCartSync.nonce
                },
                success: function(response) {
                    // Hide status and show results
                    $('#printify-surecart-sync-status').hide();
                    $('#printify-surecart-sync-results').show();
                    $('#printify-surecart-sync-results-content').html(response);
                    
                    // Re-enable the button
                    $('#printify-surecart-sync-button').prop('disabled', false);
                },
                error: function(xhr, status, error) {
                    // Hide status and show error
                    $('#printify-surecart-sync-status').hide();
                    $('#printify-surecart-sync-results').show();
                    $('#printify-surecart-sync-results-content').html(
                        '<div class="notice notice-error"><p>' + 
                        printifySureCartSync.errorText + 
                        ': ' + error + '</p></div>'
                    );
                    
                    // Re-enable the button
                    $('#printify-surecart-sync-button').prop('disabled', false);
                }
            });
        });
        
        // Handle order sync button click
        $('#sync-single-order').on('click', function() {
            var orderId = $('#order-id-to-sync').val().trim();
            
            if (!orderId) {
                $('#order-sync-result').html(
                    '<div class="notice notice-error"><p>' + 
                    printifySureCartSync.enterOrderIdText + 
                    '</p></div>'
                ).show();
                return;
            }
            
            // Show status and hide previous results
            $('#order-sync-status').show();
            $('#order-sync-result').hide();
            
            // Disable the button during sync
            $(this).prop('disabled', true);
            
            // Make AJAX request
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'printify_surecart_sync_order',
                    nonce: printifySureCartSync.nonce,
                    order_id: orderId
                },
                success: function(response) {
                    // Hide status
                    $('#order-sync-status').hide();
                    
                    // Show results
                    if (response.success) {
                        $('#order-sync-result').html(
                            '<div class="notice notice-success"><p>' + 
                            response.data.message + 
                            '</p></div>'
                        ).show();
                        
                        // Clear the input
                        $('#order-id-to-sync').val('');
                        
                        // Reload the page after a delay to show the updated order list
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $('#order-sync-result').html(
                            '<div class="notice notice-error"><p>' + 
                            response.data.message + 
                            '</p></div>'
                        ).show();
                    }
                    
                    // Re-enable the button
                    $('#sync-single-order').prop('disabled', false);
                },
                error: function(xhr, status, error) {
                    // Hide status and show error
                    $('#order-sync-status').hide();
                    $('#order-sync-result').html(
                        '<div class="notice notice-error"><p>' + 
                        printifySureCartSync.errorText + 
                        ': ' + error + '</p></div>'
                    ).show();
                    
                    // Re-enable the button
                    $('#sync-single-order').prop('disabled', false);
                }
            });
        });
        
        // Toggle API token visibility
        $('#toggle-api-token').on('click', function(e) {
            e.preventDefault();
            
            var $input = $('#printify_surecart_sync_api_token');
            var type = $input.attr('type');
            
            if (type === 'password') {
                $input.attr('type', 'text');
                $(this).text(printifySureCartSync.hideText);
            } else {
                $input.attr('type', 'password');
                $(this).text(printifySureCartSync.showText);
            }
        });
    });
})(jQuery);
/**
 * Printify SureCart Sync Admin JavaScript
 */
(function($) {
    'use strict';
    
    console.log('Printify SureCart Sync admin script loaded');

    $(document).ready(function() {
        console.log('Document ready - initializing Printify SureCart Sync admin');
        
        // Handle product sync button click
        $('#printify-surecart-sync-button').on('click', function(e) {
            e.preventDefault();
            console.log('Regular sync button clicked');
            performSync(false);
        });
        
        // Handle force resync button click
        $('#printify-surecart-force-sync-button').on('click', function(e) {
            e.preventDefault();
            console.log('Force resync button clicked');
            performSync(true);
        });
        
        // Function to perform sync with option to force
        function performSync(forceResync) {
            console.log('performSync called with forceResync =', forceResync);
            
            // Show status and hide previous results
            $('#printify-surecart-sync-status').show();
            $('#printify-surecart-sync-results').hide();
            
            // Hide notice initially (will be shown when sync starts)
            $('#printify-surecart-sync-notice').hide();
            
            // Disable both buttons during sync
            $('#printify-surecart-sync-button').prop('disabled', true);
            $('#printify-surecart-force-sync-button').prop('disabled', true);
            
            // Make AJAX request to start the sync
            console.log('Making AJAX request with force_resync =', forceResync ? 1 : 0);
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'printify_surecart_sync_products',
                    nonce: printifySureCartSync.nonce,
                    force_resync: forceResync ? 1 : 0
                },
                success: function(response) {
                    console.log('AJAX request successful', response);
                    
                    // Check if we have a proper JSON response
                    if (response && response.success && response.data && response.data.html) {
                        $('#printify-surecart-sync-results-content').html(response.data.html);
                        $('#printify-surecart-sync-results').show();
                        
                        // If sync has started in the background, start polling for status
                        if (response.data.status === 'started') {
                            console.log('Sync started, showing notice');
                            
                            // Show the sync notice
                            $('#printify-surecart-sync-notice').show();
                            $('#printify-surecart-sync-status-text').text(printifySureCartSync.startingSyncText);
                            
                            // Start polling for status
                            pollSyncStatus();
                        } else {
                            // Hide status
                            $('#printify-surecart-sync-status').hide();
                            
                            // Re-enable both buttons
                            $('#printify-surecart-sync-button').prop('disabled', false);
                            $('#printify-surecart-force-sync-button').prop('disabled', false);
                        }
                    } else if (response && response.success && response.data && response.data.message) {
                        // Fallback to just showing the message
                        $('#printify-surecart-sync-results-content').html(
                            '<div class="notice notice-success"><p>' + 
                            response.data.message + 
                            '</p></div>'
                        );
                        $('#printify-surecart-sync-results').show();
                        $('#printify-surecart-sync-status').hide();
                        
                        // Re-enable both buttons
                        $('#printify-surecart-sync-button').prop('disabled', false);
                        $('#printify-surecart-force-sync-button').prop('disabled', false);
                    } else {
                        // Handle unexpected response format
                        $('#printify-surecart-sync-results-content').html(
                            '<div class="notice notice-warning"><p>' + 
                            'Received response in unexpected format. Check server logs for details.' + 
                            '</p></div>'
                        );
                        $('#printify-surecart-sync-results').show();
                        $('#printify-surecart-sync-status').hide();
                        
                        console.warn('Unexpected response format:', response);
                        
                        // Re-enable both buttons
                        $('#printify-surecart-sync-button').prop('disabled', false);
                        $('#printify-surecart-force-sync-button').prop('disabled', false);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX request failed:', status, error);
                    console.log('XHR response:', xhr.responseText);
                    console.log('XHR status:', xhr.status);
                    console.log('XHR statusText:', xhr.statusText);
                    
                    // Try to parse the response if possible
                    var errorMessage = error;
                    try {
                        if (xhr.responseText) {
                            var jsonResponse = JSON.parse(xhr.responseText);
                            if (jsonResponse && jsonResponse.data && jsonResponse.data.message) {
                                errorMessage = jsonResponse.data.message;
                            }
                        }
                    } catch (e) {
                        console.log('Could not parse error response as JSON:', e);
                        
                        // If we couldn't parse JSON, try to extract error message from HTML
                        if (xhr.responseText && xhr.responseText.indexOf('<p>') > -1) {
                            var start = xhr.responseText.indexOf('<p>') + 3;
                            var end = xhr.responseText.indexOf('</p>', start);
                            if (end > start) {
                                errorMessage = xhr.responseText.substring(start, end);
                            }
                        }
                    }
                    
                    // Hide status and show error
                    $('#printify-surecart-sync-status').hide();
                    $('#printify-surecart-sync-results').show();
                    $('#printify-surecart-sync-results-content').html(
                        '<div class="notice notice-error"><p>' + 
                        printifySureCartSync.errorText + 
                        ': ' + errorMessage + '</p>' +
                        '<p>Status: ' + xhr.status + ' ' + xhr.statusText + '</p>' +
                        '</div>'
                    );
                    
                    // Re-enable both buttons
                    $('#printify-surecart-sync-button').prop('disabled', false);
                    $('#printify-surecart-force-sync-button').prop('disabled', false);
                }
            });
        }
        
        // Function to poll for sync status
        function pollSyncStatus() {
            console.log('Polling for sync status');
            
            // Make sure notice is visible and status spinner is hidden
            $('#printify-surecart-sync-notice').show();
            $('#printify-surecart-sync-status').hide();
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'printify_surecart_sync_products',
                    nonce: printifySureCartSync.nonce,
                    check_status: 1
                },
                success: function(response) {
                    console.log('Status check successful', response);
                    
                    if (response && response.success && response.data) {
                        // Update the results content
                        if (response.data.html) {
                            $('#printify-surecart-sync-results-content').html(response.data.html);
                        }
                        
                        // Update status text if progress information is available
                        if (response.data.processed !== undefined && response.data.total !== undefined) {
                            var processed = response.data.processed;
                            var total = response.data.total;
                            var statusText = printifySureCartSync.syncingProgressText
                                .replace('{processed}', processed)
                                .replace('{total}', total);
                            
                            console.log('Updating status text:', statusText);
                            $('#printify-surecart-sync-status-text').text(statusText);
                        }
                        
                        // Check if sync is completed
                        if (response.data.status === 'completed') {
                            console.log('Sync completed');
                            
                            // Hide status spinner
                            $('#printify-surecart-sync-status').hide();
                            
                            // Update the notice to show completion
                            $('#printify-surecart-sync-notice').removeClass('notice-info').addClass('notice-success');
                            $('#printify-surecart-sync-notice p:first-child strong').text(printifySureCartSync.syncCompletedText);
                            $('#printify-surecart-sync-status-text').text(printifySureCartSync.syncCompletedDetailsText
                                .replace('{created}', response.data.created)
                                .replace('{updated}', response.data.updated)
                                .replace('{errors}', response.data.errors)
                            );
                            
                            // Keep the notice visible for a moment, then hide it after a delay
                            setTimeout(function() {
                                $('#printify-surecart-sync-notice').fadeOut(500);
                            }, 10000); // Show for 10 seconds
                            
                            // Re-enable both buttons
                            $('#printify-surecart-sync-button').prop('disabled', false);
                            $('#printify-surecart-force-sync-button').prop('disabled', false);
                        } else if (response.data.status === 'in_progress') {
                            // Continue polling after a delay
                            setTimeout(pollSyncStatus, 3000);
                        } else {
                            // Unknown status, stop polling
                            console.log('Unknown status:', response.data.status);
                            
                            // Hide status spinner
                            $('#printify-surecart-sync-status').hide();
                            
                            // Re-enable both buttons
                            $('#printify-surecart-sync-button').prop('disabled', false);
                            $('#printify-surecart-force-sync-button').prop('disabled', false);
                        }
                    } else {
                        // Handle unexpected response format
                        console.warn('Unexpected status response format:', response);
                        
                        // Hide status spinner
                        $('#printify-surecart-sync-status').hide();
                        
                        // Re-enable both buttons
                        $('#printify-surecart-sync-button').prop('disabled', false);
                        $('#printify-surecart-force-sync-button').prop('disabled', false);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Status check failed:', status, error);
                    
                    // Try again after a delay
                    setTimeout(pollSyncStatus, 5000);
                }
            });
        }
        
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
        
        // Test API connection
        $('#printify-test-connection').on('click', function() {
            console.log('Test connection button clicked'); // Debug log
            var $button = $(this);
            var $result = $('#printify-connection-result');
            var apiToken = $('#printify_surecart_sync_api_token').val();
            var shopId = $('input[name="printify_surecart_sync_shop_id"]').val();
            
            console.log('API Token length:', apiToken.length); // Debug log (length only for security)
            console.log('Shop ID:', shopId); // Debug log
            
            // Disable button and show loading
            $button.prop('disabled', true).text('Testing...');
            $result.hide();
            
            // Make AJAX request
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'printify_test_connection',
                    nonce: printifySureCartSync.nonce,
                    api_token: apiToken,
                    shop_id: shopId
                },
                success: function(response) {
                    console.log('AJAX success response:', response); // Debug log
                    
                    if (response.success) {
                        $result.removeClass('notice-error').addClass('notice-success')
                               .html(response.data.message)
                               .show();
                    } else {
                        $result.removeClass('notice-success').addClass('notice-error')
                               .html(response.data.message)
                               .show();
                    }
                    
                    // Re-enable button
                    $button.prop('disabled', false).text(printifySureCartSync.testConnectionText);
                },
                error: function(xhr, status, error) {
                    console.log('AJAX error:', status, error); // Debug log
                    console.log('Response text:', xhr.responseText); // Debug log
                    
                    $result.removeClass('notice-success').addClass('notice-error')
                           .html(printifySureCartSync.errorText + ': ' + error)
                           .show();
                    
                    // Re-enable button
                    $button.prop('disabled', false).text(printifySureCartSync.testConnectionText);
                }
            });
        });
    });
})(jQuery);
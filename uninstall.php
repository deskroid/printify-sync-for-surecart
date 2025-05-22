<?php
/**
 * Uninstall Printify SureCart Sync
 *
 * @package Printify_SureCart_Sync
 */

// If uninstall not called from WordPress, exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('printify_surecart_sync_api_token');
delete_option('printify_surecart_sync_shop_id');
delete_option('printify_surecart_sync_auto_sync');

// Clear scheduled cron events
wp_clear_scheduled_hook('printify_surecart_sync_cron');
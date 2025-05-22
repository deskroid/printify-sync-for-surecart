=== Printify SureCart Sync ===
Contributors: yourname
Tags: printify, surecart, woocommerce, ecommerce, print on demand, pod
Requires at least: 5.6
Tested up to: 6.4
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync your Printify products with SureCart to easily sell print-on-demand products.

== Description ==

Printify SureCart Sync allows you to automatically sync your Printify products with SureCart, making it easy to sell print-on-demand products on your WordPress site.

= Features =

* Automatically sync products from Printify to SureCart
* Manual sync option
* Scheduled daily sync
* Syncs product details, variants, images, and pricing
* Keeps track of Printify product IDs for easy updates
* Automatically sync orders from SureCart to Printify
* Manual order sync option
* Order status synchronization

= Requirements =

* WordPress 5.6 or higher
* PHP 7.4 or higher
* SureCart plugin installed and activated
* Printify account with API access

== Installation ==

1. Upload the `printify-surecart-sync` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to SureCart > Printify Sync to configure the plugin
4. Enter your Printify API token and Shop ID
5. Click "Sync Products Now" to manually sync products or enable auto-sync

== Frequently Asked Questions ==

= Where do I find my Printify API token? =

You can generate a Printify API token in your Printify account under My Profile > Connections.

= Where do I find my Printify Shop ID? =

Your Printify Shop ID can be found in the URL when viewing your shop in Printify. For example, if the URL is `https://printify.com/app/shop/12345/products`, your Shop ID is `12345`.

= How often are products synced? =

If you enable auto-sync, products will be synced once daily. You can also manually sync products at any time.

= What happens if I delete a product in Printify? =

Currently, the plugin only syncs products from Printify to SureCart. It does not delete products in SureCart when they are deleted in Printify.

= How does order sync work? =

When an order is placed in SureCart that contains Printify products, the plugin can automatically send that order to Printify for fulfillment. The plugin maps SureCart order data to Printify's order format, including customer information, shipping address, and product details.

= Which order statuses trigger a sync to Printify? =

By default, only orders with the "Paid" status are synced to Printify. You can customize this in the plugin settings to include other statuses like "Processing" or "Completed".

= Can I manually sync an order to Printify? =

Yes, you can manually sync any SureCart order to Printify by entering the order ID in the Order Sync section of the plugin settings page.

== Screenshots ==

1. Plugin settings page
2. Manual sync results

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release
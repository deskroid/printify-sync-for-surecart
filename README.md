# Printify SureCart Sync

Sync your Printify products with SureCart to easily sell print-on-demand products on your WordPress site.

## Description

Printify SureCart Sync allows you to automatically sync your Printify products with SureCart, making it easy to sell print-on-demand products on your WordPress site.

### Features

* Automatically sync products from Printify to SureCart
* Manual sync option
* Scheduled daily sync
* Syncs product details, variants, images, and pricing
* Keeps track of Printify product IDs for easy updates
* Automatically sync orders from SureCart to Printify
* Manual order sync option
* Order status synchronization

### Requirements

* WordPress 5.6 or higher
* PHP 7.4 or higher
* SureCart plugin installed and activated
* Printify account with API access

## Installation

1. Upload the `printify-surecart-sync` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to SureCart > Printify Sync to configure the plugin
4. Enter your Printify API token and Shop ID
5. Click "Sync Products Now" to manually sync products or enable auto-sync

## Configuration

### Printify API Token

You can generate a Printify API token in your Printify account under My Profile > Connections.

1. Log in to your Printify account
2. Go to My Profile > Connections
3. Under "API Keys", click "Generate new key"
4. Enter a name for your key (e.g., "SureCart Sync")
5. Copy the generated API token

### Printify Shop ID

Your Printify Shop ID can be found in the URL when viewing your shop in Printify.

1. Log in to your Printify account
2. Go to your shop
3. Look at the URL in your browser
4. The Shop ID is the number in the URL (e.g., if the URL is `https://printify.com/app/shop/12345/products`, your Shop ID is `12345`)

## Usage

### Product Sync

#### Manual Product Sync

1. Go to SureCart > Printify Sync
2. Click "Sync Products Now"
3. Wait for the sync to complete
4. View the sync results

#### Automatic Product Sync

1. Go to SureCart > Printify Sync
2. Check the "Automatically sync products daily" option
3. Click "Save Changes"

### Order Sync

#### Manual Order Sync

1. Go to SureCart > Printify Sync
2. In the Order Sync section, enter the SureCart Order ID
3. Click "Sync Order"
4. Wait for the sync to complete
5. View the sync results

#### Automatic Order Sync

1. Go to SureCart > Printify Sync
2. Check the "Automatically sync orders to Printify" option
3. Select which order statuses should trigger a sync
4. Click "Save Changes"

## Frequently Asked Questions

### How often are products synced?

If you enable auto-sync, products will be synced once daily. You can also manually sync products at any time.

### What happens if I delete a product in Printify?

Currently, the plugin only syncs products from Printify to SureCart. It does not delete products in SureCart when they are deleted in Printify.

### How does order sync work?

When an order is placed in SureCart that contains Printify products, the plugin can automatically send that order to Printify for fulfillment. The plugin maps SureCart order data to Printify's order format, including customer information, shipping address, and product details.

### Which order statuses trigger a sync to Printify?

By default, only orders with the "Paid" status are synced to Printify. You can customize this in the plugin settings to include other statuses like "Processing" or "Completed".

### Can I manually sync an order to Printify?

Yes, you can manually sync any SureCart order to Printify by entering the order ID in the Order Sync section of the plugin settings page.

### Can I customize the product data that is synced?

The plugin syncs the following data from Printify to SureCart:
- Product title
- Product description
- Product images
- Variants (options, SKUs, prices)

You can customize the plugin code to sync additional data or modify how data is synced.

## Support

For support, please create an issue on the [GitHub repository](https://github.com/davleav/printify-surecart-sync).

## License

This plugin is licensed under the GPL v2 or later.

```
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
```
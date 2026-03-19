=== WooCommerce Margin Calculator Pro ===
Contributors: sascom
Tags: woocommerce, margin, profit, cost, calculator
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Advanced margin calculation for WooCommerce: Quick Edit, per-category thresholds, order profit, CSV import/export, dashboard widget.

== Description ==

WooCommerce Margin Calculator Pro is a professional plugin for managing purchase prices and calculating product margins in your WooCommerce store.

= Main features =

* **Quick Edit** - Edit purchase prices directly from the product list
* **Margin calculation** - Automatic margin calculation for each product
* **Margin colors** - Visual indicators (green / orange / red / pink)
* **Per-category thresholds** - Individual margin thresholds for each category
* **Variation support** - Each variation has its own purchase price and margin
* **Order margin** - See profit and margin on every order page + orders list column
* **Unprofitable order warning** - Alert when an order generates negative profit
* **CSV Import** - Bulk import purchase prices via CSV (by SKU or product ID)
* **CSV Export** - Export all purchase prices to CSV
* **Statistics** - Average margin and product overview
* **Dashboard widget** - At-a-glance margin overview on the WordPress dashboard
* **HPOS compatible** - Fully compatible with WooCommerce High-Performance Order Storage

= Translations included =

* 🇬🇧 English
* 🇵🇱 Polish (pl_PL)
* 🇩🇪 German (de_DE)
* 🇫🇷 French (fr_FR)
* 🇪🇸 Spanish (es_ES)

= Margin formula =

`Margin % = (Sale Price Net - Purchase Price Net) / Sale Price Net × 100`

== Installation ==

1. Upload the `margin-calculator-pro` folder to `/wp-content/plugins/`
2. Activate the plugin in the WordPress admin panel
3. Go to WooCommerce → Margin Calculator
4. Configure margin thresholds and VAT rate

== Frequently Asked Questions ==

= Does the plugin work with variable products? =
Yes! Each variation can have its own purchase price and margin.

= How do I import purchase prices in bulk? =
Go to WooCommerce → Margin Calculator → Import/Export section. Upload a CSV with columns: sku, purchase_price (or product_id, purchase_price).

= Is it HPOS compatible? =
Yes, fully compatible with WooCommerce High-Performance Order Storage.

= What languages are supported? =
English, Polish, German, French and Spanish are included. You can add more translations via Poedit.

== Changelog ==

= 1.1.0 - 2025-03-19 =
* NEW: Order margin — profit/margin displayed on order edit page and orders list
* NEW: Unprofitable order warning
* NEW: CSV import of purchase prices (by SKU or product ID)
* NEW: CSV export of all purchase prices
* NEW: HPOS (High-Performance Order Storage) compatibility
* NEW: Translations: Polish, German, French, Spanish
* Security: all SQL queries use $wpdb->prepare()
* Security: capability checks on all admin actions
* All strings i18n ready

= 1.0.0 - 2024-10-13 =
* Initial release

== Upgrade Notice ==

= 1.1.0 =
Major update: order margin, CSV import/export, HPOS support, 4 languages. Upgrade recommended.

=== WooCommerce ShipStation Integration ===
Contributors: woocommerce, automattic, royho, akeda, mattyza, bor0, woothemes, dwainm, laurendavissmith001
Tags: shipping, woocommerce, automattic
Requires at least: 6.5
Tested up to: 6.7
WC tested up to: 9.3
WC requires at least: 9.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 4.4.5
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

The official WooCommerce ShipStation plugin helps store owners integrate WooCommerce with ShipStation and expedite the shipping process.

== Description ==

ShipStation’s sophisticated automation features help you shave many hours off your fulfillment process. Print wirelessly and share your printer with ease thanks to ShipStation Connect. Run your business on-the-go with ShipStation Mobile, the industry’s only mobile app (free for iOS and Android) and do everything from creating orders to printing labels and emailing return labels all from your phone or tablet.

= Why choose ShipStation? =

ShipStation is a web-based shipping solution that streamlines the order fulfillment process for online retailers, handling everything from order import and batch label creation to customer communication. Advanced customization features allow ShipStation to fit businesses with any number of users or locations.

== Frequently Asked Questions ==

= Does ShipStation provide real-time shipping quotes that can be used at checkout? =

No. Store owners need a real-time shipping quote extension such as USPS, FedEx, UPS, etc. or have an alternate way to show shipping quotes (e.g., Flat rate charge).

= Does ShipStation send data when not being used (e.g., Free Shipping)? =

Yes, there isn’t conditional exporting. If the data is there, we export it!

= Why do multiple line items in an order on the WooCommerce side get combined when they reach ShipStation? =

This is most likely because unique Product SKUs have not been configured for each product and variation in the Store. To ensure that order line items show up correctly in ShipStation, we recommend assigning a unique SKU to each product as well as each variation within a product.

= Why do multiple line items in an order on the WooCommerce side get combined when they reach ShipStation? =

This is most likely because unique Product SKUs have not been configured for each product and variation in the Store. To ensure that order line items show up correctly in ShipStation, we recommend assigning a unique SKU to each product as well as each variation within a product.

= Where can I find documentation? =

For help setting up and configuring, please refer to our [user guide](https://docs.woocommerce.com/document/shipstation-for-woocommerce)

= Where can I get support or talk to other users? =

If you get stuck, you can ask for help in the Plugin Forum.

== Changelog ==

= 4.4.5 - 2024-10-28 =
* Tweak - WordPress 6.7 Compatibility.

= 4.4.4 - 2024-07-02 =
* Fix   - Security updates.
* Tweak - WooCommerce 9.0 and WordPress 6.6 Compatibility.

= 4.4.3 - 2024-05-27 =
* Tweak - Performance enhancement.

= 4.4.2 - 2024-04-09 =
* Fix - Cannot retrieve order number on from GET variable.

= 4.4.1 - 2024-03-25 =
* Tweak - WordPress 6.5 compatibility.

= 4.4.0 - 2024-03-19 =
* Fix - Applying WordPress coding standards.

= 4.3.9 - 2023-09-05 =
* Fix - Security updates.
* Tweaks - Developer dependencies update.
* Add - Developer QIT workflow.

= 4.3.8 - 2023-08-09 =
* Fix - Security updates.

= 4.3.7 - 2023-05-08 =
* Fix - Allow filtering the order exchange rate and currency code before exporting to ShipStation.

= 4.3.6 - 2023-04-20 =
* Fix - Compatibility for Sequential Order Numbers by WebToffee.
* Add - New query var for WC_Order_Query called `wt_order_number` to search order number.

= 4.3.5 - 2023-04-17 =
* Fix - Revert version 4.3.4's compatibility update for Sequential Order Numbers by WebToffee.

= 4.3.4 - 2023-04-12 =
* Fix   - Compatibility for Sequential Order Numbers by WebToffee.

= 4.3.3 - 2023-03-29 =
* Fix   - Fatal error when product image does not exist.

= 4.3.2 - 2022-11-29 =
* Fix   - Use product variation name when exporting a product variation.

= 4.3.1 - 2022-10-25 =
* Add   - Declared HPOS compatibility.

= 4.3.0 - 2022-10-13 =
* Add   - High-Performance Order Storage compatibility.

= 4.2.0 - 2022-09-07 =
* Add   - Filter for manipulating address export data.
* Fix   - Remove unnecessary files from plugin zip file.
* Tweak - Transition version numbering to WordPress versioning.
* Tweak - WC 6.7.0 and WP 6.0.1 compatibility.
* Fix - Remove 'translate : true' in package.json.

= 4.1.48 - 2021-11-03 =
* Fix - Critical Error when null value is passed to appendChild method.
* Fix - $logging_enabled compared against string instead of boolean.

= 4.1.47 - 2021-09-29 =
* Fix - Change API Export order search to be accurate down to the second, not just the date.

= 4.1.46 - 2021-09-10 =
* Fix   - Order is not changed to completed when the order has partial refund and is marked as shipped in ShipStation.

= 4.1.45 - 2021-08-24 =
* Fix    - Remove all usage of deprecated $HTTP_RAW_POST_DATA.

= 4.1.44 - 2021-08-12 =
* Fix    - Changing text domain to "woocommerce-shipstation-integration" to match with plugin slug.
* Fix    - Order product quantities do not sync to Shipstation when using a refund.
* Fix    - PHP notice error "wc_cog_order_total_cost" was called incorrectly.

= 4.1.43 - 2021-07-27 =
* Fix   - API returns status code 200 even when errors exist.
* Tweak - Add version compare for deprecated Order::get_product_from_item().

= 4.1.42 - 2021-04-20 =
* Fix - Use order currency code instead of store currency.

= 4.1.41 - 2021-03-02 =
* Add - Add currency code and weight units to orders XML.

= 4.1.40 - 2020-11-24 =
* Tweak - PHP 8 compatibility fixes.

= 4.1.39 - 2020-10-06 =
* Add   - Add woocommerce_shipstation_export_order_xml filter.
* Tweak - Update Readme.
* Tweak - WC 4.5 compatibility.
* Fix   - Updated shop_thumbnail to woocommerce_gallery_thumbnail for thumbnail export.

[See changelog for all versions](https://github.com/woocommerce/woocommerce-shipstation/raw/master/changelog.txt).

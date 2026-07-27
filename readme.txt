=== BLT SureCart Extensions ===
Contributors: sfxcom
Tags: surecart, shipping, shippo, fulfillment, ecommerce
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires Plugins: surecart

Umbrella extension plugin adding modular capabilities to SureCart. This release ships one module: Shippo Fulfillment.

== Description ==

BLT SureCart Extensions is a private plugin by S-FX.com Small Business Solutions, built for deployment across multiple SureCart-powered client sites. It is not distributed via the WordPress.org plugin directory; updates are delivered from private GitHub Releases.

**Shippo Fulfillment module**

* When a SureCart order is paid, quotes it against Shippo and purchases a shipping label.
* Writes the tracking number and label back to SureCart as a fulfillment.
* Keeps shipment status synced as the package moves — shipped, in transit, delivered, exception — via Shippo's tracking webhook plus a reconciliation sweep for anything a webhook missed.

This module never touches checkout rate calculation or order totals — SureCart's own subtotal-banded/weight-banded shipping rates remain in full control of what the customer is charged. Everything this module does happens strictly after payment.

Every guardrail below ships **on** by default except auto-purchase, which ships **off**:

* Absolute and percent-of-order-total rate ceilings.
* Destination address validation via Shippo before purchase.
* Configurable destination-country allowlist (APO/FPO/DPO held by default).
* Database-level duplicate-purchase protection.
* Test/live Shippo token mismatch is blocked outright.
* A single kill switch halts all purchasing without deactivating the plugin.

== Installation ==

1. Ensure the SureCart plugin is installed and active.
2. Install and activate BLT SureCart Extensions.
3. Go to **BLT SureCart Extensions → Settings** and configure a Shippo API token (a `shippo_test_…` token first, to exercise the whole flow without spending money), your ship-from address, parcel definitions, and SKU → parcel mapping.
4. Leave **Auto-purchase** off until you've watched a few orders move through the Review Queue correctly.

== Frequently Asked Questions ==

= Does this plugin change what customers are charged for shipping at checkout? =

No. It only runs after an order is already paid.

= What happens if Shippo is down or a request times out? =

The purchase job retries with exponential backoff, up to 5 attempts, then marks the shipment `failed` and surfaces it in the admin (Shipments screen, an admin notice, and a WP Site Health check). It never retries indefinitely against a paid API.

= What if a tracking webhook is missed? =

A reconciliation sweep re-checks any shipment stuck in a non-terminal status every 15 minutes (configurable threshold, default 6 hours before a shipment is considered "stuck").

== Changelog ==

= 0.1.0 =
* Initial release. Shippo Fulfillment module: label purchase, SureCart fulfillment write-back, tracking status sync, reconciliation sweep, full guardrail set, module registry for future extensions.

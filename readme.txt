=== SneakyPeak — WooCommerce Sneak Peek & Campaign Reveal ===
Contributors: dazztine
Donate link: https://github.com/dazztine/sneakypeak
Tags: woocommerce, sneak peek, flash sale, mega sale, 10.10, countdown, badges
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generalized sneak peek campaign manager for WooCommerce: customizable corner badges, masked teaser prices (8,???), early-sale price guards, and scheduled reveal phases.

== Description ==

**SneakyPeak** is an enterprise-grade WooCommerce marketing plugin designed for major shopping festivals and flash campaigns (such as 10.10, 11.11, Black Friday, Payday Drop, or Summer Kickoff).

Each campaign operates through distinct phases:
* **Scheduled**: Prior to teaser launch.
* **Teaser**: Displays eye-catching corner ribbon/pill badges on product cards and masked teaser prices (`From ₱8,???`) in price lists. Strictly enforces the regular price so customers cannot purchase discounts early.
* **Live**: Automatically reveals the campaign sale price and switches badges to live mode.
* **Ended**: Automatically hides badges and teasers, restoring native WooCommerce behavior.

### 🛡️ Non-Negotiable Architecture Rules
1. **Never Touches Images**: Badges attach exclusively to the product card container. Thumbnail markup and lazy-load wrappers remain 100% untouched.
2. **Strict Price Guarding**: Disables premature discount purchases in cart, checkout, REST API, JSON-LD, Store API, and variation dropdowns.
3. **No Fake Prices**: Products without an eligible sale price never show teaser prices or badges.
4. **Independent Reveal Logic**: Evaluates campaign phases dynamically at request time based on store timezone without depending on WP-Cron timing.

== Installation ==

1. Upload the `sneakypeak` folder to the `/wp-content/plugins/` directory, or upload `sneakypeak.zip` directly via **Plugins > Add New > Upload Plugin**.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **WooCommerce > SneakyPeak** to create your first campaign.

== Frequently Asked Questions ==

= Does this plugin wrap or restyle product thumbnails? =
No. In accordance with strict compatibility standards, SneakyPeak attaches badges only to the outer card element. Your theme's image containers and hover overlays remain completely unmodified.

= What happens if a product is in multiple active campaigns? =
Each campaign features a configurable **Priority** setting. The campaign with the lower priority number takes precedence.

== Changelog ==

= 1.2.0 =
* Official Stable Open-Source Release: Codebase cleaned, audit-verified, and prepared for open-source distribution on GitHub with dazztine as author.
* Sticky Live Designer: Interactive mock product card stays pinned alongside badge customization settings (>=1200px viewport) with instant live preview updates.
* Precision Badge Nudging: Signed X and Y nudge inputs (-100px to 100px) with CSS transform translation and backwards-compatible offset migration.
* Architecture Hardening: Null-safe PriceSource fallback resolution in Resolver, fail-safe hook wrappers (`Safe::action`, `Safe::filter`), and complete isolation from product edit screens.

= 1.1.3-preview =
* Sticky Live Preview: Wrapped Section 3 (Badge Design & Placement) in a 2-column layout (>=1200px) with the mock card pinned sticky on the right (`top: 48px;`), keeping live visual feedback in view while tuning badge settings.
* Position Badge by Nudge: Replaced 4 offset fields with signed Horizontal (X) and Vertical (Y) nudge fields (-100px to 100px) applied via `transform: translate(Xpx, Ypx) !important;` with backward compatibility migration for legacy offsets.
* Scoped Section Typography: Enhanced metabox header, section header, and form label typography scoped cleanly to `sneakypeak_campaign` admin screens.

= 1.1.2-preview =
* Admin Isolation: Removed all product edit metaboxes, scripts, and product screen UI to eliminate conflicts and prevent blank product edit screens; SneakyPeak operates strictly on its own campaign post type screens.
* Storefront-Only Hooks: Frontend price guards, post classes, and teaser hooks register exclusively during storefront requests (`Plugin::is_storefront_request()`).
* Fail-Safe Architecture: Added `Safe::filter()` and `Safe::action()` wrappers around all WooCommerce/WordPress hook callbacks to catch any exceptions cleanly, log once per request, and return safe default values.
* Legacy Teaser Clean: Removed per-product custom teaser meta lookups from `LegacyPriceSource`, relying cleanly on WooCommerce sale prices.

= 1.1.1-preview =
* Unavailable Presets: Preset evaluation returns 0 and displays informative notice if schedule dates are missing, preventing unconfigured preview starts; unavailable presets are greyed out and labeled in admin bar and campaign screen.
* Redirect Target: Preview actions support validated `redirect_to` parameter; "Preview on store" buttons land on the shop page or targeted product page.
* Admin Footer Modal: Custom date/time modal and trigger scripts rendered on `admin_footer` when admin bar is active.
* Full Checkout Safety: Multi-campaign comparison against real time blocks checkout if any campaign phase differs or in custom mode; integrated Cart/Checkout Blocks & Store API guards (`woocommerce_store_api_checkout_update_order_from_request`, `woocommerce_store_api_cart_errors`).
* Per-Campaign Presets: Preset mode simulates time per-campaign dynamically; campaigns without valid presets run in real time and are listed in the preview banner.

= 1.1.0-preview =
* Preview Engine: Front-end time travel simulating Scheduled, Teaser, Live, Ended phases and custom date/times via signed HMAC cookies without modifying DB settings.
* Admin Bar: Quick switcher with presets, active phase indicator, and custom date/time dialog.
* Sticky Banner: Front-end status banner with simulated vs real time and exit button.
* Safety Guards: Checkout validation prevents simulated phase checkout; cache suppression tags (DONOTCACHEPAGE) applied automatically.
* Admin Insights: Interactive Live Preview & Insights metabox featuring vertical milestone timeline, customer experience matrix, interactive badge designer mock card, live teaser calculator, and targeted product sample inspector.
* Product Edit Metabox: Read-only SneakyPeak box displaying active campaign status and per-phase pricing.

= 1.0.1-phase1 =
* Price Guard Gap Fix: Price guard now guards across scheduled and teaser phases.
* Performance: Static request caching for campaign objects and resolver lookups.
* Validation: Non-silent admin notices for invalid timing sequences.

= 1.0.0-phase1 =
* Phase 1 Foundation: Custom Post Type `sneakypeak_campaign`, priority conflict resolution, card-only badge attachment, timing order validation, early-sale price guards, and one-click migration from legacy 10.10 Sneak Peek.

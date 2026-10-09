# SneakyPeak — WooCommerce Sneak Peek & Campaign Reveal

[![Version](https://img.shields.io/badge/version-1.3.0-blue.svg)](https://github.com/dazztine/sneakypeak/releases)
[![WordPress](https://img.shields.io/badge/wordpress-6.2%2B-blue.svg)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/woocommerce-8.0%2B-purple.svg)](https://woocommerce.com)
[![PHP](https://img.shields.io/badge/php-7.4%2B-8892BF.svg)](https://php.net)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-orange.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

**SneakyPeak** is an open-source, enterprise-grade promotional campaign manager for WooCommerce. Built for major shopping festivals and flash sales (**10.10, 11.11, Black Friday, Payday Sales, or seasonal drops**), SneakyPeak lets store owners build hype with teaser badges and masked prices while strictly locking down checkout until the moment the sale officially goes live.

---

## 📖 Table of Contents
- [Why SneakyPeak?](#-why-sneakypeak)
- [Core Architecture & Safety Rules](#-core-architecture--safety-rules)
- [How It Works (Campaign Lifecycle)](#-how-it-works-campaign-lifecycle)
- [Key Features](#-key-features)
- [Quick Start Guide](#-quick-start-guide)
- [Time Travel & Preview Engine](#-time-travel--preview-engine)
- [Developer Hooks & API](#-developer-hooks--api)
- [File Structure](#-file-structure)
- [Contributing](#-contributing)
- [Author & License](#-author--license)

---

## 💡 Why SneakyPeak?

Most flash sale plugins suffer from two major flaws:
1. **Broken Theme Layouts**: Injecting wrappers around catalog thumbnails breaks lazy loaders, hover overlays, wishlist icons, and gallery carousels.
2. **Early Price Leaks**: Displaying upcoming discounts often leaks into variable dropdowns, schema markup, cart endpoints, or REST APIs, allowing savvy customers to buy items early at the promo price.

**SneakyPeak solves both completely.** It attaches absolute-positioned badges strictly to the product card container without altering image markup, and enforces a multi-layer early-sale pricing guard across all WooCommerce endpoints until reveal time.

---

## 🛡️ Core Architecture & Safety Rules

1. **Zero Thumbnail Touching (Card-Only Badges)**:
   - Badges (`.sneakypeak-badge-wrap`) attach exclusively to the outer product card element (`li.product`, `.wc-block-grid__product`, etc.) via absolute positioning.
   - The plugin **never** wraps, modifies, or restyles `<img>` tags or their immediate anchors, preserving theme zoom, hover states, and lazy-loading.
2. **No Fake / Estimated Prices**:
   - Products without a valid WooCommerce sale price never display misleading teaser prices or badges.
3. **Multi-Layer Early-Sale Price Guard**:
   - Prior to the Live reveal, discounts are strictly guarded across:
     - `woocommerce_product_get_price` & `woocommerce_product_variation_get_price`
     - Variation prices transients & JSON-LD product offers
     - Store API & WooCommerce Cart/Checkout Blocks
     - REST API (`/wc/store/`)
4. **Independent Dynamic Phase Resolution**:
   - Campaign phases evaluate dynamically at request time using the store's configured timezone (`wp_timezone()`), eliminating reliance on WP-Cron timing.
5. **Multi-Campaign Priority Engine**:
   - When multiple campaigns target the same product, conflict resolution is handled cleanly via a configurable priority score (lower number wins).
6. **Isolated Admin Footprint**:
   - SneakyPeak runs strictly on its own Custom Post Type (`sneakypeak_campaign`) and never modifies native product edit screens.

---

## 🔄 How It Works (Campaign Lifecycle)

```
[ Scheduled ] ──> [ Teaser Phase ] ──> [ Live (Reveal) ] ──> [ Ended ]
     │                    │                     │                 │
 Price Guard          Price Guard           Discounts         Badges & Teasers
   Active,              Active,              Unlocked,          Deactivated,
 Badge Hidden      Badge + Masked Price    Live Badge         Normal Catalog
                      (e.g. ₱8,???)           Shown              Restored
```

| Phase | Badge Visibility | Catalog Price Shown | Add to Cart / Checkout Price |
| :--- | :--- | :--- | :--- |
| **Scheduled** | Hidden | Regular Price | Regular Price (Guarded) |
| **Teaser** | Teaser Badge (`SNEAK PEEK`) | Regular Price + Masked Teaser Line (`₱8,???`) | Regular Price (Guarded) |
| **Live** | Live Badge (`SALE NOW`) | Discounted Sale Price | Sale Price (Unlocked) |
| **Ended** | Hidden | Normal WooCommerce Price | Normal WooCommerce Price |

---

## ✨ Key Features

- **Custom Campaign Builder**:
  - Manage multiple simultaneous campaigns under **WooCommerce > SneakyPeak**.
  - Target by product categories (with auto child/descendant category inclusion), tags, or specific product IDs, with dedicated category and ID exclusion rules.
- **Sticky Live Badge Designer**:
  - Pinned interactive product card mock in campaign settings provides instant visual feedback while scrolling through options.
  - Choose corner anchor (Top-Right, Top-Left, Bottom-Right, Bottom-Left).
  - Select shapes: **Ribbon**, **Pill**, **Circle**, or **Flag**.
  - Fine-tune positioning using signed **Horizontal (X)** and **Vertical (Y)** nudge inputs in pixels.
  - Configure icons (Gold Star ★, Fire 🔥, Price Tag 🏷, or None), gradient backgrounds, typography, and custom campaign CSS.
- **Single Product Page Placement Controls**:
  - Granular control over single product view: choose between **Over main gallery image**, **Inside product summary (above title/price)**, or **Custom CSS selector**.
  - Independent corner anchoring and fine-tuning signed nudges (X/Y) specifically for single product view.
- **Typography & Font Customization**:
  - Curated Google Fonts matching modern retail & athletic aesthetics (such as Adidas): **Jost** (Avant Garde / Futura-style), **Montserrat**, **Poppins**, **Outfit**, **Inter**, and **Questrial**.
  - Upload custom brand fonts (`.woff2`, `.woff`, `.ttf`) directly via the WordPress Media Uploader.
  - Custom font size controls with live interactive preview in wp-admin.
- **Masked Teaser Pricing**:
  - Two masking modes: `keep_first` (e.g. `₱8,499.00` → `₱8,???`) or `mask_last_3` (e.g. `₱12,500.00` → `₱12,???`).
  - Interactive teaser calculator directly inside wp-admin.
- **Admin Insights & Diagnostics**:
  - Visual campaign timeline with countdowns.
  - Diagnostic warnings for targeted products missing sale prices or having conflicting native WooCommerce sale schedules.
  - Sample inspector table of the first 50 targeted products showing the active winning campaign.
- **One-Click Duplication**:
  - Clone existing campaigns as disabled drafts with a single click.
- **Legacy Migration Assistant**:
  - One-click migration of settings from older `wc-1010-sneak-peek` setups without data loss.

---

## 🚀 Quick Start Guide

### Installation

1. Download the latest `sneakypeak.zip` from [Releases](https://github.com/dazztine/sneakypeak/releases).
2. Go to your WordPress Admin dashboard: **Plugins > Add New > Upload Plugin**.
3. Choose `sneakypeak.zip`, install, and click **Activate**.

### Creating Your First Campaign

1. Navigate to **WooCommerce > SneakyPeak** in your WordPress dashboard.
2. Click **Add New Campaign**.
3. Set your schedule:
   - **Teaser Start**: When badges and masked teaser lines appear (optional; starts immediately if left blank).
   - **Reveal Start (Required)**: Exact date/time when sale prices unlock and the badge flips to Live.
   - **End Date**: When the campaign concludes and returns to normal prices.
4. Select your **Targets** (Categories, tags, or product IDs).
5. Customize badge appearance in the **Sticky Live Designer** and review your sample card.
6. Click **Publish**.

---

## 🔮 Time Travel & Preview Engine

Test your campaigns before they go live without modifying database timestamps or impacting real shoppers.

- **Admin Bar Time Traveler**:
  - Switch between **Scheduled**, **Teaser**, **Live**, and **Ended** presets or jump to any arbitrary date/time.
  - Generates secure, HMAC-signed session cookies (`wp_hash`) valid for 4 hours.
  - Accessible only to users with the `manage_woocommerce` capability.
- **Storefront Banner**:
  - Pinned bar displaying current simulated phase, simulated time, and real time with a one-click exit button.
- **Zero-Risk Checkout Guard**:
  - Blocks accidental orders if the active preview simulates a phase that differs from the store's real-time phase.
- **Cache Suppression**:
  - Sends `DONOTCACHEPAGE`, `DONOTCACHEOBJECT`, and `nocache_headers()` so preview pages are never cached by CDNs or page caches.

---

## 🛠️ Developer Hooks & API

### Filters

```php
// Customise or override whether a preview checkout is permitted
add_filter('sneakypeak_allow_preview_checkout', function(bool $allow): bool {
    return false;
});

// Provide a custom pricing engine by implementing SneakyPeak\Pricing\PriceSource
add_action('sneakypeak_init', function() {
    \SneakyPeak\Campaigns\Resolver::set_price_source(new MyCustomPriceSource());
});
```

### Safe Error Boundary
All WordPress and WooCommerce action and filter callbacks within SneakyPeak are guarded with `SneakyPeak\Support\Safe`:
```php
use SneakyPeak\Support\Safe;

// Catches any Throwable, logs once per unique error per request, returns default value safely
add_filter('woocommerce_get_price_html', Safe::filter('my_callback', 0));
add_action('wp_body_open', Safe::action('my_action_callback'));
```

---

## 📦 File Structure

```text
sneakypeak/
├── sneakypeak.php              # Plugin entrypoint, HPOS & Blocks declaration (v1.2.0)
├── readme.txt                  # WordPress.org plugin directory readme
├── README.md                   # GitHub documentation & guide
├── assets/
│   ├── css/
│   │   └── sneakypeak.css      # Badge styling, ribbon notches, responsive cards
│   └── js/
│       ├── sneakypeak.js       # Card-only badge attachment & MutationObserver
│       └── sneakypeak-admin.js # Live sticky designer & interactive teaser calculator
├── includes/
│   ├── Plugin.php              # Central coordinator & subsystem initialization
│   ├── Campaign.php            # Campaign model, phase resolver, signed nudges
│   ├── Preview.php             # Signed time travel preview engine & checkout guards
│   ├── Admin/
│   │   └── MetaBox.php         # Campaign settings metaboxes, sticky live designer
│   ├── Campaigns/
│   │   ├── PostType.php        # Custom post type, admin menus, row actions
│   │   └── Resolver.php        # Target matching, priority sorting, versioned cache
│   ├── Frontend/
│   │   ├── Controller.php      # Price guarding filters, gallery injection, inline CSS
│   │   └── Badge.php           # HTML badge generator (ribbon, pill, circle, flag)
│   ├── Migration/
│   │   └── Importer.php        # 1-click migration for legacy 10.10 options
│   ├── Pricing/
│   │   ├── PriceSource.php     # Interface for price providers
│   │   └── LegacyPriceSource.php # Default WooCommerce sale price provider
│   └── Support/
│       └── Safe.php            # Fail-safe error boundary for hook callbacks
└── languages/
    └── sneakypeak.pot          # Translation template
```

---

## 🤝 Contributing

Contributions, bug reports, and suggestions are welcome!
1. Fork the repository on GitHub.
2. Create your feature branch (`git checkout -b feature/amazing-feature`).
3. Commit your changes (`git commit -m 'Add amazing feature'`).
4. Push to the branch (`git push origin feature/amazing-feature`).
5. Open a Pull Request.

---

## 👤 Author & License

- **Author**: [dazztine](https://github.com/dazztine)
- **Repository**: [https://github.com/dazztine/sneakypeak](https://github.com/dazztine/sneakypeak)
- **License**: Released under the [GNU General Public License v2.0 or later (GPLv2+)](https://www.gnu.org/licenses/gpl-2.0.html).

=== Muldoon ===
Contributors: jeffcaldwell
Tags: domain mapping, domain alias, multiple domains, parked domain, subdomain
Requires at least: 4.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Map any extra domain or subdomain to a WordPress page, post, or archive without redirects. The mapped domain stays in the visitor's address bar.

== Description ==

**Muldoon: The Multi Domain Name Mapper.** Point any extra domain or subdomain at a specific WordPress path and the plugin handles the rest transparently. Visitors see the mapped domain in their address bar; WordPress serves content normally from the underlying path. All internal links (navigation, pagination, archives) are rewritten to use the mapped domain, with no redirect and no iframe.

For example, mapping `www.product-a.com` to `/product-a/` means:

* `www.product-a.com/` is served from `www.mainsite.com/product-a/`
* `www.product-a.com/faq/` is served from `www.mainsite.com/product-a/faq/`

All descendant paths follow automatically, and `http`/`https` plus `www`/non-`www` are handled with a single entry per domain.

= Core mapping =

* Domain-stays-in-bar mapping, with no redirect and no iframe
* Rewrites all standard WordPress permalink functions (pages, posts, custom post types, archives, categories, feeds, nav menus)
* Multiple mappings supported; all descendant URIs are mapped automatically
* Core, Yoast SEO, and RankMath XML sitemap compatibility
* Elementor preview URL compatibility

= Per-mapping options =

* Active / inactive toggle (disable without deleting)
* 301 redirect of the original path to the mapped domain
* Noindex on the original path
* Pass-through for unmatched paths
* Site name, site tagline, and default Open Graph image overrides per domain
* Custom `<head>` code, GA4 / GTM ID, and robots.txt sitemap URL per domain
* Live connection test

= SEO =

* A single canonical tag on the mapped domain (Yoast / RankMath canonical rewritten in place, no duplicates)
* `og:url` replacement for Yoast SEO and RankMath
* Mapped-domain URLs in core, Yoast, and RankMath XML sitemaps
* REST API domain replacement

= Administration =

* Admin-bar badge showing the active mapped domain on the frontend
* Import / export mappings as JSON
* Drag-to-reorder mapping rows
* Automatic cache flushing for WP Super Cache, W3 Total Cache, WP Rocket, LiteSpeed Cache, and WP Engine

= Developer hooks =

All action hooks are prefixed `mdmap_appa_` and all filter hooks are prefixed `mdmap_appf_`. See the plugin source or the project website for the full list.

Project website and documentation: https://www.jeffcaldwell.ca/muldoon/

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it from the Plugins screen.
2. Activate Muldoon through the **Plugins** menu in WordPress.
3. Go to **Tools → Muldoon**.
4. Enter the additional domain in the left field and the WordPress path in the right field.
5. Save.

**DNS and hosting:** each additional domain's A-record must point to the same IP as your main domain, and the domain must be routed to the same WordPress root directory (virtual host / domain alias / parked domain).

**Test before configuring:** place a `test.txt` file in your WordPress root and confirm it is reachable from both `yourmain.com/test.txt` and `youraddon.com/test.txt` without redirects before adding mappings.

**nginx users:** change the PHP Server-Variable setting to `HTTP_HOST` in the Settings tab.

== Frequently Asked Questions ==

= Does it use redirects or iframes? =

No. The mapping is transparent. Visitors see the mapped domain in the address bar while WordPress serves content from the underlying path, with no redirect and no iframe.

= Does it work with caching plugins? =

WP Fastest Cache works out of the box. W3 Total Cache requires enabling "Cache alias hostnames" in Page Cache settings. The plugin automatically flushes supported caches when mappings change.

= Does it work with page builders like Elementor? =

Yes. If a page builder fails to load mapped pages in the editor, enable Enhanced Compatibility Mode in the Settings tab.

= Does it work with Yoast SEO and RankMath? =

Yes. XML sitemaps list the mapped domains, `og:url` is patched, and the canonical is rewritten to the mapped domain (no duplicate tag).

= How do I avoid duplicate content? =

Enable the "Noindex on original path" option per mapping, or enable the "301 redirect" option. The plugin also outputs a canonical tag on mapped pages automatically.

= Does it support international domain names (IDN)? =

Yes, in punycode format. For example, enter `www.küche.at` as `www.xn--kche-0ra.at`.

= Is it compatible with WooCommerce? =

Not reliably. WooCommerce uses many non-standard link-generation functions that cannot be intercepted consistently.

= Is it compatible with WPML / Polylang? =

Partially. If those plugins manage domains of their own, add them to the Excluded Domains list in the Settings tab to prevent conflicts.

= Is it compatible with WordPress Multisite? =

No. Multisite has built-in domain mapping. Muldoon targets single-site installations only.

== Screenshots ==

1. The Muldoon admin screen (Tools → Muldoon) showing the branded header and a domain mapping with its advanced options panel.
2. The Settings tab: PHP Server-Variable, enhanced compatibility mode, and excluded domains.

== Changelog ==

= 2.0 =
* Rebrand to Muldoon (The Multi Domain Name Mapper) with a "torn domain" brand mark in the admin header.
* Redesigned, intentional sticky save action bar with an inline unsaved-changes status.
* Internal identifiers (option keys, hook prefixes) unchanged, so existing installs keep all saved mappings and settings with zero migration.

== Upgrade Notice ==

= 2.0 =
Rebrand to Muldoon with admin UI refinements. Your existing mappings and settings are preserved automatically.

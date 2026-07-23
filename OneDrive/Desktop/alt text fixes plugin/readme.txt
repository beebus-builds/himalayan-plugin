=== Himalayan Auto-Fixer — Alt Text & SEO Automation ===
Contributors: himalayan-autofixer
Tags: alt text, seo, accessibility, meta description, meta title, open graph, bulk, automation
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multi-purpose WordPress automation: fixes missing image alt text everywhere and auto-generates SEO meta, social cards, and canonical tags — built in the spirit of the Himalayas, automated and hands-off.

== Description ==

**Himalayan Auto-Fixer** is an all-in-one automation plugin that keeps your site accessible and SEO-ready without manual busywork. Born from the roof of the world, it scales from a single post to unlimited images and pages.

= Alt text, everywhere =

Missing alt text is found and fixed in *every* location it can hide:

* **Media Library** attachments
* `<img>` tags **embedded in post/page content**
* **Post meta** (Elementor, ACF, and other builders — serialized too)
* **CSS `background-image`** (inline `style` and `<style>` blocks → marked decorative)
* **Widgets, Customizer theme mods, and nav-menu markup** stored in `wp_options`
* **All public custom post types** and Full-Site-Editing templates (`wp_template`, `wp_template_part`, `wp_navigation`)

= SEO automation =

* Auto-generates **meta title & description** from configurable templates (`%title%`, `%sitename%`, `%sep%`, `%category%`, `%excerpt%`)
* Outputs **Open Graph + Twitter Card** tags (og:image, twitter:card, etc.)
* Emits a **canonical URL** to avoid duplicate-content issues
* **Per-post override** metabox; auto-generates on save
* Bulk "Generate SEO meta" action, batched and progress-tracked
* **Pings search engines** (Pingomatic + sitemap) when the bulk run finishes

= Schema / structured data =

Outputs valid **JSON-LD** automatically — no manual coding:

* **Organization / LocalBusiness / MedicalBusiness / ProfessionalService / Store / Restaurant** site-wide (configurable name, logo, phone, email, address, geo, opening hours, sameAs, medical specialties)
* **WebSite** with a **SearchAction** (sitelinks search box)
* **BreadcrumbList** on every view (home → taxonomy → post, archives, search)
* **BlogPosting** for posts and **Product** for WooCommerce products (with **ImageObject** and **speakable** markup)
* **FAQPage** built from a per-post FAQ metabox
* **Custom JSON-LD** — paste any client-specific schema (Event, Service, Recipe, etc.) per post
* Technical SEO **audit** (HTTPS, robots.txt, sitemap, canonicals, meta length, social cards, single H1, structured data, alt coverage, indexing) with one-click safe auto-fixes; WP-CLI `wp atf tech_audit`

= Client spreadsheet import =

Upload a CSV your client's developer provides:

* **Alt text mode** — columns `image`, `alt`
* **SEO mode** — columns `post`, `title`, `description`, `image`

A **Site base URL** field resolves bare filenames or relative paths. Downloadable **template CSVs** are provided so the dev knows the exact columns.

= Theme file audit (read-only) =

A "Run theme audit" button scans the active theme's PHP templates for hard-coded `<img>` without alt and CSS background-images that the plugin cannot auto-fix (they live in code, not the database), producing a file/line report for manual edits.

= Hands-off automation =

* **Daily auto-fix (WP-Cron)** — fixes a slice of every scope each day so new content is handled automatically
* **Smarter alt text** — falls back to post context when an image has no usable title/filename
* **Dashboard widget** — remaining counts per scope at a glance
* **WP-CLI** — `wp atf status`, `wp atf fix-all`, `wp atf seo`, `wp atf audit` for reliable bulk runs

== Installation ==

1. Upload the `alt-text-fixer` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" screen.
3. Go to **Settings > Himalayan Auto-Fixer** to configure automation options.

== Frequently Asked Questions ==

= Will this work on a site with thousands of images? =

Yes. Every fixer processes items in small AJAX batches with a progress bar and Stop button, so it never times out. A WP-CLI command is also provided for very large sites or unreliable cron.

= Does it caption image content with AI? =

No. Alt text is generated from existing metadata (title, filename, post context). For AI descriptions, hook the `atf_generate_alt` filter into a vision API.

= Can it fix images in my theme's PHP files? =

Those live outside the database, so the plugin reports them via the read-only theme audit. They need a manual theme edit (or child theme override).

== Screenshots ==

1. Settings page with per-scope fix cards and progress bars.
2. SEO automation settings and per-post override metabox.
3. CSV import with downloadable templates.
4. Dashboard status widget.

== Assets ==

Plugin listing artwork (generated, Himalaya-themed): `assets/banner-772x250.png`,
`assets/banner-1544x500.png`, `assets/icon-128x128.png`, `assets/icon-256x256.png`
(editable sources: `assets/banner.svg`, `assets/icon.svg`).

== Development ==

* `composer install` pulls PHPCS + WordPress Coding Standards for linting.
* `composer lint` runs `php -l` across all PHP files.
* `composer phpcs` (or `vendor/bin/phpcs`) checks coding standards.
* GitHub Actions (`.github/workflows/ci.yml`) runs PHP lint + PHPCS on every push/PR.
* `.gitattributes` / `.distignore` keep dev tooling out of the distributed zip.

== Changelog ==

= 1.0.0 =
* Initial release: alt-text fixing across all locations, SEO meta/social/canonical, CSV import with base-URL resolution and templates, theme audit, daily cron, dashboard widget, WP-CLI, and search-engine pinging.

= 1.1.0 =
* Added schema (structured data) generator: Organization/LocalBusiness/MedicalBusiness, WebSite+SearchAction, BreadcrumbList, BlogPosting/Product with ImageObject + speakable, FAQPage, custom JSON-LD, per-post metabox.
* Added technical SEO audit (HTTPS, robots.txt, sitemap, canonicals, meta length, social cards, single H1, structured data, alt coverage, indexing) with safe one-click auto-fixes.
* WP-CLI: `wp atf schema`, `wp atf tech_audit`.

= 1.2.0 =
* Dedicated top-level admin menu "Himalayan Auto-Fixer" with its own developer dashboard (overview cards, quick actions, inline technical-audit panel).
* Guided schema types per post: Event, JobPosting, VideoObject, Service, Recipe, Course, Person — with a type-aware field UI in the schema metabox.
* Expanded technical audit: mobile viewport, noindex on key pages, sitemap declared in robots.txt, permalink structure.
* Dashboard is accessible to any Administrator (manage_options).

= 1.3.0 =
* **Access capability setting** — choose the minimum capability (Administrator down to Contributor/Shop Manager) required to see the Himalayan Auto-Fixer menu, so a client's developer can use the dashboard without full Admin rights.
* **"Run all fixes" button** on the dashboard — one click fixes library alt text, embedded/meta/CSS images, widget/customizer options, SEO meta, and marks schema-eligible posts.

== Upgrade Notice ==

= 1.0.0 =
First release.

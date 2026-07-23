# Alt Text Fixer

A multi-purpose WordPress automation plugin. It fixes missing image alt text
across the entire site (every possible location) **and** automatically
generates SEO meta titles and descriptions for posts and pages.

## Alt Text features

- **Auto-fill on upload** – When a new image is uploaded, alt text is generated automatically (from the title or file name) if it is empty.
- **Media library column** – A new "Alt Text" column in *Media > Library* shows a green value or a red "Missing" flag.
- **One-click fix** – Each image missing alt text gets a "Fix alt" row action.
- **Bulk fix (unlimited scale)** – From *Settings > Alt Text Fixer*, each scope is processed in background AJAX batches with a live progress bar and **Stop** button. It never times out, no matter how many items exist.
- **Every location scanned** – missing alt is fixed in:
  - Media Library attachments
  - `<img>` embedded in post/page content
  - Post meta (Elementor, ACF, and other builders — serialized too)
  - CSS `background-image` (inline `style` and `<style>` blocks → marked decorative)
  - Widgets, Customizer theme mods, and nav-menu markup stored in `wp_options`
  - All public custom post types and Full-Site-Editing templates (`wp_template`, `wp_template_part`, `wp_navigation`)

## SEO features

- **Auto meta title & description** – generated from configurable templates
  (`%title%`, `%sitename%`, `%sep%`, `%category%`, `%excerpt%`) and output in
  `<head>` (with Open Graph tags).
- **Open Graph + Twitter cards** – automatically outputs `og:image`,
  `twitter:card` (summary_large_image) and `twitter:image` using the featured
  image, the first content image, or a per-post override (metabox + CSV import).
- **Per-post override** – a metabox on every post lets you set a custom title/
  description/image; leaving it blank uses the generated value.
- **Bulk generate** – the "Generate SEO meta" action fills every post/page
  missing a title or description, batched and progress-tracked.
- **Auto on save** – SEO meta is generated automatically when a post is saved
  (toggleable).

## CSV import (client spreadsheet)

The developer can upload a CSV the client provides. A **Site base URL** field
(pre-filled with this site) is used to resolve bare filenames or relative
paths in the spreadsheet (e.g. `foo.jpg` or `/wp-content/uploads/foo.jpg`
becomes `https://site/wp-content/uploads/foo.jpg`) before matching.

- **Alt text mode** — columns `image` (URL / relative path / filename) and
  `alt`. Matches the media-library attachment by URL (or file basename) and
  sets its alt text.
- **SEO mode** — columns `post` (URL / relative path / ID / slug), `title`,
  `description`, and optional `image`. Sets the matching post's SEO
  title/description/social image meta.

Upload via **Settings > Alt Text Fixer > Apply client spreadsheet**. Applied
rows are reported back as an admin notice.

Two **Download template** buttons generate correctly-formatted CSVs (with a
header row and an example row) to send to the client's developer:
`atf-alt-template.csv` (`image`,`alt`) and `atf-seo-template.csv`
(`post`,`title`,`description`,`image`).

## Theme file audit (read-only)

A "Run theme audit" button scans the active theme's PHP templates (child +
parent) for hard-coded `<img>` without alt and CSS `background-image` usages
that the plugin cannot fix from the database. It produces a file/line report so
the developer knows exactly what to edit manually. It never writes to theme
files.

## Hands-off automation

- **Daily auto-fix (WP-Cron)** – enable "Daily auto-fix schedule" in the
  settings. A daily cron event fixes a slice of each scope (library, content,
  meta, CSS, widgets/customizer, SEO) so images/posts added later are fixed
  without any admin action. Each run is sliced to avoid timeouts and the event
  is created/removed automatically with the setting.
- **Smarter alt text** – when an attachment has no usable title or filename,
  the plugin derives alt from the **post context** (the attachment's parent
  post, or the first published post that embeds the image) instead of leaving a
  generic value.
- **Canonical URL** – the SEO module emits `<link rel="canonical">` (defaults to
  the post permalink) to prevent duplicate-content issues. Filterable via
  `atf_canonical_url`.
- **Dashboard widget** – an "Alt Text Fixer status" widget on the WordPress
  dashboard shows the remaining counts per scope at a glance, with a link to
  the settings page.
- **WP-CLI** – for hosts where WP-Cron is unreliable or for one-shot bulk runs
  on huge sites:
  - `wp atf status` — remaining counts per scope
  - `wp atf fix-all [--scope=<library|content|meta|css|global|all>] [--batch=<n>]`
  - `wp atf seo [--batch=<n>]` — generate missing SEO meta (and notify engines)
  - `wp atf audit` — scan theme files (read-only)
- **Search-engine ping** – when the SEO bulk run finishes, the plugin pings
  Pingomatic with the sitemap URL (auto-detected for core/Yoast/Rank Math) so
  crawlers pick up the new meta. The WP-CLI `wp atf seo` does the same.

## Installation

1. Copy the `alt-text-fixer` folder into `wp-content/plugins/`.
2. Activate **Alt Text Fixer** from the Plugins screen.
3. Go to **Settings > Alt Text Fixer** to configure generation options.

## How it works

Alt text is stored in the `_wp_attachment_image_alt` post meta (the standard
location WordPress and page builders read from). Generated text is humanized
(dashes/underscores become spaces, words capitalized) so it reads naturally.

SEO meta is cached in `_atf_seo_title` / `_atf_seo_desc` post meta, so it is
editable per-post and visible to crawlers and other SEO tools.

## Notes & limits

- Alt text is generated from existing metadata; it does not caption image
  content. For AI descriptions, hook the `atf_generate_alt` filter
  (`Alt_Text_Fixer::generate_alt_text`) into a vision API.
- CSS background images in an **external stylesheet file** (not inline `<style>`)
  cannot be reached from the database and are out of scope; those need a theme
  edit.
- Hard-coded `<img>` in theme PHP template files is also out of DB scope; the
  meta/options scanner does not touch theme code.

## Scaling to very large sites

All batch processors read/fix items in chunks via `wp_ajax_*` endpoints, so a
single page load never processes the whole site. The queue is recomputed from
the database each batch, so it safely resumes after Stop and picks up new
content added mid-run.

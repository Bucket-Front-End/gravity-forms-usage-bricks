# Gravity Forms Usage for Bricks

A lightweight admin utility that shows where each Gravity Form is used
across:

-   Bricks pages
-   Bricks templates
-   Standard WordPress post content (shortcodes + blocks)

Built for agencies and content teams who need quick visibility into form
usage without digging through pages manually.

Unlike many usage tracking plugins, scans run **on demand and cache results**, preventing constant background queries on production sites.

------------------------------------------------------------------------

## Why This Exists

If you work with Gravity Forms and Bricks, you've probably run into
this:

-   "Where is this form used?"
-   "Can we delete this form?"
-   "Why did conversions drop?"
-   "Which templates reference this?"

Gravity Forms does not provide native usage tracking, and most solutions
are either expensive or not builder-aware.

This plugin scans your site and shows:

-   Pages using a form
-   Bricks templates referencing a form
-   Usage source (post content vs Bricks meta)
-   Publish status
-   Direct edit links

All inside WordPress admin.

------------------------------------------------------------------------

## Features

-   ✅ Detects `[gravityform id="X"]` shortcodes
-   ✅ Detects Gravity Forms Gutenberg block usage
-   ✅ Detects Bricks builder form references via `_bricks_%` meta
-   ✅ Groups results by post type
-   ✅ Shows publish status
-   ✅ One-click "Copy links"
-   ✅ On-demand scan
-   ✅ Cached results (12-hour transient)
-   ✅ Automatic cache invalidation on post/meta update
-   ✅ Modern admin UI
-   ✅ Dedicated "Form Usage" admin page
-   ✅ Also visible directly inside Gravity Forms → Settings

------------------------------------------------------------------------

## How It Works

The plugin scans:

-   `wp_posts.post_content`
-   `wp_postmeta.meta_key LIKE '_bricks_%'`

It matches:

-   Gravity Forms shortcode patterns
-   Gravity Forms block JSON
-   Bricks meta values referencing form IDs

Results are cached for performance and invalidated when content updates.

------------------------------------------------------------------------

## Installation

1.  Download or clone this repository.

2.  Upload the plugin folder to:

        wp-content/plugins/gravity-forms-usage-bricks/

3.  Activate via **Plugins → Installed Plugins**

4.  Go to:

    -   **Forms → Form Usage**
    -   or **Tools → Form Usage**

------------------------------------------------------------------------

## Requirements

-   WordPress 6.0+
-   Gravity Forms active
-   Bricks Builder (for Bricks-specific detection)
-   PHP 8.0+

------------------------------------------------------------------------

## Limitations

-   Forms rendered via custom PHP (`gravity_form()` in theme/plugin
    files) cannot be detected.
-   Extremely custom builder integrations may not store form IDs in
    detectable meta.
-   Multisite not deeply tested (transients are blog-scoped).

------------------------------------------------------------------------

## Performance

-   Scans are manual or cache-based.
-   Results are cached for 12 hours.
-   Cache auto-clears when posts or Bricks meta update.
-   Does not run heavy queries on every page load.

------------------------------------------------------------------------

## Custom Capability

By default, the plugin uses:

    gravityforms_edit_forms

You can override this with:

``` php
add_filter('gffu_capability', function() {
    return 'edit_pages';
});
```

------------------------------------------------------------------------

## Roadmap Ideas

-   Usage count column in Gravity Forms list
-   Reverse lookup (Page → Which forms are used here?)
-   Bulk scan all forms
-   Elementor compatibility
-   Export to CSV

------------------------------------------------------------------------

## Author

Built by Adam Pedersen\
Frontend-focused technical lead building workflow tooling for agencies.

------------------------------------------------------------------------

## License

GPLv2 or later.


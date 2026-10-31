=== QuantCDN ===
Contributors: stooit, quantcdn
Donate link: https://www.quantcdn.io/
Tags: static, cdn, static site generator, jamstack, search
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish a static copy of your WordPress site to the QuantCDN edge, keep it in sync automatically, purge the cache, and add Quant Search pages.

== Description ==

QuantCDN is a CDN engineered for the static web. This plugin works in two modes:

* **Static Site**: every page, listing, file and redirect is rendered and published to the Quant edge. Content changes are pushed automatically. Visitors never reach WordPress.
* **CDN Only**: WordPress serves pages behind Quant, and the plugin purges the Quant cache when content changes.

Static mode publishes:

* Posts, pages and public custom post types
* The home page, blog listing, category, tag and custom taxonomy pages, author pages, date and post type archives, including every paginated page
* Media library files with all image sizes, theme assets, and every stylesheet, script, image or document a page references
* Custom routes, the 404 page, robots.txt and sitemaps (WordPress core, Yoast SEO, Rank Math)
* Redirects from the Redirection plugin and from changed post slugs
* Quant Search records, so the Quant Search pages and autocomplete box work on the static site

Also included:

* A React admin under **QuantCDN** with a dashboard, content browser, cache purge, search configuration, settings and diagnostics
* Sync status in the post lists and the admin bar, with a "Push to Quant" row action
* Login with your Quant account to pick a project, or paste the project credentials
* QuantGov support: set the endpoint to `https://api.quantgov.cloud`
* WP-CLI commands for scripted pushes and purges, with parallel workers
* Background processing with WP-Cron and an optional scheduled re-push
* Configuration from `QUANT_*` environment variables for Quant Cloud

The QuantCDN platform provides [forms](https://docs.quantcdn.io/docs/dashboard/forms) and [search](https://docs.quantcdn.io/docs/dashboard/search) for static sites.

Requires a [QuantCDN account](https://www.quantcdn.io).

== Installation ==

1. Install from the Plugins screen or upload the `quant` directory to `/wp-content/plugins/`.
2. Activate the plugin.
3. Open **QuantCDN** in the admin menu. Log in to Quant and choose a project, or enter the organisation, project and token from the Integrations page of the Quant dashboard.
4. Choose the mode. In Static mode, check **Internal connectivity** under Settings, then open **Content** and press **Push everything**.

== Upgrading from 1.x ==

Version 2.0 keeps your settings. On the first page load after the update the plugin copies the 1.x settings (API credentials, automatic push, cache purge on save, webserver URL and host, 404 route, custom routes, relative rewrite domains and cron settings) to the new format. Content pushes continue to work without any action. A notice links to a review page.

The 1.x WP-CLI commands `wp quant info`, `wp quant reset_queue <queue|all>` and `wp quant process_queue <queue|all> --threads=N` keep working.

Version 2.0 requires PHP 8.1. Sites on older PHP are not offered the update and stay on 1.6.

== WP-CLI ==

* `wp quant status` - connection, mode and queue counts
* `wp quant sync --all --process` - queue the whole site and push it
* `wp quant sync --type=page` - queue one content type (`post`, `page`, `media`, `taxonomy`, `home`, `archives`, `authors`, `theme_assets`, `custom_routes`, `sitemaps`, `redirects`, `search`, or a custom post type)
* `wp quant sync 123 456 --process` - push specific posts
* `wp quant queue process --all --threads=5` - process the queue with parallel workers
* `wp quant queue status|retry|clear|errors`
* `wp quant purge /path` and `wp quant purge --all`
* `wp quant unpublish /path`
* `wp quant search --clear --process` - rebuild the Quant Search index

== Environment variables ==

Set these to configure the plugin without the admin UI (for example on Quant Cloud): `QUANT_API_ENDPOINT`, `QUANT_CUSTOMER`, `QUANT_PROJECT`, `QUANT_TOKEN`, `QUANT_WEBSERVER_URL`, `QUANT_WEBSERVER_HOST`, `QUANT_ENABLED`, `QUANT_PURGE_ON_SAVE`, `QUANT_DISABLE_TLS_VERIFY`, `QUANT_HTTP_REQUEST_TIMEOUT`, `QUANT_MODE`. The same names work as PHP constants in `wp-config.php`. Overridden fields are read-only in the UI.

== Frequently Asked Questions ==

= Where do I find the project credentials? =

In the Quant dashboard, open the project and go to Integrations. Copy the organisation (API customer), project name and token.

= The internal connectivity test fails =

The plugin renders pages by requesting them from the web server. Set **Webserver URL** to an address the PHP process can reach (for example `http://localhost`) and **Hostname** to the public domain. In a container, WP-CLI runs without a web server, so point the Webserver URL at the web container.

= How do I use Quant Search? =

Enable search for the project in the Quant dashboard, then open **QuantCDN > Search**, turn on search records and create a search page. Place the "Quant Search" block or `[quant_search]` shortcode on any page for more search pages, and the "Quant Search Autocomplete" block or `[quant_search_autocomplete]` shortcode in a header or sidebar.

= Forms on a static site =

Follow the [Forms documentation](https://docs.quantcdn.io/docs/dashboard/forms). Contact Form 7 needs `define('WPCF7_LOAD_JS', false);`.

== Changelog ==

= 2.0.0 =
* Complete rewrite: React admin, service container, REST API, WP-Cron queue.
* New CDN Only mode for sites that keep WordPress as the origin.
* Publishes paginated listings, author pages, date archives, sitemaps and referenced assets automatically.
* Captures slug changes as redirects and unpublishes removed content reliably.
* Quant Search: records with every push, search pages with facets, autocomplete, index rebuild.
* Sync status column, admin bar item and "Push to Quant" row action.
* QUANT_* environment variable and constant overrides.
* QuantGov dashboard support for the Quant login.
* Automatic migration of 1.x settings; 1.x WP-CLI commands kept as aliases.
* Requires PHP 8.1.

= 1.6.0 =
* Feature: Added new Cache tab for manual CDN cache purging (purge all or selective paths).
* Feature: Added "Cache purge on save" option to automatically purge CDN cache when content changes.
* Feature: Renamed "Quant Enabled" to "Automatic static push" for clarity.
* Feature: Automatic trailing slash handling - purges both /path and /path/ variants.
* Improvement: Enhanced error handling and validation for cache purge operations.
* Improvement: Cache purge now works independently of automatic static push.
* Tested with WordPress 6.8.2.
* Updated minimum PHP requirement to 7.4 (previously stated 8.1).

== Upgrade Notice ==

= 2.0.0 =
Major release. Settings migrate automatically. Requires PHP 8.1. Review QuantCDN > Settings after the update and run "Push everything" once so listings, sitemaps and redirects are published with the new engine.

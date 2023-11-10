=== QuantCDN ===
Contributors: stooit
Donate link: https://www.quantcdn.io/
Tags: static, jamstack, cdn, quant, static site generator
Requires at least: 4.6
Tested up to: 6.4.1
Requires PHP: 8.1
Stable tag: 1.5.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

QuantCDN static site generator and edge integration. Push a static export of your Wordpress site with ease.

== Description ==

QuantCDN is a CDN engineered specifically for the static web. This plugin acts as a static site generator, letting you easily push a static copy of your entire Wordpress site with a single click. Any ongoing content change is automatically pushed, ensuring your static site in the QuantCDN edge is always kept in sync.

Full support for:
* Pages and Posts
* Homepages (static or lists), including pagination
* Archives (including pagination)
* 404 error page
* Custom routes
* Tags and Categories (including pagination)
* Attached images & media, CSS, Javascript
* Custom post and page types

The QuantCDN platform has integrated support for both [forms](https://docs.quantcdn.io/docs/dashboard/forms) and [search](https://docs.quantcdn.io/docs/dashboard/search).

Requires a [QuantCDN account](https://www.quantcdn.io)

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/quant` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen
3. Configure on the Settings > QuantCDN screen, as per https://docs.quantcdn.io/docs/integrations/wordpress

== WP-CLI support ==

WP-CLI is a command line tool to interface with Wordpress. It is the recommended way of initially seeding your Wordpress site in Quant, and provides vast performance improvements over using the UI.

This is largely due to the ability to run the seed process with concurrency, which can immediately give a 10x performance boost (or greater) when compared to using the UI.

* Use `wp quant info` to view queue status
* Use `wp quant reset_queue <queue_name>` to reset a queue
* Use `wp quant process_queue <queue_name|all> --threads=10` to push content to Quant

== Frequently Asked Questions ==

= Where do I register for an account? =

Visit the [QuantCDN dashboard](https://dashboard.quantcdn.io/register) to register.

= Where do I retrieve the project key from? =

Visit the "Integrations" section in the QuantCDN dashboard to retrieve token and other required details.

= How do I use Forms? =

Follow the [Forms documentation](https://docs.quantcdn.io/docs/dashboard/forms) to configure forms via the Quant Dashboard. Form submission data can be received via email or Slack, as well as directly via the Quant Dashboard.

**Note**: Contact Form 7 requires a small [configuration change](https://docs.quantcdn.io/docs/dashboard/forms#contact-form-7-support-wordpress).

== Screenshots ==

== Changelog ==

= 1.5.1 =
* Tested on latest versions of WordPress.

= 1.5.0 =
* Improved support for PHP 8.1 and 8.2.

= 1.4.2 =
* Feature: Improved support for redirection plugin (add/update/delete tracking).
* Tested with WordPress 6.1.1.

= 1.4.1 =
* Feature: Added timestamp_published value to search.
* Tested with WordPress 6.0.1.

= 1.4.0 =
* Feature: Add ability to push all content at once via the UI.
* Feature: Improved support for Divi Builder.
* Feature: Added support for redirection plugin.
* Bugfix: Better support for additional custom post types via later action weight.
* Bugfix: Strip whitespace from custom routes.
* Bugfix: Resolve cron schedule being added multiple times.
* Bugfix: Resolve issues with some routes when run via cli.

= 1.3.5 =
* Tested on WordPress 6.0.
* Bugfix: Resolve homepage lookup with multi-site blogs.
* Bugfix: Resolve archives URLs in some setups.

= 1.3.4 =
* Improved support when using third party plugins (e.g Muffin Builder).
* Bugfix: Resolve relative path lookup when running on a non-standard port.

= 1.3.3 =
* Bugfix: wp-cli fixes on some platforms.
* Bugfix: Issues with case sensitive filesystems.

= 1.3.2 =
* Improved multisite support: Quant Search (filter content by site)
* Multisite bugfix: Resolved some media assets not seeding correctly
* Added configurable HTTP timeout value

= 1.3.1 =
* Adds option to disable SSL verification if required.
* Tested up to Wordpress 5.9.1

= 1.3.0 =
* Adds wp-cli support for vastly improved seeding of sites (with controllable concurrency).
* Use `wp quant info` to view queue status
* Use `wp quant reset_queue <queue_name>` to reset a queue
* Use `wp quant process_queue <queue_name|all> --threads=10` to process one queue (or all) with concurrent threads

= 1.2.3 =
* Improved theme asset regex.
* Make content routes consistent (strip trailing slash).

= 1.2.2 =
* Improved theme asset lookup to exclude node_modules.
* Improved support for Elementor.
* Bugfix: Resolved issue with detection of external assets.

= 1.2.1 =
* New feature: Additional support for relative asset rewriting.
* Simplified settings screen.

= 1.2.0 =
* New feature: Improved support for Elementor.
* New feature: Support seeding entire media library.
* New feature: Added improved validation to settings screen.
* Bugfix: Resolved issue where content was not republished when restoring from trash.

= 1.1.0 =
* New feature: Support for custom post types and taxonomies.
* New feature: Support custom binary routes.
* Bugfix: Ensure content is unpublished in Quant when state changes.

= 1.0.2 =
* Performance improvement with added concurrency.

= 1.0.1 =
* Added support for QuantSearch.
* Ensure API calls use the /v1 prefix.
* Added `author_name` to posted metadata.

= 1.0 =
Initial release.

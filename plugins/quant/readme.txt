=== QuantCDN ===
Contributors: stooit
Donate link: https://www.quantcdn.io/
Tags: static, jamstack, cdn, quant, static site generator
Requires at least: 4.6
Tested up to: 5.7.2
Requires PHP: 7.2
Stable tag: 1.2.1
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

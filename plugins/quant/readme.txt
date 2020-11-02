=== QuantCDN ===
Contributors: stooit
Donate link: https://www.quantcdn.io/
Tags: static, jamstack, cdn, quant
Requires at least: 4.6
Tested up to: 5.5.1
Requires PHP: 7.2
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

QuantCDN static edge integration. Push a static export of your Wordpress site.

== Description ==

QuantCDN is a CDN engineered specifically for the static web. With the QuantCDN plugin you can push a static copy of your entire Wordpress site with a single
click. Any ongoing content change is automatically pushed, ensuring your static edge is always kept in sync.

Full support for:
* Pages and Posts
* Homepages (static or lists), including pagination
* Archives (including pagination)
* 404 error page
* Custom routes
* Tags and Categories (including pagination)
* Attached images & media, CSS, Javascript

Requires a [QuantCDN account](https://www.quantcdn.io)

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/quant` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen
3. Configure on the Settings > QuantCDN screen, as per https://docs.quantcdn.io/docs/integrations/wordpress

== Frequently Asked Questions ==

= Where do I register for an account? =

Visit the [QuantCDN dashboard](https://dashboard.quantcdn.io/register) to register.

= Where do I retrieve the project key from? =

Visit the "Projects" section in the QuantCDN dashboard to view project tokens.

== Screenshots ==

== Changelog ==

= 1.0.1 =
* Added support for QuantSearch.
* Ensure API calls use the /v1 prefix.
* Added `author_name` to posted metadata.

= 1.0 =
Initial release.

=== Surge ===
Contributors: kovshenin
Donate link: https://github.com/kovshenin/surge
Tags: cache, performance, caching
Requires at least: 5.7
Tested up to: 6.6
Requires PHP: 7.3
Stable tag: 1.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.en.html

Surge is a very simple and fast page caching plugin for WordPress.

== Description ==

Surge generates and serves static HTML files for your WordPress site, causing quicker requests, faster load times and a shorter time to first byte (TTFB).

Surge does not require configuration, and has no options. It works out of the box on any well-configured hosting platform. Cached files are stored on disk, and automatically invalidated when your site is updated.

In various load tests, Surge has shown to easily handle 1000-2500 requests per second at 100 concurrent, on a small single-core server with only 1 GB of RAM. That's over 70 times faster than a stock WordPress install.

== Installation ==

Via the WordPress Dashboard: navigate to Plugins - Add New. In the search bar type "surge" and hit Enter. Find the Surge plugin in the search results, hit Install, then Activate.

Manually: download the Surge plugin .zip file from WordPress.org. In your WordPress admin navigate to Plugins - Add New - Upload. Select the .zip file and hit Upload. Activate the plugin after upload is successful.

Manually via FTP: download the Surge plugin .zip file from WordPress.org, extract the archive, make sure the directory is called "surge". Use your FTP/SFTP client to upload the "surge" directory to wp-content/plugins. Then activate the plugin in your WordPress admin from the Plugins section.

Using WP-CLI: wp plugin install surge --activate

== Frequently Asked Questions ==

= Where is the plugin configuration screen? =

There isn't one.

= How do I clear the cache? =

Toggle the plugin activation or run `wp surge flush` using WP-CLI.

= Is my cache working? =

Visit the Site Health screen under Tools in your WordPress dashboard. Common caching errors, like installation problems, etc. will appear there. Otherwise, open your site in an Incognito window to see the cached version. You could also look for the "X-Cache" header in the server response.

= Why am I getting cache misses? =

Below are a few common reasons:

* You are logged into your WordPress site
* You have a unique cookie set in your browser
* A unique query parameter will also cause a cache miss, except common marketing parameters, such as utm_campaign, etc.
* Request methods outside of GET and HEAD are not cached

= Can I exclude page X from being cached? =

Of course. If you pass a "Cache-Control: no-cache" header (or max-age=0) the request will automatically be excluded from cache. Note that most WordPress plugins will already do this where necessary.

= fpassthru() has been disabled for security reasons =

It seems like your hosting provider disabled the fpassthru() function, likely by mistake. This is a requirement for Surge. Please get in touch with them and kindly ask them to enable it.

= How can I support Surge? =

If you like Surge, consider giving us a [star on GitHub](https://github.com/kovshenin/surge) and a review on WordPress.org.

== Changelog ==

= 1.1.0 =
* Improved Multisite compatibility
* Fixed occasional stat() warnings in cleanup routines
* Fixed expiration by path being too broad
* Added a filter for flush actions
* Feature: added a simple events system for s-maxage and stale-while-revalidate support

= 1.0.5 =
* Fix woocommerce_product_title compatibility
* Honor DONOTCACHEPAGE constant
* Use built-in is_ssl() WordPress function for better compatibility

= 1.0.4 =
* Add a WP-CLI command to invalidate/flush page cache
* Fix redirect loop with Core's redirect_canonical for ignore_query_vars
* Fix warnings for requests with empty headers
* Fix warnings when cron cleanup attempts to read a file that no longer exists
* Add a filter to disable writing to wp-config.php

= 1.0.3 =
* Invalidate cache when posts_per_page is changed
* Fix redirect loop with unknown query vars caused by Core's redirect_canonical
* Ignore X-Cache and X-Powered-By headers from cache metadata
* Allow multiple headers with the same name

= 1.0.2 =
* Fix PHP notice in invalidation
* Protect against race conditions when writing flags.json
* Add support for more post statuses in transition_post_status invalidation

= 1.0.1 =
* Add support for custom user configuration
* Various invalidation enhancements and fixes
* Remove advanced-cache.php when plugin is deactivated
* Add a note about fpassthru() in FAQ
* Minor fix in Site Health screen tests

= 1.0.0 =
* Anonymize requests to favicon.ico and robots.txt
* Improve cache expiration, add cache expiration by path

= 0.1.0 =
* Initial release

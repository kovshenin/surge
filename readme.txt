=== Surge ===
Contributors: kovshenin
Donate link: https://github.com/kovshenin/surge
Tags: cache, performance, caching
Requires at least: 5.8
Tested up to: 5.8.2
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.en.html

Surge is a very simple and fast page caching plugin for WordPress.

== Description ==

Surge generates and serves static HTML files for your WordPress site, causing
quicker requests and, faster load times and a shorter time to first byte (TTFB).

Surge does not require configuration, and has no options. It works out of the box
on any well-configured hosting platform. Cached files are stored on disk, and
automatically invalidated when your site is updated.

In various load tests, Surge has shown to easily handle 1000-2500 requests per
second at 100 concurrent, on a small single-core server with only 1 GB of RAM.
That's over 70 times faster than an empty WordPress install.

== Installation ==

1. Navigate to Plugins - Add New, find "Surge", hit Install, then Activate
1. Enjoy

== Frequently Asked Questions ==

= Can I exclude page X from being cached? =

Of course. If you pass a "Cache-Control: no-cache" header (or max-age=0) the request
will automatically be excluded from cache. Note that most WordPress plugins will
already do this where necessary.

= How can I support Surge? =

If you like Surge, consider giving us a [star on GitHub](https://github.com/kovshenin/surge).

== Changelog ==

= 0.1.0 =
* Initial release

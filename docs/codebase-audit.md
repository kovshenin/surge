# Surge Codebase Audit

## Purpose

This repository contains the current open-source implementation of the Surge WordPress plugin: a disk-backed full-page cache that installs an `advanced-cache.php` drop-in, serves cached responses before WordPress fully boots, and invalidates cached pages when content or key site settings change.

The codebase is deliberately small and operationally opinionated:

- No admin settings screen
- No JavaScript build, frontend assets, or external package manager
- No test suite
- No persistence layer besides the filesystem and WordPress options
- No SaaS/backend dependency

That simplicity is the product's main strength today, but it also defines the upper bound of what the current plugin can explain, control, and optimize for site owners.

## Repository Structure

| Path | Role |
| --- | --- |
| `surge.php` | Main plugin bootstrap. Conditionally loads cache writer, registers installers, Site Health integration, cron scheduling, and deactivation hooks. |
| `include/advanced-cache.php` | WordPress drop-in entrypoint loaded early when `WP_CACHE` is enabled. |
| `include/serve.php` | Cache read path. Resolves cache keys, reads cache files, checks expiry/invalidation flags, serves hits, and emits `X-Cache` headers. |
| `include/cache.php` | Cache write path. Captures full output buffer, decides whether a response is cacheable, writes metadata + HTML to disk, and emits request events. |
| `include/common.php` | Shared primitives: config loading, cache-key generation, flag storage helpers, metadata parsing, request anonymization, event dispatching, and status tracking. |
| `include/invalidate.php` | Runtime invalidation logic. Tags requests with flags and writes expirations to `flags.json.php` during shutdown. |
| `include/install.php` | First-run installer. Copies the drop-in, creates cache directories, and attempts to write `WP_CACHE` into `wp-config.php`. |
| `include/cron.php` | Scheduled cleanup of expired or malformed cache files. |
| `include/cli.php` | WP-CLI surface for flushing cache and reading cache size/count. |
| `include/health.php` | Site Health test that reports installation and writability issues. |
| `uninstall.php` | Removes the drop-in, recursively deletes cache files, and clears plugin state on uninstall. |
| `readme.txt` | WordPress.org distribution metadata and user-facing documentation. |
| `.wporg/*` | WordPress.org plugin assets only. |

Approximate code footprint: 1,339 lines across plugin PHP files and `readme.txt`.

## Execution Model

Surge has two critical execution paths.

### 1. Read path: serve a cached response as early as possible

When `WP_CACHE` is enabled, WordPress loads `wp-content/advanced-cache.php` before most of core finishes booting. Surge installs a drop-in that points to `include/serve.php`.

`include/serve.php` then:

1. Initializes a request status of `miss`
2. Builds a normalized cache key from the request
3. Hashes the key with `md5(json_encode($key))`
4. Looks for a cache file under `wp-content/cache/surge/<last-two-hash-chars>/<hash>.php`
5. Reads metadata from the file header
6. Rejects entries that are expired or invalidated by newer flags
7. Restores the original response code and headers
8. Sends `X-Cache: hit`
9. Streams the HTML body to the client

If any guard fails, the request falls through to normal WordPress execution.

### 2. Write path: capture a generated response and save it

If the plugin loads during a regular request and `WP_CACHE` is enabled, `surge.php` includes `include/cache.php`, which installs an output-buffer callback.

That callback:

1. Checks cache TTL and several cache-bypass conditions
2. Collects response headers
3. Rejects requests with `Set-Cookie`, `Authorization`, non-cacheable methods, unsupported status codes, `DONOTCACHEPAGE`, or explicit `Cache-Control: no-cache|max-age=0`
4. Builds the same normalized cache key used by the read path
5. Stores metadata plus raw HTML into a PHP file on disk
6. Atomically renames the temporary file into place
7. Emits a `request` event for custom integrations

This is a classical full-page static cache with lazy invalidation: cache files remain on disk until cleaned up, but are treated as invalid if newer expiration flags exist.

## Request Identity and Cache Keys

The cache key is generated in `include/common.php` and includes:

- `https`: whether WordPress considers the request SSL
- `method`: uppercased HTTP method
- `host`: lowercased host header
- `path`: URL path
- `query_vars`: parsed query string after removing ignored marketing parameters
- `cookies`: all cookies except ignored ones and underscore-prefixed client-side cookies
- `variants`: arbitrary config-driven variant values

Important characteristics:

- Marketing parameters such as `utm_*`, `fbclid`, `gclid`, `msclkid`, and many others are stripped from the key.
- When ignored query vars are removed, Surge also mutates `$_SERVER['REQUEST_URI']`, `$_REQUEST`, and `$_GET` so later WordPress logic sees the cleaned request.
- Requests to `/robots.txt` and `/favicon.ico` are anonymized: cookies and request arrays are cleared so those paths can be shared across visitors.
- Logged-in or personalized traffic is not explicitly detected by role; it is simply separated by cookies. That preserves correctness, but it also fragments the cache heavily for cookie-rich sites.

## On-Disk Format

Each cache file is a PHP file with three parts:

1. A fixed security header: `<?php exit; ?>`
2. A 4-byte packed integer containing metadata length
3. JSON metadata, followed by the cached HTML body

Stored metadata includes:

- HTTP status code
- Response headers
- Creation timestamp
- Expiration timestamp
- Request flags used for invalidation
- Original request path

This format keeps files executable-safe if requested directly while remaining cheap to parse.

## Configuration Surface

There is no admin configuration UI. Configuration comes from PHP only.

### Built-in config keys

Defined in `include/common.php`:

- `ttl`: default `600`
- `ignore_cookies`: default `['wordpress_test_cookie']`
- `fpassthru_alt`: default `false`
- `ignore_query_vars`: long allowlist of marketing/tracking params
- `variants`: default `[]`
- `events`: default `[]`

### Configuration channels

Surge supports three ways to alter config:

1. A `WP_CACHE_CONFIG` PHP file that returns config values
2. `SURGE_*` constants such as `SURGE_TTL`
3. Select WordPress filters:
   - `surge_skip_config_update`
   - `surge_flush_actions`

This is developer-friendly, but non-technical site owners have no discoverable settings surface.

## Invalidation Model

Surge does not eagerly delete all related cache files when content changes. Instead, it uses request-time tagging plus shutdown-time expiration flags.

### Request tagging

During uncached page generation, Surge records flags such as:

- `post:<blog_id>:<post_id>` for posts present in query results
- `post_type:<post_type>` for public archive/list contexts
- `feed:<blog_id>` for feed requests
- `network:<network_id>:<blog_id>` on multisite

WooCommerce compatibility is handled by tagging pages when `woocommerce_product_title` runs, because some WooCommerce query flows bypass the usual post-query hooks.

### Expiration triggers

On shutdown, Surge writes expiration timestamps to `wp-content/cache/surge/flags.json.php` when events happen, including:

- Post cache cleanup
- Post status transitions that enter or leave public visibility
- Feed-related option changes
- Plugin activation/deactivation
- Theme switching/customizer save
- Permalink and front-page option changes
- Selected WooCommerce permalink changes
- Core update completion events in multisite/network contexts

### Invalidation check on read

When a cached file is read, Surge compares its `created` timestamp and flags against `flags.json.php`.

- Path-style flags beginning with `/` invalidate matching path prefixes
- Semantic flags such as `post_type:post` or `post:1:42` invalidate entries carrying those tags

This design is fast to write and simple to reason about, but it provides only coarse visibility into why a page was invalidated.

## Supported and Unsupported Cache Outcomes

### Cacheable today

- `GET` and `HEAD` requests
- Response codes `200`, `301`, `302`, and `404`
- Anonymous or cookie-keyed traffic
- Static page responses that do not emit `Set-Cookie`

### Explicit bypasses today

- `Authorization` header present
- Methods other than `GET`/`HEAD`
- `Set-Cookie` in the response
- `Cache-Control: no-cache` or `max-age=0`
- `DONOTCACHEPAGE === true`
- Global TTL below `1`

The plugin emits `X-Cache: miss`, `hit`, `expired`, or `bypass`, but it does not store structured reason codes or counters for later inspection.

## Installation and Lifecycle

### First install

On `plugins_loaded`, `surge.php` checks `surge_installed`.

If the option does not exist, it:

1. Creates `surge_installed = 0`
2. Includes `include/install.php`
3. Removes any existing `advanced-cache.php`
4. Copies Surge's drop-in into `wp-content/advanced-cache.php`
5. Creates the cache directory
6. Attempts to enable `WP_CACHE` in `wp-config.php` unless already enabled or filtered out
7. Stores install result codes via `surge_installed`

### Activation

Activation simply clears `surge_installed`, forcing reinstall logic on the next load.

### Deactivation

Deactivation clears `surge_installed` and removes `advanced-cache.php` only if the file appears to be Surge-owned.

### Uninstall

Uninstall removes the drop-in, recursively deletes the cache directory, and deletes the plugin option.

## Operational Features

### WP-CLI

Current commands:

- `wp surge flush`
- `wp surge flush --delete`
- `wp surge status`

This is the only first-party operator control surface besides activation toggling and Site Health.

### Cron cleanup

An hourly event `surge_delete_expired` is scheduled from `shutdown` if it is missing.

The job scans the cache directory, ignores very recent files, deletes empty files, and removes entries whose metadata is absent or expired.

### Site Health

The plugin adds one direct Site Health test. It checks:

- Install state via `surge_installed`
- `WP_CACHE` enablement
- Presence and ownership of `advanced-cache.php`
- Cache directory writability

This is helpful for support, but it is still a binary health surface rather than an operational dashboard.

## Extension Points

The open-source plugin already exposes some low-level hooks that are important for a future pro strategy.

### Filters

- `surge_skip_config_update`
- `surge_flush_actions`
- `site_status_tests`
- `site_status_page_cache_supported_cache_headers`

### Config-driven event callbacks

`config('events')` supports callbacks for event names. The code currently emits:

- `request`
- `expire`

This is flexible enough to support add-on instrumentation, but only for developers comfortable injecting PHP configuration.

### Variants

`config('variants')` is appended to the cache key. That means the core already supports arbitrary cache segmentation, but only through code-level customization.

## Current UX Surface

From an end-user perspective, the plugin experience is intentionally minimal:

- Install and activate
- No settings screen
- Check Site Health if something seems wrong
- Use `X-Cache` headers or WP-CLI if you know they exist

This makes the plugin low-friction for technical operators, but mostly invisible to typical WordPress admins. There is no built-in explanation of:

- Why a page missed cache
- Which URLs are cached
- How much disk space is being used without CLI
- What invalidations recently happened
- Whether cache efficiency is improving or degrading
- How to safely introduce variants, exclusions, or warmups

## Architecture Strengths

- Very small codebase with low conceptual overhead
- Early-cache read path using WordPress drop-in conventions
- Shared key generation between read and write paths
- Sensible cache bypass behavior for cookies, auth, and no-cache responses
- Practical invalidation tagging for posts, post types, feeds, and multisite
- Safe-enough atomic write pattern for cache files
- Developer extension points via constants, PHP config, filters, and events

## Architecture Constraints

- Filesystem-only storage and cleanup model
- No native analytics, dashboards, or historical metrics
- No first-party preload/warmup queue
- No admin UX for exclusions, variants, or purge targeting
- No built-in CDN integration, edge purge, or remote APIs
- No test coverage protecting invalidation and install edge cases
- No explicit observability around bypass reasons or invalidation causes
- Relies on WordPress/PHP file write permissions for install success
- Configuration is invisible to non-developers because it lives in PHP

## Notable Gaps Visible in Code

These are direct observations from the source, not speculative product ideas:

- There is no `docs/` directory or internal engineering documentation in the current repo.
- There are no unit, integration, or WordPress environment tests.
- There is no admin page, settings API usage, REST route, or Ajax interface.
- There is no built-in metrics persistence for hits, misses, bypasses, or expirations.
- There is no first-party URL-level purge command; flush is global.
- Stale serving and cache warming are not implemented as first-party features, despite the lightweight events system hinting at that direction.
- There is no structured reason model for `bypass` or `expired` states.
- The code contains a few explicit future-looking TODOs around anonymized request handling and `post_type => any` invalidation coverage.

## Summary

Surge today is a lean, developer-oriented full-page cache for WordPress built around:

- a drop-in read path,
- an output-buffer write path,
- file-based storage,
- flag-based invalidation,
- and minimal operator controls.

That is a solid base for a pro version. The core engine already provides the right primitives for request identity, invalidation tags, extension callbacks, and CLI control. What it lacks is productization: visibility, configurability, safer workflows, targeted controls, and premium operational features layered on top of the existing cache engine.

# QuantCDN WordPress plugin 2.0.0

A complete rewrite of the plugin. Existing 1.x sites update in place; see
"Upgrading" below.

## What is new

**Two modes.** *Static Site* publishes the whole site to the Quant edge and
keeps it in sync. *CDN Only* keeps WordPress as the origin and purges the
Quant cache when content changes.

**Complete static output.** A full push now covers everything a visitor can
reach: posts, pages, custom post types, category, tag and custom taxonomy
pages with pagination, the home page and blog listing with pagination, author
pages, date and post type archives, media with every image size, theme
assets, custom routes, the 404 page, robots.txt, and sitemaps (WordPress
core, Yoast SEO, Rank Math). Every stylesheet, script, image and document a
page references is uploaded too, using the asset list the Content API returns
for each page, so plugin and core assets reach the edge without configuration.

**Reliable change tracking.** Saves, publishes, unpublishes, trashes,
deletions, term changes, media changes and Redirection plugin changes queue
the right work. The home page, the post's terms, author page and archive are
refreshed with it. A changed slug becomes a 301 redirect from the old path.
Paths are captured before an object disappears, so unpublishing is exact.

**Background queue.** Jobs are stored in a database table with their data,
deduplicated, processed by WP-Cron every minute and immediately after a
change, by the admin "Process queue" button, or by WP-CLI with parallel
workers. Stuck jobs recover automatically; failed jobs can be retried.

**Quant Search.** Each published page carries a search record (title,
summary, content, image, URL, language, content type and taxonomy terms, the
same shape as the Drupal module). Search pages with facets are configured in
the admin and rendered by the `[quant_search]` shortcode or the "Quant
Search" block; an autocomplete box is available as a shortcode or block. The
WordPress search form can be pointed at a search page so search works on the
static site. The index can be rebuilt or cleared from the admin or WP-CLI.

**Admin.** A React admin under *QuantCDN*: dashboard with queue state and
recent errors, content browser with per-item status and selective push or
unpublish, cache purge, search, settings and diagnostics. The post lists get
a "Quant" status column and a "Push to Quant" row action, and the admin bar
shows the status on single pages.

**Login with Quant.** Connect with your Quant account and pick the project,
or paste the project credentials. QuantGov endpoints use the QuantGov
dashboard for the login.

**Configuration from the environment.** `QUANT_API_ENDPOINT`,
`QUANT_CUSTOMER`, `QUANT_PROJECT`, `QUANT_TOKEN`, `QUANT_WEBSERVER_URL`,
`QUANT_WEBSERVER_HOST`, `QUANT_ENABLED`, `QUANT_PURGE_ON_SAVE`,
`QUANT_DISABLE_TLS_VERIFY`, `QUANT_HTTP_REQUEST_TIMEOUT` and `QUANT_MODE`
override the saved settings, as environment variables or constants. Fields
set this way are read-only in the admin.

## Upgrading from 1.x

- **Requires PHP 8.1.** WordPress does not offer the update to sites on an
  older PHP version; they stay on 1.6. If the plugin files are copied onto an
  old PHP version by hand, the plugin shows a notice and stays inactive
  instead of failing.
- **Settings migrate automatically.** On the first request after the update
  the 1.x options (`wp_quant_settings`, `wp_quant_seed`,
  `wp_quant_cron_settings`) are copied to the new options: API credentials,
  automatic push, cache purge on save, TLS verification, request timeout,
  webserver URL and host, 404 route, custom routes, relative rewrite domains
  and the cron schedule. Pushes keep working without a visit to the admin. A
  dismissible notice links to a review page; the old options stay until you
  choose "Clean up v1 data".
- **WP-CLI.** `wp quant info`, `wp quant reset_queue <queue|all>` and
  `wp quant process_queue <queue|all> --threads=N` keep working alongside the
  new `wp quant status|sync|queue|purge|unpublish|search` commands.
- **Quant Cloud.** Sites configured by the `app-wordpress` template's
  `QUANT_*` variables need no change; the plugin reads the variables itself.
- **Settings URL.** `options-general.php?page=quant` redirects to the new
  page.
- **Recommended after the update.** Run "Push everything" once (or
  `wp quant sync --all --process`) so paginated listings, author pages,
  sitemaps, redirects and referenced assets are published by the new engine.

## Behaviour changes

- After every publish and unpublish the plugin purges both `/path` and
  `/path/`, as 1.6.0 did. Return false from `quant_purge_after_publish` to
  skip it.
- Purge on save applies in CDN Only mode and in Static mode with automatic
  push off, as in 1.x. In CDN Only mode it also purges when a published post
  is unpublished, trashed or deleted, and covers the terms a post was removed
  from. The purge runs once per request, after the response is sent.
- When several changes to the same item wait in the queue, the latest one
  wins. Publishing, unpublishing and publishing again leaves the page online.
- A custom route, archive or listing that now returns 404 or 410 is
  unpublished from Quant, as in 1.x, instead of being reported as a failure.
- Only static files are uploaded: known asset, media and document
  extensions, never PHP, configuration, package-manager or hidden files.
  Extend the list with `quant_public_file_extensions`.
- Files are uploaded through the WordPress HTTP API. A file larger than
  256 MB (`quant_max_upload_bytes`), or one that would not fit in PHP memory,
  is reported as failed instead of stopping the queue. Upload very large
  media with the Quant CLI.
- Failed jobs are retried automatically up to three times with a growing
  delay. A job that keeps stopping PHP (timeout or memory) is marked failed
  after three attempts. Completed rows older than a week are pruned, keeping
  the latest row for each item.
- On multisite, only network administrators can change the Webserver URL and
  Host header.
- The InstantSearch and Autocomplete libraries are bundled with the plugin;
  nothing loads from a third-party CDN.
- Absolute links to the site are rewritten as relative links only in HTML.
  XML sitemaps and robots.txt keep absolute URLs, which the sitemap protocol
  requires. Set the Hostname (Host header) to the public domain so those URLs
  are correct.
- The 404 page is published at `/_quant404`, the path Quant serves for
  missing content.
- Sitemaps, robots.txt, feeds with an extension and other non-HTML routes
  are uploaded as files, so the edge serves them with the content type of
  their extension (for example `application/xml`). The edge serves pushed
  markup as `text/html` whatever headers are sent.
- Links and asset URLs on `localhost` or a private IP address
  (10.x, 172.16-31.x, 192.168.x, 127.x) become relative paths. Page builders
  and slider plugins often cache such URLs; on the static site they made
  Chrome ask visitors for local network access and broke images.
- The site addresses stored in the database (`home`, `siteurl`) are stripped
  as well as the values in effect, so sites whose address is set by
  `WP_HOME`/`WP_SITEURL` per request (Quant Cloud) do not leak the
  production domain into the static copy.
- WP-CLI commands do not start a WP-Cron request. A queue change made from
  WP-CLI is processed by the next cron run or by `--process`.

## For developers

- PHP 8.1, PSR-4 under `Quant\`, a service container, REST endpoints under
  `quant/v1`, and a single `JobProcessor` used by REST, WP-CLI and WP-Cron.
- Filters: `quant_internal_fetch_url`, `quant_internal_fetch_args`,
  `quant_processed_markup`, `quant_local_hosts`, `quant_custom_redirects`,
  `quant_search_record`, `quant_search_record_language`,
  `quant_sitemap_routes`, `quant_sitemap_indexes`, `quant_purge_paths`,
  `quant_cron_batch_size`, `quant_cron_time_budget`, `quant_portal_url`,
  `quant_cloud_api_url`. Actions: `quant_jobs_queued`, `quant_seeded`,
  `quant_sync_job_failed`, `quant_purged`, `quant_schedule_updated`.
- Additional filters: `quant_purge_after_publish`, `quant_public_file_extensions`,
  `quant_max_upload_bytes`, `quant_search_language_label`.
- Tests: `composer check` (PHPUnit, PHPStan level 5, PHPMD complexity),
  `npm run lint:js && npm run build`, the Playwright suite in `tests/e2e`
  against a Dockerised WordPress and a mock Quant API, and
  `tests/e2e/bin/upgrade-test.sh`, which updates the published 1.x plugin in
  place and checks the migration.
- Checked with WordPress.org Plugin Check: no errors.

# QuantCDN WordPress Plugin v2.0 - Hardening and End-to-End Test Plan

**Date:** 2026-10-31
**Branch:** `feat/v2-overhaul`
**Design:** `docs/plans/2026-01-31-v2-complete-overhaul-design.md`

## State found on 2026-10-31

- 40 commits on the branch plus uncommitted work on redirects, theme assets and
  debug logging.
- PHPUnit: 157 tests, 9 errors, 7 failures (tests not updated after the
  renderer `info` change and the renderer exception change).
- The v1 plugin files are still in the tree next to v2 (`src/App.php`,
  `src/Client.php`, `src/seed/`, `wp-batch-processing/`, `quant-cli.php`).
- No end-to-end tests. No lint configuration.

## Defects found in review

| # | Area | Defect |
|---|------|--------|
| 1 | Queue | `SyncJob::fromDatabaseRow()` drops `meta`. Taxonomy, archive, CPT and redirect jobs lose the data they need and fail or sync the wrong URL. |
| 2 | Hooks | `ContentSyncSubscriber` is never registered. Nothing is queued on save in Static mode. |
| 3 | Hooks | Unpublish jobs call `get_post()` for every type. A deleted term can unpublish an unrelated post with the same ID. |
| 4 | Processing | Job processing is duplicated in `RestController` and `CLI\Commands`, and the two copies differ. No WP-Cron processing exists. |
| 5 | API | `uploadFile()` posts a raw body to `/v1/file`. The Content API takes a multipart `data` field at `POST /v1` with `Quant-File-Url`. |
| 6 | API | `purge('/*')` also purges `/*/`. |
| 7 | API | Debug `error_log()` calls in `publish()` and `processQueue()`. |
| 8 | Theme assets | `glob('**/*')` is not recursive. Only files one directory deep are found. |
| 9 | Onboarding | Step 3 posts `{mode}` only; `saveSettings()` then blanks organization and project. Step 4 always fails. |
| 10 | Features | Custom routes, the 404 route, pagination of home/archives/terms, and domain rewrites exist in settings but are never used. v1 had all of them. |
| 11 | Archives | The "blog" archive job uses `/blog/` when there is no posts page. The job fails on sites that show posts on the front page. |
| 12 | Redirection | The Redirection plugin add/update/delete hooks from v1 are not ported. |
| 13 | Quant Cloud | The `app-wordpress` template sets v1 options from `QUANT_*` environment variables. v2 ignores them. |
| 14 | Uninstall | `uninstall.php` is the v1 file and references undefined constants. |
| 15 | Bootstrap | `quant.php` defines legacy constants with the wrong v1 option names. |
| 16 | Queue | Jobs left in `processing` after a crash are never retried. Duplicate pending jobs pile up on repeated saves. |
| 17 | OAuth | Portal URL is fixed to the public dashboard. QuantGov endpoints get the wrong portal. |
| 18 | Status | `getSyncStatuses()` reports `synced` for an item that has a newer pending job. |

## Work

### A. PHP core
1. Delete v1 files. Rewrite `uninstall.php`. Fix constants in `quant.php`.
2. Add a `meta` column to the queue table with a version-gated `dbDelta` upgrade. Dedupe pending jobs. Recover stale `processing` jobs.
3. Add `Sync\InternalFetcher` (one internal HTTP fetch path), `Sync\MarkupProcessor` (absolute to relative URL rewrite), `Sync\JobProcessor` (one job processor), `Sync\Seeder` (one job builder).
4. Fix `QuantContentApi` (multipart upload, purge variants, `info` keys, no debug logs).
5. Register `ContentSyncSubscriber`; capture URLs in `meta` before deletion; port Redirection hooks; add WP-Cron queue processing and scheduled sync.
6. Settings: honour `QUANT_*` environment variables and constants.
7. REST: partial settings update, delegate to Seeder/JobProcessor, custom routes and 404 seed types, status ordering fix.
8. CLI: delegate to Seeder/JobProcessor, `queue process --all`, `unpublish`.
9. OAuth: derive the portal from the endpoint.

### B. Tests and lint
- Fix the existing unit tests. Add tests for every new class and for `QuantContentApi`.
- Add `phpmd` with a cyclomatic complexity rule and `wp-scripts lint-js`.

### C. End-to-end
- `docker-compose.e2e.yml`: WordPress, MySQL, WP-CLI, and a mock Quant API that records requests.
- Playwright suite in `tests/e2e/`: onboarding, settings, seed and process, auto-sync on save/trash/delete, cache purge, CDN-only mode, migration from v1, WP-CLI, diagnostics.

## Added 2026-10-31: Drupal module parity and seamless upgrade

Reference: `~/apps/quant-drupal` (module 2.1.0). WordPress equivalents:

| Drupal feature | WordPress v2 implementation |
|---|---|
| Track content change; tracked entities (nodes, terms, redirects, views) | `auto_sync`; posts, pages, CPTs, terms, media, redirects, archives |
| Disable content drafts | Only `publish` status is pushed. Scheduled posts push when WP publishes them. |
| Follow links (xpath) for pagination | Explicit pagination for home, archives, terms, authors, plus `rel="next"` discovery in rendered markup |
| Strip host domain from content | `MarkupProcessor`: home host, `domain_rewrites`, `http://` to `https://` for external links (v1 behaviour) |
| Proxy override metadata | `proxy_override` setting sent with every page |
| Info metadata (`author_name`, `log`), `content_timestamp` | `author_name`, `source: wordpress`, `log` (post modified), `content_timestamp` from `post_modified_gmt` |
| Attachments discovery (`find_attachments`, response `attachments`, md5 skip) | `JobProcessor` uploads local files the API reports, skipping unchanged md5 |
| Special pages (`/`, `/_quant404`) | Home and `route_404` rendered as `/_quant404` |
| Path alias change: unpublish old alias and redirect | Slug change: unpublish old permalink, queue 301 redirect to the new permalink |
| Redirect module presave/predelete | Redirection plugin `redirection_redirect_updated` / `_deleted` hooks |
| Internal `quant-token` request header | `Quant-Token` internal header: skips canonical redirects, marks the request internal |
| Page info block | Admin bar item and list-table column with the sync status |
| Quant Purger (cache tags) | `CachePurgeSubscriber` purges the page, home, its terms, author and post-type archives |
| Quant Cron | `quant_schedule` scheduled sync of chosen types (v1 cron parity) |
| Quant Sitemap | Core `wp-sitemap.xml` index and children, Yoast and Rank Math index detection |
| Quant Search: records with each push, `skip`, full reindex, clear index, facets, index status | `SearchRecordBuilder`, `search_record` on publish, reindex and clear endpoints, status from `/v1/search` |
| Quant Search pages and autocomplete block | Search pages stored as an option, rendered by the `[quant_search]` shortcode or block on a WP page; autocomplete shortcode/block; option to point the WP search form at a search page |
| Multi-domain | WordPress multisite: per-site options and table; network activation creates every site's table |
| Webform | Not ported. Forms are configured in the Quant dashboard. The readme keeps the Contact Form 7 note. |
| Tome | Not applicable. |

Seamless upgrade from v1 (1.x):
- `readme.txt` sets `Requires PHP: 8.1`, so WordPress does not offer the update to sites on older PHP. `quant.php` also guards the PHP version and shows a notice instead of a fatal error.
- On the first load after the update the v1 options are migrated automatically. No wizard step blocks the admin. A dismissible notice links to the review page.
- All v1 settings are kept: `enabled`, `purge_on_save`, `disable_tls_verify`, `http_request_timeout`, `webserver_url`, `webserver_host`, API fields, `404_route`, `custom_routes`, `domains_strip`, and every cron option.
- `QUANT_*` environment variables (Quant Cloud `app-wordpress` template) are read by v2 directly.
- v1 WP-CLI commands keep working: `wp quant info`, `wp quant reset_queue <queue|all>`, `wp quant process_queue <queue|all> --threads=N`.
- The v1 settings URL `options-general.php?page=quant` redirects to the new page.

## Status 2026-10-31 (end of session)

Done in `plugins/quant`:
- v1 files removed; `uninstall.php` rewritten; PHP 8.1 guard and PSR-4 fallback autoloader in `quant.php`; network activation.
- Queue: `meta` and `claim` columns, atomic claims (safe for `--threads`), dedupe, stale recovery, version-gated `dbDelta` upgrade (`quant_db_version` 2.0.2).
- `Sync\InternalFetcher`, `MarkupProcessor` (HTML only), `Paths`, `LinkDiscovery`, `Publisher`, `AttachmentUploader`, `Seeder`, `JobProcessor` + handlers, `SitemapDiscovery`, `Redirects\RedirectProvider`, renderers (`RouteRenderer`, `PostRenderer`, `TermRenderer`).
- `Search\SearchRecordBuilder`, `SearchPageRepository`, `SearchPageSync`, `Frontend` (shortcodes, blocks, search form replacement), `Admin\SearchController`.
- Hooks: `ContentSyncSubscriber` (registered), `RedirectionSubscriber`, `CachePurgeSubscriber` (v1 rule), `InternalRequestHandler`; `Core\Scheduler` (WP-Cron), `Core\Upgrader` (auto migration), `Core\Environment` (QUANT_* overrides).
- REST and CLI rewritten on the shared services; v1 CLI aliases; `SyncStatusUi` column, row action, admin bar.
- React: Settings, Dashboard, Content, Search pages; frontend `search.js`, `autocomplete.js`, `blocks.js`; ESLint and stylelint clean.
- `readme.txt` for 2.0.0, `RELEASE-NOTES-2.0.0.md`, `.distignore`, `bin/build-zip.sh`, GitHub Actions CI.
- PHPStan level 5 and PHPMD complexity rules pass.

Verified in Docker against the mock Content API: a full push publishes posts, pages, a CPT, terms, home, archives, `/_quant404`, `/robots.txt` (text/plain), all core sitemaps (application/xml), uploads theme assets, media sizes and discovered attachments, and creates the Redirection redirect; markup has relative links only; `wp quant process_queue all --threads=3` works.

Open at the time of writing: the unit test rewrite and the Playwright suite run in parallel agents; results are recorded in the session summary.

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

# QuantCDN WordPress plugin: end-to-end tests

Docker runs WordPress, MySQL, WP-CLI and a mock Quant API. Playwright drives
wp-admin and inspects what the plugin sent to the mock.

## Run

```bash
cd tests/e2e
npm install
npx playwright install chromium
npm run setup      # start containers, install WordPress, activate plugin, create fixtures
npm test           # runs bin/setup.sh again (idempotent) unless E2E_SKIP_SETUP=1
npm run teardown   # stop containers and delete volumes
```

`E2E_SKIP_SETUP=1 npm test` skips the setup step on a running environment.

## Environment

| What | Value |
|---|---|
| WordPress | http://localhost:8088 (admin / password) |
| Mock Quant API | http://localhost:4000 from the host, `http://mock-api:4000` from WordPress |
| Mock credentials | organisation `test-org`, project `test-project`, token `test-token-123` |
| Compose file | `docker-compose.e2e.yml` at the repo root |
| WP-CLI | `docker compose -f docker-compose.e2e.yml exec -T wpcli wp <cmd> --path=/var/www/html` |
| Debug log | `npm run wp:logs` |

The mu-plugin in `tests/e2e/mu-plugins/quant-e2e.php` points the plugin's OAuth
portal at the mock (filters `quant_portal_url`, `quant_cloud_api_url`) and
registers the `book` post type and `genre` taxonomy.

Fixtures created by `bin/setup.sh`: posts `hello-quant`, `second-post`,
`third-post`; pages `about`, `contact`; book `my-book` (genre `fiction`);
categories `news`, `tech`; tags `alpha`, `beta`; attachment `sample.png`;
Redirection redirect `/old-page` to `/about`.

## Inspect the mock

```bash
curl -s localhost:4000/_mock/state | jq .            # published, files, redirects, searchRecords, facets, unpublished, purges
curl -s 'localhost:4000/_mock/requests?path=/v1/purge' | jq .
curl -s -X DELETE localhost:4000/_mock/requests        # clear requests and state
curl -s -X POST localhost:4000/_mock/fail -d '{"path":"/v1/purge","status":500,"times":1}'
npm run mock:logs
```

Helpers for specs live in `lib/wp.js`: `wp()`, `wpEval()`, `mockState()`,
`mockRequests()`, `mockReset()`, `mockFail()`, `waitForMock()`,
`resetPlugin()`, `configurePlugin()`, `seedV1Options()`, `postIdBySlug()`.

`lib/queue.js` adds `processQueue()` (WP-CLI `wp quant queue process --all`),
`queueStatus()`, `queueJobs()`, `truncateQueue()`, `runCron()`. `lib/admin.js`
adds `gotoQuant(page, route)`, `rest(page, endpoint, { method, data })` (the
plugin's REST API through the logged-in page), `notice(page, text)`,
`screenshot(page, name)` and `acceptDialogs(page)`.

## Specs

| Spec | Covers |
|---|---|
| `smoke.spec.js` | Mock auth, front page, Quant admin page renders |
| `onboarding.spec.js` | Fresh-install wizard; the mode step keeps the credentials |
| `oauth.spec.js` | Login through the (mock) portal, project selection, change project, disconnect |
| `settings.spec.js` | Manual credentials, connection tests, toggle persistence, `QUANT_*` constant override |
| `content-sync.spec.js` | Push everything, push/unpublish selected, failure → retry |
| `auto-sync.spec.js` | Save/rename/draft/delete posts, terms, media, Redirection items; WP-Cron drains the queue |
| `cdn-only.spec.js` | Purge on save, Cache page purges |
| `migration.spec.js` | Automatic 1.x migration, review page, cleanup, legacy settings URL |
| `search.spec.js` | Search status, records, search pages with facets, public page, reindex, clear, native search redirect |
| `cli.spec.js` | `wp quant` commands including the 1.x aliases and parallel workers |
| `diagnostics.spec.js` | Diagnostics page and report download |
| `admin-ui.spec.js` | Post list column, row action, admin bar, dashboard stats |

Run one spec or one test:

```bash
E2E_SKIP_SETUP=1 npx playwright test specs/search.spec.js --reporter=line
E2E_SKIP_SETUP=1 npx playwright test -g "slug change" --reporter=line
```

Screenshots of key screens are written to `output/screens/`. Failure
screenshots and the HTML report go to `output/test-results/` and
`output/report/`.

Internal rendering in tests uses `webserver_url = http://wordpress` so that
both the web container and the WP-CLI container can fetch pages.

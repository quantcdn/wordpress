# QuantCDN WordPress plugin

The plugin lives in `plugins/quant`. The rest of this repository is the
development and test environment.

- Plugin documentation for site owners: `plugins/quant/readme.txt`
- Release notes: `RELEASE-NOTES-2.0.0.md`
- Design and plans: `docs/plans/`

## Develop

```bash
cd plugins/quant
composer install          # PHP dev tools (PHPUnit, PHPStan, PHPMD)
npm install               # admin and frontend assets
npm run build             # or: npm start (watch)
composer check            # PHPStan + PHPMD + PHPUnit
npm run lint:js && npm run lint:css
```

Built assets in `plugins/quant/assets/build` are committed; rebuild them
before you commit a change under `plugins/quant/assets/src`.

A local WordPress for manual testing: `docker compose up -d` (port 8088,
`docker-compose.yml`). The plugin directory is mounted into it.

## End-to-end tests

`docker-compose.e2e.yml` starts WordPress, MySQL, a WP-CLI sidecar and a mock
Quant Content API that records every request. The Playwright suite in
`tests/e2e` drives the admin UI, WP-CLI and the public site and asserts on
what the mock received.

```bash
cd tests/e2e
npm install && npx playwright install chromium
npm run setup             # containers, WordPress install, fixtures
npm test                  # E2E_SKIP_SETUP=1 npm test to skip setup
npm run teardown
```

See `tests/e2e/README.md` for the spec list and the mock endpoints.

Upgrade test: install the published 1.x plugin from WordPress.org, configure
it, update it in place with a freshly built 2.x zip, and check the migration,
WP-Cron processing and a full push.

```bash
tests/e2e/bin/upgrade-test.sh          # from 1.6.0
tests/e2e/bin/upgrade-test.sh 1.4.0    # from an older release
```

## Release

```bash
plugins/quant/bin/build-zip.sh   # writes plugins/quant/dist/quant-<version>.zip
```

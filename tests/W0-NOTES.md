# Notes for W8 (package.json owner) — from W0 (test infra)

package.json is owned by W8 (PLAN-1.10.1-v4.md 2.2). W0 is not allowed to
touch it, so the devDependencies and scripts the new test infra needs are
listed here for W8 to add.

## devDependencies to add

```json
{
  "@playwright/test": "^1.48.0",
  "playwright-core": "^1.48.0"
}
```

- `@playwright/test` is used by `playwright.config.mjs` (repo root, owned by
  W0) and by every `tests/e2e/*.spec.mjs`.
- `playwright-core` is a transitive dependency of `@playwright/test` but is
  imported DIRECTLY by `tests/fixtures/gen-consent-cookie.mjs`
  (`import { chromium } from 'playwright-core'`), which launches a bare
  browser outside the Playwright test runner. Declaring it explicitly avoids
  relying on `@playwright/test`'s internal dependency tree staying stable.
- Version pin **must match** `mcr.microsoft.com/playwright:v1.48.0-jammy`,
  the image used by the `e2e`/`e2e-wp62` Docker services
  (`tests/docker/compose.yml`) — a mismatched Playwright/browser version is
  a common source of "works locally, fails in Docker" flakiness. Bump both
  together.

## scripts to add

```json
{
  "test:js": "node --test \"tests/js/**/*.test.mjs\"",
  "test:e2e": "playwright test"
}
```

- Acceptance criterion 4.3.3 says `node --test tests/js/` (bare directory
  argument). **Verified on this dev machine (Windows, Node v25.9.0,
  git-bash): that exact invocation fails** with
  `Error: Cannot find module '...\tests\js'` / `MODULE_NOT_FOUND` — Node's
  test runner tries to `require()` the directory instead of recursing into
  it (reproduced in a throwaway directory outside this repo too, so it is
  not something in tests/js/ causing it). The glob form
  `node --test "tests/js/**/*.test.mjs"` works and was used to verify
  sandbox.mjs (see W0's task report). Needs verification on the actual
  Linux CI/Docker Node version whether the bare-directory form works there;
  until confirmed, `test:js` above uses the glob form defensively.
- `test:e2e` is a local convenience alias; the Docker services run
  `npx playwright test` directly (see `tests/docker/compose.yml`,
  `tests/docker/e2e/entrypoint.sh`).

## Why W0 didn't just add these

package.json is single-owner per PLAN-1.10.1-v4.md 2.2 to avoid merge
conflicts across 8 parallel workstreams touching the same file. This file
exists so W8 doesn't have to reverse-engineer what the test infra needs from
reading every new test file.

## phpunit.xml.dist: narrow the testsuite to test-*.php (W8 change)

`phpunit.xml.dist` currently is:

```xml
<testsuite name="TrackWP Unit Tests">
    <directory suffix=".php">./tests/</directory>
    <exclude>./tests/bootstrap.php</exclude>
</testsuite>
```

`suffix=".php"` over the whole `./tests/` tree also matches
`tests/fixtures/gen-purchase-config.php` (a wp-cli script, not a test case).
It is currently harmless (see the comment in that file — it `return`s
instead of `exit()`ing when not run under `WP_CLI`, precisely so a stray
PHPUnit inclusion is a silent no-op), but it is fragile: any future `.php`
file under `tests/docker/`, `tests/fixtures/` or `tests/legacy/` would be
swept in too. Please tighten the pattern to only match actual test files:

```xml
<testsuite name="TrackWP Unit Tests">
    <directory suffix="Test.php">./tests/</directory>
    <exclude>./tests/bootstrap.php</exclude>
</testsuite>
```

This requires every test class file to be named `*Test.php` (they already
are: `test-consent.php` contains `class TrackWP_Consent_Test`, but the
FILENAME doesn't end in `Test.php` — check the actual naming convention
used across W1-W7's files before flipping this, since PHPUnit's
`directory suffix=` matches the FILENAME, not the class name). If the
filenames stay `test-*.php` (verified: they do, e.g. `test-consent.php`,
`test-woocommerce-purchase.php`), use an explicit exclude list instead:

```xml
<testsuite name="TrackWP Unit Tests">
    <directory suffix=".php">./tests/</directory>
    <exclude>./tests/bootstrap.php</exclude>
    <exclude>./tests/fixtures</exclude>
    <exclude>./tests/docker</exclude>
    <exclude>./tests/e2e</exclude>
    <exclude>./tests/js</exclude>
</testsuite>
```

The second form is what W0 actually verified works (see "Verified commands"
below) and is the recommended one, since it doesn't require renaming any
existing test file.

## Verified commands per service (run 2026-09-27 from C:\tools\trackwp\trackwp)

```
DC="docker compose -f tests/docker/compose.yml"

$DC up -d db                                             # starts db, waits healthy
$DC build phpunit phpunit-legacy-orders                  # ~35s (gd/mysqli/etc compile)
$DC run --rm phpunit vendor-dev/bin/phpunit               # 181 tests, 849 assertions,
                                                           # 2 failures -- BOTH pre-existing
                                                           # in other workstreams' WIP files
                                                           # (test-assets.php W8,
                                                           # test-sample.php), not infra bugs
$DC run --rm phpunit-legacy-orders vendor-dev/bin/phpunit --group orders
                                                           # OK (21 tests, 95 assertions)
$DC run --rm phpunit vendor-dev/bin/phpunit --filter 'TrackWP_Sample_Test|TrackWP_WooCommerce_Test|TrackWP_WooCommerce_Purchase_Test'
                                                           # OK (47 tests, 176 assertions) --
                                                           # confirms test-sample.php,
                                                           # test-woocommerce.php and
                                                           # test-woocommerce-purchase.php
                                                           # all run
```

`$DC down` (no `-v`) tears down containers but keeps the `wp-core-71` /
`db-data` volumes, so the next run skips the WordPress-core download and
`composer install`.

Not yet verified in this session (ran out of turn budget, see the task
report): `$DC run --rm e2e npx playwright test`, `$DC run --rm e2e-wp62 ...`,
and the `wp`/`wp62`/`cache`/`mockplatforms` services end-to-end.

## Things W8 should double check

- `npx playwright install chromium` needs to be run (once) wherever
  `gen-consent-cookie.mjs` runs outside Docker; inside the `e2e`/`e2e-wp62`
  Docker services the browsers are already baked into the
  `mcr.microsoft.com/playwright` base image.
- `.phpcs.xml.dist` note from NEXT-BUILD.md (WPCS 3.x incompatibility) is
  unrelated to this test infra and intentionally not touched here.

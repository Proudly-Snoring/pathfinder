# Dependency update plan (step 2)

Execution plan for the findings in [`dependency-audit.md`](dependency-audit.md). Built around one hard constraint: **there is no test suite and no CI** — every change is validated by building the image and running a manual smoke checklist (§5). That forces small, independently-revertible steps and a phased path the user can stop after at any point.

## 1. Context that shapes the plan

- **Validation = build + smoke test.** No PHPUnit, no Jest, no CI workflow. The smoke checklist (§5) *is* the regression suite; it runs as the gate at the end of every phase.
- **Two EOL runtimes gate everything** (see audit): PHP `7.2.34` and Node `12`. Most major bumps wait on a runtime jump.
- **PHP 8 is a Dockerfile overhaul, not a composer bump.** The runtime stage uses `trafex/alpine-nginx-php7` (PHP 7); the build stage uses `php:7.2.34-fpm-alpine` with hand-built pecl `redis`/`zmq`. Moving to PHP 8 means a new runtime base image, `php8-*` Alpine packages, and rebuilt pecl extensions.
- **The socket is an external prebuilt image** (`ghcr.io/goryn-clade/pf-websocket`), not built from this repo. The `react/*` + `clue/*` composer deps ship in the image but are **not exercised by the default compose stack** — keep that in mind when validating their bumps (needs a separately-run socket script to truly test).
- **PHP support matrix (verified 2026-06):** 8.1 EOL; 8.2 security-only→Dec 2026; **8.3 security→Dec 2027**; **8.4 active→Dec 2028**; 8.5 active→Dec 2029.

## 2. Decisions

**Guiding principle (user directive): target the *latest stable* version of everything — not the conservative lower one.** Corollary (decided): **never land on a throwaway intermediate.** Every Phase 1 bump goes straight to its *final* latest-stable that happens to run on PHP 7.2 (fatfree 3.9.2, cortex 1.7.8, …). The only PHP-gated deps (monolog, php-jwt, cache/*) are **held at their current version through Phase 1** and jump **straight to latest** in Phase 2 — no 2.x waypoint for monolog.

Phases 0 and 1 are low-risk and need only a "go". Two decisions gate the rest:

### Decision A — PHP target (gates Phase 2) — **DECIDED: PHP 8.5**

Per the latest-stable directive, the target is **PHP 8.5** (released 2025-11, in active support, security to Dec 2029), in one jump 7.2 → 8.5. No intermediate 7.4 stop (its only gain — unblocking cache/* 1.2 + php-jwt 6.10 earlier — isn't worth doing the base-image/pecl overhaul twice). The expensive part is the **8.0** language break (removed `each`/`create_function`, stricter string↔number comparison, type coercion) plus the base-image overhaul; landing on 8.5 vs an older 8.x costs the same.

> **Ecosystem-lag risk to watch (Phase 2):** 8.5 is only ~7 months old. Confirm the runtime base image (a PHP 8.5 `trafex/php-nginx` tag or equivalent), Alpine `php85-*` packages, and pecl `redis`/`zmq` builds are all available for 8.5 before committing the Dockerfile. Fallback if any are missing: 8.4 (security to Dec 2028), which is functionally identical for our code.

### Decision B — Frontend / Node scope (gates Phase 3) — **DECIDED: minimal Phase 3 + overhaul as a separate initiative**

Phase 3 stays **strictly scoped** to "get the *existing* gulp + RequireJS toolchain onto a current Node LTS (Node 24) + modern ESM plugins + terser". **Replacing RequireJS / modernizing the frontend is explicitly OUT OF SCOPE here** and is sketched as a separate follow-on project in [Appendix A](#appendix-a--frontend-overhaul-separate-initiative).

Chain of thought: the JS bundle is built by `gulp-requirejs-optimize` (frozen 2018) over a RequireJS/AMD codebase loading ~40 vendored jQuery-era libs (jQuery 3.4, Bootstrap 3 EOL, …). A real "modern pipeline" (Vite/esbuild) is ESM-native and won't consume AMD source, so an overhaul is a UI rewrite — a different risk class from version bumps, and with no test suite it is validated entirely by manual smoke testing. Keeping the two separate lets the dependency update actually ship. The minimal Phase 3 still removes the EOL-Node-12 risk cheaply.

## 3. The phases

Each phase ends with the §5 checklist. Each is a separate branch/PR so it can be reverted independently.

### Phase 0 — Cuts & hygiene  *(no runtime change, no version bumps)*  — **DONE (pending build validation)**

Smallest, safest, shrinks the tree first.

- **Platform pin (moved up from Phase 1):** any composer op re-resolves the graph, and without a pin the resolver (run in a newer-PHP container) drags in PHP-8-only transitives (observed: `psr/log` 1.1.4→3.0.2, `cache/adapter-common` 1.2→1.3). So `config.platform` is set **here**, and it must fake the **extensions too** — `--ignore-platform-reqs` alone *overrides* `platform.php` and reintroduces the bumps. Pinned: `php 7.2.34` + `ext-pdo/openssl/curl/json/mbstring/ctype/gd`. Composer run via throwaway `composer:2.1.8` container (`--no-install`).
- **Composer:** removed `swiftmailer/swiftmailer` (mail decommission — **~18 files**, larger than audit: also `Config::getSMTPConfig/isValidSMTPConfig/getNotificationMail`, `MapModel`/`UserModel`/`SystemModel` mail methods, the rally **mail-poke** channel incl. frontend `system_rally.html`/`system.js`, `Setup.php` SMTP fields, `pathfinder.ini` NOTIFICATION + `SEND_RALLY_Mail_ENABLED`, all `SMTP_*` env keys). Removed `cache/void-adapter` from **direct** requires — but it stays in the tree (transitive dep of `goryn-clade/pathfinder_esi`), so this is hygiene only, not a tree-shrink. Kept `PATHFINDER.EMAIL` (public contact, JSON-LD — unrelated to SMTP).
- **npm:** removed `babel`, `node-sass` (+ vestigial `sass.compiler = require('node-sass')`), `slash`; **declared** phantom deps `ini ^1.3.8`, `minimist ^1.2.8`, `lodash.merge ^4.6.2`; lock regenerated (`--package-lock-only`, node 22 container).
- **Validate:** CSS still builds (node-sass gone), logging still works + clean logs (mail gone), full checklist. *(Build smoke test pending.)*

### Phase 1 — Runtime-safe bumps  *(stay on PHP 7.2 / Node 12)*

The resolver pin (`config.platform` php + faked exts) is **already in place from Phase 0**. Keep it for the whole phase.

| Dep                       | From            | To           | Note                                              |
|---------------------------|-----------------|--------------|---------------------------------------------------|
| bcosca/fatfree-core       | 3.7.3           | 3.9.2        | in-line bump                                      |
| ikkez/f3-cortex           | dev-master@af03 | ^1.7 (tag)   | unpin 2021 commit → tag; no PHP floor (verified)  |
| xfra35/f3-cron            | 1.2.1           | 1.3.0        |                                                   |
| league/html-to-markdown   | 5.0.2           | 5.1.1        | needs php ^7.2.5 ✓ (verified)                     |
| react/socket              | 1.9.0           | 1.17.0       | socket only fully testable via socket script      |
| react/promise-stream      | 1.3.0           | 1.7.0        |                                                   |
| clue/ndjson-react         | 1.2.0           | 1.3.0        |                                                   |
| react/promise (transitive)| 2.11.0          | 3.3.0        | **major** — pulled by socket 1.17; code migration, see below |
| **npm** gulp-rename       | 2.0.0           | 2.1.0        |                                                   |
| **npm** ansi-colors       | 4.1.1           | 4.1.3        |                                                   |
| **npm** node-notifier     | 8.x             | 10.0.1       | loose `>=8.0.1` → pinned `^10.0.1`                 |

**react/promise 2 → 3 migration (DONE).** The socket stack bump dragged `react/promise` from v2 to v3 (major). `app/Lib/Socket/AbstractSocket.php` used three v2-only constructs, now rewritten: `FulfilledPromise` → `Promise\resolve()`; `RejectedPromise(\Exception)` → `reject(\Exception)`; the final `->otherwise()` error handler → `->catch()`. v3 forbids non-`Throwable` rejection reasons, so the old "reject with an error-payload **array**" pattern now **resolves** with that array instead. Consumer impact is benign: `Setup.php`'s health-check onRejected becomes dead (its onFulfilled `else` branch already renders `task=='error'`); `Map.php`'s `$status` shows the error text instead of `''` (still non-`OK`). Runtime socket path needs manual smoke (Setup → TCP-Socket PING).

**Held at current through Phase 1, then straight to latest in Phase 2 (PHP-gated):** `monolog` (2.3.5 → 3.10, **no 2.11 waypoint**), `firebase/php-jwt` (6.x → 7.1), `cache/*` adapters (1.x → 1.2).
**Held back to Phase 3 (Node-gated) — moved here from Phase 1 after validation failed on Node 12:**
- `gulp` (4.0.2 → 5.0.1): gulp 5 + vinyl 3 breaks the unmaintained `gulp-image-resize` (2017) image pipeline (*"async completion"*); land it with the image-toolchain overhaul.
- `sass` (1.62.0 → latest 1.x): latest dart-sass (1.101.0) hard-requires Node ≥20.19; no meaningful Node-12-safe upgrade exists above the current 1.62.0.
**Held back to Phase 3 (ESM/Node-gated):** `gulp-filter`, `gulp-debug`, `gulp-autoprefixer`, `gulp-imagemin`, `imagemin-webp`, `pretty-bytes`, `fancy-log`, `flat`, `uglify-es`→terser.
**Held (no change):** `goryn-clade/pathfinder_esi` v2.1.4 — latest stable; v3 is beta only.

- **Validate:** full checklist. Build-level: PHP `build` + `assets` Dockerfile stages both green (composer install on php:7.2.34 → 696 classes; gulp-4 assets bundle incl. images).

### Phase 2 — PHP runtime upgrade  *(gated by Decision A)*

Split into two sub-steps so a runtime/language failure stays separable from a major-dep API failure (critical with no tests). Each sub-step ends with the §5 checklist.

**Phase 2a — runtime jump only (deps held). DONE.** PHP 7.2.34 → **8.5**, confirmed available end-to-end (`php:8.5-fpm-alpine` 8.5.7; runtime `trafex/php-nginx:3.11.1` ships 8.5.3). What landed:
1. **Dockerfile:** build stage → `php:8.5-fpm-alpine`; **dropped pecl `redis` + `zmq`** (redis now from Alpine `php85-redis` 6.3.0, no cross-stage `.so` copy; `zmq` unused in PHP code + abandoned on 8.x). Runtime base → `trafex/php-nginx:3.11.1`; `php7-*`→`php85-*`; **dropped `php85-event`** (no Alpine pkg; React falls back to `stream_select`). Added `USER root` (trafex/php-nginx defaults to `nobody`; supervisord needs root). Removed the obsolete DST CA fix. Composer pin `2.1.8` → latest. Pool/conf paths `/etc/php7/`→`/etc/php85/`; removed trafex's bundled `www.conf` so our pool (`listen 127.0.0.1:9000`) is the only `[www]`. `php-fpm7`→`php-fpm85`.
2. **Platform pin** 7.2.34 → 8.5.3; `php-64bit` floor → `>=8.5`. Held deps verified to install + boot on 8.5 (fatfree 3.9.2, cortex 1.7.8, monolog 2.3.5, php-jwt 6.4.0).
   - *esi caveat hit:* `pathfinder_esi` 2.1.4 pins two PHP-7-only transitives. Resolved with inline aliases (held, kept stable): `cache/void-adapter "1.1.0 as 1.0.1"` (no-op cache) and `caseyamcl/guzzle_retry_middleware "2.9.0 as 2.3.3"` (same major). esi's own code is 8.5-clean (68 files lint-pass). *(Vendoring esi was considered and rejected — see §"esi" note; revisit as dedicated work if dropping the unmaintained dep. → done, see Phase 4.)*
   - **Bug found in this alias during Phase 2b SSO testing, fixed there (see below): `caseyamcl/guzzle_retry_middleware` real-2.9.0 has a `final` `__construct()` (added in v2.6.1, 2020-11-27) that esi's bundled `GuzzleRetryMiddleware` subclass overrides — "same major, no API risk" was wrong; a fatal only surfaces when an ESI HTTP call is actually made (e.g. SSO login), which Phase 2a's smoke pass didn't exercise.**
3. **F3 + PHP-8 strictness:** Fat-Free escalates every error in its mask to a 500. PHP 8 reclassified undefined var/array-key `E_NOTICE`→`E_WARNING` (silent on 7.2, fatal on 8). Fix: `index.php` drops `E_DEPRECATED | E_WARNING` from the mask → restores 7.2 behavior; real errors still 500.
   - **Separately**, held guzzle 6.5 emits a compile-time `E_DEPRECATED` (PHP 8.4+ implicit-nullable-param) during the composer autoload, once per opcache cold-start. The mask above suppresses its *display*, but `Base::instance()`'s startup self-check (`$check && error_get_last()`) reads PHP's internal last-error record directly, bypassing the mask — fatals on the lingering masked deprecation. Fix: `error_clear_last()` right after the autoload require, before `Base::instance()`.
   - **TODO (follow-up task — own phase): clean up all PHP-8 warnings/deprecations** (undefined template vars/array-keys app-wide; the guzzle deprecation + its `error_clear_last()` workaround both clear once guzzle goes 7.x with the esi work) **and revert both the `index.php` error-level relaxation and the `error_clear_last()` call** to strict. Tracked separately so 2a stays a runtime jump.

**Phase 2b — bump the now-unblocked PHP-gated deps straight to latest stable. DONE.**
   - `cache/*` 1.1 → 1.2 (`redis-adapter`, `filesystem-adapter`, `array-adapter`, `namespaced-cache`, `void-adapter` incl. its alias `1.2.0 as 1.0.1`). Constructors unchanged (`RedisCachePool`, `FilesystemCachePool`, `ArrayCachePool`, `NamespacedCachePool` all verified against `app/Lib/Api/AbstractClient.php` call sites) — no code changes needed.
   - `firebase/php-jwt` 6.4.0 → 7.1.0 — `JWT::decode()`/`JWK::parseKeySet()` signatures unchanged. Removed a dead 3rd arg in `Sso.php::verifyJwtAccessToken()` (`$supportedAlgs`, already ignored since 6.x — allowed algs come from the `Key` objects in the parsed key set; on 7.x that slot is a by-ref `$headers` out-param, so the dead arg would have silently started receiving JWT header data — removed instead of left to rot).
   - `monolog` 2.3.5 → 3.10.0 — **the real risk**: monolog 3's `LogRecord` is a readonly object (partial `ArrayAccess` BC; `offsetUnset` always throws), replacing v2's plain record array. `app/Lib/Monolog.php` itself needed no change (`Logger::toMonologLevel()`/`addRecord()` accept the new `Level` enum transparently). Two custom handlers needed fixes:
     - `Handler/SocketHandler.php` — used to override `handle()` to splice a custom `{task, load:{meta, log}}` envelope into the record *before* formatting, then run the configured (`json`) formatter over that fake structure — `JsonFormatter::format()` now hard-requires a real `LogRecord`, so that trick no longer compiles type-wise. Replaced with overriding the (still-array-free) `generateDataStream(LogRecord $record): string` hook instead: let the parent class format the *real* record normally, then wrap the already-formatted JSON string into the `{task, load}` envelope. Verified against a live `Monolog\LogRecord` + `JsonFormatter` in-container — output matches the old envelope shape byte-for-byte (`task`/`load.meta`/`load.log`). This handler is live (`MapModel::log()` → real-time map sync over the socket), not just config-commented.
     - `Handler/AbstractWebhookHandler.php` (Slack/Discord Map+Rally handlers) — `write()` was typed `array $record`; changed to `write(LogRecord $record)` and convert to array (`$record->toArray()` + carry over `->formatted`) at the top, so all the existing array-based `getSlackData()`/`excludeFields()`/`cleanAttachments()` logic in this file and its Map/Rally subclasses needed no further changes. Verified via reflection-driven call against a real `LogRecord` in-container — reaches the `curl_init()`/`Util::execute()` call (failed only on DNS for the fake test webhook URL, as expected).
   - Side effects of the resolve: `psr/log` 1.1.4 → 3.0.2 and `psr/cache` 1.0.1 → 2.0.0 (transitive; monolog 2.3.5 already declared `psr/log ^3.0` support, so no conflict surfaced before the jump either).
   - **Phase 2a regression found + fixed here:** manual SSO-login smoke (checklist item 4, not exercised by 2a's build+boot-only pass) hit a 500 on `/sso/requestAuthorization`: `Cannot override final method GuzzleRetry\GuzzleRetryMiddleware::__construct()`. Root cause: the 2a alias `caseyamcl/guzzle_retry_middleware "2.9.0 as 2.3.3"` installed real-**2.9.0** to satisfy esi's `2.3.*` constraint string — but `__construct()` was made `final` in **v2.6.1** (2020-11-27), which esi's bundled `Exodus4D\ESI\Lib\Middleware\GuzzleRetryMiddleware` subclass overrides to merge default retry options. "Same major → no API risk" (2a's reasoning) didn't hold across that point release. Fix: alias real version down to **`2.6.0`** (last pre-`final` release; its own `composer.json` already declares `php ^7.1|^8.0`, so it installs cleanly under the 8.5 platform pin without needing version-string trickery beyond the existing `as 2.3.3` to satisfy esi). Re-verified end-to-end: `/sso/requestAuthorization` now 302s to `login.eveonline.com` with correct client_id/scopes/state, clean logs.
   - **Reviewed but explicitly NOT touched (still blocked):** the Node-12-gated Phase-1 holds (`gulp` 5, `sass` latest, `gulp-filter`/`debug`/`autoprefixer`/`imagemin`, `imagemin-webp`, `pretty-bytes`, `fancy-log`, `flat`, `uglify-es`→terser) — `composer outdated -D` only reflects the PHP side; the `assets` Dockerfile stage is still `node:12-bullseye-slim`, untouched by Phase 2a/2b. They stay deferred to Phase 3 per the original plan.
   - **Re-checked, still holds:** `goryn-clade/pathfinder_esi` v3 is still `v3.0.0-beta.1` (no stable release) — staying on `2.1.4` per the latest-*stable* directive. Its guzzle 6.5 pin (worked around via the `caseyamcl/guzzle_retry_middleware`/`cache/void-adapter` aliases from 2a) is also why `composer audit` still reports `guzzlehttp/guzzle` and `guzzlehttp/psr7` CVEs (all fixed in versions newer than esi's pin) — same held-dependency tradeoff as 2a, not new in 2b, not actioned here.

**Main risk (both sub-steps):** 2017-era `app/` code hitting 8.0 breaks. Mitigation: incremental, watch logs hard, lean on the checklist.

- **Validate:** full checklist after **each** sub-step **+ extra log scrutiny** (8.0 type/deprecation breakage is silent until exercised).

### Phase 3 — Node toolchain modernization  *(scoped per Decision B)*  — **DONE**

Goal: **get the *existing* gulp + RequireJS toolchain onto the current Node LTS (Node 24, Krypton). Replacing RequireJS / modernizing the frontend itself is OUT OF SCOPE — see [Appendix A](#appendix-a--frontend-overhaul-separate-initiative).**

What landed:
- **Landmine check first (de-risk before touching anything):** ran `gulp-requirejs-optimize` (`task:concatJS`/`task:diffJS`) standalone against Node 25 with zero other changes — passed clean (one harmless `fs.Stats` deprecation warning). Critical path cleared; no escalation needed, no module-system rewrite.
- **Dockerfile:** `assets` stage `node:12-bullseye-slim` → `node:24-bullseye-slim`; dropped the `graphicsmagick` apt install (only needed by the now-removed `gulp-image-resize`); `npm install --prefer-offline` → `npm ci` (Node 12's npm 6 couldn't `ci` a lockfileVersion-3 lock — Node 24's npm 11 can).
- **`package.json`:** `engines.node` 12.x → 24.x; added `"type": "module"`.
- **`gulpfile.js` ESM migration:** `require` → `import` throughout; `__dirname` shimmed via `fileURLToPath(new URL('.', import.meta.url))` (still needed by `gulp-jshint`'s config path).
- **gulp 4 → 5 (forced, not deferred):** `gulp-filter@10` peer-requires `gulp >=5` — discovered via `npm install` ERESOLVE, so the gulp bump couldn't stay held; landed together with the ESM plugin sweep.
- **ESM-gated plugins bumped to latest stable:** `gulp-filter` 10, `gulp-debug` 5, `gulp-autoprefixer` 10, `gulp-imagemin` 9, `imagemin-webp` 8, `pretty-bytes` 7, `fancy-log` 2, `flat` 6 (named export `{flatten}`, was default). `gulp-imagemin` 9 dropped its old "pass any imagemin plugin array" docs example but the implementation still accepts an arbitrary plugin array under the hood — confirmed `imagemin([imageminWebp(...)])` still works unchanged.
- **Minifier:** `uglify-es` + `gulp-uglify/composer` → `gulp-terser` (`terser@5`). Options object (`ecma`, `toplevel`, `keep_classnames`, `nameCache`) carried over near-verbatim — terser still honors the legacy top-level `warnings` shorthand. Dropped the `.on('warnings', log)` hookup (gulp-terser is a plain stream, not the old composer API).
- **`gulp-image-resize` → custom `sharp`-based replacement (the flagged landmine, handled as its own step, isolated from the ESM sweep per review).** `gulp-image-resize`/`gulp-gm`/GraphicsMagick is 2014-era and unmaintained; rather than risk the "vinyl 3 async completion" break called out in Phase 1, replaced it outright with a small `through2.obj` transform (`sharpResize` in `gulpfile.js`) that mirrors its exact option semantics: `crop` → `sharp` `fit:'cover', position:'centre'`; `upscale:false` → `withoutEnlargement:true`; `format` → `.toFormat()`; `quality` (0–1, gm-style) → `Math.floor(quality*100)`. `noProfile` needed no equivalent call — sharp strips metadata by default unless `.withMetadata()` is called. Verified output dimensions/format byte-for-byte reasonable against the old gm output (e.g. `pf-header-480.png` → 480×354, `pf-header-480.webp` → valid VP8 webp). Confirmed sharp's prebuilt binary needs no system libvips/build-essential on `node:24-bullseye-slim`.
- **`sass` 1.62.0 → 1.101.0 (latest stable).** Was held in Phase 1 only because dart-sass ≥1.63 hard-requires Node ≥20.19 — moot now that the assets stage is Node 24. Compiles clean (only `slash-div`/`if-function` deprecation warnings, pre-existing in the Bootstrap-3-era SCSS, not errors).
- **Real bug found + fixed (not a plugin-version issue):** `gulp 5`'s `vinyl-fs@4.0.2` defaults `gulp.src`/`gulp.dest` to `encoding: 'utf8'`, which round-trips file contents through `iconv-lite` decode/re-encode — lossy for any byte sequence that isn't valid UTF-8. Every binary image (PNG/JPG) gulp touched got silently corrupted (bytes starting `0x89` → `U+FFFD`/`EF BF BD`), reproduced identically in a clean `node:24-bullseye-slim` container (not a Windows/local-Node-25 artifact). Fix: added `encoding: false` to every image-related `gulp.src`/`gulp.dest` call (header resize, gallery copy/webp, "rest" copy, SVG copy). JS/CSS reads are untouched (legitimately textual, default `utf8` is correct there).
- **Reviewed, left alone (pre-existing, not introduced by this phase):** `jshint`/`gulp-jshint`/`jshint-stylish` stayed on their current legacy versions — plan flagged ESLint migration as a separate, optional call, not bundled here. The header-image resize tasks transcode pixels to both `png` and `jpg` per size but only ever write to the `.png`-suffixed filename (rename only adds a size suffix, doesn't swap extension) — same quirk existed in the pre-migration `gm`-based code, left as-is (out of scope to fix bugs not introduced by the dependency bump).

- **Validate:** full local `gulp production` run (Node 25 host, stand-in for 24) — clean, all JS/CSS/image/webp/gzip/brotli outputs present and uncorrupted. Then the **real gate**: full `podman build` of all three Dockerfile stages (composer/PHP 8.5 `build`, gulp/Node 24 `assets`, `trafex/php-nginx` runtime) — green. `podman compose up -d --build` — all four services healthy, clean logs. HTTP checks: homepage 200, `login.js` served as `application/javascript`, `pathfinder.css` as `text/css`, `pf-header-480.webp` as `image/webp`. **Not exercised** (needs a browser + real EVE SSO credentials): SSO login round-trip, map CRUD, real-time socket sync — checklist items 4–8 are the user's to run manually; the stack is up at this point for that.

### Phase 4 — Vendor `goryn-clade/pathfinder_esi` in-tree  *(Step A: behavior-preserving)*  — **DONE**

Goal: drop the unmaintained external composer package; move its code into the repo as first-party code. **Guzzle stays held at 6.5.\*** and the existing Phase 2a/2b aliases (`cache/void-adapter`, `caseyamcl/guzzle_retry_middleware`) are unchanged — zero behavior change. A guzzle-7 migration is deliberately **not** part of this phase (see Step B below).

What landed:
- **Moved** `vendor/goryn-clade/pathfinder_esi/app/*` (68 files, namespace `Exodus4D\ESI\` unchanged) → `app/Lib/Esi/`, plus its `LICENSE` (MIT, Mark Friedrich). Kept the namespace and directory layout as-is: the app already uses the same `Exodus4D\` vendor prefix (`Exodus4D\Pathfinder\`), so this needed zero `use`-statement changes in the 5 consumers under `app/Lib/Api/` (verified).
- **composer.json:** removed `goryn-clade/pathfinder_esi`; added psr-4 `"Exodus4D\\ESI\\": "app/Lib/Esi/"`; promoted `guzzlehttp/guzzle: "6.5.*"` from esi's transitive pin to a direct require (no other package's constraint conflicts — checked).
- **composer.lock:** removed the `goryn-clade/pathfinder_esi` package entry; hash refreshed via `composer update --lock` (no resolution change — confirmed same 39 packages install).
- **Removed now-dead dev tooling:** `composer-dev.json` (+ its `.gitignore`/`.dockerignore` entries and `docs/contributing.md` section) — its sole purpose was pointing the ESI *package* at a sibling checkout for local hacking; now that ESI is in-tree there's nothing to swap, just edit `app/Lib/Esi/` directly.
- **Validated:** `composer validate` clean (same 2 pre-existing alias warnings as Phase 2b, not new); full `build`-stage image rebuild — 39 packages install (down from 40), `composer dump-autoload --optimize` generates 705 classes including all 68 `Exodus4D\ESI\*` in the classmap; `php -l` over all 68 moved files — no syntax errors (3 pre-existing PHP 8.4+ nullable-param deprecations, identical to what `vendor/` already had, not new).

**Step B — guzzle 6.5 → 7 migration — deferred, not done here.** Closes the `guzzlehttp/guzzle`/`guzzlehttp/psr7` CVEs `composer audit` still flags (held since Phase 2a specifically because of esi's pin). Blocked on more than a version bump: esi's `Lib/Middleware/GuzzleRetryMiddleware` subclasses `caseyamcl/guzzle_retry_middleware`'s `GuzzleRetryMiddleware` and overrides `__construct()`, which went `final` in caseyamcl 2.6.1 (the exact issue hit in Phase 2b) — bumping caseyamcl past 2.6.0 needs that override reworked (composition instead of inheritance) before the version can move, and guzzle 6→7 itself drags `psr7` 1.x→2.x + `promises` 1.x→2.x across the vendored client/middleware/stream code. Scope unverified — needs its own grep-and-plan pass. Tracked as future work, not gating this phase.

## 4. Sequencing rationale

Cuts before bumps (smaller surface to upgrade). Runtime-safe bumps before the runtime jump — and they double as a **PHP 8 prerequisite** (the frameworks must be 8.5-capable *before* the jump), which is why Phase 1 stays first rather than swapping with Phase 2. The aim throughout is to keep "library churn" failures separable from "language/runtime" failures (no test suite to tell them apart): Phase 1 isolates lib bumps on the old runtime, Phase 2a isolates the runtime jump with deps held, Phase 2b isolates the major-dep API churn. No throwaway intermediates — PHP-gated deps are simply *deferred* to 2b, not stepped through a 2.x version. PHP and Node tracks are independent — Phase 2 and Phase 3 can run in either order or be skipped. Every phase is its own PR gated by the same checklist, so any phase can be the stopping point.

## 5. Smoke-test checklist  *(the gate after every phase)*

No tests means this checklist is the regression suite. Run all of it after each phase; for frontend-only phases the backend items are a quick sanity pass.

1. **Build:** image builds clean through all stages (composer `build`, gulp `assets`, runtime). No warnings that weren't there before.
2. **Boot:** `podman compose up -d --build`; all four services (`pf`, `pfdb`, `redis`, `socket`) healthy; logs clean.
3. **Setup wizard:** `/setup` → "Setup tables" + "Fix columns/keys" for **both** DBs (pathfinder + eve_universe); schema builds; static data imports.
4. **SSO login:** EVE Online OAuth round-trip succeeds; character loads.
5. **Map CRUD:** create a map; add systems; add/drag connections; edit; delete.
6. **Real-time:** open a second session/char; live map updates sync via the socket.
7. **Cron:** scheduled jobs fire (universe / SDE update); check logs for clean runs.
8. **Logging/webhooks:** error log is clean; map/rally webhook handlers still format & send (if configured).
9. **Assets:** correct `public/{js,css,img}/<VERSION>/` output; JS/CSS load; no browser console errors; `.gz`/`.br` variants present.

---

## Appendix A — Frontend overhaul (separate initiative)

**Not part of this dependency update.** Captured here so the scope boundary is explicit and the follow-on project has a starting point. Phase 3 keeps the existing toolchain; this appendix is the *next* project, decided/planned/validated on its own.

### Why it's separate
A modern bundler (Vite/esbuild/Rollup) is ESM-native and **will not consume AMD source**. So this is a UI rewrite, not a tooling swap — a different risk class from version bumps, and with no test suite it is validated entirely by manual smoke testing. Bundling it into the dependency update would let it swallow the whole effort.

### Current state (what has to change)
- ~90 app modules in `js/app/**` as AMD `define([...], fn)`.
- ~40 **vendored** libs in `js/lib/` wired via RequireJS `paths` + `shim`, most jQuery-era and old: **jQuery 3.4.1, Bootstrap 3.3.0 (EOL)**, jsPlumb 2.13, velocity 1.5, select2 4.0, DataTables 1.10.18, summernote 0.8.10, PNotify 4, morris/raphael, farahey, slidebars…
- Templates loaded via the RequireJS `text!` plugin; entry point chosen at runtime from a `data-script` attribute (`login` / `mappage` / `setup` / `admin`).

### Recommended target & approach
- **Bundler: Vite** — closest single-tool replacement for what gulp does here (Rollup bundling, native SCSS, dev server/HMR, asset handling; plugins cover gzip/brotli + image webp). esbuild is faster but less batteries-included; webpack heavier.
- **Migrate per entry point** (natural seams): `login` → `setup` → `admin` → `mappage` (easiest → hardest).
- **The real cost is not the bundler** — it's (1) AMD→ESM for ~90 modules, (2) re-sourcing the ~40 vendored libs from npm and replacing every `shim` with imports/globals (several are dead or globals-only), (3) replacing `text!` template loading, (4) wiring jQuery/Bootstrap as bundler-provided globals.
- **Bleeds into a frontend *dependency* overhaul:** jQuery 3.4 + Bootstrap 3 are themselves EOL. Decide up front whether the goal is "modern build tool" (smaller) or "modern frontend" (much bigger) — they're hard to fully separate.

### Prerequisite
Best started *after* Phase 3 (toolchain already on Node 24), so the overhaul only changes the bundler/source, not the runtime too.

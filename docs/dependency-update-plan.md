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

**Phase 2a — runtime jump only (deps held at their Phase 1 versions).**
1. **Dockerfile / image:** build stage `php:7.2.34-fpm-alpine` → `php:8.5-fpm-alpine`; rebuild pecl `redis` + `zmq` for 8.5; runtime base `trafex/alpine-nginx-php7` → a PHP 8.5 image (e.g. `trafex/php-nginx`); swap `php7-*` Alpine packages → `php85-*`; revisit the pinned composer version. (First confirm 8.5 availability per the ecosystem-lag note in §2; fall back to 8.4 if needed.)
2. **Repoint the platform pin** 7.2.34 → 8.5. Do **not** bump deps yet — the held versions (monolog 2.3.5, php-jwt 6.x, cache 1.x, fatfree 3.9.2, cortex 1.7.8) must boot on 8.5 as-is. This isolates the **8.0 language break** (removed `each`/`create_function`, stricter comparisons, type coercion) and framework-on-8.x issues from any library churn.
   - *Caveat:* if a held PHP-gated dep (most likely monolog 2.3.5) won't run on 8.5, bump that one **straight to its 3.x/latest** here (never to a 2.x intermediate) and fold its API work into 2a.
3. **Hypothesis to validate, not assume:** `fatfree-core` 3.9 and `f3-cortex` 1.7 *run clean* on PHP 8.x. The `>=7.2` constraint only proves they *permit* 8.x. **Fallback** if smoke fails: pin the framework to the last working release / isolate the break; worst case revert to the Phase 1 result.

**Phase 2b — bump the now-unblocked PHP-gated deps straight to latest stable:**
   - `monolog` 2.3.5 → 3.10 — **API changes** (level enums, handler/record signatures, formatter sigs): touches `app/Lib/Monolog.php` + `app/Lib/Logging/*`.
   - `firebase/php-jwt` 6.x → 7.1 — minor API tightening in `Sso.php`.
   - `cache/*` 1.x → 1.2.

**Main risk (both sub-steps):** 2017-era `app/` code hitting 8.0 breaks. Mitigation: incremental, watch logs hard, lean on the checklist.

- **Validate:** full checklist after **each** sub-step **+ extra log scrutiny** (8.0 type/deprecation breakage is silent until exercised).

### Phase 3 — Node toolchain modernization  *(scoped per Decision B)*

Goal: **get the *existing* gulp + RequireJS toolchain onto the current Node LTS (Node 24, Krypton). Replacing RequireJS / modernizing the frontend itself is OUT OF SCOPE — see [Appendix A](#appendix-a--frontend-overhaul-separate-initiative).**

- Bump the `assets` build stage `node:12-bullseye-slim` → `node:24-*`; set `package.json` `engines.node` to 24.
- Migrate `gulpfile.js` CommonJS → ESM (`require`→`import`, `__dirname` shim) — required because the new plugin majors are ESM-only.
- Bump ESM-gated plugins to **latest stable**: `gulp-filter` 10, `gulp-debug` 5, `gulp-autoprefixer` 10, `gulp-imagemin` 9, `imagemin-webp` 8, `pretty-bytes` 7, `fancy-log` 2, `flat` 6.
- Replace deprecated minifier: `uglify-es` → `terser` (rewire `gulp-uglify/composer`, or move to `gulp-terser`).
- **Landmines (may force replacement, flag early):** `gulp-requirejs-optimize` (frozen 2018, **critical path** — if it won't run on Node 24, **stop and escalate**; do **not** silently rewrite the module system — that's Appendix A, not this phase); `gulp-image-resize` (GraphicsMagick, frozen → possibly `sharp`); `jshint`/`gulp-jshint`/`jshint-stylish` (legacy → optionally ESLint, separate call).

- **Validate:** full checklist, frontend focus (JS bundle loads, CSS, webp images, `.gz`/`.br` variants, correct `VERSION` folder, no console errors).

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

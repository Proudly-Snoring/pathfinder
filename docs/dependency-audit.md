# Dependency audit (step 1)

Snapshot of outdated / deprecated / unmaintained dependencies. **List only — no update plan here** (that is step 2).
Data sourced from Packagist / npm registry on 2026-06-21. Installed versions from `composer.lock` / `package-lock.json`.

## Runtime context (the gate that governs everything)

| Runtime  | Pinned in repo                  | Status                  |
|----------|---------------------------------|-------------------------|
| PHP      | `7.2.34` (`pathfinder.Dockerfile` build stage) | EOL since 2020-11 |
| Node.js  | `12.x` (`package.json` engines, `node:12` image) | EOL since 2022-04 |

Many "latest" versions below require a newer runtime. The **Runtime gate** column flags every *verified* floor above the current runtime; "none known" means no floor above PHP 7.2 / Node 12 was found for the latest release (still confirm in step 2). Newer Node build tooling is also largely **ESM-only** — incompatible with the current CommonJS `gulpfile.js` even apart from the Node version.

---

## A. Composer (PHP backend) — direct dependencies

| Package                      | Installed                  | Latest stable | Runtime gate                          | Status / note                                                        |
|------------------------------|----------------------------|---------------|---------------------------------------|----------------------------------------------------------------------|
| swiftmailer/swiftmailer      | v6.2.7                     | v6.3.0        | —                                     | **ABANDONED**. Decision: **remove** (cut the mail system, see §C)    |
| bcosca/fatfree-core          | 3.7.3                      | 3.9.2         | none known (3.9.2 needs PHP ≥7.2)     | Active. In-major-line bump (3.7 → 3.9).                              |
| ikkez/f3-cortex              | dev-master @af03561 (~2021)| v1.7.8        | verify                                | Pinned to a 2021 dev-master commit; tagged releases now exist. Move to a tag. |
| xfra35/f3-cron               | v1.2.1                     | v1.3.0        | none known (PHP ≥7.2)                 | Minor bump.                                                          |
| monolog/monolog              | 2.3.5                      | 3.10.0        | **3.x needs PHP ≥8.1**; 2.11.0 = PHP ≥7.2 | 2.11.0 safe now; 3.x gated by PHP.                              |
| league/html-to-markdown      | 5.0.2                      | 5.1.1         | none known                            | Minor bump.                                                         |
| firebase/php-jwt             | v6.4.0                     | v7.1.0        | **7.x needs PHP ≥8.0; 6.10 needs PHP ≥7.4** | Newer 6.x/7.x raise the PHP floor.                            |
| react/socket                 | v1.9.0                     | v1.17.0       | none known                            | Several minor bumps.                                                |
| react/promise-stream         | v1.3.0                     | v1.7.0        | none known                            | Minor bumps.                                                        |
| clue/ndjson-react            | v1.2.0                     | v1.3.0        | none known                            | Minor bump.                                                         |
| cache/redis-adapter          | 1.1.0                      | 1.2.0         | **1.2.0 needs PHP ≥7.4**              | php-cache org dormant since 2022; 1.2.0 gated by PHP.              |
| cache/filesystem-adapter     | 1.1.0                      | 1.2.0         | **1.2.0 needs PHP ≥7.4**              | same org / same gate                                               |
| cache/array-adapter          | 1.1.0                      | 1.2.0         | **1.2.0 needs PHP ≥7.4**              | same org / same gate                                               |
| cache/namespaced-cache       | 1.1.0                      | 1.2.0         | **1.2.0 needs PHP ≥7.4**              | same org / same gate                                               |
| cache/void-adapter           | 1.0.0                      | 1.2.0         | **1.2.0 needs PHP ≥7.4**              | same org / same gate                                               |

**Composer — current on the stable channel (no action / nuance):**
- `ikkez/f3-sheet` v0.4.3 — already latest.
- `goryn-clade/pathfinder_esi` v2.1.4 — latest **stable**; only a pre-release (`v3.0.0-beta.1`, May 2026) is newer. Note: production `composer.json` uses the **goryn-clade fork**, while `composer-dev.json` points at the upstream `exodus4d/pathfinder_esi` (`dev-develop`). Picking the step-2 target means picking a fork.

---

## B. npm (frontend build toolchain) — direct devDependencies

### B.1 Deprecated / unmaintained — replacement needed

| Package                  | Installed | Latest  | Status / replacement                                                            |
|--------------------------|-----------|---------|---------------------------------------------------------------------------------|
| node-sass                | 8.0.0     | 9.0.0   | **DEPRECATED** ("no longer supported") → `sass` (dart-sass, already a dep)       |
| uglify-es                | 3.3.9     | 3.3.9   | **DEPRECATED** → `terser` (or `uglify-js` ≥3.13 for ES support)                  |
| babel                    | 6.23.0    | 6.23.0  | **DEPRECATED** (Babel 6 meta-package) → `@babel/*`; first confirm it is even used |
| gulp-image-resize        | 0.13.1    | 0.13.1  | Frozen ~2018; needs GraphicsMagick on PATH → consider `sharp`/`gulp-sharp`        |
| gulp-jshint              | 2.1.0     | 2.1.0   | Frozen ~2018 → ESLint-based flow                                                |
| jshint-stylish           | 2.2.1     | 2.2.1   | Frozen 2016 (reporter for jshint)                                               |
| jshint                   | 2.13.6    | 2.13.6  | Maintained but legacy linter → ESLint (modern)                                  |
| gulp-requirejs-optimize  | 1.3.0     | 1.3.0   | Frozen 2018; RequireJS itself is legacy (whole module strategy is a step-2 topic)|

### B.2 Newer version available

| Package            | Installed | Latest  | Runtime / ESM gate                        | Note                                  |
|--------------------|-----------|---------|-------------------------------------------|---------------------------------------|
| gulp               | 4.0.2     | 5.0.1   | none (Node ≥10.13, CommonJS)              | Safe on Node 12.                      |
| sass               | 1.62.0    | 1.101.0 | none known                                | dart-sass; replacement for node-sass. |
| gulp-sass          | 5.1.0     | 6.0.1   | verify                                    | Major bump.                           |
| gulp-autoprefixer  | 8.0.0     | 10.0.0  | **ESM, newer Node**                       | Gated.                                |
| gulp-imagemin      | 7.1.0     | 9.2.0   | **ESM, Node ≥18**                         | Gated.                                |
| imagemin-webp      | 6.1.0     | 8.0.0   | **ESM (imagemin chain)**                  | Gated.                                |
| gulp-filter        | 7.0.0     | 10.0.0  | **ESM, Node ≥22**                         | Gated.                                |
| gulp-debug         | 4.0.0     | 5.0.1   | **ESM, Node ≥18**                         | Gated.                                |
| pretty-bytes       | 5.6.0     | 7.1.0   | **ESM, Node ≥20**                         | Gated.                                |
| fancy-log          | 1.3.3     | 2.0.0   | verify                                    | Major bump.                           |
| flat               | 5.0.2     | 6.0.1   | verify                                    | Major bump.                           |
| slash              | 4.0.0     | 5.1.0   | ESM (4.x already ESM)                     | Minor.                                |
| gulp-rename        | 2.0.0     | 2.1.0   | none                                      | Minor.                                |
| ansi-colors        | 4.1.1     | 4.1.3   | none                                      | Patch.                                |
| node-notifier      | 10.0.0    | 10.0.1  | none                                      | Patch.                                |

**npm — already current (no action):**
`gulp-uglify` 3.0.2, `gulp-clean-css` 4.3.0, `gulp-sourcemaps` 3.0.0, `gulp-brotli` 3.0.0, `gulp-gzip` 1.4.2, `gulp-if` 3.0.0, `gulp-bytediff` 1.0.0, `lodash.padend` 4.6.1, `promised-del` 1.0.2, `terminal-table` 0.0.12 (frozen), `file-extension` 4.0.5 (frozen).
Note: `gulp-uglify` is current but the build feeds it the deprecated `uglify-es` as a custom minifier — see B.1.

---

## C. Dependencies we can cut (unused / redundant / decommissioned)

Verified by usage grep across `app/` and `gulpfile.js`. Removing these shrinks the tree before any upgrade work.

| Dependency               | Eco      | Reason to cut                                                                                       | Effort |
|--------------------------|----------|----------------------------------------------------------------------------------------------------|--------|
| swiftmailer/swiftmailer  | composer | **Decommission the mail system** (user decision: unused, untestable). Scope below.                 | medium |
| cache/void-adapter       | composer | Unused — no `VoidCachePool` reference anywhere in `app/` (other 4 cache adapters are used).         | trivial |
| babel                    | npm      | Never `require()`d in `gulpfile.js`; no `.babelrc`; not used anywhere. Dead devDependency.          | trivial |
| node-sass                | npm      | Redundant: active Sass compiler is dart-sass (`sass`, gulpfile L19). L45 `sass.compiler = node-sass` is vestigial under gulp-sass 5. Also deprecated. | trivial |
| slash                    | npm      | Never `require()`d in `gulpfile.js`; the other repo hits are PHP path strings / a FontAwesome icon. | trivial |

**Mail system removal — scope (swiftmailer):** the mail path is a Monolog handler, not a standalone mailer. Touch points:
- `app/Lib/Logging/AbstractLog.php` — `getHandlerParamsMail()` (the only `Swift_*` usage) + the `'mail'` case in the handler switch.
- `app/Lib/Monolog.php` — `'mail'` entries in the handler/formatter maps.
- `app/Lib/Logging/Formatter/MailFormatter.php` — delete.
- `app/Lib/Logging/UserLog.php` (mail handler already commented out) and `RallyLog.php` — drop the `'mail'` handler config.
- `templates/mail/*` templates; SMTP keys in `app/environment.ini` / `app/pathfinder.ini` (+ the rendered `deployment/pathfinder/environment.ini`).
- Note: `MailFormatter` uses F3's built-in `\Markdown` (Markdown→HTML), **not** `league/html-to-markdown` — so html-to-markdown stays (it is used by the webhook/rally logging).

**Not cuttable (checked, in use):** `firebase/php-jwt` (SSO, `Sso.php`), `ikkez/f3-sheet` (`AbstractModel`), `league/html-to-markdown` (webhook logging), the React/clue socket stack, and the other 4 `cache/*` adapters.

### Phantom (undeclared) build deps — fix while here
`gulpfile.js` `require()`s **`ini`**, **`minimist`** and **`lodash.merge`**, none of which are in `package.json` (they resolve only via transitive install). Declare them explicitly when reworking the npm manifest — this is an *add*, not a cut, but it belongs to the same pass.

## Cross-cutting findings (carry into step 2)

1. **Two EOL runtimes are the spine.** Most "newer major" jumps (monolog 3, php-jwt 7, cache/* 1.2, all ESM gulp plugins) are blocked until PHP and/or Node are bumped. Deciding the target PHP and Node versions is the first step-2 decision.
2. **PHP path:** staying on 7.2 still allows fatfree 3.9, f3-cron 1.3, html-to-markdown 5.1, react/* minors, monolog 2.11. PHP 7.4 unlocks cache/* 1.2 and php-jwt 6.10. PHP 8.1 unlocks monolog 3. (Mail/swiftmailer is being removed, so it no longer constrains the PHP target.)
3. **Node/ESM path:** gulp 4→5 is free on Node 12, but every other current build plugin needs Node 18–22 **and** an ESM `gulpfile.js`. The frontend toolchain is effectively an all-or-nothing migration.
4. **Cuts/replacements independent of runtime:** remove swiftmailer (decommission mail), drop node-sass (dart-sass already in use), drop unused `cache/void-adapter` / `babel` / `slash`; replace uglify-es→terser.
5. **Two "unpinning" tasks:** `ikkez/f3-cortex` off the 2021 dev-master commit onto a tag; decide the `pathfinder_esi` fork/target.

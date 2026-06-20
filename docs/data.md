# Data reference

A map of every datastore the app uses, **what** it holds, **how it is imported/seeded**, and **how it is kept up to date at runtime**. Scoped to this repo's podman stack (`compose.yaml`, `pathfinder.Dockerfile`).

## Stores at a glance

| Database       | Backend     | Holds                                                      |
|----------------|-------------|------------------------------------------------------------|
| `pathfinder`   | MariaDB     | operational app data (maps, chars, logs, sessions)         |
| `eve_universe` | MariaDB     | local mirror of CCP static universe data (seeded from SDE; refreshed via ESI) |
| `eve_ccp`      | MariaDB     | **unused** in this version (legacy SDE slot)               |

| Store       | Backend     | Holds                                                      |
|-------------|-------------|------------------------------------------------------------|
| Redis       | Redis       | PHP-native session fallback only                           |
| App cache   | Filesystem  | ESI responses, DB query/schema cache, systems search index |
| Map history | Filesystem  | per-map activity log JSON                                  |

## 1. The `pathfinder` database (alias `PF`)

The operational database.
Models are defined in `app/Model/Pathfinder/*`.

**What it holds**
- Maps & topology: `map`, `system` (systems placed on a map), `connection`, `connection_log`, `connection_scope`, `system_signature`.
- Identity & access: `user`, `character`, `user_character`, `corporation`, `alliance`, `*_map` link tables, `right`, `role`, `corporation_right`, `corporation_structure`.
- Live system stats: `system_jumps`, `system_kills_ships`, `system_kills_pods`, `system_kills_factions` (24 rolling hourly columns `value1..value24` each).
- Bookkeeping: `character_log`, `character_authentication` (cookie auth), `activity_log`, `cron`, `sessions`, `structure`.

**Creation**
- The schema is built by the `/setup` wizard ("Setup tables" + "Fix columns/keys"). Cortex creates tables from the model `$fieldConf`.
- Not seeded. All rows are produced at runtime by users and crons.

**Live updates**
- **User actions** → REST API (`app/Controller/Api/*`) write maps/systems/connections/signatures, then broadcast the delta over the websocket (ZMQ → `socket` service) to other clients.
  Nothing is polled; edits push.
- **Character data** (location, ship, online, roles, clones): fetched from ESI on demand per character via `CharacterModel`, using that character's stored token.
- **ESI auth tokens** live on the `character` row (`esiAccessToken`, `esiAccessTokenExpires`, refresh token).
  `CharacterModel::getAccessToken()` returns the cached access token until it expires, then silently exchanges the refresh token at the SSO endpoint and rewrites the row.

**Cron jobs**
  - `importSystemData` (hourly, `@halfPastHour`) — `CcpSystemsUpdate`: pulls `getUniverseJumps` + `getUniverseKills` from ESI and rolls them into `system_jumps` / `system_kills_*` (k-space systems only).
  - `MapUpdate` — deletes EOL/expired connections, expired signatures, deactivates & deletes stale maps.
  - `CharacterUpdate` — prunes character logs, kick/ban cleanup, expired cookie auth.
  - `StatisticsUpdate` — prunes old `activity_log`.

## 2. The `eve_universe` database (alias `UNIVERSE`)

A **local mirror / cache** of CCP's static New Eden data.
Models are defined in `app/Model/Universe/*`.

**What it holds**
- Map hierarchy: `region` → `constellation` → `system` → `star`, `planet`, `stargate`, `station`, `structure`.
- Item data: `category` → `group` → `type`, plus `dogma_attribute`, `type_attribute`.
- Gameplay overlays: `system_static` (wormhole statics per system), `sovereignty_map`, `faction_war_system`, `faction`, `race`, `alliance`, `corporation`.
- `system_neighbour` — precomputed adjacency index for route search (see §4).

**Creation**
1. **`/setup` admin buttons** — the browser drives a chunked AJAX loop (`js/app/setup.js` → `Api/Setup::buildIndex`). The bulk seed is sourced from the **CCP SDE** (JSON Lines), not ESI:
   - _EVE SDE download_ → fetch the SDE zip (~84 MB) to `tmp/sde/` (`Lib\Sde\Archive`). **Run this first**; the imports below read from it. "Clear" deletes the downloaded/extracted files. Extraction uses PHP's `ZipArchive`, so the image now ships `php7-zip` (`pathfinder.Dockerfile`).
   - _Systems data_ → regions, constellations, systems (+ stars, planets) in FK order (`Lib\Sde\Importer::importSystems`). Derives wormhole `security` (class from `mapSolarSystems`/constellation/region `wormholeClassID`) and `effect` (from `mapSecondarySuns` typeID) — both of which ESI cannot supply.
   - _Stargates data_ → stargate connections (`importStargates`; systems must exist).
   - _Wormholes / Structures / Ships data_ → `type`/`group`/`category` rows for those categories (`importTypesByGroup`/`importTypesByCategory`); wormholes also import dogma attributes (mass/security) from `typeDogma`.
   - _Wormhole statics data_ → `system_static`, loaded from `data/system_static.csv` (not in the SDE - needs wormhole `type` rows first).
   The SDE→ESI-shape adapters in `Lib\Sde\Importer` feed the existing Cortex models (`copyfrom` + `save`); referenced celestial/dogma rows absent from the SDE buttons (star/planet/stargate types, dogma attributes) are created on demand from the SDE.
2. **CLI cron** — `Cron\Universe->setup` (`type=system|stargate`), the bulk equivalent of the buttons, no nginx timeout. See `containers/README.md`. (Still ESI-backed.)

**Live updates**
- **Lazy per-row refresh.** `AbstractUniverseModel::loadById()` reads from DB/cache, and only re-fetches from ESI when `isOutdated()` is true — i.e. the row's `updated` timestamp is older than `CACHE_MAX_DAYS = 60` days (`app/Model/AbstractModel.php`). So static data is fetched once and refreshed at most every 60 days, on access.
- **Sovereignty & faction warfare** — `updateSovereigntyData` cron (`@halfPastHour`, every 30 min) → `Cron\Universe`, refreshes `sovereignty_map` / `faction_war_system` from ESI. These are the only genuinely time-sensitive rows here.

**Cron jobs**
- `updateUniverseSystems` cron exists but is **disabled (WIP)** in `app/cron.ini`.

---

## 3. `eve_ccp` DB (alias `CCP`)

Configured (`DB_CCP_*` in `environment.ini`, `MYSQL_CCP_DB_NAME` in `.env`) and created by `init-databases.sh`, but **no code references the `CCP` alias in this version** (`getDB('CCP')` appears nowhere). It is a legacy slot for the old CCP database export (SDE MySQL conversion) and stays empty. Safe to ignore; left in place so config matches upstream.

---

## 4. Search indexes

Two "indexes" are built by `/setup` → *Build search index* from `eve_universe` data:
- **Systems data index** — lives in the **app cache** (filesystem `tmp/cache/`), not a DB table. Built by `UniverseController::buildSystemsIndex()` from the `system` table for fast name search. Lost on cache clear / rebuild; rebuilt on demand.
- **Systems neighbour index** — a real DB table, `eve_universe.system_neighbour`, derived from `stargate` connections. Used as the offline fallback for route search when ESI is down (`app/Controller/Api/Rest/Route.php`). Requires stargates to be imported first.

---

## 5. Caches & ephemeral stores

**App cache — filesystem.** `CACHE = folder=tmp/cache/` and `API_CACHE = {{@CACHE}}` (`config.ini`; *not* overridden in the container — `entrypoint.sh` only `envsubst`s, and these lines have no env vars). Despite model comments saying "RAM"/Redis, in this stack everything caches to `pathfinder/tmp/cache/`:
- ESI HTTP responses (honoring each response's `Expires` header — this is what makes repeated `getUniverseSystems` etc. cheap),
- DB query cache + DB schema cache,
- the systems search index (§4),
- CSV import temp data (`DEFAULT_CACHE_CSV_TTL`).

`tmp/cache/` is **not a volume** → a `--build` rebuild wipes it. Consequence: ESI cache and the systems search index are lost and lazily rebuilt, but `eve_universe`/`pathfinder` data on the `db_data` volume survive. Clearable from `/setup` ("Delete files").

**Redis.** The container's `containers/php/php.ini` sets `session.save_handler = redis`, but app requests register the MySQL session handler instead (§1), so Redis only catches PHP-native sessions from requests that don't bootstrap F3 (observed: a single `PHPREDIS_SESSION:*` key). Redis is **not** the app cache in this configuration.

**Map history.** `pathfinder/history/map` (volume `pf_history`, shared with the `socket` service) holds per-map activity JSON logs. Written by the app, consumed by the websocket server, truncated by the `truncateMapHistoryLogFiles` cron (`@halfHour`).

---

## 6. Cron schedule summary

Defined in `app/cron.ini`, run by busybox-cron + `app/Lib/Cron.php`.

| Job                                                                     | Schedule       | Target       | Purpose                                           |
|-------------------------------------------------------------------------|----------------|--------------|---------------------------------------------------|
| `importSystemData`                                                      | hourly :30     | PF           | ESI jumps/kills → `system_jumps`/`system_kills_*` |
| `updateSovereigntyData`                                                 | every 30 min   | UNIVERSE     | ESI sov + faction war                             |
| `deleteEolConnections`                                                  | every 5 min    | PF           | drop EOL connections                              |
| `deleteSignatures`                                                      | every 30 min   | PF           | drop expired signatures                           |
| `deleteExpiredConnections` / `deactivateMapData`                        | hourly         | PF           | connection/map cleanup                            |
| `truncateMapHistoryLogFiles`                                            | every 30 min   | `pf_history` | trim history logs                                 |
| `deleteLogData`                                                         | every min      | PF           | trim character logs                               |
| `cleanUpCharacterData`                                                  | hourly         | PF           | kick/ban cleanup                                  |
| `deleteMapData` / `deleteAuthenticationData` / `deleteExpiredCacheData` | downtime 11:00 | PF / cache   | downtime cleanup                                  |
| `deleteStatisticsData`                                                  | weekly         | PF           | trim activity log                                 |
| `setup`, `updateUniverseSystems`                                        | **disabled**   | UNIVERSE     | bulk/WIP universe import (see §2)                 |

---

## 7. What survives what

| Operation                       | `db_data` (PF + universe) | `redis_data` | `pf_history` | `tmp/cache`         |
|---------------------------------|---------------------------|--------------|--------------|---------------------|
| `podman compose up -d --build`  | ✅ kept                  | ✅ kept      | ✅ kept     | ❌ wiped (in image) |
| `podman compose down` (no `-v`) | ✅ kept                  | ✅ kept      | ✅ kept     | ❌                  |
| `podman compose down -v`        | ❌ gone                  | ❌ gone      | ❌ gone     | ❌                  |

After a rebuild the only user-visible cost is ESI re-caching and a one-click rebuild of the systems search index; the heavy `eve_universe` import does **not** need to be repeated.

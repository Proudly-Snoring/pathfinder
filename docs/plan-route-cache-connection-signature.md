# Plan: connection-state-aware route caching

Fix: route results don't refresh when a wormhole status (lifetime/mass/size/scope) changes,
even on the route panel "refresh" button. Root cause is caching, not calculation.

Backend-only change (`app/Controller/Api/Rest/Route.php`). No frontend / `PATHFINDER.VERSION` bump.

---

## 1. Root cause (confirmed)

Two server caches key on inputs that ignore connection state, so an unchanged route filter
returns a stale route until the TTL lapses. Both currently share `dynamicJumpDataCacheTime = 10s`.

| Cache | Location | Keyed on | Problem |
|-------|----------|----------|---------|
| Route-result cache | `getRouteCacheKey()` (727), used in `post()` (838-858) | `mapIds + systemFrom + systemTo + filterData` | no connection state |
| Inner SQL query cache | `setDynamicJumpData()` `exec()` (216) | raw SQL text (query identical when filter unchanged) | no connection state |

- Changing a **filter setting** alters `filterData` → new key → instant recompute (why settings "work").
- Changing a **connection** leaves `filterData` identical → same key → stale for up to 10s.
- The panel **refresh** re-sends identical `filterData` → same server key → still stale (it only
  busts the *client* cache).
- Connection change **does persist** cleanly: `Connection::post` → `copyfrom(...,['scope','type'])`
  → `set_type` overwrites the `type` column. So the stored graph is correct; only the cache is stale.
  Symptom is therefore transient (self-heals in ≤10s), never permanent.

Both mass and lifetime fail identically because they share this one route path — a shared-cache
symptom, not a per-dimension bug.

## 2. Fix strategy

Make both caches **connection-state-aware** via a cheap per-map-set *signature*. Then a long TTL is
both safe and correct: any connection change instantly yields a new key (fresh result); unchanged
maps hit cache for the full TTL. This fixes the staleness *and* removes the "can't cache long" limit
(both were the same root problem: a state-blind key protected only by a short TTL).

Rejected alternative — purge route caches from `ConnectionModel::afterUpdateEvent`: route keys are
`route_<md5>` hashes; F3 has no prefix-clear and can't enumerate "keys for map X" without a new
secondary index. Signature-keying self-invalidates (stale entries become unreachable, expire on TTL)
with no bookkeeping.

## 3. The signature

Per map-set aggregate over **active** connections. Must change on any routing-relevant edit
(insert, delete, or change to a field the route query filters on: `scope`, `type`, `source`,
`target`, `sourceEndpointType`, `targetEndpointType`).

```sql
SELECT
    COUNT(*) num,
    COALESCE(SUM(CRC32(CONCAT_WS('#',
        `id`, `scope`, `type`, `source`, `target`, `sourceEndpointType`, `targetEndpointType`
    ))), 0) checksum
FROM `connection`
WHERE `active` = 1 AND `mapId` <= | IN (...)>
```

Signature string = `"{num}-{checksum}"`.

- **Content checksum, not `MAX(updated)`.** `updated` is 1s-resolution `TIMESTAMP`; `COUNT + MAX(updated)`
  aliases when two edits land in the same wall-clock second (e.g. collaborative WH *rolling* — bursts
  of same-second mass/lifetime edits), leaving the cache stale until a later-second edit — the exact
  reported symptom. A CRC32 content sum changes whenever any routing-relevant field changes, regardless
  of clock resolution. `COUNT` covers insert/delete; `CRC32` covers field edits. (Theoretical CRC32
  collision risk is negligible at map scale; `COUNT` is a cheap extra guard.)
- Confirmed load-bearing facts: `AbstractModel::set()` touches on any field change (not needed for the
  checksum, but confirms edits persist); `CONCAT_WS` skips NULL endpoint types; `type` is JSON text →
  concatenated as string.
- The signature query runs **uncached** (`ttl = 0`) so it always reflects current DB state. It is one
  small indexed aggregate — negligible vs. the BFS it guards.

## 4. Edits (`app/Controller/Api/Rest/Route.php`)

### 4a. TTL bump
`$dynamicJumpDataCacheTime = 10;` → `= 300;` (line 40). Update the doc comment.

### 4b. New helper `getConnectionSignature(array $mapIds) : string`
Runs the §3 query with the same `intval` sanitisation + `whereMapIdsQuery` construction already used
in `setDynamicJumpData` (119, 123). Returns `"0"` for empty `$mapIds`. `exec(..., null, 0)` — never cached.

### 4c. Outer route-result cache — `post()` (~838-858)
- `getRouteCacheKey($mapIds, $systemFrom, $systemTo, $filterData)` → add a `$signature` param
  (append to `$keyParts` before the md5).
- Compute `$connectionSignature = $this->getConnectionSignature($mapIds);` inside the
  `!skipSearch && count(mapIds) > 0` block, pass to `getRouteCacheKey`.
- **Thera cap (blocks otherwise).** Thera hops come from `setTheraJumpData()` (eve-scout, external,
  `theraJumpDataCacheTime = 60s`) and are **not** in the `connection` table, so the signature can't see
  a Thera hole appearing/collapsing. A 300s outer cache would serve stale Thera routes → regression of
  the very bug class we fix. So cap the outer `$f3->set` TTL when Thera is on:
  ```php
  $ttl = $filterData['wormholesThera']
      ? min($this->dynamicJumpDataCacheTime, $this->theraJumpDataCacheTime)
      : $this->dynamicJumpDataCacheTime;
  $f3->set($cacheKey, $returnRoutData, $ttl);
  ```
  Inner query is unaffected (Thera isn't in it).

### 4d. Inner SQL query cache — `setDynamicJumpData()` (~196-216)
Append the signature as a SQL comment so the query text (hence F3's cache hash) changes with state:
```php
$signature = $this->getConnectionSignature($mapIds);
$query .= "\n/* sig:" . $signature . " */";
$rows = $this->getDB()->exec($query, null, $this->dynamicJumpDataCacheTime);
```
Verified in `vendor/bcosca/fatfree-core/db/sql.php:192`: the cache hash is
`$fw->hash($this->dsn . $cmd . stringify($args))` — raw SQL, **no comment stripping** → the comment
is part of the key. (If this ever changes upstream, fallback: cache the computed `$jumpData` yourself
under an explicit signature-keyed `$f3->set` and set the inner `exec` TTL to 0.)

`setStaticJumpData()` (K-space stargates, 86400s) is untouched — universe-static, not map connections.

## 5. Residual staleness after fix

| Scenario | Before | After |
|----------|--------|-------|
| Connection lifetime/mass/size/scope change | ≤10s (felt permanent on refresh) | next request (signature changes) |
| Connection add / delete | ≤10s | next request (COUNT changes) |
| Thera hole appear/collapse (Thera on) | ≤10s | ≤60s (own cache; outer TTL capped) |
| No change | 10s cache | 300s cache |

## 5b. Force-refresh (user-initiated) — bypass the route cache

A user click on a refresh control must **force a fresh calculation regardless of cache state**
(the signature keeps the cache correct on connection edits, but the user still wants an explicit
"recompute now").

- **Client** (`js/app/ui/module/system_route.js`): set `forceSearch = 1` on the request `routeData`
  for the two user-initiated paths — the per-row reload button and the toolbar refresh-all
  (`updateRoutesTable`). It is set transiently on the request object only; it is **not** stored in
  `tableRowData` nor read back by `getRouteRequestDataFromRowData`, so normal/auto requests never force.
- **Server** (`post()`): `$forceSearch = (bool)($routeData['forceSearch'] ?? false);` and skip the
  cache read (`if(!$forceSearch && $f3->exists(...))`). The result is still (re)written to the cache,
  so later non-forced requests benefit.

Scope of the bypass:
- Recomputes the BFS against the **current** connection graph (the inner jump-data query is already
  signature-fresh, so no separate inner bypass is needed).
- Does **not** force a Thera re-fetch: `setTheraJumpData()` hits the external eve-scout API and keeps
  its own 60s cache; forcing it on every click risks rate-limiting. Thera still refreshes within ~60s.

## 6. Manual test checklist

- Route filter min = `< 4h`; a WH on the route at `< 1h` → route excludes it. Change that WH to `< 4h`
  on the map → next route request (incl. panel refresh) includes it, **without** touching the filter.
- Same for mass (`critical` → `reduced`) and size.
- Delete a connection on the route → route updates on next request.
- Two quick same-second edits to different connections on the route → both reflected (checksum, not
  timestamp).
- Unchanged route + filter → served from cache (no recompute) across repeated requests within 5 min.
- Thera route: a Thera change still reflects within ~60s.
- Force-refresh: with no connection change at all, the per-row reload button and the toolbar refresh
  both trigger a server recompute (not served from cache) — confirm via a fresh route result / server
  log rather than the ≤5s client cache.

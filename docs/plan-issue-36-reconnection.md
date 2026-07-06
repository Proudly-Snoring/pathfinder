# Plan — resilient reconnection (issue #36)

Fixes "inopportune disconnections": transient failures currently end at a hard
"Logged out" screen with no retry. Goal: degrade gracefully, keep retrying,
inform the user, and only log out on a genuine auth loss.

Investigation: see issue #36 comment. Summary of current behaviour:

| Mechanism | Location | Today |
|-----------|----------|-------|
| A — any failed ping = shutdown | `js/app/mappage.js:539` `handleAjaxErrorResponse` | `clearUpdateTimeouts()` + `pf:shutdown`, no retry (covers `status 0` and HTML/5xx) |
| B — 200 body with `error[]` = active logout | `js/app/mappage.js:478`, `:395` | calls `Util.logout()`, destroys session server-side |
| C — auth 403 | `app/Controller/AccessController.php:29` → `Controller.php:533` | genuine but often transient (session/`Pf-Character` loss); generic message |
| WS drop | `js/app/worker/map.js:56` | silent, no reconnect (not a logout) |

## Decisions (agreed)

- **Logout signal = server `reroute` field** (`Controller.php:532`), *not* an HTTP
  status. Backend emits `403` for auth, never `401`, so a status list is unreliable;
  `reroute` is already set on real logout and already read client-side (`mappage.js:560`).
- **Retry with exponential backoff** (server may be under load on 5xx), not fixed interval.
- **On-screen errors via existing `Util.showNotify`** (PNotify), not a shutdown dialog.
- **Refresh is the modal CTA** — a full reload re-syncs cleanly; resuming risks stale
  map state / unsaved local edits diverging.

## Non-goals

- No change to the WebSocket server protocol.
- No backend switch to `401` (keying on `reroute` avoids it).
- No offline-edit conflict resolution — a refresh is the safe resync path.

---

## Target model

Connection state machine driven by the ajax ping heartbeat
(`triggerMapUpdatePing` / `triggerUserUpdatePing`):

```
online ──ping fails (transient)──> reconnecting ──ping ok──> online
                                        │
                                        └─ after RECONNECT_TIMEOUT (60s) ─> modal (Refresh / Logout)

any response with `reroute` (real auth loss) ──> redirect to login  (no retry)
```

| Response | Classification | Action |
|----------|---------------|--------|
| `status 0`, `>= 500`, non-JSON/HTML | transient | banner + notify, keep retrying (backoff) |
| 200 with `data.error[]`, no `reroute` | app error | notify on screen, **no logout**, keep running |
| any response with `reroute` set | auth loss | immediate redirect to login |

---

## Step 1 — retry loop + connection banner (core)

The heart of the fix: the ping loop must survive failures instead of tearing down.

- Rewrite `handleAjaxErrorResponse` (`mappage.js:539`):
  - If `jqXHR.responseJSON.reroute` → keep current behaviour (`pf:shutdown` with
    `redirect`, i.e. real logout). This is the *only* logout path.
  - Otherwise → mark connection lost (do **not** `clearUpdateTimeouts` permanently),
    show/keep the banner, `Util.showNotify` the error, and **reschedule the ping**
    with backoff instead of shutting down.
- Add a `connectionState` module var + a `pf:connectionLost` / `pf:connectionRestored`
  event pair (mirror the existing `pf:syncStatus` pattern, `util.js:2212`).
- On a successful `.done` (`mappage.js:468`, `:383`): reset backoff, clear the banner,
  fire `pf:connectionRestored`, `setProgramStatus('online')`.
- Backoff: base = current trigger delay (`Util.getCurrentTriggerDelay`), then
  `min(base * 2^n, cap)`; cap via config (see Step 4 config). Reset on success.
- **Banner**: persistent bar at top of viewport, reusing notification styling
  (`txt-color-warning`). Shown on `pf:connectionLost`, hidden on
  `pf:connectionRestored`. Text hints "Reconnecting… unsaved changes may be lost."
  Wire the listener where other document observers live (`page.js`, near `:859`).

Files: `js/app/mappage.js`, `js/app/page.js`, `js/app/util.js`, banner markup/SCSS.

## Step 2 — escalation modal (60s)

- Start a timer when entering `reconnecting`; cancel it on `pf:connectionRestored`.
- On expiry (`RECONNECT_TIMEOUT`, default 60s, configurable) show a modal via the
  existing `$.fn.showNotificationDialog` helper (`page.js:899`) with two buttons:
  - **Refresh page** — CTA (`btn-primary`) → `location.reload()`.
  - **Logout** → `Util.triggerMenuAction(document, 'Logout')`.
- Keep retrying in the background while the modal is open; if a retry succeeds,
  auto-dismiss the modal + clear the banner.

Files: `js/app/mappage.js` (timer), `js/app/page.js` (modal wiring).

## Step 3 — stop treating in-body `error[]` as logout (Mechanism B)

- In both `.done` handlers (`mappage.js:478` and `:390-395`): remove the
  `Util.triggerMenuAction('Logout')` branch. Instead:
  - If the payload carries `reroute` → redirect (auth loss).
  - Else → `Util.showNotify` each error, mark connection degraded, keep running.
- Confirm no server path returns an auth failure *only* inside a 200 `error[]`
  without `reroute` (grep `updateMapData` / `updateUserData` error assembly in
  `app/Controller/Api/Map.php`). If one exists, add `reroute` there or a
  `type: 'logout'` flag the client keys on.

Files: `js/app/mappage.js`, possibly `app/Controller/Api/Map.php`.

## Step 4 — on-screen error surface + config

- Route all transient/app errors through `Util.showNotify` (already used at
  `page.js:903`) so they are visible but non-blocking. Also fix the display bug:
  synthesized error uses `message` but the dialog reads `error.text`
  (`mappage.js:567` vs `page.js:895`) → normalise the field.
- Add config section (mirrors `[PATHFINDER.TIMER.*]`, `app/pathfinder.ini:238+`):

  ```ini
  [PATHFINDER.TIMER.CONNECTION]
  ; Time (ms) a lost connection may keep retrying before the recovery modal is shown
  RECONNECT_TIMEOUT   =   60000
  ; Max backoff delay (ms) between reconnection attempts
  RECONNECT_MAX_DELAY =   30000
  ```

  Surfaces automatically to the client as `Init.timer.connection.*` via
  `Map.php:62` (`$return->timer = Config::getPathfinderData('timer')`). No extra
  server wiring needed; read it in `mappage.js` like other `Init.timer` values.

Files: `app/pathfinder.ini`, `js/app/mappage.js`.

## Step 5 — WebSocket auto-reconnect (SEPARATE / independent) — IMPLEMENTED

Independent of Steps 1–4; the ajax heartbeat already keeps the app alive, so this
is pure realtime-sync resilience.

- SharedWorker (`js/app/worker/map.js`) now stores the connection `uri` and, on a
  non-clean `socket.onclose`, schedules its own reconnect with bounded exponential
  backoff (base 1s, cap `RECONNECT_MAX_DELAY`, default 30s). Stops once reopened
  (`onopen` clears the backoff) or once the last port disconnects (`sw:closePort`
  with no ports left).
- `RECONNECT_MAX_DELAY` is threaded from `Init.timer.CONNECTION.RECONNECT_MAX_DELAY`
  through `MapWorker.init({reconnectMaxDelay, ...})` (`js/app/map/worker.js`) into the
  worker's `ws:init` message, so it stays configurable from `pathfinder.ini`.
- Guarded `socket.send()` in `ws:send`/`sw:closePort` against a null/closed socket
  (previously threw during a reconnect gap).
- **Decision (deviates from the original sketch):** did *not* wire WS
  onClosed/onError directly into the ajax `pf:connectionLost`/`Restored` banner
  events. Doing so races with the ajax retry state machine — if WS reopens while an
  ajax retry is still in flight, it would hide the banner and desync
  `reconnectState.active`, permanently suppressing future banner shows. Instead, WS
  loss already triggers `setSyncStatus('ws:closed') → ajax:enable`, which
  immediately re-enables full ajax polling as a fallback (existing code) — so a
  real, sustained outage still surfaces through the ajax path (Step 1) and the
  small header sync indicator (`util.js:2198`) continues to reflect WS state on its
  own. No logout on WS drop, either way.

Files: `js/app/worker/map.js`, `js/app/map/worker.js`, `js/app/mappage.js`, `app/pathfinder.ini`.

---

## Files touched (summary)

| File | Steps |
|------|-------|
| `js/app/mappage.js` | 1, 2, 3, 4, 5 |
| `js/app/page.js` | 1, 2 |
| `js/app/util.js` | 1 (events/state), 4 (notify) |
| `app/pathfinder.ini` | 4 |
| `app/Controller/Api/Map.php` | 3 (only if an auth error hides in a 200 body) |
| `js/app/worker/map.js`, `js/app/map/worker.js` | 5 |
| banner markup + SCSS | 1 |

Bump `PATHFINDER.VERSION` in `app/pathfinder.ini` when shipping (frontend asset
cache-buster) — only on release, per AGENTS.md.

## Testing

- **status 0**: throttle offline mid-session → banner appears, pings retry, restore
  on reconnect; no logout. After 60s offline → modal; Refresh reloads.
- **5xx / HTML**: stub a 502 on `updateUserData` → notify + banner, no shutdown.
- **200 + error[]**: inject an app error → on-screen notice, session survives.
- **real logout**: force `reroute` (expired session / kicked) → immediate redirect,
  no retry loop.
- **WS drop (Step 5)**: kill the ws upstream → worker reconnects with backoff,
  `subscribe` re-sent, no logout; ajax heartbeat unaffected.

## Risks / open items

- Backoff must not starve the 60s modal timer — run them independently.
- Confirm no auth-critical error is delivered *only* via a 200 `error[]` without
  `reroute` (Step 3 grep) before removing the auto-logout branch.
- Banner z-index vs existing fixed UI (header/footer) — verify no overlap.

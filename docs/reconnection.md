# Connection resilience

Two independent transports can drop: the ajax ping loop (map/user data) and the SharedWorker's
WebSocket. Both now retry with backoff instead of forcing a logout.

## Ajax ping loop (`js/app/mappage.js`)

- `triggerMapUpdatePing` / `triggerUserUpdatePing` POST on a timer (`initMapUpdatePing`,
  `initMapUserUpdatePing`). On success both call `exitReconnecting()` and resume their own loop.
- On ajax failure, `.fail()` calls `handleAjaxErrorResponse(jqXHR, status, error, mapModule)`:
  - Server response has `reroute` set (genuine auth loss) -> clears all timers, fires
    `pf:shutdown` (unchanged logout/redirect behavior).
  - No `mapModule` (failure during app startup, no ping loop running yet) -> same shutdown
    behavior as before.
  - Otherwise (transient failure inside an active ping loop: network blip, 502/504, JSON parse
    error) -> `clearUpdateTimeouts()` stops both ping timers, a warning notify is shown, and
    `enterReconnecting(mapModule)` takes over.
- `enterReconnecting`: sets `reconnectState.active`, fires `pf:connectionLost` (once), sets header
  status to `problem`, and schedules a retry via `triggerMapUpdatePing(mapModule, true)` (always
  the *map* ping, even if the failure came from the user-update ping — recovering the map ping
  restarts the user-update loop too once data arrives, see `initMapUpdatePing`'s `.then()`).
- Retry delay is exponential backoff: `getCurrentTriggerDelay() * 2^attempt`, capped at
  `RECONNECT_MAX_DELAY`. `reconnectState.attempt` increments each retry, resets on recovery.
- If the problem outlasts `RECONNECT_TIMEOUT` (from `enterReconnecting`'s first call), a
  `showReconnectModal()` timer fires: a "Connection problem" dialog with **Refresh** (reload page)
  and **Logout** buttons. Auto-dismissed by `pf:connectionRestored` if the connection recovers
  first.
- `app-level` errors in a successful response (`data.error`) no longer force logout either — they
  are shown as notifications and the loop keeps running.

Config (`app/pathfinder.ini`, `[PATHFINDER.TIMER.CONNECTION]`): `RECONNECT_TIMEOUT` (default
60000ms, delay before the escalation modal), `RECONNECT_MAX_DELAY` (default 30000ms, backoff cap).
Reaches the client via `Map::initData()` (`Config::getPathfinderData('timer')` dumps the whole
`PATHFINDER.TIMER` hive subtree, F3 nests dotted ini sections automatically) ->
`Init.timer = response.timer` in `mappage.js`, so `Init.timer.CONNECTION.RECONNECT_MAX_DELAY` /
`RECONNECT_TIMEOUT` are live config, same mechanism as the other `TIMER.*` values.

## UI feedback (`js/app/page.js`, `sass/layout/_main.scss`)

- `pf:connectionLost` shows a persistent banner (`.pf-connection-banner`, "Connection lost.
  Reconnecting…") below the page header.
- `pf:connectionRestored` hides the banner and dismisses the escalation modal if it's open.
- Header status icon reuses the existing `problem` state (orange, `fa-exclamation-triangle`) via
  `setProgramStatus('problem')`.

## WebSocket (`js/app/worker/map.js`, SharedWorker)

- `socket.onclose`: if `!closeEvent.wasClean` (server restart, network blip — not an explicit
  `ws:close`/port teardown), `scheduleReconnect()` is called instead of leaving the socket dead.
- `scheduleReconnect`: exponential backoff (`1000 * 2^attempt`, capped at `wsReconnectMaxDelay`,
  default 30000ms), calls `initSocket(wsUri)` again. Skipped if no ports are left listening.
- `wsReconnectMaxDelay` is set once from the `ws:init` message's `reconnectMaxDelay` field, which
  the main thread (`js/app/map/worker.js`) fills from
  `Util.getObjVal(Init, 'timer.CONNECTION.RECONNECT_MAX_DELAY')` — same config value as the ajax
  backoff cap above.
- Backoff/attempt counter resets on `onopen` (`clearReconnect()`) or when the last port
  disconnects (`sw:closePort` with `ports.length === 0`).
- `ws:send` and the `sw:closePort` unsubscribe path now guard on `socket && socket.readyState ===
  WebSocket.OPEN` instead of assuming `socket` is live — avoids throwing while a reconnect is
  pending.

## What still triggers a hard logout

- Server explicitly sets `reroute` on an ajax error response (session/auth actually gone).
- Ajax failure during initial app startup, before any ping loop exists.

# Running Pathfinder with Podman

This directory holds the build context for a containerised Pathfinder stack.
- The image is built from **this repository's source** (`../pathfinder.Dockerfile`) and orchestrated by `../compose.yaml`.
- It is adapted from [goryn-clade/pathfinder-containers](https://github.com/goryn-clade/pathfinder-containers), with the production Traefik + Let's Encrypt layer replaced by a directly exposed host port for simple local use.

## Stack

| Service | Image | Role |
|---------|------------------------------------|--------------------------------------|
| `pf`    | built from source                  | nginx + php-fpm + cron (supervisord) |
| `pfdb`  | `mariadb:10.6`                     | MySQL databases                      |
| `redis` | `redis:7-alpine`                   | cache + PHP sessions                 |
| `socket`| `ghcr.io/goryn-clade/pf-websocket` | real-time map websocket              |

## Quick start

1. Install [podman](https://podman.io/docs/installation) (dock works too if you prefer).
2. Create an Application on the Eve Online [Developer portal](https://developers.eveonline.com/applications):
  * After signing in go to "MANAGE APPLICATIONS" → "CREATE NEW APPLICATION".
  * Choose a name for your application (e.g. "Local Pathfinder develoment") and enter a description.
  * Set the callback URL to `https://<YOUR_DOMAIN>/sso/callbackAuthorization` (or `http://localhost:8080/sso/callbackAuthorization` for local development).
  * Select the following "Enabled Scopes":
    - esi-characters.read_corporation_roles.v1
    - esi-clones.read_clones.v1
    - esi-corporations.read_corporation_membership.v1
    - esi-location.read_location.v1
    - esi-location.read_online.v1
    - esi-location.read_ship_type.v1
    - esi-search.search_structures.v1
    - esi-ui.open_window.v1
    - esi-ui.write_waypoint.v1
    - esi-universe.read_structures.v1
3. Create the local configuration with `cp .env.example .env` and edit the `.env` file (change passwords + fill in the SSO info).
4. Start the application with `podman compose up -d --build`.
5. The app is served at `http://localhost:8080` (or the `HTTP_PORT` you set in `.env`).

## First-run setup

1. Open `http://localhost:8080/setup` — basic-auth user `pf`, password is the `APP_PASSWORD` defined in .env.
2. Run the setup wizard: it builds the database schema and imports the static map data (`data/*`).
   Make sure to click both the "Setup tables" and "Fix columns/keys" buttons for both tables (pathfinder and eve_universe).
3. **Disable the setup routes** once done: in `app/routes.ini`, comment out the `/setup` and `/setup/api` lines, then rebuild.

## Logging

To review logs, run:
```shell
podman compose logs -f             # all services, follow
podman compose logs -f <container> # just the app container
```

The containers are listed in `compose.yaml`: `pfdb`, `redis`, `socket` and `pf`.

## Production / TLS

nginx serves HTTPS itself via its native ACME module (`ngx_http_acme_module`) — no separate reverse proxy or cert-renewal cron needed; certs are issued/renewed automatically from Let's Encrypt.

Certs are issued via the ACME **tls-alpn-01** challenge (validated inside the TLS handshake on 443), not http-01 — the module's http-01 solver does not serve the challenge in this build (see `deployment/nginx/site-tls.conf`).

Prerequisites:
- A real domain with a DNS A/AAAA record pointing at this host.
- Port 443 open to the internet and reachable as-is (Let's Encrypt's tls-alpn-01 challenge always connects to port 443 on `DOMAIN`). Port 80 is optional — it only serves the http -> https redirect.

In `.env`:
- `DOMAIN` — the bare public hostname (no port), e.g. `pathfinder.example.com`.
- `HTTPS_PORT=443` — **must** be 443: Let's Encrypt's tls-alpn-01 challenge always connects to port 443 on `DOMAIN`.
- `ENABLE_TLS=true`
- `ACME_EMAIL` — your Let's Encrypt account contact.
- `ACME_DIRECTORY_URL` — leave as the production Let's Encrypt directory, or swap to the staging directory while testing to avoid production rate limits.

Rebuild and restart as usual:
```shell
podman compose up -d --build
```

Certs and the ACME account key persist in the `pf_acme` volume (`/var/lib/nginx/acme` in the container) — `podman compose down -v` wipes them too, which can trigger Let's Encrypt rate limits on the next issuance. Watch first-boot cert issuance with `podman compose logs -f pf`.

If you want to switch from the let's encrypt staging environment to produciton, you need to delete the cache with:
```shell
podman exec pathfinder sh -c 'rm -f /var/lib/nginx/acme/*'
```

## Notes

- You need tor ebuild after changing app source or baked config: `podman compose up -d --build`.
- To reset everything, run `podman compose down -v` (**warning**: this resets the database too!).
- Config rendering: `entrypoint.sh` runs `envsubst` over the `*.ini` and nginx templates at container start. nginx substitution is intentionally scoped to a single variable (`$DOMAIN`, `$PATHFINDER_SOCKET_HOST`) so literal nginx variables like `$uri`/`$host` survive — don't widen it.
- No SELinux relabel (`:z`/`:Z`) flags are set on the bind mounts; on a Windows podman machine the share is virtiofs and relabeling can error. On an SELinux host (Fedora/RHEL) you may need to add `:Z` to the `init-databases.sh` mount in `compose.yaml`.

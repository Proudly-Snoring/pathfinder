# Running Pathfinder with Podman

This directory holds the build context for a containerised Pathfinder stack.
The image is built from **this repository's source** (`../pathfinder.Dockerfile`) and orchestrated by `../compose.yaml`.
It is adapted from [goryn-clade/pathfinder-containers](https://github.com/goryn-clade/pathfinder-containers),
with the production Traefik + Let's Encrypt layer replaced by a directly exposed host port for simple local use.

## Stack

| Service | Image | Role |
|---------|------------------------------------|--------------------------------------|
| `pf`    | built from source                  | nginx + php-fpm + cron (supervisord) |
| `pfdb`  | `mariadb:10.6`                     | MySQL databases                      |
| `redis` | `redis:7-alpine`                   | cache + PHP sessions                 |
| `socket`| `ghcr.io/goryn-clade/pf-websocket` | real-time map websocket              |

## Quick start

1. Create an Application on the developers portal:
  * Go the Eve Online [Developer portal](https://developers.eveonline.com/applications).
  * After signing in go to "MANAGE APPLICATIONS" → "CREATE NEW APPLICATION".
  * Choose a name for your application (e.g. "Pathfinder Production").
  * Enter a Description for this installation.
  * Select the following "Enabled Scopes":
    - esi-characters.read_corporation_roles.v1
    - esi-clones.read_clones.v1
    - esi-corporations.read_corporation_membership.v1
    - esi-location.read_online.v1
    - esi-location.read_location.v1
    - esi-location.read_ship_type.v1
    - esi-search.search_structures.v1
    - esi-ui.write_waypoint.v1
    - esi-ui.open_window.v1
    - esi-universe.read_structures.v1
2. Create the local configuration with `cp .env.example .env`.
3. Edit the `.env` file passwords and fill in the SSO secrets.
4. Start the application from podman compose file with `podman compose up -d --build`.
5. The app is served at `http://localhost:8080` (or the `HTTP_PORT` you set in `.env`).

## First-run setup

1. Open `http://localhost:8080/setup` — basic-auth user `pf`, password is the `APP_PASSWORD` defined in .env.
2. Run the setup wizard: it builds the database schema and imports the static map data (`export/csv/*`).
   Make sure to click both the "Setup tables" and "Fix columns/keys" buttons for both tables (pathfinder and eve_universe - 4 buttons total).
3. **Comment out the `@setup` route** in `app/routes.ini` (and rebuild) once done, as that file warns.

### EVE universe (New Eden) data

The `eve_universe` DB is populated from CCP's ESI API — on demand as maps are used, and by the `/cron` jobs (which run inside the `pf` container).
A pre-built snapshot ships at `export/sql/eve_universe.sql.zip` to skip the slow initial ESI bootstrap.
Import it manually if you want a head start (run from the repo root):
```shell
unzip -p export/sql/eve_universe.sql.zip | podman compose exec -T pfdb mysql -uroot -p"$MYSQL_ROOT_PASSWORD" eve_universe
```

## Logging

To review logs, run:
```shell
podman compose logs -f             # all services, follow
podman compose logs -f <container> # just the app container
```
The containers are listed in `compose.yaml`: `pfdb`, `redis`, `socket` and `pf`.

## Notes

- **EVE SSO is required for login.** Register an app at https://developers.eveonline.com, set the callback to `http://localhost:8080/sso/callbackAuthorization`, and fill `CCP_SSO_CLIENT_ID` / `CCP_SSO_SECRET_KEY` / `CCP_ESI_SCOPES` in `.env`.
- Config rendering: `entrypoint.sh` runs `envsubst` over the `*.ini` and nginx templates at container start. nginx substitution is intentionally scoped to a single variable (`$DOMAIN`, `$PATHFINDER_SOCKET_HOST`) so literal nginx variables like `$uri`/`$host` survive — don't widen it.
- No SELinux relabel (`:z`/`:Z`) flags are set on the bind mounts; on a Windows podman machine the share is virtiofs and relabeling can error. On an SELinux host (Fedora/RHEL) you may need to add `:Z` to the `init-databases.sh` mount in `compose.yaml`.
- Rebuild after changing app source or baked config: `podman compose up -d --build`.
- Reset everything (including DB): `podman compose down -v`.

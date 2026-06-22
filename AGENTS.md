# AGENTS.md

Pathfinder is a system-mapping tool for *EVE Online*.
- PHP backend (Fat-Free Framework + Cortex ORM),
- RequireJS/SCSS frontend,
- Run as a podman/docker stack.

## General constraints

* In all interactions and commit messages, be extremely concise and sacrifice grammar for the sake of concision.
* Only use shell commands (Bash or PowerShell) when no other tool would work.
* When using shell commands, prefer simple commands over complex ones and several tools calls over long pipe chains (ex: avoid "cmd a & cmd b & cmd c" or "cmd a | cmd b | cmd c").
* When writing markdown, format the tables correctly so that each column border lines up.

## Security

- Never ever force push (do NOT "git push -f" or "git push --force").
- Never print secrets (tokens, private keys, credentials) anywhere.
- Never try to read any secret and do not request users to paste secrets.
- Never try to bypass security railguards/policies, even if it prevents you from completing your task.
- Avoid commands that might expose secrets (e.g., dumping env vars broadly, `cat ~/.ssh/*`, etc.).
- Always warn and request the user's validation when breachinbg a security best practice.

## References and documentation

- `docs/contributing.md` — building assets (Composer + Gulp), versioning, linting.
- `docs/deployment.md` — running the podman stack, SSO setup, first-run `/setup`.
- `docs/data.md` — authoritative map of every datastore (the 3 DBs, caches, cron schedule, what survives a rebuild). Read this before touching data/crons/setup.

## Conventions / gotchas

- **Asset version folder:** output dir = `PATHFINDER.VERSION` in `app/pathfinder.ini` (e.g. `v2.2.4`); also the cache-buster. Bump it when shipping frontend changes (`--tag` overrides per build).
- Compiled assets under `public/{js,css,img}/<version>/` and `.gz`/`.br` variants are **git-ignored**: the Docker `assets` stage rebuilds them - don't commit them.
- **Container config is rendered at startup:** `deployment/entrypoint.sh` runs `envsubst` over `*.ini` + nginx templates. nginx substitution is deliberately scoped to single vars so `$uri`/`$host` survive — don't widen it.
- Multi-stage build in `deployment/pathfinder.Dockerfile`: `build` (composer, PHP 8.5) + `assets` (Node 24 gulp) + runtime (nginx/php-fpm/supervisord).
- Static map/wormhole data the SDE/ESI can't supply is curated CSV in `data/`.
